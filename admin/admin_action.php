<?php
// ========================================================
// ERROR HANDLING & SESSION SETUP
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

// Auth: hanya admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../user/index.php");
    exit;
}

// Session timeout 30 menit (samakan dengan user)
$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: ../user/index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

require "../config/db.php";

// Hanya terima POST — aksi via GET ditolak agar token tidak bocor
// di log/history/Referer dan mencegah CSRF via <img>/link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method. Use the buttons on this page.";
    header("Location: admin.php");
    exit;
}

$action = $_POST['action'] ?? '';
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf   = $_POST['csrf'] ?? '';

// ── CSRF (timing-safe) ────────────────────────────────
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    $_SESSION['error'] = "Invalid security token! Please refresh the page and try again.";
    header("Location: admin.php");
    exit;
}

// ── Validasi aksi & ID ────────────────────────────────
$validActions = ['confirm', 'complete', 'cancel', 'settings'];

if ($id <= 0 && $action !== 'settings') {
    $_SESSION['error'] = "Invalid request.";
    header("Location: admin.php");
    exit;
}
if (!in_array($action, $validActions, true)) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: admin.php");
    exit;
}

// ── Pengaturan global (denda/hari, maks sewa) ─────────
if ($action === 'settings') {
    $late = max(0, (int)($_POST['late_fee_per_day'] ?? 0));
    $maxd = min(365, max(1, (int)($_POST['max_rent_days'] ?? 30)));
    try {
        $s1 = $conn->prepare("INSERT INTO app_settings (kunci, nilai) VALUES ('late_fee_per_day', ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)");
        $v1 = (string)$late; $s1->bind_param("s", $v1); $s1->execute(); $s1->close();
        $s2 = $conn->prepare("INSERT INTO app_settings (kunci, nilai) VALUES ('max_rent_days', ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)");
        $v2 = (string)$maxd; $s2->bind_param("s", $v2); $s2->execute(); $s2->close();
        admin_log("update settings: late_fee_per_day=$late, max_rent_days=$maxd");
        $_SESSION['success'] = "Settings saved.";
    } catch (Throwable $e) {
        error_log("Admin settings error: " . $e->getMessage());
        $_SESSION['error'] = "Failed to save settings.";
    }
    header("Location: admin.php");
    exit;
}

