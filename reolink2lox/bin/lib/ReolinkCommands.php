<?php
/**
 * Befehle aus Loxone an die Kamera: reolink/befehl/<name>/<befehl> = Wert, Quittung unter .../quittung (nie retained).
 *
 * Erlaubt sind nur die hier aufgelisteten Befehle, in vier Stufen, die je Kamera einzeln freizugeben sind (ab Werk alle gesperrt):
 *   licht          - Scheinwerfer, Infrarot-Licht, Status-LED
 *   ptz            - Schwenken, Neigen, Zoom, Fokus, gespeicherte Positionen, Autofokus
 *   sirene         - Alarmton der Kamera
 *   einstellungen  - Aufnahme, Push, E-Mail, FTP bei Alarm ein-/ausschalten
 * Nie vorgesehen: Neustart, Zuruecksetzen, Firmware, Benutzer, Netzwerk, Zugangsdaten, Formatieren.
 */
final class ReolinkCommands
{
    /** Befehl => [Stufe, erlaubte Werte als Regex, Bezeichnung]. */
    public const LISTE = [
        'scheinwerfer' => ['licht', '/^[01]$/', 'Scheinwerfer (1 an, 0 aus)'],
        'scheinwerfer_modus' => ['licht', '/^[0-3]$/', 'Betriebsart des Scheinwerfers (0 aus, 1 automatisch, 2 nachts, 3 Zeitplan)'],
        'scheinwerfer_helligkeit' => ['licht', '/^(100|[1-9]?[0-9])$/', 'Helligkeit des Scheinwerfers in Prozent (0–100)'],
        'ir' => ['licht', '/^[01]$/', 'Infrarot-Licht (1 automatisch, 0 aus)'],
        'status_led' => ['licht', '/^[01]$/', 'Status-LED der Kamera (1 an, 0 aus)'],
        'ptz' => ['ptz', '/^(links|rechts|hoch|runter|links_hoch|links_runter|rechts_hoch|rechts_runter|stopp|zoom_plus|zoom_minus)$/', 'Fahren: links, rechts, hoch, runter, links_hoch, links_runter, rechts_hoch, rechts_runter, zoom_plus, zoom_minus oder stopp (fährt die eingestellte Zeit, dann stoppt das Plugin)'],
        'ptz_position' => ['ptz', '/^(6[0-3]|[1-5]?[0-9])$/', 'Gespeicherte Position anfahren (Nummer 0–63 wie in der Kamera)'],
        'zoom' => ['ptz', '/^[0-9]{1,3}$/', 'Zoomstufe absolut (0 = Weitwinkel)'],
        'fokus' => ['ptz', '/^[0-9]{1,4}$/', 'Fokus absolut'],
        'autofokus' => ['ptz', '/^[01]$/', 'Autofokus (1 an, 0 aus)'],
        'sirene' => ['sirene', '/^[01]$/', 'Sirene (1 spielt den Alarmton einmal ab, 0 stoppt ihn)'],
        'aufnahme' => ['einstellungen', '/^[01]$/', 'Aufnahme (Zeitplan der Kamera) an/aus'],
        'push' => ['einstellungen', '/^[01]$/', 'Push-Meldungen der Kamera bei Alarm (1 an, 0 aus)'],
        'email' => ['einstellungen', '/^[01]$/', 'E-Mail der Kamera bei Alarm (1 an, 0 aus)'],
        'ftp' => ['einstellungen', '/^[01]$/', 'FTP-Upload der Kamera bei Alarm (1 an, 0 aus)'],
    ];

    private const PTZ_OPS = ['links' => 'Left', 'rechts' => 'Right', 'hoch' => 'Up', 'runter' => 'Down', 'links_hoch' => 'LeftUp', 'links_runter' => 'LeftDown',
        'rechts_hoch' => 'RightUp', 'rechts_runter' => 'RightDown', 'stopp' => 'Stop', 'zoom_plus' => 'ZoomInc', 'zoom_minus' => 'ZoomDec'];

    /** Welche Werte ein Befehl danach neu gelesen werden (Schluessel von ReolinkCamera::LESEN). */
    private const NACH = [
        'scheinwerfer' => 'whiteled', 'scheinwerfer_modus' => 'whiteled', 'scheinwerfer_helligkeit' => 'whiteled', 'ir' => 'ir', 'status_led' => 'powerled',
        'zoom' => 'zoomfocus', 'fokus' => 'zoomfocus', 'autofokus' => 'autofokus', 'aufnahme' => 'rec', 'push' => 'push', 'email' => 'email', 'ftp' => 'ftp',
    ];

