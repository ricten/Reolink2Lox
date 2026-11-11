<?php
/**
 * Konfiguration des Plugins (reolink.json, Rechte 0600).
 * Enthaelt die Kamera-Passwoerter und ggf. das Passwort eines eigenen MQTT-Brokers: nie anzeigen, nie loggen, nie per MQTT senden.
 */
final class ReolinkConfig
{
    public const MAX_KAMERAS = 5;
    /** Stufen der Befehlsfreigabe je Kamera. */
    public const STUFEN = [
        'licht' => 'Licht (Scheinwerfer, Infrarot, Status-LED)',
        'ptz' => 'Schwenken, Neigen, Zoom, Fokus',
        'sirene' => 'Sirene',
        'einstellungen' => 'Aufnahme und Meldungen (Aufnahme, Push, E-Mail, FTP)',
    ];
    public const SCHEMEN = ['auto' => 'automatisch (erst HTTPS, dann HTTP)', 'https' => 'HTTPS', 'http' => 'HTTP'];

    public static function defaultKamera(string $name = ''): array
    {
        return ['aktiv' => false, 'name' => $name, 'host' => '', 'port' => 0, 'schema' => 'auto', 'benutzer' => '', 'passwort' => '',
            'befehle' => ['licht' => false, 'ptz' => false, 'sirene' => false, 'einstellungen' => false]];
    }

    public static function defaults(): array
    {
        $k = [self::defaultKamera('kamera1')];
        while (count($k) < self::MAX_KAMERAS) {
            $k[] = self::defaultKamera();
        }
        return [
            'abfrage_alarm' => 1,        // Sekunden: Bewegung und KI-Erkennung abfragen
            'abfrage_status' => 30,      // Sekunden: Einstellungen und Werte (Licht, Zoom, Aufnahme ...) abfragen
            'ptz_dauer' => 1,            // Sekunden: so lange fährt die Kamera bei einem Richtungsbefehl, dann stoppt das Plugin
            'ptz_geschwindigkeit' => 32, // 1-64
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
            'kameras' => $k,
        ];
    }

    public static function cleanBefehle($b): array
    {
        $b = is_array($b) ? $b : [];
        $out = [];
        foreach (array_keys(self::STUFEN) as $k) {
            $out[$k] = !empty($b[$k]);
        }
        return $out;
    }

    public static function cleanSchema($s): string
    {
        $s = strtolower(trim((string)$s));
        return isset(self::SCHEMEN[$s]) ? $s : 'auto';
    }

    /** Port der Kamera; 0 oder leer = Standard (443 bei HTTPS, 80 bei HTTP). */
    public static function cleanPort($p): int
    {
        $p = (int)$p;
        return $p > 0 ? min(65535, $p) : 0;
    }

    /** Host: Name oder IP, ohne Schema und Pfad. */
    public static function cleanHost(string $h): string
    {
        $h = trim($h);
        $h = (string)preg_replace('#^[a-z]+://#i', '', $h);
        $h = explode('/', $h, 2)[0];
        return (string)preg_replace('/[^A-Za-z0-9_.-]/', '', $h);
    }

    /** Benutzername der Kamera: keine Steuerzeichen. */
    public static function cleanBenutzer(string $s): string
    {
        return trim((string)preg_replace('/[\x00-\x1f\x7f]/', '', $s));
    }

    /** Passwort: Steuerzeichen entfernen, sonst unveraendert (Leerzeichen sind erlaubt). */
    public static function cleanPasswort(string $s): string
    {
        return (string)preg_replace('/[\x00-\x1f\x7f]/', '', $s);
    }

