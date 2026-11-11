#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
"$5/bin/plugins/$3/stop.sh"
mkdir -p "/tmp/$1_upgrade/config" "/tmp/$1_upgrade/data"
cp -p -r "$5/config/plugins/$3/." "/tmp/$1_upgrade/config/"
cp -p -r "$5/data/plugins/$3/."   "/tmp/$1_upgrade/data/" 2>/dev/null
exit 0
