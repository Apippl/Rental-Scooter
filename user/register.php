<?php
// ========================================================
// ERROR HANDLING — harus paling atas sebelum apapun
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ========================================================
// SESSION — sebelum output apapun
// ========================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // 0 for XAMPP, 1 for production HTTPS
ini_set('session.cookie_samesite', 'Lax');

session_start();

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// ========================================================
// PENTING: Pindahkan require DB ke sini, setelah session siap
// supaya tidak mengganggu session_start()
// ========================================================
require "../config/db.php";

// ========================================================
// CSRF TOKEN — PASTIKAN SELALU ADA
// ========================================================
if (empty($_SESSION['csrf_register'])) {
    $_SESSION['csrf_register'] = bin2hex(random_bytes(32));
}

// Rate limiting untuk register
if (!isset($_SESSION['register_attempts'])) {
    $_SESSION['register_attempts'] = 0;
    $_SESSION['register_first_attempt'] = time();
}

$error    = '';
$username = '';
$email    = '';  // <-- TAMBAHKAN: untuk menampung input email

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // ========================================================
    // 🔒 KEAMANAN: HAPUS FIELD BERBAHAYA DARI POST
    // ========================================================
    $forbidden_fields = [
        'role', 'is_admin', 'user_level', 'privilege',
        'is_verified', 'status', 'permission', 'access_level',
        'user_role', 'admin', 'superuser'
    ];
    
    foreach ($forbidden_fields as $field) {
        if (isset($_POST[$field])) {
            error_log("SECURITY: Forbidden field '{$field}' detected in POST from IP {$_SERVER['REMOTE_ADDR']}");
            unset($_POST[$field]);
        }
    }

    // Reset rate limit setelah 1 jam
    if (isset($_SESSION['register_first_attempt']) &&
        $_SESSION['register_attempts'] >= 10 &&
        (time() - $_SESSION['register_first_attempt']) >= 3600) {
        $_SESSION['register_attempts']    = 0;
        $_SESSION['register_first_attempt'] = time();
    }

    // Rate limit check
    if ($_SESSION['register_attempts'] >= 10) {
        $remaining = 3600 - (time() - $_SESSION['register_first_attempt']);
        $minutes   = max(1, ceil($remaining / 60));
        $error = "Too many registration attempts. Please try again in $minutes minutes.";

    // CSRF TOKEN CHECK — PERBAIKAN: gunakan hash_equals dengan aman
    } elseif (!isset($_POST['csrf_token']) ||
              !hash_equals($_SESSION['csrf_register'] ?? '', $_POST['csrf_token'] ?? '')) {

        $_SESSION['register_attempts']++;
        
        // Log detail untuk debugging
        error_log("CSRF mismatch register. Session token: " . ($_SESSION['csrf_register'] ?? 'empty') . 
                  ", POST token: " . ($_POST['csrf_token'] ?? 'empty') . 
                  ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        
        $error = "Your session has expired or the page was left open for too long. Please refresh the page and try again.";
        $_SESSION['csrf_register'] = bin2hex(random_bytes(32));

    } else {
        $_SESSION['register_attempts']++;

        // ========================================================
        // SANITASI INPUT - HANYA AMBIL FIELD YANG DIIZINKAN
        // ========================================================
        $username         = trim($_POST['username'] ?? '');
        $email            = trim($_POST['email'] ?? '');         // <-- AMBIL EMAIL
        $password_raw     = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirmPassword'] ?? '';
        $terms_accepted   = isset($_POST['terms']);

        // ---------- VALIDASI EMAIL (TAMBAHAN) ----------
        if (empty($email)) {
            $error = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($email) > 255) {
            $error = "Email address is too long.";
        }

        // Validasi TERMS
        elseif (!$terms_accepted) {
            $error = "You must agree to the Terms of Service and Privacy Policy.";

        // Validasi USERNAME
        } elseif (strlen($username) < 3 || strlen($username) > 30) {
            $error = "Username must be between 3 and 30 characters.";

        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = "Username can only contain letters, numbers, and underscores.";

        // Validasi PASSWORD
        } elseif (strlen($password_raw) < 6) {
            $error = "Password must be at least 6 characters long.";

        } elseif (!preg_match('/[A-Za-z]/', $password_raw) || !preg_match('/\d/', $password_raw)) {
            $error = "Password must contain both letters and numbers.";

        } elseif ($password_raw !== $confirm_password) {
            $error = "Passwords do not match.";

        } else {
            $username_save = strtolower($username);

            // ---------- CEK DUPLIKAT USERNAME ----------
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            if ($stmt === false) {
                error_log("Prepare SELECT username failed: " . $conn->error);
                $error = "Database error. Please try again later.";
            } else {
                $stmt->bind_param("s", $username_save);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $error = "Username already exists! Please choose another one.";
                    $stmt->close();
                } else {
                    $stmt->close();

                    // ---------- CEK DUPLIKAT EMAIL ----------
                    $stmt2 = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                    if ($stmt2 === false) {
                        error_log("Prepare SELECT email failed: " . $conn->error);
                        $error = "Database error. Please try again later.";
                    } else {
                        $stmt2->bind_param("s", $email);
                        $stmt2->execute();
                        $stmt2->store_result();

                        if ($stmt2->num_rows > 0) {
                            $error = "Email already registered. Please use a different email.";
                            $stmt2->close();
                        } else {
                            $stmt2->close();

                            $password_hash = password_hash($password_raw, PASSWORD_DEFAULT);

                            // ---------- INSERT DATA (termasuk email) ----------
                            $ins = $conn->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (?, ?, ?, 'user', NOW())");
                            
                            if ($ins === false) {
                                error_log("Prepare INSERT failed: " . $conn->error);
                                $error = "Registration failed. Please try again later.";
                            } else {
                                $ins->bind_param("sss", $username_save, $email, $password_hash);

                                if ($ins->execute()) {
                                    $ins->close();

                                    $_SESSION['register_attempts'] = 0;
                                    session_regenerate_id(true);
                                    $_SESSION['csrf_register'] = bin2hex(random_bytes(32));
                                    $_SESSION['success']       = "Registration successful! You can now login.";
                                    
                                    // Jangan log email (PII) ke file — cukup username + IP untuk audit.
                                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                                    error_log("New user registered: username={$username_save}, IP={$ip}");
                                    
                                    header("Location: index.php");
                                    exit;
                                } else {
                                    error_log("Registration INSERT failed — username: $username_save | DB: " . $conn->error);
                                    $error = "Registration failed. Please try again later.";
                                    $ins->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Cleanup pasif: reset counter jika sudah >2 jam tidak ada aktivitas apapun
if (isset($_SESSION['register_first_attempt']) &&
    (time() - $_SESSION['register_first_attempt']) > 7200) {
    $_SESSION['register_attempts']      = 0;
    $_SESSION['register_first_attempt'] = time();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Scooter Rental</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <link rel="stylesheet" href="../css/register.css">
</head>
<body>

<div class="page-wrapper">

  <div class="left-panel">
    <div class="brand-tag">
      <i class="fas fa-scooter"></i>
      Scooter Rental
    </div>
    <h2>RIDE<br><span>YOUR</span>WAY</h2>
    <p>Join thousands of riders already exploring the city with our affordable, easy-to-use scooter rental platform.</p>
    <div class="feature-list">
      <div class="feature-item"><div class="feature-dot"><i class="fas fa-bolt"></i></div><span>Instant booking in under 60 seconds</span></div>
      <div class="feature-item"><div class="feature-dot"><i class="fas fa-shield-alt"></i></div><span>Safe & secured payment system</span></div>
      <div class="feature-item"><div class="feature-dot"><i class="fas fa-map-marker-alt"></i></div><span>200+ pickup locations citywide</span></div>
      <div class="feature-item"><div class="feature-dot"><i class="fas fa-headset"></i></div><span>24/7 customer support</span></div>
    </div>
    <div class="left-bottom">© 2025 Scooter Rental. All rights reserved.</div>
  </div>

  <div class="right-panel">
    <div class="form-container">
      <div class="form-header">
        <div class="step-label">New Account</div>
        <h1>REGISTER</h1>
        <p>Fill in the details below to get started.</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="msg error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error); ?></span></div>
      <?php endif; ?>

      <?php if (isset($_SESSION['success'])): ?>
        <div class="msg success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($_SESSION['success']); ?></span></div>
        <?php unset($_SESSION['success']); ?>
      <?php endif; ?>

      <?php if ($_SESSION['register_attempts'] >= 7 && $_SESSION['register_attempts'] < 10): ?>
        <div class="msg warning"><i class="fas fa-exclamation-triangle"></i><span>Warning: <?= (10 - $_SESSION['register_attempts']) ?> attempt(s) remaining before lockout.</span></div>
      <?php endif; ?>

      <form method="post" id="registerForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_register'] ?? ''); ?>">
        <input type="hidden" name="confirmPassword" id="confirmPasswordField">

        <div>
          <label class="field-label" for="username">Username</label>
          <div class="input-wrap">
            <i class="fas fa-user icon-left"></i>
            <input type="text" id="username" name="username" placeholder="e.g. rider_john" required autocomplete="username" value="<?= htmlspecialchars(strtolower($username)); ?>" minlength="3" maxlength="30">
          </div>
        </div>

        <!-- TAMBAHKAN INPUT EMAIL DI SINI -->
        <div>
          <label class="field-label" for="email">Email Address</label>
          <div class="input-wrap">
            <i class="fas fa-envelope icon-left"></i>
            <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="email" value="<?= htmlspecialchars($email); ?>">
          </div>
        </div>

        <div>
          <label class="field-label" for="password">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock icon-left"></i>
            <input type="password" id="password" name="password" placeholder="Min. 6 chars, letters & numbers" required autocomplete="new-password" minlength="6">
            <i class="fas fa-eye icon-toggle" onclick="togglePassword(this, 'password')"></i>
          </div>
          <div class="strength-bar-wrap" id="strengthWrap">
            <div class="strength-track"><div class="strength-fill" id="strengthFill"></div></div>
            <div class="strength-label" id="strengthLabel">Too weak</div>
          </div>
        </div>

        <div>
          <label class="field-label" for="confirmPassword">Confirm Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock icon-left"></i>
            <input type="password" id="confirmPassword" placeholder="Re-enter your password" required autocomplete="new-password" minlength="6">
            <i class="fas fa-eye icon-toggle" onclick="togglePassword(this, 'confirmPassword')"></i>
          </div>
        </div>

        <div class="terms-row">
          <input type="checkbox" id="terms" name="terms" required>
          <label for="terms">I agree to the <a href="#" onclick="showTerms(); return false;">Terms of Service</a> and <a href="#" onclick="showPrivacy(); return false;">Privacy Policy</a></label>
        </div>

        <?php if ($_SESSION['register_attempts'] > 0): ?>
          <div class="attempt-note">Attempts: <?= $_SESSION['register_attempts'] ?>/10</div>
        <?php endif; ?>

        <button type="submit" class="btn-submit" id="submitBtn">
          <i class="fas fa-user-plus"></i>
          <span>Create Account</span>
        </button>
      </form>

      <div class="divider"><span>OR</span></div>
      <div class="login-link-wrap">Already have an account? <a href="index.php"><i class="fas fa-sign-in-alt"></i> Login here</a></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function togglePassword(iconEl, inputId) {
    const input = document.getElementById(inputId);
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

const passwordInput = document.getElementById('password');
const strengthWrap  = document.getElementById('strengthWrap');
const strengthFill  = document.getElementById('strengthFill');
const strengthLabel = document.getElementById('strengthLabel');

passwordInput.addEventListener('input', function () {
    const val = this.value;
    if (!val) {
        strengthWrap.classList.remove('visible');
        return;
    }
    strengthWrap.classList.add('visible');

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/\d/.test(val))    score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '20%', color: '#EF4444', label: 'Too weak' },
        { pct: '40%', color: '#F97316', label: 'Weak' },
        { pct: '60%', color: '#EAB308', label: 'Fair' },
        { pct: '80%', color: '#3B82F6', label: 'Good' },
        { pct: '100%', color: '#10B981', label: 'Strong 💪' },
    ];
    const lvl = levels[Math.min(score - 1, 4)] || levels[0];
    strengthFill.style.width = lvl.pct;
    strengthFill.style.background = lvl.color;
    strengthLabel.textContent = lvl.label;
    strengthLabel.style.color = lvl.color;
});

function showTerms() {
    Swal.fire({
        title: 'Terms of Service',
        html: `<div style="text-align:left;max-height:400px;overflow-y:auto;">
          <div style="background:#FFF7F0;padding:14px;border-radius:10px;margin-bottom:20px;border-left:4px solid #FF6B00;">
            <p>Please read these terms carefully before using our scooter rental service.</p>
          </div>
          <div style="background:#FAFAFA;padding:16px;border-radius:10px;margin-bottom:14px;">
            <h4 style="margin:0 0 10px;color:#111;">📝 Account Registration & Usage</h4>
            <p>Must be 18+ · Accurate info required · Keep account secure · Report unauthorized access immediately.</p>
          </div>
          <div style="background:#FAFAFA;padding:16px;border-radius:10px;margin-bottom:14px;">
            <h4 style="margin:0 0 10px;color:#111;">🛵 Scooter Rental Agreement</h4>
            <p>Use in designated areas · Follow traffic laws · Return scooter in same condition · Report damages promptly.</p>
          </div>
          <div style="background:#FAFAFA;padding:16px;border-radius:10px;margin-bottom:14px;">
            <h4 style="margin:0 0 10px;color:#111;">💰 Payment & Fees</h4>
            <p>Fees due at rental time · Late returns incur extra charges · Damage fees may apply · Refunds in 7-10 business days.</p>
          </div>
        </div>`,
        confirmButtonText: '✓ I Agree',
        width: 600,
    });
}

function showPrivacy() {
    Swal.fire({
        title: 'Privacy Policy',
        html: `<div style="text-align:left;max-height:400px;overflow-y:auto;">
          <div style="background:#FFF7F0;padding:14px;border-radius:10px;margin-bottom:20px;border-left:4px solid #FF6B00;">
            <p>We are committed to protecting your personal information.</p>
          </div>
          <div style="background:#FAFAFA;padding:16px;border-radius:10px;margin-bottom:14px;">
            <h4 style="margin:0 0 10px;color:#111;">📊 Data Collection</h4>
            <p>Personal info (username, email) for account creation · Usage data & rental history for service improvement.</p>
          </div>
          <div style="background:#FAFAFA;padding:16px;border-radius:10px;">
            <h4 style="margin:0 0 10px;color:#111;">🔒 Data Protection</h4>
            <p>All data transmitted via SSL/TLS encryption · Strict internal access control policies.</p>
          </div>
        </div>`,
        confirmButtonText: '✓ I Understand',
        width: 600,
    });
}

document.getElementById('registerForm').addEventListener('submit', function (e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const confirmField = document.getElementById('confirmPasswordField');
    const terms = document.getElementById('terms').checked;
    const submitBtn = document.getElementById('submitBtn');

    if (password !== confirmPassword) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Password Mismatch', text: 'Please make sure both passwords match.', confirmButtonColor: '#FF6B00' });
        return false;
    }

    if (!terms) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'Terms Required', text: 'You must agree to the Terms of Service and Privacy Policy.', confirmButtonColor: '#FF6B00' });
        return false;
    }

    confirmField.value = confirmPassword;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Creating Account...</span>';
    return true;
});

document.addEventListener('DOMContentLoaded', function () {
    const usernameField = document.querySelector('input[name="username"]');
    if (usernameField && !usernameField.value) usernameField.focus();

    ['.msg.success', '.msg.error', '.msg.warning'].forEach((sel, i) => {
        const el = document.querySelector(sel);
        if (el) setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.style.display = 'none', 500); }, [5000, 8000, 10000][i]);
    });

    if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);
});
</script>
</body>
</html>