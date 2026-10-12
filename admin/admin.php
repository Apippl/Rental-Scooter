<?php
// ========================================================
// ERROR HANDLING — HARUS paling atas sebelum output apapun
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

// ========================================================
// SESSION
// ========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

// Auth: hanya admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../user/index.php");
    exit;
}

// Session timeout 30 menit
$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: ../user/index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// BUG FIX: require bukan include — script berhenti jika DB hilang
require "../config/db.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// ========================================================
// STATISTICS — helper pakai query sederhana & safe
// ========================================================
function getCount(mysqli $conn, string $sql): int {
    $r = $conn->query($sql);
    return $r ? (int)$r->fetch_assoc()['total'] : 0;
}

$stats = [
    'total_motorcycle'  => getCount($conn, "SELECT COUNT(*) AS total FROM scooters"),
    'total_users'     => getCount($conn, "SELECT COUNT(*) AS total FROM users WHERE role='user'"),
    'pending_rentals' => getCount($conn, "SELECT COUNT(*) AS total FROM rentals WHERE status='pending'"),
    'active_rentals'  => getCount($conn, "SELECT COUNT(*) AS total FROM rentals WHERE status='active'"),
];
    
// Revenue hanya dari completed (akurat)
$rev = $conn->query("SELECT COALESCE(SUM(total),0) AS rev FROM rentals WHERE status='completed'");
$stats['total_revenue'] = $rev ? (int)$rev->fetch_assoc()['rev'] : 0;

// Rating global (per-sewa)
$rat_res = $conn->query("SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS cnt FROM ratings");
$rat_row = $rat_res ? $rat_res->fetch_assoc() : ['avg_r' => 0, 'cnt' => 0];

