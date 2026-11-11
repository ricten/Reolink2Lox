<?php
/** Fake-MQTT-Broker fuer Tests: php tests/fake_mqtt.php <port> <verzeichnis>
 *  Schreibt <verzeichnis>/mqtt.log ("CONNECT\t<clientid>\t<will>", "SUB\t<filter>", "<topic>\t<payload>").
 *  Zeilen "topic<TAB>payload[<TAB>R]" in <verzeichnis>/inject.txt werden als PUBLISH an den Client gesendet (R = retained). */
$port = (int)($argv[1] ?? 18830);
$dir = $argv[2] ?? sys_get_temp_dir();
$logf = "$dir/mqtt.log";
$injf = "$dir/inject.txt";
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
function logline(string $f, string $l): void
{
    file_put_contents($f, $l . "\n", FILE_APPEND);
}
function mstr(string $s): string
{
    return pack('n', strlen($s)) . $s;
}
$clients = [];
$buf = [];
echo "listening $port\n";
while (true) {
    $r = array_merge([$srv], $clients);
    $w = $x = null;
    if (stream_select($r, $w, $x, 0, 200000)) {
        foreach ($r as $s) {
            if ($s === $srv) {
                $c = stream_socket_accept($srv);
                $clients[(int)$c] = $c;
                $buf[(int)$c] = '';
                continue;
            }
            $d = fread($s, 65536);
            if ($d === '' || $d === false) {
                fclose($s);
                unset($clients[(int)$s], $buf[(int)$s]);
                continue;
            }
            $id = (int)$s;
            $buf[$id] .= $d;
            while (strlen($buf[$id]) >= 2) {
                $mul = 1;
                $rem = 0;
                $i = 1;
                do {
                    if ($i >= strlen($buf[$id])) {
                        break 2;
                    }
                    $b = ord($buf[$id][$i++]);
                    $rem += ($b & 127) * $mul;
                    $mul *= 128;
                } while ($b & 128);
                if (strlen($buf[$id]) < $i + $rem) {
                    break;
                }
                $first = ord($buf[$id][0]);
                $body = substr($buf[$id], $i, $rem);
                $buf[$id] = substr($buf[$id], $i + $rem);
                $type = $first >> 4;
                if ($type === 1) {
                    $o = 2 + unpack('n', substr($body, 0, 2))[1] + 4;      // Protokollname, Level, Flags, Keepalive
                    $cl = unpack('n', substr($body, $o, 2))[1];
                    $cid = substr($body, $o + 2, $cl);
                    $o += 2 + $cl;
                    $wl = unpack('n', substr($body, $o, 2))[1];
                    $will = substr($body, $o + 2, $wl);
                    logline($logf, "CONNECT\t$cid\t$will");
                    fwrite($s, chr(0x20) . chr(2) . chr(0) . chr(0));
                } elseif ($type === 8) {
                    $pid = substr($body, 0, 2);
                    $fl = unpack('n', substr($body, 2, 2))[1];
                    logline($logf, "SUB\t" . substr($body, 4, $fl));
                    fwrite($s, chr(0x90) . chr(3) . $pid . chr(0));
                } elseif ($type === 3) {
                    $tl = unpack('n', substr($body, 0, 2))[1];
                    logline($logf, substr($body, 2, $tl) . "\t" . substr($body, 2 + $tl));
                } elseif ($type === 12) {
                    fwrite($s, chr(0xD0) . chr(0));
                }
            }
        }
    }
    if (is_file($injf)) {
        $lines = file($injf, FILE_IGNORE_NEW_LINES) ?: [];
        @unlink($injf);
        foreach ($lines as $l) {
            [$t, $p, $ret] = explode("\t", $l, 3) + [1 => '', 2 => ''];     // dritte Spalte "R" = retained
            $body = mstr($t) . $p;
            foreach ($clients as $c) {
                @fwrite($c, chr(0x30 | ($ret === 'R' ? 1 : 0)) . chr(strlen($body)) . $body);   // Laenge < 128 in den Tests
            }
        }
    }
}
