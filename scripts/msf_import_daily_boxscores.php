<?php
// public/scripts/cron_import_daily_boxscores.php
//
// Daily league-wide boxscore import.
//
// Usage:
//   php cron_import_daily_boxscores.php              # default: "yesterday" in MSF timezone
//   php cron_import_daily_boxscores.php 2025-12-06   # explicit date (YYYY-MM-DD)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * TEMP FIX:
 * MSF player_gamelogs appears to have evenStrengthTimeOnIceSeconds and
 * shorthandedTimeOnIceSeconds swapped. We swap them back on import.
 *
 * When MSF fixes it, set this to false.
 */
define('MSF_SWAP_EV_SH_TOI', true);

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
 * Given a DateTime, return MSF season string, e.g. "2025-2026".
 *
 * @param DateTime $dt
 * @return string
 */
function msf_get_season_for_date($dt)
{
    $year      = (int) $dt->format('Y');
    $month     = (int) $dt->format('n');
    $startYear = ($month >= 10) ? $year : $year - 1;
    $endYear   = $startYear + 1;
    return $startYear . '-' . $endYear;
}

/**
 * Generic fetch helper with simple retry on 429.
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

/**
 * Index team gamelogs as [game_id][TEAM_ABBR] => log.
 *
 * @param array $teamData
 * @return array
 */
function index_team_gamelogs_by_game($teamData)
{
    $index = array();
    if (empty($teamData['gamelogs'])) {
        return $index;
    }

    foreach ($teamData['gamelogs'] as $log) {
        $gameId = isset($log['game']['id']) ? (int) $log['game']['id'] : 0;
        $abbr   = strtoupper(isset($log['team']['abbreviation']) ? $log['team']['abbreviation'] : '');
        if (!$gameId || !$abbr) {
            continue;
        }
        if (!isset($index[$gameId])) {
            $index[$gameId] = array();
        }
        $index[$gameId][$abbr] = $log;
    }

    return $index;
}

/**
 * Index player gamelogs as [game_id] => list of logs.
 *
 * @param array $playerData
 * @return array
 */
function index_player_gamelogs_by_game($playerData)
{
    $index = array();
    if (empty($playerData['gamelogs'])) {
        return $index;
    }

    foreach ($playerData['gamelogs'] as $log) {
        $gameId = isset($log['game']['id']) ? (int) $log['game']['id'] : 0;
        if (!$gameId) {
            continue;
        }
        if (!isset($index[$gameId])) {
            $index[$gameId] = array();
        }
        $index[$gameId][] = $log;
    }

    return $index;
}

function msf_int_nonneg($v) {
    $n = is_numeric($v) ? (int)$v : 0;
    return ($n < 0) ? 0 : $n;
}

/**
 * Extract TOI seconds from a player gamelog entry.
 * Returns: array(total_toi, ev_toi, pp_toi, sh_toi)
 */
function msf_extract_toi($log) {
    $stats  = isset($log['stats']) ? $log['stats'] : array();
    $shifts = (isset($stats['shifts']) && is_array($stats['shifts'])) ? $stats['shifts'] : array();

    $total = msf_int_nonneg(isset($shifts['timeOnIceSeconds']) ? $shifts['timeOnIceSeconds'] : 0);
    $pp    = msf_int_nonneg(isset($shifts['powerplayTimeOnIceSeconds']) ? $shifts['powerplayTimeOnIceSeconds'] : 0);

    $evRaw = msf_int_nonneg(isset($shifts['evenStrengthTimeOnIceSeconds']) ? $shifts['evenStrengthTimeOnIceSeconds'] : 0);
    $shRaw = msf_int_nonneg(isset($shifts['shorthandedTimeOnIceSeconds']) ? $shifts['shorthandedTimeOnIceSeconds'] : 0);

    if (defined('MSF_SWAP_EV_SH_TOI') && MSF_SWAP_EV_SH_TOI) {
        // TEMP: MSF appears swapped; store corrected
        $ev = $shRaw;
        $sh = $evRaw;
    } else {
        $ev = $evRaw;
        $sh = $shRaw;
    }

    // If total missing but parts exist, rebuild
    if ($total <= 0) {
        $sum = $ev + $pp + $sh;
        if ($sum > 0) $total = $sum;
    }

    return array($total, $ev, $pp, $sh);
}

/* ---------------------------------------------------------
 * Decide which date to process
 * ------------------------------------------------------- */

$argDate = isset($argv[1]) ? $argv[1] : null;

