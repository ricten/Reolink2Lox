<?php
// Rendert einen Reiter der Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken (nur fuer Tests): php ui_page.php <reiter>
// Umgebung: STUB_INDEX (index.php), STUB_BIN, STUB_CONFIG, STUB_LOG, STUB_SYSCONFIG, STUB_UDP, RLK_DEV_DIR; STUB_POST_AKTION = Aktion eines Formulars (POST).
$_GET['tab'] = $argv[1] ?? 'kameras';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_ADDR'] = '192.0.2.25';
if (getenv('STUB_POST_AKTION')) {
    session_id('stubsitzung');
    session_start();
    $_SESSION['rlk_token'] = 'stubtoken';
    session_write_close();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['token' => 'stubtoken', 'aktion' => getenv('STUB_POST_AKTION')];
}
include (string)getenv('STUB_INDEX');
