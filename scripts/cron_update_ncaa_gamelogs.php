<?php
// public/scripts/cron_update_ncaa_gamelogs.php
//
// Usage:
//   php cron_update_ncaa_gamelogs.php            # today (NY time)
//   php cron_update_ncaa_gamelogs.php 2025-10-05
//   php cron_update_ncaa_gamelogs.php 2025-10-01 2025-12-10
//
// Flow:
//   - For each date in range, read game_id from ncaa_games
//   - For each game_id, call /game/{id}/boxscore on your ncaa-api
//   - Upsert into ncaa_team_gamelogs and ncaa_player_gamelogs

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__, 2); // /home/leadley/website/SJS/gameday-board
require_once $root . '/config/db.php';

// Base URL for your local ncaa-api
$NCAA_API_BASE = 'http://127.0.0.1:3000';

// ---------------- CLI date parsing ----------------

$tzNy = new DateTimeZone('America/New_York');

if ($argc === 1) {
  // No dates passed: default to "today" in NY time
  $start = new DateTimeImmutable('now', $tzNy);
  $start = $start->setTime(0, 0, 0);
  $end   = $start;
} elseif ($argc === 2) {
  try {
    $start = new DateTimeImmutable($argv[1], $tzNy);
    $end   = $start;
  } catch (Exception $e) {
    fwrite(STDERR, "Invalid date: {$argv[1]}\n");
    exit(1);
  }
} elseif ($argc === 3) {
  try {
    $start = new DateTimeImmutable($argv[1], $tzNy);
    $end   = new DateTimeImmutable($argv[2], $tzNy);
  } catch (Exception $e) {
    fwrite(STDERR, "Invalid date(s): {$argv[1]} {$argv[2]}\n");
    exit(1);
  }
} else {
  fwrite(STDERR, "Usage:\n");
  fwrite(STDERR, "  php {$argv[0]}            # today\n");
  fwrite(STDERR, "  php {$argv[0]} YYYY-MM-DD\n");
  fwrite(STDERR, "  php {$argv[0]} YYYY-MM-DD YYYY-MM-DD\n");
  exit(1);
}

if ($end < $start) {
  fwrite(STDERR, "End date must be >= start date\n");
  exit(1);
}

function date_range($start, $end) {
  $dates = [];
  for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
    $dates[] = $d;
  }
  return $dates;
}

// ---------------- HTTP helpers ----------------

function http_get_json($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FAILONERROR    => false,
  ]);

  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    fwrite(STDERR, "HTTP error for {$url}: {$err}\n");
    return null;
  }

  if ($code < 200 || $code >= 300) {
    fwrite(STDERR, "HTTP {$code} for {$url}: {$raw}\n");
    return null;
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    fwrite(STDERR, "Non-JSON response for {$url}\n");
    return null;
  }

  return $data;
}

// ---------------- DB upserts ----------------

/**
 * Player gamelog upsert
 */
