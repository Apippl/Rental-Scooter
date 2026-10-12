<?php
// ================================================================
// BUG FIX 1: display_errors HARUS paling atas sebelum output
// ================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);
 
// ================================================================
// SESSION â€” sebelum require DB
// ================================================================
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

require "../config/db.php";
 
// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
 
$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');
 
// ================================================================
// QUERY SCOOTERS + statistik rental per motor
// ================================================================
$scooters = $conn->query("
    SELECT s.*,
           COUNT(r.rental_id) AS total_rentals,
           SUM(CASE WHEN r.status IN ('active','pending') THEN 1 ELSE 0 END) AS active_rentals,
           SUM(CASE WHEN r.status = 'completed' THEN r.total ELSE 0 END) AS total_revenue
    FROM scooters s
    LEFT JOIN rentals r ON s.scooter_id = r.scooter_id
    GROUP BY s.scooter_id
    ORDER BY s.created_at DESC
");
 
$total_scooters   = 0;
$available_count  = 0;
$rented_count     = 0;
$rows = [];
 
if ($scooters) {
    while ($row = $scooters->fetch_assoc()) {
        $rows[] = $row;
        $total_scooters++;
        if ($row['status'] === 'available')  $available_count++;
        if ($row['status'] === 'rented')     $rented_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Scooters â€” Admin Dashboard</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../css/adminmanage.css?v=20261007d">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
 
<!-- ===== HEADER â€” identik dashboard ===== -->
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
 
    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar" id="sidebar">
        <div class="sb-label">Main</div>
        <a href="admin.php" class="sb-link"><i class="fas fa-th-large"></i> Dashboard</a>

        <div class="sb-label">Management</div>
        <a href="admin_add.php" class="sb-link"><i class="fas fa-plus-circle"></i> Add Scooter</a>
        <a href="admin_manage.php" class="sb-link active"><i class="fas fa-screwdriver-wrench"></i> Manage Scooters</a>
        <a href="admin_users.php" class="sb-link"><i class="fas fa-users"></i> Manage Users</a>

        <div class="sb-label">Reports</div>
        <a href="admin_history.php" class="sb-link"><i class="fas fa-history"></i> Rental History</a>

        <div class="sb-label">Site</div>
        <a href="../user/dashboard.php" class="sb-link" target="_blank"><i class="fas fa-external-link-alt"></i> View Site</a>
        <a href="../user/logout.php" class="sb-link danger" onclick="return swalConfirm(event,'Logout?','Kamu akan keluar dari Admin Panel.','warning','Ya, Logout',this.href)">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>
 
    <div class="menu-overlay" id="menuOverlay"></div>
 
    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">
 
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>MANAGE <span>SCOOTERS</span></h1>
                <p class="page-subtitle"><?= date('l, d F Y') ?></p> <!-- HANYA TANGGAL, TANPA TOTAL SKUTER -->
            </div>
        </div>
 
        <!-- Summary Stats -->
        <div class="summary-row">
            <div class="summary-card">
                <div class="summary-icon si-orange"><i class="fas fa-motorcycle"></i></div>
                <div class="summary-label">Total</div>
                <div class="summary-value"><?= $total_scooters ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon si-green"><i class="fas fa-check-circle"></i></div>
                <div class="summary-label">Available</div>
                <div class="summary-value"><?= $available_count ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon si-amber"><i class="fas fa-key"></i></div>
                <div class="summary-label">Rented</div>
                <div class="summary-value"><?= $rented_count ?></div>
            </div>
        </div>
 
        <!-- Scooters Grid -->
        <div class="scooters-grid">
        <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $s):
                $isElec    = strtolower($s['type'] ?? '') === 'electric';
                $hasActive = (int)($s['active_rentals'] ?? 0) > 0;
                $status    = strtolower($s['status'] ?? 'unavailable');
            ?>
            <div class="scooter-card">
                <div class="card-body">
                    <div class="card-head">
                        <div>
                            <div class="card-name"><?= htmlspecialchars($s['name'] ?? '') ?></div>
                            <div class="card-id">ID #<?= (int)$s['scooter_id'] ?></div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
                            <span class="sp sp-<?= htmlspecialchars($status) ?>">
                                <?= ucfirst(htmlspecialchars($status)) ?>
                            </span>
                            <span class="type-pill <?= $isElec ? 'electric' : '' ?>">
                                <i class="fas <?= $isElec ? 'fa-bolt' : 'fa-gas-pump' ?>"></i>
                                <?= $isElec ? 'Electric' : 'Gasoline' ?>
                            </span>
                        </div>
                    </div>
 
                    <div class="card-details">
                        <div class="detail-row">
                            <div class="label"><i class="fas fa-tag"></i> Brand</div>
                            <strong><?= htmlspecialchars(ucfirst($s['brand'] ?? '')) ?></strong>
                        </div>
                        <div class="detail-row">
                            <div class="label"><i class="fas fa-money-bill-wave"></i> Price</div>
                            <strong>Rp <?= number_format((float)($s['price'] ?? 0), 0, ',', '.') ?>/day</strong>
                        </div>
                        <div class="detail-row">
                            <div class="label"><i class="fas fa-history"></i> Total Rentals</div>
                            <strong><?= (int)($s['total_rentals'] ?? 0) ?></strong>
                        </div>
                        <div class="detail-row">
                            <div class="label"><i class="fas fa-key"></i> Active Now</div>
                            <strong style="color:<?= $hasActive ? 'var(--amber)' : 'var(--green)' ?>">
                                <?= (int)($s['active_rentals'] ?? 0) ?>
                            </strong>
                        </div>
                        <div class="detail-row">
                            <div class="label"><i class="fas fa-wallet"></i> Revenue</div>
                            <strong>Rp <?= number_format((float)($s['total_revenue'] ?? 0), 0, ',', '.') ?></strong>
                        </div>
                    </div>
 
                    <div class="card-actions">
                        <?php if (!$hasActive):
                            $sName = htmlspecialchars($s['name'] ?? '', ENT_QUOTES);
                            $sId   = (int)$s['scooter_id'];
                        ?>
                            <form method="POST" action="admin_delete.php" style="display:inline;" onsubmit="return swalConfirmForm(event,'Hapus Skuter?','<?= $sName ?> akan dihapus permanen dan tidak bisa dikembalikan.','warning','Ya, Hapus',this)">
                                <input type="hidden" name="id" value="<?= $sId ?>">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn btn-delete">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-locked" disabled>
                                <i class="fas fa-lock"></i> Has Active Rentals
                            </button>
                        <?php endif; ?>
 
                        <a href="admin_edit.php?action=edit&id=<?= (int)$s['scooter_id'] ?>" class="btn btn-edit">
                            <i class="fas fa-edit"></i>
                            <span>Edit</span>
                        </a>
                    </div>
                </div>
 
            </div>
            <?php endforeach; ?>
 
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-scooter"></i></div>
                <h3>No Scooters Found</h3>
                <p>Belum ada scooter. Tambahkan melalui menu <strong>Add Scooter</strong> di sidebar.</p>
            </div>
        <?php endif; ?>
        </div>
 
    </main>
</div>
 
<script>
// â”€â”€ MOBILE MENU â”€â”€
const menuToggle  = document.getElementById('menuToggle');
const sidebar     = document.getElementById('sidebar');
const menuOverlay = document.getElementById('menuOverlay');
 
function closeMenu() {
    sidebar.classList.remove('open');
    menuOverlay.classList.remove('show');
    document.body.style.overflow = '';
    menuToggle.querySelector('i').className = 'fas fa-bars';
}
 
function openMenu() {
    sidebar.classList.add('open');
    menuOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    menuToggle.querySelector('i').className = 'fas fa-times';
}
 
menuToggle?.addEventListener('click', e => {
    e.stopPropagation();
    sidebar.classList.contains('open') ? closeMenu() : openMenu();
});
 
menuOverlay?.addEventListener('click', closeMenu);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });
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