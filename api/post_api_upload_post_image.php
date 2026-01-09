<?php
// public/post_api_upload_post_image.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_login();

header('Content-Type: application/json');

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'No image uploaded.']);
  exit;
}

$file = $_FILES['file'];

// ---------- 1) File size limit (raise so we can resize big phone photos) ----------
$maxBytes = 10 * 1024 * 1024; // 10 MB
if ($file['size'] > $maxBytes) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Image is too large (max 10 MB).']);
  exit;
}

// ---------- 2) MIME type check ----------
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/gif'  => 'gif',
  'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unsupported image type.']);
  exit;
}

$ext = $allowed[$mime];

// ---------- 3) Read dimensions ----------
$info = @getimagesize($file['tmp_name']);
if ($info === false) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Could not read image dimensions.']);
  exit;
}

list($width, $height) = $info;

// Max dimensions we will *resize down to* (not reject)
$maxWidth  = 1600;
$maxHeight = 1600;

// ---------- 4) Prepare upload directory ----------
$uploadDirFs  = realpath(__DIR__ . '/../assets/img');
if ($uploadDirFs === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Upload dir missing.']);
  exit;
}

$subDir = $uploadDirFs . '/post_uploads';
if (!is_dir($subDir)) {
  if (!mkdir($subDir, 0755, true) && !is_dir($subDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create upload dir.']);
    exit;
  }
}

$basename = 'post_' . bin2hex(random_bytes(8)) . '.' . $ext;
$targetFs = $subDir . '/' . $basename;

// ---------- 5) If within size limits, just move without resizing ----------
if ($width <= $maxWidth && $height <= $maxHeight) {
  if (!move_uploaded_file($file['tmp_name'], $targetFs)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save image.']);
    exit;
  }

  $urlPath = 'assets/img/post_uploads/' . $basename;
  echo json_encode([
    'ok'  => true,
    'url' => $urlPath,
  ]);
  exit;
}

// ---------- 6) Otherwise, resize + compress using GD ----------

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
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'WEBP not supported on server.']);
      exit;
    }
    $srcImg = imagecreatefromwebp($file['tmp_name']);
    break;
  default:
    $srcImg = null;
}

if (!$srcImg) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to read image data.']);
  exit;
}

// Compute new size keeping aspect ratio
$scaleW = $maxWidth / $width;
$scaleH = $maxHeight / $height;
$scale  = min($scaleW, $scaleH);

$newWidth  = (int)floor($width * $scale);
$newHeight = (int)floor($height * $scale);

$dstImg = imagecreatetruecolor($newWidth, $newHeight);

// Preserve transparency where applicable
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
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to resize image.']);
  exit;
}

imagedestroy($srcImg);

// Save the resized image with compression
$saveOk = false;

switch ($ext) {
  case 'jpg':
  case 'jpeg':
    // quality 0–100 (higher = better / larger)
    $saveOk = imagejpeg($dstImg, $targetFs, 80);
    break;
  case 'png':
    // compression 0–9 (higher = smaller / slower)
    $saveOk = imagepng($dstImg, $targetFs, 6);
    break;
  case 'gif':
    $saveOk = imagegif($dstImg, $targetFs);
    break;
  case 'webp':
    if (function_exists('imagewebp')) {
      $saveOk = imagewebp($dstImg, $targetFs, 80);
    } else {
      $saveOk = false;
    }
    break;
}

imagedestroy($dstImg);

if (!$saveOk) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to save resized image.']);
  exit;
}

// Relative URL used in HTML, relative to /public
$urlPath = 'assets/img/post_uploads/' . $basename;

echo json_encode([
  'ok'  => true,
  'url' => $urlPath,
]);
