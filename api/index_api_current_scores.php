<?php
// public/api/index_api_current_scores.php
//
// Returns current live scores for given MSF game IDs using MySportsFeeds API.
// Uses short-lived cache (~20s) to avoid hitting API limits.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$msfConfig = require_once __DIR__ . '/../../config/msf.php';

$apiKey = $msfConfig['api_key'] ?? '';
if (!$apiKey) {
  echo json_encode(['ok' => false, 'error' => 'Missing API key']);
  exit;
}

$tzName = $msfConfig['timezone'] ?? 'America/New_York';
$tz     = new DateTimeZone($tzName);
$now    = new DateTime('now', $tz);

// --------------- INPUT: ?game_ids=123,456 ----------------
$param = $_GET['game_ids'] ?? '';
if (!$param) {
  echo json_encode(['ok' => false, 'error' => 'Missing game_ids']);
  exit;
}
$gameIds = array_values(array_filter(array_map('intval', explode(',', $param))));
if (!$gameIds) {
  echo json_encode(['ok' => false, 'error' => 'No valid game IDs']);
  exit;
}

// --------------- DB lookup to map IDs → away/home/date ---------------
require_once __DIR__ . '/../../config/db.php';
$in = implode(',', array_fill(0, count($gameIds), '?'));
$stmt = $pdo->prepare("
  SELECT msf_game_id, game_date, away_team_abbr, home_team_abbr, season
  FROM msf_games
  WHERE msf_game_id IN ($in)
");
foreach ($gameIds as $i => $gid) {
  $stmt->bindValue($i + 1, $gid, PDO::PARAM_INT);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
  echo json_encode(['ok' => true, 'games' => new stdClass()]);
  exit;
}

// --------------- Cache + MSF fetch helper ---------------
function msf_fetch_boxscore($season, $date, $away, $home, $apiKey) {
  $root = dirname(__DIR__, 2);
  $cacheDir = $root . '/cache/msf/live';
  if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
  }

  $key  = md5("$season|$date|$away|$home");
  $file = "$cacheDir/$key.json";
  $ttl  = 20; // seconds

  if (file_exists($file) && (time() - filemtime($file) < $ttl)) {
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if ($data) {
      return $data;
    }
  }

  $url = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}-regular/games/"
       . str_replace('-', '', $date) . "-{$away}-{$home}/boxscore.json";

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => 'gzip',
    CURLOPT_HTTPHEADER => [
      'Authorization: Basic ' . base64_encode($apiKey . ':MYSPORTSFEEDS'),
    ],
  ]);
  $resp = curl_exec($ch);
  if ($resp === false) {
    return null;
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code >= 200 && $code < 300) {
    @file_put_contents($file, $resp);
    return json_decode($resp, true);
  }

  return null;
}

// --------------- Build response ---------------
$result = [];

foreach ($rows as $r) {
  $gid    = (int)$r['msf_game_id'];
  $away   = strtoupper($r['away_team_abbr']);
  $home   = strtoupper($r['home_team_abbr']);
  $date   = $r['game_date'];
  $season = $r['season'] ?? '';

  if (!$away || !$home || !$date || !$season) {
    continue;
  }

  $box = msf_fetch_boxscore($season, $date, $away, $home, $apiKey);
  if (!$box || empty($box['scoring'])) {
    continue;
  }

  $scoring    = $box['scoring'];
  $awayScore  = (int)($scoring['awayScoreTotal'] ?? 0);
  $homeScore  = (int)($scoring['homeScoreTotal'] ?? 0);

  $periodNum    = $scoring['currentPeriod'] ?? null;
  $secondsRem   = $scoring['currentPeriodSecondsRemaining'] ?? null;
  $intermission = $scoring['currentIntermission'] ?? null; // may be 0,1,2,...
  $playedStatus = strtolower($box['game']['playedStatus'] ?? '');

  $label          = 'Game Day';
  $isLive         = false;
  $isIntermission = false;
  $isFinal        = false;

  // Treat any status starting with "completed" as final:
  // "COMPLETED", "COMPLETED_PENDING_REVIEW", etc.
  if ($playedStatus !== '' && strncmp($playedStatus, 'completed', 9) === 0) {
    $label   = 'Final';
    $isFinal = true;

  } elseif ($intermission !== null && (int)$intermission > 0) {
    // Intermission, even if currentPeriod is null
    $intNum = (int)$intermission;
    switch ($intNum) {
      case 1:  $label = '1st INT'; break;
      case 2:  $label = '2nd INT'; break;
      case 3:  $label = '3rd INT'; break;
      default: $label = 'OT INT';  break;
    }
    $isIntermission = true;
    $isLive         = false;

  } elseif ($periodNum !== null && $periodNum > 0) {
    // Game in progress (period known)
    switch ($periodNum) {
      case 1: $p = '1st'; break;
      case 2: $p = '2nd'; break;
      case 3: $p = '3rd'; break;
      case 4: $p = 'OT';  break;
      case 5: $p = 'SO';  break;
      default: $p = "P{$periodNum}";
    }

    if ($secondsRem !== null) {
      $min = floor($secondsRem / 60);
      $sec = str_pad($secondsRem % 60, 2, '0', STR_PAD_LEFT);
      $label = "{$p} – {$min}:{$sec}";
    } else {
      $label = $p;
    }
    $isLive = true;
  }

  $result[$gid] = [
    'away'            => $awayScore,
    'home'            => $homeScore,
    'label'           => $label,
    'is_live'         => $isLive,
    'is_intermission' => $isIntermission,
    'is_final'        => $isFinal,
  ];
}

echo json_encode(['ok' => true, 'games' => $result]);
