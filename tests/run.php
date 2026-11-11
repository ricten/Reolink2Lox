<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php   (unter Windows ohne php.ini: php -d extension_dir=<php>/ext -d extension=openssl tests/run.php)
 * Teil 1: Unit-Tests (Konfiguration, Mapper gegen die anonymisierten Antworten einer RLC-823S1, Befehle)
 * Teil 2: Ende-zu-Ende: API-Schicht und der Dienst (reolinkd.php im Entwicklungsmodus) gegen Fake-Kameras (HTTPS und HTTP) und Fake-Broker.
 * Teil 3: Healthcheck, Weboberflaeche mit Attrappen, build.sh und ZIP.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, das echte Aussehen der Oberflaeche, andere Kameramodelle als die RLC-823S1
 * (die Fake-Kamera folgt deren Antworten), das echte Zertifikat der Kameras und der Dienst auf dem LoxBerry.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
// Gemeinsamer Code direkt aus ../common (im ZIP liegt er in bin/lib)
foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['ReolinkConfig', 'ReolinkHttp', 'ReolinkApi', 'ReolinkMapper', 'ReolinkCommands', 'ReolinkCamera', 'AppVersion'] as $c) {
    require_once $root . '/reolink2lox/bin/lib/' . $c . '.php';
}

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rlk_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/rlk_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    if (!is_resource($pp[0])) {
        return;
    }
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}
/** Selbstsigniertes Testzertifikat (nur fuer die Fake-Kamera, wird bei jedem Lauf neu erzeugt). Unter Windows ohne openssl.cnf mit eigener Minimalkonfiguration. */
function makeCert(string $pem): bool
{
    if (!extension_loaded('openssl')) {
        return false;
    }
    $cnf = sys_get_temp_dir() . '/rlk_test_openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=localhost\n");
    $o = ['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $k = @openssl_pkey_new($o);
    if (!$k) {
        return false;
    }
    $csr = openssl_csr_new(['commonName' => 'localhost'], $k, $o);
    $crt = openssl_csr_sign($csr, null, $k, 2, $o);
    openssl_x509_export($crt, $c);
    openssl_pkey_export($k, $kk, null, $o);
    return file_put_contents($pem, $c . $kk) !== false;
}
function fixture(string $name): array
{
    return json_decode((string)file_get_contents(__DIR__ . '/fixtures/rlc823s1/' . $name . '.json'), true);
}

$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);
$dir = $tmp . '/t' . getmypid();
@mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Fixtures: anonymisiert?
$fixTxt = '';
foreach (glob(__DIR__ . '/fixtures/rlc823s1/*.json') as $f) {
    $fixTxt .= (string)file_get_contents($f);
}
check('Fixtures: keine private IP-Adresse', (bool)preg_match('/\b(192\.168|10\.\d+|172\.(1[6-9]|2\d|3[01]))\.\d+\.\d+\b/', $fixTxt), false);
check('Fixtures: Seriennummer anonymisiert', (bool)preg_match('/"serial": "(?!<anonym>)[^"]+"/', $fixTxt), false);
check('Fixtures: keine Namen, Adressen, Passwoerter, MAC', (bool)preg_match('/"(password|userName|mac|uid|server|addr[123]|nickName)": "(?!<anonym>|")[^"]+"/', $fixTxt), false);
check('Fixtures: Modell der Aufnahme', fixture('GetDevInfo')['value']['DevInfo']['model'], 'RLC-823S1');

// ---------------------------------------------------------------- Unit: Mapper
check('kiName bekannte Arten', [ReolinkMapper::kiName('people'), ReolinkMapper::kiName('vehicle'), ReolinkMapper::kiName('dog_cat'), ReolinkMapper::kiName('face'), ReolinkMapper::kiName('package')], ['person', 'fahrzeug', 'tier', 'gesicht', 'paket']);
check('kiName unbekannte Art wird bereinigt', [ReolinkMapper::kiName('Non-Motor Vehicle'), ReolinkMapper::kiName('###')], ['non_motor_vehicle', 'ki']);
$aiNeu = ReolinkMapper::aiZustand(fixture('GetAiState')['value']);
ksort($aiNeu);
check('aiZustand (neue Firmware): nur unterstuetzte Arten', $aiNeu, ['dog_cat' => 0, 'people' => 0, 'vehicle' => 0]);
check('aiZustand: Alarm', ReolinkMapper::aiZustand(['channel' => 0, 'people' => ['alarm_state' => 1, 'support' => 1], 'face' => ['alarm_state' => 1, 'support' => 0]]), ['people' => 1]);
check('aiZustand (alte Firmware): einfache Zahlen', ReolinkMapper::aiZustand(['channel' => 0, 'dog_cat' => 1, 'people' => 0]), ['dog_cat' => 1, 'people' => 0]);
check('aiZustand leer', ReolinkMapper::aiZustand([]), []);

$st = ['md' => 0, 'ai' => ['people' => 0, 'vehicle' => 1, 'dog_cat' => 0], 'alarm_ts' => 1700000000,
    'ir' => 'Auto', 'whiteled' => fixture('GetWhiteLed')['value']['WhiteLed'], 'powerled' => 'On',
    'zoom' => 0, 'fokus' => 310, 'autofokus_aus' => 0, 'rec' => 0, 'push' => 1, 'email' => 0, 'ftp' => 0,
    'perf' => fixture('GetPerformance')['value']['Performance'], 'hdd' => []];
$m = ReolinkMapper::map($st, ['modell' => 'RLC-823S1', 'firmware' => 'v3.1.0.4885', 'name' => 'Hof']);
check('Mapper: Bewegung und KI', [$m['bewegung'], $m['person'], $m['fahrzeug'], $m['tier'], $m['alarm'], $m['alarm_ts']], ['0', '0', '1', '0', '1', '1700000000']);
check('Mapper: keine nicht unterstuetzte KI-Art (gesicht)', isset($m['gesicht']), false);
check('Mapper: Scheinwerfer Zustand, Modus, Helligkeit', [$m['licht/scheinwerfer'], $m['licht/scheinwerfer_modus'], $m['licht/scheinwerfer_helligkeit']], ['0', '0', '100']);
check('Mapper: IR Auto = 1, Status-LED On = 1', [$m['licht/ir'], $m['licht/status_led']], ['1', '1']);
check('Mapper: Zoom, Fokus, Autofokus (disable 0 = an)', [$m['ptz/zoom'], $m['ptz/fokus'], $m['ptz/autofokus']], ['0', '310', '1']);
check('Mapper: Aufnahme und Meldungen', [$m['aufnahme'], $m['meldung/push'], $m['meldung/email'], $m['meldung/ftp']], ['0', '1', '0', '0']);
check('Mapper: Kamera-Last', [$m['system/cpu_prozent'], isset($m['system/netz_kbit']), isset($m['system/codec_kbit'])], [(string)$st['perf']['cpuUsed'], true, true]);
check('Mapper: ohne SD-Karte', [$m['sd/vorhanden'], isset($m['sd/frei_gb'])], ['0', false]);
check('Mapper: Info', [$m['info/modell'], $m['info/firmware'], $m['info/name']], ['RLC-823S1', 'v3.1.0.4885', 'Hof']);
$m2 = ReolinkMapper::map(['hdd' => [['capacity' => 122104, 'size' => 61052, 'mount' => 1, 'format' => 1]], 'ir' => 'Off', 'powerled' => 'Off', 'autofokus_aus' => 1], []);
check('Mapper: SD-Karte 119,2 GB, halb voll', [$m2['sd/vorhanden'], $m2['sd/kapazitaet_gb'], $m2['sd/frei_gb'], $m2['sd/belegt_prozent']], ['1', '119.2', '59.6', '50']);
check('Mapper: IR Off = 0, Status-LED Off = 0, Autofokus aus = 0', [$m2['licht/ir'], $m2['licht/status_led'], $m2['ptz/autofokus']], ['0', '0', '0']);
check('Mapper: leerer Zustand = nichts', ReolinkMapper::map([], []), []);
check('Mapper: nur Bewegung (einfache Kamera)', ReolinkMapper::map(['md' => 1], []), ['bewegung' => '1', 'alarm' => '1']);
check('Mapper: Werte sind Texte ohne Leerzeichen in Zahlen', count(array_filter(ReolinkMapper::map($st, []), fn($v) => preg_match('/^\d+(\.\d+)?$/', $v))) > 15, true);

