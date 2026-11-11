#!/bin/bash
# Beendet den Dienst und wartet, bis er wirklich weg ist (sonst startet ein direkt folgendes start.sh
# gegen die noch gehaltene Sperre und der Dienst laeuft danach nicht).
FOLDER="$(basename "$(cd "$(dirname "$0")" && pwd)")"
PIDFILE="/dev/shm/$FOLDER/reolink.pid"
[ -f "$PIDFILE" ] || exit 0
PID="$(cat "$PIDFILE")"
[ -n "$PID" ] || exit 0
kill "$PID" 2>/dev/null || exit 0
for i in $(seq 1 30); do          # bis zu 15 s auf ein sauberes Ende warten
    kill -0 "$PID" 2>/dev/null || exit 0
    sleep 0.5
done
kill -9 "$PID" 2>/dev/null
exit 0
