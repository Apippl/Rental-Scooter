<?php
// admin_users.php — Kelola user: blokir/buka + reset password
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// ── Aksi POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $_SESSION['error'] = "Invalid security token!";
        header("Location: admin_users.php");
        exit;
    }
    $uid = (int)($_POST['user_id'] ?? 0);
    $act = $_POST['do'] ?? '';
    // Jangan bisa memblokir diri sendiri / sesama admin
    $t = $conn->prepare("SELECT user_id, username, role FROM users WHERE user_id = ?");
    $t->bind_param("i", $uid);
    $t->execute();
    $target = $t->get_result()->fetch_assoc();
    $t->close();

    if (!$target) {
        $_SESSION['error'] = "User not found!";
    } elseif ($target['role'] === 'admin') {
        $_SESSION['error'] = "Cannot modify another admin.";
    } elseif ($uid === (int)$_SESSION['user_id']) {
        $_SESSION['error'] = "Cannot modify your own account.";
    } elseif ($act === 'block') {
        $b = $conn->prepare("UPDATE users SET is_blocked = 1, blocked_at = NOW() WHERE user_id = ?");
        $b->bind_param("i", $uid); $b->execute(); $b->close();
        admin_log("block user #$uid ({$target['username']})");
        $_SESSION['success'] = "User '{$target['username']}' blocked.";
    } elseif ($act === 'unblock') {
        $b = $conn->prepare("UPDATE users SET is_blocked = 0, blocked_at = NULL WHERE user_id = ?");
        $b->bind_param("i", $uid); $b->execute(); $b->close();
        admin_log("unblock user #$uid ({$target['username']})");
        $_SESSION['success'] = "User '{$target['username']}' unblocked.";
    } elseif ($act === 'resetpw') {
        // Password acak 12 karakter alnum (71-bit entropy) — jauh lebih kuat dari hex 10-char.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $new = '';
        for ($i = 0; $i < 12; $i++) $new .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $b = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $b->bind_param("si", $hash, $uid); $b->execute(); $b->close();
        // Hapuskan semua token reset user ini agar tidak bisa dipakai lagi
        $d = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $d->bind_param("i", $uid); $d->execute(); $d->close();
        admin_log("reset password user #$uid ({$target['username']})");
        $_SESSION['success'] = "Password '{$target['username']}' direset. Password baru (catat & sampaikan aman, minta user segera ganti): " . $new;
    }
    header("Location: admin_users.php");
    exit;
}

