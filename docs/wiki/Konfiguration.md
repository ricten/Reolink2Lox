# Konfiguration

Die Weboberfläche des Plugins (LoxBerry → Plugin-Verwaltung → Reolink2Lox) hat fünf Reiter: **Kameras · MQTT · Einbindung in Loxone · Test · Logdateien**. Änderungen wirken ohne Neustart (der Dienst lädt die Konfiguration neu, sobald sich die Datei ändert); jeder Reiter speichert nur seine eigenen Einstellungen.

## Reiter Kameras

### Kameras (bis zu fünf)

Ein farbiges Kästchen zeigt den Zustand (grün verbunden, grau nicht aktiv, rot Fehler mit Text). Bei verbundenen Kameras steht darunter, welche Positionen in der Kamera gespeichert sind (Nummer und Name; die Nummer gehört in den Befehl `ptz_position`).

| Spalte | Beschreibung |
|---|---|
| Aktiv | Nur aktive Einträge werden abgefragt |
| Name im Topic | `a-z 0-9 _ -`, leer = `kamera1`, `kamera2` … Doppelte Namen bekommen eine Nummer |
| Host/IP, Port | Adresse der Kamera im Heimnetz; Port leer = Standard (443 bei HTTPS, 80 bei HTTP) |
| Zugriff | automatisch (erst HTTPS, dann HTTP), nur HTTPS oder nur HTTP |
| Benutzer, Passwort | Anmeldung an der Kamera. Das Passwort wird nie angezeigt, geloggt oder per MQTT gesendet; ein leeres Feld behält das gespeicherte Passwort |
| Befehle freigeben | Vier Stufen (siehe unten), ab Werk alle aus |

Lesen geht mit einem normalen Benutzer, **Schalten braucht einen Administrator** der Kamera. Die Kamera verwendet ein **selbst signiertes Zertifikat**, das das Plugin nicht prüft.

Ist eine Kamera nicht erreichbar oder das Passwort falsch, meldet `reolink/status/ok` den Wert 0 und `reolink/<name>/verbunden` ist 0. Bei falschem Passwort probiert das Plugin nur selten erneut (nach 1, 2, 4, 8 und dann alle 15 Minuten), damit die Kamera den Zugang nicht sperrt.

### Befehle freigeben

| Stufe | Befehle |
|---|---|
| Licht (Scheinwerfer, Infrarot, Status-LED) | `scheinwerfer`, `scheinwerfer_modus`, `scheinwerfer_helligkeit`, `ir`, `status_led` |
| Schwenken, Neigen, Zoom, Fokus | `ptz`, `ptz_position`, `zoom`, `fokus`, `autofokus` |
| Sirene | `sirene` |
| Aufnahme und Meldungen (Aufnahme, Push, E-Mail, FTP) | `aufnahme`, `push`, `email`, `ftp` |

Welche Befehle eine Kamera tatsächlich kann, liest das Plugin aus ihren Fähigkeiten; Befehle, die sie nicht kennt, werden mit `abgelehnt: diese Kamera unterstützt das nicht` quittiert. Nie vorgesehen sind Neustart, Zurücksetzen, Firmware, Benutzer, Netzwerk und Formatieren der Speicherkarte.

**Hinweis zum Scheinwerfer:** Die Kamera schaltet den Scheinwerfer im Zeitplan-Betrieb nur innerhalb ihres Zeitplans. Der Befehl `scheinwerfer 1` setzt deshalb den Zeitplan der Kamera auf ganztägig (0:00–23:59) und merkt sich den bisherigen Zeitplan; `scheinwerfer 0` stellt ihn wieder her (in zwei Schritten mit zwei Sekunden Pause, die Quittung kommt am Ende) und schaltet die Betriebsart auf *aus*.

### Allgemein

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Bewegung und KI-Erkennung abfragen alle … Sekunden | 1 s | 1–10 s. Kürzere Ereignisse gehen verloren; die Kamera meldet sie ohnehin nur für einige Sekunden |
| Einstellungen und Werte abfragen alle … Sekunden | 30 s | 10–3600 s. Licht, Zoom, Aufnahme, Meldungen, Last, Speicherkarte; je Durchlauf wird nur eine Anfrage gesendet |
| Richtungsbefehl: so viele Sekunden fährt die Kamera | 1 s | 1–10 s. Danach stoppt das Plugin die Kamera selbst, damit sie bei einem Ausfall nicht weiterfährt |
| Geschwindigkeit beim Schwenken, Neigen und Anfahren von Positionen | 32 | 1–64 |

Die Kamera ist empfindlich: das Plugin sendet höchstens vier Anfragen pro Sekunde und Kamera.

## Reiter MQTT

Oben zeigt ein farbiges Feld das **Gateway-Abo** (grün ab einem passenden Abo, rot nur ohne Abo).

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Eigenen MQTT-Broker verwenden | aus | Aus: Broker des LoxBerry (Zugangsdaten verwaltet LoxBerry) |
| Host/IP, Port | - / 1883 | Eigener Broker (ohne TLS) |
| Benutzer, Passwort | leer | Optional; das Passwort wird nie angezeigt oder geloggt |

Das ist nicht die Kamera (Reolink-Kameras haben keinen MQTT-Anschluss). Mit eigenem Broker erreichen die Werte den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert.

## Reiter Test und Logdateien

| Aktion | Wirkung |
|---|---|
| Sofort abrufen | Zeigt, wenn der Dienst läuft, dessen aktuelle Werte (sonst meldet sich der Test selbst an jeder Kamera an und liest alle Werte); angezeigt werden die Topics und Werte, die das Plugin veröffentlichen würde, sowie die Befehle, die die Kamera kennt |
| Selbstprüfung | Konfiguration, Anmeldung je Kamera, Broker, Gateway-Abo, Dienst |
| Dienst neu starten | Beendet und startet den Dienst |

Der Loglevel wird in der LoxBerry-Plugin-Verwaltung eingestellt. Die Logdatei steht im Reiter *Logdateien*.

## Konfigurationsdatei

`config/plugins/reolink2lox/reolink.json` (Rechte 0600) enthält die Passwörter der Kameras und ggf. das des eigenen MQTT-Brokers. Sie bleibt bei Updates erhalten. Wer sie von Hand ändert, sollte den Dienst danach nicht neu starten müssen (er lädt sie selbst neu).
