<?php
// public/login.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username === '' || $password === '') {
    $error = 'Please enter username and password.';
  } else {
    $stmt = $pdo->prepare("
      SELECT id, username, password_hash, is_admin, is_verified
      FROM users
      WHERE username = :username
      LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $error = 'Invalid username or password.';
    } elseif ((int)$user['is_verified'] !== 1) {
      $error = 'Your account is not verified yet. Please check your email for the verification link.';
    } else {
      // Verified – log in
      $_SESSION['user_id']  = (int)$user['id'];
      $_SESSION['username'] = $user['username'];
      $_SESSION['is_admin'] = (int)$user['is_admin'];

      header('Location: index.php');
      exit;
    }
  }
}

// Page meta for header include
$pageTitle = 'Login – Water Cooler';
$bodyClass = 'page-login';
$pageCss   = ['assets/css/login.css'];   // main.css is added in header.php

// Load Google Identity Services script + your login JS
$pageJs    = [
  'https://accounts.google.com/gsi/client',
  '../assets/js/login.js',
];

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>Log in</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Google Sign-In -->
  <div class="auth-social">
    <div id="g_id_onload"
         data-client_id="289114978428-9i31lsm4mu828r7tiktn63fpivs5gnu0.apps.googleusercontent.com"
         data-login_uri="https://nhlwatercooler.com/auth/google-callback.php"
         data-auto_prompt="false">
    </div>

    <div class="g_id_signin"
         data-type="standard"
         data-shape="rectangular"
         data-theme="outline"
         data-text="signin_with"
         data-size="large">
    </div>
  </div>

  <div class="auth-divider">
    <span>or</span>
  </div>

  <!-- Existing username/password form -->
  <form method="post" class="auth-form">
    <label>
      Username
      <input
        type="text"
        name="username"
        id="login-username"
        required
        value="<?= htmlspecialchars($username) ?>"
      >
    </label>

    <label>
      Password
      <input type="password" name="password" required>
    </label>

    <button type="submit" class="button-primary">Log in</button>
  </form>

  <p class="auth-footer">
    Don’t have an account?
    <a href="register.php">Register</a>
      <br>
    Forgot your password?
    <a href="recover.php">Reset it here</a>
  </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
