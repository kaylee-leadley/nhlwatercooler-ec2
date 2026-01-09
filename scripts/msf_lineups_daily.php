<?php
// public/scripts/cron_lineups_daily.php
//
// Fetch lineups (expected/final) for *today's* games that have threads,
// using gameday_threads.external_game_id -> msf_games.
//
// - Works for UNPLAYED / LIVE / COMPLETED (projected + final).
// - If lineups already exist, we *do not delete* them; we simply call
//   processGameKey(), which will upsert any new data from MSF.
// - If MSF returns HTTP 204 (no content), processGameKey() should
//   return false and leave existing DB rows untouched.
// - Includes light throttling + simple backoff on HTTP 429.

require __DIR__ . '/../../config/lineups_config.php';

$pdo = getPdo();

// Use local timezone to decide "today"
$tz = new DateTimeZone('America/New_York');
$today = new DateTime('now', $tz);
$todayStr = $today->format('Y-m-d');

echo "Running daily lineup fetch for {$todayStr} (" . MSF_SEASON . ")\n";

// Find threads for today's games and join msf_games to get team abbrevs/date.
$sql = "
  SELECT DISTINCT
    gt.id               AS thread_id,
    gt.external_game_id AS msf_game_id,
    mg.season           AS msf_season,
    mg.game_date,
    mg.away_team_abbr,
    mg.home_team_abbr
  FROM gameday_threads gt
  JOIN msf_games mg
    ON mg.msf_game_id = gt.external_game_id
  WHERE gt.game_date = :today
    AND gt.external_game_id IS NOT NULL
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':today' => $todayStr]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  echo "No threads with external_game_id for {$todayStr}.\n";
  exit(0);
}

echo "Found " . count($rows) . " thread game(s) for today.\n";

// Count existing rows just for logging
$cntStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM lineups
  WHERE season   = :season
    AND game_key = :game_key
");

$processed       = 0; // games where processGameKey() returned true (updated from MSF)
$withExisting    = 0; // games that already had at least one lineup row
$errors          = 0;
$reqCount        = 0;

foreach ($rows as $row) {
  $season = $row['msf_season'] ?: MSF_SEASON;

  // Build MSF gameKey in the same format used by backfill:
  // YYYYMMDD-AWAY-HOME  (e.g. 20251207-SJS-CAR)
  $dateCompact = str_replace('-', '', $row['game_date']);
  $gameKey = $dateCompact . '-' . $row['away_team_abbr'] . '-' . $row['home_team_abbr'];

  echo "Thread {$row['thread_id']}: {$gameKey} ({$season})\n";

  // Check how many lineup rows already exist for this gameKey+season
  $cntStmt->execute([
    ':season'   => $season,
    ':game_key' => $gameKey,
  ]);
  $existingCount = (int)$cntStmt->fetchColumn();

  if ($existingCount > 0) {
    $withExisting++;
    echo "  -> existing lineups found ({$existingCount} rows); refreshing from MSF if available...\n";
  } else {
    echo "  -> no existing lineups; fetching from MSF...\n";
  }

  try {
    // processGameKey() should:
    // - call fetchLineupFromMSF()
    // - if HTTP 204: return false, leave DB untouched
    // - if valid payload: upsert rows, return true
    $didUpdate = processGameKey($gameKey, $season);
    if ($didUpdate) {
      $processed++;
    }
  } catch (Throwable $e) {
    $errors++;
    fwrite(STDERR, "Error on {$gameKey}: " . $e->getMessage() . "\n");

    // If we hit rate limits, back off a bit
    if (strpos($e->getMessage(), 'HTTP 429') !== false) {
      $sleep = 10 + rand(0, 5);
      echo "  -> Rate limit hit. Sleeping {$sleep}s...\n";
      sleep($sleep);
    }
  }

  // Light throttling even on success/failure
  $reqCount++;
  if ($reqCount % 5 === 0) {
    echo "Short cooldown (2s)...\n";
    sleep(2);
  } else {
    usleep(400000); // 0.4s
  }
}

echo "Daily lineup job complete.\n";
echo "  Games processed from MSF (fetch & upsert): {$processed}\n";
echo "  Games where lineups already existed (counted): {$withExisting}\n";
echo "  Errors: {$errors}\n";
