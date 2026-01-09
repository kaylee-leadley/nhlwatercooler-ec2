<?php
// public/register.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/mailer.php'; // sendVerificationEmail()

// If already logged in, go home
if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

/* ----------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------- */
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function build_public_url($pathAndQuery) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // e.g. /public
  return $scheme . '://' . $host . $base . $pathAndQuery;
}

/* ----------------------------------------------------------
 * Form handling
 * ---------------------------------------------------------- */
$error   = '';
$success = '';

$username = '';
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $pass1    = $_POST['password'] ?? '';
  $pass2    = $_POST['password_confirm'] ?? '';

  // Validate
  if ($username === '') {
    $error = 'Please enter a username.';
  } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $username)) {
    $error = 'Username must be 3–50 characters and use letters, numbers, underscore, dash, or dot.';
  } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
  } elseif ($pass1 === '' || $pass2 === '') {
    $error = 'Please enter and confirm your password.';
  } elseif ($pass1 !== $pass2) {
    $error = 'Passwords do not match.';
  } elseif (strlen($pass1) < 8) {
    $error = 'Password must be at least 8 characters.';
  }

  // Ensure username/email unique
  if ($error === '') {
    $stmt = $pdo->prepare("
      SELECT id
      FROM users
      WHERE username = :u OR email = :e
      LIMIT 1
    ");
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($stmt->fetch()) {
      $error = 'That username or email is already in use.';
    }
  }

  // Create user + send verification email
  if ($error === '') {
    $passwordHash = password_hash($pass1, PASSWORD_DEFAULT);
    $verifyToken  = bin2hex(random_bytes(32)); // 64 chars fits users.verify_token

    try {
      $ins = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, is_admin, is_verified, verify_token)
        VALUES (:u, :e, :ph, 0, 0, :vt)
      ");
      $ins->execute([
        ':u'  => $username,
        ':e'  => $email,
        ':ph' => $passwordHash,
        ':vt' => $verifyToken,
      ]);

      // IMPORTANT: your verification endpoint
      $verifyLink = build_public_url('/verify_email.php?token=' . urlencode($verifyToken));

      $mailError = '';
      $ok = sendVerificationEmail($email, $username, $verifyLink, $mailError);

      if (!$ok) {
        error_log('[SJSharkTank] Verification email failed: ' . $mailError);
        $success = 'Account created, but we could not send the verification email. Please contact an admin.';
      } else {
        $success = 'Account created! Check your email to verify your account.';
      }

      // Clear form fields
      $username = '';
      $email    = '';
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') {
        $error = 'That username or email is already in use.';
      } else {
        error_log('[SJSharkTank] Register DB error: ' . $e->getMessage());
        $error = 'Could not create your account right now. Please try again.';
      }
    }
  }
}

/* ----------------------------------------------------------
 * Page meta for header include (match login/recover)
 * ---------------------------------------------------------- */
$pageTitle = 'Register – Water Cooler';
$bodyClass = 'page-login';
$pageCss   = ['../assets/css/login.css'];

// Same JS includes pattern as login.php
$pageJs    = [
  'https://accounts.google.com/gsi/client',
  '../assets/js/login.js',
];

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>Create account</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success">
      <p><?= h($success) ?></p>
      <p style="margin:8px 0 0; text-align:center;">
        <a href="login.php">Go to login</a>
      </p>
    </div>
  <?php endif; ?>

  <?php if (!$success): ?>

    <!-- Google Sign-In (same as login.php) -->
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

    <!-- Username/password registration -->
    <form method="post" class="auth-form" autocomplete="off">
      <label>
        Username
        <input type="text" name="username" required maxlength="50" value="<?= h($username) ?>">
        <span class="field-help">3–50 chars: letters, numbers, underscore, dash, dot.</span>
      </label>

      <label>
        Email address
        <input type="email" name="email" required value="<?= h($email) ?>">
      </label>

      <label>
        Password
        <input type="password" name="password" required minlength="8">
      </label>

      <label>
        Confirm password
        <input type="password" name="password_confirm" required minlength="8">
      </label>

      <button type="submit" class="button-primary">Create account</button>
    </form>
  <?php endif; ?>

  <p class="auth-footer">
    Already have an account?
    <a href="login.php">Log in</a>
      <br>
    Forgot your password?
    <a href="recover.php">Reset it here</a>
  </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
