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
// CEK AUTENTIKASI & TIMEOUT (30 MENIT)
// ========================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: ../admin/admin.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

// Pastikan CSRF token untuk submit_rating.php selalu ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

$user_id = $_SESSION['user_id'];
$username_display = htmlspecialchars($_SESSION['username'] ?? 'Rider');

// ── Ambil rating PER-SEWA milik user ──
$my_ratings = [];
$mr_stmt = $conn->prepare("SELECT rental_id, rating, review FROM ratings WHERE user_id = ?");
if ($mr_stmt) {
    $mr_stmt->bind_param("i", $user_id);
    $mr_stmt->execute();
    $mr_res = $mr_stmt->get_result();
    while ($mr_row = $mr_res->fetch_assoc()) $my_ratings[(int)$mr_row['rental_id']] = $mr_row;
    $mr_stmt->close();
}

// ========================================================
// AMBIL DATA RENTALS USER DENGAN PREPARED STATEMENT
// ========================================================
$stmt = $conn->prepare("
    SELECT
        r.rental_id,
        r.start_date,
        r.end_date,
        r.total,
        r.late_days,
        r.late_fee,
        r.return_note,
        r.status,
        r.created_at,
        s.scooter_id,
        s.name AS scooter_name,
        s.image,
        s.brand,
        s.type
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");

if (!$stmt) {
    error_log("Prepare failed (rentals): " . $conn->error);
    $rentals = [];
} else {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rentals = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ========================================================
// FUNGSI BANTU UNTUK STATUS BADGE
// ========================================================
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'pending':   return 'status-pending';
        case 'confirmed': return 'status-confirmed';
        case 'active':    return 'status-active';
        case 'completed': return 'status-completed';
        case 'cancelled': return 'status-cancelled';
        default:          return 'status-unknown';
    }
}

function getStatusText($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'pending':   return 'Pending';
        case 'confirmed': return 'Confirmed';
        case 'active':    return 'Active';
        case 'completed': return 'Completed';
        case 'cancelled': return 'Cancelled';
        default:          return ucfirst($status);
    }
}

// Default image
$defaultImage = "https://images.unsplash.com/photo-1566279604926-163b3a6795da?w=200&h=150&fit=crop&crop=center";
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Rentals — Scooter Rental</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../css/rentals.css">
</head>
<body>

<main class="container">
  <div class="page-header">
    <a href="dashboard.php" class="back-link" style="text-decoration: none; display: inline-block;">
        <h1 style="cursor: pointer; transition: opacity 0.2s;">MY <span>RENTALS</span></h1>
    </a>
    <p>View your rental history and booking status</p>
