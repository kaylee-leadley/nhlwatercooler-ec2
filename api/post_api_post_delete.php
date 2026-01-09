<?php
// public/post_api_post_delete.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_login();

header('Content-Type: application/json');

// Only admins can delete (matches UI where only admins see "Delete")
if (empty($_SESSION['is_admin'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

// Read JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$post_id = isset($data['post_id']) ? (int)$data['post_id'] : 0;
if (!$post_id) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid post_id']);
  exit;
}

try {
  // Ensure the post exists and isn't already deleted
  $check = $pdo->prepare("
    SELECT id
    FROM posts
    WHERE id = :id AND is_deleted = 0
    LIMIT 1
  ");
  $check->execute([':id' => $post_id]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Post not found']);
    exit;
  }

  $pdo->beginTransaction();

  // Collect this post + all descendants (DOWN the tree only)
  $toVisit = [$post_id];
  $allIds  = []; // assoc set: id => true

  while (!empty($toVisit)) {
    // Add current layer to set
    foreach ($toVisit as $id) {
      $allIds[$id] = true;
    }

    // Find direct children of all posts in this layer
    $placeholders = implode(',', array_fill(0, count($toVisit), '?'));
    $stmtChildren = $pdo->prepare("
      SELECT id
      FROM posts
      WHERE parent_id IN ($placeholders)
        AND is_deleted = 0
    ");
    $stmtChildren->execute($toVisit);
    $children = $stmtChildren->fetchAll(PDO::FETCH_COLUMN, 0);

    $toVisit = [];
    foreach ($children as $childId) {
      $childId = (int)$childId;
      if ($childId && !isset($allIds[$childId])) {
        $toVisit[] = $childId;
      }
    }
  }

  if (empty($allIds)) {
    // Should never happen if root exists, but be defensive
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nothing to delete']);
    exit;
  }

  $idList        = array_keys($allIds);
  $placeholderIn = implode(',', array_fill(0, count($idList), '?'));

  // Soft-delete all: this post + its children / grandchildren / etc.
  $update = $pdo->prepare("
    UPDATE posts
       SET is_deleted = 1
     WHERE id IN ($placeholderIn)
  ");
  $update->execute($idList);

  $pdo->commit();

  echo json_encode([
    'ok'          => true,
    'deleted_ids' => $idList,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => 'Server error',
    'detail'=> $e->getMessage(),
  ]);
}
