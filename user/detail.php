<?php
// ========================================================
// ERROR HANDLING — paling atas sebelum output apapun
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// ========================================================
// SESSION SECURITY
// ========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

// Auth check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Session timeout (30 menit)
$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// CSRF token untuk form booking (dipakai book.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================================
// DATABASE
// ========================================================
require "../config/db.php";

// ========================================================
// VALIDASI ID
// ========================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: dashboard.php?invalid=1");
    exit;
}

// ========================================================
// QUERY SCOOTER — prepared statement
// ========================================================
$stmt = $conn->prepare("SELECT * FROM scooters WHERE scooter_id = ?");
if (!$stmt) {
    error_log("Prepare failed (detail): " . $conn->error);
    header("Location: dashboard.php?error=1");
    exit;
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: dashboard.php?notfound=1");
    exit;
}
$scooter = $result->fetch_assoc();
$stmt->close();

// === TAMBAHKAN KODE BARU DI SINI (QUERY TANGGAL TERISI) ===
$booked_dates = [];
$stmt_dates = $conn->prepare("SELECT start_date, end_date FROM rentals WHERE scooter_id = ? AND status IN ('pending', 'active')");
$stmt_dates->bind_param("i", $id);
$stmt_dates->execute();
$res_dates = $stmt_dates->get_result();

while ($date_row = $res_dates->fetch_assoc()) {
    $booked_dates[] = [
        'start' => $date_row['start_date'],
        'end'   => $date_row['end_date']
    ];
}
$stmt_dates->close();

// HELPERS
// ========================================================
$username_display = htmlspecialchars($_SESSION['username'] ?? 'Rider');
$user_initial = strtoupper(substr($_SESSION['username'] ?? 'R', 0, 1));

// INFO AKUN untuk dropdown (sama seperti dashboard.php)
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
} catch (Throwable $e) { error_log("Account info error (detail): " . $e->getMessage()); }
$acc_email_display = htmlspecialchars($acc_email);

$defaultImage = "https://images.unsplash.com/photo-1566279604926-163b3a6795da?w=800&h=500&fit=crop&crop=center";
$imageFile    = basename($scooter['image'] ?? '');
$imagePath    = $imageFile ? "../uploads/$imageFile" : '';
$imgSrc       = ($imagePath && file_exists($imagePath)) ? $imagePath : $defaultImage;

// Status valid: available | rented | maintenance.
// Hanya 'available' yang bisa dibooking.
$s_status_raw = strtolower(trim($scooter['status'] ?? ''));
$isAvailable = ($s_status_raw === 'available');

// Validasi type agar hanya menampilkan Electric / Gasoline
$rawType = strtolower(trim($scooter['type'] ?? ''));
if ($rawType === 'electric') {
    $s_type  = 'Electric';
    $isElectric = true;
} elseif ($rawType === 'gasoline') {
    $s_type  = 'Gasoline';
    $isElectric = false;
} else {
    $s_type  = 'Unknown';
    $isElectric = false;
}

