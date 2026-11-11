# MQTT-Topics

Schema `reolink/<name>/<wert>`, Zahlen mit Punkt ohne Einheit, Zustände 0/1, nicht retained. Werte, die eine Kamera nicht liefert, werden nicht gesendet (z. B. keine KI-Arten, die sie nicht kann, kein Scheinwerfer an Kameras ohne Scheinwerfer). Gesendet werden nur Änderungen und alle 5 Minuten alle Werte.

Der Loxone-Eingangsname ist das Topic mit `_` statt `/`, z. B. `reolink_hof_person`. Die Plugin-Oberfläche zeigt die Tabelle im Reiter *Einbindung in Loxone*.

## Erkennung

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/bewegung` | 0/1 | Bewegungserkennung der Kamera ausgelöst |
| `reolink/<name>/person` | 0/1 | KI-Erkennung Person |
| `reolink/<name>/fahrzeug` | 0/1 | KI-Erkennung Fahrzeug |
| `reolink/<name>/tier` | 0/1 | KI-Erkennung Tier (Hund/Katze) |
| `reolink/<name>/gesicht`, `paket`, `besucher` | 0/1 | nur bei Modellen, die das melden (Gesicht, Paket, Besucher an der Türklingel) |
| `reolink/<name>/alarm` | 0/1 | 1, solange Bewegung oder eine KI-Erkennung ausgelöst ist |
| `reolink/<name>/alarm_ts` | s | Unix-Zeitstempel des letzten Beginns einer Erkennung (wird beim Dienststart nicht gesetzt) |

Andere KI-Arten, die eine Kamera meldet, erscheinen unter ihrem Namen (auf a–z, 0–9, _ bereinigt).

## Licht

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/licht/scheinwerfer` | 0/1 | Scheinwerfer leuchtet |
| `reolink/<name>/licht/scheinwerfer_modus` | 0–3 | Betriebsart: 0 aus, 1 automatisch, 2 nachts, 3 Zeitplan (welche die Kamera kennt, hängt vom Modell ab) |
| `reolink/<name>/licht/scheinwerfer_helligkeit` | % | Helligkeit des Scheinwerfers |
| `reolink/<name>/licht/ir` | 0/1 | Infrarot-Licht: 1 automatisch (oder an), 0 aus |
| `reolink/<name>/licht/status_led` | 0/1 | Status-LED der Kamera an |

## Objektiv

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/ptz/zoom` | - | Zoomposition (0 = Weitwinkel) |
| `reolink/<name>/ptz/fokus` | - | Fokusposition (Zahl wie in der Kamera; der Bereich hängt vom Modell und vom Zoom ab) |
| `reolink/<name>/ptz/autofokus` | 0/1 | Autofokus an |

## Aufnahme und Meldungen

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/aufnahme` | 0/1 | Aufnahme (Zeitplan der Kamera) eingeschaltet |
| `reolink/<name>/meldung/push` | 0/1 | Push-Meldungen der Kamera bei Alarm eingeschaltet |
| `reolink/<name>/meldung/email` | 0/1 | E-Mail der Kamera bei Alarm eingeschaltet |
| `reolink/<name>/meldung/ftp` | 0/1 | FTP-Upload der Kamera bei Alarm eingeschaltet |

## Speicherkarte und System

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/sd/vorhanden` | 0/1 | Speicherkarte eingelegt |
| `reolink/<name>/sd/kapazitaet_gb`, `frei_gb`, `belegt_prozent` | GB, GB, % | nur mit Speicherkarte |
| `reolink/<name>/system/cpu_prozent` | % | Auslastung der Kamera |
| `reolink/<name>/system/netz_kbit` | kbit/s | Netzdurchsatz laut Kamera |
| `reolink/<name>/system/codec_kbit` | kbit/s | Datenrate des Encoders laut Kamera |

## Info und Status

| Topic | Einheit | Bedeutung |
|---|---|---|
| `reolink/<name>/verbunden` | 0/1 | Verbindung zur Kamera steht und sie antwortet |
| `reolink/<name>/info/modell` | Text | Modell, z. B. `RLC-823S1` |
| `reolink/<name>/info/firmware` | Text | Firmware der Kamera |
| `reolink/<name>/info/name` | Text | Gerätename der Kamera |
| `reolink/status/ok` | 0/1 | Alle Kameras in Ordnung (eine nicht erreichbare Kamera oder ein falsches Passwort ergibt 0) |
| `reolink/status/zaehler` | 0–999 | Lebenszeichen, zählt alle 10 s weiter |
| `reolink/status/ts` | s | Unix-Zeitstempel des letzten Lebenszeichens |

Beim Absturz des Dienstes setzt der Broker `reolink/status/ok` per Letzter-Wille-Nachricht auf 0 (retained).

## Befehle

`reolink/befehl/<name>/<befehl>` mit Wert; Quittung unter `reolink/befehl/<name>/<befehl>/quittung` (nie retained). Siehe [Loxone-Anbindung](Loxone-Anbindung.md).

## Nicht enthalten

Das Bild der Kamera (RTSP-Stream, Standbild) und Ereignisaufnahmen gehören nicht zum Plugin; sie werden direkt in Loxone oder in einem Aufnahmeprogramm eingebunden. Die Seriennummer der Kamera wird nicht gesendet.
