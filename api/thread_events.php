<?php
// public/thread_events.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_login();          // just for auth
@session_write_close();   // don't hold the session lock during SSE

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Tell the browser how often to retry if the connection closes
echo "retry: 5000\n\n";

ignore_user_abort(true);
set_time_limit(0);

$threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$lastId   = isset($_GET['last_id'])   ? (int)$_GET['last_id']   : 0;

if (!$threadId) {
  echo "event: error\n";
  echo "data: " . json_encode(['error' => 'thread_id required']) . "\n\n";
  @ob_flush(); @flush();
  exit;
}

// Prepare once, reuse in the loop
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
    u.username,
    u.avatar_path
  FROM posts p
  JOIN users u ON u.id = p.user_id
  WHERE p.thread_id = :thread_id
    AND p.id > :last_id
  ORDER BY p.id ASC
  LIMIT 50
");

// loop settings
$loops     = 0;
$maxLoops  = 200; // hard cap (e.g. ~a few minutes)
$sleep     = 1;   // start with 1 second
$maxSleep  = 15;  // backoff ceiling

while (!connection_aborted() && $loops < $maxLoops) {
  $loops++;

  $stmt->execute([
    ':thread_id' => $threadId,
    ':last_id'   => $lastId,
  ]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($rows) {
    // reset backoff when we see activity
    $sleep = 1;

    foreach ($rows as $row) {
      $lastId = (int)$row['id'];

      echo "id: {$lastId}\n";
      echo "event: post\n";
      echo "data: " . json_encode($row) . "\n\n";
    }
  } else {
    echo "event: ping\n";
    echo "data: {}\n\n";

    // exponential backoff up to $maxSleep
    $sleep = min($sleep * 2, $maxSleep);
  }

  @ob_flush();
  @flush();

  if (connection_aborted()) {
    break;
  }

  sleep($sleep);
}
