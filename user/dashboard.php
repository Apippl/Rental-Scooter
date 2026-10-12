<?php
// ERROR HANDLING // 
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

//  SESSION // 
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
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

require "../config/db.php";

// Paksa logout jika akun diblokir admin saat sesi masih aktif
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

$search = trim($_GET['search'] ?? '');
$search = mb_substr($search, 0, 100);

$sql    = "SELECT * FROM scooters";
$params = [];
$types  = "";

if ($search !== '') {
    // Escape wildcard LIKE agar % dan _ dicari literal, tambahkan ESCAPE '\\'
    $search_esc = addcslashes($search, '%_\\');
    $sql     .= " WHERE (name LIKE ? ESCAPE '\\\\' OR brand LIKE ? ESCAPE '\\\\')";
    $params[] = "%$search_esc%";
    $params[] = "%$search_esc%";
    $types    = "ss";
}
$sql .= " ORDER BY created_at DESC";

$stmt   = $conn->prepare($sql);
$result = null;
if ($stmt) {
    if ($params) $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
    } else {
        error_log("Execute error: " . $stmt->error);
    }
} else {
    error_log("Prepare error: " . $conn->error);
}

$username_display = htmlspecialchars($_SESSION['username'] ?? 'Rider');
$user_initial     = strtoupper(substr($_SESSION['username'] ?? 'R', 0, 1));

// ========================================================
// INFO AKUN untuk dropdown (email, member sejak, statistik)
// ========================================================
$acc_email = '';
$acc_since = '';
$acc_total = 0;
$acc_active = 0;
$acc_rating = 0;
try {
    $acc_uid = (int)($_SESSION['user_id'] ?? 0);
    $aq = $conn->prepare("SELECT email, created_at FROM users WHERE user_id = ?");
    if ($aq) {
        $aq->bind_param("i", $acc_uid);
        $aq->execute();
        if ($arow = $aq->get_result()->fetch_assoc()) {
            $acc_email = $arow['email'] ?? '';
            $acc_since = $arow['created_at'] ? date('M Y', strtotime($arow['created_at'])) : '';
        }
        $aq->close();
    }
    $sq = $conn->prepare("SELECT COUNT(*) AS t, SUM(status = 'active') AS a FROM rentals WHERE user_id = ?");
    if ($sq) {
        $sq->bind_param("i", $acc_uid);
        $sq->execute();
        if ($srow = $sq->get_result()->fetch_assoc()) {
            $acc_total = (int)($srow['t'] ?? 0);
            $acc_active = (int)($srow['a'] ?? 0);
        }
        $sq->close();
    }
    $gq = $conn->prepare("SELECT ROUND(AVG(rating),1) AS av FROM ratings WHERE user_id = ?");
    if ($gq) {
        $gq->bind_param("i", $acc_uid);
        $gq->execute();
        $acc_rating = (float)($gq->get_result()->fetch_assoc()['av'] ?? 0);
        $gq->close();
    }
} catch (Throwable $e) { error_log("Account info error: " . $e->getMessage()); }
$acc_email_display = htmlspecialchars($acc_email);

// ========================================================
// PERINGATAN RENTAL — overdue & ending soon untuk user ini
// ========================================================
$uid = (int)$_SESSION['user_id'];

