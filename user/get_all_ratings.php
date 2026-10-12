<?php
// ============================================================
// get_all_ratings.php — Public API: daftar rating per-sewa
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=30');

require "../config/db.php";

$result = $conn->query("
    SELECT
        u.username,
        r.rating,
        r.review AS rating_review,
        r.updated_at AS rated_at,
        s.name AS scooter_name
    FROM ratings r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN scooters s ON s.scooter_id = r.scooter_id
    ORDER BY r.updated_at DESC
    LIMIT 200
");

$ratings = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Kembalikan mentah; frontend WAJIB escape.
        $raw = $row['rating_review'] ?? null;
        if ($raw !== null && $raw !== '') {
            $decoded = htmlspecialchars_decode($raw, ENT_QUOTES);
            if (strpos($decoded, '&amp;') !== false) {
                $decoded = htmlspecialchars_decode($decoded, ENT_QUOTES);
            }
            $raw = $decoded;
        }
        $ratings[] = [
            'username'      => $row['username'],
            'rating'        => (float)$row['rating'],
            'rating_review' => ($raw !== null && $raw !== '') ? $raw : null,
            'rated_at'      => $row['rated_at'] ? date('d M Y', strtotime($row['rated_at'])) : null,
            'scooter_name'  => $row['scooter_name'] ?? null,
        ];
    }
}

echo json_encode([
    'success' => true,
    'ratings' => $ratings,
    'total'   => count($ratings),
]);
