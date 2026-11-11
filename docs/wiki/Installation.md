# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `reolink2lox-<version>.zip` und ein gleiches `reolink2lox.zip` ohne Version im Namen (enthält nur den Inhalt von `reolink2lox/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

**Immer das neueste Release:** Der Link `https://github.com/ricten/Reolink2Lox/releases/latest/download/reolink2lox.zip` lädt das ZIP des jeweils letzten Releases (ohne Version im Dateinamen). Er lässt sich in der LoxBerry-Plugin-Verwaltung beim Installieren von einer Internetadresse eintragen und muss nach einem neuen Release nicht angepasst werden.

## An der Kamera vorbereiten

1. **IP-Adresse:** Der Kamera am besten eine feste Adresse geben (DHCP-Reservierung im Router).
2. **HTTPS oder HTTP aktivieren:** In der Reolink-App oder der Weboberfläche der Kamera unter *Einstellungen → Netzwerk → Erweitert → Server-Einstellungen* muss HTTPS und/oder HTTP eingeschaltet sein. Das Plugin probiert erst HTTPS (Port 443), dann HTTP (Port 80); andere Ports lassen sich im Plugin eintragen.
3. **Benutzer:** Für das Plugin einen **eigenen Benutzer** anlegen (*Einstellungen → System → Benutzerverwaltung*). **Lesen** (Bewegung, KI, Werte) geht mit einem normalen Benutzer; **Schalten** (Licht, Zoom, Aufnahme, Sirene …) braucht einen **Administrator**. Ein zu schwacher Benutzer führt zur Quittung `abgelehnt: der Kamera-Benutzer hat dafür keine Berechtigung`.
4. Die Kamera erlaubt nur **wenige gleichzeitige Anmeldungen**. Das Plugin meldet sich einmal an, nutzt die Sitzung weiter und meldet sich beim Beenden ab. Wer die Kamera gleichzeitig mit anderen Programmen (App, NVR, ioBroker) abfragt, sollte den ioBroker-Adapter abschalten, sobald das Plugin läuft.

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Die Installation startet den Dienst.
3. Plugin **Reolink2Lox** öffnen, Reiter **Kameras**: Host/IP, Benutzer und Passwort eintragen, **Aktiv** anhaken, **Speichern** (siehe [Konfiguration](Konfiguration.md)).
4. Im **MQTT Gateway** das Abo `reolink/#` eintragen: Beim Gateway V1 macht das die Abo-Datei des Plugins, beim **Gateway V2** muss es von Hand unter „Abonnements“ stehen. Der Reiter **MQTT** zeigt, ob es eingetragen ist.
5. Reiter **Test** → **Selbstprüfung** und **Sofort abrufen**.
6. Nach wenigen Sekunden erscheinen die Werte unter `reolink/<name>/…` (siehe [MQTT-Topics](MQTT-Topics.md)).

## Was die Installation einrichtet

| Teil | Ort (von LoxBerry gesetzt) |
|---|---|
| Dienst und Bibliotheken | `bin/plugins/reolink2lox/` |
| Konfiguration (mit Passwörtern) | `config/plugins/reolink2lox/reolink.json` (Rechte 0600) |
| Logdatei | `log/plugins/reolink2lox/reolink.log` |
| Daten (gemerkter Zeitplan des Scheinwerfers) | `data/plugins/reolink2lox/` |
| Live-Status (RAM) | `/dev/shm/reolink2lox/status.json` |
| Autostart | Daemon beim Boot (root → startet als `loxberry`) |
| Watchdog | Cronjob jede Minute startet den Dienst, falls er nicht läuft |

Das Plugin ändert nichts außerhalb seiner eigenen Ordner.

## Sicherheit

- Das **Passwort** der Kamera liegt nur in der Konfigurationsdatei (Rechte 0600). Es wird nie angezeigt, nie geloggt und nie per MQTT gesendet; in der Adresse der Anfragen steht es ebenfalls nie (nur im Anmelde-Körper).
- Die Kamera verwendet ein **selbst signiertes Zertifikat**. Das Plugin prüft es nicht (das Gerät steht im Heimnetz); die Verbindung ist verschlüsselt, aber nicht gegen einen Angreifer im selben Netz abgesichert. Über HTTP ist sie unverschlüsselt.
- Befehle sind ab Werk gesperrt (siehe [Konfiguration](Konfiguration.md)).
- Bei **falschem Passwort** probiert das Plugin nur selten erneut (nach 1, 2, 4 … bis 15 Minuten), damit die Kamera den Zugang nicht sperrt.

## Update

Beim Update sichern `preupgrade.sh`/`postupgrade.sh` **Konfiguration und Daten** und spielen sie zurück. Neue Konfigurationsschlüssel ergänzt der Dienst beim Laden automatisch mit Standardwerten.

### Automatische Updates

Das Plugin nutzt die Update-Funktion der LoxBerry-Plugin-Verwaltung. In der `plugin.cfg` steht der Verweis auf die Datei `release.cfg` im GitHub-Repository (`ricten/Reolink2Lox`, Zweig `main`). LoxBerry prüft sie täglich und vergleicht die dort eingetragene Version mit der installierten.

- **Neue Installationen** stehen in der Plugin-Verwaltung bei „Automatische Updates“ auf **Releases**: eine neuere Version wird selbst heruntergeladen und installiert. Dort lässt sich auch *Aus* oder *Benachrichtigen* wählen.
- Konfiguration und Daten bleiben wie bei jedem Update erhalten (siehe oben).
- Die `release.cfg` zeigt immer auf das ZIP der jeweils letzten Version; sie wird erst nach dem GitHub-Release angepasst, damit nie auf ein fehlendes ZIP verwiesen wird.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/reolink2lox` gelöscht. An der Kamera bleibt alles, wie es ist (außer Einstellungen, die Sie über das Plugin geändert haben).