    /** Zeitplan "ganztags" (von 0:00 bis 23:59) fuer den Scheinwerfer. */
    public const GANZTAGS = ['StartHour' => 0, 'StartMin' => 0, 'EndHour' => 23, 'EndMin' => 59];

    /** Ist der Zeitplan der Kamera ganztags? */
    public static function istGanztags(array $z): bool
    {
        return (int)($z['StartHour'] ?? -1) === 0 && (int)($z['StartMin'] ?? -1) === 0 && (int)($z['EndHour'] ?? -1) === 23 && (int)($z['EndMin'] ?? -1) === 59;
    }

    /** Befehlstopic zerlegen: reolink/befehl/<name>/<befehl>; Quittungen (…/quittung) und Fremdes ergeben null. @return array{0:string,1:string}|null */
    public static function parseTopic(string $topic): ?array
    {
        return preg_match('#^reolink/befehl/([a-z0-9_-]+)/([a-z0-9_]+)$#', $topic, $m) ? [$m[1], $m[2]] : null;
    }

    /**
     * Prueft einen Befehl gegen Freigabe, Wert und Faehigkeiten der Kamera. Liefert null (in Ordnung) oder den Ablehnungsgrund (ohne "abgelehnt: ").
     * @param array         $befehle     Freigaben der Kamera (licht, ptz, sirene, einstellungen)
     * @param array<string> $verfuegbar  Befehle, die diese Kamera kann (leer = noch unbekannt, nur Freigabe und Wert pruefen)
     */
    public static function pruefen(array $befehle, string $befehl, string $wert, array $verfuegbar = []): ?string
    {
        if (!isset(self::LISTE[$befehl])) {
            return 'Befehl nicht erlaubt';
        }
        [$stufe, $regex] = self::LISTE[$befehl];
        if (empty($befehle[$stufe])) {
            return 'Befehle sind gesperrt (Stufe „' . ReolinkConfig::STUFEN[$stufe] . '“ freigeben)';
        }
        if (!preg_match($regex, trim($wert))) {
            return 'ungültiger Wert';
        }
        if ($verfuegbar && !in_array($befehl, $verfuegbar, true)) {
            return 'diese Kamera unterstützt das nicht';
        }
        return null;
    }

