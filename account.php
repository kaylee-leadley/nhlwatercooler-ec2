<?php
// public/account.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_login();  // should be defined in db.php

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
  header('Location: /login.php');
  exit;
}

// Fetch current user data
$stmt = $pdo->prepare("
  SELECT username, email, avatar_path
  FROM users
  WHERE id = :id
");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
  http_response_code(404);
  echo "User not found.";
  exit;
}

$error   = '';
$success = '';
$currentUsername  = $user['username'];
$currentAvatarRel = $user['avatar_path']; // e.g. assets/img/avatars/xxx.png

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $newUsername           = trim($_POST['username'] ?? '');
  $removeAvatarRequested = !empty($_POST['remove_avatar']);  // checkbox
  $newAvatarRel          = $currentAvatarRel;

  // Basic username validation
  if ($newUsername === '') {
    $error = 'Username is required.';
  } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $newUsername)) {
    $error = 'Username must be 3–32 chars, letters/numbers/underscore only.';
  } else {
    // Ensure username is unique (except this user)
    $stmt = $pdo->prepare("
      SELECT id
      FROM users
      WHERE username = :u AND id <> :id
      LIMIT 1
    ");
    $stmt->execute([
      ':u'  => $newUsername,
      ':id' => $userId,
    ]);

    if ($stmt->fetch()) {
      $error = 'That username is already taken.';
    }
  }

  // Handle avatar actions if no username error
  if ($error === '') {
    // 1) Remove avatar if requested
    if ($removeAvatarRequested && !empty($currentAvatarRel)) {
      $oldFs = realpath(__DIR__ . '/' . $currentAvatarRel); // /public + assets/...
      if ($oldFs && is_file($oldFs)) {
        @unlink($oldFs);
      }
      $newAvatarRel = ''; // remove from DB
    }
    // 2) Otherwise, handle avatar upload if a file was provided
    elseif (!empty($_FILES['avatar']['name'])) {
      $file = $_FILES['avatar'];

      if ($file['error'] !== UPLOAD_ERR_OK) {
        // Log raw PHP upload error code for debugging
        error_log('[SJST avatar] upload error code=' . $file['error']);
        $error = 'There was a problem receiving the uploaded file (error ' . $file['error'] . ').';
      } else {
        // Max ~5 MB
        $maxBytes = 5 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
          $error = 'Avatar must be 5MB or smaller. Your file is about '
                 . round($file['size'] / (1024 * 1024), 1) . 'MB.';
        } else {
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime  = $finfo->file($file['tmp_name']);

          $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
          ];

          if (!isset($allowed[$mime])) {
            $error = 'Unsupported avatar format. Use JPG, PNG, GIF, or WEBP.';
          } else {
            $ext = $allowed[$mime];

            // Base FS dir for images (under /public/assets/img)
            $baseImgFs = realpath(__DIR__ . '/assets/img');
            if ($baseImgFs === false) {
              $error = 'Avatar directory not found.';
            } else {
              $avatarDirFs = $baseImgFs . '/avatars';
              if (!is_dir($avatarDirFs)) {
                if (!mkdir($avatarDirFs, 0755, true) && !is_dir($avatarDirFs)) {
                  $error = 'Could not create avatar directory.';
                }
              }

              if ($error === '') {
                $basename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $targetFs = $avatarDirFs . '/' . $basename;

                // --- Resize + compress avatar before saving ---

                $info = @getimagesize($file['tmp_name']);
                if ($info === false) {
                  $error = 'Could not read avatar image.';
                } else {
                  $width  = $info[0];
                  $height = $info[1];

                  // Create source image
                  switch ($ext) {
                    case 'jpg':
                    case 'jpeg':
                      $srcImg = imagecreatefromjpeg($file['tmp_name']);
                      break;
                    case 'png':
                      $srcImg = imagecreatefrompng($file['tmp_name']);
                      break;
                    case 'gif':
                      $srcImg = imagecreatefromgif($file['tmp_name']);
                      break;
                    case 'webp':
                      if (!function_exists('imagecreatefromwebp')) {
                        $srcImg = null;
                      } else {
                        $srcImg = imagecreatefromwebp($file['tmp_name']);
                      }
                      break;
                    default:
                      $srcImg = null;
                  }

                  if (!$srcImg) {
                    $error = 'Failed to read avatar image data.';
                  } else {

                    /* ---------- EXIF orientation fix for JPEG ---------- */
                    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                      $exif = @exif_read_data($file['tmp_name']);
                      if (!empty($exif['Orientation'])) {
                        $orientation = (int)$exif['Orientation'];
                        switch ($orientation) {
                          case 3: // 180°
                            $srcImg = imagerotate($srcImg, 180, 0);
                            break;

                          case 6: // 90° CW → rotate -90
                            $srcImg = imagerotate($srcImg, -90, 0);
                            $tmp    = $width;
                            $width  = $height;
                            $height = $tmp;
                            break;

                          case 8: // 90° CCW → rotate 90
                            $srcImg = imagerotate($srcImg, 90, 0);
                            $tmp    = $width;
                            $width  = $height;
                            $height = $tmp;
                            break;
                        }
                      }
                    }
                    /* ---------- end EXIF orientation fix ---------- */

                    // Max avatar dimensions
                    $maxWidth  = 456;
                    $maxHeight = 456;

                    // Compute new size keeping aspect ratio
                    $scale = 1.0;
                    if ($width > $maxWidth || $height > $maxHeight) {
                      $scaleW = $maxWidth / $width;
                      $scaleH = $maxHeight / $height;
                      $scale  = min($scaleW, $scaleH);
                    }

                    $newWidth  = (int)floor($width * $scale);
                    $newHeight = (int)floor($height * $scale);

                    $dstImg = imagecreatetruecolor($newWidth, $newHeight);

                    // Preserve transparency for PNG/GIF/WEBP
                    if (in_array($ext, ['png', 'gif', 'webp'], true)) {
                      imagealphablending($dstImg, false);
                      imagesavealpha($dstImg, true);
                    }

                    if (!imagecopyresampled(
                      $dstImg,
                      $srcImg,
                      0, 0, 0, 0,
                      $newWidth, $newHeight,
                      $width, $height
                    )) {
                      imagedestroy($srcImg);
                      imagedestroy($dstImg);
                      $error = 'Failed to resize avatar image.';
                    } else {
                      imagedestroy($srcImg);

                      // Save with compression depending on type
                      $saveOk = false;
                      switch ($ext) {
                        case 'jpg':
                        case 'jpeg':
                          $saveOk = imagejpeg($dstImg, $targetFs, 80);
                          break;
                        case 'png':
                          $saveOk = imagepng($dstImg, $targetFs, 6);
                          break;
                        case 'gif':
                          $saveOk = imagegif($dstImg);
                          break;
                        case 'webp':
                          if (function_exists('imagewebp')) {
                            $saveOk = imagewebp($dstImg, $targetFs, 80);
                          }
                          break;
                      }

                      imagedestroy($dstImg);

                      if (!$saveOk) {
                        $error = 'Failed to save resized avatar file.';
                      } else {
                        // Relative path from /public
                        $newAvatarRel = 'assets/img/avatars/' . $basename;

                        // Optional: delete old avatar if it exists
                        if (!empty($currentAvatarRel)) {
                          $oldFs = realpath(__DIR__ . '/' . $currentAvatarRel);
                          if ($oldFs && is_file($oldFs)) {
                            @unlink($oldFs);
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  // If everything looks good, update DB
  if ($error === '') {
    $stmt = $pdo->prepare("
      UPDATE users
      SET username = :username,
          avatar_path = :avatar_path
      WHERE id = :id
    ");
    $stmt->execute([
      ':username'    => $newUsername,
      ':avatar_path' => $newAvatarRel,
      ':id'          => $userId,
    ]);

    // Update session username for header
    $_SESSION['username'] = $newUsername;

    $currentUsername  = $newUsername;
    $currentAvatarRel = $newAvatarRel;

    // Tailored success message
    if ($removeAvatarRequested && $newAvatarRel === '') {
      $success = 'Account updated. Your avatar has been removed.';
    } elseif (!empty($_FILES['avatar']['name'])) {
      $success = 'Account updated. Your avatar has been updated.';
    } else {
      $success = 'Account updated successfully.';
    }
  }
}

// Determine URL for current avatar (root-relative)
$avatarUrl = $currentAvatarRel ?: 'assets/img/default-avatar.png';

// Page meta for header shell
$pageTitle = 'My Account – NHL Water Cooler';
$bodyClass = 'page-account team-all';
$pageCss   = ['/assets/css/login.css']; // reuse auth styles

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <h1>My Account</h1>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success">
      <p><?= htmlspecialchars($success) ?></p>
    </div>
  <?php endif; ?>

  <div class="account-avatar-block">
    <div class="account-avatar">
      <img src="/<?= htmlspecialchars($avatarUrl) ?>" alt="Your avatar">
    </div>
  </div>

  <form method="post"
        enctype="multipart/form-data"
        class="auth-form account-form">

    <label>
      Username
      <input
        type="text"
        name="username"
        required
        value="<?= htmlspecialchars($currentUsername) ?>"
      >
      <span class="field-help">
        3–32 characters; letters, numbers, and underscore only.
      </span>
    </label>

    <label>
      Avatar
      <input type="file" name="avatar" accept="image/*">
      <span class="field-help">
        Optional. JPG, PNG, GIF, or WEBP up to 5MB. Large images will be resized.
      </span>
    </label>

    <?php if (!empty($currentAvatarRel)): ?>
      <label class="account-remove-avatar">
        <input type="checkbox" name="remove_avatar" value="1">
        <span>Remove current avatar</span>
      </label>
    <?php endif; ?>

    <button type="submit" class="button-primary">
      Save changes
    </button>

    <a href="/index.php" class="button account-back-home">
      Back to Home
    </a>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>