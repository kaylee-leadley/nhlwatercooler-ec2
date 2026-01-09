<?php
// public/admin_edit_thread.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_admin();

$thread_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$thread_id) {
  http_response_code(404);
  echo 'Thread not found';
  exit;
}

// Fetch existing thread
$stmt = $pdo->prepare("
  SELECT id, title, game_date, header_image_url, description_html
  FROM gameday_threads
  WHERE id = :id
");
$stmt->execute([':id' => $thread_id]);
$thread = $stmt->fetch();

if (!$thread) {
  http_response_code(404);
  echo 'Thread not found';
  exit;
}

$error = '';
// Start with existing image path; we only change it if a new file is uploaded
$headerImagePath = $thread['header_image_url'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title       = trim($_POST['title'] ?? '');
  $game_date   = trim($_POST['game_date'] ?? '');
  $description = trim($_POST['description_html'] ?? '');
  $removeImage = !empty($_POST['remove_header_image']); // new checkbox

  if ($title === '' || $game_date === '') {
    $error = 'Title and game date are required.';
  } else {

    // ---- Handle optional image upload ----
    if (!empty($_FILES['header_image']['name']) &&
        $_FILES['header_image']['error'] === UPLOAD_ERR_OK) {

      $tmpName  = $_FILES['header_image']['tmp_name'];
      $origName = basename($_FILES['header_image']['name']);
      $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

      $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

      if (!in_array($ext, $allowed, true)) {
        $error = 'Header image must be JPG, PNG, GIF, or WEBP.';
      } elseif (!is_uploaded_file($tmpName)) {
        $error = 'Upload failed – invalid temp file.';
      } else {
        $uploadDirFs = __DIR__ . '/assets/img/gdt/';
        if (!is_dir($uploadDirFs)) {
          mkdir($uploadDirFs, 0755, true);
        }

        $fileName = bin2hex(random_bytes(8)) . '.' . $ext;
        $destFs   = $uploadDirFs . $fileName;

        if (!move_uploaded_file($tmpName, $destFs)) {
          $error = 'Could not move uploaded file.';
        } else {
          // Store as web path used by index.php / thread.php
          $headerImagePath = '/assets/img/gdt/' . $fileName;
        }
      }
    }

    // ---- Handle explicit removal (only if no new upload succeeded) ----
    if ($error === '' && $removeImage && empty($_FILES['header_image']['name'])) {
      if (!empty($headerImagePath)) {
        // Try to delete the old file if it's under our gdt directory
        $relative = ltrim($headerImagePath, '/'); // normalize "/assets/..." -> "assets/..."
        $fsPath   = __DIR__ . '/../' . $relative;

        if (is_file($fsPath)) {
          @unlink($fsPath);
        }
      }
      $headerImagePath = null;
    }

    // Only attempt DB update if we did not hit an upload/removal error
    if ($error === '') {
      try {
        $stmt = $pdo->prepare("
          UPDATE gameday_threads
          SET title = :title,
              game_date = :game_date,
              header_image_url = :header_image_url,
              description_html = :description_html
          WHERE id = :id
        ");
        $stmt->execute([
          ':title'            => $title,
          ':game_date'        => $game_date,
          ':header_image_url' => $headerImagePath, // may be null, existing, or new value
          ':description_html' => $description,
          ':id'               => $thread_id,
        ]);

        // Redirect back to the thread after saving
        header('Location: thread.php?id=' . (int)$thread_id);
        exit;
      } catch (Throwable $e) {
        $error = 'Error saving thread.';
      }
    }
  }

  // refresh in-memory thread values for re-render
  $thread['title']            = $title;
  $thread['game_date']        = $game_date;
  $thread['header_image_url'] = $headerImagePath;
  $thread['description_html'] = $description;
}

// Page meta – reuse same CSS/JS as "new thread" page (includes Summernote + custom JS)
$pageTitle = 'Edit Thread – Sharks Message Board';
$bodyClass = 'page-admin-thread team-all';
$pageCss   = [
  'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css',
  '../assets/css/admin_new_thread.css',
];
$pageJs    = [
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js',
  '../assets/js/admin_new_thread.js',
];

include __DIR__ . '/includes/header.php';
?>

<div class="admin-thread-main">
  <div class="top-bar">
    <h1>Edit Gameday Thread</h1>
    <div class="top-bar__nav">
      <a href="thread.php?id=<?= (int)$thread_id ?>">← Back to Thread</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="thread-form">
    <div class="form-row form-row--two-col">
      <div class="form-field">
        <label for="title">Title</label>
        <input
          type="text"
          id="title"
          name="title"
          value="<?= htmlspecialchars($thread['title']) ?>"
          required
        >
      </div>

      <div class="form-field">
        <label for="game_date">Game Date</label>
        <input
          type="date"
          id="game_date"
          name="game_date"
          value="<?= htmlspecialchars($thread['game_date']) ?>"
          required
        >
      </div>
    </div>

    <div class="form-row form-row--two-col">
      <div class="form-field">
        <label for="header_image">
          Header Image
          <span class="label-note">(optional – JPG/PNG/GIF/WEBP)</span>
        </label>
        <input
          type="file"
          id="header_image"
          name="header_image"
          accept="image/*"
        >
        <?php if (!empty($thread['header_image_url'])): ?>
          <p class="current-image-note">
            Current: <code><?= htmlspecialchars(basename($thread['header_image_url'])) ?></code>
          </p>
          <label class="checkbox-inline">
            <input
              type="checkbox"
              name="remove_header_image"
              value="1"
            >
            Remove current header image
          </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row">
      <label for="description_html">Description</label>
      <textarea
        id="description_html"
        name="description_html"
        rows="10"
      ><?= htmlspecialchars($thread['description_html'] ?? '') ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="button-primary">Save Changes</button>
      <a href="thread.php?id=<?= (int)$thread_id ?>" class="button-ghost">Cancel</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
