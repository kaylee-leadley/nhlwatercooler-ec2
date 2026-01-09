<?php
// public/scripts/msf_lineups_backfill.php
//
// Backfill lineups for ALL games in MSF_SEASON, projected or final.
// Skips already-imported games, and throttles requests to avoid HTTP 429.

require __DIR__ . '/../../config/lineups_config.php';

$pdo = getPdo();

function fetchSeasonalGames($season) {
  $url = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}/games.json";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => MSF_API_KEY . ':' . MSF_API_PASS,
    CURLOPT_TIMEOUT        => 40,
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) throw new RuntimeException("Curl error: " . curl_error($ch));
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($status != 200) throw new RuntimeException("HTTP {$status}: {$raw}");
  $data = json_decode($raw, true);
  return $data['games'] ?? [];
}

function deriveGameKey($schedule) {
  if (!empty($schedule['id'])) return $schedule['id'];
  $dt = new DateTime($schedule['startTime']);
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Ymd') . '-' .
         $schedule['awayTeam']['abbreviation'] . '-' .
         $schedule['homeTeam']['abbreviation'];
}

echo "Fetching all games for season " . MSF_SEASON . "...\n";
$games = fetchSeasonalGames(MSF_SEASON);
echo "Total games: " . count($games) . "\n";

$checkStmt = $pdo->prepare('SELECT 1 FROM lineups WHERE season = :season AND game_key = :key LIMIT 1');

$created = 0; $skippedExisting = 0; $errors = 0;
$reqCount = 0;

foreach ($games as $g) {
  if (empty($g['schedule'])) continue;
  try {
    $key = deriveGameKey($g['schedule']);
  } catch (Throwable $e) {
    fwrite(STDERR, "Skip (bad schedule): " . $e->getMessage() . "\n");
    continue;
  }

  $checkStmt->execute([':season' => MSF_SEASON, ':key' => $key]);
  if ($checkStmt->fetchColumn()) {
    $skippedExisting++;
    continue;
  }

  try {
    processGameKey($key);
    $created++;
  } catch (Throwable $e) {
    $errors++;
    fwrite(STDERR, "Error processing {$key}: " . $e->getMessage() . "\n");
    // handle 429s: slow down exponentially
    if (strpos($e->getMessage(), 'HTTP 429') !== false) {
      $sleep = 10 + rand(0, 5);
      echo "Rate limit hit. Sleeping {$sleep}s...\n";
      sleep($sleep);
    }
  }

  // throttle requests even if successful
  $reqCount++;
  if ($reqCount % 5 === 0) {
    echo "Short cooldown (2s)...\n";
    sleep(2);
  } else {
    usleep(400000); // 0.4s between calls
  }
}

echo "Backfill complete.\n";
echo "  New lineups: {$created}\n";
echo "  Skipped (existing): {$skippedExisting}\n";
echo "  Errors: {$errors}\n";