function upsert_player_gamelog(
  PDO $pdo,
  $contestId,
  $gameDateYmd,
  $teamSide,
  array $teamMeta,
  array $p
) {
  static $stmt = null;
  if ($stmt === null) {
    $stmt = $pdo->prepare("
      INSERT INTO ncaa_player_gamelogs (
        contest_id,
        game_date,
        league,
        team_id,
        team_side,
        team_seoname,
        team_name_short,
        team_name_full,
        player_number,
        player_first_name,
        player_last_name,
        player_name,
        position,
        goals,
        assists,
        shots,
        points,
        pim_minutes,
        plus_raw,
        minus_raw,
        plus_minus,
        faceoff_won,
        faceoff_lost,
        blocks,
        goalie_minutes,
        goals_allowed,
        saves,
        starter,
        participated,
        raw_json,
        created_at,
        updated_at
      ) VALUES (
        :contest_id,
        :game_date,
        'ncaa',
        :team_id,
        :team_side,
        :team_seoname,
        :team_name_short,
        :team_name_full,
        :player_number,
        :player_first_name,
        :player_last_name,
        :player_name,
        :position,
        :goals,
        :assists,
        :shots,
        :points,
        :pim_minutes,
        :plus_raw,
        :minus_raw,
        :plus_minus,
        :faceoff_won,
        :faceoff_lost,
        :blocks,
        :goalie_minutes,
        :goals_allowed,
        :saves,
        :starter,
        :participated,
        :raw_json,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        goals          = VALUES(goals),
        assists        = VALUES(assists),
        shots          = VALUES(shots),
        points         = VALUES(points),
        pim_minutes    = VALUES(pim_minutes),
        plus_raw       = VALUES(plus_raw),
        minus_raw      = VALUES(minus_raw),
        plus_minus     = VALUES(plus_minus),
        faceoff_won    = VALUES(faceoff_won),
        faceoff_lost   = VALUES(faceoff_lost),
        blocks         = VALUES(blocks),
        goalie_minutes = VALUES(goalie_minutes),
        goals_allowed  = VALUES(goals_allowed),
        saves          = VALUES(saves),
        starter        = VALUES(starter),
        participated   = VALUES(participated),
        raw_json       = VALUES(raw_json),
        game_date      = VALUES(game_date),
        team_seoname   = VALUES(team_seoname),
        team_name_short= VALUES(team_name_short),
        team_name_full = VALUES(team_name_full),
        updated_at     = NOW()
    ");
  }

  $goals       = (int)($p['goals'] ?? 0);
  $assists     = (int)($p['assists'] ?? 0);
  $shots       = (int)($p['shots'] ?? 0);
  $points      = $goals + $assists;
  $pimMinutes  = (int)($p['minutes'] ?? 0);
  $plus        = (int)($p['plus'] ?? 0);
  $minus       = (int)($p['minus'] ?? 0);
  $plusMinus   = (int)($p['plusminus'] ?? 0);
  $fow         = (int)($p['facewon'] ?? 0);
  $fol         = (int)($p['facelost'] ?? 0);
  $blocks      = (int)($p['blk'] ?? 0);
  $goalieMin   = (float)($p['goalieMinutes'] ?? 0);
  $ga          = (int)($p['goalsAllowed'] ?? 0);
  $saves       = (int)($p['saves'] ?? 0);
  $starter     = !empty($p['starter']) ? 1 : 0;
  $participated= !empty($p['participated']) ? 1 : 0;

  $firstName   = trim((string)($p['firstName'] ?? ''));
  $lastName    = trim((string)($p['lastName'] ?? ''));
  $fullName    = trim($firstName . ' ' . $lastName);

  $stmt->execute([
    ':contest_id'        => (int)$contestId,
    ':game_date'         => $gameDateYmd,
    ':team_id'           => (int)($teamMeta['teamId'] ?? 0),
    ':team_side'         => $teamSide,
    ':team_seoname'      => $teamMeta['seoname'] ?? null,
    ':team_name_short'   => $teamMeta['nameShort'] ?? null,
    ':team_name_full'    => $teamMeta['nameFull'] ?? null,
    ':player_number'     => isset($p['number']) ? (int)$p['number'] : null,
    ':player_first_name' => $firstName ?: null,
    ':player_last_name'  => $lastName ?: null,
    ':player_name'       => $fullName ?: null,
    ':position'          => $p['position'] ?? null,
    ':goals'             => $goals,
    ':assists'           => $assists,
    ':shots'             => $shots,
    ':points'            => $points,
    ':pim_minutes'       => $pimMinutes,
    ':plus_raw'          => $plus,
    ':minus_raw'         => $minus,
    ':plus_minus'        => $plusMinus,
    ':faceoff_won'       => $fow,
    ':faceoff_lost'      => $fol,
    ':blocks'            => $blocks,
    ':goalie_minutes'    => $goalieMin,
    ':goals_allowed'     => $ga,
    ':saves'             => $saves,
    ':starter'           => $starter,
    ':participated'      => $participated,
    ':raw_json'          => json_encode(
      $p,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
  ]);
}

/**
 * Team gamelog upsert (from teamStats)
 */
function upsert_team_gamelog(
  PDO $pdo,
  $contestId,
  $gameDateYmd,
  $teamSide,
  array $teamMeta,
  array $ts
) {
  static $stmt = null;
  if ($stmt === null) {
    $stmt = $pdo->prepare("
      INSERT INTO ncaa_team_gamelogs (
        contest_id,
        game_date,
        league,
        team_id,
        team_side,
        team_seoname,
        team_name_short,
        team_name_full,
        goals,
        shots,
        assists,
        pp_goals,
        pp_opportunities,
        pp_percentage,
        sh_goals,
        empty_net_goals,
        goals_allowed,
        saves,
        pim_minutes,
        plus_raw,
        minus_raw,
        plus_minus,
        faceoff_won,
        faceoff_lost,
        blocks,
        raw_json,
        created_at,
        updated_at
      ) VALUES (
        :contest_id,
        :game_date,
        'ncaa',
        :team_id,
        :team_side,
        :team_seoname,
        :team_name_short,
        :team_name_full,
        :goals,
        :shots,
        :assists,
        :pp_goals,
        :pp_opportunities,
        :pp_percentage,
        :sh_goals,
        :empty_net_goals,
        :goals_allowed,
        :saves,
        :pim_minutes,
        :plus_raw,
        :minus_raw,
        :plus_minus,
        :faceoff_won,
        :faceoff_lost,
        :blocks,
        :raw_json,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        goals            = VALUES(goals),
        shots            = VALUES(shots),
        assists          = VALUES(assists),
        pp_goals         = VALUES(pp_goals),
        pp_opportunities = VALUES(pp_opportunities),
        pp_percentage    = VALUES(pp_percentage),
        sh_goals         = VALUES(sh_goals),
        empty_net_goals  = VALUES(empty_net_goals),
        goals_allowed    = VALUES(goals_allowed),
        saves            = VALUES(saves),
        pim_minutes      = VALUES(pim_minutes),
        plus_raw         = VALUES(plus_raw),
        minus_raw        = VALUES(minus_raw),
        plus_minus       = VALUES(plus_minus),
        faceoff_won      = VALUES(faceoff_won),
        faceoff_lost     = VALUES(faceoff_lost),
        blocks           = VALUES(blocks),
        raw_json         = VALUES(raw_json),
        game_date        = VALUES(game_date),
        team_seoname     = VALUES(team_seoname),
        team_name_short  = VALUES(team_name_short),
        team_name_full   = VALUES(team_name_full),
        updated_at       = NOW()
    ");
  }

  $goals          = (int)($ts['goals'] ?? 0);
  $shots          = (int)($ts['shots'] ?? 0);
  $assists        = (int)($ts['assists'] ?? 0);
  $ppGoals        = (int)($ts['powerPlayGoals'] ?? 0);
  $ppOpp          = (int)($ts['powerPlayOpportunities'] ?? 0);
  $ppPct          = (float)($ts['powerPlayPercentage'] ?? 0);
  $shGoals        = (int)($ts['shortHandedGoals'] ?? 0);
  $emptyNetGoals  = (int)($ts['emptyNetGoals'] ?? 0);
  $goalsAllowed   = (int)($ts['goalsAllowed'] ?? 0);
  $saves          = (int)($ts['saves'] ?? 0);
  $pimMinutes     = (int)($ts['minutes'] ?? 0);
  $plus           = (int)($ts['plus'] ?? 0);
  $minus          = (int)($ts['minus'] ?? 0);
  $plusMinus      = (int)($ts['plusminus'] ?? 0);
  $faceWon        = (int)($ts['facewon'] ?? 0);
  $faceLost       = (int)($ts['facelost'] ?? 0);
  $blocks         = (int)($ts['blk'] ?? 0);

  $stmt->execute([
    ':contest_id'        => (int)$contestId,
    ':game_date'         => $gameDateYmd,
    ':team_id'           => (int)($teamMeta['teamId'] ?? 0),
    ':team_side'         => $teamSide,
    ':team_seoname'      => $teamMeta['seoname'] ?? null,
    ':team_name_short'   => $teamMeta['nameShort'] ?? null,
    ':team_name_full'    => $teamMeta['nameFull'] ?? null,
    ':goals'             => $goals,
    ':shots'             => $shots,
    ':assists'           => $assists,
    ':pp_goals'          => $ppGoals,
    ':pp_opportunities'  => $ppOpp,
    ':pp_percentage'     => $ppPct,
    ':sh_goals'          => $shGoals,
    ':empty_net_goals'   => $emptyNetGoals,
    ':goals_allowed'     => $goalsAllowed,
    ':saves'             => $saves,
    ':pim_minutes'       => $pimMinutes,
    ':plus_raw'          => $plus,
    ':minus_raw'         => $minus,
    ':plus_minus'        => $plusMinus,
    ':faceoff_won'       => $faceWon,
    ':faceoff_lost'      => $faceLost,
    ':blocks'            => $blocks,
    ':raw_json'          => json_encode(
      $ts,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
  ]);
}

// ---------------- Fetch games from ncaa_games ----------------

function get_games_for_date(PDO $pdo, $ymd) {
  static $stmt = null;
  if ($stmt === null) {
    $stmt = $pdo->prepare("
      SELECT game_id, game_date
      FROM ncaa_games
      WHERE game_date = :game_date
    ");
  }

  $stmt->execute([':game_date' => $ymd]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------- Per-date processing ----------------

function process_date($date, PDO $pdo, $apiBase) {
  $ymd = $date->format('Y-m-d');
  echo "=== Processing NCAA gamelogs for {$ymd} ===\n";

  $games = get_games_for_date($pdo, $ymd);
  if (empty($games)) {
    echo "No games in ncaa_games for {$ymd}\n";
    return;
  }

  $totalPlayerRows = 0;
  $totalTeamRows   = 0;

  foreach ($games as $gRow) {
    // game_id is stored as varchar but is the numeric contest ID used by ncaa-api
    $contestId = isset($gRow['game_id']) ? (int)$gRow['game_id'] : null;
    if (!$contestId) {
      continue;
    }

    $boxUrl = sprintf(
      '%s/game/%d/boxscore',
      rtrim($apiBase, '/'),
      $contestId
    );

    $box = http_get_json($boxUrl);
    if ($box === null) {
      echo "  [{$contestId}] no boxscore data\n";
      continue;
    }

    $contestIdBox = isset($box['contestId']) ? (int)$box['contestId'] : $contestId;
    $teams        = isset($box['teams']) && is_array($box['teams']) ? $box['teams'] : [];
    $teamBoxes    = isset($box['teamBoxscore']) && is_array($box['teamBoxscore']) ? $box['teamBoxscore'] : [];

    if (empty($teams) || empty($teamBoxes)) {
      echo "  [{$contestIdBox}] missing teams/teamBoxscore\n";
      continue;
    }

    // Map teamId -> meta & home/away
    $teamMetaById = [];
    $homeTeamId   = null;
    $awayTeamId   = null;

    foreach ($teams as $t) {
      $tid = isset($t['teamId']) ? (int)$t['teamId'] : 0;
      if (!$tid) continue;

      $teamMetaById[$tid] = $t;
      if (!empty($t['isHome'])) {
        $homeTeamId = $tid;
      } else {
        $awayTeamId = $tid;
      }
    }

    $playersThisGame = 0;
    $teamsThisGame   = 0;

    foreach ($teamBoxes as $tb) {
      $tid = isset($tb['teamId']) ? (int)$tb['teamId'] : 0;
      if (!$tid) continue;

      if ($homeTeamId && $tid === $homeTeamId) {
        $side = 'home';
      } elseif ($awayTeamId && $tid === $awayTeamId) {
        $side = 'away';
      } else {
        $side = 'home'; // fallback
      }

      $teamMeta = isset($teamMetaById[$tid]) ? $teamMetaById[$tid] : [
        'teamId'    => $tid,
        'seoname'   => null,
        'nameShort' => null,
        'nameFull'  => null,
      ];

      // TEAM GAMELOG
      $teamStats = isset($tb['teamStats']) && is_array($tb['teamStats']) ? $tb['teamStats'] : [];
      upsert_team_gamelog(
        $pdo,
        $contestIdBox,
        $ymd,
        $side,
        $teamMeta,
        $teamStats
      );
      $teamsThisGame++;

      // PLAYER GAMELOGS
      $players = isset($tb['playerStats']) && is_array($tb['playerStats']) ? $tb['playerStats'] : [];
      foreach ($players as $p) {
        upsert_player_gamelog(
          $pdo,
          $contestIdBox,
          $ymd,
          $side,
          $teamMeta,
          $p
        );
        $playersThisGame++;
      }
    }

    $totalTeamRows   += $teamsThisGame;
    $totalPlayerRows += $playersThisGame;

    echo "  [{$contestIdBox}] saved {$teamsThisGame} team rows, {$playersThisGame} player rows\n";
  }

  echo "Totals for {$ymd}: {$totalTeamRows} team rows, {$totalPlayerRows} player rows\n";
}

// ---------------- Run ----------------

$dates = date_range($start, $end);
foreach ($dates as $d) {
  process_date($d, $pdo, $NCAA_API_BASE);
}

echo "Done.\n";