$s_name   = htmlspecialchars($scooter['name'] ?? '');
$s_brand  = htmlspecialchars(ucfirst($scooter['brand'] ?? ''));
$s_status = htmlspecialchars(ucfirst($scooter['status'] ?? ''));
$s_desc   = nl2br(htmlspecialchars($scooter['description'] ?? ''));
$s_price  = number_format((float)($scooter['price'] ?? 0), 0, ',', '.');
$s_id     = (int)$scooter['scooter_id'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $s_name ?> — Scooter Rental</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/detail.css?v=20261007e">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<!-- Header -->
<header class="header">
  <div class="header-content">
    <!-- Brand (Home) -->
    <a href="dashboard.php" class="header-brand">
      <div class="brand-wordmark">
        <strong>SCOOTER</strong>
        <small>RIDER</small>
      </div>
    </a>

    <!-- Kanan: History + User Dropdown (sama seperti dashboard) -->
    <div class="header-right">
      <a href="history.php" class="header-history" title="History">
        <i class="fas fa-history"></i> <span>History</span>
      </a>

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
          <a href="history.php" class="dropdown-item" role="menuitem">
            <i class="fas fa-history"></i> My Rentals
          </a>

          <div class="dropdown-divider"></div>

          <a href="logout.php" class="dropdown-item danger" role="menuitem">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a>

        </div>
      </div>
    </div>
  </div>
</header>
<div class="dropdown-overlay" id="dropdownOverlay"></div>

<!-- Main Content -->
<main class="container">

  <div class="detail-card">

    <!-- Header -->
    <div class="detail-header">
      <div>
        <h1><?= $s_name ?></h1>
        <p class="detail-brand"><span><?= $s_brand ?></span></p>
      </div>
    </div>

   
    <div class="image-wrap">
      <img src="<?= htmlspecialchars($imgSrc) ?>"
           class="detail-image"
           alt="<?= $s_name ?>"
           loading="lazy"
           onerror="this.src='<?= $defaultImage ?>'">
    </div>

   
    <div class="info-grid">
      <div class="info-item">
        <i class="fas <?= $isElectric ? 'fa-bolt' : 'fa-gas-pump' ?>"></i>
        <div class="info-label">Type</div>
        <div class="info-value"><?= $s_type ?></div>
      </div>
      <div class="info-item">
        <i class="fas fa-money-bill-wave"></i>
        <div class="info-label">Price per Day</div>
        <div class="info-value price-value">Rp <?= $s_price ?> <span class="price-sub">/day</span></div>
      </div>
      <div class="info-item">
        <i class="fas fa-info-circle"></i>
        <div class="info-label">Status</div>
        <div class="info-value"><?= ucfirst($s_status) ?></div>
      </div>
    </div>

    
    <?php if (!empty(trim($scooter['description'] ?? ''))): ?>
    <div class="desc-section">
      <h3><i class="fas fa-align-left"></i> Description</h3>
      <div class="desc-content"><?= $s_desc ?></div>
    </div>
    <?php endif; ?>

    <div class="booking-section">
  <div class="booking-title">
    <h2><i class="fas fa-calendar-plus"></i> Book This Scooter</h2>
    <p>Fill in the details below to reserve this scooter</p>
  </div>

  <?php if (!empty($booked_dates)): ?>
    <div class="booked-info" style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <h4 style="margin-bottom: 10px; color: #856404; font-size: 0.9rem;"><i class="fas fa-calendar-times"></i> Fully Booked Dates:</h4>
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <?php foreach ($booked_dates as $date): ?>
                <span style="background: #ffc107; color: #000; padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: bold;">
                    <?= date('d M', strtotime($date['start'])) ?> - <?= date('d M Y', strtotime($date['end'])) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
  <?php endif; ?>

  <?php if (isset($_SESSION['booking_error'])): ?>
    <div class="error-alert">
      <i class="fas fa-exclamation-triangle"></i>
      <?= htmlspecialchars($_SESSION['booking_error']); unset($_SESSION['booking_error']); ?>
    </div>
  <?php endif; ?>

  <?php if (!$isAvailable): ?>
    <div class="error-alert" style="display:flex;">
      <i class="fas fa-ban"></i>
      <span>Unit ini sedang <strong><?= $s_status ?></strong> dan tidak dapat dibooking saat ini. Silakan pilih unit lain yang Available.</span>
    </div>
  <?php endif; ?>

  <form method="POST" action="book.php" class="booking-form" id="bookingForm"
        enctype="multipart/form-data" <?= $isAvailable ? '' : 'style="opacity:.5;pointer-events:none;" onsubmit="return false;"' ?>>
    <input type="hidden" name="scooter_id" value="<?= $s_id ?>">
    <input type="hidden" name="total_price" id="total_price_hidden" value="0">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div class="form-group">
      <label for="nama"><i class="fas fa-user"></i> Full Name</label>
      <input type="text" id="nama" name="nama" required
             value="<?= $username_display ?>"
             placeholder="Enter your full name" maxlength="100">
    </div>
    <div class="form-group">
      <label for="start_date"><i class="fas fa-calendar-day"></i> Start Date</label>
      <input type="date" id="start_date" name="start_date" required>
    </div>
    <div class="form-group">
      <label for="end_date"><i class="fas fa-calendar-check"></i> End Date</label>
      <input type="date" id="end_date" name="end_date" required>
    </div>
    <div id="dateError" class="error-alert" style="display:none;">
      <i class="fas fa-exclamation-triangle"></i> End date cannot be earlier than start date!
    </div>

    <!-- ── UPLOAD KTP / SIM ── -->
    <div class="form-group">
      <label for="id_card"><i class="fas fa-id-card"></i> KTP / SIM / Tanda Identitas <span style="color:#FF6B00">*</span></label>
      <div class="upload-area" id="uploadArea">
        <input type="file" id="id_card" name="id_card" required
               accept="image/jpeg,image/png,image/webp,application/pdf"
               style="display:none">
        <div class="upload-placeholder" id="uploadPlaceholder" onclick="document.getElementById('id_card').click()">
          <i class="fas fa-cloud-upload-alt upload-icon"></i>
          <p class="upload-text">Klik atau drag &amp; drop file di sini</p>
          <p class="upload-hint">Format: JPG, PNG, WEBP, PDF &nbsp;·&nbsp; Maks. 3 MB</p>
        </div>
        <div class="upload-preview" id="uploadPreview" style="display:none">
          <img id="previewImg" src="" alt="Preview" style="max-height:140px;border-radius:8px;object-fit:contain;">
          <div class="preview-info">
            <span id="previewName" class="preview-filename"></span>
            <button type="button" class="remove-file" onclick="clearFile()">
              <i class="fas fa-times"></i> Ganti file
            </button>
          </div>
        </div>
      </div>
      <small style="color:#888;display:block;margin-top:6px;">
        <i class="fas fa-lock" style="color:#FF6B00"></i>
        Foto identitas hanya digunakan untuk verifikasi dan tidak disebarluaskan.
      </small>
    </div>

    <!-- Inline CSS untuk upload area -->
    <style>
      .upload-area {
        border: 2px dashed rgba(255,107,0,.4);
        border-radius: 10px;
        background: rgba(255,107,0,.04);
        transition: border-color .2s, background .2s;
        overflow: hidden;
      }
      .upload-area.dragover {
        border-color: #FF6B00;
        background: rgba(255,107,0,.1);
      }
      .upload-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 28px 20px;
        cursor: pointer;
      }
      .upload-icon { 
        font-size: 2rem; 
        color: #FF6B00; 
      }
      .upload-text { 
        font-weight: 600; 
        margin: 0; 
        color: #ccc; 
        font-size: .9rem; 
      }
      .upload-hint { 
        margin: 0; 
        font-size: .78rem; 
        color: #777; 
      }
      .upload-preview {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
      }
      .preview-info { 
        display:flex; 
        flex-direction:column; 
        gap:8px; 
        flex:1; 
        min-width:0; 
      }
      .preview-filename { 
        font-size:.82rem; 
        color:#ccc; 
        word-break:break-all; 
      }
      .remove-file {
        background: none; 
        border: 1px solid #FF6B00;
        color: #FF6B00; 
        border-radius: 6px;
        padding: 4px 12px; 
        font-size:.78rem;
        cursor: pointer; 
        font-family: inherit;
        transition: background .2s, color .2s;
        width: fit-content;
      }
      .remove-file:hover { 
        background: #FF6B00; 
        color: #fff; 
      }
    </style>
    <div class="price-summary" id="priceSummary">
      <div class="price-summary-title"><i class="fas fa-calculator"></i> Price Summary</div>
      <div class="price-row">
        <span class="lbl">Price per day</span>
        <span class="val">Rp <?= $s_price ?></span>
      </div>
      <div class="price-row">
        <span class="lbl">Duration</span>
        <span class="val"><span id="daysCount">0</span> day(s)</span>
      </div>
      <div class="price-row">
        <span class="lbl">Payment Method</span>
        <span class="val">COD (Cash on Delivery)</span>
      </div>
      <div class="price-total-row">
        <span class="lbl">Total Rental Cost</span>
        <strong id="totalPrice">Rp 0</strong>
      </div>