// ========================================================
// PENDING RENTALS
// ========================================================
$pending_rentals = [];
$r = $conn->query("
    SELECT r.rental_id, r.start_date, r.end_date, r.total, r.status, r.id_card_path,
           s.name AS scooter_name, u.username AS customer_name
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
    LIMIT 10
");
if ($r) { while ($row = $r->fetch_assoc()) $pending_rentals[] = $row; }

// ========================================================
// ACTIVE RENTALS
// ========================================================
$active_rentals = [];
$r = $conn->query("
    SELECT r.*, s.name AS scooter_name, u.username AS customer_name
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.status = 'active'
    ORDER BY r.end_date ASC
    LIMIT 10
");
if ($r) { while ($row = $r->fetch_assoc()) $active_rentals[] = $row; }

// ========================================================
// ENDING SOON (dalam 2 hari)
// ========================================================
$ending_soon = [];
$r = $conn->query("
    SELECT r.*, s.name AS scooter_name, u.username AS customer_name
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.status = 'active'
    AND r.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.end_date ASC
");
if ($r) { while ($row = $r->fetch_assoc()) $ending_soon[] = $row; }

// BUG FIX: Kalkulasi durasi tidak bisa negatif
function rentalDays(string $start, string $end): int {
    return max(1, (int)round((strtotime($end) - strtotime($start)) / 86400) + 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard — Scooter Rental</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin.css?v=20261007d">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>

<header class="admin-header">
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>
    <a href="admin.php" class="header-brand">
        <div class="brand-wordmark">
            <strong>SCOOTER</strong>
            <small>ADMIN</small>
        </div>
    </a>
    <div class="header-spacer"></div>
    <div class="admin-pill">
        <div class="admin-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
        <div class="admin-pill-info">
            <strong><?= $admin_name ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</header>

<div class="page-layout">

    <!-- ===== SIDEBAR TANPA REPORT SECTION ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sb-label">Main</div>
        <a href="admin.php" class="sb-link active">
            <i class="fas fa-th-large"></i> Dashboard
        </a>

        <div class="sb-label">Management</div>
        <a href="admin_add.php" class="sb-link">
            <i class="fas fa-plus-circle"></i> Add Scooter
        </a>
        <a href="admin_manage.php" class="sb-link">
            <i class="fas fa-screwdriver-wrench"></i> Manage Scooters
        </a>
        <a href="admin_users.php" class="sb-link">
            <i class="fas fa-users"></i> Manage Users
        </a>

        <div class="sb-label">Reports</div>
        <a href="admin_history.php" class="sb-link">
            <i class="fas fa-history"></i> Rental History
        </a>

        <div class="sb-label">Site</div>
        <a href="../user/dashboard.php" class="sb-link" target="_blank">
            <i class="fas fa-external-link-alt"></i> View Site
        </a>
        <a href="../user/logout.php" class="sb-link danger" onclick="return swalConfirm(event,'Logout?','Kamu akan keluar dari Admin Panel.','warning','Ya, Logout',this.href)">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>

    <div class="menu-overlay" id="menuOverlay"></div>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">

        <!-- Notifications -->
        <?php if (isset($_SESSION['error'])): ?>
        <div class="notification error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
        <div class="notification success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <div class="page-title">
            <h1>ADMIN <span>DASHBOARD</span></h1>
            <p class="page-subtitle"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Welcome back, <?= $admin_name ?></p>
        </div>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon si-orange"><i class="fas fa-motorcycle"></i></div>
                <div class="stat-label">Motorcycles</div>
                <div class="stat-number"><?= number_format($stats['total_motorcycle']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
                <div class="stat-label">Users</div>
                <div class="stat-number"><?= number_format($stats['total_users']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-amber"><i class="fas fa-clock"></i></div>
                <div class="stat-label">Pending</div>
                <div class="stat-number"><?= number_format($stats['pending_rentals']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-green"><i class="fas fa-bolt"></i></div>
                <div class="stat-label">Active</div>
                <div class="stat-number"><?= number_format($stats['active_rentals']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon si-black"><i class="fas fa-wallet"></i></div>
                <div class="stat-label">Revenue</div>
                <div class="stat-number stat-number-sm">
                    Rp <?= number_format($stats['total_revenue'], 0, ',', '.') ?>
                </div>
            </div>
            <div class="stat-card stat-card-wide">
                <div class="stat-icon si-gold"><i class="fas fa-star"></i></div>
                <div class="stat-wide-body">
                    <div class="stat-label">Avg Rating</div>
                    <div class="stat-number">
                        <?= $rat_row['avg_r'] > 0 ? number_format((float)$rat_row['avg_r'], 1) . ' ⭐' : '—' ?>
                    </div>
                    <div class="stat-sub"><?= (int)$rat_row['cnt'] ?> user rated</div>
                </div>
            </div>
        </div>

        
        <!-- PENDING RENTALS -->
        <section class="section" id="section-pending">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-amber"><i class="fas fa-clipboard-list"></i></div>
                    <h2>Need Your Action</h2>
                </div>
                <span class="badge badge-amber"><?= $stats['pending_rentals'] ?> pending</span>
            </div>
            <div class="section-body">
                <?php if (!empty($pending_rentals)): ?>
                    <div class="cards-grid">
                    <?php foreach ($pending_rentals as $r): ?>
                        <div class="card">
                            <div class="card-head">
                                <div>
                                    <div class="card-name"><?= htmlspecialchars($r['scooter_name']) ?></div>
                                    <div class="card-id">#<?= (int)$r['rental_id'] ?></div>
                                </div>
                                <span class="sp sp-pending">Pending</span>
                            </div>
                            <div class="card-details">
                                <div class="dr">
                                    <i class="fas fa-user"></i>
                                    <span><strong><?= htmlspecialchars($r['customer_name'] ?: 'Guest') ?></strong></span>
                                </div>
                                <div class="dr">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <span><strong>Rp <?= number_format($r['total'], 0, ',', '.') ?></strong></span>
                                </div>
                                <div class="dr">
                                    <i class="fas fa-calendar"></i>
                                    <span><?= date('d M Y', strtotime($r['start_date'])) ?></span>
                                </div>
                                <div class="dr">
                                    <i class="fas fa-calendar-check"></i>
                                    <span><?= date('d M Y', strtotime($r['end_date'])) ?></span>
                                </div>
                            </div>

                            <!-- Tombol lihat KTP/SIM via proxy aman (view_idcard.php) -->
                            <?php
                                $idCardPath = $r['id_card_path'] ?? '';
                                $idCardExt  = strtolower(pathinfo($idCardPath, PATHINFO_EXTENSION));
                                $isImage    = in_array($idCardExt, ['jpg','jpeg','png','webp']);
                                $idCardProxy = 'view_idcard.php?rental_id=' . (int)$r['rental_id'];
                            ?>
                            <div style="padding: 0 0 10px;">
                                <?php if ($idCardPath): ?>
                                    <button type="button"
                                        class="btn-id-card"
                                        onclick="showIdCard('<?= $idCardProxy ?>','<?= $isImage ? 'image' : 'pdf' ?>','<?= htmlspecialchars($r['customer_name'] ?: 'Guest', ENT_QUOTES) ?>')">
                                        <i class="fas fa-id-card"></i> Lihat KTP / SIM
                                    </button>
                                <?php else: ?>
                                    <span class="no-id-card">
                                        <i class="fas fa-exclamation-triangle"></i> Identitas tidak diupload
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-actions">
                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return swalConfirmForm(event,'Konfirmasi Rental?','Rental #<?= (int)$r['rental_id'] ?> akan disetujui dan skuter aktif.','question','Ya, Konfirmasi',this)">
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="btn btn-confirm">
                                        <i class="fas fa-check"></i> Confirm
                                    </button>
                                </form>
                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return swalConfirmForm(event,'Batalkan Rental?','Rental #<?= (int)$r['rental_id'] ?> akan dibatalkan.','warning','Ya, Batalkan',this)">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="btn btn-cancel">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-check-circle"></i></div>
                        <p>All clear — no pending rentals.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ACTIVE RENTALS -->
        <?php if (!empty($active_rentals)): ?>
        <section class="section" id="section-active">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-green"><i class="fas fa-bolt"></i></div>
                    <h2>Currently Rented</h2>
                </div>
                <span class="badge badge-green"><?= count($active_rentals) ?> active</span>
            </div>
            <div class="section-body">
                <div class="cards-grid">
                <?php foreach ($active_rentals as $rental): ?>
                    <?php $endingSoon = strtotime($rental['end_date']) <= strtotime('+2 days'); ?>
                    <div class="card">
                        <div class="card-head">
                            <div>
                                <div class="card-name"><?= htmlspecialchars($rental['scooter_name']) ?></div>
                                <div class="card-id">#<?= (int)$rental['rental_id'] ?></div>
                            </div>
                            <span class="sp sp-active">Active</span>
                        </div>
                        <div class="card-details">
                            <div class="dr">
                                <i class="fas fa-user"></i>
                                <span><strong><?= htmlspecialchars($rental['customer_name'] ?: 'Guest') ?></strong></span>
                            </div>
                            <div class="dr">
                                <i class="fas fa-calendar-alt"></i>
                                <span><?= date('d M', strtotime($rental['start_date'])) ?> – <?= date('d M Y', strtotime($rental['end_date'])) ?></span>
                            </div>
                            <div class="dr">
                                <i class="fas fa-clock"></i>
                                <span><strong><?= rentalDays($rental['start_date'], $rental['end_date']) ?> days</strong></span>
                            </div>
                            <div class="dr">
                                <i class="fas fa-<?= $endingSoon ? 'exclamation-triangle' : 'check-circle' ?>"
                                   style="color:<?= $endingSoon ? 'var(--red)' : 'var(--green)' ?>"></i>
                                <span style="color:<?= $endingSoon ? 'var(--red)' : 'var(--green)' ?>;font-weight:600;">
                                    <?= $endingSoon ? 'Ending soon' : 'On track' ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-actions">
                            <?php $rid = (int)$rental['rental_id']; ?>
                            <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return swalConfirmForm(event,'Cancel Rental Aktif?','Skuter akan langsung tersedia kembali.','warning','Ya, Cancel',this)">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= $rid ?>">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn btn-cancel">
                                    <i class="fas fa-ban"></i> Cancel
                                </button>
                            </form>
                            <form method="POST" action="admin_action.php" enctype="multipart/form-data" style="display:inline;" onsubmit="return swalConfirmForm(event,'Tandai Selesai?','Rental #<?= $rid ?> akan dicatat sebagai completed. Denda keterlambatan dihitung otomatis.','success','Ya, Selesai!',this)">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="id" value="<?= $rid ?>">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn btn-complete">
                                    <i class="fas fa-flag-checkered"></i> Complete
                                </button>
                                <details style="margin-top:8px;font-size:12px;color:var(--gray-dk);">
                                    <summary style="cursor:pointer;font-weight:700;">Bukti pengembalian (opsional)</summary>
                                    <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;">
                                        <input type="text" name="return_note" maxlength="1000" placeholder="Catatan kondisi unit…" style="padding:8px 10px;border:1.5px solid var(--gray-lt);border-radius:7px;font-family:inherit;font-size:12px;">
                                        <input type="file" name="return_photo" accept="image/jpeg,image/png,image/webp" style="font-size:12px;">
                                    </div>
                                </details>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ENDING SOON (pengingat H-2) -->
        <?php if (!empty($ending_soon)): ?>
        <section class="section" id="section-ending">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-amber"><i class="fas fa-bell"></i></div>
                    <h2>Ending Soon — Hubungi Penyewa</h2>
                </div>
                <span class="badge badge-amber"><?= count($ending_soon) ?> segera berakhir</span>
            </div>
            <div class="section-body">
                <div style="overflow-x:auto;">
                    <table class="rental-table">
                        <thead><tr><th>#</th><th>Scooter</th><th>Customer</th><th>Berakhir</th><th>Sisa</th></tr></thead>
                        <tbody>
                        <?php foreach ($ending_soon as $es): ?>
                            <tr>
                                <td style="font-weight:700;">#<?= (int)$es['rental_id'] ?></td>
                                <td><strong><?= htmlspecialchars($es['scooter_name']) ?></strong></td>
                                <td><?= htmlspecialchars($es['customer_name'] ?? 'Guest') ?></td>
                                <td style="white-space:nowrap;"><?= date('d M Y', strtotime($es['end_date'])) ?></td>
                                <td><?= (int)($es['days_left'] ?? 0) === 0 ? 'Hari ini' : ((int)($es['days_left'] ?? 0) . ' hari lagi') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- SETTINGS -->
        <?php $set_late = (int)getSetting('late_fee_per_day', '50000'); $set_max = (int)getSetting('max_rent_days', '30'); ?>
        <section class="section" id="section-settings">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-orange"><i class="fas fa-sliders-h"></i></div>
                    <h2>Rental Settings</h2>
                </div>
            </div>
            <div class="section-body">
                <form method="POST" action="admin_action.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="action" value="settings">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <label style="font-size:12px;font-weight:700;">Denda / hari (Rp)
                        <input type="number" name="late_fee_per_day" min="0" step="1000" value="<?= $set_late ?>" style="display:block;padding:8px 10px;border:1.5px solid var(--gray-lt);border-radius:7px;margin-top:4px;width:180px;">
                    </label>
                    <label style="font-size:12px;font-weight:700;">Maks sewa (hari)
                        <input type="number" name="max_rent_days" min="1" max="365" value="<?= $set_max ?>" style="display:block;padding:8px 10px;border:1.5px solid var(--gray-lt);border-radius:7px;margin-top:4px;width:140px;">
                    </label>
                    <button type="submit" class="btn btn-confirm" style="flex:0 0 auto;padding:9px 18px;">
                        <i class="fas fa-save"></i> Save
                    </button>
                </form>
            </div>
        </section>

        <!-- RENTAL HISTORY (COLLAPSIBLE) -->
        <section class="section" id="section-history">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-gray"><i class="fas fa-history"></i></div>
                    <h2>Rental History</h2>
                </div>
                <div class="sec-right">
                    <span class="badge badge-gray">Last 20 records</span>
                    <button class="toggle-btn collapsed" id="historyToggle" onclick="toggleHistory()">
                        <i class="fas fa-chevron-down"></i>
                        <span id="historyLabel">Show History</span>
                    </button>
                </div>
            </div>
            <div class="section-body hidden" id="historyBody">
                <?php
                $recent_rentals = [];
                $rh = $conn->query("
                    SELECT r.rental_id, r.start_date, r.end_date, r.total, r.status, r.created_at,
                           r.late_days, r.late_fee,
                           s.name AS scooter_name, u.username AS customer_name
                    FROM rentals r
                    JOIN scooters s ON r.scooter_id = s.scooter_id
                    LEFT JOIN users u ON r.user_id = u.user_id
                    ORDER BY r.created_at DESC
                    LIMIT 20
                ");
                if ($rh) { while ($row = $rh->fetch_assoc()) $recent_rentals[] = $row; }
                ?>
                <?php if (!empty($recent_rentals)): ?>
                    <div style="overflow-x:auto;">
                        <table class="rental-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Scooter</th>
                                    <th>Customer</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_rentals as $row): ?>
                                <tr>
                                    <td style="color:var(--gray-mid);font-weight:700;font-size:11px;">#<?= (int)$row['rental_id'] ?></td>
                                    <td style="font-weight:700;color:var(--black);"><?= htmlspecialchars($row['scooter_name']) ?></td>
                                    <td><?= htmlspecialchars($row['customer_name'] ?: 'Guest') ?></td>
                                    <td style="white-space:nowrap;">
                                        <?= date('d M', strtotime($row['start_date'])) ?> –
                                        <?= date('d M Y', strtotime($row['end_date'])) ?>
                                    </td>
                                    <td style="text-align:center;font-weight:700;"><?= rentalDays($row['start_date'], $row['end_date']) ?>d</td>
                                    <td style="font-weight:700;">Rp <?= number_format($row['total'], 0, ',', '.') ?><?= !empty($row['late_fee']) && (float)$row['late_fee'] > 0 ? '<br><small style="color:#B91C1C;">+denda Rp ' . number_format($row['late_fee'], 0, ',', '.') . ' (' . (int)$row['late_days'] . 'd)</small>' : '' ?></td>
                                    <td>
                                        <span class="sp sp-<?= strtolower(htmlspecialchars($row['status'])) ?>">
                                            <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--gray-mid);font-size:11.5px;white-space:nowrap;">
                                        <?= date('d M Y', strtotime($row['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-history"></i></div>
                        <p>No rental history yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- USER RATINGS (READ ONLY) -->
        <?php
        $rated_users = [];
        $ru = $conn->query("
            SELECT u.username, r.rating, r.review AS rating_review, r.updated_at AS rated_at, s.name AS scooter_name
            FROM ratings r
            JOIN users u ON u.user_id = r.user_id
            LEFT JOIN scooters s ON s.scooter_id = r.scooter_id
            ORDER BY r.rating DESC, r.updated_at DESC
            LIMIT 20
        ");
        if ($ru) { while ($row = $ru->fetch_assoc()) $rated_users[] = $row; }
        ?>
        <?php if (!empty($rated_users)): ?>
        <section class="section" id="section-ratings">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon" style="background:rgba(255,184,0,.15);color:#FFB800;"><i class="fas fa-star"></i></div>
                    <h2>User Ratings</h2>
                </div>
                <span class="badge" style="background:rgba(255,184,0,.15);color:#FFB800;"><?= count($rated_users) ?> rated</span>
            </div>
            <div class="section-body">
                <div style="overflow-x:auto;">
                    <table class="rental-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Scooter</th>
                                <th>Rating</th>
                                <th>Review</th>
                                <th>Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rated_users as $ru): ?>
                            <tr>
                                <td style="font-weight:700;"><?= htmlspecialchars($ru['username']) ?></td>
                                <td><?= htmlspecialchars($ru['scooter_name'] ?? '—') ?></td>
                                <td>
                                    <span style="color:#FFB800;font-weight:700;">
                                        <?= str_repeat('★', (int)$ru['rating']) ?><?= str_repeat('☆', 5 - (int)$ru['rating']) ?>
                                        <?= number_format((float)$ru['rating'], 1) ?>
                                    </span>
                                </td>
                                <td style="color:#999;font-size:13px;max-width:220px;">
                                    <?= $ru['rating_review'] ? htmlspecialchars(mb_substr($ru['rating_review'], 0, 60)) . (mb_strlen($ru['rating_review']) > 60 ? '…' : '') : '<em>—</em>' ?>
                                </td>
                                <td style="color:#888;font-size:12px;white-space:nowrap;">
                                    <?= $ru['rated_at'] ? date('d M Y', strtotime($ru['rated_at'])) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<script>
// ── MOBILE MENU TOGGLE ──
const menuToggle  = document.getElementById('menuToggle');
const sidebar     = document.getElementById('sidebar');
const menuOverlay = document.getElementById('menuOverlay');

function closeMenu() {
    sidebar.classList.remove('open');
    menuOverlay.classList.remove('show');
    document.body.style.overflow = '';
}

function openMenu() {
    sidebar.classList.add('open');
    menuOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

menuToggle?.addEventListener('click', function(e) {
    e.stopPropagation();
    sidebar.classList.contains('open') ? closeMenu() : openMenu();
});

menuOverlay?.addEventListener('click', closeMenu);

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });

window.addEventListener('resize', () => {
    if (window.innerWidth > 768) closeMenu();
});

// ── TOGGLE RENTAL HISTORY ──
function toggleHistory() {
    const body  = document.getElementById('historyBody');
    const btn   = document.getElementById('historyToggle');
    const label = document.getElementById('historyLabel');
    const isHidden = body.classList.contains('hidden');

    body.classList.toggle('hidden', !isHidden);
    btn.classList.toggle('collapsed', isHidden);
    label.textContent = isHidden ? 'Hide History' : 'Show History';

    if (isHidden) {
        setTimeout(() => {
            document.getElementById('section-history')
                .scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 60);
    }
}

// ── SMOOTH SCROLL ──
function scrollTo(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── AUTO FADE NOTIFICATIONS ──
document.querySelectorAll('.notification').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 5500);
});
</script>

<!-- ═══════════════════════════════════════════════
     MODAL KTP / SIM (TANPA DOWNLOAD, ANTI SS)
════════════════════════════════════════════════ -->
<div id="idCardModal" class="idcard-modal" role="dialog" aria-modal="true" aria-label="Dokumen Identitas">
    <div class="idcard-modal-box" id="idcardModalBox">
        <div class="idcard-modal-header">
            <span id="idCardTitle" class="idcard-modal-title">
                <i class="fas fa-id-card"></i> Identitas Customer
            </span>
            <button class="idcard-close" onclick="closeIdCard()" aria-label="Tutup">&times;</button>
        </div>
        <div class="idcard-modal-body" id="idCardBody">
            <div class="loading-preview">
                <i class="fas fa-spinner fa-pulse"></i> Memuat data...
            </div>
        </div>
        <div class="idcard-modal-footer">
            <button onclick="closeIdCard()" class="btn-cl">Tutup</button>
        </div>
    </div>
</div>

<style>
.btn-id-card {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(255,107,0,.12); color: #FF6B00;
    border: 1px solid rgba(255,107,0,.35); border-radius: 7px;
    padding: 6px 14px; font-size: .82rem; font-weight: 600;
    cursor: pointer; font-family: inherit;
    transition: background .2s, color .2s;
    width: 100%; justify-content: center; margin-bottom: 4px;
}
.btn-id-card:hover { background: #FF6B00; color: #fff; border-color: #FF6B00; }
.no-id-card {
    display: flex; align-items: center; gap: 6px;
    font-size: .8rem; color: #e53935; padding: 4px 0; opacity: .85;
}
.idcard-modal {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.85); backdrop-filter: blur(8px);
    align-items: center; justify-content: center;
}
.idcard-modal.open { display: flex; }
.idcard-modal-box {
    background: #1a1a1a; border: 1px solid rgba(255,107,0,.4);
    border-radius: 20px; width: min(92vw, 680px);
    max-height: 85vh; display: flex; flex-direction: column;
    overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.6);
    animation: modalIn .25s ease;
    position: relative;
}
@keyframes modalIn {
    from { opacity:0; transform: scale(.94) translateY(12px); }
    to   { opacity:1; transform: scale(1) translateY(0); }
}
.idcard-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,.1);
    background: #0f0f0f;
}
.idcard-modal-title { font-size: .95rem; font-weight: 700; color: #fff; display: flex; gap: 8px; align-items: center; }
.idcard-modal-title i { color: #FF6B00; }
.idcard-close {
    background: none; border: none; color: #888; font-size: 1.5rem;
    cursor: pointer; line-height: 1; padding: 0 4px; transition: color .2s;
}
.idcard-close:hover { color: #fff; }
.idcard-modal-body {
    flex: 1; overflow-y: auto; padding: 20px;
    display: flex; align-items: center; justify-content: center; min-height: 300px;
    background: #111;
    position: relative;
}
.idcard-modal-body img {
    max-width: 100%; max-height: 55vh; border-radius: 8px; object-fit: contain;
    border: 1px solid rgba(255,255,255,.1);
    /* Proteksi: gambar tidak bisa di-drag */
    user-drag: none;
    -webkit-user-drag: none;
    pointer-events: none;
}
.idcard-modal-body iframe {
    width: 100%; height: 55vh; border: none; border-radius: 8px;
    background: #fff;
    pointer-events: none; /* Tidak bisa di-interaksi */
}
.idcard-modal-body .no-preview, 
.idcard-modal-body .loading-preview {
    text-align: center; color: #888; font-size: .9rem; line-height: 1.8;
}
.idcard-modal-body .no-preview i,
.idcard-modal-body .loading-preview i {
    font-size: 2.5rem; color: #FF6B00; display: block; margin-bottom: 10px;
}
.idcard-modal-footer {
    display: flex; gap: 10px; justify-content: flex-end;
    padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.1);
    background: #0f0f0f;
}
.btn-cl {
    background: rgba(255,107,0,.15); color: #FF6B00;
    border: 1px solid rgba(255,107,0,.4); border-radius: 8px;
    padding: 8px 20px; font-size: .82rem; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: background .2s;
}
.btn-cl:hover { background: #FF6B00; color: #fff; }

/* Overlay watermark untuk anti-screenshot (opsional) */
.idcard-modal-box::before {
    content: "CONFIDENTIAL";
    position: absolute;
    bottom: 80px;
    right: 20px;
    font-size: 10px;
    color: rgba(255,107,0,.3);
    transform: rotate(-15deg);
    pointer-events: none;
    z-index: 10;
    font-weight: bold;
    letter-spacing: 2px;
}
</style>

<script>
function showIdCard(path, type, customerName) {
    const modal = document.getElementById('idCardModal');
    const body  = document.getElementById('idCardBody');
    
    // Set judul dengan nama customer
    document.getElementById('idCardTitle').innerHTML = 
        `<i class="fas fa-id-card"></i> Identitas — ${escapeHtml(customerName)}`;
    
    // Tampilkan loading
    body.innerHTML = `<div class="loading-preview"><i class="fas fa-spinner fa-pulse"></i> Memuat dokumen...</div>`;
    
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    
    // Gunakan fetch untuk mengambil file melalui proxy (agar tidak bisa diakses langsung)
    // Atau gunakan blob URL untuk proteksi tambahan
    if (type === 'image') {
        // Load gambar via fetch -> blob -> objectURL (agar tidak bisa di-save via right click)
        fetch(path)
            .then(res => {
                if (!res.ok) throw new Error('Gagal memuat gambar');
                return res.blob();
            })
            .then(blob => {
                const url = URL.createObjectURL(blob);
                const img = document.createElement('img');
                img.src = url;
                img.alt = `Identitas ${customerName}`;
                img.style.maxWidth = '100%';
                img.style.maxHeight = '55vh';
                img.style.borderRadius = '8px';
                img.style.objectFit = 'contain';
                img.style.pointerEvents = 'none'; // tidak bisa di-klik kanan
                img.style.userSelect = 'none';
                img.setAttribute('draggable', 'false');
                
                // Hapus loading, tampilkan gambar
                body.innerHTML = '';
                body.appendChild(img);
                
                // Revoke URL setelah gambar selesai dimuat untuk mencegah akses ulang
                img.onload = () => URL.revokeObjectURL(url);
            })
            .catch(err => {
                body.innerHTML = `<div class="no-preview">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Dokumen tidak dapat ditampilkan.<br>Format mungkin tidak didukung atau file rusak.</p>
                </div>`;
            });
    } else {
        // Untuk PDF, tampilkan via iframe dengan proteksi
        // Gunakan blob URL juga
        fetch(path)
            .then(res => {
                if (!res.ok) throw new Error('Gagal memuat PDF');
                return res.blob();
            })
            .then(blob => {
                const url = URL.createObjectURL(blob);
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.style.width = '100%';
                iframe.style.height = '55vh';
                iframe.style.border = 'none';
                iframe.style.borderRadius = '8px';
                iframe.style.pointerEvents = 'none'; // tidak bisa di-interaksi
                
                body.innerHTML = '';
                body.appendChild(iframe);
                
                // Revoke URL setelah iframe selesai (delay)
                setTimeout(() => URL.revokeObjectURL(url), 5000);
            })
            .catch(err => {
                body.innerHTML = `<div class="no-preview">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Dokumen tidak dapat ditampilkan.<br>Format mungkin tidak didukung atau file rusak.</p>
                </div>`;
            });
    }
}

function closeIdCard() {
    const modal = document.getElementById('idCardModal');
    const body = document.getElementById('idCardBody');
    modal.classList.remove('open');
    body.innerHTML = '<div class="loading-preview"><i class="fas fa-spinner fa-pulse"></i> Memuat data...</div>';
    document.body.style.overflow = '';
}

// Escape HTML untuk keamanan
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
}

// Proteksi global: disable right click di dalam modal
document.getElementById('idCardModal')?.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    return false;
});

// Proteksi: disable keyboard shortcut untuk screenshot (Ctrl+S, Ctrl+P, PrintScreen)
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('idCardModal');
    if (modal && modal.classList.contains('open')) {
        // Disable Ctrl+S, Ctrl+P, PrintScreen
        if ((e.ctrlKey && (e.key === 's' || e.key === 'p')) || e.key === 'PrintScreen') {
            e.preventDefault();
            // Optional: tampilkan peringatan
            Swal.fire({
                icon: 'warning',
                title: 'Tidak Diizinkan',
                text: 'Screenshot / download dokumen identitas tidak diperbolehkan.',
                confirmButtonColor: '#FF6B00',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
            return false;
        }
    }
});

// Tutup modal dengan ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeIdCard(); });

// Klik overlay untuk tutup
document.getElementById('idCardModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeIdCard();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function swalConfirm(e, title, text, icon, confirmText, href) {
    e.preventDefault();
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Batal',
        confirmButtonColor: icon === 'warning' ? '#e53935'
                          : icon === 'success' ? '#2e7d32'
                          : '#1565c0',
        cancelButtonColor: '#78909c',
        reverseButtons: true,
        focusCancel: true,
        customClass: { popup: 'swal-admin-popup' }
    }).then(r => { if (r.isConfirmed) window.location.href = href; });
    return false;
}
// Versi form POST: submit form hanya jika dikonfirmasi (hindari token di URL)
function swalConfirmForm(e, title, text, icon, confirmText, form) {
    e.preventDefault();
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Batal',
        confirmButtonColor: icon === 'warning' ? '#e53935'
                          : icon === 'success' ? '#2e7d32'
                          : '#1565c0',
        cancelButtonColor: '#78909c',
        reverseButtons: true,
        focusCancel: true,
        customClass: { popup: 'swal-admin-popup' }
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}
</script>
</body>
</html>