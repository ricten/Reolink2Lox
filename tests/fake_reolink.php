<?php
/**
 * Fake-Reolink-Kamera fuer Tests: php tests/fake_reolink.php <port> <verzeichnis> [<zertifikat.pem>|-] [profil]
 *
 * Antwortet auf POST /cgi-bin/api.cgi wie die Kamera (JSON-Liste, Login mit Token). Die Antworten der Get-Befehle stammen aus den
 * anonymisierten Aufnahmen einer echten RLC-823S1 (tests/fixtures/rlc823s1); Set-Befehle aendern den Zustand im Speicher.
 * Mit Zertifikat laeuft der Server als HTTPS, sonst als HTTP.
 *
 * Profile:
 *   rlc823s1  (Standard) volle Kamera: Bewegung, KI, Scheinwerfer, IR, Status-LED, PTZ, Zoom, Aufnahme, Push, E-Mail, FTP
 *   einfach   alte/einfache Kamera: nur Bewegung (GetMdState), kein GetAiState, nur die alten Befehle (GetRec, GetPush ...), kein Licht, kein PTZ
 *
 * Benutzer testuser, Passwort testpass (bei "einfach" ebenfalls).
 * Steuerung durch die Tests ueber <verzeichnis>/cam<port>.ctl (JSON, wird nach dem Lesen geloescht):
 *   {"md":1,"ai":{"people":1}}      Erkennungen setzen
 *   {"token_weg":true}              alle Tokens vergessen (naechste Anfrage: "please login first")
 *   {"modus":"502","anfragen":3}    die naechsten n Anfragen mit HTTP 502 beantworten
 *   {"modus":"stumm","anfragen":2}  die naechsten n Anfragen ohne Antwort schliessen
 *   {"chunked":true}                Antworten als chunked senden
 *   {"max_sitzungen":true}          Login mit "max session" ablehnen
 * Protokoll: <verzeichnis>/cam<port>.log: "REQ\t<cmd>\t<param-json>" je Befehl, "LOGIN\tok|falsch|max", "HTTP\t<Methode> <Pfad ohne Token>".
 */
$port = (int)($argv[1] ?? 18900);
$dir = $argv[2] ?? sys_get_temp_dir();
$pem = $argv[3] ?? '-';
$profil = $argv[4] ?? 'rlc823s1';
$logf = "$dir/cam$port.log";
$ctlf = "$dir/cam$port.ctl";
$fix = dirname(__DIR__) . '/tests/fixtures/rlc823s1';
if (!is_dir($fix)) {
    $fix = __DIR__ . '/fixtures/rlc823s1';
}