</div>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($rentals)): ?>
    <div class="rental-list">
      <?php foreach ($rentals as $index => $row): 
        $statusClass = getStatusBadgeClass($row['status']);
        $statusText = getStatusText($row['status']);
        
        $startDate = date('d M Y', strtotime($row['start_date']));
        $endDate = date('d M Y', strtotime($row['end_date']));
        $createdDate = date('d M Y', strtotime($row['created_at']));
        
        // Hitung durasi & harga/hari dari start/end/total (kolom duration/price_per_day tidak ada di DB)
        $start = new DateTime($row['start_date']);
        $end = new DateTime($row['end_date']);
        $duration = max(1, $start->diff($end)->days + 1);
        $pricePerDay = $duration > 0 ? ((float)$row['total'] / $duration) : (float)$row['total'];
        
        $imageFile = basename($row['image'] ?? '');
        $imagePath = $imageFile ? "../uploads/$imageFile" : '';
        $imgSrc = ($imagePath && file_exists($imagePath)) ? $imagePath : $defaultImage;
        
        $isElectric = ($row['type'] ?? '') === 'electric';
      ?>
        <div class="rental-card" style="animation-delay: <?= $index * 0.07 ?>s">
          <div class="rental-header">
            <span class="rental-id">
              <i class="fas fa-receipt"></i> Booking #<?= $row['rental_id'] ?>
            </span>
            <span class="rental-status <?= $statusClass ?>">
              <?= $statusText ?>
            </span>
          </div>
          
          <div class="rental-content">
            <div class="rental-image">
              <img src="<?= htmlspecialchars($imgSrc) ?>" 
                   alt="<?= htmlspecialchars($row['scooter_name']) ?>"
                   onerror="this.src='<?= $defaultImage ?>'">
            </div>
            
            <div class="rental-details">
              <h3><?= htmlspecialchars($row['scooter_name']) ?></h3>
              
              <div class="detail-item">
                <i class="fas fa-tag"></i>
                <span><?= htmlspecialchars($row['brand']) ?> • <?= $isElectric ? 'Electric' : 'Gasoline' ?></span>
              </div>
              
              <div class="detail-item">
                <i class="fas fa-calendar-alt"></i>
                <span><strong><?= $startDate ?></strong> → <strong><?= $endDate ?></strong></span>
              </div>
              
              <div class="detail-item">
                <i class="fas fa-clock"></i>
                <span>Duration: <strong><?= $duration ?> days</strong></span>
              </div>
              
              <div class="price-breakdown">
                <div class="price-breakdown-item">
                  <span>Price per day (at time of booking):</span>
                  <span>Rp <?= number_format($pricePerDay, 0, ',', '.') ?></span>
                </div>
                <div class="price-breakdown-item">
                  <span>× <?= $duration ?> days:</span>
                  <span><strong>Rp <?= number_format($row['total'], 0, ',', '.') ?></strong></span>
                </div>
                <?php if (!empty($row['late_fee']) && (float)$row['late_fee'] > 0): ?>
                <div class="price-breakdown-item" style="color:#B91C1C;">
                  <span><i class="fas fa-exclamation-triangle"></i> Late <?= (int)$row['late_days'] ?> day(s):</span>
                  <span><strong>Rp <?= number_format($row['late_fee'], 0, ',', '.') ?></strong></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($row['return_note'])): ?>
                <div class="price-breakdown-item">
                  <span>Return note:</span>
                  <span><em>"<?= htmlspecialchars(mb_substr($row['return_note'], 0, 80)) ?>"</em></span>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          
          <div class="rental-footer">
            <div class="rental-price">
              Rp <?= number_format($row['total'], 0, ',', '.') ?>
              <span>total</span>
            </div>
            <div class="rental-date">
              <i class="fas fa-calendar-plus"></i> Booked on <?= $createdDate ?>
            </div>
          </div>

          <?php if ($row['status'] === 'completed'):
            $rid = (int)$row['rental_id'];
            $myRate = $my_ratings[$rid] ?? null;
            $curStar = (int)($myRate['rating'] ?? 0);
            $curReview = $myRate['review'] ?? '';
          ?>
          <div class="rating-form-section" id="rating-section-<?= $rid ?>">
            <div class="rating-form-title">
              <i class="fas fa-star"></i>
              <?= $curStar > 0 ? 'Rating Sewa Ini' : 'Beri Rating Sewa Ini' ?>
            </div>

            <?php if ($curStar > 0): ?>
              <div class="rating-already-done">
                <i class="fas fa-check-circle"></i>
                Rating sewa ini: <?= str_repeat('⭐', $curStar) ?> (<?= $curStar ?>/5)
              </div>
              <?php if ($curReview !== '' && $curReview !== null): ?>
                <div style="font-size:.85rem;color:#999;font-family:'DM Sans',sans-serif;margin-bottom:10px;font-style:italic;">
                  "<?= htmlspecialchars($curReview) ?>"
                </div>
              <?php endif; ?>
              <p style="font-size:.82rem;color:#666;font-family:'DM Sans',sans-serif;margin-bottom:10px;">
                Kamu bisa mengubah rating sewa ini kapan saja:
              </p>
            <?php endif; ?>

            <form id="rating-form-<?= $rid ?>" onsubmit="return false;">
              <div class="star-picker">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                  <input type="radio" name="star"
                         id="star-<?= $rid ?>-<?= $s ?>"
                         value="<?= $s ?>"
                         <?= ($curStar == $s) ? 'checked' : '' ?>>
                  <label for="star-<?= $rid ?>-<?= $s ?>">
                    <i class="fas fa-star"></i>
                  </label>
                <?php endfor; ?>
              </div>
              <textarea class="rating-review-input"
                        placeholder="Cerita pengalamanmu (opsional, maks. 500 karakter)"
                        maxlength="500"><?= htmlspecialchars($curReview ?? '') ?></textarea>
              <button type="button" class="rating-submit-btn"
                      onclick="submitRating(<?= $rid ?>)">
                <i class="fas fa-paper-plane"></i>
                <?= $curStar > 0 ? 'Update Rating' : 'Kirim Rating' ?>
              </button>
            </form>
          </div>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>
    </div>
    
    <div class="historical-note">
      <i class="fas fa-database"></i> 
      Prices shown reflect the rate at the time of booking
    </div>
    
  <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">
        <i class="fas fa-history"></i>
      </div>
      <h3>No Rental History Yet</h3>
      <p>You haven't rented any scooters yet. Start your first ride now!</p>
      <a href="dashboard.php" class="btn-primary">
        <i class="fas fa-scooter"></i>
        <span>Browse Scooters</span>
      </a>
    </div>
  <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php require_once "footer.php"; ?>
</body>
</html>