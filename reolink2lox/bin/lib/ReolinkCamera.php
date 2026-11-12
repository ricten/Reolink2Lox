<?php
/**
 * Eine Kamera: Anmeldung, Abfragen (Bewegung/KI jede Sekunde, Einstellungen und Werte seltener), Befehle mit Quittung,
 * Veroeffentlichen nur geaenderter Werte.
 *
 * Alle Anfragen laufen nacheinander mit Mindestabstand (die Kamera ist empfindlich). Es geht hoechstens eine Anfrage je Durchlauf:
 * zuerst Stopp einer Fahrt, dann Befehle, dann das Nachlesen eines geaenderten Wertes, dann Bewegung/KI, dann die seltenen Werte.
 *
 * Status: eine nicht erreichbare Kamera ist ein Fehler (status/ok = 0); Anmeldefehler werden nur selten wiederholt (Sperre der Kamera vermeiden).
 */
final class ReolinkCamera
{
    private const MIN_ABSTAND = 0.25;     // Sekunden zwischen zwei Anfragen
    private const REFRESH = 300;            // Sekunden: alle Werte auch ohne Aenderung erneut senden
    private const ACK_TIMEOUT = 30;         // Sekunden bis "keine Antwort der Kamera" fuer einen Befehl in der Warteschlange
    private const MAX_WARTESCHLANGE = 10;
    private const FEHLER_BIS_GETRENNT = 3;  // aufeinanderfolgende Fehlschlaege bis die Verbindung als verloren gilt
    private const MIN_BEFEHLSABSTAND = 0.3;

    public string $name;
    private ReolinkApi $api;
    private bool $bereit = false;               // angemeldet und Kamera antwortet
    private bool $versucht = false;
    private string $error = '';
    private string $art = '';
    private int $nextConnect = 0;
    private int $versuche = 0;
    private int $fehl = 0;
    private int $verbundenSeit = 0;
    private int $letzterErfolg = 0;
    private array $info = [];                   // modell, firmware, name
    private array $st = [];                     // Zustand (siehe ReolinkMapper)
    private array $ability = [];                // value.Ability von GetAbility (leer = unbekannt)
    /** @var array<string,array{cmd:string,param:array,parse:callable,faellig:float,ok:?bool}> */
    private array $lesen = [];
    private array $nachlesen = [];              // Schluessel von $lesen => Zeitpunkt, ab dem der Wert nach einem Befehl neu gelesen wird (die Kamera braucht einen Moment)
    private array $queue = [];                  // [befehl, wert, frist]
    private array $acks = [];                   // fertige Quittungen: [befehl, text]
    private array $sent = [];                   // Topic => [Wert, Zeit]
    private float $frei = 0.0;                  // fruehestens dann die naechste Anfrage
    private float $nextAlarm = 0.0;
    private ?float $stopAm = null;              // Zeitpunkt, an dem eine PTZ-Fahrt gestoppt wird
    private float $lastCmd = 0.0;
    private bool $kiAbfragen = true;
    private bool $mdAbfragen = true;
    private int $abfragen = 0;                  // Zahl der Alarm-Abfragen seit dem Verbinden (nur fuer Statistik)
    private array $unterstuetzt = [];           // Schluessel => bool (aus Faehigkeiten und Probeabfragen)
    private ?array $folge = null;               // Befehl mit mehreren Schritten: ['befehl','wert','b','cmds','rest','zeit'] (die Kamera braucht Zeit zwischen den Schritten)

    private array $cfg;
    private Logger $log;
    private int $abfrageAlarm;
    private int $abfrageStatus;
    private int $ptzDauer;
    private int $ptzGeschwindigkeit;
    private ?string $datenDir;

