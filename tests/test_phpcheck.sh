#!/bin/bash
# Prueft die PHP-Pruefung in preinstall.sh und preupgrade.sh mit vorgetaeuschten PHP-Versionen (kein echtes PHP noetig).
# Aufruf aus dem Repo-Ordner:  bash tests/test_phpcheck.sh
HIER="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$HIER/../reolink2lox"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP" "/tmp/rlktest_$$_upgrade"' EXIT
ok=0; fehl=0
check() { # Beschreibung, erwartet, tatsaechlich
  if [ "$2" = "$3" ]; then ok=$((ok+1)); else fehl=$((fehl+1)); echo "FEHLER: $1 (erwartet $2, war $3)"; fi
}
# gefaelschtes php: gibt je nach Argument die Version aus
fakephp() { # Verzeichnis, Version, ID
  mkdir -p "$1"
  printf '#!/bin/bash\ncase "$*" in *PHP_VERSION_ID*) echo -n %s;; *PHP_VERSION*) echo -n %s;; esac\n' "$3" "$2" > "$1/php"
  chmod +x "$1/php"
}
lauf() { # Skript, PHP-Verzeichnis (oder leer), Apache-Module-Verzeichnis
  ( cd "$TMP" && PATH="$2:/usr/bin:/bin" RLK_APACHE_MODS="$3" bash "$PLUGIN/$1" "rlktest_$$" n o v "$TMP/lbhome" > "$TMP/out.txt" 2>&1 ); echo $?
}
mkdir -p "$TMP/mods_leer" "$TMP/mods73" "$TMP/mods74" "$TMP/mods84" "$TMP/leer"
touch "$TMP/mods73/php7.3.load" "$TMP/mods74/php7.4.load" "$TMP/mods84/php8.4.load"
fakephp "$TMP/php73" 7.3.33 70333
fakephp "$TMP/php74" 7.4.33 70433
fakephp "$TMP/php82" 8.2.0 80200
fakephp "$TMP/php84" 8.4.2 80402
# preinstall.sh
check "preinstall: PHP 7.3 -> Abbruch (Exit 2)" 2 "$(lauf preinstall.sh "$TMP/php73" "$TMP/mods_leer")"
check "preinstall: Meldung nennt PHP 7.3.33 und die Anleitung" 2 "$(grep -c -e 'PHP 7.3.33' -e 'upgrade_distribution' "$TMP/out.txt")"
check "preinstall: PHP 8.2 -> in Ordnung" 0 "$(lauf preinstall.sh "$TMP/php82" "$TMP/mods_leer")"
check "preinstall: PHP 7.4 (Apache auch 7.4) -> in Ordnung" 0 "$(lauf preinstall.sh "$TMP/php74" "$TMP/mods74")"
check "preinstall: PHP 8.4, Apache mit PHP 8.4 -> in Ordnung" 0 "$(lauf preinstall.sh "$TMP/php84" "$TMP/mods84")"
check "preinstall: PHP 8.4 auf der Kommandozeile, Apache mit PHP 7.3 -> Abbruch" 2 "$(lauf preinstall.sh "$TMP/php84" "$TMP/mods73")"
check "preinstall: Meldung nennt den Webserver" 1 "$(grep -c 'Webserver (Apache) nutzt PHP 7.3' "$TMP/out.txt")"
check "preinstall: kein PHP -> Abbruch" 2 "$(lauf preinstall.sh "$TMP/leer" "$TMP/mods_leer")"
check "preinstall: Meldung nennt fehlendes PHP" 1 "$(grep -c 'kein PHP' "$TMP/out.txt")"
# preupgrade.sh: bei zu altem PHP Abbruch, bevor der Dienst gestoppt wird; sonst laeuft es weiter
mkdir -p "$TMP/lbhome/bin/plugins/o" "$TMP/lbhome/config/plugins/o" "$TMP/lbhome/data/plugins/o"
printf '#!/bin/bash\ntouch "%s/gestoppt"\n' "$TMP" > "$TMP/lbhome/bin/plugins/o/stop.sh"
chmod +x "$TMP/lbhome/bin/plugins/o/stop.sh"
rm -f "$TMP/gestoppt"
check "preupgrade: PHP 7.3 -> Abbruch (Exit 2)" 2 "$(lauf preupgrade.sh "$TMP/php73" "$TMP/mods_leer")"
check "preupgrade: bei Abbruch wurde der Dienst nicht gestoppt" no "$([ -e "$TMP/gestoppt" ] && echo ja || echo no)"
check "preupgrade: PHP 8.4 -> weiter, Dienst wird gestoppt" 0 "$(lauf preupgrade.sh "$TMP/php84" "$TMP/mods84")"
check "preupgrade: Dienst wurde gestoppt" ja "$([ -e "$TMP/gestoppt" ] && echo ja || echo no)"
# die ZIP-Skripte muessen dieselbe Pruefung enthalten
check "preinstall.sh und preupgrade.sh enthalten dieselbe Pruefung" 1 "$(for f in preinstall.sh preupgrade.sh; do sed -n '/^# PHP-Pruefung/,/^fi$/p' "$PLUGIN/$f" | md5sum; done | sort -u | wc -l)"
echo "$ok Pruefungen in Ordnung, $fehl Fehler"
[ "$fehl" -eq 0 ]
