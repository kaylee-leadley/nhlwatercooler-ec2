<?php
// public/scripts/cron_injuries_daily.php
//
// Daily league-wide injuries import from MySportsFeeds.
// Creates a snapshot for one "injury_date" per run (default: today).
//
// Usage:
//   php cron_injuries_daily.php
//   php cron_injuries_daily.php 2025-12-08   # optional override for snapshot date

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root      = dirname(__DIR__, 2); // /gameday-board
require_once $root . '/config/db.php';
$msfConfig = require $root . '/config/msf.php';

$apiKey = isset($msfConfig['api_key']) ? $msfConfig['api_key'] : '';
$tzName = isset($msfConfig['timezone']) ? $msfConfig['timezone'] : 'America/New_York';
$tz     = new DateTimeZone($tzName);

if (!$apiKey) {
    fwrite(STDERR, "No MSF api_key configured in config/msf.php\n");
    exit(1);
}

/**
 * Generic fetch helper with simple retry on 429.
 * Copied from cron_import_daily_boxscores.php for consistency.
 *
 * @param string $url
 * @param string $tag
 * @param string $apiKey
 * @param int    $maxRetries
 * @return array|null
 */
function msf_fetch_json($url, $tag, $apiKey, $maxRetries = 5)
{
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $attempt++;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Basic ' . base64_encode($apiKey . ':MYSPORTSFEEDS'),
            ),
        ));

        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log("[$tag] cURL error: " . curl_error($ch));
            curl_close($ch);
            return null;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 429) {
            $sleep = 10 * $attempt;
            error_log("[$tag] HTTP 429 (rate limit). Sleeping {$sleep}s then retry...");
            sleep($sleep);
            continue;
        }

        if ($code < 200 || $code >= 300) {
            error_log("[$tag] HTTP {$code} for {$url}");
            return null;
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            error_log("[$tag] JSON decode error");
            return null;
        }

        return $data;
    }

    error_log("[$tag] Max retries reached for {$url}");
    return null;
}

/* ---------------------------------------------------------
 * Decide snapshot date (injury_date) for this run
 * ------------------------------------------------------- */

$argDate = isset($argv[1]) ? $argv[1] : null;

if ($argDate) {
    $snapshot = DateTime::createFromFormat('Y-m-d', $argDate, $tz);
    if (!$snapshot) {
        fwrite(STDERR, "Invalid date format. Use YYYY-MM-DD.\n");
        exit(1);
    }
} else {
    $snapshot = new DateTime('now', $tz);
}

$injuryDate = $snapshot->format('Y-m-d');

echo "=== Daily injuries import; snapshot date = {$injuryDate} ===\n";

/* ---------------------------------------------------------
 * Fetch league-wide injuries from MSF
 * ------------------------------------------------------- */

// Example you gave:
// https://c472b8ae-171e-4988-88cd-cf69c7:MYSPORTSFEEDS@api.mysportsfeeds.com/v2.1/pull/nhl/injuries.json
// We replicate that via api_key + Basic auth:
$injUrl = "https://api.mysportsfeeds.com/v2.1/pull/nhl/injuries.json";

$data = msf_fetch_json($injUrl, 'injuries', $apiKey);
if (!$data) {
    echo "No data returned from injuries endpoint.\n";
    exit(1);
}

$players = isset($data['players']) && is_array($data['players'])
    ? $data['players']
    : array();

if (!$players) {
    echo "No players array in injuries payload.\n";
    exit(0);
}

$feedUpdatedOn = isset($data['lastUpdatedOn']) ? $data['lastUpdatedOn'] : '(unknown)';
echo "Feed lastUpdatedOn = {$feedUpdatedOn}\n";
echo "Players in injuries feed: " . count($players) . "\n";

/* ---------------------------------------------------------
 * Prepare upsert into injuries table
 * ------------------------------------------------------- */

$sql = "
  INSERT INTO injuries (
    player_id,
    injury_date,
    first_name,
    last_name,
    team_abbr,
    position,
    jersey_number,
    injury_description,
    playing_probability,
    raw_json
  )
  VALUES (
    :player_id,
    :injury_date,
    :first_name,
    :last_name,
    :team_abbr,
    :position,
    :jersey_number,
    :injury_description,
    :playing_probability,
    :raw_json
  )
  ON DUPLICATE KEY UPDATE
    first_name          = VALUES(first_name),
    last_name           = VALUES(last_name),
    team_abbr           = VALUES(team_abbr),
    position            = VALUES(position),
    jersey_number       = VALUES(jersey_number),
    injury_description  = VALUES(injury_description),
    playing_probability = VALUES(playing_probability),
    raw_json            = VALUES(raw_json),
    updated_at          = CURRENT_TIMESTAMP
";

$st = $pdo->prepare($sql);

$total    = 0;
$upserted = 0;

foreach ($players as $p) {
    $total++;

    // Skip players that have no currentInjury block or no description
    if (empty($p['currentInjury']) || empty($p['currentInjury']['description'])) {
        continue;
    }

    $inj = $p['currentInjury'];

    $playerId  = isset($p['id']) ? (int)$p['id'] : null;
    if (!$playerId) {
        continue;
    }

    $firstName = isset($p['firstName']) ? $p['firstName'] : null;
    $lastName  = isset($p['lastName']) ? $p['lastName'] : null;
    $teamAbbr  = isset($p['currentTeam']['abbreviation']) ? strtoupper($p['currentTeam']['abbreviation']) : null;
    $position  = isset($p['primaryPosition']) ? $p['primaryPosition'] : null;
    $jersey    = isset($p['jerseyNumber']) ? $p['jerseyNumber'] : null;

    $desc      = isset($inj['description']) ? $inj['description'] : null;
    $prob      = isset($inj['playingProbability']) ? $inj['playingProbability'] : null;

    $st->execute(array(
        ':player_id'          => $playerId,
        ':injury_date'        => $injuryDate,
        ':first_name'         => $firstName,
        ':last_name'          => $lastName,
        ':team_abbr'          => $teamAbbr,
        ':position'           => $position,
        ':jersey_number'      => $jersey,
        ':injury_description' => $desc,
        ':playing_probability'=> $prob,
        ':raw_json'           => json_encode($p, JSON_UNESCAPED_SLASHES),
    ));

    $upserted++;
}

echo "Injuries import complete.\n";
echo "  Total players in feed: {$total}\n";
echo "  Players with injuries upserted for {$injuryDate}: {$upserted}\n";