</div> <button type="submit" class="btn-book">
      <i class="fas fa-check-circle"></i> <span>Confirm Booking</span>
</button>
  </form>
</div>
<script>
// 1. DATA DARI PHP (LOGIKA BARU)
const bookedDates = <?= json_encode($booked_dates) ?>;

// Kalkulator harga
const pricePerDay = <?= (float)($scooter['price'] ?? 0) ?>;
const startDateInput = document.getElementById('start_date');
const endDateInput = document.getElementById('end_date');
const totalPriceSpan = document.getElementById('totalPrice');
const daysCountSpan = document.getElementById('daysCount');
const dateErrorDiv = document.getElementById('dateError');
const totalPriceHidden = document.getElementById('total_price_hidden');
const priceSummary = document.getElementById('priceSummary');
const btnBook = document.querySelector('.btn-book'); // Ambil tombol booking

function formatRupiah(n) { return new Intl.NumberFormat('id-ID').format(n); }
function formatDate(d) { return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }

// 2. FUNGSI CEK BENTROK (LOGIKA BARU)
function isOverlapping(start, end) {
    const s = new Date(start); s.setHours(0,0,0,0);
    const e = new Date(end); e.setHours(0,0,0,0);
    
    return bookedDates.some(range => {
        const rangeStart = new Date(range.start); rangeStart.setHours(0,0,0,0);
        const rangeEnd = new Date(range.end); rangeEnd.setHours(0,0,0,0);
        return (s <= rangeEnd && e >= rangeStart);
    });
}