// ── Ambil data rental (kunci baris) ───────────────────
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT * FROM rentals WHERE rental_id = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception("Rental not found!");
    }
    $rental = $res->fetch_assoc();
    $stmt->close();

    // ── State validation ──────────────────────────────
    $allowedTransitions = [
        'confirm'  => ['pending'],
        'complete' => ['active'],
        'cancel'   => ['pending', 'active'],
    ];
    if (!in_array($rental['status'], $allowedTransitions[$action], true)) {
        throw new Exception("Cannot '$action' rental with status '{$rental['status']}'.");
    }

    // Helper: unit tetap 'rented' jika masih ada active lain (berbasis tanggal, bukan flag buta)
    $hasOtherActive = function () use ($conn, $rental, $id): bool {
        $c = $conn->prepare("SELECT COUNT(*) AS n FROM rentals WHERE scooter_id = ? AND status = 'active' AND rental_id <> ?");
        $c->bind_param("ii", $rental['scooter_id'], $id);
        $c->execute();
        $n = (int)$c->get_result()->fetch_assoc()['n'];
        $c->close();
        return $n > 0;
    };

    switch ($action) {
        case 'confirm':
            // Cek ulang bentrok tanggal terhadap active lain (anti double-confirm)
            $ov = $conn->prepare("
                SELECT COUNT(*) AS n FROM rentals
                WHERE scooter_id = ? AND rental_id <> ?
                  AND status = 'active'
                  AND start_date <= ? AND end_date >= ?
            ");
            $ov->bind_param("iiss", $rental['scooter_id'], $id, $rental['end_date'], $rental['start_date']);
            $ov->execute();
            $n = (int)$ov->get_result()->fetch_assoc()['n'];
            $ov->close();
            if ($n > 0) {
                throw new Exception("Cannot confirm: dates overlap with another active rental.");
            }
            $u = $conn->prepare("UPDATE rentals SET status = 'active' WHERE rental_id = ?");
            $u->bind_param("i", $id);
            $u->execute(); $u->close();

            $u2 = $conn->prepare("UPDATE scooters SET status = 'rented' WHERE scooter_id = ?");
            $u2->bind_param("i", $rental['scooter_id']);
            $u2->execute(); $u2->close();

            admin_log("confirm rental #$id (scooter {$rental['scooter_id']})");
            $_SESSION['success'] = "Rental successfully confirmed!";
            break;

        case 'complete': {
            // Denda otomatis: hari lewat batas x tarif pengaturan
            $feePerDay = max(0, (int)getSetting('late_fee_per_day', '50000'));
            $today = new DateTime('today');
            $end = new DateTime(substr($rental['end_date'], 0, 10));
            $lateDays = $today > $end ? (int)$today->diff($end)->days : 0;
            $lateFee = $lateDays * $feePerDay;

            // Catatan + foto pengembalian (opsional, dari form complete)
            $returnNote = mb_substr(trim($_POST['return_note'] ?? ''), 0, 1000);
            $returnPhoto = null;
            if (!empty($_FILES['return_photo']['name']) && $_FILES['return_photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['return_photo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed, true)) throw new Exception("Invalid return photo type (JPG/PNG/WEBP).");
                if ($_FILES['return_photo']['size'] > 5 * 1024 * 1024) throw new Exception("Return photo max 5MB.");
                $fi = new finfo(FILEINFO_MIME_TYPE);
                if (!in_array($fi->file($_FILES['return_photo']['tmp_name']), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    throw new Exception("Invalid return photo content.");
                }
                if (!is_dir(__DIR__ . '/../uploads/returns')) {
                    mkdir(__DIR__ . '/../uploads/returns', 0755, true);
                }
                // Pastikan folder returns tidak bisa diakses langsung (wajib ada .htaccess)
                $retHt = __DIR__ . '/../uploads/returns/.htaccess';
                if (!is_file($retHt)) {
                    @file_put_contents($retHt, "Options -Indexes\n<FilesMatch \"^.*$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n");
                }
                $returnPhoto = 'returns/' . 'ret_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($_FILES['return_photo']['tmp_name'], __DIR__ . '/../uploads/' . $returnPhoto)) {
                    throw new Exception("Failed to save return photo.");
                }
            }

            $u = $conn->prepare("UPDATE rentals SET status = 'completed', late_days = ?, late_fee = ?, returned_at = NOW(), return_note = ?, return_photo = ? WHERE rental_id = ?");
            $u->bind_param("iisssi", $lateDays, $lateFee, $returnNote, $returnPhoto, $id);
            $u->execute(); $u->close();

            // Tandai available hanya jika tidak ada active lain (date-aware)
            if (!$hasOtherActive()) {
                $u2 = $conn->prepare("UPDATE scooters SET status = 'available' WHERE scooter_id = ?");
                $u2->bind_param("i", $rental['scooter_id']);
                $u2->execute(); $u2->close();
            }

            admin_log("complete rental #$id (late {$lateDays}d, fee {$lateFee})");
            $_SESSION['success'] = $lateDays > 0
                ? "Rental completed with late fee: {$lateDays} day(s) × Rp " . number_format($feePerDay, 0, ',', '.') . " = Rp " . number_format($lateFee, 0, ',', '.')
                : "Rental successfully completed!";
            break;
        }

        case 'cancel':
            $u = $conn->prepare("UPDATE rentals SET status = 'cancelled' WHERE rental_id = ?");
            $u->bind_param("i", $id);
            $u->execute(); $u->close();

            if (!$hasOtherActive()) {
                $u2 = $conn->prepare("UPDATE scooters SET status = 'available' WHERE scooter_id = ?");
                $u2->bind_param("i", $rental['scooter_id']);
                $u2->execute(); $u2->close();
            }

            admin_log("cancel rental #$id (was {$rental['status']})");
            $_SESSION['success'] = ($rental['status'] === 'active')
                ? "Ongoing rental cancelled. Scooter availability updated."
                : "Rental successfully cancelled!";
            break;
    }

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    // Bersihkan file return yang sempat terupload jika transaksi gagal
    if (!empty($returnPhoto)) {
        $tmp = __DIR__ . '/../uploads/' . ltrim(str_replace(chr(0), '', $returnPhoto), '/');
        if (is_file($tmp)) @unlink($tmp);
    }
    error_log("Admin Action Error (action=$action, id=$id): " . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
}

header("Location: admin.php");
exit;
