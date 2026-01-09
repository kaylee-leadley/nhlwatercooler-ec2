<?php
// public/admin_delete_thread.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

$thread_id = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0;
if (!$thread_id) {
  http_response_code(400);
  echo 'Invalid thread_id';
  exit;
}

try {
  // Optionally use a transaction for safety
  $pdo->beginTransaction();

  // Delete all posts in this thread
  $stmt = $pdo->prepare("DELETE FROM posts WHERE thread_id = :id");
  $stmt->execute([':id' => $thread_id]);

  // Delete the thread itself
  $stmt = $pdo->prepare("DELETE FROM gameday_threads WHERE id = :id");
  $stmt->execute([':id' => $thread_id]);

  $pdo->commit();

  // Back to main thread list
  header('Location: index.php');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo 'Server error';
}