function setDateDefaults() {
    const today = new Date(); today.setHours(0,0,0,0);
    const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate()+1);
    if(startDateInput) { startDateInput.min = formatDate(today); if(!startDateInput.value) startDateInput.value = formatDate(today); }
    if(endDateInput) { endDateInput.min = formatDate(tomorrow); if(!endDateInput.value) endDateInput.value = formatDate(tomorrow); }
}

function updateEndDateMin() {
    if(!startDateInput?.value || !endDateInput) return;
    // Langsung ambil tanggal yang sama dengan start date
    const currentStart = new Date(startDateInput.value); 
    endDateInput.min = formatDate(currentStart);
    
    if(endDateInput.value && endDateInput.value < endDateInput.min) {
        endDateInput.value = endDateInput.min;
    }
}

// ======= FUNGSI UPDATE STATUS TOMBOL BOOK (DIPERBAIKI) =======
function updateBookButtonState() {
    if (!btnBook) return;
    
    const fileInput = document.getElementById('id_card');
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
    
    // Cek apakah ada error tanggal yang tampil
    const isDateErrorVisible = dateErrorDiv && dateErrorDiv.style.display === 'flex';
    
    // Tombol aktif hanya jika: sudah upload file DAN tidak ada error tanggal
    if (hasFile && !isDateErrorVisible) {
        btnBook.disabled = false;
        btnBook.style.opacity = '1';
        btnBook.style.cursor = 'pointer';
        console.log('Tombol Aktif: file ada, no error'); // buat debugging
    } else {
        btnBook.disabled = true;
        btnBook.style.opacity = '0.5';
        btnBook.style.cursor = 'not-allowed';
        console.log('Tombol Nonaktif: hasFile=' + hasFile + ', isDateErrorVisible=' + isDateErrorVisible);
    }
}