if ($argDate) {
    $targetDate = DateTime::createFromFormat('Y-m-d', $argDate, $tz);
    if (!$targetDate) {
        fwrite(STDERR, "Invalid date format. Use YYYY-MM-DD.\n");
        exit(1);
    }
} else {
    // Default: "yesterday" in MSF timezone
    $targetDate = new DateTime('yesterday', $tz);
}

$season  = msf_get_season_for_date($targetDate);
$dateSql = $targetDate->format('Y-m-d');  // for DB
$dateYmd = $targetDate->format('Ymd');    // for MSF URLs

echo "=== Daily boxscore import for {$dateSql} (season {$season}) ===\n";

/* ---------------------------------------------------------
 * Fetch league-wide gamelogs from MSF
 * ------------------------------------------------------- */

$teamUrl   = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}-regular/date/{$dateYmd}/team_gamelogs.json";
$playerUrl = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}-regular/date/{$dateYmd}/player_gamelogs.json";

$teamData   = msf_fetch_json($teamUrl, 'team_gamelogs', $apiKey);
$playerData = msf_fetch_json($playerUrl, 'player_gamelogs', $apiKey);

if (empty($teamData['gamelogs']) && empty($playerData['gamelogs'])) {
    echo "No gamelogs returned by MSF for {$dateSql}.\n";
    exit(0);
}

$teamIndex   = $teamData   ? index_team_gamelogs_by_game($teamData)     : array();
$playerIndex = $playerData ? index_player_gamelogs_by_game($playerData) : array();

echo "team_gamelogs:   " . (!empty($teamData['gamelogs'])   ? count($teamData['gamelogs'])   : 0) . " rows.\n";
echo "player_gamelogs: " . (!empty($playerData['gamelogs']) ? count($playerData['gamelogs']) : 0) . " rows.\n";

/* ---------------------------------------------------------
 * Load all games from msf_games for that date & season
 * ------------------------------------------------------- */

$sqlGames = "
  SELECT *
  FROM msf_games
  WHERE season = :season
    AND game_date = :gdate
  ORDER BY msf_game_id ASC
";
$stGames = $pdo->prepare($sqlGames);
$stGames->execute(array(
    ':season' => $season,
    ':gdate'  => $dateSql,
));
$games = $stGames->fetchAll();

if (!$games) {
    echo "No msf_games rows for {$season} on {$dateSql}. Nothing to update.\n";
    exit(0);
}

/* ---------------------------------------------------------
 * Prepare statements for upserts + game status update
 * ------------------------------------------------------- */

$sqlTeamUp = "
  INSERT INTO msf_team_gamelogs
    (msf_game_id, team_abbr,
     goals_for, goals_against, shots, shots_against,
     faceoff_wins, faceoff_losses, faceoff_percent,
     powerplays, powerplay_goals, powerplay_percent,
     hits, blocked_shots, penalties, penalty_minutes,
     ot_wins, ot_losses, so_wins, so_losses,
     raw_json)
  VALUES
    (:gid, :team,
     :gf, :ga, :sh, :sha,
     :fow, :fol, :fopct,
     :pp, :ppg, :pppct,
     :hits, :blocks, :pens, :pim,
     :otw, :otl, :sow, :sol,
     :raw_json)
  ON DUPLICATE KEY UPDATE
    goals_for         = VALUES(goals_for),
    goals_against     = VALUES(goals_against),
    shots             = VALUES(shots),
    shots_against     = VALUES(shots_against),
    faceoff_wins      = VALUES(faceoff_wins),
    faceoff_losses    = VALUES(faceoff_losses),
    faceoff_percent   = VALUES(faceoff_percent),
    powerplays        = VALUES(powerplays),
    powerplay_goals   = VALUES(powerplay_goals),
    powerplay_percent = VALUES(powerplay_percent),
    hits              = VALUES(hits),
    blocked_shots     = VALUES(blocked_shots),
    penalties         = VALUES(penalties),
    penalty_minutes   = VALUES(penalty_minutes),
    ot_wins           = VALUES(ot_wins),
    ot_losses         = VALUES(ot_losses),
    so_wins           = VALUES(so_wins),
    so_losses         = VALUES(so_losses),
    raw_json          = VALUES(raw_json),
    updated_at        = CURRENT_TIMESTAMP
";
$stTeamUp = $pdo->prepare($sqlTeamUp);

