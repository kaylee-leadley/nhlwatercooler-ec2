<?php
// public/scripts/cron_create_gameday_threads.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "CLI only.\n";
  exit;
}

// Project root: /home/leadley/website/SJS/gameday-board
$root = dirname(__DIR__, 2);

require_once $root . '/config/db.php';
$msfConfig = require $root . '/config/msf.php';

// ✅ NEW: preview builder
require_once $root . '/public/helpers/gameday_preview.php';

$tzName = $msfConfig['timezone'] ?? 'America/New_York';
$tz     = new DateTimeZone($tzName);

// Admin user id to own the threads (used on INSERT only)
$ADMIN_USER_ID = 1;

// Placeholder header image (relative to /public)
$PLACEHOLDER_HEADER = 'assets/img/gameday-placeholder.png';

/**
 * Convert msf_games.start_time_utc (UTC datetime string) -> local MySQL TIME "HH:MM:SS"
 * Returns string "HH:MM:SS" or null.
 */
function mysql_time_from_msf_start_utc($startUtc, $localTz) {
  $startUtc = trim((string)$startUtc);
  if ($startUtc === '') return null;

  try {
    $dtUtc = new DateTime($startUtc, new DateTimeZone('UTC'));
    $dtUtc->setTimezone($localTz);
    return $dtUtc->format('H:i:s');
  } catch (Exception $e) {
    return null;
  }
}

/**
 * CLI date range handling:
 *
 *   php cron_create_gameday_threads.php
 *     -> today only
 *
 *   php cron_create_gameday_threads.php 2025-10-08
 *     -> that date only
 *
 *   php cron_create_gameday_threads.php 2025-10-08 2025-10-31
 *     -> range inclusive
 */
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

if ($end < $start) {
  $tmp   = $start;
  $start = $end;
  $end   = $tmp;
}

$cur = clone $start;

while ($cur <= $end) {
  $sqlDate = $cur->format('Y-m-d');
  echo "Processing date {$sqlDate}...\n";

  // ALL games on this date (no team filter)
  $stmt = $pdo->prepare("
    SELECT *
    FROM msf_games
    WHERE game_date = :game_date
    ORDER BY start_time_utc ASC, msf_game_id ASC
  ");
  $stmt->execute([':game_date' => $sqlDate]);
  $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$games) {
    echo "  No games found for {$sqlDate}\n";
    $cur->modify('+1 day');
    continue;
  }

  foreach ($games as $game) {
    $msfGameId = (int)$game['msf_game_id'];
    $homeAbbr  = $game['home_team_abbr'];
    $awayAbbr  = $game['away_team_abbr'];
    $venue     = $game['venue_name'];

    $title = "{$awayAbbr} @ {$homeAbbr} Gameday Thread";

    // human-friendly local start time (for description)
    $startLocalStr = 'TBD';
    if (!empty($game['start_time_utc'])) {
      try {
        $dtUtc = new DateTime($game['start_time_utc'], new DateTimeZone('UTC'));
        $dtUtc->setTimezone($tz);
        $startLocalStr = $dtUtc->format('D M j, Y g:ia T');
      } catch (Exception $e) {
        // leave as TBD
      }
    }

    // TIME (HH:MM:SS) in local TZ
    $threadStartTime = mysql_time_from_msf_start_utc($game['start_time_utc'] ?? '', $tz);

    $descriptionHtml = sprintf(
      '<p><strong>%s @ %s</strong></p>' .
      '<p>Venue: %s</p>' .
      '<p>Puck drop: %s</p>' .
      '<p>Use this thread to talk about the game before, during, and after.</p>',
      htmlspecialchars($awayAbbr, ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($homeAbbr, ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($venue, ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($startLocalStr, ENT_QUOTES, 'UTF-8')
    );

    // ✅ NEW: append richer preview HTML
    $previewHtml = sjms_build_gameday_preview_html($pdo, $awayAbbr, $homeAbbr, $sqlDate);
    if ($previewHtml) {
      $descriptionHtml .= "\n" . $previewHtml;
    }

    // UPSERT by external_game_id
    $check = $pdo->prepare("
      SELECT id
      FROM gameday_threads
      WHERE external_game_id = :gid
      LIMIT 1
    ");
    $check->execute([':gid' => $msfGameId]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
      $upd = $pdo->prepare("
        UPDATE gameday_threads
        SET
          league = 'NHL',
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
        ':game_date'        => $sqlDate,
        ':start_time'       => $threadStartTime,      // TIME or NULL
        ':description_html' => $descriptionHtml,
        ':header_image_url' => $PLACEHOLDER_HEADER,
        ':id'               => (int)$existingId,
      ]);

      echo "  Updated thread #{$existingId} for {$title} (start_time=" . ($threadStartTime ? $threadStartTime : 'NULL') . ")\n";
      continue;
    }

    // INSERT
    $ins = $pdo->prepare("
      INSERT INTO gameday_threads
        (league, external_game_id, title, game_date, start_time, description_html, header_image_url, created_by, created_at)
      VALUES
        ('NHL', :gid, :title, :game_date, :start_time, :description_html, :header_image_url, :created_by, NOW())
    ");

    $ins->execute([
      ':gid'              => $msfGameId,
      ':title'            => $title,
      ':game_date'        => $sqlDate,
      ':start_time'       => $threadStartTime, // TIME or NULL
      ':description_html' => $descriptionHtml,
      ':header_image_url' => $PLACEHOLDER_HEADER,
      ':created_by'       => $ADMIN_USER_ID,
    ]);

    $newId = (int)$pdo->lastInsertId();
    echo "  Created thread #{$newId} for {$title} (start_time=" . ($threadStartTime ? $threadStartTime : 'NULL') . ")\n";
  }

  $cur->modify('+1 day');
}

echo "Done.\n";