function updateTotalPrice() {
    if(!startDateInput?.value || !endDateInput?.value) {
        if(totalPriceSpan) totalPriceSpan.textContent = 'Rp 0';
        if(daysCountSpan) daysCountSpan.textContent = '0';
        if(totalPriceHidden) totalPriceHidden.value = '0';
        updateBookButtonState();
        return;
    }

    const start = new Date(startDateInput.value); start.setHours(0,0,0,0);
    const end = new Date(endDateInput.value); end.setHours(0,0,0,0);

    // 3. VALIDASI BENTROK TANGGAL (LOGIKA BARU)
    if (isOverlapping(startDateInput.value, endDateInput.value)) {
        if(dateErrorDiv) {
            dateErrorDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Oops! These dates are already booked. Please select another schedule.';
            dateErrorDiv.style.display = 'flex';
        }
        updateBookButtonState();
        return; // Stop fungsi biar harga gak kehitung kalau bentrok
    }

    if(end < start) {
        if(dateErrorDiv) {
            dateErrorDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> End date cannot be earlier than start date!';
            dateErrorDiv.style.display = 'flex';
        }
        if(totalPriceSpan) totalPriceSpan.textContent = 'Rp 0';
        if(daysCountSpan) daysCountSpan.textContent = '0';
        if(totalPriceHidden) totalPriceHidden.value = '0';
        updateBookButtonState();
        return;
    }

    // Kalau aman, hilangkan error
    if(dateErrorDiv) dateErrorDiv.style.display = 'none';

    const diffDays = Math.floor((end - start)/86400000) + 1;
    const total = diffDays * pricePerDay;
    if(daysCountSpan) daysCountSpan.textContent = diffDays;
    if(totalPriceSpan) totalPriceSpan.textContent = `Rp ${formatRupiah(total)}`;
    if(totalPriceHidden) totalPriceHidden.value = total;
    if(priceSummary) {
        priceSummary.style.transition = 'all 0.2s';
        priceSummary.style.borderColor = 'var(--orange)';
        priceSummary.style.boxShadow = '0 0 0 3px var(--orange-glow)';
        setTimeout(() => { priceSummary.style.borderColor = ''; priceSummary.style.boxShadow = ''; }, 400);
    }
    
    updateBookButtonState();
}

if(startDateInput) { startDateInput.addEventListener('change', ()=>{ updateEndDateMin(); updateTotalPrice(); }); startDateInput.addEventListener('input', ()=>{ updateEndDateMin(); updateTotalPrice(); }); }
if(endDateInput) { endDateInput.addEventListener('change', updateTotalPrice); endDateInput.addEventListener('input', updateTotalPrice); }
document.addEventListener('DOMContentLoaded', ()=>{ setDateDefaults(); updateEndDateMin(); updateTotalPrice(); });

// ======= UPLOAD KTP/SIM =======
const fileInput    = document.getElementById('id_card');
const uploadArea   = document.getElementById('uploadArea');
const placeholder  = document.getElementById('uploadPlaceholder');
const previewBox   = document.getElementById('uploadPreview');
const previewImg   = document.getElementById('previewImg');
const previewName  = document.getElementById('previewName');

// === TAMBAH PESAN PERINGATAN DI BAWAH UPLOAD AREA ===
let warningEl = document.getElementById('uploadWarning');
if (!warningEl && uploadArea) {
    warningEl = document.createElement('div');
    warningEl.id = 'uploadWarning';
    warningEl.style.cssText = 'margin-top: 10px; font-size: 0.8rem; color: #ef4444; display: flex; align-items: center; gap: 6px;';
    warningEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>Harap upload KTP / SIM</span>';
    uploadArea.parentNode?.insertBefore(warningEl, uploadArea.nextSibling);
}

function showPreview(file) {
  if (!file) return;
  
  // Validasi ukuran & format sebelum preview
  if (file.size > 3 * 1024 * 1024) {
    Swal.fire({
      icon: 'error',
      title: 'Ukuran File Terlalu Besar',
      text: 'Ukuran file maksimal 3 MB. Silakan kompres atau pilih file lain.',
      confirmButtonColor: '#FF6B00'
    });
    fileInput.value = '';
    updateBookButtonState();
    return;
  }
  const allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
  if (!allowed.includes(file.type)) {
    Swal.fire({
      icon: 'error',
      title: 'Format File Tidak Didukung',
      text: 'Gunakan format JPG, PNG, WEBP, atau PDF.',
      confirmButtonColor: '#FF6B00'
    });
    fileInput.value = '';
    updateBookButtonState();
    return;
  }
  
  previewName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
  if (file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = e => { previewImg.src = e.target.result; previewImg.style.display = 'block'; };
    reader.readAsDataURL(file);
  } else {
    previewImg.style.display = 'none';
  }
  placeholder.style.display = 'none';
  previewBox.style.display  = 'flex';
  
  // Sembunyikan peringatan
  if (warningEl) warningEl.style.display = 'none';
  
  // Update tombol book (jadi aktif)
  updateBookButtonState();
}

