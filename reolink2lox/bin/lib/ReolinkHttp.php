<?php
/**
 * Minimaler HTTP/1.0-Client ueber Streams (HTTP oder HTTPS mit selbst signiertem Zertifikat der Kamera).
 * HTTP/1.0 mit "Connection: close": die Kamera (nginx) antwortet damit nie chunked und die Antwort endet mit dem Schliessen der Verbindung.
 * Eigene Verbindungs-Zeitgrenze, damit eine nicht erreichbare Kamera den Dienst nicht lange blockiert.
 */
final class ReolinkHttp
{
    private const int MAX_BYTES = 4194304;

    /**
     * POST mit JSON-Koerper.
     * @return array{status:int,body:string,error:string}  status 0 = keine Verbindung / kein Ergebnis (error nennt den Grund)
     */
    public static function post(string $host, int $port, bool $tls, string $pfad, string $body, float $verbindungsZeit = 3.0, float $zeit = 10.0): array
    {
        $ctxOpt = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => false,
            'ciphers' => 'DEFAULT:@SECLEVEL=0']];            // Kameras mit aelterem TLS nicht an der Sicherheitsstufe von OpenSSL 3 scheitern lassen
        // erst die TCP-Verbindung, dann (bei HTTPS) der TLS-Aufbau: so lassen sich "nicht erreichbar" und "TLS fehlgeschlagen" trennen
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $verbindungsZeit, STREAM_CLIENT_CONNECT, stream_context_create($ctxOpt));
        if (!$fp) {
            return ['status' => 0, 'body' => '', 'error' => self::fehlertext((string)$errstr, (int)$errno)];
        }
        if ($tls) {
            stream_set_timeout($fp, max(1, (int)ceil($verbindungsZeit) + 2));
            $krypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($krypto !== true) {
                fclose($fp);
                return ['status' => 0, 'body' => '', 'error' => 'TLS-Verbindung fehlgeschlagen (spricht die Kamera HTTP statt HTTPS?)'];
            }
        }
        $ende = microtime(true) + $zeit;
        stream_set_timeout($fp, max(1, (int)ceil($zeit)));
        $req = "POST $pfad HTTP/1.0\r\nHost: $host\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
        $w = 0;
        $len = strlen($req);
        while ($w < $len) {
            $n = @fwrite($fp, substr($req, $w));
            if ($n === false || $n === 0) {
                fclose($fp);
                return ['status' => 0, 'body' => '', 'error' => 'Senden an die Kamera fehlgeschlagen'];
            }
            $w += $n;
        }
        $raw = '';
        $erwartet = null;       // Gesamtlaenge laut Content-Length: damit endet das Lesen, auch wenn die Kamera die Verbindung nicht schliesst
        while (!feof($fp) && microtime(true) < $ende && strlen($raw) < self::MAX_BYTES) {
            if ($erwartet === null && ($p0 = strpos($raw, "\r\n\r\n")) !== false) {
                $erwartet = preg_match('/^Content-Length:\s*(\d+)/mi', substr($raw, 0, $p0), $cl) ? $p0 + 4 + (int)$cl[1] : -1;
            }
            if ($erwartet !== null && $erwartet > 0 && strlen($raw) >= $erwartet) {
                break;
            }
            $c = @fread($fp, 65536);
            if ($c === false) {
                break;
            }
            if ($c === '') {
                if (stream_get_meta_data($fp)['timed_out'] ?? false) {
                    break;
                }
                usleep(2000);
                continue;
            }
            $raw .= $c;
        }
        if ($erwartet === null && ($p0 = strpos($raw, "\r\n\r\n")) !== false) {
            $erwartet = preg_match('/^Content-Length:\s*(\d+)/mi', substr($raw, 0, $p0), $cl) ? $p0 + 4 + (int)$cl[1] : -1;
        }
        $vollstaendig = $erwartet !== null && $erwartet > 0 && strlen($raw) >= $erwartet;
        $timedOut = !$vollstaendig && !feof($fp) && microtime(true) >= $ende;
        fclose($fp);
        if ($raw === '') {
            return ['status' => 0, 'body' => '', 'error' => $timedOut ? 'Zeitüberschreitung: die Kamera antwortet nicht' : 'Die Kamera hat die Verbindung ohne Antwort geschlossen'];
        }
        $p = strpos($raw, "\r\n\r\n");
        if ($p === false || !preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $raw, $m)) {
            return ['status' => 0, 'body' => '', 'error' => 'Keine gültige HTTP-Antwort'];
        }
        $kopf = substr($raw, 0, $p);
        $text = (string)substr($raw, $p + 4);
        if (preg_match('/^Transfer-Encoding:\s*chunked/mi', $kopf)) {
            $text = self::dechunk($text);
        }
        return ['status' => (int)$m[1], 'body' => $text, 'error' => ''];
    }

    private static function dechunk(string $s): string
    {
        $out = '';
        while ($s !== '') {
            $e = strpos($s, "\r\n");
            if ($e === false) {
                break;
            }
            $n = hexdec(trim(explode(';', substr($s, 0, $e), 2)[0]));
            if ($n <= 0) {
                break;
            }
            $out .= substr($s, $e + 2, (int)$n);
            $s = (string)substr($s, $e + 2 + (int)$n + 2);
        }
        return $out;
    }

    private static function fehlertext(string $errstr, int $errno): string
    {
        $t = strtolower($errstr);
        if (str_contains($t, 'refused') || $errno === 111 || $errno === 10061) {
            return 'Verbindung abgelehnt (Port geschlossen?)';
        }
        if (str_contains($t, 'timed out') || $errno === 110 || $errno === 10060) {
            return 'Zeitüberschreitung: Kamera nicht erreichbar';
        }
        if (str_contains($t, 'unreachable') || str_contains($t, 'resolve') || str_contains($t, 'getaddrinfo')) {
            return 'Kamera nicht erreichbar (Adresse prüfen)';
        }
        return 'Verbindung fehlgeschlagen' . ($errstr !== '' ? ' (' . preg_replace('/[^\x20-\x7e]/', '', $errstr) . ')' : '');
    }
}
