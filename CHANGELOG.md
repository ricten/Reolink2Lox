# Changelog

Alle Änderungen an diesem Plugin. Schema: MAJOR.MINOR.PATCH.

## [0.1.4] - 2026-11-12

### Geändert
- **Läuft jetzt auch mit PHP 7.4** (Debian 11 und 12) sowie weiterhin mit PHP 8.2, 8.3 und 8.4: PHP-8-Syntax (Konstruktor-Promotion, `readonly`, `match`, Union-Typen, `mixed`, `catch` ohne Variable) wurde umgebaut, die Funktionen `str_contains`, `str_starts_with`, `str_ends_with` und `array_is_list` haben einen Rückfall. Das Verhalten ist unverändert, die Topics bleiben gleich.
- Installation und Update verlangen jetzt mindestens PHP 7.4 (bisher 8.2). Ältere PHP-Versionen brechen weiterhin mit einer klaren Meldung ab.
- Getestet mit PHP 7.4, 8.2, 8.3 und 8.4.

## [0.1.3] - 2026-11-11

### Geändert
- Installation und Update prüfen jetzt, ob PHP 8.2 oder neuer verfügbar ist (Kommandozeile und Apache). Ist es älter, bricht `preinstall.sh` bzw. `preupgrade.sh` mit einer klaren Meldung im Installationslog ab (Hinweis auf LoxBerry 4.0 mit Debian 13 und die Upgrade-Anleitung des LoxBerry-Wikis), statt dass die Weboberfläche nach der Installation mit HTTP 500 antwortet. Bei einem Update bleibt die alte Version dabei unverändert erhalten. Test: `bash tests/test_phpcheck.sh`.
- Doku: PHP 8.2 oder neuer hat ein LoxBerry 4.0 auf Debian 13 (Trixie); auf Debian 11 und 12 ist meist PHP 7.4 aktiv.

## [0.1.2] - 2026-11-11

### Behoben
- Läuft jetzt auch mit PHP 8.2 und 8.3. Bisher war PHP 8.4 nötig: typisierte Klassenkonstanten gibt es erst ab PHP 8.3, ältere PHP-Versionen brachen mit einem Syntaxfehler ab (HTTP 500 in der Weboberfläche, Dienst startete nicht). Keine Funktionsänderung.

## [0.1.1] - 2026-11-11

### Behoben
- Zu langsame Abfragen: Der HTTP-Client wartete bis zum Schließen der Verbindung. Hält eine Kamera die Verbindung nach der Antwort offen, dauerte jede Anfrage bis zur Zeitgrenze von 10 s. Jetzt endet das Lesen mit dem Ende der Antwort (Content-Length).
- „Sofort abrufen“ und „Selbstprüfung“ (Reiter Test) meldeten sich bei jeder Kamera ein zweites Mal an, nacheinander und mit bis zu 25 s je Kamera; bei zwei Kameras konnte das die Zeitgrenze von PHP überschreiten. Läuft der Dienst, zeigen beide jetzt dessen aktuelle Werte (sofort, ohne zweite Sitzung an der Kamera). Nur ohne laufenden Dienst melden sie sich selbst an, ohne PHP-Zeitgrenze.

### Geändert
- `status.json` enthält je Kamera die aktuellen Topics (nur für die Weboberfläche, liegt im RAM).

## [0.1.0] - 2026-11-11

Erste Fassung (Beta). Geprüft an einer Reolink RLC-823S1 (Firmware v3.1.0.4885); andere Modelle nach der API-Beschreibung der Bibliothek `reolink_aio`.

### Neu
- Bis zu fünf Kameras, je mit eigenem Namen im Topic (`reolink/<name>/…`), Zugriff über die lokale HTTP-API der Kamera (HTTPS mit selbst signiertem Zertifikat oder HTTP, automatisch).
- Bewegungserkennung und KI-Erkennung (Person, Fahrzeug, Tier, je nach Modell Gesicht, Paket, Besucher) im Sekundentakt, dazu `alarm` und `alarm_ts`.
- Zustand von Scheinwerfer (Zustand, Betriebsart, Helligkeit), Infrarot-Licht, Status-LED, Zoom, Fokus, Autofokus, Aufnahme, Push, E-Mail und FTP, Kamera-Last, Speicherkarte, Modell und Firmware.
- Befehle aus Loxone mit Quittung, ab Werk gesperrt, in vier Stufen je Kamera: Licht, Schwenken/Zoom/Fokus, Sirene, Aufnahme und Meldungen. Richtungsbefehle stoppt das Plugin nach einer einstellbaren Zeit selbst.
- Anmeldung mit Token: eine Sitzung je Kamera, Erneuerung vor Ablauf, Abmelden beim Beenden; Mindestabstand zwischen Anfragen (die Kamera ist empfindlich).
- Fehlerfälle: Kamera nicht erreichbar, HTTP 502 der Kamera, abgelaufenes Token, Sitzungsgrenze, falsches Passwort (selten wiederholt, damit die Kamera den Zugang nicht sperrt) ergeben `status/ok` = 0, `verbunden` = 0; das Lebenszeichen läuft weiter.
- Weboberfläche im LoxBerry Design System: Kameras, MQTT, Einbindung in Loxone, Test, Logdateien; Healthcheck; automatische Updates über `release.cfg`.
