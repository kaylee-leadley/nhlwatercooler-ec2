<?php
// public/scripts/msf_players_backfill.php
//
// Backfill / refresh player master data from MySportsFeeds.
// Uses https://api.mysportsfeeds.com/v2.1/pull/nhl/players.json
//
// Usage (CLI):
//   php msf_players_backfill.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../config/lineups_config.php'; // has getPdo() + MSF creds

$pdo = getPdo();

/**
 * Fetch one page of players.
 * If you don't need paging, we can just call page=1 and be done.
 * This version supports simple paging in case MSF enforces it.
 */
function fetchPlayersPage(int $page = 1): array {
  $url = "https://api.mysportsfeeds.com/v2.1/pull/nhl/players.json?page={$page}";
  // You can add filters if you want:
  // $url .= '&rosterstatus=all';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => MSF_API_KEY . ':' . MSF_API_PASS,
    CURLOPT_TIMEOUT        => 40,
  ]);

  $raw = curl_exec($ch);
  if ($raw === false) {
    throw new RuntimeException('Curl error: ' . curl_error($ch));
  }

  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($status != 200) {
    throw new RuntimeException("HTTP {$status}: {$raw}");
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new RuntimeException('Bad JSON from MSF');
  }

  return $data;
}

$insertStmt = $pdo->prepare("
  INSERT INTO msf_players (
    player_id, first_name, last_name, primary_position, jersey_number,
    current_team_id, current_team_abbr, roster_status,
    height, weight, birth_date, birth_city, birth_country,
    shoots, catches, rookie, official_image_src,
    draft_year, draft_team_id, draft_team_abbr, draft_round, draft_overall,
    last_updated_on, raw_json
  ) VALUES (
    :player_id, :first_name, :last_name, :primary_position, :jersey_number,
    :current_team_id, :current_team_abbr, :roster_status,
    :height, :weight, :birth_date, :birth_city, :birth_country,
    :shoots, :catches, :rookie, :official_image_src,
    :draft_year, :draft_team_id, :draft_team_abbr, :draft_round, :draft_overall,
    :last_updated_on, :raw_json
  )
  ON DUPLICATE KEY UPDATE
    first_name         = VALUES(first_name),
    last_name          = VALUES(last_name),
    primary_position   = VALUES(primary_position),
    jersey_number      = VALUES(jersey_number),
    current_team_id    = VALUES(current_team_id),
    current_team_abbr  = VALUES(current_team_abbr),
    roster_status      = VALUES(roster_status),
    height             = VALUES(height),
    weight             = VALUES(weight),
    birth_date         = VALUES(birth_date),
    birth_city         = VALUES(birth_city),
    birth_country      = VALUES(birth_country),
    shoots             = VALUES(shoots),
    catches            = VALUES(catches),
    rookie             = VALUES(rookie),
    official_image_src = VALUES(official_image_src),
    draft_year         = VALUES(draft_year),
    draft_team_id      = VALUES(draft_team_id),
    draft_team_abbr    = VALUES(draft_team_abbr),
    draft_round        = VALUES(draft_round),
    draft_overall      = VALUES(draft_overall),
    last_updated_on    = VALUES(last_updated_on),
    raw_json           = VALUES(raw_json)
");

$totalInserted = 0;
$totalUpdated  = 0;
$page          = 1;

echo "Starting player backfill...\n";

while (true) {
  echo "Fetching players page {$page}...\n";

  try {
    $data = fetchPlayersPage($page);
  } catch (Throwable $e) {
    fwrite(STDERR, "Error fetching page {$page}: " . $e->getMessage() . "\n");
    // basic cool-off on failure
    sleep(5);
    break;
  }

  $players      = $data['players'] ?? [];
  $lastUpdated  = $data['lastUpdatedOn'] ?? null;

  if (empty($players)) {
    echo "No players on page {$page}, stopping.\n";
    break;
  }

  foreach ($players as $wrapper) {
    $p     = $wrapper['player'] ?? [];
    if (empty($p['id'])) {
      continue;
    }

    $playerId      = (int) $p['id'];
    $firstName     = $p['firstName'] ?? '';
    $lastName      = $p['lastName'] ?? '';
    $position      = $p['primaryPosition'] ?? '';
    $jerseyNumber  = $p['jerseyNumber'] ?? null;

    $currentTeam   = $p['currentTeam'] ?? [];
    $teamId        = $currentTeam['id'] ?? null;
    $teamAbbr      = $currentTeam['abbreviation'] ?? null;

    $rosterStatus  = $p['currentRosterStatus'] ?? null;
    $height        = $p['height'] ?? null;
    $weight        = isset($p['weight']) ? (int) $p['weight'] : null;
    $birthDate     = !empty($p['birthDate']) ? $p['birthDate'] : null;
    $birthCity     = $p['birthCity'] ?? null;
    $birthCountry  = $p['birthCountry'] ?? null;

    $handedness    = $p['handedness'] ?? [];
    $shoots        = $handedness['shoots']  ?? null;
    $catches       = $handedness['catches'] ?? null;

    $rookie        = !empty($p['rookie']) ? 1 : 0;
    $imageSrc      = $p['officialImageSrc'] ?? null;

    $draft         = $p['drafted'] ?? null;
    $draftYear     = $draft['year'] ?? null;
    $draftTeam     = $draft['team'] ?? [];
    $draftTeamId   = $draftTeam['id'] ?? null;
    $draftTeamAbbr = $draftTeam['abbreviation'] ?? null;
    $draftRound    = $draft['round'] ?? null;
    $draftOverall  = $draft['overallPick'] ?? null;

    $jsonBlob      = json_encode($wrapper);

    $insertStmt->execute([
      ':player_id'         => $playerId,
      ':first_name'        => $firstName,
      ':last_name'         => $lastName,
      ':primary_position'  => $position,
      ':jersey_number'     => $jerseyNumber,
      ':current_team_id'   => $teamId,
      ':current_team_abbr' => $teamAbbr,
      ':roster_status'     => $rosterStatus,
      ':height'            => $height,
      ':weight'            => $weight,
      ':birth_date'        => $birthDate,
      ':birth_city'        => $birthCity,
      ':birth_country'     => $birthCountry,
      ':shoots'            => $shoots,
      ':catches'           => $catches,
      ':rookie'            => $rookie,
      ':official_image_src'=> $imageSrc,
      ':draft_year'        => $draftYear,
      ':draft_team_id'     => $draftTeamId,
      ':draft_team_abbr'   => $draftTeamAbbr,
      ':draft_round'       => $draftRound,
      ':draft_overall'     => $draftOverall,
      ':last_updated_on'   => $lastUpdated ? str_replace('Z', '', $lastUpdated) : null,
      ':raw_json'          => $jsonBlob,
    ]);

    if ($insertStmt->rowCount() === 1) {
      $totalInserted++;
    } else {
      $totalUpdated++;
    }
  }

  echo "Page {$page}: inserted {$totalInserted}, updated {$totalUpdated}\n";

  // simple throttle so we don't hammer MSF
  $page++;
  if ($page > 50) {
    echo "Safety stop after 50 pages.\n";
    break;
  }

  echo "Cooling down (1s)...\n";
  sleep(1);
}

echo "Player backfill complete.\n";
echo "  Inserted: {$totalInserted}\n";
echo "  Updated:  {$totalUpdated}\n";
