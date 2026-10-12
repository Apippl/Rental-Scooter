<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Filter by status (whitelist) + rentang tanggal
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$has_filter = $status_filter && in_array($status_filter, ['pending', 'active', 'completed', 'cancelled'], true);
if (!$has_filter) $status_filter = '';

$date_from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$date_to   = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';

$where = [];
$types = '';
$params = [];
if ($has_filter) { $where[] = "r.status = ?"; $types .= 's'; $params[] = $status_filter; }
if ($date_from !== '') { $where[] = "DATE(r.created_at) >= ?"; $types .= 's'; $params[] = $date_from; }
if ($date_to !== '') { $where[] = "DATE(r.created_at) <= ?"; $types .= 's'; $params[] = $date_to; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Export CSV (semua data sesuai filter, tanpa pagination)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $eq = "SELECT r.rental_id, s.name AS scooter, u.username AS customer, r.start_date, r.end_date,
                   r.total, r.late_days, r.late_fee, r.status, r.created_at
            FROM rentals r
            JOIN scooters s ON r.scooter_id = s.scooter_id
            LEFT JOIN users u ON r.user_id = u.user_id
            $where_sql ORDER BY r.created_at DESC";
    $es = $conn->prepare($eq);
    if ($params) $es->bind_param($types, ...$params);
    $es->execute();
    $er = $es->get_result();
    admin_log("export rentals csv (filter: status=$status_filter from=$date_from to=$date_to)");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rentals_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Scooter', 'Customer', 'Start', 'End', 'Total', 'LateDays', 'LateFee', 'Status', 'Created']);
    while ($erow = $er->fetch_assoc()) fputcsv($out, $erow);
    fclose($out);
    $es->close();
    exit;
}

// Count total
$cq = "SELECT COUNT(*) as total FROM rentals r $where_sql";
$cs = $conn->prepare($cq);
if ($params) $cs->bind_param($types, ...$params);
$cs->execute();
$total_rows = (int)$cs->get_result()->fetch_assoc()['total'];
$cs->close();
$total_pages = $limit > 0 ? (int)ceil($total_rows / $limit) : 0;

// Get rentals (LIMIT/OFFSET sudah cast int, aman interpolasi)
$rentals = [];
$gq = "
    SELECT r.*, s.name AS scooter_name, u.username AS customer_name
    FROM rentals r
    JOIN scooters s ON r.scooter_id = s.scooter_id
    LEFT JOIN users u ON r.user_id = u.user_id
    $where_sql
    ORDER BY r.created_at DESC
    LIMIT $limit OFFSET $offset
";
$qs = $conn->prepare($gq);
if ($params) $qs->bind_param($types, ...$params);
$qs->execute();
$r = $qs->get_result();
while ($row = $r->fetch_assoc()) $rentals[] = $row;
$qs->close();

