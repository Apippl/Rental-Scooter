<?php
// ========================================================
// BUG FIX 1: display_errors HARUS paling atas, sebelum
//            include apapun supaya error tidak bocor ke user.
// ========================================================
ini_set('display_errors', 0);   // MATIKAN di production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);         // Tetap catat semua error ke log

// ========================================================
// BUG FIX 2: session_start() HARUS sebelum include DB.
//            File asli: include dulu, baru session_start()
//            — itu salah urutan, bisa konflik header.
// ========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // 0 untuk XAMPP, 1 untuk production HTTPS
ini_set('session.cookie_samesite', 'Lax');

session_start();

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// ========================================================
// BUG FIX 3: include → require supaya script berhenti
//            jika file DB tidak ada, bukan error diam.
// ========================================================
require "../config/db.php";

// Global rating untuk ditampilkan di login page
$idx_fr = $conn->query("SELECT ROUND(AVG(rating),1) AS avg_r, COUNT(*) AS total_r FROM ratings");
$idx_rating = $idx_fr ? $idx_fr->fetch_assoc() : ['avg_r' => 0, 'total_r' => 0];
$idx_avg    = (float)($idx_rating['avg_r']   ?? 0);
$idx_total  = (int)($idx_rating['total_r'] ?? 0);

// CSRF Token — generate sekali per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Inisialisasi rate limiting
$_SESSION['login_attempts']    = $_SESSION['login_attempts']    ?? 0;
$_SESSION['first_attempt_time'] = $_SESSION['first_attempt_time'] ?? time();

