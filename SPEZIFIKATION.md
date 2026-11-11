# Spezifikation Reolink2Lox

Stand: 0.1.3 (Beta), 2026-11-11. Ersetzt den ioBroker-Adapter `reolink`. Dieses Dokument hält fest, was das Plugin tut und was bei den Versuchen an der echten Kamera herausgekommen ist.

## 1. Ziel

Reolink-Kameras haben keinen MQTT-Anschluss und keine offizielle, öffentliche API-Dokumentation. Das Plugin fragt bis zu fünf Kameras über ihre lokale HTTP-API ab, veröffentlicht die Werte per MQTT am LoxBerry-Broker (→ Gateway → Miniserver) und nimmt Befehle aus Loxone an. Ein vorhandenes LoxBerry-Plugin („Reolink – Bewegungserkennung und Steuerbefehle“) liefert Bewegung nur per UDP und bietet kein MQTT; deshalb ein eigenes Plugin.

## 2. Quellen

- Bibliothek `reolink_aio` (Home Assistant): Befehle, Parameter, Antwortformate, Fähigkeiten
- Beschreibung des Perl-Moduls `IPCamera::Reolink` und des „Camera HTTP API User Guide“ (Auszüge)
- Versuche an einer **RLC-823S1** (Firmware v3.1.0.4885_25051904, PTZ, 8 MP, Scheinwerfer, KI für Person/Fahrzeug/Tier). Die Kamera war als RLC-831S1 angekündigt und meldet sich als `RLC-823S1`.

## 3. Protokoll

- POST `/cgi-bin/api.cgi` mit JSON-Liste (mehrere Befehle je Anfrage möglich), HTTPS mit selbst signiertem Zertifikat (Standard) oder HTTP.
- `Login` mit `{"User":{"userName":…,"password":…}}` liefert `value.Token.name` und `leaseTime` (3600 s). Das Token steht danach in der Adresse (`?token=…`). `Logout` beendet die Sitzung; die Kamera erlaubt nur wenige Sitzungen.
- Antworten: `code` 0 mit `value` (bei Set-Befehlen `{"rspCode":200}`), sonst `error.rspCode` und `error.detail`. Bekannte Fehler: -6 `please login first` (Token ungültig), -7 `login failed`, -5 `max session`, -9 `not support`, -26 `ability error` (fehlende Berechtigung), -3 `param error`.

## 4. Abfragen

| Wert | Befehl | Takt |
|---|---|---|
| Bewegung, KI-Arten | `GetMdState` + `GetAiState` in einer Anfrage | 1 s (einstellbar 1–10 s) |
| Infrarot, Scheinwerfer, Status-LED | `GetIrLights`, `GetWhiteLed`, `GetPowerLed` | 30 s (einstellbar), je eine Anfrage je Durchlauf |
| Zoom, Fokus, Autofokus | `GetZoomFocus`, `GetAutoFocus` | wie oben |
| Aufnahme, Push, E-Mail, FTP | `GetRecV20`, `GetPushV20`, `GetEmailV20`, `GetFtpV20` (alte Kameras: `GetRec` … ohne V20) | wie oben |
| Last, Speicherkarte | `GetPerformance`, `GetHddInfo` | wie oben |
| Gespeicherte Positionen | `GetPtzPreset` (nur `enable` = 1) | wie oben (nur für die Oberfläche) |
| Modell, Firmware, Fähigkeiten | `GetDevInfo`, `GetAbility` | beim Verbinden |

`GetAiState`: neue Firmware `{"people":{"alarm_state":0,"support":1},…}`, alte einfache Zahlen; Arten mit `support` 0 werden nicht veröffentlicht. Der Zeitstempel `alarm_ts` entsteht im Plugin bei der steigenden Flanke von Bewegung oder KI.

Die V20-Befehle werden nur benutzt, wenn die Fähigkeit (`push`, `email`, `ftp`, `recSchedule` mit Version ≥ 1) das anzeigt. Nicht unterstützte Befehle (`-9`) werden nicht erneut abgefragt.

## 5. Befehle

Vier Stufen je Kamera, ab Werk gesperrt: **Licht**, **Schwenken/Zoom/Fokus**, **Sirene**, **Aufnahme und Meldungen**. Jeder Befehl wird gegen Freigabe, Wert (Regex) und Fähigkeiten der Kamera geprüft und mit `angenommen` → `ausgefuehrt` | `abgelehnt: <Grund>` quittiert. Danach liest das Plugin den betroffenen Wert neu (Scheinwerfer nach 2,5 s, sonst nach 1 s).

