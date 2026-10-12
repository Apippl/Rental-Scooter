<?php
// ============================================================
// submit_rating.php — Rating PER-SEWA (tabel ratings) via AJAX
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ── Only accept POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require "../config/db.php";

// ── Parse JSON body ───────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

$rating = isset($input['rating']) ? (int)$input['rating'] : 0;
$review = isset($input['review']) ? trim($input['review']) : '';
$rental_id = isset($input['rental_id']) ? (int)$input['rental_id'] : 0;
$csrf   = $input['csrf'] ?? '';

// ── CSRF Validation ───────────────────────────────────────
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

// ── Rating value validation ───────────────────────────────
if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5.']);
    exit;
}

// ── Review text length (multibyte-safe) ───────────────────
if (mb_strlen($review, 'UTF-8') > 500) {
    echo json_encode(['success' => false, 'message' => 'Review text too long (max 500 chars).']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ── Rental harus milik user & sudah completed ─────────────
$chk = $conn->prepare("SELECT rental_id, scooter_id FROM rentals WHERE rental_id = ? AND user_id = ? AND status = 'completed'");
$chk->bind_param("ii", $rental_id, $user_id);
$chk->execute();
$rent = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$rent) {
    echo json_encode(['success' => false, 'message' => 'You can only rate your own completed rentals.']);
    exit;
}

// ── Simpan mentah (strip tags), escape saat OUTPUT ────────
$review_clean = trim(strip_tags($review));
$review_clean = preg_replace('/\s+/', ' ', $review_clean);
$scooter_id = (int)$rent['scooter_id'];

try {
    // Upsert per rental (1 rating per sewa, bisa diubah)
    $stmt = $conn->prepare(
        "INSERT INTO ratings (rental_id, user_id, scooter_id, rating, review)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review)"
    );
    $stmt->bind_param("iiiis", $rental_id, $user_id, $scooter_id, $rating, $review_clean);
    $stmt->execute();
    $stmt->close();

    // Mirror ke users.rating (kompatibilitas tampilan lama) = rating terbaru user
    $m = $conn->prepare(
        "UPDATE users u
         JOIN ratings rt ON rt.rating_id = (SELECT rating_id FROM ratings WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1)
         SET u.rating = rt.rating, u.rating_review = rt.review, u.rated_at = rt.updated_at
         WHERE u.user_id = ?"
    );
    $m->bind_param("ii", $user_id, $user_id);
    $m->execute();
    $m->close();

    // Global stats dari tabel ratings
    $globalResult = $conn->query(
        "SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM ratings"
    );
    $global = $globalResult ? $globalResult->fetch_assoc() : ['avg_rating' => 0, 'total' => 0];

    echo json_encode([
        'success'    => true,
        'message'    => 'Rating saved! Thank you for your feedback.',
        'avg_rating' => (float)($global['avg_rating'] ?? 0),
        'total'      => (int)($global['total'] ?? 0),
    ]);

} catch (Throwable $e) {
    error_log("submit_rating error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save rating. Please try again.']);
}
