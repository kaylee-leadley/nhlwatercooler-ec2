<?php
// public/post_api_post_create.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_login();

require_once __DIR__ . '/../helpers/thread_sanitize_post_html.php'; // NEW

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$thread_id = isset($data['thread_id']) ? (int)$data['thread_id'] : 0;
$parent_id = isset($data['parent_id']) && $data['parent_id'] !== ''
  ? (int)$data['parent_id']
  : null;
$body_html = trim($data['body_html'] ?? '');
$user_id   = (int)($_SESSION['user_id'] ?? 0);

if (!$thread_id || !$user_id || $body_html === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

/* --------------------------------------------------
 * 1) Limit total post size
 * -------------------------------------------------- */

$MAX_POST_CHARS = 8000;

if (strlen($body_html) > $MAX_POST_CHARS) {
  http_response_code(400);
  echo json_encode(['error' => 'Post is too long. Please shorten it (max ~' . $MAX_POST_CHARS . ' characters).']);
  exit;
}

/* --------------------------------------------------
 * 2) Block inline data: images
 * -------------------------------------------------- */
if (stripos($body_html, 'src="data:') !== false || stripos($body_html, "src='data:") !== false) {
  http_response_code(400);
  echo json_encode(['error' => 'Inline base64 images are not allowed. Please use the upload feature.']);
  exit;
}

/* --------------------------------------------------
 * 3) Limit number of <img> tags
 * -------------------------------------------------- */
$MAX_IMAGES_PER_POST = 5;
$imgCount = 0;
if (preg_match_all('/<img\b[^>]*>/i', $body_html, $matches)) $imgCount = count($matches[0]);
if ($imgCount > $MAX_IMAGES_PER_POST) {
  http_response_code(400);
  echo json_encode(['error' => 'Too many images in one post. Max ' . $MAX_IMAGES_PER_POST . ' images allowed.']);
  exit;
}

/* --------------------------------------------------
 * 4) Limit number of links + reject javascript: URLs (spam/xss)
 * -------------------------------------------------- */
$MAX_LINKS_PER_POST = 8;

if (preg_match_all('/<a\b[^>]*href\s*=\s*([\'"])(.*?)\1/i', $body_html, $m)) {
  if (count($m[0]) > $MAX_LINKS_PER_POST) {
    http_response_code(400);
    echo json_encode(['error' => 'Too many links in one post.']);
    exit;
  }
  foreach ($m[2] as $href) {
    $h = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^\s*javascript:/i', $h) || preg_match('/^\s*vbscript:/i', $h) || preg_match('/^\s*data:/i', $h)) {
      http_response_code(400);
      echo json_encode(['error' => 'Invalid link URL.']);
      exit;
    }
  }
}

/* --------------------------------------------------
 * 5) Rate limit posting (basic anti-spam)
 * -------------------------------------------------- */
$MIN_SECONDS_BETWEEN_POSTS = 8;

$st = $pdo->prepare("SELECT created_at FROM posts WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
$st->execute([':uid' => $user_id]);
$last = $st->fetchColumn();
if ($last) {
  $lastTs = strtotime($last);
  if ($lastTs && (time() - $lastTs) < $MIN_SECONDS_BETWEEN_POSTS) {
    http_response_code(429);
    echo json_encode(['error' => 'You are posting too fast. Please wait a moment.']);
    exit;
  }
}

/* --------------------------------------------------
 * 6) Sanitize HTML (XSS/garbage protection)
 * -------------------------------------------------- */
$clean_html = sjms_sanitize_post_html($body_html);

// If purifier stripped everything, reject
if ($clean_html === '' || $clean_html === strip_tags($clean_html) && trim(strip_tags($clean_html)) === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Post content is empty or not allowed.']);
  exit;
}

try {
  $stmt = $pdo->prepare("
    INSERT INTO posts (thread_id, parent_id, user_id, body_html, created_at, is_deleted, rec)
    VALUES (:thread_id, :parent_id, :user_id, :body_html, NOW(), 0, 0)
  ");
  $stmt->execute([
    ':thread_id' => $thread_id,
    ':parent_id' => $parent_id,
    ':user_id'   => $user_id,
    ':body_html' => $clean_html, // IMPORTANT: store sanitized
  ]);

  $postId = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare("
    SELECT
      p.id,
      p.thread_id,
      p.parent_id,
      p.user_id,
      p.body_html,
      p.created_at,
      p.is_deleted,
      p.rec           AS rec_count,
      0               AS has_rec,
      u.username,
      u.avatar_path
    FROM posts p
    JOIN users u ON u.id = p.user_id
    WHERE p.id = :id
  ");
  $stmt->execute([':id' => $postId]);
  $post = $stmt->fetch();

  if (!$post) throw new RuntimeException('Post not found after insert');

  echo json_encode($post);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error'   => 'Server error',
    'details' => $e->getMessage(),
  ]);
}