    /**
     * Anfragen an die Kamera bauen.
     * @param array $ctx kanal (int), ptz_geschwindigkeit (int), v20 (Schluessel rec, push, email, ftp => bool: neue Befehle SetRecV20 usw.)
     * @return array{cmds:list<array>,folge:list<list<array>>,lesen:?string,bewegt:bool}  cmds: Befehle der ersten Anfrage; folge: weitere Anfragen, jede nach einer Pause von 2 s;
     *         lesen: danach neu zu lesender Wert; bewegt: PTZ faehrt (Stopp-Zeit starten)
     */
    public static function bauen(string $befehl, string $wert, array $ctx): array
    {
        $wert = trim($wert);
        $ch = (int)($ctx['kanal'] ?? 0);
        $an = $wert === '1';
        $v20 = fn(string $k) => !empty($ctx['v20'][$k]);
        $cmds = [];
        $folge = [];
        $bewegt = false;
        switch ($befehl) {
            case 'scheinwerfer':
                // an: Zeitplan-Betrieb mit ganztaegigem Zeitplan (sonst schaltet die Kamera ausserhalb des Zeitplans ab).
                // aus: Betriebsart aus; war vorher ein anderer Zeitplan eingestellt, wird er zuerst (mit Pause dazwischen) wiederhergestellt,
                // denn die Kamera uebernimmt einen Zeitplan nur zusammen mit der Betriebsart 3.
                if ($an) {
                    $cmds[] = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => $ch, 'state' => 1, 'mode' => 3, 'LightingSchedule' => self::GANZTAGS]]];
                } else {
                    $alt = $ctx['zeitplan_alt'] ?? null;
                    $aus = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => $ch, 'state' => 0, 'mode' => 0]]];
                    if (is_array($alt)) {
                        $cmds[] = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => $ch, 'state' => 0, 'mode' => 3, 'LightingSchedule' => $alt]]];
                        $folge[] = [$aus];
                    } else {
                        $cmds[] = $aus;
                    }
                }
                break;
            case 'scheinwerfer_modus':
                $cmds[] = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => $ch, 'mode' => (int)$wert]]];
                break;
            case 'scheinwerfer_helligkeit':
                $cmds[] = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => $ch, 'bright' => (int)$wert]]];
                break;
            case 'ir':
                $cmds[] = ['cmd' => 'SetIrLights', 'param' => ['IrLights' => ['channel' => $ch, 'state' => $an ? 'Auto' : 'Off']]];
                break;
            case 'status_led':
                $cmds[] = ['cmd' => 'SetPowerLed', 'param' => ['PowerLed' => ['channel' => $ch, 'state' => $an ? 'On' : 'Off']]];
                break;
            case 'ptz':
                $op = self::PTZ_OPS[$wert];
                $p = ['channel' => $ch, 'op' => $op];
                if ($op !== 'Stop') {
                    $p['speed'] = (int)($ctx['ptz_geschwindigkeit'] ?? 32);
                    $bewegt = true;
                }
                $cmds[] = ['cmd' => 'PtzCtrl', 'param' => $p];
                break;
            case 'ptz_position':
                $cmds[] = ['cmd' => 'PtzCtrl', 'param' => ['channel' => $ch, 'op' => 'ToPos', 'id' => (int)$wert, 'speed' => (int)($ctx['ptz_geschwindigkeit'] ?? 32)]];
                break;
            case 'zoom':
                $cmds[] = ['cmd' => 'StartZoomFocus', 'param' => ['ZoomFocus' => ['channel' => $ch, 'op' => 'ZoomPos', 'pos' => (int)$wert]]];
                break;
            case 'fokus':
                $cmds[] = ['cmd' => 'StartZoomFocus', 'param' => ['ZoomFocus' => ['channel' => $ch, 'op' => 'FocusPos', 'pos' => (int)$wert]]];
                break;
            case 'autofokus':
                $cmds[] = ['cmd' => 'SetAutoFocus', 'param' => ['AutoFocus' => ['channel' => $ch, 'disable' => $an ? 0 : 1]]];
                break;
            case 'sirene':
                $cmds[] = ['cmd' => 'AudioAlarmPlay', 'param' => $an
                    ? ['alarm_mode' => 'times', 'times' => 1, 'channel' => $ch]
                    : ['alarm_mode' => 'manul', 'manual_switch' => 0, 'channel' => $ch]];
                break;
            case 'aufnahme':
                $cmds[] = $v20('rec')
                    ? ['cmd' => 'SetRecV20', 'param' => ['Rec' => ['enable' => $an ? 1 : 0]]]
                    : ['cmd' => 'SetRec', 'param' => ['Rec' => ['schedule' => ['enable' => $an ? 1 : 0, 'channel' => $ch]]]];
                break;
            case 'push':
                $cmds[] = $v20('push')
                    ? ['cmd' => 'SetPushV20', 'param' => ['Push' => ['enable' => $an ? 1 : 0]]]
                    : ['cmd' => 'SetPush', 'param' => ['Push' => ['schedule' => ['enable' => $an ? 1 : 0, 'channel' => $ch]]]];
                break;
            case 'email':
                $cmds[] = $v20('email')
                    ? ['cmd' => 'SetEmailV20', 'param' => ['Email' => ['enable' => $an ? 1 : 0]]]
                    : ['cmd' => 'SetEmail', 'param' => ['Email' => ['schedule' => ['enable' => $an ? 1 : 0, 'channel' => $ch]]]];
                break;
            case 'ftp':
                $cmds[] = $v20('ftp')
                    ? ['cmd' => 'SetFtpV20', 'param' => ['Ftp' => ['enable' => $an ? 1 : 0]]]
                    : ['cmd' => 'SetFtp', 'param' => ['Ftp' => ['schedule' => ['enable' => $an ? 1 : 0, 'channel' => $ch]]]];
                break;
        }
        return ['cmds' => $cmds, 'folge' => $folge, 'lesen' => self::NACH[$befehl] ?? null, 'bewegt' => $bewegt];
    }

    /**
     * Antwort der Kamera auswerten.
     * @param array{ok:bool,rsp:int,detail:string} $a
     * @return string "ausgefuehrt" oder "abgelehnt: <Grund>"
     */
    public static function antwort(array $a): string
    {
        if (!empty($a['ok']) && (int)$a['rsp'] === 200) {
            return 'ausgefuehrt';
        }
        $rsp = (int)($a['rsp'] ?? 0);
        $d = strtolower((string)($a['detail'] ?? ''));
        if ($rsp === -26 || str_contains($d, 'ability')) {
            return 'abgelehnt: der Kamera-Benutzer hat dafür keine Berechtigung (Administrator nötig)';
        }
        if ($rsp === -9 || str_contains($d, 'not support')) {
            return 'abgelehnt: diese Kamera unterstützt das nicht';
        }
        if ($rsp === -3 || str_contains($d, 'param')) {
            return 'abgelehnt: die Kamera lehnt den Wert ab';
        }
        return 'abgelehnt: ' . (!empty($a['ok']) ? 'unerwartete Antwort (' . $rsp . ')' : ($d !== '' ? preg_replace('/[^\x20-\x7e]/', '', $d) : 'Kamera lehnt ab'));
    }
}
