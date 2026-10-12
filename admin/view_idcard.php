<?php
// view_idcard.php — Proxy aman untuk dokumen identitas (KTP/SIM)
// Admin saja. Menghindari direct access ke uploads/id_cards/ (yang di-block .htaccess).
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    http_response_code(401);
    exit('Session expired');
}
$_SESSION['LAST_ACTIVITY'] = time();

require "../config/db.php";

$rental_id = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;
if ($rental_id <= 0) {
    http_response_code(400);
    exit('Invalid rental id');
}

$stmt = $conn->prepare("SELECT id_card_path FROM rentals WHERE rental_id = ?");
if (!$stmt) {
    http_response_code(500);
    exit('DB error');
}
$stmt->bind_param("i", $rental_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['id_card_path'])) {
    http_response_code(404);
    exit('No identity document');
}

// id_card_path disimpan sebagai 'id_cards/namafile.ext' (relatif ke uploads/)
$rel = $row['id_card_path'];
// Tolak path traversal: hanya basename + subdir id_cards/ yang diizinkan
$rel = str_replace(chr(0), '', $rel);
if (strpos($rel, '..') !== false || strpos($rel, '/') === 0 || strpos($rel, '\\') !== false) {
    http_response_code(400);
    exit('Invalid path');
}
$allowed_prefix = 'id_cards/';
if (strpos($rel, $allowed_prefix) !== 0) {
    http_response_code(400);
    exit('Invalid path');
}
$filename = basename($rel);
$full = realpath(__DIR__ . '/../uploads/' . $rel);
// Pastikan file benar-benar di dalam uploads/id_cards/
$base = realpath(__DIR__ . '/../uploads/id_cards');
if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('File not found');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($full);
$allowed_mime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
if (!isset($allowed_mime[$mime])) {
    http_response_code(415);
    exit('Unsupported file type');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
    http_response_code(415);
    exit('Unsupported extension');
}

// Sajikan inline (untuk <img>/<iframe> via blob), tanpa cache, tanpa attachment
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
header('Content-Disposition: inline; filename="identity-' . $rental_id . '.' . $ext . '"');
readfile($full);
exit;