| Befehl | Anfrage an die Kamera |
|---|---|
| `scheinwerfer 1` | `SetWhiteLed {state:1, mode:3, LightingSchedule:0:00–23:59}`; der bisherige Zeitplan wird in `data/zeitplan_<name>.json` gemerkt |
| `scheinwerfer 0` | mit gemerktem Zeitplan: `SetWhiteLed {state:0, mode:3, LightingSchedule:<alt>}`, nach 2 s `SetWhiteLed {state:0, mode:0}`; ohne gemerkten Zeitplan nur der zweite Schritt |
| `scheinwerfer_modus n` | `SetWhiteLed {mode:n}` |
| `scheinwerfer_helligkeit n` | `SetWhiteLed {bright:n}` |
| `ir 1/0` | `SetIrLights {state:Auto/Off}` |
| `status_led 1/0` | `SetPowerLed {state:On/Off}` |
| `ptz <richtung>` | `PtzCtrl {op:Left/Right/Up/Down/LeftUp/…/ZoomInc/ZoomDec, speed}`, nach der eingestellten Zeit (1–10 s) automatisch `PtzCtrl {op:Stop}`; `stopp` sofort |
| `ptz_position n` | `PtzCtrl {op:ToPos, id:n, speed}` |
| `zoom n`, `fokus n` | `StartZoomFocus {op:ZoomPos/FocusPos, pos:n}` |
| `autofokus 1/0` | `SetAutoFocus {disable:0/1}` |
| `sirene 1/0` | `AudioAlarmPlay {alarm_mode:times, times:1}` bzw. `{alarm_mode:manul, manual_switch:0}` (am RLC-823S1 geprüft) |
| `aufnahme`, `push`, `email`, `ftp` | `SetRecV20 {Rec:{enable}}`, `SetPushV20 {Push:{enable}}`, `SetEmailV20 {Email:{enable}}`, `SetFtpV20 {Ftp:{enable}}` (alte Kameras: `SetRec` … mit `schedule.enable`) |

Nie vorgesehen: Neustart, Zurücksetzen, Firmware, Benutzer, Netzwerk, Formatieren.

## 6. Befunde an der RLC-823S1

- **Abstürze durch schnelle Anfragen:** Eine Folge von rund 40 Abfragen ohne Pause, darunter der alte Befehl `GetPush` (die Kamera kennt `GetPushV20`), brachte den Webdienst der Kamera zum Absturz (HTTP 502 von nginx, dann Verbindung verweigert; nach etwa einer Minute wieder erreichbar). Einzeln und mit Abstand beantwortet die Kamera alles. Deshalb: Mindestabstand 0,25 s, höchstens eine Anfrage je Durchlauf, neuere Befehle nur nach den Fähigkeiten.
- Abfrage im Sekundentakt (`GetMdState` + `GetAiState`) ist stabil, Antwortzeit etwa 0,16 s.
- `GetPtzCurPos`, `GetStateLight`, `GetAudioAlarm`, `GetRec`, `GetManualRec`: `not support`. Die Position der Kamera lässt sich nicht lesen.
- **Scheinwerfer:** `state` allein lässt sich im Zeitplan-Betrieb (Betriebsart 3) nicht ausschalten. Ein Zeitplan wird nur zusammen mit Betriebsart 3 übernommen; die Anfrage mit Betriebsart 0 ändert ihn nicht. Zwei `SetWhiteLed` in einer Anfrage (Stapel) übernehmen den Zeitplan nicht, mit zwei Sekunden Pause dazwischen schon. Eine Änderung zeigt `GetWhiteLed` erst nach ein bis zwei Sekunden. Daraus die Abläufe in Abschnitt 5.
- Zoom über den Endwert hinaus nimmt die Kamera ohne Fehler an (sie begrenzt selbst). Der Fokusbereich ist größer als in der Bibliothek angegeben (Werte über 1500 bei Zoom 4); deshalb prüft das Plugin keine Obergrenze.
- `PtzCtrl ToPos` mit einer nicht gespeicherten Nummer liefert `rspCode` 200; die gespeicherten Positionen stehen in `GetPtzPreset` (`enable` = 1).
- Aufnahme, Push, E-Mail und FTP lassen sich mit `{enable:0/1}` schalten und zeigen das nach 1–2 s.
- Die Fähigkeit `GetAbility` nennt `powerLed` mit `permit` 0, trotzdem beantwortet die Kamera `GetPowerLed`/`SetPowerLed`; deshalb werden Werte nicht allein nach den Fähigkeiten ausgeblendet, sondern nach der Antwort der Kamera (`not support` = nicht anbieten).

## 7. Fehlerverhalten

- Nicht erreichbar, HTTP 502, Verbindung ohne Antwort, Anmeldung abgelehnt: `verbunden` = 0, `status/ok` = 0, das Lebenszeichen läuft weiter. Nach drei aufeinanderfolgenden Fehlern gilt die Verbindung als verloren; neuer Versuch nach 2, 4, 8 … bis 60 s.
- Falsches Passwort: neuer Versuch erst nach 1, 2, 4, 8 und dann alle 15 Minuten (Sperre der Kamera vermeiden). Sitzungsgrenze: 30 s bis 5 Minuten.
- Ein abgelaufenes Token führt zu einer neuen Anmeldung und Wiederholung der Anfrage.
- Beim Beenden: laufende PTZ-Fahrt stoppen, abmelden.

## 8. Ungeprüft

Sirene, andere Kameramodelle (auch solche mit Türklingel, Akku oder mehreren Kanälen/NVR; das Plugin fragt nur Kanal 0 ab), HTTP statt HTTPS an einer echten Kamera, alte Befehle ohne V20 an einer echten Kamera (nur gegen die Fake-Kamera getestet), das Aussehen der Oberfläche und der Dienst auf dem LoxBerry.
