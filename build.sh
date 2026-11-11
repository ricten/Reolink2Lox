#!/bin/bash
# Baut reolink2lox-<version>.zip aus reolink2lox/ plus den gemeinsamen Klassen aus ../common/php (ohne tests/, .git, docs/).
cd "$(dirname "$0")" || exit 1
VER="$(grep '^VERSION=' reolink2lox/plugin.cfg | cut -d= -f2 | tr -d '\r')"
CODEVER="$(grep -o "CURRENT = '[^']*'" reolink2lox/bin/lib/AppVersion.php | cut -d"'" -f2)"
if [ "$VER" != "$CODEVER" ]; then
    echo "Versionen passen nicht: plugin.cfg=$VER, AppVersion.php=$CODEVER" >&2
    exit 1
fi
if ! grep -q "^## \[$VER\]" CHANGELOG.md; then
    echo "CHANGELOG.md hat keinen Eintrag fuer Version $VER" >&2
    exit 1
fi
for c in Util Logger MqttClient GatewayInfo; do
    [ -f "../common/php/$c.php" ] || { echo "../common/php/$c.php fehlt" >&2; exit 1; }
done
# release.cfg (Autoupdate): Hinweis, wenn sie auf eine andere Version zeigt als plugin.cfg
if [ -f release.cfg ]; then
    RCVER="$(grep '^VERSION=' release.cfg | cut -d= -f2 | tr -d '\r\n')"
    [ "$RCVER" = "$VER" ] || echo "Hinweis: release.cfg zeigt auf $RCVER, plugin.cfg hat $VER (erst nach dem GitHub-Release mit 'bash build.sh release-cfg' aktualisieren)" >&2
fi
if [ "${1:-}" = "release-cfg" ]; then
    # schreibt release.cfg (liegt im Repo, nicht im ZIP); erst nach dem GitHub-Release ausfuehren und pushen
    cat > release.cfg <<EOT
[AUTOUPDATE]
# Autoupdate des LoxBerry: plugin.cfg verweist (RELEASECFG) auf diese Datei im Branch main.
# Erst nach dem GitHub-Release aktualisieren (bash build.sh release-cfg), nie auf eine Version zeigen, deren ZIP noch fehlt.

# Version des neuen Releases
VERSION=$VER

# Direkter Download des Plugin-ZIP
ARCHIVEURL=https://github.com/ricten/Reolink2Lox/releases/download/v$VER/reolink2lox-$VER.zip

# Seite mit Hinweisen zu diesem Release
INFOURL=https://github.com/ricten/Reolink2Lox/releases/tag/v$VER
EOT
    echo "release.cfg geschrieben (Version $VER)"
    exit 0
fi
PY="$(command -v python3 || command -v python)" || { echo "python noetig (ersetzt zip)" >&2; exit 1; }

# Staging: Plugin + gemeinsamer Code
STAGE="$PWD/build/stage"
rm -rf build
mkdir -p "$STAGE"
cp -r reolink2lox/. "$STAGE/"
cp ../common/php/Util.php ../common/php/Logger.php ../common/php/MqttClient.php ../common/php/GatewayInfo.php "$STAGE/bin/lib/"

# PHP-Syntaxpruefung, sofern php vorhanden
if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do
        php -l "$f" >/dev/null || { echo "Syntaxfehler in $f" >&2; exit 1; }
    done < <(find "$STAGE" -name '*.php')
else
    echo "Hinweis: php nicht gefunden, Syntaxpruefung uebersprungen" >&2
fi
# Healthcheck (PHP-Skript ohne Endung)
if command -v php >/dev/null 2>&1 && [ -f "$STAGE/bin/healthcheck" ]; then
    php -l "$STAGE/bin/healthcheck" >/dev/null || { echo "Syntaxfehler in bin/healthcheck" >&2; exit 1; }
fi
# Shell-Syntax
for f in "$STAGE"/*.sh "$STAGE"/bin/*.sh "$STAGE"/cron/cron.01min "$STAGE"/daemon/daemon "$STAGE"/uninstall/uninstall; do
    bash -n "$f" || { echo "Shell-Syntaxfehler in $f" >&2; exit 1; }
done

OUT="$PWD/reolink2lox-$VER.zip"
rm -f reolink2lox-*.zip      # alte Installer entfernen, nur der aktuelle bleibt
"$PY" - "$OUT" "$STAGE" <<'PYEOF'
import os, sys, zipfile
out, root = sys.argv[1], sys.argv[2]
names = ['plugin.cfg']
for d, dirs, files in os.walk(root):
    dirs.sort()
    for f in sorted(files):
        rel = os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/')
        if rel != 'plugin.cfg':
            names.append(rel)
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in names:
        data = open(os.path.join(root, rel), 'rb').read()
        if rel.endswith(('.sh', '.php', '.cfg', '.json', 'apt', 'apt12', 'daemon', 'uninstall', 'healthcheck')) or rel.startswith('cron/'):
            data = data.replace(b'\r\n', b'\n')       # Linux: nur LF
        info = zipfile.ZipInfo(rel, (2026, 1, 1, 0, 0, 0))
        exe = rel.endswith(('.sh', '.php')) or rel in ('daemon/daemon', 'uninstall/uninstall', 'bin/healthcheck') or rel.startswith('cron/')
        info.external_attr = (0o100755 if exe else 0o100644) << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, data)
PYEOF
rm -rf build
# Zusaetzlich ein ZIP ohne Version im Namen: https://github.com/ricten/<Repo>/releases/latest/download/reolink2lox.zip zeigt dann immer auf das neueste Release
cp -f "$OUT" "$PWD/reolink2lox.zip"
echo "$OUT"
echo "$PWD/reolink2lox.zip"
