<?php
// reset.php — Tukar token menjadi password baru
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

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';

$error = '';
$valid_user = null;

if ($token !== '') {
    $hash = hash('sha256', $token);
    $st = $conn->prepare("SELECT pr.user_id, pr.expires_at, pr.used_at, u.username, u.is_blocked
                          FROM password_resets pr JOIN users u ON u.user_id = pr.user_id
                          WHERE pr.token_hash = ? LIMIT 1");
    $st->bind_param("s", $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row && empty($row['used_at']) && strtotime($row['expires_at']) > time() && empty($row['is_blocked'])) {
        $valid_user = $row;
    } else {
        $error = "Reset link is invalid, expired, or already used.";
    }
} else {
    $error = "Missing reset token.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_user) {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security token invalid. Please refresh.";
    } else {
        $p1 = $_POST['password'] ?? '';
        $p2 = $_POST['confirm'] ?? '';
        if (strlen($p1) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif (!preg_match('/[A-Za-z]/', $p1) || !preg_match('/\d/', $p1)) {
            $error = "Password must contain both letters and numbers.";
        } elseif ($p1 !== $p2) {
            $error = "Passwords do not match.";
        } else {
            $new_hash = password_hash($p1, PASSWORD_DEFAULT);
            $uid = (int)$valid_user['user_id'];
            $u = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $u->bind_param("si", $new_hash, $uid);
            $u->execute(); $u->close();
            $d = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $d->bind_param("i", $uid);
            $d->execute(); $d->close();
            error_log("Password reset completed: user_id=$uid");
            $_SESSION['success'] = "Password changed! Please login with your new password.";
            header("Location: index.php");
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Scooter Rental</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/index.css">
</head>
<body>
<div class="page-wrapper">
  <div class="left-panel">
    <div class="brand-tag"><i class="fas fa-scooter"></i> Scooter Rental</div>
    <h2>NEW<br><span>START</span> KEY</h2>
    <p class="tagline">Choose a strong password with letters &amp; numbers.</p>
  </div>
  <div class="right-panel">
    <div class="form-container">
      <div class="form-header">
        <div class="step-label">Recovery</div>
        <h1>RESET PASSWORD</h1>
        <p><?= $valid_user ? ('Halo, <strong>' . htmlspecialchars($valid_user['username']) . '</strong> — buat password baru.') : 'Link tidak valid.' ?></p>
      </div>
      <?php if ($error): ?><div class="msg error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
      <?php if ($valid_user): ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div>
          <label class="field-label" for="password">New Password</label>
          <div class="input-wrap"><i class="fas fa-lock icon-left"></i>
            <input type="password" id="password" name="password" placeholder="Min. 6 chars, letters & numbers" required minlength="6">
          </div>
        </div>
        <div>
          <label class="field-label" for="confirm">Confirm Password</label>
          <div class="input-wrap"><i class="fas fa-lock icon-left"></i>
            <input type="password" id="confirm" name="confirm" placeholder="Repeat new password" required minlength="6">
          </div>
        </div>
        <button type="submit" class="btn-submit"><i class="fas fa-key"></i><span>Change Password</span></button>
      </form>
      <?php endif; ?>
      <div class="divider"><span>OR</span></div>
      <div class="bottom-links"><a href="index.php"><i class="fas fa-sign-in-alt"></i> Back to Login</a></div>
    </div>
  </div>
</div>
</body>
</html>
