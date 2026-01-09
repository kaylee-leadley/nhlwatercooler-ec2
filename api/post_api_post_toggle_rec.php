<?php
// public/post_api_post_toggle_rec.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_login();

header('Content-Type: application/json');

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Accept both x-www-form-urlencoded (current JS) and JSON just in case
$post_id = 0;
if (isset($_POST['post_id'])) {
    $post_id = (int)$_POST['post_id'];
} else {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['post_id'])) {
            $post_id = (int)$decoded['post_id'];
        }
    }
}

if (!$post_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'post_id and login required']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check if this user already rec'd the post
    $stmt = $pdo->prepare("
        SELECT id
        FROM post_recs
        WHERE post_id = :post_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':post_id' => $post_id,
        ':user_id' => $user_id,
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Remove rec
        $stmt = $pdo->prepare("DELETE FROM post_recs WHERE id = :id");
        $stmt->execute([':id' => $existing['id']]);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET rec = GREATEST(rec - 1, 0)
            WHERE id = :post_id
        ");
        $stmt->execute([':post_id' => $post_id]);

        $state = 'unrec';
    } else {
        // Add rec
        $stmt = $pdo->prepare("
            INSERT INTO post_recs (post_id, user_id)
            VALUES (:post_id, :user_id)
        ");
        $stmt->execute([
            ':post_id' => $post_id,
            ':user_id' => $user_id,
        ]);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET rec = rec + 1
            WHERE id = :post_id
        ");
        $stmt->execute([':post_id' => $post_id]);

        $state = 'rec';
    }

    // Get fresh count
    $stmt = $pdo->prepare("SELECT rec FROM posts WHERE id = :post_id");
    $stmt->execute([':post_id' => $post_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'state'     => $state,
        'rec_count' => (int)($row['rec'] ?? 0),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Helpful while developing – you’ll see the real DB error in the response
    http_response_code(500);
    echo json_encode([
        'error'   => 'Server error',
        'details' => $e->getMessage(),
    ]);
}
