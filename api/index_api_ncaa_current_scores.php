<?php
// public/api/index_api_ncaa_current_scores.php
//
// Returns current live scores for given NCAA contest IDs using your NCAA API.
// Response shape (matches NHL side):
// { ok: true, games: { [id]: { home, away, label, is_live, is_intermission, is_final, status, period, minutes, seconds } } }
//
// PERFORMANCE FIXES:
// - Fetch ALL boxscores in parallel via curl_multi (instead of N serial file_get_contents calls)
// - Fail fast (short timeouts) since NCAA API is localhost
// - Micro-cache the computed output for a few seconds to avoid stampedes
// - No success logging (only optional debug)
//
// LOGIC FIXES:
// - Treat NCAA boxscore status "I" as LIVE (your Node boxscore uses "I" for in-progress)
// - Build label from period+clock when live, and "Final" only when status says final

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';

// ---------------------------
// NCAA API base
// ---------------------------
if (!defined('NCAA_API_BASE')) {
  define('NCAA_API_BASE', 'http://127.0.0.1:3000');
}

$debug = !empty($_GET['debug']);

// ---------------------------
// Tiny file cache helpers (local to this endpoint)
// Cache dir: /cache (sibling of /public)
// ---------------------------
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

// ---------------------------
// Input: ?game_ids=6498537,6498917
// ---------------------------
if (!isset($_GET['game_ids'])) {
  echo json_encode(['ok' => false, 'error' => 'Missing game_ids']);
  exit;
}

$ids = explode(',', (string)$_GET['game_ids']);
$ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
$ids = array_values(array_filter($ids, function($v) {
  return ctype_digit((string)$v);
}));

if (!$ids) {
  echo json_encode(['ok' => false, 'error' => 'No valid game_ids']);
  exit;
}

// Keep it sane (protect PHP/Node)
$MAX_IDS = 60;
if (count($ids) > $MAX_IDS) {
  $ids = array_slice($ids, 0, $MAX_IDS);
}

// ---------------------------
// Micro-cache output (prevents stampede across users)
// ---------------------------
$cacheKey = 'ncaa_current_scores_' . md5(implode(',', $ids));
$cached = file_cache_get($cacheKey, 8); // 8 second cache
if (is_array($cached)) {
  echo json_encode(['ok' => true, 'games' => $cached]);
  exit;
}

// ---------------------------
// DB lookup for scheduled time label
// Prefer ncaa_games.start_time_local_str else ncaa_games.start_time (TIME)
// ---------------------------
$startInfo = [];
$in = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("
  SELECT game_id, start_time_local_str, start_time
  FROM ncaa_games
  WHERE game_id IN ($in)
");

foreach ($ids as $i => $gid) {
  $stmt->bindValue($i + 1, (int)$gid, PDO::PARAM_INT);
}
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
  $gid = (string)(int)$r['game_id'];
  $startInfo[$gid] = [
    'local_str' => isset($r['start_time_local_str']) ? (string)$r['start_time_local_str'] : '',
    'time'      => isset($r['start_time']) ? (string)$r['start_time'] : '',
  ];
}

// ---------------------------
// Helpers
// ---------------------------
function fmt_ampm_from_local_str($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/\s*(ET|EST|EDT)\s*$/i', '', $s);
  $s = preg_replace('/\s+/', '', $s); // "07:00PM"
  if (!preg_match('/^\d{1,2}:\d{2}(AM|PM)$/i', $s)) return '';
  $dt = DateTime::createFromFormat('g:ia', strtolower($s));
  return $dt ? $dt->format('g:i A') : '';
}

function fmt_ampm_from_time($mysqlTime) {
  $t = trim((string)$mysqlTime);
  if ($t === '') return '';
  if (preg_match('/^\d{2}:\d{2}$/', $t)) $t .= ':00';
  if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return '';
  $dt = DateTime::createFromFormat('H:i:s', $t);
  return $dt ? $dt->format('g:i A') : '';
}

