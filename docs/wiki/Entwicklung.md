# Entwicklung

## Aufbau

```
reolink2lox/             das Plugin (landet im ZIP)
  plugin.cfg             Name, Version, Autoupdate, Loglevel
  bin/reolinkd.php       Dienst: fragt die Kameras ab, veröffentlicht Änderungen, nimmt Befehle an
  bin/lib/               ReolinkHttp (HTTP/HTTPS über Streams), ReolinkApi (Anmeldung, Token, Anfragen),
                         ReolinkCamera (Zustand, Abfragen, Befehle), ReolinkMapper (Zustand → Topics),
                         ReolinkCommands (Befehle), ReolinkConfig; Util, Logger, MqttClient, GatewayInfo kommen aus ../common/php
  bin/healthcheck        Healthcheck der LoxBerry-Seite
  webfrontend/htmlauth/  Weboberfläche (LoxBerry Design System)
  daemon, cron, *.sh     Autostart, Watchdog, Installation und Update
tests/                   Testlauf, Fake-Kamera, Fake-Broker, Aufnahmen einer RLC-823S1
tools/make_logo.py       erzeugt Logo und Icons
docs/                    Wiki-Seiten, Text fürs LoxBerry-Wiki
build.sh                 baut das ZIP
```

## Tests

```bash
php tests/run.php
```

Unter Windows ohne `php.ini`: `php -d extension_dir=<php>/ext -d extension=openssl tests/run.php` (die Fake-Kamera braucht TLS). Der Lauf dauert einige Minuten. Er prüft:

- Konfiguration, Abbildung des Zustands auf Topics, Befehle (Unit-Tests)
- die API-Schicht gegen Fake-Kameras: Anmeldung, abgelaufenes Token, falsches Passwort, Sitzungsgrenze, HTTP 502, Verbindung ohne Antwort, chunked-Antworten, Mindestabstand
- den Dienst gegen zwei Fake-Kameras (eine HTTPS wie die RLC-823S1, eine einfache HTTP-Kamera mit alten Befehlen), eine Kamera mit falschem Passwort und einen Fake-Broker: Werte, Erkennungen, alle Befehle mit Quittung, automatischer PTZ-Stopp, Ausfall und Rückkehr einer Kamera, Konfiguration im Betrieb ändern
- Healthcheck, alle Reiter der Weboberfläche mit Attrappen der LoxBerry-Bibliotheken, `build.sh` und ZIP

Die Fake-Kamera (`tests/fake_reolink.php`) beantwortet die Abfragen mit den anonymisierten Aufnahmen einer echten RLC-823S1 (`tests/fixtures/rlc823s1/`, ohne IP, Seriennummer, Namen und Passwörter). Nicht prüfbar ohne echte Geräte: andere Kameramodelle, das echte Zertifikat, die Sirene, das Aussehen der Oberfläche in LoxBerry.

## Wie die Kamera angesprochen wird

- POST `/cgi-bin/api.cgi` mit einer JSON-Liste, z. B. `[{"cmd":"GetMdState","action":0,"param":{"channel":0}}]`; Anmeldung mit `Login` (Token, `leaseTime` 3600 s), Abmeldung mit `Logout`.
- Bewegung und KI: `GetMdState` und `GetAiState` zusammen in einer Anfrage. Neue Firmware liefert `{"people":{"alarm_state":0,"support":1}}`, alte einfache Zahlen.
- Einstellungen: `GetIrLights`, `GetWhiteLed`, `GetPowerLed`, `GetZoomFocus`, `GetAutoFocus`, `GetRecV20`/`GetRec`, `GetPushV20`/`GetPush`, `GetEmailV20`/`GetEmail`, `GetFtpV20`/`GetFtp`, `GetPerformance`, `GetHddInfo`, `GetPtzPreset`. Die neueren Befehle (`…V20`) nur, wenn die Fähigkeit (`GetAbility`) das anzeigt: am RLC-823S1 hat der alte `GetPush` den Webdienst der Kamera zum Absturz gebracht.
- Befehle: `SetWhiteLed`, `SetIrLights`, `SetPowerLed`, `PtzCtrl` (Richtung, `ToPos`, `Stop`), `StartZoomFocus`, `SetAutoFocus`, `AudioAlarmPlay`, `SetRecV20`, `SetPushV20`, `SetEmailV20`, `SetFtpV20` (bzw. die alten Befehle).
- Es gibt keine offizielle Dokumentation der API; die Quellen sind die Bibliothek `reolink_aio` (Home Assistant), die Beschreibung des Perl-Moduls `IPCamera::Reolink` und Versuche an der RLC-823S1. Details: [SPEZIFIKATION.md](../../SPEZIFIKATION.md).

## Regeln

Siehe [CLAUDE.md](../../CLAUDE.md) und die übergeordneten Konventionen: Topics `<plugin>/<gerät>/<wert>`, keine Leerzeichen, Zahlen mit Punkt, Zustände 0/1, `status/ok|zaehler|ts`, Befehle ab Werk gesperrt mit Quittung, Passwörter nie loggen oder senden, Version und Changelog bei jeder Änderung.

## Release

1. Version in `reolink2lox/plugin.cfg` und `reolink2lox/bin/lib/AppVersion.php` anheben, `CHANGELOG.md` ergänzen, Tests laufen lassen.
2. Commit ohne `release.cfg`, Push, Tag `vX.Y.Z`, `gh release create` mit `reolink2lox-X.Y.Z.zip` **und** `reolink2lox.zip`.
3. Danach `bash build.sh release-cfg`, `release.cfg` committen und pushen (löst bei Installationen mit Auto-Updates das Update aus; nur nach Rücksprache).
