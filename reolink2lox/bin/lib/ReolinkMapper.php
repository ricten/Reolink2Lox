<?php
/**
 * Zustand einer Kamera -> MQTT-Topics (relativ zu reolink/<name>/). Reine Funktionen, ohne Netzwerk.
 *
 * Zustand ($st), von ReolinkCamera gefuellt (nicht vorhandene Eintraege fehlen oder sind null):
 *   md (0/1), ai (Name der KI-Art => 0/1, nur die von der Kamera unterstuetzten), alarm_ts (Unix-Zeit der letzten Erkennung),
 *   ir ("Auto"|"Off"|"On"), whiteled (Antwort GetWhiteLed: state, mode, bright), powerled ("On"|"Off"),
 *   zoom, fokus (Positionen), autofokus_aus (0/1: 1 = Autofokus ausgeschaltet), rec, push, email, ftp (enable 0/1),
 *   perf (cpuUsed, netThroughput, codecRate), hdd (Liste der Datentraeger)
 */
final class ReolinkMapper
{
    /** KI-Art der Kamera => Topic-Name. Unbekannte Arten werden auf a-z, 0-9, _ bereinigt. */
    public const array KI_NAMEN = ['people' => 'person', 'vehicle' => 'fahrzeug', 'dog_cat' => 'tier', 'face' => 'gesicht', 'package' => 'paket', 'visitor' => 'besucher'];

    public static function kiName(string $art): string
    {
        $n = self::KI_NAMEN[$art] ?? strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $art));
        return trim($n, '_') !== '' ? trim($n, '_') : 'ki';
    }

    /**
     * Antwort von GetAiState (Wert unter value) in [Art => 0/1] umsetzen. Beide Formate:
     * alte Firmware {"people":1}, neue {"people":{"alarm_state":1,"support":1}}; nicht unterstuetzte Arten fehlen.
     * @return array<string,int>
     */
    public static function aiZustand(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if ($k === 'channel') {
                continue;
            }
            if (is_int($v)) {
                $out[(string)$k] = $v === 1 ? 1 : 0;
            } elseif (is_array($v) && (int)($v['support'] ?? 0) === 1) {
                $out[(string)$k] = (int)($v['alarm_state'] ?? 0) === 1 ? 1 : 0;
            }
        }
        return $out;
    }

    /**
     * @param array $st   Zustand (siehe oben)
     * @param array $info modell, firmware, name (aus GetDevInfo)
     * @return array<string,string> Topic (relativ) => Wert
     */
    public static function map(array $st, array $info): array
    {
        $o = [];
        $b = fn($v) => ((int)$v === 1) ? '1' : '0';
        $zahl = function ($v): ?string {
            if (!is_numeric($v)) {
                return null;
            }
            $f = (float)$v;
            return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
        };
        $setze = function (string $t, ?string $v) use (&$o): void {
            if ($v !== null) {
                $o[$t] = $v;
            }
        };

        // Bewegung und KI-Erkennung
        $alarm = 0;
        if (isset($st['md'])) {
            $o['bewegung'] = $b($st['md']);
            $alarm |= (int)$st['md'];
        }
        foreach ((array)($st['ai'] ?? []) as $art => $v) {
            $o[self::kiName((string)$art)] = $b($v);
            $alarm |= (int)$v;
        }
        if (isset($st['md']) || !empty($st['ai'])) {
            $o['alarm'] = (string)$alarm;
        }
        if (!empty($st['alarm_ts'])) {
            $o['alarm_ts'] = (string)(int)$st['alarm_ts'];
        }

        // Licht
        $w = is_array($st['whiteled'] ?? null) ? $st['whiteled'] : null;
        if ($w !== null) {
            if (isset($w['state'])) {
                $o['licht/scheinwerfer'] = $b($w['state']);
            }
            $setze('licht/scheinwerfer_modus', isset($w['mode']) && is_numeric($w['mode']) ? (string)(int)$w['mode'] : null);
            $setze('licht/scheinwerfer_helligkeit', isset($w['bright']) && is_numeric($w['bright']) ? (string)(int)$w['bright'] : null);
        }
        if (isset($st['ir'])) {
            $o['licht/ir'] = in_array(strtolower((string)$st['ir']), ['auto', 'on'], true) ? '1' : '0';
        }
        if (isset($st['powerled'])) {
            $o['licht/status_led'] = in_array(strtolower((string)$st['powerled']), ['on', 'always', 'auto'], true) ? '1' : '0';
        }

        // Zoom, Fokus
        $setze('ptz/zoom', isset($st['zoom']) ? $zahl($st['zoom']) : null);
        $setze('ptz/fokus', isset($st['fokus']) ? $zahl($st['fokus']) : null);
        if (isset($st['autofokus_aus'])) {
            $o['ptz/autofokus'] = ((int)$st['autofokus_aus'] === 1) ? '0' : '1';
        }

        // Aufnahme und Meldungen
        if (isset($st['rec'])) {
            $o['aufnahme'] = $b($st['rec']);
        }
        foreach (['push', 'email', 'ftp'] as $k) {
            if (isset($st[$k])) {
                $o['meldung/' . $k] = $b($st[$k]);
            }
        }

        // Kamera
        $p = is_array($st['perf'] ?? null) ? $st['perf'] : [];
        $setze('system/cpu_prozent', isset($p['cpuUsed']) ? $zahl($p['cpuUsed']) : null);
        $setze('system/netz_kbit', isset($p['netThroughput']) ? $zahl($p['netThroughput']) : null);
        $setze('system/codec_kbit', isset($p['codecRate']) ? $zahl($p['codecRate']) : null);
        if (is_array($st['hdd'] ?? null)) {
            $platte = null;
            foreach ($st['hdd'] as $h) {
                if (is_array($h) && (float)($h['capacity'] ?? 0) > 0) {
                    $platte = $h;
                    break;
                }
            }
            $o['sd/vorhanden'] = $platte !== null ? '1' : '0';
            if ($platte !== null) {
                $kap = (float)$platte['capacity'];
                $frei = max(0.0, min($kap, (float)($platte['size'] ?? 0)));        // size = freier Platz in MB
                $o['sd/kapazitaet_gb'] = $zahl(round($kap / 1024, 1));
                $o['sd/frei_gb'] = $zahl(round($frei / 1024, 1));
                $o['sd/belegt_prozent'] = $zahl(round(100 * (1 - $frei / $kap), 1));
            }
        }

        // Info
        foreach (['modell', 'firmware', 'name'] as $k) {
            if (isset($info[$k]) && $info[$k] !== '') {
                $o['info/' . $k] = (string)$info[$k];
            }
        }
        return $o;
    }
}