$ctx = stream_context_create($pem !== '-' ? ['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]] : []);
$srv = @stream_socket_server(($pem !== '-' ? 'ssl' : 'tcp') . "://127.0.0.1:$port", $e, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
function logline(string $f, string $l): void
{
    file_put_contents($f, $l . "\n", FILE_APPEND);
}
function aufnahme(string $fix, string $cmd): ?array
{
    $f = "$fix/$cmd.json";
    return is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
}

/** Zustand der Kamera (veraenderbar). */
$z = [];
foreach (['GetIrLights', 'GetWhiteLed', 'GetPowerLed', 'GetZoomFocus', 'GetAutoFocus', 'GetRecV20', 'GetPushV20', 'GetEmailV20', 'GetFtpV20', 'GetPtzPreset', 'GetPerformance', 'GetHddInfo', 'GetDevInfo', 'GetAbility', 'GetAiState', 'GetAudioAlarmV20'] as $c) {
    $z[$c] = aufnahme($fix, $c);
}
$md = 0;
$ai = ['people' => 0, 'vehicle' => 0, 'dog_cat' => 0];
$tokens = [];
$mod = null;
$modN = 0;
$chunked = false;
$offen = false;
$maxSitz = false;
$einfach = $profil === 'einfach';
$alt = ['rec' => 0, 'push' => 1, 'email' => 0, 'ftp' => 0];     // Zustand der alten Befehle (nur Profil einfach)
if ($einfach) {
    $z['GetDevInfo']['value']['DevInfo']['model'] = 'RLC-410';
    $z['GetDevInfo']['value']['DevInfo']['firmVer'] = 'v3.0.0.100';
    // aeltere Faehigkeiten: keine V20-Befehle
    foreach (['recSchedule', 'ftp'] as $k) {
        $z['GetAbility']['value']['Ability']['abilityChn'][0][$k] = ['permit' => 6, 'ver' => 0];
    }
    foreach (['push', 'email'] as $k) {
        $z['GetAbility']['value']['Ability'][$k] = ['permit' => 6, 'ver' => 0];
    }
    $z['GetAbility']['value']['Ability']['abilityChn'][0]['ptzCtrl'] = ['permit' => 0, 'ver' => 0];
    $z['GetAbility']['value']['Ability']['abilityChn'][0]['supportZoom'] = ['permit' => 0, 'ver' => 0];
    $z['GetAbility']['value']['Ability']['abilityChn'][0]['supportFocus'] = ['permit' => 0, 'ver' => 0];
    $z['GetAbility']['value']['Ability']['supportAudioAlarm'] = ['permit' => 0, 'ver' => 0];
    $z['GetAbility']['value']['Ability']['abilityChn'][0]['alarmAudio'] = ['permit' => 0, 'ver' => 0];
}

function ok(string $cmd, array $value = [], array $extra = []): array
{
    return ['cmd' => $cmd, 'code' => 0, 'value' => $value ?: ['rspCode' => 200]] + $extra;
}
function fehler(string $cmd, int $rsp, string $detail): array
{
    return ['cmd' => $cmd, 'code' => 1, 'error' => ['detail' => $detail, 'rspCode' => $rsp]];
}

/** Ein Befehl der Liste bearbeiten. */
function befehl(array $b, array &$z, int &$md, array &$ai, bool $einfach, array &$alt, string $logf): array
{
    $cmd = (string)($b['cmd'] ?? '');
    $p = is_array($b['param'] ?? null) ? $b['param'] : [];
    logline($logf, "REQ\t$cmd\t" . json_encode($p));
    $nicht = fn() => fehler($cmd, -9, 'not support');
    $lies = fn(string $k) => $z[$k] ?? null;
    switch ($cmd) {
        case 'GetDevInfo':
            return $lies($cmd);
        case 'GetAbility':
            return $lies($cmd);
        case 'GetMdState':
            return ok($cmd, ['state' => $md]);
        case 'GetAiState':
            if ($einfach) {
                return $nicht();
            }
            $v = ['channel' => 0, 'face' => ['alarm_state' => 0, 'support' => 0]];
            foreach ($ai as $k => $s) {
                $v[$k] = ['alarm_state' => $s, 'support' => 1];
            }
            return ok($cmd, $v);
        case 'GetIrLights':
        case 'GetWhiteLed':
        case 'GetPowerLed':
        case 'GetZoomFocus':
        case 'GetAutoFocus':
        case 'GetPtzPreset':
            return $einfach ? $nicht() : $lies($cmd);
        case 'GetPerformance':
        case 'GetHddInfo':
            return $lies($cmd);
        case 'GetRecV20':
        case 'GetPushV20':
        case 'GetEmailV20':
        case 'GetFtpV20':
            return $einfach ? $nicht() : $lies($cmd);
        case 'GetRec':
        case 'GetPush':
        case 'GetEmail':
        case 'GetFtp':
            if (!$einfach) {
                return $nicht();
            }
            $k = strtolower(substr($cmd, 3));
            return ok($cmd, [substr($cmd, 3) => ['schedule' => ['enable' => $alt[$k], 'channel' => 0]]]);
        case 'SetWhiteLed':
            if ($einfach) {
                return $nicht();
            }
            // wie die echte Kamera: ein Zeitplan wird nur zusammen mit Betriebsart 3 uebernommen; Betriebsart 3 = leuchtet (hier immer innerhalb des Zeitplans), 0 = aus
            $w = &$z['GetWhiteLed']['value']['WhiteLed'];
            foreach (['mode', 'bright'] as $k) {
                if (isset($p['WhiteLed'][$k])) {
                    $w[$k] = $p['WhiteLed'][$k];
                }
            }
            if (($p['WhiteLed']['mode'] ?? null) === 3 && isset($p['WhiteLed']['LightingSchedule'])) {
                $w['LightingSchedule'] = $p['WhiteLed']['LightingSchedule'];
            }
            if (isset($p['WhiteLed']['mode'])) {
                $w['state'] = $p['WhiteLed']['mode'] === 3 ? 1 : 0;
            } elseif (isset($p['WhiteLed']['state']) && (int)$w['mode'] !== 3) {
                $w['state'] = (int)$p['WhiteLed']['state'];
            }
            unset($w);
            return ok($cmd);
        case 'SetIrLights':
            if ($einfach) {
                return $nicht();
            }
            $z['GetIrLights']['value']['IrLights']['state'] = $p['IrLights']['state'] ?? 'Auto';
            return ok($cmd);
        case 'SetPowerLed':
            if ($einfach) {
                return $nicht();
            }
            $z['GetPowerLed']['value']['PowerLed']['state'] = $p['PowerLed']['state'] ?? 'On';
            return ok($cmd);
        case 'StartZoomFocus':
            if ($einfach) {
                return $nicht();
            }
            $op = $p['ZoomFocus']['op'] ?? '';
            if (($p['ZoomFocus']['pos'] ?? 0) > 500) {
                return fehler($cmd, -3, 'param error');
            }
            $z['GetZoomFocus']['value']['ZoomFocus'][$op === 'ZoomPos' ? 'zoom' : 'focus']['pos'] = (int)($p['ZoomFocus']['pos'] ?? 0);
            return ok($cmd);
        case 'SetAutoFocus':
            if ($einfach) {
                return $nicht();
            }
            $z['GetAutoFocus']['value']['AutoFocus']['disable'] = (int)($p['AutoFocus']['disable'] ?? 0);
            return ok($cmd);
        case 'SetRecV20':
        case 'SetPushV20':
        case 'SetEmailV20':
        case 'SetFtpV20':
            if ($einfach) {
                return $nicht();
            }
            $name = substr($cmd, 3, -3);
            $z['Get' . $name . 'V20']['value'][$name]['enable'] = (int)($p[$name]['enable'] ?? 0);
            return ok($cmd);
        case 'SetRec':
        case 'SetPush':
        case 'SetEmail':
        case 'SetFtp':
            if (!$einfach) {
                return $nicht();
            }
            $alt[strtolower(substr($cmd, 3))] = (int)($p[substr($cmd, 3)]['schedule']['enable'] ?? 0);
            return ok($cmd);
        case 'PtzCtrl':
            if ($einfach) {
                return $nicht();
            }
            return ($p['op'] ?? '') === 'Kaputt' ? fehler($cmd, -3, 'param error') : ok($cmd);
        case 'AudioAlarmPlay':
            return $einfach ? $nicht() : ok($cmd);
        default:
            return $nicht();
    }
}

echo "listening $port\n";
while (true) {
    $c = @stream_socket_accept($srv, 1);
    if (!$c) {
        if (is_file($ctlf)) {                          // Steuerung auch ohne Verbindungen anwenden
            $s = json_decode((string)file_get_contents($ctlf), true);
            @unlink($ctlf);
            if (is_array($s)) {
                $md = (int)($s['md'] ?? $md);
                $ai = array_merge($ai, (array)($s['ai'] ?? []));
                if (!empty($s['token_weg'])) {
                    $tokens = [];
                }
                if (isset($s['modus'])) {
                    $mod = $s['modus'];
                    $modN = (int)($s['anfragen'] ?? 1);
                }
                if (isset($s['chunked'])) {
                    $chunked = (bool)$s['chunked'];
                }
                if (isset($s['offen'])) {
                    $offen = (bool)$s['offen'];
                }
                if (isset($s['max_sitzungen'])) {
                    $maxSitz = (bool)$s['max_sitzungen'];
                }
            }
        }
        continue;
    }
    stream_set_timeout($c, 5);
    $kopf = '';
    while (!str_contains($kopf, "\r\n\r\n") && !feof($c) && strlen($kopf) < 65536) {
        $d = fread($c, 8192);
        if ($d === '' || $d === false) {
            break;
        }
        $kopf .= $d;
        if (!preg_match('/^(POST|GET) /', $kopf) && strlen($kopf) >= 4) {      // kein HTTP (z. B. TLS-Anfang an einem HTTP-Port): wie ein Webserver mit 400 abweisen
            fwrite($c, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            break;
        }
    }
    $p = strpos($kopf, "\r\n\r\n");
    if ($p === false) {
        fclose($c);
        continue;
    }
    $head = substr($kopf, 0, $p);
    $body = substr($kopf, $p + 4);
    $cl = preg_match('/Content-Length:\s*(\d+)/i', $head, $m) ? (int)$m[1] : 0;
    while (strlen($body) < $cl && !feof($c)) {
        $d = fread($c, 8192);
        if ($d === '' || $d === false) {
            break;
        }
        $body .= $d;
    }
    // Steuerung lesen
    if (is_file($ctlf)) {
        $s = json_decode((string)file_get_contents($ctlf), true);
        @unlink($ctlf);
        if (is_array($s)) {
            $md = (int)($s['md'] ?? $md);
            $ai = array_merge($ai, (array)($s['ai'] ?? []));
            if (!empty($s['token_weg'])) {
                $tokens = [];
            }
            if (isset($s['modus'])) {
                $mod = $s['modus'];
                $modN = (int)($s['anfragen'] ?? 1);
            }
            if (isset($s['chunked'])) {
                $chunked = (bool)$s['chunked'];
            }
            if (isset($s['offen'])) {
                $offen = (bool)$s['offen'];
            }
            if (isset($s['max_sitzungen'])) {
                $maxSitz = (bool)$s['max_sitzungen'];
            }
        }
    }
    $zeile = explode("\r\n", $head)[0];
    logline($logf, 'HTTP' . "\t" . preg_replace('/token=[^&\s]*/', 'token=<x>', $zeile));
    if ($mod !== null && $modN > 0) {
        $modN--;
        if ($modN === 0) {
            $m2 = $mod;
            $mod = null;
        } else {
            $m2 = $mod;
        }
        if ($m2 === 'stumm') {
            fclose($c);
            continue;
        }
        $t = "<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>";
        fwrite($c, "HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/html\r\nContent-Length: " . strlen($t) . "\r\nConnection: close\r\n\r\n" . $t);
        fclose($c);
        continue;
    }
    $liste = json_decode($body, true);
    $antwort = [];
    $token = preg_match('/[?&]token=([A-Za-z0-9%]+)/', $zeile, $m) ? urldecode($m[1]) : '';
    foreach (is_array($liste) ? $liste : [] as $b) {
        $cmd = (string)($b['cmd'] ?? '');
        if ($cmd === 'Login') {
            $u = $b['param']['User']['userName'] ?? '';
            $pw = $b['param']['User']['password'] ?? '';
            if ($maxSitz) {
                logline($logf, "LOGIN\tmax");
                $antwort[] = fehler($cmd, -5, 'max session');
            } elseif ($u === 'testuser' && $pw === 'testpass') {
                $t = bin2hex(random_bytes(8));
                $tokens[$t] = true;
                logline($logf, "LOGIN\tok");
                $antwort[] = ['cmd' => 'Login', 'code' => 0, 'value' => ['Token' => ['leaseTime' => 3600, 'name' => $t]]];
            } else {
                logline($logf, "LOGIN\tfalsch");
                $antwort[] = fehler($cmd, -7, 'login failed');
            }
            continue;
        }
        if ($cmd === 'Logout') {
            unset($tokens[$token]);
            logline($logf, "REQ\tLogout\t{}");
            $antwort[] = ok($cmd);
            continue;
        }
        if (!isset($tokens[$token])) {
            $antwort[] = fehler($cmd, -6, 'please login first');
            continue;
        }
        $antwort[] = befehl($b, $z, $md, $ai, $einfach, $alt, $logf) ?? fehler($cmd, -9, 'not support');
    }
    $json = json_encode($antwort);
    if ($chunked) {
        $half = intdiv(strlen($json), 2);
        $teile = [substr($json, 0, $half), substr($json, $half)];
        $out = '';
        foreach ($teile as $t) {
            $out .= dechex(strlen($t)) . "\r\n" . $t . "\r\n";
        }
        $out .= "0\r\n\r\n";
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n" . $out);
    } else {
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json);
    }
    if ($offen) {
        usleep(3000000);
    }
    fclose($c);
}
