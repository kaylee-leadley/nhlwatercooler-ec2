<?php
// public/recover.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/mailer.php'; // gives us sjst_get_mail_config()

// If already logged in, no need to recover
if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}

/**
 * Send password reset email using existing mail config.
 *
 * @param string $toEmail
 * @param string $username
 * @param string $resetLink
 * @param string $errorMessage (by ref)
 * @return bool
 */
function sendPasswordResetEmail($toEmail, $username, $resetLink, &$errorMessage)
{
  $errorMessage = '';

  try {
    $config = sjst_get_mail_config();
  } catch (\Throwable $e) {
    $errorMessage = $e->getMessage();
    error_log('[SJSharkTank] Mail config error (reset): ' . $e->getMessage());
    return false;
  }

  // Use PHPMailer via Composer autoload (already required in mailer.php)
  $mail = new PHPMailer\PHPMailer\PHPMailer(true);

  try {
    /* ---------- SMTP setup ---------- */
    $mail->isSMTP();
    $mail->Host       = isset($config['host']) ? $config['host'] : '';
    $mail->SMTPAuth   = true;
    $mail->Username   = isset($config['username']) ? $config['username'] : '';
    $mail->Password   = isset($config['password']) ? $config['password'] : '';

    if (!empty($config['encryption'])) {
      $mail->SMTPSecure = $config['encryption']; // 'tls' or 'ssl'
    }

    $mail->Port = isset($config['port']) ? (int)$config['port'] : 587;

    // Optional debug while testing:
    // $mail->SMTPDebug  = 2;
    // $mail->Debugoutput = 'error_log';

    /* ---------- From / To ---------- */
    $fromEmail = isset($config['from_email']) ? $config['from_email'] : $config['username'];
    $fromName  = isset($config['from_name']) ? $config['from_name'] : 'SJ Shark Tank';

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $username);

    /* ---------- Content ---------- */
    $mail->isHTML(true);
    $mail->Subject = 'Reset your SJ Shark Tank password';

    $safeUsername  = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeResetLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    $mail->Body = '
      <p>Hi ' . $safeUsername . ',</p>
      <p>We received a request to reset your password for <strong>SJ Shark Tank</strong>.</p>
      <p>Please click the button below to choose a new password:</p>
      <p>
        <a href="' . $safeResetLink . '"
           style="
             display:inline-block;
             padding:8px 14px;
             background:#007b8a;
             color:#ffffff;
             text-decoration:none;
             border-radius:4px;
             font-weight:600;
           ">
          Reset my password
        </a>
      </p>
      <p>If the button doesn\'t work, copy and paste this URL into your browser:</p>
      <p><code>' . $safeResetLink . '</code></p>
      <p>If you didn\'t request a password reset, you can safely ignore this email.</p>
    ';

    $mail->AltBody =
      "Hi $username,\n\n" .
      "We received a request to reset your password for SJ Shark Tank.\n\n" .
      "Open this link to choose a new password:\n\n" .
      $resetLink . "\n\n" .
      "If you didn't request this, you can ignore this email.\n";

    $mail->send();
    return true;
  } catch (\Throwable $e) {
    $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
    error_log('[SJSharkTank] Reset email send failed: ' . $errorMessage);
    return false;
  }
}

$error   = '';
$success = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');

  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
  } else {
    // Look up user by email (do not leak if not found)
    $stmt = $pdo->prepare("
      SELECT id, username, email
      FROM users
      WHERE email = :email
      LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // Generic success message to avoid account enumeration
    $genericMsg = 'If an account with that email exists, we\'ve sent a password reset link. Check your inbox.';

    if (!$user) {
      // Pretend it worked even if we didn't find anyone
      $success = $genericMsg;
    } else {
      // Create/reset token
      $userId    = (int)$user['id'];
      $token     = bin2hex(random_bytes(32));
      $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now

      // Remove any old tokens for this user
      $del = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid");
      $del->execute([':uid' => $userId]);

      // Store new token
      $ins = $pdo->prepare("
        INSERT INTO password_resets (user_id, token, expires_at)
VALUES (:uid, :token, :expires_at)");
      $ins->execute([
        ':uid'        => $userId,
        ':token'      => $token,
        ':expires_at' => $expiresAt,
      ]);

      // Build reset link (e.g. https://example.com/reset_password.php?token=...)
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
      $resetLink = $scheme . '://' . $host . $base . '/reset_password.php?token=' . urlencode($token);

      $mailError = '';
      $ok = sendPasswordResetEmail($user['email'], $user['username'], $resetLink, $mailError);

      if (!$ok) {
        // In production you might still show generic success; here we log + generic.
        error_log('[SJSharkTank] Failed to send reset email: ' . $mailError);
      }

      $success = $genericMsg;
    }
  }
}

// Page meta for header shell
$pageTitle = 'Recover Password – Sharks Message Board';
$bodyClass = 'page-recover';
$pageCss   = ['../assets/css/login.css']; // reuse auth styles

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>Password recovery</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="post" class="auth-form">
    <label>
      Email address
      <input
        type="email"
        name="email"
        required
        value="<?= htmlspecialchars($email) ?>"
      >
      <span class="field-help">
        Enter the email you used for your SJ Shark Tank account.
      </span>
    </label>

    <button type="submit" class="button-primary">Send reset link</button>
  </form>

  <p class="auth-footer">
    Remembered your password?
    <a href="login.php">Back to login</a>
  </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
