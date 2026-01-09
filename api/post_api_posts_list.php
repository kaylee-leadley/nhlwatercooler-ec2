<?php
// public/api/post_api_posts_list.php

// For JSON APIs, don't spew notices into the response:
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
// IMPORTANT: guests must be able to read comments
// require_login();

header('Content-Type: application/json; charset=utf-8');

$thread_id     = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (!$thread_id) {
  http_response_code(400);
  echo json_encode(['error' => 'thread_id required']);
  exit;
}

try {
  $sql = "
    SELECT
      p.id,
      p.thread_id,
      p.parent_id,
      p.user_id,
      p.body_html,
      p.created_at,
      p.is_deleted,
      p.rec               AS rec_count,
      (pr.id IS NOT NULL) AS has_rec,
      u.username,
      u.avatar_path
    FROM posts p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN post_recs pr
      ON pr.post_id = p.id
     AND pr.user_id = :current_user_id
    WHERE p.thread_id = :thread_id
    ORDER BY p.created_at ASC
  ";

  $params = [
    ':thread_id'       => $thread_id,
    ':current_user_id' => $currentUserId,
  ];

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($posts);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Server error',
  ]);
}