    private static function bereich($v, int $min, int $max, int $std): int
    {
        $v = (int)$v;
        return $v === 0 ? $std : max($min, min($max, $v));
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $k = [];
        foreach (is_array($c['kameras'] ?? null) ? $c['kameras'] : $def['kameras'] as $x) {
            $g = array_merge(self::defaultKamera(), is_array($x) ? $x : []);
            $g['aktiv'] = !empty($g['aktiv']);
            $g['port'] = self::cleanPort($g['port']);
            $g['schema'] = self::cleanSchema($g['schema']);
            $g['befehle'] = self::cleanBefehle($g['befehle']);
            $k[] = $g;
        }
        while (count($k) < self::MAX_KAMERAS) {
            $k[] = self::defaultKamera();
        }
        $out['kameras'] = array_slice($k, 0, self::MAX_KAMERAS);
        $out['abfrage_alarm'] = self::bereich($out['abfrage_alarm'], 1, 10, 1);
        $out['abfrage_status'] = self::bereich($out['abfrage_status'], getenv('RLK_DEV_DIR') ? 1 : 10, 3600, 30);
        $out['ptz_dauer'] = self::bereich($out['ptz_dauer'], 1, 10, 1);
        $out['ptz_geschwindigkeit'] = self::bereich($out['ptz_geschwindigkeit'], 1, 64, 32);
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        return $out;
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren. */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        // Jeder Reiter sendet nur seine Abschnitte; fehlende bleiben unveraendert.
        if (isset($post['abfrage_alarm'])) {
            $new['abfrage_alarm'] = self::bereich($post['abfrage_alarm'], 1, 10, 1);
            $new['abfrage_status'] = self::bereich($post['abfrage_status'] ?? 30, getenv('RLK_DEV_DIR') ? 1 : 10, 3600, 30);
            $new['ptz_dauer'] = self::bereich($post['ptz_dauer'] ?? 1, 1, 10, 1);
            $new['ptz_geschwindigkeit'] = self::bereich($post['ptz_geschwindigkeit'] ?? 32, 1, 64, 32);
        }
        if (is_array($post['kameras'] ?? null)) {
            for ($i = 0; $i < self::MAX_KAMERAS; $i++) {
                $p = is_array($post['kameras'][$i] ?? null) ? $post['kameras'][$i] : [];
                $pw = self::cleanPasswort((string)($p['passwort'] ?? ''));
                $new['kameras'][$i] = [
                    'aktiv' => !empty($p['aktiv']),
                    'name' => Util::name((string)($p['name'] ?? '')),
                    'host' => self::cleanHost((string)($p['host'] ?? '')),
                    'port' => self::cleanPort($p['port'] ?? 0),
                    'schema' => self::cleanSchema($p['schema'] ?? 'auto'),
                    'benutzer' => self::cleanBenutzer((string)($p['benutzer'] ?? '')),
                    'passwort' => $pw !== '' ? $pw : (string)($old['kameras'][$i]['passwort'] ?? ''),   // leer = unveraendert
                    'befehle' => self::cleanBefehle($p['befehle'] ?? []),
                ];
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return $new;
    }

    /**
     * Abzufragende Kameras (aktiv, mit Host und Benutzer). Der Name im Topic ist der Name, sonst kamera<Nummer>;
     * doppelte Namen bekommen eine Nummer.
     * @return list<array{name:string,host:string,port:int,schema:string,benutzer:string,passwort:string,befehle:array}>
     */
    public static function kameras(array $cfg): array
    {
        $out = [];
        $used = [];
        foreach ($cfg['kameras'] as $i => $g) {
            if (empty($g['aktiv']) || trim((string)$g['host']) === '' || trim((string)$g['benutzer']) === '') {
                continue;
            }
            $name = Util::name((string)$g['name']);
            $name = $name !== '' ? $name : 'kamera' . ($i + 1);
            $base = $name;
            for ($n = 2; isset($used[$name]); $n++) {
                $name = $base . '_' . $n;
            }
            $used[$name] = true;
            $out[] = ['name' => $name, 'host' => trim((string)$g['host']), 'port' => self::cleanPort($g['port'] ?? 0), 'schema' => self::cleanSchema($g['schema'] ?? 'auto'),
                'benutzer' => (string)$g['benutzer'], 'passwort' => (string)$g['passwort'], 'befehle' => self::cleanBefehle($g['befehle'] ?? [])];
        }
        return $out;
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }

    /** Alle Geheimnisse der Konfiguration (fuer die Maskierung im Log). @return list<string> */
    public static function secrets(array $cfg): array
    {
        $s = [(string)$cfg['mqtt']['passwort']];
        foreach ($cfg['kameras'] as $g) {
            $s[] = (string)($g['passwort'] ?? '');
        }
        return array_values(array_filter($s, fn($x) => strlen($x) >= 4));
    }
}
