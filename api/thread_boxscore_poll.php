<?php
// public/api/thread_boxscore_poll.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json; charset=utf-8');

/* ---------------------------
   Tiny file cache (HTML)
   Cache dir: /cache (sibling of /public)
--------------------------- */
function file_cache_dir(): string {
  return __DIR__ . '/../../cache';
}
function file_cache_path(string $key): string {
  $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
  return rtrim(file_cache_dir(), '/') . '/' . $safe . '.json';
}
function file_cache_get(string $key, int $ttlSeconds) {
  $path = file_cache_path($key);
  if (!is_file($path)) return null;
  if ($ttlSeconds > 0 && (time() - @filemtime($path)) >= $ttlSeconds) return null;
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return null;
  $data = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}
function file_cache_set(string $key, $data): void {
  $dir = file_cache_dir();
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $path = file_cache_path($key);
  @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
}

/* ---------------------------
   League param + normalization
--------------------------- */
$leagueRaw = isset($_GET['league']) ? (string)$_GET['league'] : '';
$league    = strtolower(trim($leagueRaw));
if ($league === 'ncaah') $league = 'ncaa';

$debug = !empty($_GET['debug']);

if ($league !== 'nhl' && $league !== 'ncaa') {
  http_response_code(400);
  echo json_encode([
    'ok'    => false,
    'error' => 'Invalid league',
    'recv'  => $debug ? $leagueRaw : null,
  ]);
  exit;
}

try {

  /* ---------------------------
     NHL
  --------------------------- */
  if ($league === 'nhl') {
    require_once __DIR__ . '/../includes/thread_nhl_boxscore.php';

    $msfGameIdRaw = $_GET['msf_game_id'] ?? 0;
    $msfGameId    = (int)$msfGameIdRaw;

    if (!$msfGameId) {
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'Missing msf_game_id',
        'recv' => $debug ? $msfGameIdRaw : null,
      ]);
      exit;
    }

    // Very short cache for NHL HTML (optional)
    $cacheKey = 'boxscore_html_nhl_' . $msfGameId;
    $cached = file_cache_get($cacheKey, 10);
    if (is_array($cached) && isset($cached['html'])) {
      echo json_encode(['ok' => true, 'html' => (string)$cached['html']]);
      exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
    $stmt->execute([':gid' => $msfGameId]);
    $gameRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$gameRow) {
      echo json_encode(['ok' => true, 'html' => '']);
      exit;
    }

    $html = sjms_get_boxscore_html($pdo, $gameRow);

    file_cache_set($cacheKey, ['html' => $html]);

    echo json_encode([
      'ok'   => true,
      'html' => $html,
      'debug' => $debug ? ['league' => $league, 'msf_game_id' => $msfGameId] : null,
    ]);
    exit;
  }

  /* ---------------------------
     NCAA
  --------------------------- */
  if ($league === 'ncaa') {
    require_once __DIR__ . '/thread_ncaa_boxscore.php';

    $contestIdRaw = $_GET['contest_id'] ?? 0;
    $contestId    = (int)$contestIdRaw;

    if (!$contestId) {
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'Missing contest_id',
        'recv' => $debug ? $contestIdRaw : null,
      ]);
      exit;
    }

    // ✅ Don’t require game_date from client.
    // Look it up: prefer gameday_threads.game_date (most accurate for your thread)
    // fallback ncaa_games.game_date if you have it.
    $gameDate = '';
    $stmt = $pdo->prepare("
      SELECT t.game_date
      FROM gameday_threads t
      WHERE t.ncaa_game_id = :cid
      ORDER BY t.game_date DESC, t.created_at DESC
      LIMIT 1
    ");
    $stmt->execute([':cid' => $contestId]);
    $gameDate = (string)($stmt->fetchColumn() ?: '');

    if ($gameDate === '') {
      // fallback attempt
      $stmt = $pdo->prepare("SELECT game_date FROM ncaa_games WHERE game_id = :cid LIMIT 1");
      $stmt->execute([':cid' => $contestId]);
      $gameDate = (string)($stmt->fetchColumn() ?: '');
    }

    if ($gameDate === '') {
      // Last resort: if client sent it, use it
      $gameDate = trim((string)($_GET['game_date'] ?? ''));
    }

    if ($gameDate === '') {
      http_response_code(400);
      echo json_encode([
        'ok' => false,
        'error' => 'Missing game_date (could not resolve from DB)',
        'debug' => $debug ? ['contest_id' => $contestId] : null,
      ]);
      exit;
    }

    // ✅ Cache NCAA HTML a bit longer; it’s expensive and changes at most every few seconds.
    // Use a short “bucket” so it naturally refreshes while live.
    $bucket = (int)floor(time() / 8); // changes every 8s
    $cacheKey = 'boxscore_html_ncaa_' . $contestId . '_' . $bucket;

    $cached = file_cache_get($cacheKey, 20); // 20s TTL; bucket ensures refresh anyway
    if (is_array($cached) && isset($cached['html'])) {
      echo json_encode(['ok' => true, 'html' => (string)$cached['html']]);
      exit;
    }

    // Build HTML
    $html = sjms_get_ncaa_boxscore_html($pdo, $contestId, $gameDate);

    file_cache_set($cacheKey, ['html' => $html]);

    echo json_encode([
      'ok'   => true,
      'html' => $html,
      'debug' => $debug ? ['league' => $league, 'contest_id' => $contestId, 'game_date' => $gameDate] : null,
    ]);
    exit;
  }

} catch (Exception $e) {
  error_log('[boxscore_poll] ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
  exit;
}
