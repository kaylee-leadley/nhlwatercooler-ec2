<?php
// public/auth/google-callback.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$googleConfig = require __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$idToken = $_POST['credential'] ?? null;
if (!$idToken) {
  http_response_code(400);
  exit('Missing ID token');
}

// -------------------------------
// Verify token with Google
// -------------------------------
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$response  = @file_get_contents($verifyUrl);
if ($response === false) {
  http_response_code(502);
  exit('Failed to verify token with Google');
}

$data = json_decode($response, true);

if (!is_array($data) || ($data['aud'] ?? null) !== $googleConfig['client_id']) {
  http_response_code(401);
  exit('Invalid token');
}

$googleId = $data['sub']        ?? null;
$email    = $data['email']      ?? '';
$name     = $data['name']       ?? '';
$avatar   = $data['picture']    ?? '';

if (!$googleId) {
  http_response_code(400);
  exit('Invalid token payload');
}

// -------------------------------
// Look up or create user
// -------------------------------
$stmt = $pdo->prepare("
  SELECT *
  FROM users
  WHERE google_id = :gid OR email = :email
  LIMIT 1
");
$stmt->execute([
  ':gid'   => $googleId,
  ':email' => $email,
]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  // New Google-only account.
  // We must populate password_hash because the column is NOT NULL.
  $dummyPassword = bin2hex(random_bytes(32)); // random garbage
  $dummyHash     = password_hash($dummyPassword, PASSWORD_DEFAULT);

  $username = $name ?: $email ?: ('user_' . substr($googleId, -6));

  $stmt = $pdo->prepare("
    INSERT INTO users (username, email, password_hash, google_id, avatar_url, created_at)
    VALUES (:username, :email, :password_hash, :google_id, :avatar_url, NOW())
  ");
  $stmt->execute([
    ':username'      => $username,
    ':email'         => $email,
    ':password_hash' => $dummyHash,
    ':google_id'     => $googleId,
    ':avatar_url'    => $avatar,
  ]);

  $userId = $pdo->lastInsertId();
  $user   = [
    'id'        => $userId,
    'username'  => $username,
    'is_admin'  => 0,
    'google_id' => $googleId,
  ];
} else {
  // Existing user. Make sure google_id and avatar are updated.
  $userId = (int)$user['id'];

  $needsUpdate = false;
  $updateFields = [];
  $updateParams = [':id' => $userId];

  if (empty($user['google_id'])) {
    $needsUpdate = true;
    $updateFields[]          = 'google_id = :google_id';
    $updateParams[':google_id'] = $googleId;
    $user['google_id']       = $googleId;
  }

  if ($avatar && ($user['avatar_url'] ?? '') !== $avatar) {
    $needsUpdate = true;
    $updateFields[]             = 'avatar_url = :avatar_url';
    $updateParams[':avatar_url'] = $avatar;
    $user['avatar_url']         = $avatar;
  }

  if ($needsUpdate) {
    $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $upd = $pdo->prepare($sql);
    $upd->execute($updateParams);
  }
}

// -------------------------------
// Log in (same pattern as normal login)
// -------------------------------
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['is_admin']  = (int)($user['is_admin'] ?? 0);
$_SESSION['login_src'] = 'google';

header('Location: /');
exit;
