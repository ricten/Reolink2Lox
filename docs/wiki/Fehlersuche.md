# Fehlersuche

Zuerst im Plugin den Reiter **Test**: *Selbstprüfung* zeigt, was funktioniert (Konfiguration, Anmeldung je Kamera, Broker, Gateway-Abo, Dienst); *Sofort abrufen* zeigt alle Werte einer Kamera. Die Logdatei steht im Reiter **Logdateien**; den Loglevel stellt man in der LoxBerry-Plugin-Verwaltung ein (Debug zeigt auch, welche Werte eine Kamera nicht kennt).

| Beobachtung | Ursache und Abhilfe |
|---|---|
| `Anmeldung abgelehnt: Benutzer oder Passwort falsch?` | Benutzer oder Passwort stimmt nicht. Das Plugin probiert dann nur nach 1, 2, 4, 8 und dann alle 15 Minuten erneut (die Kamera kann den Zugang sonst sperren). Nach dem Korrigieren in den Einstellungen verbindet es sich selbst neu |
| `Zu viele Anmeldungen an der Kamera (Sitzungsgrenze erreicht)` | Die Kamera erlaubt nur wenige gleichzeitige Anmeldungen (App, NVR, ioBroker, andere Plugins). Andere Zugriffe beenden oder die Kamera neu starten |
| `Zeitüberschreitung: Kamera nicht erreichbar` / `Verbindung abgelehnt (Port geschlossen?)` | IP-Adresse prüfen; in der Kamera HTTPS oder HTTP einschalten (*Einstellungen → Netzwerk → Erweitert → Server-Einstellungen*); Port im Plugin eintragen, wenn er geändert wurde |
| `Webdienst der Kamera antwortet mit HTTP 502` | Der Webdienst der Kamera ist überlastet oder startet neu. Das Plugin verbindet sich nach einigen Sekunden selbst neu. Tritt es dauernd auf: Abfrageintervalle verlängern, andere Zugriffe auf die Kamera beenden |
| Kamera meldet sich, aber Befehle werden mit `abgelehnt: der Kamera-Benutzer hat dafür keine Berechtigung` quittiert | Der Plugin-Benutzer ist kein Administrator. In der Kamera dem Benutzer die Rolle Administrator geben (oder einen Administrator eintragen) |
| `abgelehnt: diese Kamera unterstützt das nicht` | Die Kamera hat die Funktion nicht (z. B. Scheinwerfer, Zoom). Im Reiter *Test* steht unter *Sofort abrufen*, welche Befehle sie kennt |
| `abgelehnt: Befehle sind gesperrt` | Die Stufe ist im Reiter *Kameras* nicht freigegeben |
| Keine Topics in Loxone | Im MQTT Gateway fehlt das Abo `reolink/#` (Gateway V2: von Hand eintragen; Reiter *MQTT* zeigt es) |
| `reolink/status/ok` ist 0 | Mindestens eine Kamera nicht erreichbar oder Anmeldung abgelehnt: Reiter *Kameras* (roter Kasten mit Text) oder Log |
| Werte kommen verzögert | Bewegung und KI kommen im eingestellten Takt (Standard 1 s), Licht, Zoom, Aufnahme usw. im Takt der Einstellungen (Standard 30 s) und nach einem Befehl sofort |
| Scheinwerfer geht nach `scheinwerfer 1` zu bestimmten Zeiten wieder aus | Der Zeitplan der Kamera wurde nicht auf ganztägig gesetzt (z. B. weil ein anderes Programm ihn zurücksetzt). Befehl wiederholen oder `scheinwerfer_modus` prüfen |
| Der Dienst läuft nicht | Der Wächter (Cronjob) startet ihn jede Minute. Sonst `log/plugins/reolink2lox/start-error.log` ansehen (PHP-Fehler beim Start) |

## Wenn die Kamera nicht mehr antwortet

Am RLC-823S1 hat in der Entwicklung eine schnelle Folge von Anfragen den Webdienst der Kamera zum Absturz gebracht (er antwortete mit HTTP 502 und startete nach etwa einer Minute neu). Das Plugin hält deshalb Abstand zwischen den Anfragen und sendet nur Befehle, die die Fähigkeiten der Kamera zulassen. Fällt eine Kamera trotzdem aus, genügt es, den Plugin-Dienst einen Moment zu beenden oder die Kamera vom Strom zu trennen.
