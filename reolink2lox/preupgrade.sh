#!/bin/bash
# $1 Temp-Name, $2 Plugin-Name, $3 Ordner, $4 Version, $5 LBHOMEDIR
# PHP-Pruefung: Das Plugin braucht PHP 7.4 oder neuer (Kommandozeile fuer den Dienst, Apache fuer die Weboberflaeche).
# Exit 2 bricht die Installation bzw. das Update ab (Exit 1 wuerde nur warnen); die Meldung steht im Installationslog.
RLK_MODS="${RLK_APACHE_MODS:-/etc/apache2/mods-enabled}"
rlk_phpbin="$(command -v php 2>/dev/null || echo /usr/bin/php)"
rlk_id="$("$rlk_phpbin" -r 'echo PHP_VERSION_ID;' 2>/dev/null)"
rlk_ver="$("$rlk_phpbin" -r 'echo PHP_VERSION;' 2>/dev/null)"
rlk_fehler=""
if ! [ "${rlk_id:-0}" -ge 70400 ] 2>/dev/null; then
  if [ -n "$rlk_ver" ]; then
    rlk_fehler="Auf der Kommandozeile laeuft PHP $rlk_ver."
  else
    rlk_fehler="Auf der Kommandozeile ist kein PHP verfuegbar."
  fi
fi
for rlk_m in "$RLK_MODS"/php*.load; do
  [ -e "$rlk_m" ] || continue
  rlk_w="$(basename "$rlk_m" .load)"
  rlk_w="${rlk_w#php}"
  case "$rlk_w" in [0-9]*.[0-9]*) ;; *) continue ;; esac
  if [ $(( ${rlk_w%%.*} * 100 + ${rlk_w#*.} )) -lt 704 ]; then
    rlk_fehler="$rlk_fehler Der Webserver (Apache) nutzt PHP $rlk_w."
  fi
done
if [ -n "$rlk_fehler" ]; then
  echo "FEHLER: Dieses Plugin braucht PHP 7.4 oder neuer. $rlk_fehler"
  echo "Das Plugin wurde NICHT installiert. Auf Debian 11 und 12 liefert LoxBerry 4.0 PHP 7.4, auf Debian 13 (Trixie) PHP 8.4; ältere Systeme (Debian 10) haben nur PHP 7.3."
  echo "Anleitung zum Upgrade der Debian-Version: https://wiki.loxberry.de/installation_von_loxberry/upgrade_distribution/start"
  exit 2
fi
"$5/bin/plugins/$3/stop.sh"
mkdir -p "/tmp/$1_upgrade/config" "/tmp/$1_upgrade/data"
cp -p -r "$5/config/plugins/$3/." "/tmp/$1_upgrade/config/"
cp -p -r "$5/data/plugins/$3/."   "/tmp/$1_upgrade/data/" 2>/dev/null
exit 0
