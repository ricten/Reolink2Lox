<?php
// Weboberflaeche Reolink2Lox: Kameras · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $rlk.

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['rlk_token'])) {
    $_SESSION['rlk_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['rlk_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Schalter im Stil des LoxBerry Design Systems (Checkbox mit lb-toggle); $text darf feste HTML-Auszeichnung enthalten. */
function schalter(string $name, bool $an, string $text = ''): string
{
    return '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label>'
        . ($text !== '' ? ' <span class="rlk-schalter-text">' . $text . '</span>' : '');
}

$paths = Paths::detect();
$rlk = ReolinkConfig::load($paths->config);
$tab = $_GET['tab'] ?? 'kameras';
if (!in_array($tab, ['kameras', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = 'kameras';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = ReolinkConfig::fromPost($rlk, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $rlk = $neu;
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    }
}

$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$basis = '?tab=';

$tabs = ['kameras' => 'Kameras', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());

// 4. Parameter true: LoxBerry Design System (lb-*-Klassen) statt jQuery Mobile
LBWeb::lbheader('Reolink2Lox ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 .rlk pre { background:var(--lb-gray-100,#f4f4f4); color:var(--lb-text,#222); padding:8px; overflow:auto; max-height:420px; border-radius:var(--lb-radius-sm,4px); }
 .rlk .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .rlk .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .rlk .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .rlk .lb-card { margin:12px 0; padding:4px 16px 12px 16px; }
 .rlk .rlk-opt { margin:6px 0; display:flex; align-items:flex-start; gap:8px; }
 .rlk .rlk-opt .lb-toggle { flex:none; }
 .rlk .lb-table td .lb-input, .rlk .lb-table td .lb-select { width:auto; max-width:100%; }
 .rlk .rlk-schmal { width:80px !important; } .rlk .rlk-mittel { width:150px !important; }
 .rlk .rlk-befehle label.lb-toggle { vertical-align:middle; }
 .rlk-led { display:inline-block; width:18px; height:18px; border-radius:3px; vertical-align:middle; border:1px solid rgba(0,0,0,.35); }
 .rlk-warn { color:var(--lb-warning,#b9770e); }
 .rlk-led-ok { background:var(--lb-success,#2ecc40); } .rlk-led-fehler { background:var(--lb-danger,#e74c3c); } .rlk-led-aus { background:var(--lb-gray-400,#bbb); }
</style>
<div class="lb-content rlk">
<?php foreach ($meldungen as $m) { echo '<div class="lb-callout' . (str_starts_with($m, 'FEHLER') ? ' lb-callout-warning' : '') . '"><span class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</span></div>'; } ?>

<?php if ($tab === 'kameras') { ?>
<form method="post" action="<?= $basis ?>kameras">
<input type="hidden" name="token" value="<?= h($token) ?>">

<div class="lb-card">
<h3>Allgemein</h3>
<div class="lb-form-row"><label class="lb-form-label" for="abfrage_alarm">Bewegung und KI-Erkennung abfragen alle … Sekunden (1–10)</label>
<div class="lb-form-field"><input class="lb-input rlk-schmal" type="number" id="abfrage_alarm" name="abfrage_alarm" min="1" max="10" value="<?= (int)$rlk['abfrage_alarm'] ?>"></div></div>
<div class="lb-form-row"><label class="lb-form-label" for="abfrage_status">Einstellungen und Werte (Licht, Zoom, Aufnahme …) abfragen alle … Sekunden (10–3600)</label>
<div class="lb-form-field"><input class="lb-input rlk-schmal" type="number" id="abfrage_status" name="abfrage_status" min="10" max="3600" value="<?= (int)$rlk['abfrage_status'] ?>"></div></div>
<div class="lb-form-row"><label class="lb-form-label" for="ptz_dauer">Richtungsbefehl: so viele Sekunden fährt die Kamera, dann stoppt das Plugin (1–10)</label>
<div class="lb-form-field"><input class="lb-input rlk-schmal" type="number" id="ptz_dauer" name="ptz_dauer" min="1" max="10" value="<?= (int)$rlk['ptz_dauer'] ?>"></div></div>
<div class="lb-form-row"><label class="lb-form-label" for="ptz_geschwindigkeit">Geschwindigkeit beim Schwenken, Neigen und Anfahren von Positionen (1–64)</label>
<div class="lb-form-field"><input class="lb-input rlk-schmal" type="number" id="ptz_geschwindigkeit" name="ptz_geschwindigkeit" min="1" max="64" value="<?= (int)$rlk['ptz_geschwindigkeit'] ?>"></div></div>
<p class="hinweis">Bewegung und KI-Erkennung kommen im Sekundentakt. Kürzere Ereignisse als der Abstand gehen verloren; die Kamera meldet sie ohnehin nur für einige Sekunden. Die Kamera ist empfindlich: das Plugin sendet höchstens vier Anfragen pro Sekunde und Kamera.</p>
</div>

<div class="lb-card">
<h3>Kameras</h3>
<div class="lb-callout">
<span class="lb-callout-title">Voraussetzungen an der Kamera</span>
<p>In der Kamera (Reolink-App oder Weboberfläche) muss der Zugriff über <b>HTTPS oder HTTP</b> aktiviert sein (Einstellungen → Netzwerk → Erweitert → Server-Einstellungen). Das Plugin probiert erst HTTPS, dann HTTP.
Legen Sie für das Plugin am besten einen <b>eigenen Benutzer</b> an: <b>Lesen</b> (Bewegung, KI, Werte) geht mit einem normalen Benutzer, <b>Schalten</b> (Licht, Zoom, Aufnahme, Sirene …) braucht einen Administrator.
Die Kamera erlaubt nur wenige gleichzeitige Anmeldungen; das Plugin meldet sich einmal an und nutzt die Sitzung weiter.</p>
</div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Status</th><th>Aktiv</th><th>Name im Topic</th><th>Host/IP</th><th>Port</th><th>Zugriff</th><th>Benutzer</th><th>Passwort</th><th>Befehle freigeben</th></tr>
<?php
$aktive = [];
foreach (ReolinkConfig::kameras($rlk) as $d) {
    $aktive[$d['host'] . ':' . $d['port'] . ':' . $d['benutzer']] = $d['name'];
}
foreach ($rlk['kameras'] as $i => $g) {
    $name = $aktive[trim((string)$g['host']) . ':' . ReolinkConfig::cleanPort($g['port'] ?? 0) . ':' . $g['benutzer']] ?? null;
    $r = ($name !== null && $laeuft) ? ($status['kameras'][$name] ?? null) : null;
    if (empty($g['aktiv']) || $g['host'] === '') {
        [$led, $tip] = ['aus', $g['host'] === '' ? 'nicht eingerichtet' : 'nicht aktiv'];
    } elseif ($r === null) {
        [$led, $tip] = ['aus', $laeuft ? 'noch keine Meldung des Dienstes' : 'der Dienst läuft nicht'];
    } elseif (empty($r['ok'])) {
        [$led, $tip] = ['fehler', (string)($r['error'] ?? 'Fehler')];
    } else {
        [$led, $tip] = ['ok', 'verbunden' . (!empty($r['modell']) ? ', ' . $r['modell'] : '')];
    }
    ?>
<tr>
 <td><span class="rlk-led rlk-led-<?= $led ?>" title="<?= h($tip) ?>"></span> <span class="hinweis"><?= h($tip) ?></span>
<?php if ($r !== null && !empty($r['presets'])) { ?><br><span class="hinweis">Gespeicherte Positionen: <?= h(implode(', ', array_map(fn($p) => $p['id'] . ($p['name'] !== '' ? ' (' . $p['name'] . ')' : ''), $r['presets']))) ?></span><?php } ?></td>
 <td><?= schalter('kameras[' . $i . '][aktiv]', !empty($g['aktiv'])) ?></td>
 <td><input class="lb-input rlk-mittel" type="text" name="kameras[<?= $i ?>][name]" value="<?= h($g['name']) ?>" placeholder="kamera<?= $i + 1 ?>"></td>
 <td><input class="lb-input rlk-mittel" type="text" name="kameras[<?= $i ?>][host]" value="<?= h($g['host']) ?>"></td>
 <td><input class="lb-input rlk-schmal" type="number" name="kameras[<?= $i ?>][port]" value="<?= (int)ReolinkConfig::cleanPort($g['port'] ?? 0) ?: '' ?>" placeholder="Standard"></td>
 <td><select class="lb-select" name="kameras[<?= $i ?>][schema]">
<?php foreach (ReolinkConfig::SCHEMEN as $k => $t) { ?>
  <option value="<?= h($k) ?>"<?= ($g['schema'] ?? 'auto') === $k ? ' selected' : '' ?>><?= h($t) ?></option>
<?php } ?>
 </select></td>
 <td><input class="lb-input rlk-mittel" type="text" name="kameras[<?= $i ?>][benutzer]" value="<?= h($g['benutzer']) ?>" autocomplete="off"></td>
 <td><input class="lb-input rlk-mittel" type="password" name="kameras[<?= $i ?>][passwort]" value="" autocomplete="new-password" placeholder="<?= $g['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td>
 <td class="rlk-befehle">
<?php foreach (ReolinkConfig::STUFEN as $k => $t) { ?>
  <div class="rlk-opt"><?= schalter('kameras[' . $i . '][befehle][' . $k . ']', !empty($g['befehle'][$k]), h($t)) ?></div>
<?php } ?>
 </td>
</tr>
<?php } ?>
</table></div>
<p class="hinweis">Bis zu fünf Kameras. Der Name steckt im Topic (<code>reolink/&lt;name&gt;/…</code>; nur a–z, 0–9, _ und -), leer = kamera1, kamera2 …
Das Passwort wird nie angezeigt; ein leeres Feld behält das gespeicherte Passwort. Die Kamera verwendet ein selbst signiertes Zertifikat: das Plugin prüft es nicht (Gerät im Heimnetz).
Ist eine Kamera nicht erreichbar oder das Passwort falsch, meldet <code>reolink/status/ok</code> den Wert 0 und <code>reolink/&lt;name&gt;/verbunden</code> ist 0.
Bei falschem Passwort probiert das Plugin nur selten erneut (die Kamera kann den Zugang sonst sperren).</p>
<p class="hinweis"><b>Befehle:</b> ab Werk gesperrt. Stufe <i>Licht</i>: Scheinwerfer, Infrarot-Licht, Status-LED. Stufe <i>Schwenken, Neigen, Zoom, Fokus</i>: bewegt die Kamera. Stufe <i>Sirene</i>: spielt den Alarmton ab. Stufe <i>Aufnahme und Meldungen</i>: schaltet Aufnahme, Push, E-Mail und FTP der Kamera ein oder aus. Die Quittung steht unter <code>…/quittung</code>.</p>
</div>

<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = ReolinkConfig::ownBroker($rlk);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('reolink/#', GatewayInfo::general());
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'reolink/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table></div>
<?php foreach (($status['kameras'] ?? []) as $a => $r) {
    echo '<p>' . h($a) . ': ' . (!empty($r['ok']) ? '<span class="ok">verbunden</span> (' . (int)($r['werte'] ?? 0) . ' Werte' . (!empty($r['modell']) ? ', ' . h($r['modell']) : '') . ')' : '<span class="fehler">' . h($r['error'] ?? 'Fehler') . '</span>') . '</p>';
} ?>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $rlk['mqtt']; ?>
<div class="rlk-opt"><?= schalter('mqtt[eigener_broker]', !empty($mq['eigener_broker']), 'Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)') ?></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Host/IP</th><td><input class="lb-input" type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>"></td>
<th>Port</th><td><input class="lb-input rlk-schmal" type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>"></td></tr>
<tr><th>Benutzer</th><td><input class="lb-input rlk-mittel" type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></td>
<th>Passwort</th><td><input class="lb-input rlk-mittel" type="password" name="mqtt[passwort]" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table></div>
<p class="hinweis">Ohne Haken verwendet das Plugin den MQTT-Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort. Dieser Broker ist <i>nicht</i> die Kamera: die Verbindung zur Kamera richtet sich nach dem Reiter „Kameras“ (Reolink-Kameras haben keinen MQTT-Anschluss).</p>
<p><button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $namen = array_map(fn($g) => $g['name'], ReolinkConfig::kameras($rlk)) ?: ['kamera1'];
    // [Topic (relativ zu reolink/<name>/), Einheit, Bedeutung, Gruppe]
    $werte = [
        ['verbunden', '0/1', 'Verbindung zur Kamera steht und sie antwortet', 'Status'],
        ['bewegung', '0/1', 'Bewegungserkennung der Kamera ausgelöst', 'Erkennung'],
        ['person | fahrzeug | tier', '0/1', 'KI-Erkennung: Person, Fahrzeug, Tier (Hund/Katze). Nur Arten, die die Kamera kann (außerdem gesicht, paket, besucher bei Modellen, die sie melden)', 'Erkennung'],
        ['alarm', '0/1', '1, solange Bewegung oder eine KI-Erkennung ausgelöst ist', 'Erkennung'],
        ['alarm_ts', 's', 'Unix-Zeitstempel des letzten Beginns einer Erkennung', 'Erkennung'],
        ['licht/scheinwerfer', '0/1', 'Scheinwerfer leuchtet', 'Licht'],
        ['licht/scheinwerfer_modus', '0–3', 'Betriebsart des Scheinwerfers: 0 aus, 1 automatisch, 2 nachts, 3 Zeitplan (welche die Kamera kennt, hängt vom Modell ab)', 'Licht'],
        ['licht/scheinwerfer_helligkeit', '%', 'Helligkeit des Scheinwerfers', 'Licht'],
        ['licht/ir', '0/1', 'Infrarot-Licht: 1 automatisch (oder an), 0 aus', 'Licht'],
        ['licht/status_led', '0/1', 'Status-LED der Kamera an', 'Licht'],
        ['ptz/zoom | ptz/fokus', '-', 'Zoom- und Fokusposition (Zahl wie in der Kamera)', 'Objektiv'],
        ['ptz/autofokus', '0/1', 'Autofokus an', 'Objektiv'],
        ['aufnahme', '0/1', 'Aufnahme (Zeitplan der Kamera) eingeschaltet', 'Aufnahme und Meldungen'],
        ['meldung/push | email | ftp', '0/1', 'Push, E-Mail und FTP der Kamera bei Alarm eingeschaltet', 'Aufnahme und Meldungen'],
        ['sd/vorhanden', '0/1', 'Speicherkarte eingelegt', 'Speicher'],
        ['sd/kapazitaet_gb | frei_gb | belegt_prozent', 'GB, %', 'Speicherkarte: Größe, freier Platz, Belegung (nur mit Karte)', 'Speicher'],
        ['system/cpu_prozent', '%', 'Auslastung der Kamera', 'System'],
        ['system/netz_kbit | codec_kbit', 'kbit/s', 'Netzdurchsatz und Datenrate des Encoders laut Kamera', 'System'],
        ['info/modell | firmware | name', 'Text', 'Modell, Firmware und Gerätename der Kamera', 'Info'],
    ];
    ?>
<h3>Einbindung in Loxone</h3>
<p>Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo reolink/#) als virtuelle Eingänge, z. B. <code>reolink_hof_person</code>.
Werte, die die Kamera nicht liefert, werden nicht gesendet. Gesendet werden nur Änderungen und alle 5 Minuten alle Werte.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Gruppe</th><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php
$einzel = fn(string $t) => !preg_match('/[|<]/', $t);     // genau ein festes Topic?
foreach ($namen as $a) {
    foreach ($werte as $w) {
        $topic = 'reolink/' . $a . '/' . $w[0];
        $name = $einzel($w[0]) ? str_replace('/', '_', $topic) : '(Topic mit _ statt /)';
        echo '<tr><td>' . h($w[3]) . '</td><td>' . h($topic) . '</td><td>' . h($name) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
    }
}
foreach ([['reolink/status/ok', '0/1', 'Alle Kameras in Ordnung (eine nicht erreichbare Kamera oder ein falsches Passwort ergibt 0; No Cache)'], ['reolink/status/zaehler', '0–999', 'Lebenszeichen, zählt alle 10 s (No Cache)'],
    ['reolink/status/ts', 's', 'Unix-Zeitstempel des letzten Lebenszeichens']] as $w) {
    echo '<tr><td>Status</td><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
}
?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker reolink/status/ok per Letzter-Wille-Nachricht auf 0 (retained). Das Bild der Kamera (RTSP oder Standbild) bindet man direkt in Loxone ein; die Zugangsdaten der Kamera gehören dann in Loxone, nicht ins Plugin.</p>

<h3>Befehle aus Loxone</h3>
<p>Gesperrt, bis sie je Kamera im Reiter „Kameras“ freigegeben sind. Virtueller Ausgang: Adresse <code>/dev/udp/&lt;LoxBerry-IP&gt;/<?= (int)$udp ?></code>,
Befehl bei EIN <code>publish reolink/befehl/&lt;name&gt;/&lt;befehl&gt; &lt;wert&gt;</code> (ohne HTTP-Header/Body, Trennzeichen leer). Die Quittung steht unter <code>…/&lt;befehl&gt;/quittung</code>:
<code>angenommen</code>, dann <code>ausgefuehrt</code> oder <code>abgelehnt: &lt;grund&gt;</code> (nie retained). Höchstens ein Befehl alle 0,3 Sekunden und Kamera; nur bei bestehender Verbindung.
Richtungsbefehle fährt die Kamera nur die eingestellte Zeit und das Plugin stoppt sie dann selbst; <code>stopp</code> hält sofort an.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Wert</th><th>Stufe</th><th>Wirkung</th></tr>
<?php
foreach ($namen as $a) {
    foreach (ReolinkCommands::LISTE as $k => [$stufe, , $text]) {
        $w = match ($k) {
            'scheinwerfer_modus' => '0–3', 'scheinwerfer_helligkeit' => '0–100', 'ptz' => 'links, rechts, hoch, runter, …, stopp', 'ptz_position' => '0–63', 'zoom', 'fokus' => 'Zahl', default => '1 oder 0',
        };
        echo '<tr><td>reolink/befehl/' . h($a) . '/' . h($k) . '</td><td>' . h($w) . '</td><td>' . h(ReolinkConfig::STUFEN[$stufe]) . '</td><td>' . h($text) . '</td></tr>';
    }
}
?>
</table></div>
<p class="hinweis">Schalten braucht an der Kamera einen Administrator-Benutzer (Quittung sonst: „abgelehnt: der Kamera-Benutzer hat dafür keine Berechtigung“). Nie vorgesehen: Neustart, Zurücksetzen, Firmware, Benutzer, Netzwerk, Formatieren der Karte.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button class="lb-btn lb-btn-primary" type="submit" name="aktion" value="abruf">Sofort abrufen</button>
<button class="lb-btn" type="submit" name="aktion" value="selbst">Selbstprüfung</button>
<button class="lb-btn lb-btn-danger" type="submit" name="aktion" value="neustart">Dienst neu starten</button>
</form>
<?php
    $a = (string)($_POST['aktion'] ?? '');
    $kameras = ReolinkConfig::kameras($rlk);
    /** Kurz mit der Kamera verbinden und alle Werte lesen (wie der Dienst, aber mit eigener Sitzung). @return array{0:?array,1:array,2:string} [Topics, Befehle, Fehler] */
    $holen = function (array $dev, int $sekunden) use ($status, $laeuft): array {
        // Laeuft der Dienst, hat er die Werte schon: nicht ein zweites Mal an der Kamera anmelden (schnell, schont die Kamera und ihre Sitzungsgrenze)
        $r = $laeuft ? ($status['kameras'][$dev['name']] ?? null) : null;
        if (is_array($r) && isset($r['topics'])) {
            if (!empty($r['verbunden']) && $r['topics']) {
                return [$r['topics'], (array)($r['befehle'] ?? []), ''];
            }
            if (empty($r['ok'])) {
                return [null, [], (string)($r['error'] ?? '') !== '' ? (string)$r['error'] : 'Der Dienst ist nicht mit der Kamera verbunden'];
            }
        }
        @set_time_limit(0);
        $cam = new ReolinkCamera($dev, new Logger(null, 'Test', 'Reolink-Test', true), 1, 1, 1, 32);
        $ende = microtime(true) + $sekunden;
        while (microtime(true) < $ende) {
            $cam->tick(time());
            if ($cam->connected() && $cam->fertigGelesen()) {
                break;
            }
            usleep(50000);
        }
        $st = $cam->status(time());
        $werte = $cam->connected() ? $cam->werte() : null;
        $befehle = $cam->details()['befehle'];
        $cam->close();
        return [$werte, $befehle, $werte !== null ? '' : ($st['error'] !== '' ? $st['error'] : 'Keine Antwort der Kamera innerhalb von ' . $sekunden . ' s')];
    };
    if ($a === 'abruf') {
        if (!$kameras) {
            echo '<p class="fehler">Keine Kamera konfiguriert (aktiv, mit Host und Benutzer).</p>';
        }
        foreach ($kameras as $dev) {
            echo '<h4>' . h($dev['name']) . ' (' . h($dev['host'] . ($dev['port'] ? ':' . $dev['port'] : '')) . ')</h4>';
            [$w, $befehle, $err] = $holen($dev, 25);
            if ($w === null) {
                echo '<p class="fehler">' . h($err) . '</p>';
                continue;
            }
            echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Topic</th><th>Wert</th></tr>';
            foreach ($w as $k => $v) {
                echo '<tr><td>reolink/' . h($dev['name'] . '/' . $k) . '</td><td>' . h($v) . '</td></tr>';
            }
            echo '</table></div><p class="hinweis">Befehle, die diese Kamera kennt: ' . h(implode(', ', $befehle)) . '</p>';
        }
    } elseif ($a === 'selbst') {
        $zeile = function (string $name, bool $ok, string $text): void {
            echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
        };
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('Konfiguration', count($kameras) > 0, count($kameras) . ' Kamera(s) konfiguriert');
        foreach ($kameras as $dev) {
            [$w, , $err] = $holen($dev, 15);
            $zeile('Kamera ' . $dev['name'], $w !== null, $w !== null
                ? 'Anmeldung in Ordnung, ' . ($w['info/modell'] ?? 'Modell unbekannt') . ', Firmware ' . ($w['info/firmware'] ?? '?') . ', ' . count($w) . ' Werte'
                : $err);
        }
        $own = ReolinkConfig::ownBroker($rlk);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'reolink_test_' . $paths->folder, 'reolink/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('reolink/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        echo '</table></div>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        // Beim allerersten Start meldet die LoxBerry-Logbibliothek, dass die neue Logdatei noch nicht in ihrer Datenbank steht. Harmlos, nicht anzeigen.
        $zeilen = array_filter($zeilen, fn($z) => !str_contains($z, 'log_db_queryid: Could not find database entry'));
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