    public function __construct(array $cfg, Logger $log, int $abfrageAlarm = 1, int $abfrageStatus = 30,
                                int $ptzDauer = 1, int $ptzGeschwindigkeit = 32, ?string $datenDir = null)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->abfrageAlarm = $abfrageAlarm;
        $this->abfrageStatus = $abfrageStatus;
        $this->ptzDauer = $ptzDauer;
        $this->ptzGeschwindigkeit = $ptzGeschwindigkeit;
        $this->datenDir = $datenDir;
        $this->name = $cfg['name'];
        $this->api = self::neueApi($cfg);
    }

    private static function neueApi(array $c): ReolinkApi
    {
        $zeit = getenv('RLK_DEV_DIR') ? 3.0 : 10.0;
        return new ReolinkApi($c['host'], (int)($c['port'] ?? 0), (string)($c['schema'] ?? 'auto'), $c['benutzer'], $c['passwort'], 2.0, $zeit, self::MIN_ABSTAND);
    }

    /** Einstellungen aktualisieren; bei geaendertem Zugang wird neu angemeldet. */
    public function configure(array $cfg, int $abfrageAlarm, int $abfrageStatus, int $ptzDauer, int $ptzGeschwindigkeit): void
    {
        $zugang = fn(array $c) => [$c['host'], $c['port'] ?? 0, $c['schema'] ?? 'auto', $c['benutzer'], $c['passwort']];
        if ($zugang($cfg) !== $zugang($this->cfg)) {
            $this->abmelden();
            $this->api = self::neueApi($cfg);
            $this->nextConnect = 0;
            $this->versuche = 0;
        }
        $this->cfg = $cfg;
        $this->name = $cfg['name'];
        $this->abfrageAlarm = $abfrageAlarm;
        $this->abfrageStatus = $abfrageStatus;
        $this->ptzDauer = $ptzDauer;
        $this->ptzGeschwindigkeit = $ptzGeschwindigkeit;
    }

    public function connected(): bool
    {
        return $this->bereit;
    }

    // ------------------------------------------------------------------ Verbindung

    private function abmelden(): void
    {
        if ($this->bereit) {
            if ($this->stopAm !== null) {
                $this->api->call([['cmd' => 'PtzCtrl', 'param' => ['channel' => 0, 'op' => 'Stop']]]);
                $this->stopAm = null;
            }
            $this->api->logout();
        }
        $this->api->vergiss();
        $this->bereit = false;
        $this->st = [];
        $this->lesen = [];
        $this->queue = [];
        $this->folge = null;
    }

    /** Anmelden, Geraetedaten und Faehigkeiten lesen. */
    private function verbinden(int $now): void
    {
        $this->versucht = true;
        if (!$this->api->login()) {
            $this->verbindenFehlgeschlagen($now);
            return;
        }
        $this->frei = microtime(true) + self::MIN_ABSTAND;
        $dev = $this->api->einzeln('GetDevInfo');
        if ($dev === null || !$dev['ok'] || !is_array($dev['value']['DevInfo'] ?? null)) {
            if ($dev !== null) {
                $this->api->lastArt = ReolinkApi::ART_ANTWORT;
                $this->api->lastError = $dev['rsp'] === -26 ? 'Der Kamera-Benutzer darf die Geräteinformationen nicht lesen' : 'Die Kamera liefert keine Geräteinformationen';
            }
            $this->verbindenFehlgeschlagen($now);
            return;
        }
        $d = $dev['value']['DevInfo'];
        $this->info = ['modell' => (string)($d['model'] ?? ''), 'firmware' => (string)($d['firmVer'] ?? ''), 'name' => (string)($d['name'] ?? '')];
        $ab = $this->api->call([['cmd' => 'GetAbility', 'action' => 1, 'param' => ['User' => ['userName' => $this->cfg['benutzer']]]]]);
        $this->ability = ($ab !== null && $ab[0]['ok'] && is_array($ab[0]['value']['Ability'] ?? null)) ? $ab[0]['value']['Ability'] : [];
        $this->bereit = true;
        $this->versuche = 0;
        $this->fehl = 0;
        $this->error = '';
        $this->art = '';
        $this->verbundenSeit = $now;
        $this->letzterErfolg = $now;
        $this->abfragen = 0;
        $this->kiAbfragen = true;
        $this->mdAbfragen = true;
        $this->unterstuetzt = [];
        $this->st = [];
        $this->vorbereiten();
        $this->nextAlarm = microtime(true);
        $this->log->info("[{$this->name}] mit der Kamera verbunden: " . ($this->info['modell'] ?: 'Modell unbekannt') . ', Firmware ' . ($this->info['firmware'] ?: '?'));
    }

    private function verbindenFehlgeschlagen(int $now): void
    {
        $this->art = $this->api->lastArt;
        $this->error = $this->api->lastError;
        $auth = $this->art === ReolinkApi::ART_ANMELDUNG;
        [$basis, $max] = $auth ? [60, 900] : ($this->art === ReolinkApi::ART_SITZUNGEN ? [30, 300] : [2, 60]);
        $this->nextConnect = $now + (int)min($max, $basis * (2 ** min(8, $this->versuche)));
        $this->versuche++;
        if ($auth) {
            $this->log->error("[{$this->name}] {$this->error} Neuer Versuch erst in " . ($this->nextConnect - $now) . ' s (die Kamera sperrt sonst eventuell den Zugang).', 'auth-' . $this->name);
        } elseif ($this->art === ReolinkApi::ART_SITZUNGEN) {
            $this->log->error("[{$this->name}] {$this->error}", 'sitz-' . $this->name);
        } else {
            $this->log->warn("[{$this->name}] {$this->error}", 'down-' . $this->name);
        }
    }

    /** Verbindung als verloren behandeln (nach mehreren Fehlschlaegen in Folge). */
    private function trennen(int $now): void
    {
        $this->art = $this->api->lastArt;
        $this->error = $this->api->lastError;
        $this->log->warn("[{$this->name}] Verbindung zur Kamera verloren: {$this->error}", 'lost-' . $this->name);
        $this->bereit = false;
        $this->api->vergiss();
        foreach ($this->queue as [$befehl]) {
            $this->acks[] = [$befehl, 'abgelehnt: Kamera nicht erreichbar'];
        }
        $this->queue = [];
        $this->folge = null;
        $this->stopAm = null;
        $this->versuche = 0;
        $this->nextConnect = $now + 2;
    }

    /** Eine Anfrage senden, Fehlschlaege zaehlen. @return list<array>|null */
    private function anfrage(array $cmds, int $now): ?array
    {
        $r = $this->api->call($cmds);
        $this->frei = microtime(true) + self::MIN_ABSTAND;
        if ($r === null) {
            $this->fehl++;
            $this->art = $this->api->lastArt;
            $this->error = $this->api->lastError;
            if ($this->fehl >= self::FEHLER_BIS_GETRENNT || !$this->bereit) {
                $this->trennen($now);
            }
            return null;
        }
        $this->fehl = 0;
        $this->letzterErfolg = $now;
        return $r;
    }

    // ------------------------------------------------------------------ Faehigkeiten und seltene Werte

    private function abilityVer(string $key): ?int
    {
        if (!$this->ability) {
            return null;
        }
        $c = is_array($this->ability['abilityChn'][0] ?? null) ? $this->ability['abilityChn'][0] : [];
        foreach ([$c[$key] ?? null, $this->ability[$key] ?? null] as $a) {
            if (is_array($a) && (int)($a['permit'] ?? 0) > 0) {
                return (int)($a['ver'] ?? 0);
            }
        }
        return 0;
    }

    /** Neuere Befehle (…V20) benutzen, wenn die Faehigkeit der Kamera das anzeigt (ohne Angabe: ja). */
    private function v20(string $abilityKey): bool
    {
        $v = $this->abilityVer($abilityKey);
        return $v === null ? true : $v >= 1;
    }

    /** @return array<string,bool> */
    private function v20Flags(): array
    {
        return ['rec' => $this->v20('recSchedule'), 'push' => $this->v20('push'), 'email' => $this->v20('email'), 'ftp' => $this->v20('ftp')];
    }

    private function vorbereiten(): void
    {
        $ch = ['channel' => 0];
        $v = $this->v20Flags();
        $enable = fn(string $k, string $v20pfad) => fn(array $val) => [$k => (int)($val[$v20pfad]['enable'] ?? $val[$v20pfad]['schedule']['enable'] ?? 0)];
        $def = [
            'ir' => ['GetIrLights', $ch, fn(array $x) => isset($x['IrLights']['state']) ? ['ir' => (string)$x['IrLights']['state']] : []],
            'whiteled' => ['GetWhiteLed', $ch, fn(array $x) => is_array($x['WhiteLed'] ?? null) ? ['whiteled' => $x['WhiteLed']] : []],
            'powerled' => ['GetPowerLed', $ch, fn(array $x) => isset($x['PowerLed']['state']) ? ['powerled' => (string)$x['PowerLed']['state']] : []],
            'zoomfocus' => ['GetZoomFocus', $ch, fn(array $x) => is_array($x['ZoomFocus'] ?? null)
                ? array_filter(['zoom' => $x['ZoomFocus']['zoom']['pos'] ?? null, 'fokus' => $x['ZoomFocus']['focus']['pos'] ?? null], fn($p) => $p !== null) : []],
            'autofokus' => ['GetAutoFocus', $ch, fn(array $x) => isset($x['AutoFocus']['disable']) ? ['autofokus_aus' => (int)$x['AutoFocus']['disable']] : []],
            'rec' => [$v['rec'] ? 'GetRecV20' : 'GetRec', $ch, $enable('rec', 'Rec')],
            'push' => [$v['push'] ? 'GetPushV20' : 'GetPush', $ch, $enable('push', 'Push')],
            'email' => [$v['email'] ? 'GetEmailV20' : 'GetEmail', $ch, $enable('email', 'Email')],
            'ftp' => [$v['ftp'] ? 'GetFtpV20' : 'GetFtp', $ch, $enable('ftp', 'Ftp')],
            'perf' => ['GetPerformance', [], fn(array $x) => is_array($x['Performance'] ?? null) ? ['perf' => $x['Performance']] : []],
            'hdd' => ['GetHddInfo', [], fn(array $x) => is_array($x['HddInfo'] ?? null) ? ['hdd' => $x['HddInfo']] : []],
        ];
        if (($this->abilityVer('ptzCtrl') ?? 1) > 0) {
            $def['presets'] = ['GetPtzPreset', $ch, function (array $x): array {
                $l = [];
                foreach ((array)($x['PtzPreset'] ?? []) as $p) {
                    if (is_array($p) && (int)($p['enable'] ?? 0) === 1) {
                        $l[] = ['id' => (int)$p['id'], 'name' => (string)($p['name'] ?? '')];
                    }
                }
                return ['presets' => $l];
            }];
        }
        $this->lesen = [];
        $t = microtime(true) + 0.5;
        foreach ($def as $k => [$cmd, $param, $parse]) {
            $this->lesen[$k] = ['cmd' => $cmd, 'param' => $param, 'parse' => $parse, 'faellig' => $t, 'ok' => null];
            $t += 0.5;                                  // beim Start ueber einige Sekunden verteilt, nicht alles auf einmal
        }
    }

    /** Antwort eines seltenen Wertes einarbeiten. */
    private function lesenAuswerten(string $k, array $a, int $now): void
    {
        $e = &$this->lesen[$k];
        if (!$a['ok']) {
            if ($a['rsp'] === -9 || str_contains(strtolower($a['detail']), 'not support')) {
                $e['ok'] = false;                       // gibt es an dieser Kamera nicht: nicht mehr abfragen
                $this->unterstuetzt[$k] = false;
                $this->log->debug("[{$this->name}] {$e['cmd']}: von der Kamera nicht unterstuetzt");
            } else {
                $this->log->debug("[{$this->name}] {$e['cmd']}: Fehler {$a['rsp']} {$a['detail']}");
            }
            return;
        }
        $e['ok'] = true;
        $this->unterstuetzt[$k] = true;
        foreach (($e['parse'])($a['value']) as $key => $val) {
            $this->st[$key] = $val;
        }
    }

    // ------------------------------------------------------------------ Durchlauf

    public function tick(int $now): void
    {
        if (!$this->bereit) {
            if ($now >= $this->nextConnect) {
                $this->verbinden($now);
            }
            $this->ackFristen($now);
            return;
        }
        $jetzt = microtime(true);
        if ($jetzt < $this->frei) {
            return;
        }
        // 1. eine fahrende PTZ-Kamera rechtzeitig stoppen
        if ($this->stopAm !== null && $jetzt >= $this->stopAm) {
            $this->stopAm = null;
            $r = $this->anfrage([['cmd' => 'PtzCtrl', 'param' => ['channel' => 0, 'op' => 'Stop']]], $now);
            if ($r !== null && !$r[0]['ok']) {
                $this->log->warn("[{$this->name}] PTZ-Stopp abgelehnt: {$r[0]['detail']}", 'ptzstop-' . $this->name);
            }
            return;
        }
        // 2. weiterer Schritt eines Befehls mit mehreren Schritten (solange einer ansteht, bleiben andere Befehle in der Warteschlange)
        if ($this->folge !== null) {
            if ($jetzt >= $this->folge['zeit']) {
                $this->folgeSchritt($now);
                return;
            }
        } elseif ($this->queue) {
            $this->naechsterBefehl($now);
            return;
        }
        // 3. nach einem Befehl den geaenderten Wert sofort neu lesen
        foreach ($this->nachlesen as $k => $faellig) {
            if ($jetzt >= $faellig) {
                unset($this->nachlesen[$k]);
                $this->leseEinen($k, $now);
                return;
            }
        }
        // 4. Bewegung und KI
        if ($jetzt >= $this->nextAlarm) {
            $this->nextAlarm = $jetzt + $this->abfrageAlarm;
            $this->alarmAbfragen($now);
            return;
        }
        // 5. seltene Werte: von den faelligen der, der am laengsten wartet (einer je Durchlauf, damit keiner verhungert)
        $wahl = null;
        foreach ($this->lesen as $k => $e) {
            if ($e['ok'] !== false && $jetzt >= $e['faellig'] && ($wahl === null || $e['faellig'] < $this->lesen[$wahl]['faellig'])) {
                $wahl = $k;
            }
        }
        if ($wahl !== null) {
            $this->leseEinen($wahl, $now);
            return;
        }
        $this->ackFristen($now);
    }

    private function leseEinen(string $k, int $now): void
    {
        if (!isset($this->lesen[$k]) || $this->lesen[$k]['ok'] === false) {
            return;
        }
        $e = $this->lesen[$k];
        $this->lesen[$k]['faellig'] = microtime(true) + $this->abfrageStatus;
        $r = $this->anfrage([['cmd' => $e['cmd'], 'param' => $e['param'] ?: new stdClass()]], $now);
        if ($r !== null) {
            $this->lesenAuswerten($k, $r[0], $now);
        }
    }

    private function alarmAbfragen(int $now): void
    {
        $cmds = [];
        if ($this->mdAbfragen) {
            $cmds[] = ['cmd' => 'GetMdState', 'param' => ['channel' => 0]];
        }
        if ($this->kiAbfragen) {
            $cmds[] = ['cmd' => 'GetAiState', 'param' => ['channel' => 0]];
        }
        if (!$cmds) {
            return;
        }
        $r = $this->anfrage($cmds, $now);
        if ($r === null) {
            return;
        }
        $this->abfragen++;
        $i = 0;
        $md = null;
        $ai = null;
        if ($this->mdAbfragen) {
            $a = $r[$i++];
            if ($a['ok'] && isset($a['value']['state'])) {
                $md = (int)$a['value']['state'] === 1 ? 1 : 0;
            } else {
                $this->mdAbfragen = false;
                $this->log->debug("[{$this->name}] GetMdState nicht verfuegbar: {$a['detail']}");
            }
        }
        if ($this->kiAbfragen) {
            $a = $r[$i];
            if ($a['ok']) {
                $ai = ReolinkMapper::aiZustand($a['value']);
            } else {
                $this->kiAbfragen = false;
                $this->log->debug("[{$this->name}] GetAiState nicht verfuegbar: {$a['detail']}");
            }
        }
        $vorher = ((int)($this->st['md'] ?? 0)) | (array_sum((array)($this->st['ai'] ?? [])) > 0 ? 1 : 0);
        if ($md !== null) {
            $this->st['md'] = $md;
        }
        if ($ai !== null) {
            $this->st['ai'] = $ai;
        }
        $jetzt = ((int)($this->st['md'] ?? 0)) | (array_sum((array)($this->st['ai'] ?? [])) > 0 ? 1 : 0);
        if ($jetzt === 1 && $vorher === 0 && $this->abfragen > 1) {
            $this->st['alarm_ts'] = time();            // steigende Flanke (nicht gleich beim Start)
        }
    }

    // ------------------------------------------------------------------ Befehle

    /** Befehle, die diese Kamera kennt (ein noch ungeprueftes Merkmal zaehlt als vorhanden). @return list<string> */
    public function verfuegbar(): array
    {
        $unbekanntOk = fn(string $k) => ($this->unterstuetzt[$k] ?? true) !== false;
        $ptz = ($this->abilityVer('ptzCtrl') ?? 1) > 0;
        $zf = ($this->abilityVer('supportZoom') ?? 1) > 0 || ($this->abilityVer('supportFocus') ?? 1) > 0;
        $sirene = ($this->abilityVer('supportAudioAlarm') ?? 1) > 0 || ($this->abilityVer('alarmAudio') ?? 1) > 0;
        $karte = [
            'scheinwerfer' => $unbekanntOk('whiteled'), 'scheinwerfer_modus' => $unbekanntOk('whiteled'), 'scheinwerfer_helligkeit' => $unbekanntOk('whiteled'),
            'ir' => $unbekanntOk('ir'), 'status_led' => $unbekanntOk('powerled'),
            'ptz' => $ptz, 'ptz_position' => $ptz, 'zoom' => $zf && $unbekanntOk('zoomfocus'), 'fokus' => $zf && $unbekanntOk('zoomfocus'), 'autofokus' => $unbekanntOk('autofokus'),
            'sirene' => $sirene, 'aufnahme' => $unbekanntOk('rec'), 'push' => $unbekanntOk('push'), 'email' => $unbekanntOk('email'), 'ftp' => $unbekanntOk('ftp'),
        ];
        return array_keys(array_filter($karte));
    }

    /**
     * Befehl aus Loxone. Gibt die erste Quittung zurueck: "angenommen" oder "abgelehnt: <Grund>"; die Ausfuehrung folgt im naechsten Durchlauf (takeAcks).
     */
    public function befehl(string $befehl, string $wert, float $jetzt): string
    {
        $grund = ReolinkCommands::pruefen($this->cfg['befehle'], $befehl, $wert, $this->bereit ? $this->verfuegbar() : []);
        if ($grund === null && !$this->bereit) {
            $grund = 'Kamera nicht verbunden';
        }
        if ($grund === null && $befehl !== 'ptz' && $jetzt - $this->lastCmd < self::MIN_BEFEHLSABSTAND) {
            $grund = 'zu schnell (höchstens ein Befehl alle 0,3 Sekunden)';
        }
        if ($grund === null && count($this->queue) >= self::MAX_WARTESCHLANGE) {
            $grund = 'zu viele Befehle in der Warteschlange';
        }
        if ($grund !== null) {
            $this->log->warn("[{$this->name}] Befehl $befehl abgelehnt: $grund");
            return 'abgelehnt: ' . $grund;
        }
        $this->lastCmd = $jetzt;
        if ($befehl === 'ptz' && trim($wert) === 'stopp') {
            $this->stopAm = null;                       // der Stopp-Befehl selbst folgt gleich
        }
        $this->queue[] = [$befehl, trim($wert), (int)$jetzt + self::ACK_TIMEOUT];
        return 'angenommen';
    }

    private function naechsterBefehl(int $now): void
    {
        [$befehl, $wert] = array_shift($this->queue);
        $ctx = ['kanal' => 0, 'ptz_geschwindigkeit' => $this->ptzGeschwindigkeit, 'v20' => $this->v20Flags()];
        if ($befehl === 'scheinwerfer') {
            if ($wert === '1') {
                $this->zeitplanMerken();
            } else {
                $ctx['zeitplan_alt'] = $this->zeitplanLaden();
            }
        }
        $b = ReolinkCommands::bauen($befehl, $wert, $ctx);
        $r = $this->anfrage($b['cmds'], $now);
        if ($r === null) {
            $this->acks[] = [$befehl, 'abgelehnt: Kamera nicht erreichbar (' . $this->error . ')'];
            $this->log->warn("[{$this->name}] Befehl $befehl nicht ausgefuehrt: {$this->error}");
            return;
        }
        $text = $this->auswerten($r);
        if ($text === 'ausgefuehrt' && $b['folge']) {
            $this->folge = ['befehl' => $befehl, 'wert' => $wert, 'b' => $b, 'cmds' => $b['folge'][0], 'rest' => array_slice($b['folge'], 1), 'zeit' => microtime(true) + 2.0];
            return;                                     // die Quittung kommt nach dem letzten Schritt
        }
        $this->abschliessen($befehl, $wert, $b, $text);
    }

    /** Naechster Schritt eines Befehls mit mehreren Schritten. */
    private function folgeSchritt(int $now): void
    {
        $f = $this->folge;
        $this->folge = null;
        $r = $this->anfrage($f['cmds'], $now);
        if ($r === null) {
            $this->acks[] = [$f['befehl'], 'abgelehnt: Kamera nicht erreichbar (' . $this->error . ')'];
            $this->log->warn("[{$this->name}] Befehl {$f['befehl']} nicht ganz ausgefuehrt: {$this->error}");
            return;
        }
        $text = $this->auswerten($r);
        if ($text === 'ausgefuehrt' && $f['rest']) {
            $f['cmds'] = $f['rest'][0];
            $f['rest'] = array_slice($f['rest'], 1);
            $f['zeit'] = microtime(true) + 2.0;
            $this->folge = $f;
            return;
        }
        $this->abschliessen($f['befehl'], $f['wert'], $f['b'], $text);
    }

    /** @param list<array{ok:bool,rsp:int,detail:string}> $r */
    private function auswerten(array $r): string
    {
        foreach ($r as $a) {
            $t = ReolinkCommands::antwort($a);
            if ($t !== 'ausgefuehrt') {
                return $t;
            }
        }
        return 'ausgefuehrt';
    }

    private function abschliessen(string $befehl, string $wert, array $b, string $text): void
    {
        $this->acks[] = [$befehl, $text];
        $this->log->info("[{$this->name}] Befehl $befehl: $text");
        if ($text !== 'ausgefuehrt') {
            return;
        }
        if ($b['bewegt']) {
            $this->stopAm = microtime(true) + $this->ptzDauer;
        }
        if ($befehl === 'scheinwerfer' && $wert === '0') {
            $this->zeitplanLoeschen();
        }
        if ($b['lesen'] !== null && isset($this->lesen[$b['lesen']])) {
            // der Scheinwerfer uebernimmt eine Aenderung erst nach ein bis zwei Sekunden, alles andere fast sofort
            $this->nachlesen[$b['lesen']] = microtime(true) + ($b['lesen'] === 'whiteled' ? 2.5 : 1.0);
        }
    }

    // ------------------------------------------------------------------ Zeitplan des Scheinwerfers merken
    // "Scheinwerfer an" setzt den Zeitplan der Kamera auf ganztags (sonst schaltet die Kamera ausserhalb des Zeitplans wieder ab).
    // Der bisherige Zeitplan wird in einer kleinen Datei gemerkt und bei "Scheinwerfer aus" wiederhergestellt.

    private function zeitplanDatei(): ?string
    {
        return $this->datenDir !== null ? $this->datenDir . '/zeitplan_' . preg_replace('/[^a-z0-9_-]/', '', $this->name) . '.json' : null;
    }

    private function zeitplanMerken(): void
    {
        $f = $this->zeitplanDatei();
        $z = $this->st['whiteled']['LightingSchedule'] ?? null;
        if ($f === null || !is_array($z) || !isset($z['StartHour'], $z['StartMin'], $z['EndHour'], $z['EndMin'])) {
            return;
        }
        if (ReolinkCommands::istGanztags($z)) {
            return;                                    // schon ganztags (Dauerlicht oder von uns gesetzt): nichts zu merken
        }
        Util::jsonSave($f, ['zeitplan' => ['StartHour' => (int)$z['StartHour'], 'StartMin' => (int)$z['StartMin'], 'EndHour' => (int)$z['EndHour'], 'EndMin' => (int)$z['EndMin']]]);
    }

    private function zeitplanLaden(): ?array
    {
        $f = $this->zeitplanDatei();
        $z = $f !== null && is_file($f) ? (Util::jsonLoad($f)['zeitplan'] ?? null) : null;
        return is_array($z) && isset($z['StartHour'], $z['StartMin'], $z['EndHour'], $z['EndMin']) ? $z : null;
    }

    private function zeitplanLoeschen(): void
    {
        $f = $this->zeitplanDatei();
        if ($f !== null && is_file($f)) {
            @unlink($f);
        }
    }

    private function ackFristen(int $now): void
    {
        foreach ($this->queue as $i => [$befehl, , $frist]) {
            if ($now >= $frist) {
                unset($this->queue[$i]);
                $this->acks[] = [$befehl, 'abgelehnt: keine Antwort der Kamera'];
                $this->log->warn("[{$this->name}] Befehl $befehl: keine Antwort der Kamera");
            }
        }
        $this->queue = array_values($this->queue);
    }

    /** Wahr, wenn alle seltenen Werte einmal gelesen (oder als nicht unterstuetzt erkannt) wurden und Bewegung/KI abgefragt ist. */
    public function fertigGelesen(): bool
    {
        if (!$this->bereit || $this->abfragen < 1) {
            return false;
        }
        foreach ($this->lesen as $e) {
            if ($e['ok'] === null) {
                return false;
            }
        }
        return true;
    }

    /** Fertige Quittungen abholen: Liste von [befehl, text]. */
    public function takeAcks(): array
    {
        $a = $this->acks;
        $this->acks = [];
        return $a;
    }

    // ------------------------------------------------------------------ Werte

    /** @return array<string,string> alle aktuellen Topics (relativ) ausser verbunden */
    public function werte(): array
    {
        return $this->st || $this->info ? ReolinkMapper::map($this->st, $this->info) : [];
    }

    /**
     * Geaenderte Werte (und alle 5 Minuten alle) an den LoxBerry-Broker senden.
     * @return int Zahl der gesendeten Topics
     */
    public function veroeffentlichen(MqttClient $out, int $now): int
    {
        $n = 0;
        $alle = ['verbunden' => $this->bereit ? '1' : '0'];
        if ($this->bereit) {
            $alle += $this->werte();
        }
        foreach ($alle as $t => $v) {
            $key = "reolink/{$this->name}/$t";
            if (!isset($this->sent[$key]) || $this->sent[$key][0] !== $v || $now - $this->sent[$key][1] >= self::REFRESH) {
                if ($out->publish($key, $v)) {
                    $this->sent[$key] = [$v, $now];
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Beim neuen Verbinden zum LoxBerry-Broker alle Werte erneut senden. */
    public function vergesseGesendet(): void
    {
        $this->sent = [];
    }

    public function close(): void
    {
        $this->abmelden();
    }

    /** Daten fuer die Weboberflaeche (gespeicherte Positionen, Faehigkeiten). */
    public function details(): array
    {
        return ['presets' => $this->st['presets'] ?? [], 'befehle' => $this->bereit ? $this->verfuegbar() : []];
    }

    /**
     * Zustand fuer status.json und Healthcheck.
     * @return array{ok:bool,error:string,art:string,verbunden:bool,modell:?string,firmware:?string,werte:int,abfragen:int}
     */
    public function status(int $now): array
    {
        $base = ['verbunden' => $this->bereit, 'modell' => $this->info['modell'] ?? null, 'firmware' => $this->info['firmware'] ?? null,
            'werte' => count($this->werte()), 'abfragen' => $this->abfragen, 'seit' => $this->verbundenSeit, 'letzter_erfolg' => $this->letzterErfolg,
            'befehle' => $this->bereit ? $this->verfuegbar() : [], 'presets' => $this->st['presets'] ?? [],
            'topics' => $this->bereit ? $this->werte() : []];       // fuer "Sofort abrufen" in der Weboberflaeche (ohne eigene Anmeldung an der Kamera)
        if ($this->bereit) {
            return ['ok' => true, 'error' => '', 'art' => ''] + $base;
        }
        if (!$this->versucht) {
            return ['ok' => true, 'error' => '', 'art' => ''] + $base;
        }
        return ['ok' => false, 'error' => $this->error, 'art' => $this->art] + $base;
    }
}