function rentalDays(string $start, string $end): int {
    return max(1, (int)round((strtotime($end) - strtotime($start)) / 86400) + 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Rental History â€” Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../css/adminhistory.css?v=20261007d">
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
    <aside class="sidebar" id="sidebar">
        <div class="sb-label">Main</div>
        <a href="admin.php" class="sb-link">
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
        <a href="admin_history.php" class="sb-link active">
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

    <main class="main-content">
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
            <h1>RENTAL <span>HISTORY</span></h1>
            <p class="page-subtitle"><?= date('l, d F Y') ?> &nbsp;Â·&nbsp; <?= number_format($total_rows) ?> records<?= $status_filter ? ' Â· Filter: ' . htmlspecialchars(ucfirst($status_filter)) : '' ?></p>
        </div>

        <section class="section">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-gray"><i class="fas fa-history"></i></div>
                    <h2>All Rentals</h2>
                </div>
                <span class="badge badge-gray"><?= number_format($total_rows) ?> total</span>
            </div>
            <div class="section-body">
                <!-- Filter Buttons -->
                <div class="filter-bar">
                    <?php $qdate = ($date_from !== '' || $date_to !== '') ? ('&from=' . urlencode($date_from) . '&to=' . urlencode($date_to)) : ''; ?>
                    <a href="admin_history.php?status=<?= $qdate ?>" class="filter-btn <?= $status_filter == '' ? 'active' : '' ?>">All</a>
                    <a href="admin_history.php?status=pending<?= $qdate ?>" class="filter-btn <?= $status_filter == 'pending' ? 'active' : '' ?>">Pending</a>
                    <a href="admin_history.php?status=active<?= $qdate ?>" class="filter-btn <?= $status_filter == 'active' ? 'active' : '' ?>">Active</a>
                    <a href="admin_history.php?status=completed<?= $qdate ?>" class="filter-btn <?= $status_filter == 'completed' ? 'active' : '' ?>">Completed</a>
                    <a href="admin_history.php?status=cancelled<?= $qdate ?>" class="filter-btn <?= $status_filter == 'cancelled' ? 'active' : '' ?>">Cancelled</a>
                </div>
                <!-- Filter tanggal + Export CSV -->
                <form method="get" class="filter-bar" style="align-items:flex-end;">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                    <label style="font-size:12px;font-weight:700;">Dari
                        <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" style="display:block;padding:7px 10px;border:1.5px solid var(--gray-lt);border-radius:8px;margin-top:4px;">
                    </label>
                    <label style="font-size:12px;font-weight:700;">Sampai
                        <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" style="display:block;padding:7px 10px;border:1.5px solid var(--gray-lt);border-radius:8px;margin-top:4px;">
                    </label>
                    <button type="submit" class="filter-btn active" style="border:none;cursor:pointer;">Filter</button>
                    <a class="filter-btn" href="admin_history.php?status=<?= urlencode($status_filter) ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>&export=csv"><i class="fas fa-download"></i> CSV</a>
                    <?php if ($date_from !== '' || $date_to !== ''): ?>
                    <a class="filter-btn" href="admin_history.php?status=<?= urlencode($status_filter) ?>">Reset tanggal</a>
                    <?php endif; ?>
                </form>

        <?php if (!empty($rentals)): ?>
        <div class="table-wrapper">
            <!-- DESKTOP TABLE -->
            <table class="rental-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Scooter</th>
                        <th>Customer</th>
                        <th>Period</th>
                        <th>Days</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rentals as $r): ?>
                    <tr>
                        <td style="font-weight:700;">#<?= (int)$r['rental_id'] ?></td>
                        <td><strong><?= htmlspecialchars($r['scooter_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['customer_name'] ?: 'Guest') ?></td>
                        <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($r['start_date'])) ?> â†’ <?= date('d/m/Y', strtotime($r['end_date'])) ?></td>
                        <td style="text-align:center;"><?= rentalDays($r['start_date'], $r['end_date']) ?>d</td>
                        <td>Rp <?= number_format($r['total'], 0, ',', '.') ?></td>
                        <td><span class="sp sp-<?= strtolower($r['status']) ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- MOBILE CARD VIEW -->
            <?php foreach ($rentals as $r): ?>
            <div class="mobile-rental-card">
                <div class="mobile-card-header">
                    <span class="mobile-card-id">#<?= (int)$r['rental_id'] ?></span>
                    <span class="sp sp-<?= strtolower($r['status']) ?>"><?= ucfirst($r['status']) ?></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Scooter</span>
                    <span class="mobile-card-value"><strong><?= htmlspecialchars($r['scooter_name']) ?></strong></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Customer</span>
                    <span class="mobile-card-value"><?= htmlspecialchars($r['customer_name'] ?: 'Guest') ?></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Period</span>
                    <span class="mobile-card-value"><?= date('d M Y', strtotime($r['start_date'])) ?> â†’ <?= date('d M Y', strtotime($r['end_date'])) ?></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Duration</span>
                    <span class="mobile-card-value"><strong><?= rentalDays($r['start_date'], $r['end_date']) ?> days</strong></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Total</span>
                    <span class="mobile-card-value"><strong>Rp <?= number_format($r['total'], 0, ',', '.') ?></strong></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Created</span>
                    <span class="mobile-card-value"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&status=<?= urlencode($status_filter) ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>">Â« Prev</a>
            <?php endif; ?>

            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&status=<?= urlencode($status_filter) ?>&from=<?= urlencode($date_from) ?>&to=<?= urlencode($date_to) ?>">Next Â»</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-history"></i></div>
            <p>No rental records found.</p>
        </div>
        <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
// Mobile menu toggle (sama admin.css)
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('menuOverlay');

function closeMenu() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

function openMenu() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

if (menuToggle) {
    menuToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        if (sidebar.classList.contains('open')) {
            closeMenu();
        } else {
            openMenu();
        }
    });
}

if (overlay) {
    overlay.addEventListener('click', closeMenu);
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });

window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        closeMenu();
    }
});

// Auto fade notifications (sama admin.css)
document.querySelectorAll('.notification').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    }, 5500);
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
</script>
</body>
</html>