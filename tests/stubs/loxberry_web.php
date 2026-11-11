<?php
// Attrappe von loxberry_web.php (nur fuer Tests). Gibt Kopf und Fuss als Kommentar aus; die Menueleiste ($navbar) wird als JSON sichtbar.
class LBWeb
{
    public static function lbheader($title = '', $helplink = '', $helptemplate = '', $nojqm = false): void
    {
        global $navbar;
        echo "<!--HEADER:$title-->\n<!--NOJQM:" . ($nojqm ? '1' : '0') . "-->\n<!--NAVBAR:" . json_encode($navbar ?? [], JSON_UNESCAPED_UNICODE) . "-->\n";
    }

    public static function lbfooter(): void
    {
        echo "<!--FOOTER-->\n";
    }

    public static function loglist_html(): string
    {
        return '';
    }
}
