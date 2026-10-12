<?php
// logout.php — Hancurkan sesi dan kembali ke login.
// Catatan: logout via GET rentan CSRF logout (risiko rendah).
// SameSite=Lax sudah memblokir logout via <img>/fetch cross-site.
// Untuk proteksi penuh, gunakan POST + CSRF di versi berikutnya.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);
session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
}
session_unset();
session_destroy();
header("Location: index.php");
exit;