// 1. Rental yang sudah LEWAT end_date tapi status masih 'active' (terlambat)
$overdue_rentals = [];
$r_ov = $conn->prepare("
    SELECT r.rental_id, r.end_date, s.name AS scooter_name,
           DATEDIFF(CURDATE(), r.end_date) AS days_late
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    WHERE r.user_id = ? AND r.status = 'active' AND r.end_date < CURDATE()
    ORDER BY r.end_date ASC
");
if ($r_ov) {
    $r_ov->bind_param("i", $uid);
    $r_ov->execute();
    $res_ov = $r_ov->get_result();
    while ($row = $res_ov->fetch_assoc()) $overdue_rentals[] = $row;
    $r_ov->close();
}

// 2. Rental yang BERAKHIR HARI INI atau dalam 2 hari ke depan (hampir habis)
$ending_soon = [];
$r_es = $conn->prepare("
    SELECT r.rental_id, r.end_date, s.name AS scooter_name,
           DATEDIFF(r.end_date, CURDATE()) AS days_left
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    WHERE r.user_id = ? AND r.status = 'active'
      AND r.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.end_date ASC
");
if ($r_es) {
    $r_es->bind_param("i", $uid);
    $r_es->execute();
    $res_es = $r_es->get_result();
    while ($row = $res_es->fetch_assoc()) $ending_soon[] = $row;
    $r_es->close();
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Dashboard – Scooter Rental</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../css/dashboard.css?v=20261007e">
  <style>
    /* ── RENTAL ALERT BANNERS ── */
    .rental-alerts { padding: 0 0 4px; }

    .alert-banner {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      padding: 14px 20px;
      margin: 10px 20px;
      box-shadow: none;
      border-radius: 10px;
      border-left: 4px solid;
      font-family: 'DM Sans', sans-serif;
      font-size: .88rem;
      animation: alertSlideIn .4s ease both;
    }
    @keyframes alertSlideIn {
      from { opacity: 0; transform: translateY(-8px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* OVERDUE — merah */
    .alert-overdue {
      background: rgba(220, 38, 38, .1);
      border-color: #dc2626;
      color: #fecaca;
    }
    .alert-overdue .alert-icon { 
      color: #f87171; 
      font-size: 1.3rem; 
      margin-top: 1px; 
    }
    .alert-overdue strong { 
      color: #fca5a5; 
    }
    .alert-soon {
      background: rgba(255, 107, 0, .1);
      color: #000000;
      border-left: 4px solid #aaa;
    }
    .alert-soon .alert-icon { 
      color: #FF6B00; 
      font-size: 1.3rem;
       margin-top: 1px; 
      }
    .alert-soon strong { 
      color: #000000; 
    }

    .alert-body { 
      flex: 1; 
      line-height: 1.6; 
    }
    .alert-body p { 
      margin: 0 0 2px; 
    }
    .alert-body small { 
      opacity: .75; 
      font-size: .8rem; 
    }

    .alert-close {
      background: none; 
      border: none; 
      cursor: pointer;
      color: inherit; 
      opacity: .5; 
      font-size: 1rem;
      padding: 0 2px; 
      align-self: flex-start;
      transition: opacity .2s;
    }
    .alert-close:hover {
      opacity: 1;
    }

    /* ── HERO RATING TRUST BADGE ── */
    .hero-rating-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      padding: 8px 16px;
      background: rgba(255, 107, 0, .07);
      border: 1px solid rgba(255, 107, 0, .28);
      border-radius: 40px;
      font-family: 'DM Sans', sans-serif;
      font-size: .85rem;
      color: #333;
      cursor: pointer;
      transition: background .2s, transform .15s, box-shadow .2s;
    }
    button.hero-rating-badge:hover {
      background: rgba(255, 107, 0, .14);
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(255, 107, 0, .18);
    }
    button.hero-rating-badge:active { transform: translateY(0); }
    .hero-rating-badge:focus-visible { outline: 2px solid #FF6B00; outline-offset: 2px; }
    .hero-rating-stars { display: inline-flex; gap: 2px; color: #FFB800; font-size: .85rem; }
    .hero-rating-badge strong { color: #111; font-size: .95rem; }
    .hero-rating-count { color: #777; }
    .hero-rating-go { font-size: .7rem; color: #FF6B00; transition: transform .2s; }
    button.hero-rating-badge:hover .hero-rating-go { transform: translateX(3px); }
    .hero-rating-badge.is-empty { cursor: default; color: #999; }
    .hero-rating-badge.is-empty i { color: #ccc; }

    @media (max-width: 480px) {
      .alert-banner { margin: 8px 12px; padding: 12px 14px; font-size: .82rem; }
    }
  </style>
</head>
<body>

<div class="dropdown-overlay" id="dropdownOverlay"></div>

<!-- HEADER -->
<header class="header">
  <div class="header-content">

    <!-- Brand -->
    <a href="dashboard.php" class="header-brand">
      <div class="brand-wordmark">
        <strong>SCOOTER</strong>
        <small>RIDER</small>
      </div>
    </a>

    <!-- Search -->
    <div class="search-container">
      <form method="get" class="search-box">
        <input type="text" name="search"
               placeholder="Search scooters..."
               value="<?= htmlspecialchars($search) ?>"
               maxlength="100"
               autocomplete="off">
        <button type="submit" class="search-btn" aria-label="Search">
          <i class="fas fa-search"></i>
        </button>
      </form>
    </div>

    <!-- History + User Dropdown -->
    <div class="header-right">
      <a href="history.php" class="header-history" title="History">
        <i class="fas fa-history"></i>
        <span>History</span>
      </a>

      <!-- User dropdown -->
      <div class="user-dropdown">
        <div class="user-pill" id="userPillBtn"
             role="button" tabindex="0"
             aria-haspopup="true" aria-expanded="false" aria-label="User menu">
          <div class="user-avatar"><?= $user_initial ?></div>
          <div class="user-pill-info">
            <strong><?= $username_display ?></strong>
            <small>MEMBER</small>
          </div>
          <i class="fas fa-chevron-down pill-caret" aria-hidden="true"></i>
        </div>

        <!-- Mobile: avatar bulat -->
        <div class="mobile-avatar-only" id="mobileAvatarBtn"
             role="button" tabindex="0"
             aria-haspopup="true" aria-expanded="false" aria-label="User menu">
          <div class="user-avatar"><?= $user_initial ?></div>
        </div>

        <!-- Dropdown -->
        <div class="dropdown-menu" id="userDropdown" role="menu" aria-label="Akun saya">
          <span class="sheet-handle" aria-hidden="true"></span>

          <div class="dropdown-user-info">
            <div class="dropdown-user-top">
              <div class="dropdown-user-avatar"><?= $user_initial ?></div>
              <div class="dropdown-user-meta">
                <div class="dropdown-user-name"><?= $username_display ?></div>
                <?php if ($acc_email_display): ?>
                <div class="dropdown-user-email"><?= $acc_email_display ?></div>
                <?php endif; ?>
                <div class="dropdown-user-role">Member<?= $acc_since ? ' · sejak ' . htmlspecialchars($acc_since) : '' ?></div>
              </div>
            </div>
            <div class="dropdown-stats">
              <div class="dropdown-stat">
                <strong><?= number_format($acc_total) ?></strong>
                <span>Total sewa</span>
              </div>
              <div class="dropdown-stat">
                <strong><?= number_format($acc_active) ?></strong>
                <span>Aktif</span>
              </div>
              <div class="dropdown-stat">
                <strong><?= $acc_rating > 0 ? number_format($acc_rating, 1) . ' ★' : '—' ?></strong>
                <span>Ratingku</span>
              </div>
            </div>
          </div>

          <div class="dropdown-label">Menu</div>

          <a href="dashboard.php" class="dropdown-item" role="menuitem">
            <i class="fas fa-th-large"></i> Browse Scooters
          </a>
          <a href="history.php" class="dropdown-item" id="dropdownHistoryLink" role="menuitem">
            <i class="fas fa-history"></i> My Rentals
          </a>

          <div class="dropdown-divider"></div>

          <!-- Logout -->
          <a href="logout.php" class="dropdown-item danger" role="menuitem">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a>

        </div><!-- /.dropdown-menu -->
      </div><!-- /.user-dropdown -->
    </div><!-- /.header-right -->
  </div><!-- /.header-content -->
</header>

<!-- ═══════════════════════════════════════════════
     RENTAL ALERTS — overdue & ending soon
════════════════════════════════════════════════ -->
<?php if (!empty($overdue_rentals) || !empty($ending_soon)): ?>
<div class="rental-alerts" id="rentalAlerts">

  <?php foreach ($overdue_rentals as $i => $r): ?>
    <div class="alert-banner alert-overdue" id="alert-ov-<?= $i ?>">
      <i class="fas fa-exclamation-triangle alert-icon"></i>
      <div class="alert-body">
        <p>
          <strong>⚠ RENTAL TERLAMBAT!</strong>
          Sewa <strong><?= htmlspecialchars($r['scooter_name']) ?></strong>
          sudah <strong><?= (int)$r['days_late'] ?> hari</strong> melewati batas pengembalian.
        </p>
        <small>
          Rental #<?= (int)$r['rental_id'] ?> &mdash;
          Batas: <?= date('d M Y', strtotime($r['end_date'])) ?> &mdash;
          Segera hubungi kami untuk menghindari denda tambahan.
        </small>
      </div>
      <button class="alert-close" onclick="dismissAlert('alert-ov-<?= $i ?>')" aria-label="Tutup">
        <i class="fas fa-times"></i>
      </button>
    </div>
  <?php endforeach; ?>

  <?php foreach ($ending_soon as $i => $r): ?>
    <?php
      $label = match((int)$r['days_left']) {
        0 => '🔴 BERAKHIR HARI INI',
        1 => '🟠 Berakhir Besok',
        default => '🟡 Berakhir dalam ' . (int)$r['days_left'] . ' hari',
      };
    ?>
    <div class="alert-banner alert-soon" id="alert-es-<?= $i ?>">
      <i class="fas fa-clock alert-icon"></i>
      <div class="alert-body">
        <p>
          <strong><?= $label ?></strong>
          &mdash; Sewa <strong><?= htmlspecialchars($r['scooter_name']) ?></strong>
          akan berakhir pada <strong><?= date('d M Y', strtotime($r['end_date'])) ?></strong>.
        </p>
        <small>
          Rental #<?= (int)$r['rental_id'] ?> &mdash;
          Hubungi kami jika ingin perpanjang sewa.
        </small>
      </div>
      <button class="alert-close" onclick="dismissAlert('alert-es-<?= $i ?>')" aria-label="Tutup">
        <i class="fas fa-times"></i>
      </button>
    </div>
  <?php endforeach; ?>

</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════
     HERO
════════════════════════════════════════════════ -->
<div class="hero-bar">
  <div class="hero-inner">
    <div class="hero-text">
      <h2>FIND YOUR <span>RIDE</span></h2>
      <p>
        <?php
          $total = $result ? $result->num_rows : 0;
          if ($search) {
              echo $total . " scooter(s) found for &ldquo;" . htmlspecialchars($search) . "&rdquo;";
          } else {
              echo "Browse all available scooters";
          }
        ?>
      </p>
      <?php
      $dash_fr = $conn->query("SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS total_r FROM ratings");
      $dash_rating = $dash_fr ? $dash_fr->fetch_assoc() : ['avg_r' => 0, 'total_r' => 0];
      $dash_avg = (float)($dash_rating['avg_r'] ?? 0);
      $dash_total = (int)($dash_rating['total_r'] ?? 0);
      ?>
      <?php if ($dash_total > 0): ?>
        <?php
          $hf = (int)floor($dash_avg); $hh = ($dash_avg - $hf) >= 0.5; $he = 5 - $hf - ($hh ? 1 : 0);
        ?>
        <button type="button" class="hero-rating-badge" onclick="showAllRatings()" aria-label="Lihat semua ulasan rider">
          <span class="hero-rating-stars">
            <?php for ($si = 0; $si < $hf; $si++): ?><i class="fas fa-star"></i><?php endfor; ?><?php if ($hh): ?><i class="fas fa-star-half-alt"></i><?php endif; ?><?php for ($si = 0; $si < $he; $si++): ?><i class="far fa-star"></i><?php endfor; ?>
          </span>
          <strong><?= number_format($dash_avg, 1) ?></strong>
          <span class="hero-rating-count">dari <?= number_format($dash_total) ?> rider</span>
          <i class="fas fa-chevron-right hero-rating-go"></i>
        </button>
      <?php else: ?>
        <div class="hero-rating-badge is-empty"><i class="fas fa-star"></i><span>Belum ada ulasan</span></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     MAIN
════════════════════════════════════════════════ -->
<main class="container">

  <?php if ($result === null): ?>
    <div class="db-error">
      <i class="fas fa-exclamation-circle" style="color:var(--orange);font-size:2rem;display:block;margin-bottom:12px;"></i>
      <p>Could not load scooters. Please try again later.</p>
    </div>

  <?php elseif ($result->num_rows > 0): ?>
    <div class="scooter-grid">
      <?php
        $defaultImage = "https://images.unsplash.com/photo-1566279604926-163b3a6795da?w=400&h=250&fit=crop&crop=center";
        while ($row = $result->fetch_assoc()):
          $imageFile = basename($row['image'] ?? '');
          $imagePath = $imageFile ? "../uploads/$imageFile" : '';
          $imgSrc    = ($imagePath && file_exists($imagePath)) ? $imagePath : $defaultImage;
          $isElec    = $row['type'] === 'electric';
      ?>
        <div class="scooter-card">
          <div class="card-image">
            <img src="<?= htmlspecialchars($imgSrc) ?>"
                 alt="<?= htmlspecialchars($row['name']) ?>"
                 loading="lazy"
                 onerror="this.src='<?= $defaultImage ?>'">
            <div class="type-badge <?= $isElec ? 'electric' : '' ?>">
              <i class="fas <?= $isElec ? 'fa-bolt' : 'fa-gas-pump' ?>"></i>
              <?= $isElec ? 'Electric' : 'Gasoline' ?>
            </div>
          </div>
          <div class="card-content">
            <h3 class="card-title"><?= htmlspecialchars($row['name']) ?></h3>
            <div class="card-meta">
              <i class="fas fa-tag"></i>
              <?= htmlspecialchars(ucfirst($row['brand'])) ?>
            </div>
            <div class="card-price">
              Rp <?= number_format($row['price'], 0, ',', '.') ?>
              <span>/ hari</span>
            </div>
            <div class="card-footer">
              <a href="detail.php?id=<?= (int)$row['scooter_id'] ?>" class="rent-btn">
                <i class="fas fa-calendar-check"></i> Rent Now
              </a>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>

  <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-scooter"></i></div>
      <h3>No Scooters Found</h3>
      <p>Try adjusting your search keyword.</p>
      <a href="?" class="btn-reset"><i class="fas fa-redo"></i> Clear Search</a>
    </div>
  <?php endif; ?>

</main>

<!-- ═══════════════════════════════════════════════
     DROPDOWN SCRIPT
════════════════════════════════════════════════ -->
<script>
// ======= DISMISS ALERT (single definition — duplikasi dihapus) =======
function dismissAlert(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.transition = 'opacity .3s, transform .3s';
  el.style.opacity    = '0';
  el.style.transform  = 'translateY(-6px)';
  setTimeout(() => {
    el.style.maxHeight = '0';
    el.style.margin    = '0';
    el.style.padding   = '0';
    el.style.overflow  = 'hidden';
  }, 300);
  setTimeout(() => {
    el.remove();
    const wrapper = document.getElementById('rentalAlerts');
    if (wrapper && wrapper.querySelectorAll('.alert-banner').length === 0) {
      wrapper.remove();
    }
  }, 700);
}

(function () {
  'use strict';

  const pillBtn   = document.getElementById('userPillBtn');
  const avatarBtn = document.getElementById('mobileAvatarBtn');
  const dropdown  = document.getElementById('userDropdown');
  const overlay   = document.getElementById('dropdownOverlay');
  const container = dropdown ? dropdown.closest('.user-dropdown') : null;
  const mqMobile  = window.matchMedia('(max-width: 768px)');

  let isOpen = false;

  function open() {
    if (!dropdown || isOpen) return;
    isOpen = true;
    dropdown.classList.add('open');
    container && container.classList.add('open');
    // Kunci scroll HANYA di mobile (bottom sheet); desktop tetap bisa scroll
    if (mqMobile.matches) {
      overlay && overlay.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    pillBtn  && pillBtn.setAttribute('aria-expanded', 'true');
    avatarBtn && avatarBtn.setAttribute('aria-expanded', 'true');
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    dropdown.classList.remove('open');
    container && container.classList.remove('open');
    overlay && overlay.classList.remove('open');
    document.body.style.overflow = '';
    pillBtn  && pillBtn.setAttribute('aria-expanded', 'false');
    avatarBtn && avatarBtn.setAttribute('aria-expanded', 'false');
  }

  function toggle(e) { e.stopPropagation(); isOpen ? close() : open(); }

  pillBtn  && pillBtn.addEventListener('click', toggle);
  avatarBtn && avatarBtn.addEventListener('click', toggle);

  // Keyboard: Enter / Space
  [pillBtn, avatarBtn].forEach(el => {
    el && el.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(e); }
    });
  });

  // Overlay tap = close
  overlay && overlay.addEventListener('click', close);

  // Escape = close (kembalikan fokus ke pemicu)
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && isOpen) {
      close();
      (pillBtn && getComputedStyle(pillBtn).display !== 'none' ? pillBtn : avatarBtn)?.focus();
    }
  });

  // Klik di luar = close
  document.addEventListener('click', e => {
    if (!isOpen || !dropdown) return;
    if (!dropdown.contains(e.target) &&
        !(pillBtn && pillBtn.contains(e.target)) &&
        !(avatarBtn && avatarBtn.contains(e.target))) close();
  });

  // Klik item menu = tutup dulu (navigasi tetap jalan)
  dropdown && dropdown.querySelectorAll('.dropdown-item').forEach(a => {
    a.addEventListener('click', close);
  });

  // Swipe-down tutup bottom sheet (mobile)
  let touchStartY = 0;
  dropdown && dropdown.addEventListener('touchstart', e => {
    touchStartY = e.touches[0].clientY;
  }, { passive: true });

  dropdown && dropdown.addEventListener('touchmove', e => {
    if (e.touches[0].clientY - touchStartY > 70) close();
  }, { passive: true });

})();
</script>

<footer class="site-footer">
 
  <!-- Dekorasi speed-line (sama kayak left-panel) -->
  <div class="footer-lines">
    <div class="footer-line"></div>
    <div class="footer-line"></div>
    <div class="footer-line"></div>
  </div>
 
  <div class="footer-inner">
 
    <!-- ── KOLOM 1: Brand -->
    <div class="footer-col footer-brand">
      <div class="footer-logo">
        <i class="fas fa-scooter"></i>
        <span>Scooter<br>Rental</span>
      </div>
      <p class="footer-tagline">
        Ride free. Explore more.<br>
        Your city, your rules.
      </p>
      <div class="footer-socials">
        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="#" aria-label="TikTok"><i class="fab fa-tiktok"></i></a>
        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
      </div>
    </div>
 
    <!-- ── KOLOM 2: Kontak -->
    <div class="footer-col">
      <h4 class="footer-heading">Hubungi Kami</h4>
      <ul class="footer-list">
        <li>
          <a href="https://wa.me/6285760284491" target="_blank" rel="noopener">
            <i class="fab fa-whatsapp"></i>
            <span>+62 857-6028-4491</span>
          </a>
        </li>
        <li>
          <a href="mailto:admin@scooterental.com">
            <i class="fas fa-envelope"></i>
            <span>fadlanwibuganteng@gmail.com</span>
          </a>
        </li>
        <li>
          <span class="footer-addr">
            <i class="fas fa-map-marker-alt"></i>
            <span>Jl. Raya Gatot Subroto No. 88,<br>Jakarta Selatan, 12930</span>
          </span>
        </li>
        <li>
          <span>
            <i class="fas fa-clock"></i>
            <span>Setiap hari, 07.00 – 23.00 WIB</span>
          </span>
        </li>
      </ul>
    </div>
 
    <!-- ── KOLOM 3: Cara Order & Booking -->
    <div class="footer-col">
      <h4 class="footer-heading">Cara Order</h4>
      <ol class="footer-steps">
        <li><span class="step-num">01</span> Pilih unit & tanggal sewa</li>
        <li><span class="step-num">02</span> Upload KTP / SIM</li>
        <li><span class="step-num">03</span> Konfirmasi &amp; bayar DP</li>
        <li><span class="step-num">04</span> Ambil unit di lokasi</li>
      </ol>
    </div>
 
    <!-- ── KOLOM 4: Kebijakan -->
    <div class="footer-col">
      <h4 class="footer-heading">Info &amp; Kebijakan</h4>
      <ul class="footer-list">
        <li><a href="#"><i class="fas fa-chevron-right"></i> Syarat &amp; Ketentuan</a></li>
        <li><a href="#"><i class="fas fa-chevron-right"></i> Kebijakan Privasi</a></li>
        <li><a href="#"><i class="fas fa-chevron-right"></i> Sistem Booking</a></li>
        <li><a href="#"><i class="fas fa-chevron-right"></i> Harga &amp; Paket</a></li>
        <li><a href="#"><i class="fas fa-chevron-right"></i> FAQ</a></li>
      </ul>
    </div>
 
  </div><!-- /.footer-inner -->
 
  <!-- ── Copyright bar -->
  <div class="footer-bottom">
    <p>© <?= date('Y') ?> <strong>Scooter Rental</strong>. All rights reserved.</p>
    <p class="footer-bottom-right">
      Dibuat dengan <i class="fas fa-bolt" style="color:#FF6B00"></i> di Indonesia
    </p>
  </div>

</footer>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Global rating functions -->
<script>
// ── Global state ──────────────────────────────────────────
let ratingsModalLoaded = false;

// ── Show all ratings modal ────────────────────────────────
function showAllRatings() {
    // Cek apakah modal overlay sudah ada, kalau belum buat dulu
    let overlay = document.getElementById('ratingsModalOverlay');
    if (!overlay) {
        createRatingsModal();
        overlay = document.getElementById('ratingsModalOverlay');
    }
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (!ratingsModalLoaded) {
        loadRatings();
    }
}

function closeRatingsModal(overlayEl, event) {
    const overlay = document.getElementById('ratingsModalOverlay');
    if (overlayEl && event && event.target !== overlayEl) return;
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
}

function createRatingsModal() {
    const modalHTML = `
    <div class="ratings-modal-overlay" id="ratingsModalOverlay" onclick="closeRatingsModal(this, event)">
        <div class="ratings-modal" id="ratingsModal">
            <div class="ratings-modal-header">
                <h2><i class="fas fa-star"></i> Rating Semua User</h2>
                <button class="ratings-modal-close" onclick="closeRatingsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="ratings-modal-global" id="ratingsModalGlobal"></div>
            <div class="ratings-modal-body" id="ratingsModalBody">
                <div class="ratings-loading"><i class="fas fa-spinner fa-spin"></i> Memuat data...</div>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHTML);
}

function loadRatings() {
    const body   = document.getElementById('ratingsModalBody');
    const global = document.getElementById('ratingsModalGlobal');

    fetch('get_all_ratings.php')
        .then(r => r.json())
        .then(data => {
            ratingsModalLoaded = true;

            if (data.total > 0) {
                const avg = data.ratings.reduce((s, r) => s + r.rating, 0) / data.total;
                const full  = Math.floor(avg);
                const half  = (avg - full) >= 0.5;
                const empty = 5 - full - (half ? 1 : 0);
                const stars = '<i class="fas fa-star"></i>'.repeat(full)
                            + (half ? '<i class="fas fa-star-half-alt"></i>' : '')
                            + '<i class="far fa-star"></i>'.repeat(empty);
                global.innerHTML = `
                    <div class="modal-global-num">${avg.toFixed(1)}</div>
                    <div>
                        <div class="modal-global-stars">${stars}</div>
                        <div class="modal-global-sub">dari ${data.total} rider</div>
                    </div>
                `;
            } else {
                global.innerHTML = '<span style="color:#666;font-size:.85rem;font-family:\'DM Sans\',sans-serif">Belum ada rating</span>';
            }

            if (!data.ratings || data.ratings.length === 0) {
                body.innerHTML = '<div class="ratings-empty"><i class="fas fa-star" style="color:#333;font-size:2rem;display:block;margin-bottom:10px"></i>Belum ada rating dari user.</div>';
                return;
            }

            body.innerHTML = data.ratings.map(r => `
                <div class="rating-user-card">
                    <div class="rating-user-avatar">${escapeHtml(r.username).charAt(0).toUpperCase()}</div>
                    <div class="rating-user-info">
                        <div class="rating-user-name">${escapeHtml(r.username)}${r.scooter_name ? ` <span style="color:#FF6B00;font-weight:400;">· ${escapeHtml(r.scooter_name)}</span>` : ''}</div>
                        <div class="rating-user-stars">${renderStars(r.rating)}</div>
                        ${r.rating_review ? `<div class="rating-user-review">"${escapeHtml(r.rating_review)}"</div>` : ''}
                        ${r.rated_at ? `<div class="rating-user-date"><i class="fas fa-calendar-alt"></i> ${escapeHtml(r.rated_at)}</div>` : ''}
                    </div>
                    <div style="color:#FFB800;font-weight:700;font-family:'Bebas Neue',sans-serif;font-size:1.2rem;flex-shrink:0">${Number(r.rating).toFixed(1)}</div>
                </div>
            `).join('');
        })
        .catch(() => {
            body.innerHTML = '<div class="ratings-empty"><i class="fas fa-exclamation-triangle" style="color:#FF6B00"></i> Gagal memuat data rating.</div>';
        });
}

function renderStars(rating) {
    const full  = Math.floor(rating);
    const half  = (rating - full) >= 0.5;
    const empty = 5 - full - (half ? 1 : 0);
    return '<i class="fas fa-star"></i>'.repeat(full)
         + (half ? '<i class="fas fa-star-half-alt"></i>' : '')
         + '<i class="far fa-star"></i>'.repeat(empty);
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}

// Escape key closes modal
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('ratingsModalOverlay');
        if (overlay && overlay.classList.contains('open')) closeRatingsModal();
    }
});
</script>

<style>
/* Modal styles */
.ratings-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(4px);
}
.ratings-modal-overlay.open { display: flex; }
.ratings-modal {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideIn 0.25s ease;
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.ratings-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 16px;
    border-bottom: 1px solid #2a2a2a;
}
.ratings-modal-header h2 {
    font-family: 'Bebas Neue', sans-serif;
    color: #fff;
    font-size: 1.5rem;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.ratings-modal-header h2 i { color: #FFB800; }
.ratings-modal-close {
    background: #2a2a2a;
    border: none;
    color: #aaa;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ratings-modal-close:hover {
    background: #FF6B00;
    color: #fff;
}
.ratings-modal-global {
    padding: 14px 24px;
    background: #141414;
    border-bottom: 1px solid #222;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.modal-global-stars {
    display: flex;
    gap: 3px;
    color: #FFB800;
    font-size: 1.2rem;
}
.modal-global-num {
    font-size: 2rem;
    font-weight: 700;
    color: #FFB800;
    font-family: 'Bebas Neue', sans-serif;
    letter-spacing: 1px;
}
.modal-global-sub {
    color: #888;
    font-size: 0.85rem;
    font-family: 'DM Sans', sans-serif;
}
.ratings-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.ratings-modal-body::-webkit-scrollbar { width: 5px; }
.ratings-modal-body::-webkit-scrollbar-track { background: #111; }
.ratings-modal-body::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
.ratings-loading, .ratings-empty {
    text-align: center;
    color: #666;
    padding: 40px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.95rem;
}
.ratings-loading i { color: #FF6B00; }
.rating-user-card {
    background: #222;
    border: 1px solid #2d2d2d;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: border-color 0.2s;
}
.rating-user-card:hover { border-color: #FF6B00; }
.rating-user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #FF6B00, #e55200);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Bebas Neue', sans-serif;
    font-size: 1.1rem;
    color: #fff;
    flex-shrink: 0;
}
.rating-user-info { flex: 1; min-width: 0; }
.rating-user-name {
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    color: #eee;
    font-size: 0.95rem;
}
.rating-user-stars {
    display: flex;
    gap: 2px;
    color: #FFB800;
    font-size: 0.85rem;
    margin: 4px 0;
}
.rating-user-review {
    font-size: 0.85rem;
    color: #999;
    font-family: 'DM Sans', sans-serif;
    line-height: 1.4;
    margin-top: 4px;
    word-break: break-word;
}
.rating-user-date {
    font-size: 0.75rem;
    color: #555;
    font-family: 'DM Sans', sans-serif;
    margin-top: 4px;
}
</style>

</body>
</html>