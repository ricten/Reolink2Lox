#!/bin/bash
# Startet den Dienst im Hintergrund (vom Daemon, vom Watchdog-Cron, von den Installationsskripten und von der Weboberflaeche genutzt).
DIR="$(cd "$(dirname "$0")" && pwd)"
FOLDER="$(basename "$DIR")"
PIDFILE="/dev/shm/$FOLDER/reolink.pid"
LOGDIR="REPLACELBPLOGDIR"

# Laeuft der Dienst schon, nichts tun (der Watchdog ruft das jede Minute auf)
if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
    exit 0
fi

mkdir -p "$LOGDIR" 2>/dev/null
# Fehlermeldungen des PHP-Starts nicht verschlucken: start-error.log
if command -v setsid >/dev/null 2>&1; then
    setsid nohup php "$DIR/reolinkd.php" </dev/null >/dev/null 2>"$LOGDIR/start-error.log" &
else
    nohup php "$DIR/reolinkd.php" </dev/null >/dev/null 2>"$LOGDIR/start-error.log" &
fi
exit 0
