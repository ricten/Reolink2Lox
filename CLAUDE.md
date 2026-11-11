# Plugin-Regeln Reolink2Lox

Ergänzt die Regeln im übergeordneten Ordner (`../CLAUDE.md`, `../README.md`); bei Widerspruch gilt diese Datei.

- Topic-Wurzel `reolink`, Plugin-Ordner `reolink2lox`, GitHub `ricten/Reolink2Lox`.
- Bis zu fünf Kameras (`ReolinkConfig::MAX_KAMERAS`). Jede hat Name, Host, Benutzer, Passwort und vier Befehlsstufen (ab Werk gesperrt).
- Die Kamera ist empfindlich: Anfragen immer nacheinander mit Mindestabstand (0,25 s), nie viele Befehle auf einmal, nur Befehle senden, die die Fähigkeiten der Kamera zulassen. Am RLC-823S1 hat eine schnelle Folge von Anfragen (darunter der alte Befehl `GetPush` an einer Kamera mit `GetPushV20`) den Webdienst der Kamera zum Absturz gebracht. Neuere Befehle (`…V20`) nur nutzen, wenn die Fähigkeit das anzeigt.
- Passwort der Kamera nie in der Adresse, im Log, per MQTT oder in der Oberfläche. Falsches Passwort nur selten erneut probieren (Sperre der Kamera).
- Nie vorgesehen: Neustart, Zurücksetzen, Firmware, Benutzer, Netzwerk, Formatieren. Kein Zugriff auf Bild/Stream (das bindet man direkt in Loxone ein).
- Keine IP-Adresse, Seriennummer oder Zugangsdaten einer echten Kamera in Repo, Tests, Dokumentation oder Logs (Tests verwenden `192.0.2.x`/`127.0.0.1` und anonymisierte Aufnahmen).
- Tests: `php tests/run.php` (unter Windows ohne php.ini: `php -d extension_dir=<php>/ext -d extension=openssl tests/run.php`); die Fake-Kamera (`tests/fake_reolink.php`) folgt den anonymisierten Aufnahmen einer RLC-823S1 in `tests/fixtures/rlc823s1/`.
- Release-Ablauf: Commit ohne `release.cfg`, Push, Tag `vX.Y.Z`, `gh release create` mit beiden ZIPs (`reolink2lox-X.Y.Z.zip` und `reolink2lox.zip`), danach `bash build.sh release-cfg`, committen, pushen. **`release.cfg` nur nach Rücksprache mit Rico anlegen oder ändern**: sie löst bei allen Installationen mit aktivierten Auto-Updates sofort ein Update aus.
- Logos und Icons nicht eigenmächtig ändern.
