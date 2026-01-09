<?php
// public/scripts/msf_import_season.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Project root: /home/leadley/website/SJS/gameday-board
$root = dirname(__DIR__, 2);

// Load DB + MSF config
require_once $root . '/config/db.php';
$msfConfig = require $root . '/config/msf.php';

$apiKey = $msfConfig['api_key'];
$tzName = $msfConfig['timezone'] ?? 'America/New_York';
$tz     = new DateTimeZone($tzName);

function msf_get_season_for_date(DateTime $dt): string {
    $year  = (int)$dt->format('Y');
    $month = (int)$dt->format('n');
    $startYear = ($month >= 10) ? $year : $year - 1;
    $endYear   = $startYear + 1;
    return "{$startYear}-{$endYear}";
}

// Optional CLI override: php msf_import_season.php 2025-10-05
$refArg = $argv[1] ?? null;
if ($refArg) {
    $refDate = new DateTime($refArg, $tz);
} else {
    $refDate = new DateTime('now', $tz);
}

$season = msf_get_season_for_date($refDate);
echo "Importing season: {$season}\n";

$url = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}-regular/games.json";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => 'gzip',
    CURLOPT_HTTPHEADER     => [
        'Authorization: Basic ' . base64_encode($apiKey . ':MYSPORTSFEEDS'),
    ],
]);
$resp = curl_exec($ch);

if ($resp === false) {
    die('MSF error: ' . curl_error($ch) . ' (code ' . curl_errno($ch) . ")\n");
}
curl_close($ch);

$data = json_decode($resp, true);
if (!is_array($data) || empty($data['games'])) {
    die("No games returned for season {$season}\n");
}

$inserted = 0;
$updated  = 0;

foreach ($data['games'] as $gameWrap) {
    if (empty($gameWrap['schedule'])) {
        continue;
    }

    $schedule = $gameWrap['schedule'];
    $score    = $gameWrap['score'] ?? [];

    $msfGameId    = (int)$schedule['id'];
    $startTimeIso = $schedule['startTime'] ?? null;
    $gameDate     = null;

    if ($startTimeIso) {
        // Local date (for game_date)
        $dt = new DateTime($startTimeIso);  // Z from API
        $dt->setTimezone($tz);
        $gameDate = $dt->format('Y-m-d');

        // UTC start time for storage
        $startUtc = (new DateTime($startTimeIso))->format('Y-m-d H:i:s');
    } else {
        $gameDate = null;
        $startUtc = null;
    }

    $homeAbbr = $schedule['homeTeam']['abbreviation'] ?? 'TBD';
    $awayAbbr = $schedule['awayTeam']['abbreviation'] ?? 'TBD';
    $venue    = $schedule['venue']['name'] ?? null;

    $scheduleStatus = $schedule['scheduleStatus'] ?? null;
    $playedStatus   = $schedule['playedStatus'] ?? null;

    $homeScore = isset($score['homeScoreTotal']) ? (int)$score['homeScoreTotal'] : null;
    $awayScore = isset($score['awayScoreTotal']) ? (int)$score['awayScoreTotal'] : null;

    $rawJson = json_encode($gameWrap);

    // Upsert into msf_games
    $sql = "
      INSERT INTO msf_games
        (msf_game_id, season, game_date, start_time_utc,
         home_team_abbr, away_team_abbr, venue_name,
         schedule_status, played_status, home_score, away_score, raw_json)
      VALUES
        (:msf_game_id, :season, :game_date, :start_time_utc,
         :home_team_abbr, :away_team_abbr, :venue_name,
         :schedule_status, :played_status, :home_score, :away_score, :raw_json)
      ON DUPLICATE KEY UPDATE
        season          = VALUES(season),
        game_date       = VALUES(game_date),
        start_time_utc  = VALUES(start_time_utc),
        home_team_abbr  = VALUES(home_team_abbr),
        away_team_abbr  = VALUES(away_team_abbr),
        venue_name      = VALUES(venue_name),
        schedule_status = VALUES(schedule_status),
        played_status   = VALUES(played_status),
        home_score      = VALUES(home_score),
        away_score      = VALUES(away_score),
        raw_json        = VALUES(raw_json)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':msf_game_id'    => $msfGameId,
        ':season'         => $season,
        ':game_date'      => $gameDate,
        ':start_time_utc' => $startUtc,
        ':home_team_abbr' => $homeAbbr,
        ':away_team_abbr' => $awayAbbr,
        ':venue_name'     => $venue,
        ':schedule_status'=> $scheduleStatus,
        ':played_status'  => $playedStatus,
        ':home_score'     => $homeScore,
        ':away_score'     => $awayScore,
        ':raw_json'       => $rawJson,
    ]);

    if ($stmt->rowCount() === 1) {
        $inserted++;
    } else {
        $updated++;
    }
}

echo "Season import complete. Inserted: {$inserted}, Updated: {$updated}\n";
