<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../user/index.php");
    exit;
}

$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: ../user/index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

require "../config/db.php";

// Hanya terima POST — tolak GET agar tidak bisa dihapus via link/<img>.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: admin_manage.php");
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf = $_POST['csrf'] ?? '';

// Validasi CSRF timing-safe
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    $_SESSION['error'] = "Invalid security token!";
    header("Location: admin_manage.php");
    exit;
}

if ($id <= 0) {
    $_SESSION['error'] = "Invalid scooter ID!";
    header("Location: admin_manage.php");
    exit;
}

try {
    // Ambil nama file gambar dulu sebelum hapus
    $getImage = $conn->prepare("SELECT image FROM scooters WHERE scooter_id = ?");
    $getImage->bind_param("i", $id);
    $getImage->execute();
    $imageData = $getImage->get_result()->fetch_assoc();
    $getImage->close();

    if (!$imageData) {
        $_SESSION['error'] = "Scooter not found!";
        header("Location: admin_manage.php");
        exit;
    }

    // Cek jika scooter sedang ada rental active atau pending
    $check = $conn->prepare("SELECT COUNT(*) as count FROM rentals WHERE scooter_id = ? AND status IN ('active', 'pending')");
    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $check->close();

    if ($result['count'] > 0) {
        $_SESSION['error'] = "Cannot delete scooter with active or pending rentals!";
        header("Location: admin_manage.php");
        exit;
    }

    // JANGAN hapus rental history (completed/cancelled) — pertahankan audit trail.
    // Tolak hapus jika masih ada history apapun agar data user tidak hilang.
    $checkHist = $conn->prepare("SELECT COUNT(*) as cnt FROM rentals WHERE scooter_id = ?");
    $checkHist->bind_param("i", $id);
    $checkHist->execute();
    $histCount = (int)$checkHist->get_result()->fetch_assoc()['cnt'];
    $checkHist->close();

    if ($histCount > 0) {
        $_SESSION['error'] = "Cannot delete scooter with rental history ($histCount records). Set status to 'maintenance' instead to hide it.";
        header("Location: admin_manage.php");
        exit;
    }

    // Aman: tidak ada rental sama sekali — hapus scooter saja
    $deleteScooter = $conn->prepare("DELETE FROM scooters WHERE scooter_id = ?");
    $deleteScooter->bind_param("i", $id);
    $deleteScooter->execute();
    $affected = $deleteScooter->affected_rows;
    $deleteScooter->close();

    if ($affected <= 0) {
        throw new Exception("Scooter not found or already deleted");
    }

    // Hapus file gambar setelah DB berhasil (basename agar aman dari traversal)
    if ($imageData && !empty($imageData['image'])) {
        $safe = basename($imageData['image']);
        $imagePath = __DIR__ . '/../uploads/' . $safe;
        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }

    admin_log("delete scooter #$id");
    $_SESSION['success'] = "Scooter deleted successfully!";

} catch (Exception $e) {
    error_log("Admin Delete Error (id=$id): " . $e->getMessage());
    $_SESSION['error'] = "Failed to delete scooter!";
}

header("Location: admin_manage.php");
exit;
