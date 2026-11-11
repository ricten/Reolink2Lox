#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
mkdir -p "$5/log/plugins/$3" "$5/data/plugins/$3"
# Bei Neuinstallation Dienst starten (bei Upgrade startet postupgrade.sh)
"$5/bin/plugins/$3/start.sh"
exit 0