function clearFile() {
  fileInput.value = '';
  previewImg.src  = '';
  previewName.textContent = '';
  previewBox.style.display  = 'none';
  placeholder.style.display = 'flex';
  
  // Tampilkan peringatan lagi
  if (warningEl) warningEl.style.display = 'flex';
  
  // Update tombol book (jadi nonaktif)
  updateBookButtonState();
}

fileInput && fileInput.addEventListener('change', function() {
  if (this.files[0]) {
    showPreview(this.files[0]);
  } else {
    clearFile();
  }
});

// Drag & drop dengan validasi
uploadArea && uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea && uploadArea.addEventListener('dragleave',  () => uploadArea.classList.remove('dragover'));
uploadArea && uploadArea.addEventListener('drop', e => {
  e.preventDefault();
  uploadArea.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if (f) { 
    fileInput.files = e.dataTransfer.files; 
    showPreview(f); 
  }
});

// === VALIDASI SUBMIT ===
document.getElementById('bookingForm')?.addEventListener('submit', function(e) {
  const f = fileInput?.files[0];
  if (!f) { 
    e.preventDefault(); 
    Swal.fire({
      icon: 'warning',
      title: 'Identitas Diperlukan',
      text: 'Harap upload KTP / SIM / tanda identitas untuk melanjutkan booking.',
      confirmButtonText: 'Ok, Saya Upload',
      confirmButtonColor: '#FF6B00'
    });
    return; 
  }
  if (f.size > 3 * 1024 * 1024) {
    e.preventDefault();
    Swal.fire({
      icon: 'error',
      title: 'Ukuran File Terlalu Besar',
      text: 'Ukuran file maksimal 3 MB. Silakan kompres atau pilih file lain.',
      confirmButtonColor: '#FF6B00'
    });
    return;
  }
  const allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
  if (!allowed.includes(f.type)) {
    e.preventDefault();
    Swal.fire({
      icon: 'error',
      title: 'Format File Tidak Didukung',
      text: 'Gunakan format JPG, PNG, WEBP, atau PDF.',
      confirmButtonColor: '#FF6B00'
    });
    return;
  }
});

// Inisialisasi awal: tombol disable karena belum upload
updateBookButtonState();

// ── USER DROPDOWN (sama seperti dashboard) ──
(function () {
  'use strict';
  const pillBtn   = document.getElementById('userPillBtn');
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
    if (mqMobile.matches) {
      overlay && overlay.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    pillBtn && pillBtn.setAttribute('aria-expanded', 'true');
  }
  function close() {
    if (!isOpen) return;
    isOpen = false;
    dropdown.classList.remove('open');
    container && container.classList.remove('open');
    overlay && overlay.classList.remove('open');
    document.body.style.overflow = '';
    pillBtn && pillBtn.setAttribute('aria-expanded', 'false');
  }
  function toggle(e) { e.stopPropagation(); isOpen ? close() : open(); }

  pillBtn && pillBtn.addEventListener('click', toggle);
  pillBtn && pillBtn.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(e); }
  });
  overlay && overlay.addEventListener('click', close);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && isOpen) { close(); pillBtn?.focus(); }
  });
  document.addEventListener('click', e => {
    if (!isOpen || !dropdown) return;
    if (!dropdown.contains(e.target) && !(pillBtn && pillBtn.contains(e.target))) close();
  });
  dropdown && dropdown.querySelectorAll('.dropdown-item').forEach(a => {
    a.addEventListener('click', close);
  });
  let touchStartY = 0;
  dropdown && dropdown.addEventListener('touchstart', e => {
    touchStartY = e.touches[0].clientY;
  }, { passive: true });
  dropdown && dropdown.addEventListener('touchmove', e => {
    if (e.touches[0].clientY - touchStartY > 70) close();
  }, { passive: true });
})();
</script>
</body>
</html>