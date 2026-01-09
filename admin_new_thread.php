<?php
// public/admin_new_thread.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_admin();

$error = '';
$title = '';
$game_date = '';
$description_html = '';

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title            = trim($_POST['title'] ?? '');
  $game_date        = $_POST['game_date'] ?? '';
  $description_html = $_POST['description_html'] ?? '';

  // Uploaded image URL we’ll store in DB (or NULL)
  $header_image_url = null;

  if (!$title || !$game_date || !$description_html) {
    $error = 'Title, game date, and description are required.';
  } else {
    // ---------- Optional image upload ----------
    if (!empty($_FILES['header_image']['name'])) {
      $file = $_FILES['header_image'];

      if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
          $error = 'There was an error uploading the image.';
        } else {
          $tmpPath = $file['tmp_name'];

          // Validate MIME type
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime  = $finfo->file($tmpPath);

          $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
          ];

          if (!isset($allowed[$mime])) {
            $error = 'Please upload a JPG, PNG, GIF, or WEBP image.';
          } else {
            // Build filesystem + web paths
            $uploadDirFs  = realpath(__DIR__ . '/assets/img/gdt');
            if ($uploadDirFs === false) {
              $error = 'Upload directory does not exist.';
            } else {
              $basename = bin2hex(random_bytes(8));
              $ext      = $allowed[$mime];
              $filename = $basename . '.' . $ext;

              $destFs   = $uploadDirFs . DIRECTORY_SEPARATOR . $filename;
              $destWeb  = '/assets/img/gdt/' . $filename; // used in HTML

              if (!move_uploaded_file($tmpPath, $destFs)) {
                $error = 'Failed to save uploaded image.';
              } else {
                $header_image_url = $destWeb;
              }
            }
          }
        }
      }
    }

    // Only insert if we haven’t tripped any upload/validation errors
    if ($error === '') {
      try {
        $stmt = $pdo->prepare("
          INSERT INTO gameday_threads
            (title, game_date, header_image_url, description_html, created_by)
          VALUES
            (:title, :game_date, :header_image_url, :description_html, :created_by)
        ");
        $stmt->execute([
          ':title'            => $title,
          ':game_date'        => $game_date,
          ':header_image_url' => $header_image_url,
          ':description_html' => $description_html,
          ':created_by'       => $_SESSION['user_id'],
        ]);

        $id = $pdo->lastInsertId();
        header('Location: thread.php?id=' . (int) $id);
        exit;
      } catch (Throwable $e) {
        $error = 'Error creating thread.';
      }
    }
  }
}

// Page meta for header include (with Summernote)
$pageTitle = 'New Gameday Thread – Sharks Message Board';
$bodyClass = 'page-admin-thread team-all';
$pageCss   = [
  'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css',
  '/assets/css/admin_new_thread.css',
];
$pageJs    = [
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js',
  '/assets/js/admin_new_thread.js',
];

include __DIR__ . '/includes/header.php';
?>

<div class="admin-thread-main">
  <div class="top-bar">
    <h1>Create Gameday Thread</h1>
    <nav class="top-bar__nav">
      <a href="index.php">← Back to Threads</a>
    </nav>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" class="thread-form" enctype="multipart/form-data">
    <div class="form-row">
      <label for="title">Title</label>
      <input
        type="text"
        id="title"
        name="title"
        required
        value="<?= htmlspecialchars($title) ?>"
      >
    </div>

    <div class="form-row form-row--two-col">
      <div class="form-field">
        <label for="game_date">Game Date</label>
        <input
          type="date"
          id="game_date"
          name="game_date"
          required
          value="<?= htmlspecialchars($game_date) ?>"
        >
      </div>

      <div class="form-field">
        <label for="header_image">
          Header Image
          <span class="label-note">(optional – JPG/PNG/GIF/WEBP)</span>
        </label>
        <input
          type="file"
          id="header_image"
          name="header_image"
          accept=".jpg,.jpeg,.png,.gif,.webp"
        >
      </div>
    </div>

    <div class="form-row">
      <label for="description_html">Description</label>
      <textarea
        id="description_html"
        name="description_html"
        rows="10"
        required
      ><?= htmlspecialchars($description_html) ?></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="button-primary">Create Thread</button>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
