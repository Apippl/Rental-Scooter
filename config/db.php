<?php
// config/db.php — Koneksi DB + hardening global
// Dukung .env / environment variable untuk produksi (jatuh ke default XAMPP lokal).
// Buat file .env di folder config/ (jangan commit) dengan isi:
// DB_HOST=localhost
// DB_USER=root
// DB_PASS=
// DB_NAME=rental_scooter
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            $pos = strpos($ln, '=');
            if ($pos === false) continue;
            $k = trim(substr($ln, 0, $pos));
            $v = trim(substr($ln, $pos + 1), " \t\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
}

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'rental_scooter';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db);
} catch (mysqli_sql_exception $e) {
    // Jangan bocorkan detail DB ke user; catat ke log saja
    error_log("DB Connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Koneksi Database Gagal!");
}

$conn->set_charset("utf8mb4");
date_default_timezone_set('Asia/Jakarta');

// Security headers global (hanya jika header belum dikirim & bukan API JSON murni).
// Membantu mitigasi clickjacking, MIME-sniffing, dan info-leak PHP.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Hapus signature PHP jika expose_php masih 1 di php.ini
    header_remove('X-Powered-By');
}

// Biarkan error_reporting diatur oleh file yang memanggil (2-register.php)

// ---------- Helper global (butuh $conn) ----------
if (!function_exists('getSetting')) {
    function getSetting(string $key, string $default = ''): string {
        global $conn;
        try {
            $s = $conn->prepare("SELECT nilai FROM app_settings WHERE kunci = ?");
            $s->bind_param("s", $key);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            return ($row && isset($row['nilai'])) ? (string)$row['nilai'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('admin_log')) {
    function admin_log(string $action): void {
        global $conn;
        try {
            $admin_id = (int)($_SESSION['user_id'] ?? 0);
            if ($admin_id <= 0) return;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $s = $conn->prepare("INSERT INTO admin_logs (admin_id, action, ip_address) VALUES (?, ?, ?)");
            $s->bind_param("iss", $admin_id, $action, $ip);
            $s->execute();
            $s->close();
        } catch (Throwable $e) {
            error_log("admin_log failed: " . $e->getMessage());
        }
    }
}
?>