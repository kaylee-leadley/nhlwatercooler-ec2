<?php
// public/verify_email.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

$token   = trim($_GET['token'] ?? '');
$message = '';

if ($token === '') {
  $message = 'Invalid or missing verification token.';
} else {
  $stmt = $pdo->prepare("
    SELECT id, is_verified
    FROM users
    WHERE verify_token = :token
    LIMIT 1
  ");
  $stmt->execute([':token' => $token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    $message = 'This verification link is invalid or has already been used.';
  } elseif ((int)$user['is_verified'] === 1) {
    $message = 'Your account is already verified. You can log in now.';
  } else {
    $upd = $pdo->prepare("
      UPDATE users
      SET is_verified = 1,
          verify_token = NULL
      WHERE id = :id
    ");
    $upd->execute([':id' => (int)$user['id']]);

    $message = 'Your email has been verified! You can now log in.';
  }
}

// Page meta for header shell (match login/recover)
$pageTitle = 'Email Verification – SJ Shark Tank';
$bodyClass = 'page-login';
$pageCss   = ['../assets/css/login.css']; // main.css comes from header.php

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>Email Verification</h1>

  <div class="alert alert-success">
    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
  </div>

  <p class="auth-footer">
    <a href="login.php">Go to Login</a>
  </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