// ── Daftar user + statistik ──
$users = [];
$q = $conn->query("
    SELECT u.user_id, u.username, u.email, u.role, u.created_at, u.is_blocked,
           COUNT(DISTINCT r.rental_id) AS total_rentals,
           ROUND(AVG(rt.rating),1) AS avg_rating
    FROM users u
    LEFT JOIN rentals r ON r.user_id = u.user_id
    LEFT JOIN ratings rt ON rt.user_id = u.user_id
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
if ($q) { while ($row = $q->fetch_assoc()) $users[] = $row; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Manage Users — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/adminhistory.css?v=20261007c">
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
        <a href="admin.php" class="sb-link"><i class="fas fa-th-large"></i> Dashboard</a>
        <div class="sb-label">Management</div>
        <a href="admin_add.php" class="sb-link"><i class="fas fa-plus-circle"></i> Add Scooter</a>
        <a href="admin_manage.php" class="sb-link"><i class="fas fa-screwdriver-wrench"></i> Manage Scooters</a>
        <a href="admin_users.php" class="sb-link active"><i class="fas fa-users"></i> Manage Users</a>
        <div class="sb-label">Reports</div>
        <a href="admin_history.php" class="sb-link"><i class="fas fa-history"></i> Rental History</a>
        <div class="sb-label">Site</div>
        <a href="../user/dashboard.php" class="sb-link" target="_blank"><i class="fas fa-external-link-alt"></i> View Site</a>
        <a href="../user/logout.php" class="sb-link danger" onclick="return swalConfirm(event,'Logout?','Kamu akan keluar dari Admin Panel.','warning','Ya, Logout',this.href)">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>

    <div class="menu-overlay" id="menuOverlay"></div>

    <main class="main-content">
        <?php if (isset($_SESSION['error'])): ?>
        <div class="notification error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
        <div class="notification success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="page-title">
            <h1>MANAGE <span>USERS</span></h1>
            <p class="page-subtitle"><?= date('l, d F Y') ?> &nbsp;·&nbsp; <?= number_format(count($users)) ?> users</p>
        </div>

        <section class="section">
            <div class="section-header">
                <div class="sec-title">
                    <div class="sec-icon si2-orange"><i class="fas fa-users"></i></div>
                    <h2>All Users</h2>
                </div>
                <span class="badge badge-gray"><?= count($users) ?> total</span>
            </div>
            <div class="section-body">
                <?php if (!empty($users)): ?>
                <div class="table-wrapper">
                    <table class="rental-table">
                        <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Sewa</th><th>Rating</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $usr): ?>
                            <tr>
                                <td style="font-weight:700;"><?= htmlspecialchars($usr['username']) ?></td>
                                <td><?= htmlspecialchars($usr['email'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($usr['role']) ?></td>
                                <td style="text-align:center;"><?= (int)$usr['total_rentals'] ?></td>
                                <td><?= $usr['avg_rating'] ? number_format((float)$usr['avg_rating'], 1) . ' ⭐' : '—' ?></td>
                                <td><?= $usr['is_blocked'] ? '<span class="sp sp-cancelled">Blocked</span>' : '<span class="sp sp-completed">Active</span>' ?></td>
                                <td style="white-space:nowrap;">
                                    <?php if ($usr['role'] !== 'admin' && (int)$usr['user_id'] !== (int)$_SESSION['user_id']): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return swalConfirmForm(event,'<?= $usr['is_blocked'] ? 'Buka blokir?' : 'Blokir user?' ?>','<?= htmlspecialchars($usr['username'], ENT_QUOTES) ?> <?= $usr['is_blocked'] ? 'bisa login kembali.' : 'tidak bisa login sampai dibuka.' ?>','warning','Ya, Lanjut',this)">
                                        <input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>">
                                        <input type="hidden" name="do" value="<?= $usr['is_blocked'] ? 'unblock' : 'block' ?>">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="filter-btn"><?= $usr['is_blocked'] ? 'Unblock' : 'Block' ?></button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return swalConfirmForm(event,'Reset password?','Password <?= htmlspecialchars($usr['username'], ENT_QUOTES) ?> akan diganti acak.','question','Ya, Reset',this)">
                                        <input type="hidden" name="user_id" value="<?= (int)$usr['user_id'] ?>">
                                        <input type="hidden" name="do" value="resetpw">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="filter-btn">Reset PW</button>
                                    </form>
                                    <?php else: ?><em style="color:#999;font-size:12px;">—</em><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><p>No users found.</p></div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('menuOverlay');
function closeMenu() { sidebar.classList.remove('open'); overlay.classList.remove('show'); document.body.style.overflow = ''; }
function openMenu() { sidebar.classList.add('open'); overlay.classList.add('show'); document.body.style.overflow = 'hidden'; }
menuToggle?.addEventListener('click', e => { e.stopPropagation(); sidebar.classList.contains('open') ? closeMenu() : openMenu(); });
overlay?.addEventListener('click', closeMenu);
document.querySelectorAll('.notification').forEach(el => { setTimeout(() => { el.style.transition = 'opacity .5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5500); });
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function swalConfirm(e, title, text, icon, confirmText, href) {
    e.preventDefault();
    Swal.fire({ title, text, icon, showCancelButton: true, confirmButtonText: confirmText, cancelButtonText: 'Batal',
        confirmButtonColor: icon === 'warning' ? '#e53935' : icon === 'success' ? '#2e7d32' : '#1565c0',
        cancelButtonColor: '#78909c', reverseButtons: true, focusCancel: true, customClass: { popup: 'swal-admin-popup' }
    }).then(r => { if (r.isConfirmed) window.location.href = href; });
    return false;
}
function swalConfirmForm(e, title, text, icon, confirmText, form) {
    e.preventDefault();
    Swal.fire({ title, text, icon, showCancelButton: true, confirmButtonText: confirmText, cancelButtonText: 'Batal',
        confirmButtonColor: icon === 'warning' ? '#e53935' : icon === 'success' ? '#2e7d32' : '#1565c0',
        cancelButtonColor: '#78909c', reverseButtons: true, focusCancel: true, customClass: { popup: 'swal-admin-popup' }
    }).then(r => { if (r.isConfirmed) form.submit(); });
    return false;
}
</script>
</body>
</html>
