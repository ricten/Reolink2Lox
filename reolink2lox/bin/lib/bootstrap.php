<?php
/**
 * Gemeinsamer Start fuer Dienst und Weboberflaeche: Klassen, Zeitzone und Pfade.
 * Ausserhalb von LoxBerry (Entwicklung/Tests) greift RLK_DEV_DIR als Basisverzeichnis.
 * Util, Logger, MqttClient und GatewayInfo kommen aus ../common/php (build.sh kopiert sie nach bin/lib).
 */
date_default_timezone_set('Europe/Berlin');     // PHP auf dem LoxBerry laeuft auf UTC

foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo', 'ReolinkConfig', 'ReolinkHttp', 'ReolinkApi', 'ReolinkMapper', 'ReolinkCommands', 'ReolinkCamera', 'AppVersion'] as $cls) {
    // im ZIP liegt alles in bin/lib; im Quellbaum kommen die gemeinsamen Klassen aus ../common/php
    $f = __DIR__ . '/' . $cls . '.php';
    require_once is_file($f) ? $f : __DIR__ . '/../../../../common/php/' . $cls . '.php';
}

final class Paths
{
    public string $folder;
    public string $config;     // reolink.json
    public string $logfile;
    public string $data;       // Datenordner
    public string $run;        // /dev/shm/<folder>
    public string $bin;

    public static function detect(): Paths
    {
        $p = new Paths();
        $dev = getenv('RLK_DEV_DIR');
        if ($dev) {
            $p->folder = getenv('RLK_DEV_FOLDER') ?: 'reolink2lox';
            $p->config = $dev . '/reolink.json';
            $p->logfile = $dev . '/reolink.log';
            $p->data = $dev . '/data';
            $p->run = getenv('RLK_DEV_RUN') ?: $dev . '/run';
            $p->bin = dirname(__DIR__);
        } else {
            require_once 'loxberry_system.php';
            $p->folder = LBPPLUGINDIR;
            $p->config = LBPCONFIGDIR . '/reolink.json';
            $p->logfile = LBPLOGDIR . '/reolink.log';
            $p->data = LBPDATADIR;
            $p->run = '/dev/shm/' . LBPPLUGINDIR;
            $p->bin = LBPBINDIR;
        }
        if (!is_dir($p->run)) {
            @mkdir($p->run, 0775, true);
        }
        if (!is_dir($p->data)) {
            @mkdir($p->data, 0775, true);
        }
        return $p;
    }

    public function statusFile(): string
    {
        return $this->run . '/status.json';
    }

    public function pidFile(): string
    {
        return $this->run . '/reolink.pid';
    }

    public function lockFile(): string
    {
        return $this->run . '/run.lock';
    }
}