function fmt_clock_mmss($minutes, $seconds) {
  if ($minutes === null || $seconds === null) return '';
  if ($minutes === '' || $seconds === '') return '';
  if (!is_numeric($minutes) || !is_numeric($seconds)) return '';
  $m = (int)$minutes;
  $s = (int)$seconds;
  return sprintf('%d:%02d', $m, $s);
}

function normalize_period_label($periodRaw) {
  $p = strtoupper(trim((string)$periodRaw));
  if ($p === '') return '';
  if ($p === '1ST') return '1st';
  if ($p === '2ND') return '2nd';
  if ($p === '3RD') return '3rd';
  if ($p === 'OT')  return 'OT';
  if ($p === 'SO')  return 'SO';
  return $p;
}

/**
 * Extract [homeGoals, awayGoals] from NCAA boxscore payload.
 * Prefers teamBoxscore[].teamStats.goals, maps teamId->isHome from teams[].
 */
function extract_scores_from_boxscore(array $b) {
  $home = null;
  $away = null;

  $teamHomeMap = []; // teamId => bool isHome
  if (!empty($b['teams']) && is_array($b['teams'])) {
    foreach ($b['teams'] as $t) {
      $teamId = isset($t['teamId']) ? (string)$t['teamId'] : '';
      if ($teamId === '') continue;
      $teamHomeMap[$teamId] = !empty($t['isHome']);
    }
  }

  if (!empty($b['teamBoxscore']) && is_array($b['teamBoxscore'])) {
    foreach ($b['teamBoxscore'] as $tb) {
      $teamId = isset($tb['teamId']) ? (string)$tb['teamId'] : '';
      if ($teamId === '') continue;

      $goalsRaw = null;
      if (isset($tb['teamStats']) && is_array($tb['teamStats']) && isset($tb['teamStats']['goals'])) {
        $goalsRaw = $tb['teamStats']['goals'];
      }

      if ($goalsRaw === null || !is_numeric($goalsRaw)) continue;

      $goals = (int)$goalsRaw;
      $isHome = array_key_exists($teamId, $teamHomeMap) ? $teamHomeMap[$teamId] : null;

      if ($isHome === true)  $home = $goals;
      if ($isHome === false) $away = $goals;
    }
  }

  return [$home, $away];
}

/**
 * Build label + booleans based on NCAA boxscore.
 * status:
 *   - "F" => final
 *   - "I" => in-progress (live)
 *   - sometimes "L" could occur; treat as live too
 */
function build_label_from_boxscore(array $b, $scheduledLabel) {
  $status    = strtoupper(trim((string)($b['status'] ?? '')));
  $periodRaw = trim((string)($b['period'] ?? ''));

  // Final?
  if ($status === 'F' || strtoupper($periodRaw) === 'FINAL') {
    return ['Final', false, false, true];
  }

  // Intermission?
  $periodUpper = strtoupper($periodRaw);
  if ($periodUpper !== '' && (strpos($periodUpper, 'INT') !== false || $periodUpper === 'INTERMISSION')) {
    // Best-effort: "1ST INT" -> "1st INT"
    $p = 'INT';
    if (preg_match('/\b(1|2|3)\b/', $periodUpper, $m)) {
      $num = (int)$m[1];
      $p = ($num === 1 ? '1st' : ($num === 2 ? '2nd' : '3rd')) . ' INT';
    } elseif ($periodUpper === 'OT INT') {
      $p = 'OT INT';
    }
    return [$p, false, true, false];
  }

  // Live? ✅ treat "I" as live
  if ($status === 'I' || $status === 'L') {
    $p = normalize_period_label($periodRaw);
    $clock = fmt_clock_mmss($b['minutes'] ?? null, $b['seconds'] ?? null);

    if ($p !== '' && $clock !== '') $label = $p . ' – ' . $clock;
    elseif ($p !== '') $label = $p;
    elseif ($clock !== '') $label = $clock;
    else $label = 'Live';

    return [$label, true, false, false];
  }

  // Pregame / unknown
  return [($scheduledLabel !== '' ? $scheduledLabel : 'Scheduled'), false, false, false];
}

