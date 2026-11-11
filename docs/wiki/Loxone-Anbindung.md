# Loxone-Anbindung

Der Weg: **Kamera → Reolink2Lox → LoxBerry-MQTT-Broker → LoxBerry-MQTT-Gateway → Miniserver (virtuelle Eingänge)**. Befehle gehen den umgekehrten Weg: **Miniserver (virtueller Ausgang) → UDP → Gateway → Broker → Reolink2Lox → Kamera**.

## 1. MQTT-Gateway einrichten

**Abonnement `reolink/#` im MQTT Gateway:** Beim **Gateway V1** (Perl) trägt das Plugin es über die Datei `mqtt_subscriptions.cfg` selbst ein (eine Zeile je Topic). Das **Gateway V2** (Python) liest diese Datei nicht (LoxBerry-Quellcode): Dort das Abo **von Hand** im MQTT Gateway unter „Abonnements“ eintragen: `reolink/#`. Das Plugin prüft im Reiter *MQTT* und in der Selbstprüfung, ob es eingetragen ist. Mit **eigenem Broker** (Reiter MQTT) bekommt der Miniserver die Werte nur, wenn das Gateway diesen Broker abonniert.

Für Lebenszeichen und Fehlererkennung die Topics `reolink/status/ok` und `reolink/status/zaehler` im Gateway auf **No Cache** stellen.

## 2. Virtuelle Eingänge in Loxone Config

Das Gateway ersetzt `/` durch `_`. Aus `reolink/hof/person` wird der Eingang `reolink_hof_person`. Die Tabelle aller Topics samt Eingangsnamen zeigt die Plugin-Oberfläche im Reiter **Einbindung in Loxone**. Typische Eingänge:

| Eingang | Bedeutung |
|---|---|
| `reolink_hof_verbunden` | 1 = Kamera verbunden |
| `reolink_hof_bewegung` | 1 = Bewegungserkennung ausgelöst |
| `reolink_hof_person`, `…_fahrzeug`, `…_tier` | 1 = KI-Erkennung ausgelöst |
| `reolink_hof_alarm` | 1 = Bewegung oder KI-Erkennung ausgelöst |
| `reolink_hof_alarm_ts` | Zeitpunkt des letzten Alarmbeginns |
| `reolink_hof_licht_scheinwerfer`, `…_licht_ir` | Zustand der Lichter |
| `reolink_hof_aufnahme`, `…_meldung_push` | Aufnahme und Push eingeschaltet |
| `reolink_status_ok` | 1 = alle Kameras in Ordnung |
| `reolink_status_zaehler` | Lebenszeichen 0…999 |

Hinweise:

- **Kurze Erkennungen:** Das Plugin fragt jede Sekunde ab. Die Kamera hält eine Erkennung ohnehin einige Sekunden; wer jede Flanke braucht, nimmt `alarm_ts` (ändert sich bei jedem Beginn) zusätzlich.
- **Ausfallerkennung:** `reolink_status_zaehler` ändert sich alle 10 s. Bleibt er stehen, läuft der Dienst nicht mehr. Ist eine Kamera nicht erreichbar oder das Passwort falsch, wird `reolink_status_ok` 0 und `…_verbunden` der Kamera ebenfalls.
- Es werden nur Änderungen gesendet (alle 5 Minuten alle Werte). Eingänge ohne Änderung behalten ihren Wert.
- Keine Leerzeichen in Kameranamen verwenden, sonst findet das Gateway die Eingänge nicht.
- **Bild der Kamera:** RTSP-Stream oder Standbild bindet man direkt in Loxone ein (z. B. Kamera-Baustein); die Zugangsdaten der Kamera gehören dann dorthin, nicht ins Plugin.

## 3. Befehle aus Loxone

Befehle sind ab Werk **gesperrt** und je Kamera in vier Stufen im Reiter *Kameras* freizugeben (Licht · Schwenken/Zoom/Fokus · Sirene · Aufnahme und Meldungen).

Virtueller Ausgang in Loxone Config:

| Feld | Wert |
|---|---|
| Adresse | `/dev/udp/<LoxBerry-IP>/<UDP-Port>` |
| Befehl bei EIN | `publish reolink/befehl/<name>/<befehl> <wert>` |
| Trennzeichen / HTTP | leer, **ohne** HTTP-Header/Body |

`<UDP-Port>` ist der Befehlseingang des MQTT-Gateways (Standard 11884; der Port ist einstellbar und steht im Plugin im Reiter „Einbindung in Loxone“). An einen virtuellen Ausgang lassen sich mehrere Befehle hängen, einer je Zeile.

| `<befehl>` | `<wert>` | Wirkung |
|---|---|---|
| `scheinwerfer` | `1` oder `0` | Scheinwerfer an (ganztägig) oder aus |
| `scheinwerfer_modus` | `0`–`3` | Betriebsart: 0 aus, 1 automatisch, 2 nachts, 3 Zeitplan |
| `scheinwerfer_helligkeit` | `0`–`100` | Helligkeit in Prozent |
| `ir` | `1` oder `0` | Infrarot-Licht automatisch oder aus |
| `status_led` | `1` oder `0` | Status-LED der Kamera an oder aus |
| `ptz` | `links`, `rechts`, `hoch`, `runter`, `links_hoch`, `links_runter`, `rechts_hoch`, `rechts_runter`, `zoom_plus`, `zoom_minus`, `stopp` | Kamera fährt die eingestellte Zeit (Standard 1 s) und stoppt dann selbst; `stopp` hält sofort an |
| `ptz_position` | `0`–`63` | gespeicherte Position anfahren (die Nummern stehen im Reiter *Kameras*) |
| `zoom` | Zahl | Zoomstufe absolut (0 = Weitwinkel) |
| `fokus` | Zahl | Fokus absolut |
| `autofokus` | `1` oder `0` | Autofokus an oder aus |
| `sirene` | `1` oder `0` | 1 spielt den Alarmton einmal ab, 0 stoppt ihn (an der RLC-823S1 geprüft) |
| `aufnahme` | `1` oder `0` | Aufnahme (Zeitplan der Kamera) an oder aus |
| `push`, `email`, `ftp` | `1` oder `0` | Push, E-Mail, FTP der Kamera bei Alarm an oder aus |

Beispiel: `publish reolink/befehl/hof/scheinwerfer 1`.

**Quittung:** Unter `reolink/befehl/<name>/<befehl>/quittung` meldet das Plugin zuerst `angenommen` und dann `ausgefuehrt` oder `abgelehnt: <Grund>` (nie retained). Auch dieses Topic lässt sich im Gateway als Eingang nutzen. Mögliche Gründe: `Befehle sind gesperrt (Stufe … freigeben)`, `ungültiger Wert`, `diese Kamera unterstützt das nicht`, `der Kamera-Benutzer hat dafür keine Berechtigung (Administrator nötig)`, `Kamera nicht verbunden`, `zu schnell (höchstens ein Befehl alle 0,3 Sekunden)`, `die Kamera lehnt den Wert ab`. Höchstens ein Befehl alle 0,3 Sekunden und Kamera (Richtungsbefehle ausgenommen); bis zu zehn warten in der Schlange.

**Scheinwerfer:** `scheinwerfer 0` braucht zwei Schritte mit zwei Sekunden Pause (die Kamera übernimmt den gemerkten Zeitplan nur mit der Betriebsart Zeitplan); die Quittung `ausgefuehrt` kommt deshalb erst nach etwa zwei Sekunden.

Der Befehlseingang des Gateways kennt kein Zurückmelden: ob ein Befehl ankam, zeigt nur die Quittung.
