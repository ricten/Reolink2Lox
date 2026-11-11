#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
"$5/bin/plugins/$3/stop.sh"
cp -p -r "/tmp/$1_upgrade/config/." "$5/config/plugins/$3/"
cp -p -r "/tmp/$1_upgrade/data/."   "$5/data/plugins/$3/" 2>/dev/null
rm -rf "/tmp/$1_upgrade"
chmod 0600 "$5/config/plugins/$3/reolink.json" 2>/dev/null
"$5/bin/plugins/$3/start.sh"
exit 0
