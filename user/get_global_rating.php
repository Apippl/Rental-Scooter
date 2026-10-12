<?php
// ============================================================
// get_global_rating.php — Public API: global average rating
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60'); // Cache for 60 seconds

require "../config/db.php";

$result = $conn->query(
    "SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS total FROM ratings"
);

if (!$result) {
    echo json_encode(['avg_rating' => 0, 'total' => 0]);
    exit;
}

$row = $result->fetch_assoc();
echo json_encode([
    'avg_rating' => (float)($row['avg_rating'] ?? 0),
    'total'      => (int)($row['total'] ?? 0),
]);