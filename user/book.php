<?php
// ========================================================
// KONFIGURASI ERROR & LOGGING
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// ========================================================
// KEAMANAN SESI
// ========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

// ========================================================
// CEK AUTENTIKASI & TIMEOUT
// ========================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: index.php?error=unauthorized");
    exit;
}

$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// ========================================================
// KONEKSI DATABASE
// ========================================================
require "../config/db.php";

// Tolak booking dari akun yang diblokir
try {
    $blk = $conn->prepare("SELECT is_blocked FROM users WHERE user_id = ?");
    $blk->bind_param("i", $_SESSION['user_id']);
    $blk->execute();
    $brow = $blk->get_result()->fetch_assoc();
    $blk->close();
    if ($brow && !empty($brow['is_blocked'])) {
        session_unset(); session_destroy();
        header("Location: index.php?blocked=1");
        exit;
    }
} catch (Throwable $e) {}

// ========================================================
// HANYA TERIMA POST
// ========================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

// ========================================================
// CSRF VALIDATION — wajib untuk semua booking
// ========================================================
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_post)) {
    error_log("CSRF mismatch booking: IP " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $_SESSION['booking_error'] = "Security token invalid. Please refresh the page and try again.";
    $fallback_id = isset($_POST['scooter_id']) ? (int)$_POST['scooter_id'] : 0;
    header("Location: detail.php?id={$fallback_id}");
    exit;
}

// ========================================================
// AMBIL INPUT
// ========================================================
$scooter_id      = isset($_POST['scooter_id'])  ? (int)$_POST['scooter_id']      : 0;
$start_date      = trim($_POST['start_date']    ?? '');
$end_date        = trim($_POST['end_date']      ?? '');
$nama            = trim($_POST['nama']          ?? '');
$total_from_form = isset($_POST['total_price']) ? (float)$_POST['total_price']   : 0;
$id_card_file    = $_FILES['id_card'] ?? null;

// ========================================================
// FUNGSI HITUNG HARI SEWA
// ========================================================
function calculateRentalDays(string $start_date, string $end_date): int
{
    $start = (new DateTime($start_date))->setTime(0, 0, 0);
    $end   = (new DateTime($end_date))->setTime(0, 0, 0);
    return $start->diff($end)->days + 1;
}

// ========================================================
// KONSTANTA UPLOAD ID CARD
// ========================================================
define('ID_CARD_UPLOAD_DIR', __DIR__ . '/../uploads/id_cards/');
define('ID_CARD_MAX_SIZE',   3 * 1024 * 1024); // 3 MB
define('ID_CARD_ALLOWED_MIME', ['image/jpeg','image/png','image/webp','application/pdf']);
define('ID_CARD_ALLOWED_EXT',  ['jpg','jpeg','png','webp','pdf']);

// ========================================================
// FUNGSI VALIDASI & SIMPAN ID CARD
// Mengembalikan path relatif file yang disimpan,
// atau melempar Exception jika gagal.
// ========================================================
function processIdCardUpload(array $file): string
{
    // 1. Cek upload error dari PHP
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'File terlalu besar (server limit).',
            UPLOAD_ERR_FORM_SIZE  => 'File terlalu besar.',
            UPLOAD_ERR_PARTIAL    => 'Upload tidak lengkap, coba lagi.',
            UPLOAD_ERR_NO_FILE    => 'Harap upload KTP / SIM.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak ditemukan.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file.',
        ];
        throw new Exception($map[$file['error']] ?? 'Upload gagal.');
    }

    // 2. Ukuran file
    if ($file['size'] > ID_CARD_MAX_SIZE) {
        throw new Exception('Ukuran file maksimal 3 MB.');
    }

    // 3. Ekstensi file
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ID_CARD_ALLOWED_EXT, true)) {
        throw new Exception('Format file tidak didukung. Gunakan JPG, PNG, WEBP, atau PDF.');
    }

    // 4. MIME type dari konten file (bukan dari header browser — itu bisa dipalsukan)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']);
    if (!in_array($mimeReal, ID_CARD_ALLOWED_MIME, true)) {
        throw new Exception('Tipe file tidak valid.');
    }

    // 5. Buat direktori upload kalau belum ada
    if (!is_dir(ID_CARD_UPLOAD_DIR)) {
        if (!mkdir(ID_CARD_UPLOAD_DIR, 0755, true)) {
            throw new Exception('Gagal membuat folder upload.');
        }
        // Blokir direct access ke folder ini (kompatibel Apache 2.2 + 2.4)
        file_put_contents(ID_CARD_UPLOAD_DIR . '.htaccess',
            "Options -Indexes\n<FilesMatch \"^.*$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n");
    }

    // 6. Generate nama file unik — TIDAK pakai nama asli user (bahaya)
    $filename = 'idcard_' . bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;
    $dest     = ID_CARD_UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception('Gagal menyimpan file identitas.');
    }

    return 'id_cards/' . $filename; // path relatif dari folder uploads/
}

