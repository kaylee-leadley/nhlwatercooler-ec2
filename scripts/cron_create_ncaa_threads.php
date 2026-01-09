<?php
// public/scripts/cron_create_ncaa_threads.php

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "CLI only.\n";
  exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__, 2); // /home/leadley/website/SJS/gameday-board
require_once $root . '/config/db.php';

$tz = new DateTimeZone('America/New_York');
$ADMIN_USER_ID = 1;

function log_msg($msg) {
  echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function ncaa_format_ranked_team($short, $rank) {
  $short = (string)$short;
  $rank  = trim((string)$rank);
  if ($rank === '' || $rank === '0') return $short;
  $rank = ltrim($rank, '#');
  return '#' . $rank . ' ' . $short;
}

/**
 * Normalize NCAA start time into MySQL TIME "HH:MM:SS" or null.
 * Prefers ncaa_games.start_time if it's HH:MM[:SS] or datetime-ish.
 * Falls back to parsing start_time_local_str like "07:00PM ET".
 */
function mysql_time_from_ncaa($start_time, $start_time_local_str, $tz) {
  $raw = trim((string)$start_time);

  // A) "HH:MM" or "HH:MM:SS"
  if ($raw !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)) {
    return (strlen($raw) === 5) ? ($raw . ':00') : $raw;
  }

  // B) datetime-ish
  if ($raw !== '') {
    try {
      $dt = new DateTime($raw, $tz);
      return $dt->format('H:i:s');
    } catch (Exception $e) {
      // fall through
    }
  }

  // C) parse "07:00PM ET"
  $localStr = trim((string)$start_time_local_str);
  if ($localStr !== '') {
    $clean = strtoupper($localStr);
    $clean = preg_replace('/\s*(ET|EST|EDT|CT|CST|CDT|MT|MST|MDT|PT|PST|PDT)\s*$/', '', $clean);
    $clean = trim($clean);
    $clean = preg_replace('/(\d)(AM|PM)$/', '$1 $2', $clean);

    $dt2 = DateTime::createFromFormat('g:i A', $clean, $tz);
    if ($dt2) return $dt2->format('H:i:s');

    $dt3 = DateTime::createFromFormat('g:ia', strtolower(str_replace(' ', '', $clean)), $tz);
    if ($dt3) return $dt3->format('H:i:s');
  }

  return null;
}

// ---------------- CLI args ----------------
$startArg = $argv[1] ?? null;
$endArg   = $argv[2] ?? null;

if ($startArg) {
  $start = new DateTime($startArg, $tz);
} else {
  $start = new DateTime('today', $tz);
}
if ($endArg) {
  $end = new DateTime($endArg, $tz);
} else {
  $end = clone $start;
}

$start->setTime(0, 0, 0);
$end->setTime(0, 0, 0);

$current = clone $start;

while ($current <= $end) {
  $dateYmd = $current->format('Y-m-d');
  log_msg("=== Upserting NCAA threads for {$dateYmd} ===");

  $gamesStmt = $pdo->prepare("
    SELECT *
    FROM ncaa_games
    WHERE game_date = :game_date
    ORDER BY start_time, id
  ");
  $gamesStmt->execute([':game_date' => $dateYmd]);
  $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$games) {
    log_msg("No ncaa_games rows for {$dateYmd}");
    $current->modify('+1 day');
    continue;
  }

  foreach ($games as $game) {
    $contestId = (int)$game['game_id'];

    $threadStartTime = mysql_time_from_ncaa(
      $game['start_time'] ?? '',
      $game['start_time_local_str'] ?? '',
      $tz
    );

    $homeShort = $game['home_team_short'];
    $awayShort = $game['away_team_short'];
    $homeRank  = $game['home_rank'] ?? '';
    $awayRank  = $game['away_rank'] ?? '';

    $homeLabel = ncaa_format_ranked_team($homeShort, $homeRank);
    $awayLabel = ncaa_format_ranked_team($awayShort, $awayRank);

    $timeDisplay = $game['start_time_local_str'] ?? '';
    if (!$timeDisplay && !empty($game['start_time'])) {
      $raw = (string)$game['start_time'];
      if (preg_match('/^\d{2}:\d{2}/', $raw)) $timeDisplay = substr($raw, 0, 5);
    }

    $niceDate = $game['game_date'];
    $dt = DateTime::createFromFormat('Y-m-d', $game['game_date'], $tz);
    if ($dt) $niceDate = $dt->format('D M j, Y');

    $puckDropLine = $timeDisplay
      ? "Puck drop: {$niceDate} {$timeDisplay}"
      : "Game date: {$niceDate}";

    $title = sprintf(
      'NCAA: %s at %s%s',
      $awayLabel,
      $homeLabel,
      $timeDisplay ? " ({$timeDisplay})" : ''
    );

    $descriptionHtml  = "<p><strong>{$awayLabel} @ {$homeLabel}</strong></p>";
    $descriptionHtml .= "<p>{$puckDropLine}</p>";
    $descriptionHtml .= "<p>Use this thread to talk about the game before, during, and after.</p>";

    $headerImage = '';

    // UPSERT by (league='NCAAH' AND ncaa_game_id)
    $check = $pdo->prepare("
      SELECT id
      FROM gameday_threads
      WHERE league = 'NCAAH'
        AND ncaa_game_id = :ncaa_game_id
      LIMIT 1
    ");
    $check->execute([':ncaa_game_id' => $contestId]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
      $upd = $pdo->prepare("
        UPDATE gameday_threads
        SET
          title = :title,
          game_date = :game_date,
          start_time = :start_time,
          description_html = :description_html,
          header_image_url = :header_image_url
        WHERE id = :id
        LIMIT 1
      ");
      $upd->execute([
        ':title'            => $title,
        ':game_date'        => $game['game_date'],
        ':start_time'       => $threadStartTime, // TIME or NULL
        ':description_html' => $descriptionHtml,
        ':header_image_url' => $headerImage,
        ':id'               => (int)$existingId,
      ]);

      log_msg("  Updated NCAA thread id={$existingId} ncaa_game_id={$contestId} (start_time=" . ($threadStartTime ? $threadStartTime : 'NULL') . ")");
      continue;
    }

    // INSERT
    $insert = $pdo->prepare("
      INSERT INTO gameday_threads
        (league, ncaa_game_id, title, game_date, start_time,
         header_image_url, description_html, created_by, created_at)
      VALUES
        ('NCAAH', :ncaa_game_id, :title, :game_date, :start_time,
         :header_image_url, :description_html, :created_by, NOW())
    ");

    $insert->execute([
      ':ncaa_game_id'     => $contestId,
      ':title'            => $title,
      ':game_date'        => $game['game_date'],
      ':start_time'       => $threadStartTime, // TIME or NULL
      ':header_image_url' => $headerImage,
      ':description_html' => $descriptionHtml,
      ':created_by'       => $ADMIN_USER_ID,
    ]);

    $threadId = (int)$pdo->lastInsertId();
    log_msg("  Created NCAA thread id={$threadId} ncaa_game_id={$contestId} (start_time=" . ($threadStartTime ? $threadStartTime : 'NULL') . ")");
  }

  $current->modify('+1 day');
}

log_msg('Done upserting NCAA threads.');
