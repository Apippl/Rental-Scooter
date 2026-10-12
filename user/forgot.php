<?php
// forgot.php — Minta link reset password (token 1 jam, dev-mode tampil di layar)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

require "../config/db.php";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_SESSION['forgot_attempts'] = $_SESSION['forgot_attempts'] ?? 0;
$_SESSION['forgot_first'] = $_SESSION['forgot_first'] ?? time();
if ($_SESSION['forgot_attempts'] >= 5 && (time() - $_SESSION['forgot_first']) >= 3600) {
    $_SESSION['forgot_attempts'] = 0;
    $_SESSION['forgot_first'] = time();
}

$error = '';
$success = '';
// $dev_link DIHAPUS — jangan pernah tampilkan reset link di layar.
// Di produksi link hanya dikirim via email. Di dev, cek error.log (tanpa token penuh).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SESSION['forgot_attempts'] >= 5) {
        $error = "Too many requests. Try again later.";
    } elseif (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['forgot_attempts']++;
        $error = "Security token invalid. Please refresh.";
    } else {
        $_SESSION['forgot_attempts']++;
        $ident = strtolower(trim($_POST['identity'] ?? ''));
        if ($ident === '') {
            $error = "Please enter your username or email.";
        } else {
            $st = $conn->prepare("SELECT user_id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
            $st->bind_param("ss", $ident, $ident);
            $st->execute();
            $user = $st->get_result()->fetch_assoc();
            $st->close();

            // Selalu respons sama (anti user-enumeration), tapi hanya buat token jika user ada
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $hash = hash('sha256', $token);
                $exp = date('Y-m-d H:i:s', time() + 3600);
                // Hanguskan token lama user ini
                $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $del->bind_param("i", $user['user_id']);
                $del->execute(); $del->close();
                $ins = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
                $ins->bind_param("iss", $user['user_id'], $hash, $exp);
                $ins->execute(); $ins->close();

                $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
                    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/reset.php?token=' . $token;
                $link = preg_replace('#/+#', '/', $link);
                $link = preg_replace('#^(https?:)/#', '$1//', $link);
                // JANGAN log link penuh (berisi token). Cukup user_id + expiry untuk audit.
                error_log("Password reset requested: user_id={$user['user_id']} expires=$exp");
                // Coba kirim email jika mail() tersedia; abaikan gagalnya
                if (!empty($user['email'])) {
                    @mail($user['email'], 'Reset Password - Scooter Rental',
                        "Klik link berikut (berlaku 1 jam):\n$link", "From: no-reply@scooterental.local");
                }
            }
            // Pesan SAMA persis baik user ada maupun tidak — anti enumeration.
            // Delay kecil acak untuk samarkan timing attack.
            usleep(random_int(100000, 300000));
            $success = "If the account exists, a reset link has been sent to the registered email (valid 1 hour).";
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Scooter Rental</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/index.css">
</head>
<body>
<div class="page-wrapper">
  <div class="left-panel">
    <div class="brand-tag"><i class="fas fa-scooter"></i> Scooter Rental</div>
    <h2>RESET<br><span>YOUR</span> KEY</h2>
    <p class="tagline">Enter your username or email and we'll create a one-hour reset link.</p>
  </div>
  <div class="right-panel">
    <div class="form-container">
      <div class="form-header">
        <div class="step-label">Recovery</div>
        <h1>FORGOT PASSWORD</h1>
        <p>We never ask for your old password here.</p>
      </div>
      <?php if ($error): ?><div class="msg error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
      <?php if ($success): ?><div class="msg success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div>
          <label class="field-label" for="identity">Username or Email</label>
          <div class="input-wrap">
            <i class="fas fa-user icon-left"></i>
            <input type="text" id="identity" name="identity" placeholder="username / email" required>
          </div>
        </div>
        <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i><span>Send Reset Link</span></button>
      </form>
      <div class="divider"><span>OR</span></div>
      <div class="bottom-links"><a href="index.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a></div>
    </div>
  </div>
</div>
</body>
</html>