// ========================================================
// $transactionStarted dideklarasikan DI LUAR try
// supaya blok catch selalu bisa mengaksesnya tanpa warning
// ========================================================
$transactionStarted = false;

try {
    // ── 0. Validasi & proses upload KTP/SIM ──────────────
    //    Dilakukan paling awal supaya error upload langsung
    //    kembali ke detail.php tanpa menyentuh DB sama sekali.
    if (empty($id_card_file) || $id_card_file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception("Harap upload KTP / SIM / tanda identitas untuk melanjutkan booking.");
    }
    $id_card_path = processIdCardUpload($id_card_file);

    // ── 1. Validasi scooter_id ────────────────────────────
    if ($scooter_id <= 0) {
        throw new Exception("Invalid scooter selection.");
    }

    // ── 2. Tanggal tidak boleh kosong ────────────────────
    if (empty($start_date) || empty($end_date)) {
        throw new Exception("Please fill in all date fields.");
    }

    // ── 3. Format tanggal YYYY-MM-DD ─────────────────────
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        throw new Exception("Invalid date format.");
    }

    // ── 4. Tanggal harus valid (bukan 31 Feb, dll) ───────
    $start_ts = strtotime($start_date);
    $end_ts   = strtotime($end_date);
    if ($start_ts === false || $end_ts === false) {
        throw new Exception("Invalid date values.");
    }

    // ── 5. Tidak boleh di masa lalu ──────────────────────
    if ($start_ts < strtotime(date('Y-m-d'))) {
        throw new Exception("Start date cannot be in the past.");
    }

    // ── 6. End >= Start ───────────────────────────────────
    if ($end_ts < $start_ts) {
        throw new Exception("End date must be after or equal to start date.");
    }

    // ── 7. Batas maksimal sewa (dari pengaturan, default 30) ──
    $max_rent_days = max(1, (int)getSetting('max_rent_days', '30'));
    $days_diff     = calculateRentalDays($start_date, $end_date);

    if ($days_diff > $max_rent_days) {
        throw new Exception("Maximum rental period is {$max_rent_days} days.");
    }

    // ── 8. Nama (fallback ke username session) ────────────
    if (empty($nama)) {
        $nama = $_SESSION['username'] ?? '';
        if (empty($nama)) {
            throw new Exception("Please enter your full name.");
        }
    }
    if (strlen($nama) > 100) {
        throw new Exception("Name is too long (maximum 100 characters).");
    }

    // ── 9. Mulai transaksi ────────────────────────────────
    // Tidak perlu SHOW ENGINES — jika db.php pakai InnoDB, transaksi pasti didukung.
    // Langsung begin_transaction(); jika gagal akan throw mysqli_sql_exception.
    $conn->begin_transaction();
    $transactionStarted = true;

    // ── 10. Cek & kunci baris scooter (FOR UPDATE) ────────
    $stmt_check = $conn->prepare(
        "SELECT scooter_id, name, price, status FROM scooters WHERE scooter_id = ? FOR UPDATE"
    );
    if (!$stmt_check) {
        throw new Exception("System error. Please try again later.");
    }
    $stmt_check->bind_param("i", $scooter_id);
    $stmt_check->execute();
    $scooter_data = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if (!$scooter_data) {
        throw new Exception("Scooter not found.");
    }

    // Tolak booking jika unit tidak available (rented / maintenance).
    // Hanya 'available' yang diizinkan (ENUM DB sudah STRICT).
    $st = strtolower(trim($scooter_data['status'] ?? ''));
    if ($st !== 'available') {
        throw new Exception("Scooter is currently '" . $scooter_data['status'] . "' and cannot be booked. Please choose another unit.");
    }

    $price = (float)$scooter_data['price'];

    // ── 11. Cek bentrokan tanggal (FOR UPDATE) ────────────
    // Satu kondisi tumpang tindih sudah cukup:
    // overlap terjadi jika start_A <= end_B DAN end_A >= start_B
    $stmt_overlap = $conn->prepare("
        SELECT COUNT(*) AS overlap_count
        FROM rentals
        WHERE scooter_id = ?
          AND status IN ('pending', 'active')
          AND start_date <= ?
          AND end_date   >= ?
        FOR UPDATE
    ");
    if (!$stmt_overlap) {
        throw new Exception("System error. Please try again later.");
    }
    $stmt_overlap->bind_param("iss", $scooter_id, $end_date, $start_date);
    $stmt_overlap->execute();
    $overlap_count = (int)$stmt_overlap->get_result()->fetch_assoc()['overlap_count'];
    $stmt_overlap->close();

    if ($overlap_count > 0) {
        throw new Exception("Scooter is already booked for the selected dates. Please choose different dates.");
    }

    // ── 12. Hitung & validasi total harga ─────────────────
    $calculated_total = $price * $days_diff;

    // Deteksi tampering: toleransi Rp 1 untuk floating-point
    if ($total_from_form > 0 && abs($calculated_total - $total_from_form) > 1) {
        error_log(sprintf(
            "Price tampering: user_id=%d, scooter_id=%d, form=%.2f, calc=%.2f, days=%d",
            $_SESSION['user_id'], $scooter_id, $total_from_form, $calculated_total, $days_diff
        ));
        throw new Exception("Invalid booking request. Please try again.");
    }

    // ── 13. Simpan booking ────────────────────────────────
    $user_id = (int)$_SESSION['user_id'];
    $status  = 'pending';

    $stmt_insert = $conn->prepare("
        INSERT INTO rentals (scooter_id, user_id, start_date, end_date, total, status, id_card_path, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt_insert) {
        throw new Exception("Booking failed. Please try again later.");
    }
    $stmt_insert->bind_param("iissdss", $scooter_id, $user_id, $start_date, $end_date, $calculated_total, $status, $id_card_path);
    if (!$stmt_insert->execute()) {
        throw new Exception("Booking failed. Please try again later.");
    }
    $rental_id = $stmt_insert->insert_id;
    $stmt_insert->close();

    // ── 14. Commit ────────────────────────────────────────
    $conn->commit();
    $transactionStarted = false; // sudah selesai, tidak perlu rollback di catch

    error_log(sprintf(
        "Booking OK: rental_id=%d, user_id=%d, scooter_id=%d, %s s/d %s, days=%d, total=%.2f",
        $rental_id, $user_id, $scooter_id, $start_date, $end_date, $days_diff, $calculated_total
    ));

    $_SESSION['success'] = "Booking successful! Your rental has been submitted. Booking #{$rental_id}";
    header("Location: history.php");
    exit;

} catch (Exception $e) {

    // Rollback hanya jika transaksi benar-benar sudah dimulai
    if ($transactionStarted) {
        $conn->rollback();
        error_log("Rollback: " . $e->getMessage());
    }

    // Hapus file yang sudah terupload kalau booking gagal setelah upload berhasil
    if (!empty($id_card_path)) {
        $full = ID_CARD_UPLOAD_DIR . basename($id_card_path);
        if (file_exists($full)) @unlink($full);
    }

    error_log(sprintf(
        "Booking error [user_id=%d, scooter_id=%d, %s s/d %s, ip=%s]: %s",
        $_SESSION['user_id'] ?? 0,
        $scooter_id,
        $start_date,
        $end_date,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $e->getMessage()
    ));

    $_SESSION['booking_error'] = $e->getMessage();
    header("Location: detail.php?id={$scooter_id}");
    exit;
}