// ---------------------------------------------------------------- Unit: Befehle
check('parseTopic', ReolinkCommands::parseTopic('reolink/befehl/hof/scheinwerfer'), ['hof', 'scheinwerfer']);
check('parseTopic Quittung = null', ReolinkCommands::parseTopic('reolink/befehl/hof/scheinwerfer/quittung'), null);
check('parseTopic fremdes Topic = null', ReolinkCommands::parseTopic('bambulab/befehl/hof/licht_kammer'), null);
$alle = ['licht' => true, 'ptz' => true, 'sirene' => true, 'einstellungen' => true];
$keine = ['licht' => false, 'ptz' => false, 'sirene' => false, 'einstellungen' => false];
check('pruefen: gesperrt', ReolinkCommands::pruefen($keine, 'scheinwerfer', '1'), 'Befehle sind gesperrt (Stufe „Licht (Scheinwerfer, Infrarot, Status-LED)“ freigeben)');
check('pruefen: unbekannter Befehl', ReolinkCommands::pruefen($alle, 'neustart', '1'), 'Befehl nicht erlaubt');
check('pruefen: Neustart/Zuruecksetzen/Formatieren nie vorgesehen', array_values(array_filter(['reboot', 'neustart', 'restart', 'reset', 'format', 'firmware', 'benutzer', 'passwort'], fn($b) => isset(ReolinkCommands::LISTE[$b]))), []);
foreach ([['scheinwerfer', '2'], ['scheinwerfer_modus', '4'], ['scheinwerfer_helligkeit', '101'], ['ir', 'x'], ['ptz', 'oben'], ['ptz_position', '64'], ['zoom', '-1'], ['sirene', '2'], ['push', '']] as [$b, $w]) {
    check("pruefen: ungueltiger Wert $b=$w", ReolinkCommands::pruefen($alle, $b, $w), 'ungültiger Wert');
}
foreach ([['scheinwerfer', '1'], ['scheinwerfer_modus', '3'], ['scheinwerfer_helligkeit', '0'], ['scheinwerfer_helligkeit', '100'], ['scheinwerfer_helligkeit', '55'], ['ptz', 'links_hoch'], ['ptz', 'stopp'],
    ['ptz_position', '0'], ['ptz_position', '63'], ['zoom', '20'], ['fokus', '250'], ['sirene', '1'], ['aufnahme', '0']] as [$b, $w]) {
    check("pruefen: gueltig $b=$w", ReolinkCommands::pruefen($alle, $b, $w), null);
}
check('pruefen: Kamera kann das nicht', ReolinkCommands::pruefen($alle, 'sirene', '1', ['ir', 'push']), 'diese Kamera unterstützt das nicht');
check('pruefen: Kamera kann es', ReolinkCommands::pruefen($alle, 'ir', '1', ['ir', 'push']), null);
check('pruefen: Stufen einzeln: ptz frei, licht gesperrt', [ReolinkCommands::pruefen(['ptz' => true] + $keine, 'ptz', 'links'), str_starts_with((string)ReolinkCommands::pruefen(['ptz' => true] + $keine, 'ir', '1'), 'Befehle sind gesperrt')], [null, true]);
$ctx = ['kanal' => 0, 'ptz_geschwindigkeit' => 20, 'v20' => ['rec' => true, 'push' => true, 'email' => true, 'ftp' => true]];
$b = fn(string $cmd, string $w, array $c = []) => ReolinkCommands::bauen($cmd, $w, $c + $ctx);
$ganz = ['StartHour' => 0, 'StartMin' => 0, 'EndHour' => 23, 'EndMin' => 59];
$aus = ['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => 0, 'state' => 0, 'mode' => 0]]];
check('bauen scheinwerfer an: Zeitplan-Betrieb mit ganztaegigem Zeitplan (sonst schaltet die Kamera ausserhalb des Zeitplans ab)', $b('scheinwerfer', '1')['cmds'],
    [['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => 0, 'state' => 1, 'mode' => 3, 'LightingSchedule' => $ganz]]]]);
check('bauen scheinwerfer aus (kein Zeitplan gemerkt): Betriebsart aus, ein Schritt', [$b('scheinwerfer', '0')['cmds'], $b('scheinwerfer', '0')['folge']], [[$aus], []]);
$alt = ['StartHour' => 18, 'StartMin' => 0, 'EndHour' => 6, 'EndMin' => 0];
check('bauen scheinwerfer aus (Zeitplan gemerkt): erst Zeitplan zurueck (Betriebsart 3), dann aus', [$b('scheinwerfer', '0', ['zeitplan_alt' => $alt])['cmds'], $b('scheinwerfer', '0', ['zeitplan_alt' => $alt])['folge']],
    [[['cmd' => 'SetWhiteLed', 'param' => ['WhiteLed' => ['channel' => 0, 'state' => 0, 'mode' => 3, 'LightingSchedule' => $alt]]]], [[$aus]]]);
check('istGanztags', [ReolinkCommands::istGanztags($ganz), ReolinkCommands::istGanztags($alt), ReolinkCommands::istGanztags([])], [true, false, false]);
check('bauen scheinwerfer: danach whiteled lesen', $b('scheinwerfer', '1')['lesen'], 'whiteled');
check('bauen helligkeit', $b('scheinwerfer_helligkeit', '55')['cmds'][0]['param'], ['WhiteLed' => ['channel' => 0, 'bright' => 55]]);
check('bauen IR aus / an', [$b('ir', '0')['cmds'][0]['param']['IrLights']['state'], $b('ir', '1')['cmds'][0]['param']['IrLights']['state']], ['Off', 'Auto']);
check('bauen Status-LED', [$b('status_led', '0')['cmds'][0]['param']['PowerLed']['state'], $b('status_led', '1')['cmds'][0]['param']['PowerLed']['state']], ['Off', 'On']);
$p = $b('ptz', 'links');
check('bauen ptz links: Left mit Geschwindigkeit, bewegt', [$p['cmds'][0]['param'], $p['bewegt']], [['channel' => 0, 'op' => 'Left', 'speed' => 20], true]);
$p = $b('ptz', 'stopp');
check('bauen ptz stopp: ohne Geschwindigkeit, nicht bewegt', [$p['cmds'][0]['param'], $p['bewegt']], [['channel' => 0, 'op' => 'Stop'], false]);
check('bauen ptz: alle Richtungen', array_map(fn($w) => $b('ptz', $w)['cmds'][0]['param']['op'], ['rechts', 'hoch', 'runter', 'links_hoch', 'links_runter', 'rechts_hoch', 'rechts_runter', 'zoom_plus', 'zoom_minus']),
    ['Right', 'Up', 'Down', 'LeftUp', 'LeftDown', 'RightUp', 'RightDown', 'ZoomInc', 'ZoomDec']);
check('bauen ptz_position', $b('ptz_position', '2')['cmds'][0]['param'], ['channel' => 0, 'op' => 'ToPos', 'id' => 2, 'speed' => 20]);
check('bauen zoom und fokus', [$b('zoom', '7')['cmds'][0]['param']['ZoomFocus'], $b('fokus', '150')['cmds'][0]['param']['ZoomFocus']], [['channel' => 0, 'op' => 'ZoomPos', 'pos' => 7], ['channel' => 0, 'op' => 'FocusPos', 'pos' => 150]]);
check('bauen autofokus (disable ist umgekehrt)', [$b('autofokus', '1')['cmds'][0]['param']['AutoFocus']['disable'], $b('autofokus', '0')['cmds'][0]['param']['AutoFocus']['disable']], [0, 1]);
check('bauen sirene an/aus', [$b('sirene', '1')['cmds'][0]['param'], $b('sirene', '0')['cmds'][0]['param']], [['alarm_mode' => 'times', 'times' => 1, 'channel' => 0], ['alarm_mode' => 'manul', 'manual_switch' => 0, 'channel' => 0]]);
check('bauen V20: Aufnahme, Push, E-Mail, FTP', array_map(fn($c) => $b($c, '1')['cmds'][0]['cmd'], ['aufnahme', 'push', 'email', 'ftp']), ['SetRecV20', 'SetPushV20', 'SetEmailV20', 'SetFtpV20']);
$alt = ['v20' => ['rec' => false, 'push' => false, 'email' => false, 'ftp' => false]];
check('bauen alte Befehle (ohne V20)', array_map(fn($c) => $b($c, '0', $alt)['cmds'][0]['cmd'], ['aufnahme', 'push', 'email', 'ftp']), ['SetRec', 'SetPush', 'SetEmail', 'SetFtp']);
check('bauen alter Befehl: schedule.enable', $b('push', '0', $alt)['cmds'][0]['param'], ['Push' => ['schedule' => ['enable' => 0, 'channel' => 0]]]);
check('bauen: jeder Befehl der Liste erzeugt Anfragen', array_values(array_filter(array_keys(ReolinkCommands::LISTE), fn($k) => !ReolinkCommands::bauen($k, in_array($k, ['ptz']) ? 'links' : '1', $ctx)['cmds'])), []);
check('antwort ok', ReolinkCommands::antwort(['ok' => true, 'rsp' => 200, 'detail' => '']), 'ausgefuehrt');
check('antwort Berechtigung', ReolinkCommands::antwort(['ok' => false, 'rsp' => -26, 'detail' => 'ability error']), 'abgelehnt: der Kamera-Benutzer hat dafür keine Berechtigung (Administrator nötig)');
check('antwort nicht unterstuetzt', ReolinkCommands::antwort(['ok' => false, 'rsp' => -9, 'detail' => 'not support']), 'abgelehnt: diese Kamera unterstützt das nicht');
check('antwort falscher Wert', ReolinkCommands::antwort(['ok' => false, 'rsp' => -3, 'detail' => 'param error']), 'abgelehnt: die Kamera lehnt den Wert ab');
check('antwort sonst', ReolinkCommands::antwort(['ok' => false, 'rsp' => -1, 'detail' => 'not exist']), 'abgelehnt: not exist');

// ---------------------------------------------------------------- Unit: Konfiguration
$def = ReolinkConfig::defaults();
check('Konfiguration: fuenf Kameras, erste vorbelegt, alle gesperrt', [count($def['kameras']), $def['kameras'][0]['name'], $def['kameras'][0]['aktiv'], array_sum(array_map('intval', $def['kameras'][0]['befehle']))], [5, 'kamera1', false, 0]);
check('Konfiguration: Standardwerte', [$def['abfrage_alarm'], $def['abfrage_status'], $def['ptz_dauer'], $def['ptz_geschwindigkeit']], [1, 30, 1, 32]);
check('cleanHost: Schema und Pfad entfernt', ReolinkConfig::cleanHost(' https://kamera.lan/x '), 'kamera.lan');
check('cleanHost: Sonderzeichen', ReolinkConfig::cleanHost('192.0.2.5;rm'), '192.0.2.5rm');
check('cleanPort: leer/ungueltig = 0 (Standard)', [ReolinkConfig::cleanPort(''), ReolinkConfig::cleanPort('abc'), ReolinkConfig::cleanPort(99999), ReolinkConfig::cleanPort(8080)], [0, 0, 65535, 8080]);
check('cleanSchema', [ReolinkConfig::cleanSchema('HTTPS'), ReolinkConfig::cleanSchema('ftp'), ReolinkConfig::cleanSchema('')], ['https', 'auto', 'auto']);
check('Passwort: Leerzeichen bleiben, Steuerzeichen weg', ReolinkConfig::cleanPasswort("a b\x07c"), 'a bc');
$post = ['abfrage_alarm' => '3', 'abfrage_status' => '5000', 'ptz_dauer' => '99', 'ptz_geschwindigkeit' => '0',
    'kameras' => [0 => ['aktiv' => '1', 'name' => 'Hof Kamera!', 'host' => 'http://192.0.2.10/', 'port' => '', 'schema' => 'https', 'benutzer' => ' admin ', 'passwort' => '', 'befehle' => ['licht' => '1', 'ptz' => '1']],
        1 => ['aktiv' => '1', 'name' => 'hof_kamera', 'host' => '192.0.2.11', 'benutzer' => 'u', 'passwort' => 'geheim12'], 2 => ['aktiv' => '1', 'name' => '', 'host' => '192.0.2.12', 'benutzer' => 'u'],
        3 => ['aktiv' => '1', 'name' => 'ohnehost', 'host' => '', 'benutzer' => 'u']]];
$old = ReolinkConfig::defaults();
$old['kameras'][0]['passwort'] = 'altespasswort';
$new = ReolinkConfig::fromPost($old, $post);
check('fromPost: Bereiche begrenzt (alarm 3, status 3600, ptz_dauer 10, Geschwindigkeit Standard)', [$new['abfrage_alarm'], $new['abfrage_status'], $new['ptz_dauer'], $new['ptz_geschwindigkeit']], [3, 3600, 10, 32]);
check('fromPost: leeres Passwortfeld behaelt das alte', $new['kameras'][0]['passwort'], 'altespasswort');
check('fromPost: Name bereinigt, Host bereinigt, Benutzer getrimmt, Schema, Port', [$new['kameras'][0]['name'], $new['kameras'][0]['host'], $new['kameras'][0]['benutzer'], $new['kameras'][0]['schema'], $new['kameras'][0]['port']], ['hof_kamera', '192.0.2.10', 'admin', 'https', 0]);
check('fromPost: Befehle nur die angehakten', $new['kameras'][0]['befehle'], ['licht' => true, 'ptz' => true, 'sirene' => false, 'einstellungen' => false]);
check('fromPost: kein Eintrag vergessen (fuenf Kameras)', count($new['kameras']), 5);
$k = ReolinkConfig::kameras($new);
check('kameras(): ohne Host oder Benutzer ausgelassen, doppelte Namen nummeriert, leerer Name = kamera<Nr>', array_column($k, 'name'), ['hof_kamera', 'hof_kamera_2', 'kamera3']);
check('kameras(): Zugang', [$k[1]['host'], $k[1]['passwort'], $k[1]['schema'], $k[1]['port']], ['192.0.2.11', 'geheim12', 'auto', 0]);
$new2 = ReolinkConfig::fromPost($new, ['mqtt' => ['eigener_broker' => '1', 'host' => 'broker.lan', 'port' => '1884', 'benutzer' => 'u', 'passwort' => '']]);
check('fromPost: MQTT-Abschnitt, Passwort bleibt, andere Abschnitte unveraendert', [ReolinkConfig::ownBroker($new2), $new2['kameras'][0]['name']], [['broker.lan', 1884, 'u', ''], 'hof_kamera']);
check('fromPost: ohne Abschnitt aendert nichts', ReolinkConfig::fromPost($new, []), $new);
check('secrets: nur Passwoerter ab 4 Zeichen', ReolinkConfig::secrets($new), ['altespasswort', 'geheim12']);
check('load: fehlende Datei = Standardwerte', ReolinkConfig::load($dir . '/gibtsnicht.json') == ReolinkConfig::defaults(), true);
Util::jsonSave($dir . '/c.json', ['kameras' => [['aktiv' => true, 'host' => 'x', 'benutzer' => 'u', 'befehle' => ['licht' => '1', 'unsinn' => '1']]], 'abfrage_alarm' => 99]);
$l = ReolinkConfig::load($dir . '/c.json');
check('load: auf fuenf Kameras aufgefuellt, Befehle bereinigt, Bereich begrenzt', [count($l['kameras']), $l['kameras'][0]['befehle'], $l['abfrage_alarm']], [5, ['licht' => true, 'ptz' => false, 'sirene' => false, 'einstellungen' => false], 10]);

// ---------------------------------------------------------------- Prozesse (Fake-Kameras, Fake-Broker)
$php = PHP_BINARY;
$phpOpt = [];
if (PHP_OS_FAMILY === 'Windows' && !php_ini_loaded_file()) {
    $phpOpt = ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=openssl'];       // ohne php.ini ist openssl nicht geladen
}
$tlsOk = extension_loaded('openssl') && makeCert($dir . '/cert.pem');
if (!$tlsOk) {
    $fails++;
    $count++;
    echo "FEHLT: PHP-Erweiterung openssl (unter Windows: php -d extension_dir=<php>/ext -d extension=openssl tests/run.php); Tests mit Kameras uebersprungen\n";
}
$mqPort = 18851;
$pA = 18861;       // Hof: RLC-823S1, HTTPS
$pB = 18862;       // Garage: einfache Kamera, nur HTTP
$pC = 18863;       // Falsch: Kamera, aber falsches Passwort in der Konfiguration (HTTP)
$pWeg = 18899;     // nichts dahinter
$ctl = fn(int $port, array $c) => file_put_contents("$dir/cam$port.ctl", json_encode($c));
$camlog = fn(int $port) => (string)@file_get_contents("$dir/cam$port.log");
$n = fn(string $haystack, string $needle) => substr_count($haystack, $needle);
$mlog = fn() => (string)@file_get_contents($dir . '/mqtt.log');
$has = fn(string $topic, string $val) => (bool)preg_match('/' . preg_quote($topic, '/') . "\t" . preg_quote($val, '/') . '(\r?\n|$)/', $mlog());
$inject = fn(string $zeilen) => file_put_contents($dir . '/inject.txt', $zeilen);
if ($tlsOk) {
    $mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $dir]);
    $prA = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_reolink.php', (string)$pA, $dir, $dir . '/cert.pem', 'rlc823s1']));
    $prB = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_reolink.php', (string)$pB, $dir, '-', 'einfach']));
    $prC = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_reolink.php', (string)$pC, $dir, '-', 'rlc823s1']));
    foreach ([$mqPort, $pA, $pB, $pC] as $pt) {
        waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pt", $e, $es, 1) !== false, 8);
    }

    // ---------------------------------------------------------------- API-Schicht gegen die Fake-Kameras
    $api = fn(int $port, string $schema = 'auto', string $pw = 'testpass') => new ReolinkApi('127.0.0.1', $port, $schema, 'testuser', $pw, 2.0, 5.0, 0.0);
    $a = $api($pA);
    check('API HTTPS: Anmeldung', [$a->login(), $a->lastError, $a->eingeloggt()], [true, '', true]);
    $r = $a->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]], ['cmd' => 'GetAiState', 'param' => ['channel' => 0]]]);
    check('API: zwei Befehle in einer Anfrage', [$r[0]['ok'], $r[0]['value']['state'], $r[1]['ok'], isset($r[1]['value']['people'])], [true, 0, true, true]);
    $r = $a->einzeln('GetNichtDa');
    check('API: unbekannter Befehl = ok false, -9 not support (kein Verbindungsfehler)', [$r['ok'], $r['rsp'], $r['detail']], [false, -9, 'not support']);
    $ctl($pA, ['token_weg' => true]);
    usleep(1500000);
    $r = $a->einzeln('GetMdState', ['channel' => 0]);
    check('API: Token verworfen = neu anmelden und wiederholen', [$r['ok'] ?? null, $n($camlog($pA), "LOGIN\tok")], [true, 2]);
    $a->logout();
    check('API: Abmeldung wird gesendet', str_contains($camlog($pA), "REQ\tLogout"), true);
    $s = $api($pA, 'https');
    check('API: Schema https erzwingen', $s->login(), true);
    $s = $api($pA, 'http');
    check('API: Schema http gegen HTTPS-Port = Fehler', [$s->login(), $s->lastArt], [false, ReolinkApi::ART_VERBINDUNG]);
    $b = $api($pB);
    check('API HTTP (Rueckfall von HTTPS auf HTTP bei auto)', [$b->login(), $b->lastError], [true, '']);
    check('API: die Kamera (nur HTTP) hat den TLS-Anfang mit 400 abgewiesen, danach HTTP', [str_contains($camlog($pB), "LOGIN\tok")], [true]);
    $f = $api($pC, 'auto', 'xyz-Passwort-77');
    check('API: falsches Passwort = anmeldung, ohne Passwort in der Meldung', [$f->login(), $f->lastArt, $f->lastError, str_contains($f->lastError, 'xyz-Passwort-77')], [false, ReolinkApi::ART_ANMELDUNG, 'Anmeldung abgelehnt: Benutzer oder Passwort falsch?', false]);
    check('API: falsches Passwort wird nur einmal probiert (kein zweites Ziel noetig)', $n($camlog($pC), "LOGIN\tfalsch"), 1);
    $ctl($pC, ['max_sitzungen' => true]);
    usleep(1300000);
    $x = $api($pC);
    check('API: Sitzungsgrenze der Kamera', [$x->login(), $x->lastArt], [false, ReolinkApi::ART_SITZUNGEN]);
    $ctl($pC, ['max_sitzungen' => false]);
    usleep(1300000);
    $w = $api($pWeg);
    check('API: nichts am Port = verbindung', [$w->login(), $w->lastArt], [false, ReolinkApi::ART_VERBINDUNG]);
    $c = $api($pC);
    $c->login();
    $ctl($pC, ['modus' => '502', 'anfragen' => 1]);
    usleep(1300000);
    $r = $c->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]]]);
    check('API: HTTP 502 der Kamera = dienst', [$r, $c->lastArt], [null, ReolinkApi::ART_DIENST]);
    $r = $c->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]]]);
    check('API: nach dem 502 wieder anmelden und weiter', $r[0]['ok'] ?? null, true);
    $ctl($pC, ['modus' => 'stumm', 'anfragen' => 1]);
    usleep(1300000);
    $r = $c->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]]]);
    check('API: Verbindung ohne Antwort geschlossen = verbindung', [$r, $c->lastArt], [null, ReolinkApi::ART_VERBINDUNG]);
    $ctl($pC, ['chunked' => true]);
    usleep(1300000);
    $c->login();
    $r = $c->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]]]);
    check('API: chunked-Antwort wird zusammengesetzt', $r[0]['value']['state'] ?? null, 0);
    $ctl($pC, ['chunked' => false]);
    usleep(1300000);
    $ctl($pC, ['offen' => true]);
    usleep(1300000);
    $c->login();      // diese Anfrage loest die Steuerung aus (sie wird erst beim naechsten Zugriff gelesen)
    usleep(3500000);
    $t0 = microtime(true);
    $r = $c->call([['cmd' => 'GetMdState', 'param' => ['channel' => 0]]]);
    check('API: Antwort mit Content-Length endet sofort, auch wenn die Kamera die Verbindung offen haelt', [$r[0]['ok'] ?? null, microtime(true) - $t0 < 2.0], [true, true]);
    $ctl($pC, ['offen' => false]);
    usleep(1300000);
    $c->login();
    usleep(3500000);
    $schnell = new ReolinkApi('127.0.0.1', $pC, 'auto', 'testuser', 'testpass', 2.0, 5.0, 0.3);
    $schnell->login();
    $t0 = microtime(true);
    $schnell->einzeln('GetMdState', ['channel' => 0]);
    $schnell->einzeln('GetMdState', ['channel' => 0]);
    check('API: Mindestabstand zwischen Anfragen wird eingehalten (3 Anfragen >= 0,6 s)', microtime(true) - $t0 >= 0.55, true);
    check('API: Zugangsdaten stehen nie in der Adresse (Kamera-Protokoll)', [str_contains($camlog($pA) . $camlog($pB) . $camlog($pC), 'testpass'), str_contains($camlog($pA) . $camlog($pB) . $camlog($pC), 'testuser')], [false, false]);

    // ---------------------------------------------------------------- Ende-zu-Ende: Dienst
    $e2e = $dir . '/e2e';
    @mkdir($e2e, 0777, true);
    $conf = ReolinkConfig::load($e2e . '/reolink.json');
    $conf['abfrage_status'] = 2;
    $conf['ptz_dauer'] = 1;
    $conf['kameras'][0] = ['aktiv' => true, 'name' => 'hof', 'host' => '127.0.0.1', 'port' => $pA, 'schema' => 'auto', 'benutzer' => 'testuser', 'passwort' => 'testpass',
        'befehle' => ['licht' => true, 'ptz' => true, 'sirene' => true, 'einstellungen' => true]];
    $conf['kameras'][1] = ['aktiv' => true, 'name' => 'garage', 'host' => '127.0.0.1', 'port' => $pB, 'schema' => 'auto', 'benutzer' => 'testuser', 'passwort' => 'testpass',
        'befehle' => ['licht' => true, 'ptz' => false, 'sirene' => false, 'einstellungen' => true]];
    $conf['kameras'][2] = ['aktiv' => true, 'name' => 'falsch', 'host' => '127.0.0.1', 'port' => $pC, 'schema' => 'auto', 'benutzer' => 'testuser', 'passwort' => 'falschespasswort',
        'befehle' => ['licht' => true, 'ptz' => true, 'sirene' => true, 'einstellungen' => true]];
    Util::jsonSave($e2e . '/reolink.json', $conf);
    @unlink($dir . '/mqtt.log');
    @unlink("$dir/cam$pA.log");
    @unlink("$dir/cam$pB.log");
    @unlink("$dir/cam$pC.log");
    $svc = startProc(array_merge([$php], $phpOpt, [dirname(__DIR__) . '/reolink2lox/bin/reolinkd.php']), ['RLK_DEV_DIR' => $e2e, 'RLK_DEV_MQTT' => "127.0.0.1:$mqPort", 'RLK_DEV_LOGLEVEL' => 'debug']);
    $bl = fn() => (string)@file_get_contents($e2e . '/reolink.log');
    $stat = fn() => Util::jsonLoad($e2e . '/run/status.json');

    check('Dienst: Letzter Wille fuer status/ok beim Broker angemeldet', waitFor(fn() => str_contains($mlog(), "CONNECT\treolink_reolink2lox\treolink/status/ok"), 12), true);
    check('Hof: verbunden 1', waitFor(fn() => $has('reolink/hof/verbunden', '1'), 15), true);
    check('Garage: verbunden 1', waitFor(fn() => $has('reolink/garage/verbunden', '1'), 15), true);
    check('Hof: Modell und Firmware', waitFor(fn() => $has('reolink/hof/info/modell', 'RLC-823S1') && str_contains($mlog(), "reolink/hof/info/firmware\tv3.1.0"), 10), true);
    check('Hof: Bewegung und KI-Arten (ohne Gesicht), alarm 0', waitFor(fn() => $has('reolink/hof/bewegung', '0') && $has('reolink/hof/person', '0') && $has('reolink/hof/fahrzeug', '0') && $has('reolink/hof/tier', '0') && $has('reolink/hof/alarm', '0')), true);
    check('Hof: kein Topic fuer nicht unterstuetzte KI (gesicht)', str_contains($mlog(), 'reolink/hof/gesicht'), false);
    $wl = fixture('GetWhiteLed')['value']['WhiteLed'];
    check('Hof: Scheinwerfer, Modus, Helligkeit', waitFor(fn() => $has('reolink/hof/licht/scheinwerfer', (string)$wl['state']) && $has('reolink/hof/licht/scheinwerfer_modus', (string)$wl['mode']) && $has('reolink/hof/licht/scheinwerfer_helligkeit', (string)$wl['bright']), 20), true);
    check('Hof: IR, Status-LED, Zoom, Fokus, Autofokus', waitFor(fn() => $has('reolink/hof/licht/ir', '1') && $has('reolink/hof/licht/status_led', '1') && $has('reolink/hof/ptz/zoom', '0') && $has('reolink/hof/ptz/fokus', (string)fixture('GetZoomFocus')['value']['ZoomFocus']['focus']['pos']) && $has('reolink/hof/ptz/autofokus', '1'), 20), true);
    check('Hof: Aufnahme und Meldungen (aus der Kamera gelesen)', waitFor(fn() => $has('reolink/hof/aufnahme', (string)fixture('GetRecV20')['value']['Rec']['enable']) && $has('reolink/hof/meldung/push', (string)fixture('GetPushV20')['value']['Push']['enable'])
        && $has('reolink/hof/meldung/email', (string)fixture('GetEmailV20')['value']['Email']['enable']) && $has('reolink/hof/meldung/ftp', (string)fixture('GetFtpV20')['value']['Ftp']['enable']), 20), true);
    check('Hof: Kamera-Last und SD-Karte', waitFor(fn() => str_contains($mlog(), 'reolink/hof/system/cpu_prozent') && $has('reolink/hof/sd/vorhanden', '0'), 20), true);
    check('Garage (einfach): Bewegung, keine KI, kein Licht, kein PTZ', [waitFor(fn() => $has('reolink/garage/bewegung', '0')), str_contains($mlog(), 'reolink/garage/person'), str_contains($mlog(), 'reolink/garage/licht/'), str_contains($mlog(), 'reolink/garage/ptz/')], [true, false, false, false]);
    check('Garage (alte Befehle): Aufnahme 0 und Push 1 ueber GetRec/GetPush', waitFor(fn() => $has('reolink/garage/aufnahme', '0') && $has('reolink/garage/meldung/push', '1'), 20), true);
    check('Garage: keine V20-Befehle gesendet', str_contains($camlog($pB), 'V20'), false);
    check('Falsch: verbunden 0, Anmeldung abgelehnt, status/ok 0', waitFor(fn() => $has('reolink/falsch/verbunden', '0') && $has('reolink/status/ok', '0') && str_contains((string)($stat()['kameras']['falsch']['error'] ?? ''), 'Anmeldung abgelehnt'), 15), true);
    sleep(3);
    check('Falsch: das falsche Passwort wird nicht dauernd probiert (hoechstens ein Versuch in der Testzeit)', $n($camlog($pC), "LOGIN\tfalsch"), 1);
    check('Falsch: Fehler steht im Log, ohne das Passwort', [str_contains($bl(), 'Anmeldung abgelehnt'), str_contains($bl(), 'falschespasswort')], [true, false]);
    check('Lebenszeichen status/zaehler und status/ts', waitFor(fn() => (bool)preg_match('/reolink\/status\/zaehler\t\d+/', $mlog()) && (bool)preg_match('/reolink\/status\/ts\t\d{10}/', $mlog())), true);
    check('status.json: Dienst laeuft, Hof ok mit Modell und Befehlen, Falsch nicht ok', [$stat()['service'] ?? '', $stat()['kameras']['hof']['ok'] ?? null, $stat()['kameras']['hof']['modell'] ?? null,
        in_array('scheinwerfer', $stat()['kameras']['hof']['befehle'] ?? [], true), $stat()['kameras']['falsch']['ok'] ?? null, $stat()['kameras']['falsch']['art'] ?? null], ['running', true, 'RLC-823S1', true, false, 'anmeldung']);
    check('Hof: gespeicherte Positionen (nur aktivierte) in status.json', array_column($stat()['kameras']['hof']['presets'] ?? [], 'id'), array_values(array_map(fn($p) => $p['id'], array_filter(fixture('GetPtzPreset')['value']['PtzPreset'], fn($p) => $p['enable'] == 1))));

    // nur Aenderungen
    @unlink($dir . '/mqtt.log');
    sleep(4);
    check('Nur Aenderungen: gleiche Werte werden nicht erneut gesendet', [str_contains($mlog(), "reolink/hof/bewegung\t"), str_contains($mlog(), "reolink/hof/licht/ir\t"), str_contains($mlog(), "reolink/hof/verbunden\t")], [false, false, false]);

    // ---------------------------------------------------------------- Bewegung und KI
    @unlink($dir . '/mqtt.log');
    $ctl($pA, ['md' => 1, 'ai' => ['people' => 1]]);
    check('Erkennung: Bewegung, Person und alarm = 1', waitFor(fn() => $has('reolink/hof/bewegung', '1') && $has('reolink/hof/person', '1') && $has('reolink/hof/alarm', '1'), 8), true);
    check('Erkennung: alarm_ts = Zeitpunkt des Alarms (steigende Flanke)', waitFor(fn() => (bool)preg_match('/reolink\/hof\/alarm_ts\t(\d{10})/', $mlog(), $mm) && abs((int)$mm[1] - time()) < 10, 5), true);
    check('Erkennung: Fahrzeug bleibt 0 (nicht erneut gesendet)', str_contains($mlog(), "reolink/hof/fahrzeug\t1"), false);
    @unlink($dir . '/mqtt.log');
    $ctl($pA, ['md' => 0, 'ai' => ['people' => 0]]);
    check('Erkennung beendet: alles 0', waitFor(fn() => $has('reolink/hof/bewegung', '0') && $has('reolink/hof/person', '0') && $has('reolink/hof/alarm', '0'), 8), true);
    check('Erkennung beendet: alarm_ts nicht erneut gesendet', str_contains($mlog(), 'reolink/hof/alarm_ts'), false);
    @unlink($dir . '/mqtt.log');
    $ctl($pB, ['md' => 1]);
    check('Garage: Bewegung = 1 (alarm 1)', waitFor(fn() => $has('reolink/garage/bewegung', '1') && $has('reolink/garage/alarm', '1'), 8), true);
    $ctl($pB, ['md' => 0]);

    // ---------------------------------------------------------------- Befehle
    $q = fn(string $cam, string $b, string $text) => str_contains($mlog(), "reolink/befehl/$cam/$b/quittung\t$text");
    $cmdTest = function (string $cam, string $b, string $w, string $erwartet, int $port) use ($inject, $q, $dir) {
        @unlink($dir . '/mqtt.log');
        $inject("reolink/befehl/$cam/$b\t$w\n");
        return waitFor(fn() => $q($cam, $b, $erwartet), 10);
    };
    check('Befehl gesperrt (Falsch ist nicht verbunden)', $cmdTest('falsch', 'ir', '0', 'abgelehnt: Kamera nicht verbunden', $pC), true);
    check('Befehl gesperrt (Garage: PTZ nicht freigegeben)', $cmdTest('garage', 'ptz', 'links', 'abgelehnt: Befehle sind gesperrt (Stufe „Schwenken, Neigen, Zoom, Fokus“ freigeben)', $pB), true);
    check('Befehl: unbekannte Kamera', $cmdTest('niemand', 'ir', '0', 'abgelehnt: unbekannte Kamera', $pA), true);
    check('Befehl: Garage kann kein Licht (einfache Kamera)', $cmdTest('garage', 'scheinwerfer', '1', 'abgelehnt: diese Kamera unterstützt das nicht', $pB), true);
    check('Befehl: ungueltiger Wert', $cmdTest('hof', 'ptz', 'oben', 'abgelehnt: ungültiger Wert', $pA), true);
    check('Befehle: nichts davon hat die Kamera erreicht', [str_contains($camlog($pA), 'PtzCtrl'), str_contains($camlog($pB), 'SetWhiteLed')], [false, false]);
    check('Hof Scheinwerfer an: angenommen, dann ausgefuehrt', $cmdTest('hof', 'scheinwerfer', '1', 'angenommen', $pA) && waitFor(fn() => $q('hof', 'scheinwerfer', 'ausgefuehrt'), 10), true);
    check('Hof Scheinwerfer an: SetWhiteLed mit state 1, mode 3 und ganztaegigem Zeitplan', (bool)preg_match('/REQ\tSetWhiteLed\t\{"WhiteLed":\{"channel":0,"state":1,"mode":3,"LightingSchedule":\{"StartHour":0,"StartMin":0,"EndHour":23,"EndMin":59\}\}\}/', $camlog($pA)), true);
    check('Hof Scheinwerfer an: neuer Zustand kommt sofort als Wert zurueck (Modus 3)', waitFor(fn() => $has('reolink/hof/licht/scheinwerfer', '1') && $has('reolink/hof/licht/scheinwerfer_modus', '3'), 6), true);
    $zp = fn() => Util::jsonLoad($e2e . '/data/zeitplan_hof.json')['zeitplan'] ?? null;
    $origPlan = fixture('GetWhiteLed')['value']['WhiteLed']['LightingSchedule'];
    check('Hof Scheinwerfer an: der bisherige Zeitplan der Kamera wurde gemerkt', $zp(), ['StartHour' => $origPlan['StartHour'], 'StartMin' => $origPlan['StartMin'], 'EndHour' => $origPlan['EndHour'], 'EndMin' => $origPlan['EndMin']]);
    sleep(1);
    check('Hof Scheinwerfer nochmal an: der gemerkte Zeitplan bleibt der urspruengliche (nicht der ganztaegige)', $cmdTest('hof', 'scheinwerfer', '1', 'ausgefuehrt', $pA) && $zp()['StartHour'] === $origPlan['StartHour'], true);
    sleep(1);
    sleep(1);
    check('Hof Helligkeit 40', $cmdTest('hof', 'scheinwerfer_helligkeit', '40', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/licht/scheinwerfer_helligkeit', '40'), 6), true);
    sleep(1);
    $wl0 = $n($camlog($pA), '"state":0,"mode":0');
    $ms3 = $n($camlog($pA), '"state":0,"mode":3');
    check('Hof Scheinwerfer aus: zwei Schritte (Zeitplan zurueck, dann aus), Quittung erst am Ende', $cmdTest('hof', 'scheinwerfer', '0', 'ausgefuehrt', $pA) && $n($camlog($pA), '"state":0,"mode":3') === $ms3 + 1 && $n($camlog($pA), '"state":0,"mode":0') === $wl0 + 1, true);
    check('Hof Scheinwerfer aus: erst der alte Zeitplan mit Betriebsart 3, danach Betriebsart 0', (bool)preg_match('/"state":0,"mode":3,"LightingSchedule":\{"StartHour":' . $origPlan['StartHour'] . '[^\n]*\n(?:[^\n]*\n)*?REQ\tSetWhiteLed\t\{"WhiteLed":\{"channel":0,"state":0,"mode":0\}\}/', $camlog($pA)), true);
    check('Hof Scheinwerfer aus: Wert 0, Zeitplan der Kamera wieder der urspruengliche, Merkdatei weg', waitFor(fn() => $has('reolink/hof/licht/scheinwerfer', '0') && !is_file($e2e . '/data/zeitplan_hof.json'), 8), true);
    sleep(1);
    check('Hof Scheinwerfer nochmal aus (nichts gemerkt): nur ein Schritt', $cmdTest('hof', 'scheinwerfer', '0', 'ausgefuehrt', $pA) && $n($camlog($pA), '"state":0,"mode":3') === $ms3 + 1, true);
    sleep(1);
    check('Hof IR aus', $cmdTest('hof', 'ir', '0', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/licht/ir', '0'), 6) && str_contains($camlog($pA), '"state":"Off"'), true);
    sleep(1);
    check('Hof Status-LED aus', $cmdTest('hof', 'status_led', '0', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/licht/status_led', '0'), 6), true);
    sleep(1);
    check('Hof Zoom 5', $cmdTest('hof', 'zoom', '5', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/ptz/zoom', '5'), 6), true);
    sleep(1);
    check('Hof Fokus 200', $cmdTest('hof', 'fokus', '200', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/ptz/fokus', '200'), 6), true);
    sleep(1);
    check('Hof Zoom ausserhalb: die Kamera lehnt ab, Zoom bleibt', $cmdTest('hof', 'zoom', '900', 'abgelehnt: die Kamera lehnt den Wert ab', $pA) && !$has('reolink/hof/ptz/zoom', '900'), true);
    sleep(1);
    check('Hof Autofokus aus', $cmdTest('hof', 'autofokus', '0', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/ptz/autofokus', '0'), 6), true);
    sleep(1);
    check('Hof Aufnahme an, E-Mail an, FTP an, Push aus (V20-Befehle)', $cmdTest('hof', 'aufnahme', '1', 'ausgefuehrt', $pA) && waitFor(fn() => $has('reolink/hof/aufnahme', '1'), 6)
        && $cmdTest('hof', 'email', '1', 'ausgefuehrt', $pA) && $cmdTest('hof', 'ftp', '1', 'ausgefuehrt', $pA) && $cmdTest('hof', 'push', '0', 'ausgefuehrt', $pA), true);
    check('Hof: Meldungen als Werte zurueck', waitFor(fn() => $has('reolink/hof/meldung/push', '0') && str_contains($camlog($pA), 'SetPushV20') && str_contains($camlog($pA), 'SetFtpV20'), 8), true);
    sleep(1);
    check('Hof Sirene an: AudioAlarmPlay times 1', $cmdTest('hof', 'sirene', '1', 'ausgefuehrt', $pA) && str_contains($camlog($pA), 'REQ' . "\t" . 'AudioAlarmPlay' . "\t" . '{"alarm_mode":"times","times":1,"channel":0}'), true);
    sleep(1);
    check('Garage Push aus (alter Befehl SetPush), Wert 0', $cmdTest('garage', 'push', '0', 'ausgefuehrt', $pB) && waitFor(fn() => $has('reolink/garage/meldung/push', '0'), 8) && str_contains($camlog($pB), 'REQ' . "\t" . 'SetPush' . "\t"), true);
    // PTZ: faehrt, stoppt automatisch
    $c0 = $n($camlog($pA), '"op":"Stop"');
    check('Hof PTZ links: angenommen, ausgefuehrt, Left mit Geschwindigkeit 32', $cmdTest('hof', 'ptz', 'links', 'ausgefuehrt', $pA) && str_contains($camlog($pA), '"op":"Left","speed":32'), true);
    check('Hof PTZ links: nach 1 s stoppt das Plugin automatisch', waitFor(fn() => $n($camlog($pA), '"op":"Stop"') === $c0 + 1, 6), true);
    sleep(2);
    check('Hof PTZ: nur ein automatischer Stopp', $n($camlog($pA), '"op":"Stop"'), $c0 + 1);
    $c1 = $n($camlog($pA), '"op":"Stop"');
    check('Hof PTZ rechts, sofort stopp: genau ein Stopp zusaetzlich, kein zweiter automatischer', $cmdTest('hof', 'ptz', 'rechts', 'ausgefuehrt', $pA) && $cmdTest('hof', 'ptz', 'stopp', 'ausgefuehrt', $pA), true);
    sleep(3);
    check('Hof PTZ stopp: Stopp-Befehle = einer vom Nutzer (kein zusaetzlicher automatischer)', $n($camlog($pA), '"op":"Stop"'), $c1 + 1);
    check('Hof PTZ-Position 2: ToPos id 2', $cmdTest('hof', 'ptz_position', '2', 'ausgefuehrt', $pA) && str_contains($camlog($pA), '"op":"ToPos","id":2'), true);
    // zwei Befehle kurz hintereinander: der zweite wird gebremst
    @unlink($dir . '/mqtt.log');
    $inject("reolink/befehl/hof/ir\t1\nreolink/befehl/hof/status_led\t1\n");
    check('Zwei Befehle sofort hintereinander: der zweite wird gebremst', waitFor(fn() => $q('hof', 'status_led', 'abgelehnt: zu schnell (höchstens ein Befehl alle 0,3 Sekunden)'), 8), true);
    sleep(2);
    // Token geht verloren: der Dienst meldet sich neu an
    $logins = $n($camlog($pA), "LOGIN\tok");
    $ctl($pA, ['token_weg' => true]);
    check('Token verloren: neue Anmeldung, Hof bleibt verbunden', waitFor(fn() => $n($camlog($pA), "LOGIN\tok") > $logins, 10) && ($stat()['kameras']['hof']['ok'] ?? false), true);
    // Zugangsdaten nie im Log, in MQTT oder im Status
    check('Passwort nirgends im Log, in MQTT, im Status oder in der Antwort der Kamera-Anfragen', [str_contains($bl(), 'testpass'), str_contains($bl(), 'falschespasswort'), str_contains($mlog(), 'testpass'),
        str_contains((string)@file_get_contents($e2e . '/run/status.json'), 'testpass')], [false, false, false, false]);
    check('Token nirgends im Log', (bool)preg_match('/token=[0-9a-f]{16}/', $bl()), false);

    // ---------------------------------------------------------------- HTTP 502: nach drei Fehlern getrennt, danach wieder verbunden
    @unlink($dir . '/mqtt.log');
    $ctl($pA, ['modus' => '502', 'anfragen' => 4]);
    check('Kamera antwortet 502: verbunden 0 und status/ok 0', waitFor(fn() => $has('reolink/hof/verbunden', '0') && $has('reolink/status/ok', '0'), 20), true);
    check('Kamera antwortet 502: Meldung mit HTTP-Code im Status', str_contains((string)($stat()['kameras']['hof']['error'] ?? ''), '502') || str_contains($bl(), '502'), true);
    check('Nach dem 502: Hof wieder verbunden', waitFor(fn() => $has('reolink/hof/verbunden', '1'), 40), true);
    check('Nach dem 502: die Kamera liefert wieder Werte (status.json); unveraenderte Werte werden nicht erneut gesendet', waitFor(fn() => ($stat()['kameras']['hof']['werte'] ?? 0) > 20, 15), true);

    // ---------------------------------------------------------------- Kamera faellt aus und kommt wieder
    @unlink($dir . '/mqtt.log');
    stopProc($prA);
    check('Hof nicht erreichbar: verbunden 0, status/ok 0, Fehler im Status', waitFor(fn() => $has('reolink/hof/verbunden', '0') && $has('reolink/status/ok', '0'), 30) && !($stat()['kameras']['hof']['ok'] ?? true), true);
    check('Hof nicht erreichbar: der Fehler nennt den Grund', ($stat()['kameras']['hof']['error'] ?? '') !== '' && ($stat()['kameras']['hof']['art'] ?? '') === 'verbindung', true);
    check('Hof nicht erreichbar: Lebenszeichen laeuft weiter', waitFor(fn() => (bool)preg_match('/reolink\/status\/zaehler\t\d+/', $mlog()), 15), true);
    check('Garage bleibt trotzdem verbunden', $has('reolink/garage/verbunden', '1') || !str_contains($mlog(), "reolink/garage/verbunden\t0"), true);
    @unlink($dir . '/mqtt.log');
    $prA = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_reolink.php', (string)$pA, $dir, $dir . '/cert.pem', 'rlc823s1']));
    check('Hof wieder erreichbar: verbunden 1, status/ok 1', waitFor(fn() => $has('reolink/hof/verbunden', '1') && $has('reolink/status/ok', '0') === false ? true : $has('reolink/hof/verbunden', '1'), 70), true);
    check('Hof wieder erreichbar: status/ok wieder 1 (Falsch bleibt der Fehler: 0)', waitFor(fn() => $stat()['kameras']['hof']['ok'] ?? false, 10), true);

    // ---------------------------------------------------------------- Konfiguration im Betrieb aendern: Kamera entfernen und Passwort korrigieren
    $conf['kameras'][2]['passwort'] = 'testpass';
    Util::jsonSave($e2e . '/reolink.json', $conf);
    check('Konfiguration im Betrieb: korrigiertes Passwort = Falsch verbindet (ohne Neustart)', waitFor(fn() => $has('reolink/falsch/verbunden', '1'), 25), true);
    check('Konfiguration im Betrieb: jetzt sind alle in Ordnung, status/ok 1', waitFor(fn() => ($stat()['ok'] ?? 0) === 1, 15), true);
    $conf['kameras'][1]['aktiv'] = false;
    Util::jsonSave($e2e . '/reolink.json', $conf);
    $beforeLogout = $n($camlog($pB), "REQ\tLogout");
    check('Konfiguration im Betrieb: abgeschaltete Kamera wird abgemeldet und nicht mehr abgefragt', waitFor(fn() => $n($camlog($pB), "REQ\tLogout") > $beforeLogout && !isset($stat()['kameras']['garage']), 10), true);

    // ---------------------------------------------------------------- Dienst beenden (unter Windows hart beendet: Abmeldung und status/ok 0 beim sauberen Beenden sind hier nicht pruefbar)
    stopProc($svc);
    stopProc($prA);
    stopProc($prB);
    stopProc($prC);
    stopProc($mqProc);
} else {
    echo "Hinweis: Tests mit Fake-Kameras uebersprungen\n";
}

// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
@mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/reolink2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['RLK_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT,
    'kameras' => ['hof' => ['ok' => true, 'error' => '', 'werte' => 30]]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Reolink2Lox: Dienst, MQTT-Broker und Kameras');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3', $j['status'], 3);
check('Healthcheck: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
$j = $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0];
check('Healthcheck: Broker weg = Warnung 4', $j['status'], 4);
check('Healthcheck: Broker-Grund im Text', str_contains($j['result'], 'Verbindung abgelehnt'), true);
check('Healthcheck: nichts eingerichtet = Info 6', $res(['kameras' => []] + $basis)[0]['status'], 6);
$j = $res(['kameras' => ['hof' => ['ok' => false, 'error' => 'Anmeldung abgelehnt: Benutzer oder Passwort falsch?']]] + $basis)[0];
check('Healthcheck: Kamera mit Problem = Warnung 4', $j['status'], 4);
check('Healthcheck: Kamera und Grund im Text', str_contains($j['result'], 'hof (Anmeldung abgelehnt: Benutzer oder Passwort falsch?)'), true);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5', $j['status'], 5);
check('Healthcheck: Text zaehlt Kameras und nennt Version', $j['result'], '1 Kamera(s) in Ordnung, Version ' . AppVersion::CURRENT);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/reolink.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/reolink.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
// Rendert jeden Reiter (ohne echtes LoxBerry): findet undefinierte Variablen, Syntax- und Laufzeitfehler und prueft Menueleiste,
// Gateway-Abo und UDP-Port. Nicht pruefbar: das echte Aussehen in LoxBerry.
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev'] as $sub) {
    @mkdir("$uiDir/$sub", 0777, true);
}
@mkdir("$uiDir/sess", 0777, true);
$uiPage = function (string $tab, array $env = []) use ($php, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/reolink2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/reolink2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'RLK_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path=' . $root . '/tests/stubs', '-d', 'session.save_path=' . "$uiDir/sess",
        $root . '/tests/ui_page.php', $tab];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
$uiConf = ReolinkConfig::defaults();
$uiConf['kameras'][0] = ['aktiv' => true, 'name' => 'hof', 'host' => '192.0.2.50', 'port' => 0, 'schema' => 'auto', 'benutzer' => 'plugin', 'passwort' => 'geheimpasswort99',
    'befehle' => ['licht' => true, 'ptz' => false, 'sirene' => false, 'einstellungen' => false]];
$uiConf['mqtt']['passwort'] = 'brokerpasswort77';
Util::jsonSave("$uiDir/dev/reolink.json", $uiConf);
foreach (['kameras', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 5 Eintraegen", count($nav), 5);
    check("UI $tab: genau ein aktiver Eintrag, und zwar dieser", array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null, '?tab=' . $tab);
    check("UI $tab: nur ein aktiver Eintrag", count(array_filter($nav, fn($n) => !empty($n['active']))), 1);
    check("UI $tab: Design System (nojqm gesetzt, lb-content, kein jQuery-Mobile-Rest)", [str_contains($o, '<!--NOJQM:1-->'), str_contains($o, 'class="lb-content'), str_contains($o, 'data-role='), str_contains($o, 'data-ajax=')], [true, true, false, false]);
    check("UI $tab: kein Zugangsdatum im HTML", [str_contains($o, 'geheimpasswort99'), str_contains($o, 'brokerpasswort77')], [false, false]);
}
[$o] = $uiPage('kameras');
check('UI Kameras: Schalter, Eingabefelder und Speichern-Knopf im Design System', [str_contains($o, 'class="lb-toggle"'), str_contains($o, 'class="lb-input'), str_contains($o, 'lb-btn lb-btn-primary'),
    str_contains($o, 'name="kameras[0][aktiv]"'), str_contains($o, 'name="kameras[0][passwort]"'), str_contains($o, 'name="kameras[0][benutzer]"'), str_contains($o, 'name="abfrage_alarm"'), str_contains($o, 'name="ptz_dauer"')], [true, true, true, true, true, true, true, true]);
check('UI Kameras: Stufen der Befehlsfreigabe je Kamera', [str_contains($o, 'name="kameras[0][befehle][licht]" value="1" checked'), str_contains($o, 'name="kameras[0][befehle][ptz]" value="1">'), str_contains($o, 'name="kameras[0][befehle][sirene]"'),
    str_contains($o, 'name="kameras[0][befehle][einstellungen]"')], [true, true, true, true]);
check('UI Kameras: Passwort als Passwortfeld ohne Wert, "(gesetzt)"', [str_contains($o, 'type="password" name="kameras[0][passwort]" value=""'), str_contains($o, 'placeholder="(gesetzt)"')], [true, true]);
check('UI Kameras: Hinweise zu Administrator, eigenem Benutzer, HTTPS/HTTP und Zertifikat', [str_contains($o, 'Administrator'), str_contains($o, 'eigenen Benutzer'), str_contains($o, 'HTTPS oder HTTP'), str_contains($o, 'selbst signiertes Zertifikat')], [true, true, true, true]);
check('UI Kameras: Auswahl des Zugriffs (automatisch, HTTPS, HTTP)', [str_contains($o, '<option value="auto" selected>'), str_contains($o, '<option value="https">'), str_contains($o, '<option value="http">')], [true, true, true]);
check('UI Kameras: fuenf Zeilen', substr_count($o, '][aktiv]"'), 5);
[$o] = $uiPage('loxone', ['STUB_UDP' => '12345']);
check('UI Loxone: Befehlstabelle mit UDP-Port, Topics und Quittung', [str_contains($o, '/dev/udp/&lt;LoxBerry-IP&gt;/12345'), str_contains($o, 'reolink/befehl/hof/scheinwerfer'), str_contains($o, 'reolink/befehl/hof/ptz_position'),
    str_contains($o, 'quittung'), str_contains($o, 'reolink_hof_bewegung'), str_contains($o, 'reolink_hof_licht_ir')], [true, true, true, true, true, true]);
check('UI Loxone: Topic-Tabelle nennt Erkennung, Licht, Objektiv, Speicher', [str_contains($o, 'reolink/hof/alarm_ts'), str_contains($o, 'reolink/hof/licht/scheinwerfer_helligkeit'), str_contains($o, 'reolink/hof/ptz/autofokus'), str_contains($o, 'sd/kapazitaet_gb')], [true, true, true, true]);
check('UI Loxone: kein Neustart/Zuruecksetzen als Befehl', [str_contains($o, 'befehl/hof/neustart'), str_contains($o, 'befehl/hof/reboot')], [false, false]);
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „reolink/#“ fehlt'), true);
check('UI mqtt: nichts abonniert = rotes Feld', str_contains($o, 'gw-abo-fehler') && !str_contains($o, 'gw-abo-ok'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'reolink/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo eingetragen = gruenes Feld mit Topic-Liste', str_contains($o, 'Gateway V2: eingetragen (reolink/#)') && str_contains($o, 'gw-abo-ok') && str_contains($o, '<li><code>reolink/#</code></li>') && !str_contains($o, 'gw-abo-fehler'), true);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 1]]));
file_put_contents("$uiDir/config/mqtt_subscriptions.cfg", "reolink/#\n");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo in der Datei = gruen', str_contains($o, 'Gateway V1: eingetragen (reolink/#)') && str_contains($o, 'gw-abo-ok'), true);
@unlink("$uiDir/config/mqtt_subscriptions.cfg");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo-Datei fehlt', str_contains($o, 'Gateway V1: Das Abo'), true);
[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);

// Reiter Test gegen Fake-Kameras (Sofort abrufen, Selbstpruefung)
if ($tlsOk) {
    $prA = startProc(array_merge([$php], $phpOpt, [__DIR__ . '/fake_reolink.php', (string)$pA, $dir, $dir . '/cert.pem', 'rlc823s1']));
    waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pA", $e, $es, 1) !== false, 8);
    $uiConf['kameras'][0] = ['aktiv' => true, 'name' => 'hof', 'host' => '127.0.0.1', 'port' => $pA, 'schema' => 'auto', 'benutzer' => 'testuser', 'passwort' => 'testpass',
        'befehle' => ['licht' => true, 'ptz' => false, 'sirene' => false, 'einstellungen' => false]];
    Util::jsonSave("$uiDir/dev/reolink.json", $uiConf);
    $uiPost = function (string $aktion) use ($php, $phpOpt, $root, $uiDir): array {
        $e = array_merge(getenv(), ['STUB_INDEX' => $root . '/reolink2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/reolink2lox/bin', 'STUB_CONFIG' => "$uiDir/config", 'STUB_LOG' => "$uiDir/log",
            'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'RLK_DEV_DIR' => "$uiDir/dev", 'STUB_POST_AKTION' => $aktion]);
        $p = proc_open(array_merge([$php], $phpOpt, ['-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path=' . $root . '/tests/stubs', '-d', 'session.save_path=' . "$uiDir/sess", $root . '/tests/ui_page.php', 'test']),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return [$out, $err];
    };
    [$o, $e] = $uiPost('abruf');
    check('UI Test: Sofort abrufen zeigt die Topics der Kamera, ohne Warnungen und ohne Passwort', [$bad($o, $e), str_contains($o, 'reolink/hof/info/modell'), str_contains($o, 'RLC-823S1'), str_contains($o, 'reolink/hof/licht/ir'), str_contains($o, 'testpass')], [false, true, true, true, false]);
    check('UI Test: Befehle der Kamera werden genannt', str_contains($o, 'Befehle, die diese Kamera kennt:') && str_contains($o, 'scheinwerfer'), true);
    [$o, $e] = $uiPost('selbst');
    check('UI Test: Selbstpruefung: Kamera in Ordnung mit Modell, Broker-Pruefung vorhanden', [$bad($o, $e), str_contains($o, 'Kamera hof'), str_contains($o, 'Anmeldung in Ordnung, RLC-823S1'), str_contains($o, '<td>Broker</td>'), str_contains($o, 'Gateway-Abo')], [false, true, true, true, true]);
    stopProc($prA);
}

// ---------------------------------------------------------------- plugin.cfg, Installationsskripte, Bauen, ZIP
$pcfg = (string)file_get_contents($root . '/reolink2lox/plugin.cfg');
check('plugin.cfg: Identitaet', [str_contains($pcfg, "NAME=Rico Tengler\nEMAIL=rico@cod-lan.de\n"), str_contains($pcfg, "NAME=reolink2lox\nFOLDER=reolink2lox\nTITLE=Reolink2Lox\n"), str_contains($pcfg, 'VERSION=' . AppVersion::CURRENT . "\n")], [true, true, true]);
check('plugin.cfg: Autoupdate an, RELEASECFG auf raw.githubusercontent.com (main), PRERELEASECFG leer, WEBSITE',
    [str_contains($pcfg, "\nAUTOMATIC_UPDATES=true\n"), str_contains($pcfg, "\nRELEASECFG=https://raw.githubusercontent.com/ricten/Reolink2Lox/main/release.cfg\n"), str_contains($pcfg, "\nPRERELEASECFG=\n"), str_contains($pcfg, "\nWEBSITE=https://github.com/ricten/Reolink2Lox\n")], [true, true, true, true]);
check('plugin.cfg: Loglevel-Auswahl der Plugin-Verwaltung frei', str_contains($pcfg, "\nCUSTOM_LOGLEVELS=true\n"), true);
check('Plugin-Icons als PNG in allen vier Groessen, kein icon.svg, keine release.cfg im Plugin', [is_file($root . '/reolink2lox/icons/icon_64.png') && is_file($root . '/reolink2lox/icons/icon_128.png') && is_file($root . '/reolink2lox/icons/icon_256.png') && is_file($root . '/reolink2lox/icons/icon_512.png'),
    is_file($root . '/reolink2lox/icons/icon.svg'), is_file($root . '/reolink2lox/release.cfg')], [true, false, false]);
check('Abo-Datei: ein Topic je Zeile', file_get_contents($root . '/reolink2lox/config/mqtt_subscriptions.cfg'), "reolink/#\n");
$hart = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/reolink2lox', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with($f->getFilename(), '.png')) {
        continue;           // Bilddateien sind Binaerdaten (enthalten zufaellig CRLF-Bytes)
    }
    $t = (string)file_get_contents($f->getPathname());
    if (str_contains($t, '/opt/loxberry')) {
        $hart[] = $f->getFilename();
    }
    if (str_contains($t, "\r\n")) {
        $hart[] = 'CRLF:' . $f->getFilename();
    }
}
check('Keine festen /opt/loxberry-Pfade, nur LF-Zeilenenden im Plugin', $hart, []);
$alles = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (str_contains($p, DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR) || str_contains($p, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) || preg_match('/\.(png|zip)$/', $p)) {
        continue;
    }
    $alles .= (string)file_get_contents($p);
}
check('Repo: keine private IP-Adresse (nur 192.0.2.x und 127.0.0.1)', (bool)preg_match('/\b(192\.168|10\.\d+|172\.(1[6-9]|2\d|3[01]))\.\d+\.\d+\b/', $alles), false);

$bashOk = false;
$pr = @proc_open(['bash', '-c', 'command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if (is_resource($pr)) {
    stream_get_contents($pp[1]);
    stream_get_contents($pp[2]);
    $bashOk = proc_close($pr) === 0;
}
if ($bashOk) {
    // build.sh in einer Kopie des Repos (sonst entstuende eine echte release.cfg bzw. ein ZIP im Repo)
    $rc = $dir . '/rc';
    $copy = function (string $from, string $to) use (&$copy): void {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    };
    @mkdir("$rc/reolink", 0777, true);
    copy($root . '/build.sh', "$rc/reolink/build.sh");
    copy($root . '/CHANGELOG.md', "$rc/reolink/CHANGELOG.md");
    $copy($root . '/reolink2lox', "$rc/reolink/reolink2lox");
    $copy(dirname($root) . '/common', "$rc/common");
    $ver = AppVersion::CURRENT;
    $bs = function (string $arg = '') use ($rc): string {
        $cmd = $arg !== '' ? ['bash', 'build.sh', $arg] : ['bash', 'build.sh'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$rc/reolink");
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $o;
    };
    $bs();
    check('build.sh: baut beide ZIPs (mit und ohne Version), ohne release.cfg zu schreiben', [file_exists("$rc/reolink/reolink2lox-$ver.zip"), file_exists("$rc/reolink/reolink2lox.zip"), file_exists("$rc/reolink/release.cfg")], [true, true, false]);
    check('build.sh: die beiden ZIPs sind gleich', md5_file("$rc/reolink/reolink2lox-$ver.zip"), md5_file("$rc/reolink/reolink2lox.zip"));
    $bs('release-cfg');
    $cfgtxt = (string)@file_get_contents("$rc/reolink/release.cfg");
    check('build.sh release-cfg: Version, ZIP-Link des Release-Anhangs, Info-Link, LF', [str_contains($cfgtxt, "[AUTOUPDATE]\n"), str_contains($cfgtxt, "\nVERSION=$ver\n"),
        str_contains($cfgtxt, "\nARCHIVEURL=https://github.com/ricten/Reolink2Lox/releases/download/v$ver/reolink2lox-$ver.zip\n"), str_contains($cfgtxt, "\nINFOURL=https://github.com/ricten/Reolink2Lox/releases/tag/v$ver\n"), str_contains($cfgtxt, "\r")], [true, true, true, true, false]);
    // ZIP-Inhalt mit Python lesen (die PHP-Erweiterung zip ist nicht ueberall da); alle Textdateien nach Zugangsdaten durchsuchen
    $zp = proc_open(['bash', '-c', 'P=$(command -v python3 || command -v python); "$P" -c "import sys,zipfile;z=zipfile.ZipFile(sys.argv[1]);print(chr(10).join(z.namelist()));print(chr(0));print(sum(1 for n in z.namelist() if b\'testpass\' in z.read(n) or b\'geheimpasswort\' in z.read(n)))" "$1"', 'x', "$rc/reolink/reolink2lox-$ver.zip"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $zpp);
    $zout = str_replace("\r\n", "\n", stream_get_contents($zpp[1]));
    stream_get_contents($zpp[2]);
    proc_close($zp);
    [$zl, $zs] = array_pad(explode("\n\0\n", $zout, 2), 2, '');
    $namen = explode("\n", $zl);
    check('ZIP: plugin.cfg zuerst, Dienst, Healthcheck, gemeinsame Klassen, Weboberflaeche', [$namen[0] ?? '', in_array('bin/reolinkd.php', $namen, true), in_array('bin/healthcheck', $namen, true), in_array('bin/lib/MqttClient.php', $namen, true),
        in_array('bin/lib/GatewayInfo.php', $namen, true), in_array('webfrontend/htmlauth/index.php', $namen, true), in_array('uninstall/uninstall', $namen, true)], ['plugin.cfg', true, true, true, true, true, true]);
    check('ZIP: keine release.cfg, vier PNG-Icons und kein icon.svg, keine Tests, keine Fixtures', [in_array('release.cfg', $namen, true), count(array_filter($namen, fn($n) => preg_match('#^icons/icon_(64|128|256|512)\.png$#', $n))), in_array('icons/icon.svg', $namen, true),
        count(array_filter($namen, fn($n) => str_contains($n, 'tests/') || str_contains($n, 'fixtures')))], [false, 4, false, 0]);
    check('ZIP: keine Zugangsdaten in den Dateien', trim($zs), '0');
    file_put_contents("$rc/reolink/release.cfg", str_replace("VERSION=$ver", 'VERSION=0.0.1', $cfgtxt));
    check('build.sh: abweichende release.cfg nur als Hinweis (kein Fehler)', str_contains($bs(), 'Hinweis: release.cfg zeigt auf 0.0.1'), true);
} else {
    echo "Hinweis: bash/python fehlen, build.sh-Tests uebersprungen\n";
}

echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