$sqlPlayerUp = "
  INSERT INTO msf_player_gamelogs
    (msf_game_id, team_abbr, player_id,
     first_name, last_name, position, jersey_number,
     goals, assists, points, shots, pim,
     total_toi, ev_toi, pp_toi, sh_toi,
     raw_json)
  VALUES
    (:gid, :team, :pid,
     :first, :last, :pos, :jersey,
     :g, :a, :p, :sh, :pim,
     :total_toi, :ev_toi, :pp_toi, :sh_toi,
     :raw_json)
  ON DUPLICATE KEY UPDATE
    team_abbr      = VALUES(team_abbr),
    first_name     = VALUES(first_name),
    last_name      = VALUES(last_name),
    position       = VALUES(position),
    jersey_number  = VALUES(jersey_number),
    goals          = VALUES(goals),
    assists        = VALUES(assists),
    points         = VALUES(points),
    shots          = VALUES(shots),
    pim            = VALUES(pim),
    total_toi      = VALUES(total_toi),
    ev_toi         = VALUES(ev_toi),
    pp_toi         = VALUES(pp_toi),
    sh_toi         = VALUES(sh_toi),
    raw_json       = VALUES(raw_json),
    updated_at     = CURRENT_TIMESTAMP
";
$stPlayerUp = $pdo->prepare($sqlPlayerUp);

// Update msf_games with final score + mark COMPLETED when we have both team logs
$sqlUpdateGame = "
  UPDATE msf_games
  SET played_status = 'COMPLETED',
      home_score    = :home_score,
      away_score    = :away_score,
      updated_at    = CURRENT_TIMESTAMP
  WHERE msf_game_id = :gid
";
$stUpdateGame = $pdo->prepare($sqlUpdateGame);

/* ---------------------------------------------------------
 * Process each game
 * ------------------------------------------------------- */