// Ambil pesan sukses dari session (dari register redirect)
$error   = '';
$success = '';
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// ========================================================
// PROSES LOGIN
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── BUG FIX 4: Reset rate limit SEBELUM dicek, supaya user
    //               langsung bisa lanjut tanpa harus submit ulang.
    if ($_SESSION['login_attempts'] >= 5 &&
        (time() - $_SESSION['first_attempt_time']) >= 300) {
        $_SESSION['login_attempts']     = 0;
        $_SESSION['first_attempt_time'] = time();
    }

    // ── BUG FIX 5: Rate limit dicek DULUAN (sebelum CSRF), supaya
    //               bot yang spam tanpa token tetap kena blokir.
    if ($_SESSION['login_attempts'] >= 5) {
        $remaining = 300 - (time() - $_SESSION['first_attempt_time']);
        $minutes   = max(1, ceil($remaining / 60));
        $error = "Too many login attempts. Please try again in $minutes minutes.";

    // ── CSRF TOKEN CHECK
    } elseif (!isset($_POST['csrf_token']) ||
              !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        // Counter tetap naik supaya bot tidak bisa bypass lewat CSRF spam
        $_SESSION['login_attempts']++;
        $error = "Security token invalid. Please refresh the page.";
        error_log("CSRF mismatch login: IP " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // ── VALIDASI INPUT KOSONG
    } elseif (empty(trim($_POST['username'] ?? '')) || empty($_POST['password'] ?? '')) {
        $_SESSION['login_attempts']++;
        $error = "Please fill in all fields.";

    } else {
        $username_input  = trim($_POST['username']);
        $password_raw    = $_POST['password'];

        // ── BUG FIX 6: Normalize ke lowercase agar cocok dengan cara
        //               register.php menyimpan username (lowercase).
        $username_normalized = strtolower($username_input);

        // Query langsung pakai kolom username (sudah lowercase di DB)
        $stmt = $conn->prepare("SELECT user_id, username, role, password, is_blocked FROM users WHERE username = ?");
        if ($stmt === false) {
            error_log("Prepare failed (login): " . $conn->error);
            $error = "System error. Please try again later.";
        } else {
            $stmt->bind_param("s", $username_normalized);
            $stmt->execute();
            $res  = $stmt->get_result();
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user) {
                if (!empty($user['is_blocked'])) {
                    $error = "This account has been blocked. Please contact admin.";
                    $_SESSION['login_attempts']++;
                } else {
                $authenticated = false;
                $needs_rehash  = false;

                // ── DUKUNGAN MIGRASI: MD5 lama → password_hash baru
                if (strlen($user['password']) === 32 && ctype_xdigit($user['password'])) {
                    // Legacy MD5
                    if (md5($password_raw) === $user['password']) {
                        $authenticated = true;
                        $needs_rehash  = true;
                    }
                } elseif (password_verify($password_raw, $user['password'])) {
                    $authenticated = true;
                    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                        $needs_rehash = true;
                    }
                }

                if ($authenticated) {
                    // ── AUTO-UPGRADE hash jika MD5 atau algoritma lama
                    if ($needs_rehash) {
                        $new_hash = password_hash($password_raw, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        if ($upd) {
                            $upd->bind_param("si", $new_hash, $user['user_id']);
                            $upd->execute();
                            $upd->close();
                            error_log("Password hash upgraded for user_id: " . $user['user_id']);
                        }
                    }

                    // ── BUG FIX 7: session_regenerate_id SEBELUM set session vars,
                    //               bukan sesudah — supaya nilai tersimpan di ID baru.
                    session_regenerate_id(true);

                    $_SESSION['login_attempts']  = 0;
                    $_SESSION['user_id']         = $user['user_id'];
                    $_SESSION['role']            = $user['role'];
                    $_SESSION['username']        = $user['username'];
                    $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

                    if ($user['role'] === 'admin') {
                        header("Location: ../admin/admin.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit;

                } else {
                    // Pesan error sama untuk username tidak ada & password salah
                    // (mencegah user enumeration)
                    $error = "Invalid username or password.";
                    $_SESSION['login_attempts']++;
                }
                } // end else (not blocked)
            } else {
                $error = "Invalid username or password.";
                $_SESSION['login_attempts']++;
            }
        }
    }
}

// Cleanup pasif: reset counter jika sudah >1 jam tidak ada aktivitas
if ((time() - $_SESSION['first_attempt_time']) > 3600) {
    $_SESSION['login_attempts']     = 0;
    $_SESSION['first_attempt_time'] = time();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Scooter Rental</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <link rel="stylesheet" href="../css/index.css">
    <style>
    .login-global-rating {
        display: flex; align-items: center; justify-content: center;
        gap: 10px; margin-top: 20px; padding: 12px 20px;
        background: rgba(255, 107, 0, 0.08);
        border: 1px solid rgba(255, 107, 0, 0.2);
        border-radius: 40px; flex-wrap: wrap;
    }
    .login-rating-stars { display: flex; gap: 3px; color: #FFB800; font-size: 1rem; }
    .login-rating-text { font-family: 'DM Sans', sans-serif; font-size: 0.88rem; color: #888; }
    .login-rating-text strong { color: #FFB800; font-weight: 700; }
    </style>
</head>

<body>

<div class="page-wrapper">

  <!-- LEFT PANEL -->
  <div class="left-panel">
    <div class="speed-lines">
      <div class="speed-line"></div>
      <div class="speed-line"></div>
      <div class="speed-line"></div>
      <div class="speed-line"></div>
    </div>

    <div class="brand-tag">
      <i class="fas fa-scooter"></i>
      Scooter Rental
    </div>

    <h2>
      WELCOME<br>
      <span>BACK</span>
      RIDER
    </h2>

    <p class="tagline">Your next adventure is just one login away. Hop back in and start riding.</p>

   <div class="stats-row">
  <div class="stat-item">
    <div class="stat-number">200+</div>
    <div class="stat-label">Locations</div>
  </div>
  <div class="stat-item">
    <div class="stat-number">5K+</div>
    <div class="stat-label">Riders</div>
  </div>
  <div class="stat-item">
    <div class="stat-number">
      <?php if ($idx_total > 0): ?>
        <?= number_format($idx_avg, 1) ?>★
      <?php else: ?>
        ?
      <?php endif; ?>
    </div>
    <div class="stat-label">
      <?php if ($idx_total > 0): ?>
        Rating <span style="font-size: 10px; opacity: 0.7;">(<?= number_format($idx_total) ?>)</span>
      <?php else: ?>
        Rating
      <?php endif; ?>
    </div>
  </div>
</div>

    <div class="left-bottom">
      © 2025 Scooter Rental. All rights reserved.
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right-panel">
    <div class="form-container">

      <div class="form-header">
        <div class="step-label">Member Area</div>
        <h1>LOGIN</h1>
        <p>Enter your credentials to continue.</p>
      </div>

      <!-- Messages -->
      <?php if (!empty($success)): ?>
        <div class="msg success">
          <i class="fas fa-check-circle"></i>
          <span><?= htmlspecialchars($success); ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="msg error">
          <i class="fas fa-exclamation-circle"></i>
          <span><?= htmlspecialchars($error); ?></span>
        </div>
      <?php endif; ?>

      <?php if ($_SESSION['login_attempts'] >= 3 && $_SESSION['login_attempts'] < 5): ?>
        <div class="msg warning">
          <i class="fas fa-exclamation-triangle"></i>
          <span>Warning: <?= (5 - $_SESSION['login_attempts']) ?> attempt(s) remaining before lockout.</span>
        </div>
      <?php endif; ?>

      <form method="post" id="loginForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

        <!-- Username -->
        <div>
          <label class="field-label" for="username">Username</label>
          <div class="input-wrap">
            <i class="fas fa-user icon-left"></i>
            <input type="text"
                   id="username"
                   name="username"
                   placeholder="Your username"
                   required
                   autocomplete="username"
                   value="<?= isset($_POST['username']) ? htmlspecialchars(strtolower(trim($_POST['username']))) : ''; ?>">
          </div>
        </div>

        <!-- Password -->
        <div>
          <label class="field-label" for="password">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock icon-left"></i>
            <input type="password"
                   id="password"
                   name="password"
                   placeholder="Your password"
                   required
                   autocomplete="current-password">
            <i class="fas fa-eye icon-toggle" onclick="togglePassword(this)"></i>
          </div>
        </div>

        <!-- Attempt counter -->
        <?php if ($_SESSION['login_attempts'] > 0): ?>
          <div class="attempt-note">Attempts: <?= $_SESSION['login_attempts'] ?>/5</div>
        <?php endif; ?>

        <!-- Submit -->
        <button type="submit" class="btn-submit" id="submitBtn">
          <i class="fas fa-sign-in-alt"></i>
          <span>Login</span>
        </button>
      </form>

      <div class="divider"><span>OR</span></div>

      <div class="bottom-links">
        <a href="register.php">
          <i class="fas fa-user-plus"></i> Create Account
        </a>
        <a href="forgot.php">
          <i class="fas fa-key"></i> Forgot Password?
        </a>
      </div>

    </div>
  </div>

</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ======= TOGGLE PASSWORD =======
function togglePassword(iconEl) {
    const input = document.getElementById('password');
    if (input.type === 'password') {
        input.type = 'text';
        iconEl.classList.replace('fa-eye', 'fa-eye-slash');
        iconEl.style.color = '#FF6B00';
    } else {
        input.type = 'password';
        iconEl.classList.replace('fa-eye-slash', 'fa-eye');
        iconEl.style.color = '';
    }
}

// ======= PASSWORD RESET MODAL =======
function showPasswordReset() {
    Swal.fire({
        title: 'Forgot Password?',
        html: `
            <div style="text-align:center;font-family:'DM Sans',sans-serif;padding:8px 0;">
                <p style="color:#555;margin-bottom:20px;font-size:14px;line-height:1.6;">
                    For security, password reset requires manual verification by our team.
                </p>
                <div style="background:#FFF7F0;padding:18px;border-radius:12px;border-left:4px solid #FF6B00;text-align:left;">
                    <p style="margin:8px 0;font-size:14px;color:#333;">
                        <i class="fas fa-envelope" style="color:#FF6B00;margin-right:8px;width:16px;"></i>
                        <strong>Email:</strong> admin@scooterental.com
                    </p>
                    <p style="margin:8px 0;font-size:14px;color:#333;">
                        <i class="fas fa-phone" style="color:#FF6B00;margin-right:8px;width:16px;"></i>
                        <strong>Phone:</strong> +62 812-3456-7890
                    </p>
                    <p style="margin:8px 0;font-size:14px;color:#333;">
                        <i class="fas fa-clock" style="color:#FF6B00;margin-right:8px;width:16px;"></i>
                        <strong>Hours:</strong> Mon–Fri, 9 AM – 5 PM (GMT+7)
                    </p>
                </div>
                <p style="margin-top:16px;font-size:12px;color:#999;">
                    Please have your username and registered email ready for verification.
                </p>
            </div>
        `,
        icon: false,
        confirmButtonText: 'Got it',
        showCloseButton: true,
        width: 480,
        padding: '28px',
    });
}

// ======= FORM SUBMIT =======
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Logging in...</span>';
});

// ======= AUTO FOCUS + AUTO-HIDE MESSAGES =======
document.addEventListener('DOMContentLoaded', function () {
    const usernameField = document.querySelector('input[name="username"]');
    if (usernameField && !usernameField.value) usernameField.focus();

    [{ sel: '.msg.success', delay: 5000 },
     { sel: '.msg.error',   delay: 8000 },
     { sel: '.msg.warning', delay: 10000 }
    ].forEach(({ sel, delay }) => {
        const el = document.querySelector(sel);
        if (!el) return;
        setTimeout(() => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(() => el.style.display = 'none', 500);
        }, delay);
    });

    // Cegah form resubmit saat refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});

// ======= ENTER KEY =======
document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && document.activeElement.tagName === 'INPUT') {
        e.preventDefault();
        document.getElementById('loginForm').requestSubmit();
    }
});
</script>

</body>
</html>