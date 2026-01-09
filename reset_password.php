<?php
// public/reset_password.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

// If already logged in, no need to reset
if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

$error    = '';
$success  = '';
$password = '';
$confirm  = '';

// Token can come from GET (initial load) or POST (form submit)
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

$userRow  = null;
$showForm = true;

if ($token === '') {
  $error    = 'Invalid or missing password reset token.';
  $showForm = false;
} else {
  // Look up token + associated user
  $stmt = $pdo->prepare("
    SELECT pr.user_id, pr.expires_at, u.username, u.email
    FROM password_resets pr
    JOIN users u ON u.id = pr.user_id
    WHERE pr.token = :token
    LIMIT 1
  ");
  $stmt->execute([':token' => $token]);
  $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$userRow) {
    $error    = 'This reset link is invalid or has already been used.';
    $showForm = false;
  } else {
    // Check expiry
    $expiresTs = strtotime($userRow['expires_at']);
    if ($expiresTs === false || $expiresTs < time()) {
      $error    = 'This reset link has expired. Please request a new one.';
      $showForm = false;

      // Optional: clean up expired token
      $del = $pdo->prepare("DELETE FROM password_resets WHERE token = :token");
      $del->execute([':token' => $token]);
    }
  }
}

// If valid token and POST, process password change
if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = $_POST['password'] ?? '';
  $confirm  = $_POST['password_confirm'] ?? '';

  if ($password === '' || $confirm === '') {
    $error = 'Please enter and confirm your new password.';
  } elseif ($password !== $confirm) {
    $error = 'Passwords do not match.';
  } elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters.';
  }

  if ($error === '' && $userRow) {
    $userId       = (int)$userRow['user_id'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Update user password
    $upd = $pdo->prepare("
      UPDATE users
      SET password_hash = :hash
      WHERE id = :id
    ");
    $upd->execute([
      ':hash' => $passwordHash,
      ':id'   => $userId,
    ]);

    // Invalidate all reset tokens for this user
    $del = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid");
    $del->execute([':uid' => $userId]);

    $success  = 'Your password has been reset. You can now log in with your new password.';
    $showForm = false;
  }
}

// Page meta for header include (match login/recover)
$pageTitle = 'Reset Password – SJ Shark Tank';
$bodyClass = 'page-login';
$pageCss   = ['../assets/css/login.css']; // main.css is added in header.php

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>Reset your password</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success">
      <p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
      <p style="margin:8px 0 0; text-align:center;">
        <a href="login.php">Go to login</a>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($showForm): ?>
    <form method="post" class="auth-form">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

      <label>
        New password
        <input type="password" name="password" required minlength="8">
      </label>

      <label>
        Confirm new password
        <input type="password" name="password_confirm" required minlength="8">
      </label>

      <button type="submit" class="button-primary">Set new password</button>
    </form>
  <?php endif; ?>

  <p class="auth-footer">
    Remembered your password?
    <a href="login.php">Back to login</a>
    <br>
    Need a new link?
    <a href="recover.php">Request another reset email</a>
  </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