foreach ($games as $game) {
    $msfGameId = (int) $game['msf_game_id'];
    $homeAbbr  = strtoupper($game['home_team_abbr']);
    $awayAbbr  = strtoupper($game['away_team_abbr']);

    echo "Game {$msfGameId} ({$awayAbbr} @ {$homeAbbr}) {$dateSql}...\n";

    // ---------- TEAM GAMELOGS ----------
    $homeLog = isset($teamIndex[$msfGameId][$homeAbbr]) ? $teamIndex[$msfGameId][$homeAbbr] : null;
    $awayLog = isset($teamIndex[$msfGameId][$awayAbbr]) ? $teamIndex[$msfGameId][$awayAbbr] : null;

    $teamLogs = array(
        $homeAbbr => $homeLog,
        $awayAbbr => $awayLog,
    );

    foreach ($teamLogs as $abbr => $log) {
        if (!$log) {
            echo "  [team] no team_gamelog for {$abbr}\n";
            continue;
        }

        $stats = isset($log['stats']) ? $log['stats'] : array();
        $face  = isset($stats['faceoffs'])      ? $stats['faceoffs']      : array();
        $pp    = isset($stats['powerplay'])     ? $stats['powerplay']     : array();
        $misc  = isset($stats['miscellaneous']) ? $stats['miscellaneous'] : array();
        $stand = isset($stats['standings'])     ? $stats['standings']     : array();

        $goalsFor     = isset($misc['goalsFor'])       ? $misc['goalsFor']       : null;
        $goalsAgainst = isset($misc['goalsAgainst'])   ? $misc['goalsAgainst']   : null;
        $shots        = isset($misc['shots'])          ? $misc['shots']          : null;
        $shotsAgainst = isset($misc['shAgainst'])      ? $misc['shAgainst']      : null;
        $foWins       = isset($face['faceoffWins'])    ? $face['faceoffWins']    : null;
        $foLosses     = isset($face['faceoffLosses'])  ? $face['faceoffLosses']  : null;
        $foPct        = isset($face['faceoffPercent']) ? $face['faceoffPercent'] : null;
        $pps          = isset($pp['powerplays'])       ? $pp['powerplays']       : null;
        $ppg          = isset($pp['powerplayGoals'])   ? $pp['powerplayGoals']   : null;
        $ppPct        = isset($pp['powerplayPercent']) ? $pp['powerplayPercent'] : null;
        $hits         = isset($misc['hits'])           ? $misc['hits']           : null;
        $blocks       = isset($misc['blockedShots'])   ? $misc['blockedShots']   : null;
        $pens         = isset($misc['penalties'])      ? $misc['penalties']      : null;
        $pim          = isset($misc['penaltyMinutes']) ? $misc['penaltyMinutes'] : null;

        $otWins   = !empty($stand['overtimeWins'])     ? 1 : 0;
        $otLosses = !empty($stand['overtimeLosses'])   ? 1 : 0;
        $soWins   = !empty($stand['shootoutWins'])     ? 1 : 0;
        $soLosses = !empty($stand['shootoutLosses'])   ? 1 : 0;

        if (($otWins + $otLosses + $soWins + $soLosses) > 1) {
            error_log("[daily boxscores] multiple OT/SO flags for game {$msfGameId} team {$abbr}: " . json_encode($stand));
        }

        $rawJson = json_encode($log);

        $stTeamUp->execute(array(
            ':gid'      => $msfGameId,
            ':team'     => $abbr,
            ':gf'       => $goalsFor,
            ':ga'       => $goalsAgainst,
            ':sh'       => $shots,
            ':sha'      => $shotsAgainst,
            ':fow'      => $foWins,
            ':fol'      => $foLosses,
            ':fopct'    => $foPct,
            ':pp'       => $pps,
            ':ppg'      => $ppg,
            ':pppct'    => $ppPct,
            ':hits'     => $hits,
            ':blocks'   => $blocks,
            ':pens'     => $pens,
            ':pim'      => $pim,
            ':otw'      => $otWins,
            ':otl'      => $otLosses,
            ':sow'      => $soWins,
            ':sol'      => $soLosses,
            ':raw_json' => $rawJson,
        ));
    }

    // If we have both team logs, update msf_games to COMPLETED + scores
    if ($homeLog && $awayLog) {
        $homeGoals = isset($homeLog['stats']['miscellaneous']['goalsFor'])
            ? $homeLog['stats']['miscellaneous']['goalsFor']
            : null;
        $awayGoals = isset($awayLog['stats']['miscellaneous']['goalsFor'])
            ? $awayLog['stats']['miscellaneous']['goalsFor']
            : null;

        if ($homeGoals !== null && $awayGoals !== null) {
            $stUpdateGame->execute(array(
                ':home_score' => $homeGoals,
                ':away_score' => $awayGoals,
                ':gid'        => $msfGameId,
            ));
        }
    }

    // ---------- PLAYER GAMELOGS ----------
    if (empty($playerIndex[$msfGameId])) {
        echo "  [player] no player logs for this game\n";
    } else {
        foreach ($playerIndex[$msfGameId] as $log) {
            $player = isset($log['player']) ? $log['player'] : array();
            $team   = isset($log['team'])   ? $log['team']   : array();
            if (empty($player['id'])) {
                continue;
            }

            $stats    = isset($log['stats']) ? $log['stats'] : array();
            $scoring  = isset($stats['scoring'])   ? $stats['scoring']   : array();
            $skating  = isset($stats['skating'])   ? $stats['skating']   : array();
            $pensStat = isset($stats['penalties']) ? $stats['penalties'] : array();

            $goals   = (int) (isset($scoring['goals'])   ? $scoring['goals']   : 0);
            $assists = (int) (isset($scoring['assists']) ? $scoring['assists'] : 0);
            $points  = (int) (isset($scoring['points'])  ? $scoring['points']  : ($goals + $assists));
            $shots   = (int) (isset($skating['shots'])   ? $skating['shots']   : 0);
            $pim     = (int) (isset($pensStat['penaltyMinutes']) ? $pensStat['penaltyMinutes'] : 0);

            $playerId = (int) $player['id'];

            list($totalToi, $evToi, $ppToi, $shToi) = msf_extract_toi($log);

            $stPlayerUp->execute(array(
                ':gid'       => $msfGameId,
                ':team'      => strtoupper(isset($team['abbreviation']) ? $team['abbreviation'] : ''),
                ':pid'       => $playerId,
                ':first'     => isset($player['firstName']) ? $player['firstName'] : '',
                ':last'      => isset($player['lastName'])  ? $player['lastName']  : '',
                ':pos'       => isset($player['position'])  ? $player['position']  : null,
                ':jersey'    => isset($player['jerseyNumber']) ? $player['jerseyNumber'] : null,
                ':g'         => $goals,
                ':a'         => $assists,
                ':p'         => $points,
                ':sh'        => $shots,
                ':pim'       => $pim,
                ':total_toi' => $totalToi,
                ':ev_toi'    => $evToi,
                ':pp_toi'    => $ppToi,
                ':sh_toi'    => $shToi,
                ':raw_json'  => json_encode($log),
            ));
        }
    }

    echo "  Boxscore stored/updated for game {$msfGameId}.\n";
}

echo "Done.\n";