// ---------------------------
// Parallel fetch: curl_multi
// ---------------------------
function ncaa_multi_get_json(array $paths, int $timeoutSeconds = 2, bool $debug = false): array {
  $base = rtrim(NCAA_API_BASE, '/');

  $mh = curl_multi_init();
  $chs = [];
  $results = [];

  foreach ($paths as $key => $path) {
    $url = $base . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $timeoutSeconds,
      CURLOPT_CONNECTTIMEOUT => 1,
      CURLOPT_FAILONERROR    => false,
      CURLOPT_HTTPHEADER     => [],
    ]);

    curl_multi_add_handle($mh, $ch);
    $chs[$key] = $ch;
  }

  $running = null;
  do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.2);
  } while ($running > 0);

  foreach ($chs as $key => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($code >= 200 && $code < 300 && $body) {
      $json = json_decode($body, true);
      $results[$key] = is_array($json) ? $json : null;
      if ($debug && !is_array($json)) {
        error_log("[NCAA multi] non-JSON for {$key} (HTTP {$code})");
      }
    } else {
      $results[$key] = null;
      if ($debug) {
        error_log("[NCAA multi] fetch failed for {$key} (HTTP {$code})");
      }
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }

  curl_multi_close($mh);
  return $results;
}

// ---------------------------
// Prefetch all boxscores in parallel
// ---------------------------
$paths = [];
foreach ($ids as $id) {
  $idStr = (string)(int)$id;
  $paths[$idStr] = "game/{$idStr}/boxscore";
}
$boxscores = ncaa_multi_get_json($paths, 2, $debug);

// ---------------------------
// Main loop: build output
// ---------------------------
$out = [];

foreach ($ids as $id) {
  $idStr = (string)(int)$id;

  // scheduled time label (fallback)
  $scheduledLabel = '';
  if (isset($startInfo[$idStr])) {
    $scheduledLabel = fmt_ampm_from_local_str($startInfo[$idStr]['local_str']);
    if ($scheduledLabel === '') $scheduledLabel = fmt_ampm_from_time($startInfo[$idStr]['time']);
  }

  // Boxscore
  $b = $boxscores[$idStr] ?? null;

  if (is_array($b) && isset($b['status'])) {
    [$home, $away] = extract_scores_from_boxscore($b);

    [$label, $isLive, $isInt, $isFinal] = build_label_from_boxscore($b, $scheduledLabel);

    $out[$idStr] = [
      'home'            => ($home !== null ? (int)$home : null),
      'away'            => ($away !== null ? (int)$away : null),
      'label'           => $label,
      'is_final'        => (bool)$isFinal,
      'is_live'         => (bool)$isLive,
      'is_intermission' => (bool)$isInt,

      // pass-through raw NCAA fields
      'status'          => strtoupper(trim((string)($b['status'] ?? ''))),
      'period'          => (string)($b['period'] ?? ''),
      'minutes'         => isset($b['minutes']) ? (int)$b['minutes'] : null,
      'seconds'         => isset($b['seconds']) ? (int)$b['seconds'] : null,
    ];
    continue;
  }

  // Unknown game fallback
  $out[$idStr] = [
    'home'            => null,
    'away'            => null,
    'label'           => ($scheduledLabel !== '' ? $scheduledLabel : 'Scheduled'),
    'is_final'        => false,
    'is_live'         => false,
    'is_intermission' => false,
    'status'          => '',
    'period'          => '',
    'minutes'         => null,
    'seconds'         => null,
  ];
}

// Cache computed map (not wrapped)
file_cache_set($cacheKey, $out);

echo json_encode(['ok' => true, 'games' => $out]);
exit;
