<?php

// public/scripts/cron_import_msf_pbp.php
//
// Imports MySportsFeeds play-by-play into msf_live_*.
//
// Modes:
//   1) Manual single game by full MSF game code:
//      php cron_import_msf_pbp.php --season=2025-2026 --game=20251007-PIT-NYR [--type=regular]
//   2) Manual single date (all games that date):
//      php cron_import_msf_pbp.php --date=2025-12-19 [--type=regular]
//   3) Manual single date + matchup filter (either order):
//      php cron_import_msf_pbp.php --date=2025-12-20 --game=SEA-SJS
//      php cron_import_msf_pbp.php --date=2025-12-20 --game=SJS-SEA
//   4) Manual date RANGE (inclusive):
//      php cron_import_msf_pbp.php --from=2025-12-14 --to=2025-12-19 [--type=regular]
//      (optional matchup filter also works)
//      php cron_import_msf_pbp.php --from=2025-12-14 --to=2025-12-19 --game=SEA-SJS
//   5) Backfill N days + today:
//      php cron_import_msf_pbp.php --days=3 [--type=regular]
//   6) Default cron mode (yesterday + today):
//      php cron_import_msf_pbp.php
//
// Notes:
// - Fixes MSF occasional duplicate officials by using local dedupe + INSERT IGNORE.
// - Adds retry-on-lock (1205) / deadlock (1213).
// - Wraps each game import in a try/catch so one bad game won’t abort the whole date backfill.
// - Uses per-game: short tx for game/meta, then bulk delete PBP by game, then chunked inserts.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__, 2); // /gameday-board (adjust if needed)
require_once $root . '/config/db.php';
$msfConfig = require $root . '/config/msf.php';

$apiKey = $msfConfig['api_key'];
$tzName = !empty($msfConfig['timezone']) ? $msfConfig['timezone'] : 'America/New_York';
$tz     = new DateTimeZone($tzName);

$baseUrl = 'https://api.mysportsfeeds.com/v2.1/pull';

/* -------------------------
   CLI args
-------------------------- */
$args = [];
for ($i = 1; $i < count($argv); $i++) {
  $a = $argv[$i];
  if (strpos($a, '--') === 0) {
    $kv = explode('=', substr($a, 2), 2);
    $args[$kv[0]] = isset($kv[1]) ? $kv[1] : '1';
  }
}

$dryRun = !empty($args['dry-run']) && $args['dry-run'] !== '0';
$type   = !empty($args['type']) ? trim($args['type']) : 'regular';

// Manual single game by full MSF game code (YYYYMMDD-AWAY-HOME)
$manualSeason = !empty($args['season']) ? trim($args['season']) : '';
$manualGame   = !empty($args['game'])   ? trim($args['game'])   : ''; // can be full MSF code OR matchup like SEA-SJS

// Manual single date
$manualDate = !empty($args['date']) ? trim($args['date']) : '';

// Manual date range (inclusive): --from, --to
$rangeFrom = !empty($args['from']) ? trim($args['from']) : '';
$rangeTo   = !empty($args['to'])   ? trim($args['to'])   : '';

// Backfill days (N days back + today)
$backDays = isset($args['days']) ? (int)$args['days'] : null;

// Optional: --game=SEA-SJS when used with --date=YYYY-MM-DD OR --from/--to (filters games by matchup)
$filterAwayAbbr = null;
$filterHomeAbbr = null;

$hasDateMode = ($manualDate !== '') || ($rangeFrom !== '' && $rangeTo !== '');

if ($hasDateMode && $manualGame !== '') {
  // If manualGame looks like "SEA-SJS" (no 8-digit date prefix), treat as matchup filter
  if (preg_match('/^[A-Za-z]{2,4}-[A-Za-z]{2,4}$/', $manualGame)) {
    $parts = explode('-', $manualGame, 2);
    $filterAwayAbbr = strtoupper($parts[0]);
    $filterHomeAbbr = strtoupper($parts[1]);
  }
}

/* -------------------------
   Helpers
-------------------------- */

function json_encode_safe($v) {
  $j = json_encode($v);
  if ($j === false) return '{}';
  return $j;
}

function is_date_ymd($s) {
  return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

function date_range_inclusive_tz(string $from, string $to, DateTimeZone $tz): array {
  $out = [];

  $d1 = DateTime::createFromFormat('Y-m-d', $from, $tz);
  $d2 = DateTime::createFromFormat('Y-m-d', $to,   $tz);
  if (!$d1 || !$d2) return $out;

  $d1->setTime(0,0,0);
  $d2->setTime(0,0,0);
  if ($d2 < $d1) return $out;

  for ($d = clone $d1; $d <= $d2; $d->modify('+1 day')) {
    $out[] = $d->format('Y-m-d');
  }
  return $out;
}

function to_dt_utc_or_null($iso) {
  if (!$iso) return null;
  $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $iso, new DateTimeZone('UTC'));
  if (!$dt) {
    $dt = DateTime::createFromFormat(DateTime::ATOM, $iso);
    if (!$dt) return null;
    $dt->setTimezone(new DateTimeZone('UTC'));
  }
  return $dt->format('Y-m-d H:i:s');
}

function parse_skater_str($s) {
  if (!$s || strpos($s, '_ON_') === false) return [null, null];
  $parts = explode('_ON_', $s, 2);
  $a = is_numeric($parts[0]) ? (int)$parts[0] : null;
  $b = is_numeric($parts[1]) ? (int)$parts[1] : null;
  return [$a, $b];
}

function state_key($awaySk, $homeSk) {
  if ($awaySk === null || $homeSk === null) return null;
  return $awaySk . 'v' . $homeSk;
}

function normalize_players_list($maybeList) {
  if ($maybeList === null) return [];
  // single object form: {"player":{...},"location":null}
  if (is_array($maybeList) && isset($maybeList['player'])) return [$maybeList];
  if (is_array($maybeList)) return $maybeList;
  return [];
}

function add_actor(&$actors, $role, $playerObj) {
  if (!is_array($playerObj) || empty($playerObj['id'])) return;
  $pid = (int)$playerObj['id'];
  $actors[] = [$role, $pid];
}

function event_type_from_play($play) {
  // exactly one of these keys exists per play in this feed
  foreach (['playerChange','faceoff','shotAttempt','goal','giveaway','takeaway','hit','penalty'] as $k) {
    if (isset($play[$k])) return $k;
  }
  return 'other';
}

function build_game_code_from_row($row) {
  // msf_games.game_date is YYYY-MM-DD, but MSF game code uses YYYYMMDD
  $ymd  = str_replace('-', '', $row['game_date']);
  $away = strtoupper($row['away_team_abbr']);
  $home = strtoupper($row['home_team_abbr']);
  return "{$ymd}-{$away}-{$home}";
}

function abbr_or_null($s) {
  $s = strtoupper(trim((string)$s));
  return ($s === '') ? null : $s;
}

/* -------------------------
   MSF GET helper w/ timeout + retry/backoff
-------------------------- */

function msf_get_json($url, $apiKey, $maxAttempts = 6, $baseDelayMs = 450) {
  $attempt = 0;

  while (true) {
    $attempt++;

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => 'gzip',
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 25,
      CURLOPT_HTTPHEADER     => [
        'Authorization: Basic ' . base64_encode($apiKey . ':MYSPORTSFEEDS'),
      ],
    ]);

    $resp = curl_exec($ch);
    $err  = ($resp === false) ? curl_error($ch) : null;
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Success
    if ($err === null && $code >= 200 && $code < 300) {
      $data = json_decode($resp, true);
      return is_array($data) ? $data : null;
    }

    // Retry only on “probably temporary”
    $retryable = false;
    if ($err !== null) $retryable = true;
    if ($code === 429) $retryable = true;
    if ($code >= 500 && $code <= 599) $retryable = true;

    if (!$retryable || $attempt >= $maxAttempts) {
      if ($err !== null) {
        error_log('[cron_import_msf_pbp msf_get_json] curl error: ' . $err . ' url=' . $url);
      } else {
        error_log('[cron_import_msf_pbp msf_get_json] HTTP ' . $code . ' url=' . $url);
      }
      return null;
    }

    // Exponential backoff + jitter
    $delay   = (int)($baseDelayMs * pow(2, $attempt - 1));
    $jitter  = rand(0, 250);
    $sleepMs = min(8000, $delay + $jitter);

    error_log('[cron_import_msf_pbp msf_get_json] retry ' . $attempt . '/' . $maxAttempts .
              ' in ' . $sleepMs . 'ms (code=' . $code . ') url=' . $url);

    usleep($sleepMs * 1000);
  }
}

/* -------------------------
   Lock timeout / deadlock retry helpers
-------------------------- */

function is_lock_retryable_exception($e) {
  if (!($e instanceof PDOException)) return false;
  $msg = $e->getMessage();
  // MySQL: 1205 lock wait timeout, 1213 deadlock
  return (strpos($msg, '1205') !== false) || (strpos($msg, '1213') !== false);
}

function import_one_game_with_retry(PDO $pdo, $apiKey, $baseUrl, $season, $type, $gameCode, $dryRun=false, $maxTries=3) {
  for ($try = 1; $try <= $maxTries; $try++) {
    try {
      return import_one_game($pdo, $apiKey, $baseUrl, $season, $type, $gameCode, $dryRun);
    } catch (Exception $e) {
      if ($try < $maxTries && is_lock_retryable_exception($e)) {
        $sleepMs = 600 + mt_rand(0, 1200) + ($try * 800);
        error_log("[cron_import_msf_pbp] retryable lock error {$gameCode} try {$try}/{$maxTries}, sleeping {$sleepMs}ms: " . $e->getMessage());
        usleep($sleepMs * 1000);
        continue;
      }
      throw $e;
    }
  }
  return false;
}

/* ============================================================
   Core importer: imports one game code for a season
   ============================================================ */
function import_one_game(PDO $pdo, $apiKey, $baseUrl, $season, $type, $gameCode, $dryRun=false) {
  $seasonSlug = $season . '-' . $type;
  $url = $baseUrl . "/nhl/{$seasonSlug}/games/{$gameCode}/playbyplay.json";

  echo "  -> Fetch {$seasonSlug} {$gameCode}\n";

  // Be more tolerant of brief contention
  try {
    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 120");
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
  } catch (Exception $e) {
    // ignore
  }

  $data = msf_get_json($url, $apiKey);
  if (!$data || empty($data['game']) || !isset($data['plays']) || !is_array($data['plays'])) {
    echo "     [WARN] No/invalid data.\n";
    return false;
  }

  $gameObj = $data['game'];
  $plays   = $data['plays'];

  $gameId = (int)($gameObj['id'] ?? 0);
  if (!$gameId) {
    echo "     [WARN] Missing game id.\n";
    return false;
  }

  $awayAbbr = strtoupper($gameObj['awayTeam']['abbreviation'] ?? '');
  $homeAbbr = strtoupper($gameObj['homeTeam']['abbreviation'] ?? '');

  $lastUpdated = to_dt_utc_or_null($data['lastUpdatedOn'] ?? null);
  $startUtc    = to_dt_utc_or_null($gameObj['startTime'] ?? null);
  $endedUtc    = to_dt_utc_or_null($gameObj['endedTime'] ?? null);

  $venueId   = $gameObj['venue']['id']   ?? null;
  $venueName = $gameObj['venue']['name'] ?? null;
  $venueAl   = $gameObj['venueAllegiance'] ?? null;

  $scheduleStatus = $gameObj['scheduleStatus'] ?? null;
  $playedStatus   = $gameObj['playedStatus']   ?? null;
  $attendance     = $gameObj['attendance']     ?? null;

  if ($dryRun) {
    echo "     [DRY] Would upsert game {$gameId} and replace " . count($plays) . " plays.\n";
    return true;
  }

  // Prepared statements for pbp inserts
  $sqlEvent = "
    INSERT INTO msf_live_pbp_event
      (game_id, play_idx, period, seconds_elapsed, event_type, team_abbr,
       x_raw, y_raw,
       away_strength, home_strength, away_skater_str, home_skater_str,
       away_skaters, home_skaters, state_key,
       is_on_goal, is_missed, is_blocked, is_empty_net,
       shot_type, missed_desc,
       faceoff_won_by,
       penalty_severity, penalty_minutes, penalty_type,
       shooter_id, scorer_id, actor_id, goalie_id, blocker_id,
       raw_json)
    VALUES
      (:game_id, :play_idx, :period, :seconds_elapsed, :event_type, :team_abbr,
       :x_raw, :y_raw,
       :away_strength, :home_strength, :away_skater_str, :home_skater_str,
       :away_skaters, :home_skaters, :state_key,
       :is_on_goal, :is_missed, :is_blocked, :is_empty_net,
       :shot_type, :missed_desc,
       :faceoff_won_by,
       :penalty_severity, :penalty_minutes, :penalty_type,
       :shooter_id, :scorer_id, :actor_id, :goalie_id, :blocker_id,
       :raw_json)
    ON DUPLICATE KEY UPDATE
      period           = VALUES(period),
      seconds_elapsed  = VALUES(seconds_elapsed),
      event_type       = VALUES(event_type),
      team_abbr        = VALUES(team_abbr),
      x_raw            = VALUES(x_raw),
      y_raw            = VALUES(y_raw),
      away_strength    = VALUES(away_strength),
      home_strength    = VALUES(home_strength),
      away_skater_str  = VALUES(away_skater_str),
      home_skater_str  = VALUES(home_skater_str),
      away_skaters     = VALUES(away_skaters),
      home_skaters     = VALUES(home_skaters),
      state_key        = VALUES(state_key),
      is_on_goal       = VALUES(is_on_goal),
      is_missed        = VALUES(is_missed),
      is_blocked       = VALUES(is_blocked),
      is_empty_net     = VALUES(is_empty_net),
      shot_type        = VALUES(shot_type),
      missed_desc      = VALUES(missed_desc),
      faceoff_won_by   = VALUES(faceoff_won_by),
      penalty_severity = VALUES(penalty_severity),
      penalty_minutes  = VALUES(penalty_minutes),
      penalty_type     = VALUES(penalty_type),
      shooter_id       = VALUES(shooter_id),
      scorer_id        = VALUES(scorer_id),
      actor_id         = VALUES(actor_id),
      goalie_id        = VALUES(goalie_id),
      blocker_id       = VALUES(blocker_id),
      raw_json         = VALUES(raw_json),
      event_id         = LAST_INSERT_ID(event_id)
  ";
  $stEvent = $pdo->prepare($sqlEvent);

  $stOnIce = $pdo->prepare("
    INSERT INTO msf_live_pbp_on_ice (event_id, side, is_goalie, player_id, position, jersey)
    VALUES (:eid, :side, :is_goalie, :pid, :pos, :jersey)
  ");
  $stActor = $pdo->prepare("
    INSERT INTO msf_live_pbp_actor (event_id, role, player_id)
    VALUES (:eid, :role, :pid)
  ");

  // ------------------------------------------------------------------
  // 1) Short transaction: upsert game + officials/broadcasters
  // ------------------------------------------------------------------
  $pdo->beginTransaction();
  try {
    $sqlGame = "
      INSERT INTO msf_live_game
        (game_id, season_slug, game_code, start_time_utc, ended_time_utc,
         away_team_abbr, home_team_abbr,
         venue_id, venue_name, venue_allegiance,
         schedule_status, played_status, attendance,
         last_updated_on_utc, raw_json)
      VALUES
        (:game_id, :season_slug, :game_code, :start_utc, :ended_utc,
         :away_abbr, :home_abbr,
         :venue_id, :venue_name, :venue_al,
         :schedule_status, :played_status, :attendance,
         :last_updated, :raw_json)
      ON DUPLICATE KEY UPDATE
        season_slug          = VALUES(season_slug),
        game_code            = VALUES(game_code),
        start_time_utc       = VALUES(start_time_utc),
        ended_time_utc       = VALUES(ended_time_utc),
        away_team_abbr       = VALUES(away_team_abbr),
        home_team_abbr       = VALUES(home_team_abbr),
        venue_id             = VALUES(venue_id),
        venue_name           = VALUES(venue_name),
        venue_allegiance     = VALUES(venue_allegiance),
        schedule_status      = VALUES(schedule_status),
        played_status        = VALUES(played_status),
        attendance           = VALUES(attendance),
        last_updated_on_utc  = VALUES(last_updated_on_utc),
        raw_json             = VALUES(raw_json)
    ";
    $pdo->prepare($sqlGame)->execute([
      ':game_id'         => $gameId,
      ':season_slug'     => $seasonSlug,
      ':game_code'       => $gameCode,
      ':start_utc'       => $startUtc,
      ':ended_utc'       => $endedUtc,
      ':away_abbr'       => $awayAbbr,
      ':home_abbr'       => $homeAbbr,
      ':venue_id'        => $venueId,
      ':venue_name'      => $venueName,
      ':venue_al'        => $venueAl,
      ':schedule_status' => $scheduleStatus,
      ':played_status'   => $playedStatus,
      ':attendance'      => $attendance,
      ':last_updated'    => $lastUpdated,
      ':raw_json'        => json_encode_safe($data),
    ]);

    $pdo->prepare("DELETE FROM msf_live_game_official WHERE game_id=:gid")->execute([':gid'=>$gameId]);
    $pdo->prepare("DELETE FROM msf_live_game_broadcaster WHERE game_id=:gid")->execute([':gid'=>$gameId]);

    // Officials: dedupe + INSERT IGNORE
    if (!empty($gameObj['officials']) && is_array($gameObj['officials'])) {
      $st = $pdo->prepare("
        INSERT IGNORE INTO msf_live_game_official
          (game_id, official_id, title, first_name, last_name)
        VALUES
          (:gid, :oid, :title, :first, :last)
      ");
      $seen = [];
      foreach ($gameObj['officials'] as $o) {
        if (empty($o['id'])) continue;
        $oid = (int)$o['id'];
        if ($oid <= 0) continue;
        if (isset($seen[$oid])) continue;
        $seen[$oid] = 1;

        $st->execute([
          ':gid'=>$gameId, ':oid'=>$oid,
          ':title'=>$o['title'] ?? null,
          ':first'=>$o['firstName'] ?? null,
          ':last'=>$o['lastName'] ?? null,
        ]);
      }
    }

    // Broadcasters: dedupe + INSERT IGNORE
    if (!empty($gameObj['broadcasters']) && is_array($gameObj['broadcasters'])) {
      $st = $pdo->prepare("INSERT IGNORE INTO msf_live_game_broadcaster (game_id, name) VALUES (:gid, :name)");
      $seen = [];
      foreach ($gameObj['broadcasters'] as $name) {
        $name = trim((string)$name);
        if ($name === '') continue;
        $k = strtolower($name);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $st->execute([':gid'=>$gameId, ':name'=>$name]);
      }
    }

    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }

  // ------------------------------------------------------------------
  // 2) Replace PBP for this game (bulk delete), then re-insert in chunks
  // ------------------------------------------------------------------
  $pdo->beginTransaction();
  try {
    // delete children first using join on event table
    $pdo->prepare("
      DELETE a FROM msf_live_pbp_actor a
      INNER JOIN msf_live_pbp_event e ON e.event_id = a.event_id
      WHERE e.game_id = :gid
    ")->execute([':gid'=>$gameId]);

    $pdo->prepare("
      DELETE o FROM msf_live_pbp_on_ice o
      INNER JOIN msf_live_pbp_event e ON e.event_id = o.event_id
      WHERE e.game_id = :gid
    ")->execute([':gid'=>$gameId]);

    $pdo->prepare("DELETE FROM msf_live_pbp_event WHERE game_id = :gid")->execute([':gid'=>$gameId]);

    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }

  // Insert fresh plays in chunks
  $chunkSize = 250;
  $inChunk = 0;

  $pdo->beginTransaction();
  try {
    foreach ($plays as $idx => $play) {
      if (!is_array($play)) continue;

      $ps = $play['playStatus'] ?? [];
      $period = (int)($ps['period'] ?? 0);
      $secs   = (int)($ps['secondsElapsed'] ?? 0);

      $awayStrength = $ps['awayTeamStrength'] ?? null;
      $homeStrength = $ps['homeTeamStrength'] ?? null;

      $awaySkStr = $ps['awaySkaterStrength'] ?? null;
      $homeSkStr = $ps['homeSkaterStrength'] ?? null;

      $skStr = $awaySkStr ? $awaySkStr : $homeSkStr;
      list($awaySk, $homeSk) = parse_skater_str($skStr);
      $stateKey = state_key($awaySk, $homeSk);

      $etypeKey  = event_type_from_play($play);
      $eventType = 'OTHER';

      // Defaults
      $teamAbbr   = null;
      $xRaw = null; $yRaw = null;
      $isOn = null; $isMiss = null; $isBlk = null; $isEN = null;
      $shotType = null; $missDesc = null;
      $faceWonBy = null;
      $penSev = null; $penMin = null; $penType = null;
      $shooterId = null; $scorerId = null; $actorId = null; $goalieId = null; $blockerId = null;

      $actors = [];

      if ($etypeKey === 'shotAttempt') {
        $eventType = 'SHOT';
        $sa = $play['shotAttempt'];
        $teamAbbr = abbr_or_null($sa['team']['abbreviation'] ?? null);

        $loc = $sa['shotLocation'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;

        $isOn   = isset($sa['isOnGoal']) ? (int)(!!$sa['isOnGoal']) : null;
        $isMiss = isset($sa['isMissed']) ? (int)(!!$sa['isMissed']) : null;
        $isBlk  = isset($sa['isBlocked']) ? (int)(!!$sa['isBlocked']) : null;

        $shotType = $sa['shotType'] ?? null;
        $missDesc = $sa['missedDescription'] ?? null;

        if (!empty($sa['shootingPlayer'])) {
          $shooterId = (int)$sa['shootingPlayer']['id'];
          add_actor($actors, 'SHOOTER', $sa['shootingPlayer']);
        }
        if (!empty($sa['goalie'])) {
          $goalieId = (int)$sa['goalie']['id'];
          add_actor($actors, 'GOALIE', $sa['goalie']);
        }
        if (!empty($sa['blockingPlayer'])) {
          $blockerId = (int)$sa['blockingPlayer']['id'];
          add_actor($actors, 'BLOCKER', $sa['blockingPlayer']);
        }
      }
      elseif ($etypeKey === 'goal') {
        $eventType = 'GOAL';
        $g = $play['goal'];
        $teamAbbr = abbr_or_null($g['team']['abbreviation'] ?? null);

        $loc = $g['location'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;
        $isEN = isset($g['isEmptyNet']) ? (int)(!!$g['isEmptyNet']) : null;

        if (!empty($g['goalScorer'])) {
          $scorerId = (int)$g['goalScorer']['id'];
          add_actor($actors, 'SCORER', $g['goalScorer']);
        }
        if (!empty($g['primaryAssist'])) add_actor($actors, 'ASSIST1', $g['primaryAssist']);
        if (!empty($g['secondaryAssist'])) add_actor($actors, 'ASSIST2', $g['secondaryAssist']);
        if (!empty($g['goalie'])) {
          $goalieId = (int)$g['goalie']['id'];
          add_actor($actors, 'GOALIE', $g['goalie']);
        }
      }
      elseif ($etypeKey === 'giveaway') {
        $eventType = 'GIVEAWAY';
        $gw = $play['giveaway'];
        $teamAbbr = abbr_or_null($gw['team']['abbreviation'] ?? null);

        $loc = $gw['location'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;

        if (!empty($gw['player'])) {
          $actorId = (int)$gw['player']['id'];
          add_actor($actors, 'GIVEAWAY', $gw['player']);
        }
      }
      elseif ($etypeKey === 'takeaway') {
        $eventType = 'TAKEAWAY';
        $tw = $play['takeaway'];
        $teamAbbr = abbr_or_null($tw['team']['abbreviation'] ?? null);

        $loc = $tw['location'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;

        if (!empty($tw['player'])) {
          $actorId = (int)$tw['player']['id'];
          add_actor($actors, 'TAKEAWAY', $tw['player']);
        }
      }
      elseif ($etypeKey === 'hit') {
        $eventType = 'HIT';
        $h = $play['hit'];
        $teamAbbr = abbr_or_null($h['team']['abbreviation'] ?? null);

        $loc = $h['location'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;

        if (!empty($h['hittingPlayer'])) {
          $actorId = (int)$h['hittingPlayer']['id'];
          add_actor($actors, 'HIT_HITTER', $h['hittingPlayer']);
        }
        if (!empty($h['receivingPlayer'])) {
          add_actor($actors, 'HIT_TARGET', $h['receivingPlayer']);
        }
      }
      elseif ($etypeKey === 'faceoff') {
        $eventType = 'FACEOFF';
        $f = $play['faceoff'];
        $faceWonBy = $f['wonBy'] ?? null; // HOME/AWAY

        if (!empty($f['homePlayer'])) add_actor($actors, 'FACEOFF_HOME_TAKER', $f['homePlayer']);
        if (!empty($f['awayPlayer'])) add_actor($actors, 'FACEOFF_AWAY_TAKER', $f['awayPlayer']);

        if ($faceWonBy === 'HOME' && !empty($f['homePlayer'])) add_actor($actors, 'FACEOFF_WINNER', $f['homePlayer']);
        if ($faceWonBy === 'AWAY' && !empty($f['awayPlayer'])) add_actor($actors, 'FACEOFF_WINNER', $f['awayPlayer']);
      }
      elseif ($etypeKey === 'penalty') {
        $eventType = 'PENALTY';
        $p = $play['penalty'];
        $teamAbbr = abbr_or_null($p['team']['abbreviation'] ?? null);

        $loc = $p['location'] ?? [];
        $xRaw = $loc['x'] ?? null;
        $yRaw = $loc['y'] ?? null;

        $penSev  = $p['severity'] ?? null;
        $penMin  = isset($p['durationMinutes']) ? (int)$p['durationMinutes'] : null;
        $penType = $p['type'] ?? null;

        if (!empty($p['penalizedPlayer'])) {
          $actorId = (int)$p['penalizedPlayer']['id'];
          add_actor($actors, 'PENALIZED', $p['penalizedPlayer']);
        }
      }
      elseif ($etypeKey === 'playerChange') {
        $eventType = 'PLAYER_CHANGE';
        $pc = $play['playerChange'];
        $teamAbbr = abbr_or_null($pc['team']['abbreviation'] ?? null);

        if (!empty($pc['incoming'])) add_actor($actors, 'INCOMING', $pc['incoming']);
        if (!empty($pc['outgoing'])) add_actor($actors, 'OUTGOING', $pc['outgoing']);
      }

      // Insert event
      $stEvent->execute([
        ':game_id' => $gameId,
        ':play_idx' => (int)$idx,
        ':period' => $period,
        ':seconds_elapsed' => $secs,
        ':event_type' => $eventType,
        ':team_abbr' => $teamAbbr,

        ':x_raw' => $xRaw,
        ':y_raw' => $yRaw,

        ':away_strength' => $awayStrength,
        ':home_strength' => $homeStrength,
        ':away_skater_str' => $awaySkStr,
        ':home_skater_str' => $homeSkStr,
        ':away_skaters' => $awaySk,
        ':home_skaters' => $homeSk,
        ':state_key' => $stateKey,

        ':is_on_goal' => $isOn,
        ':is_missed' => $isMiss,
        ':is_blocked' => $isBlk,
        ':is_empty_net' => $isEN,

        ':shot_type' => $shotType,
        ':missed_desc' => $missDesc,

        ':faceoff_won_by' => $faceWonBy,

        ':penalty_severity' => $penSev,
        ':penalty_minutes' => $penMin,
        ':penalty_type' => $penType,

        ':shooter_id' => $shooterId,
        ':scorer_id' => $scorerId,
        ':actor_id' => $actorId,
        ':goalie_id' => $goalieId,
        ':blocker_id' => $blockerId,

        ':raw_json' => json_encode_safe($play),
      ]);

      $eventId = (int)$pdo->lastInsertId();

      // On-ice (dedupe)
      $homeList = normalize_players_list($ps['homePlayersOnIce'] ?? []);
      $awayList = normalize_players_list($ps['awayPlayersOnIce'] ?? []);
      $seenOI = [];

      foreach ($homeList as $row) {
        if (empty($row['player']['id'])) continue;
        $pid = (int)$row['player']['id'];
        $k = "H:0:$pid";
        if (isset($seenOI[$k])) continue;
        $seenOI[$k] = 1;

        $stOnIce->execute([
          ':eid'=>$eventId, ':side'=>'HOME', ':is_goalie'=>0,
          ':pid'=>$pid,
          ':pos'=>$row['player']['position'] ?? null,
          ':jersey'=>$row['player']['jerseyNumber'] ?? null,
        ]);
      }

      foreach ($awayList as $row) {
        if (empty($row['player']['id'])) continue;
        $pid = (int)$row['player']['id'];
        $k = "A:0:$pid";
        if (isset($seenOI[$k])) continue;
        $seenOI[$k] = 1;

        $stOnIce->execute([
          ':eid'=>$eventId, ':side'=>'AWAY', ':is_goalie'=>0,
          ':pid'=>$pid,
          ':pos'=>$row['player']['position'] ?? null,
          ':jersey'=>$row['player']['jerseyNumber'] ?? null,
        ]);
      }

      if (!empty($ps['homeGoalie']['id'])) {
        $pid = (int)$ps['homeGoalie']['id'];
        $stOnIce->execute([
          ':eid'=>$eventId, ':side'=>'HOME', ':is_goalie'=>1,
          ':pid'=>$pid, ':pos'=>$ps['homeGoalie']['position'] ?? 'G',
          ':jersey'=>$ps['homeGoalie']['jerseyNumber'] ?? null,
        ]);
      }
      if (!empty($ps['awayGoalie']['id'])) {
        $pid = (int)$ps['awayGoalie']['id'];
        $stOnIce->execute([
          ':eid'=>$eventId, ':side'=>'AWAY', ':is_goalie'=>1,
          ':pid'=>$pid, ':pos'=>$ps['awayGoalie']['position'] ?? 'G',
          ':jersey'=>$ps['awayGoalie']['jerseyNumber'] ?? null,
        ]);
      }

      // Actors
      foreach ($actors as $a) {
        $stActor->execute([':eid'=>$eventId, ':role'=>$a[0], ':pid'=>$a[1]]);
      }

      $inChunk++;
      if ($inChunk >= $chunkSize) {
        $pdo->commit();
        $pdo->beginTransaction();
        $inChunk = 0;
      }
    }

    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }

  echo "     OK: game_id={$gameId}, plays=" . count($plays) . "\n";
  return true;
}

/* ============================================================
   Determine which date(s) to run + pull matchups from msf_games
   ============================================================ */

function fetch_games_for_date(PDO $pdo, $dateSql) {
  $sql = "
    SELECT msf_game_id, season, game_date, away_team_abbr, home_team_abbr, schedule_status, played_status
    FROM msf_games
    WHERE game_date = :gdate
    ORDER BY start_time_utc ASC, msf_game_id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':gdate' => $dateSql]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

try {
  // Manual single-game mode (full MSF game code like YYYYMMDD-AWAY-HOME)
  if ($manualSeason !== '' && $manualGame !== '') {
    if (!preg_match('/^\d{8}-[A-Za-z]{2,4}-[A-Za-z]{2,4}$/', $manualGame)) {
      fwrite(STDERR, "When using --season, --game must be full MSF code like YYYYMMDD-AWAY-HOME\n");
      exit(1);
    }

    echo "Manual import: season={$manualSeason} type={$type} game={$manualGame}\n";
    import_one_game_with_retry($pdo, $apiKey, $baseUrl, $manualSeason, $type, $manualGame, $dryRun, 3);
    exit(0);
  }

  // Build date list
  $dates = [];

  if ($manualDate !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $manualDate, $tz);
    if (!$dt) {
      fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD\n");
      exit(1);
    }
    $dates[$dt->format('Y-m-d')] = $dt;

  } elseif ($rangeFrom !== '' || $rangeTo !== '') {
    // Range mode requires both
    if (!is_date_ymd($rangeFrom) || !is_date_ymd($rangeTo)) {
      fwrite(STDERR, "Invalid --from/--to. Use YYYY-MM-DD, e.g. --from=2025-12-14 --to=2025-12-19\n");
      exit(1);
    }
    $list = date_range_inclusive_tz($rangeFrom, $rangeTo, $tz);
    if (!$list) {
      fwrite(STDERR, "Invalid range: --from must be <= --to\n");
      exit(1);
    }

    foreach ($list as $dateSql) {
      $dt = DateTime::createFromFormat('Y-m-d', $dateSql, $tz);
      if ($dt) $dates[$dateSql] = $dt;
    }

  } elseif ($backDays !== null) {
    // Backfill mode: --days=N => run (today-N) .. today
    if ($backDays < 0) $backDays = 0;
    if ($backDays > 14) $backDays = 14; // safety cap

    $today = new DateTime('today', $tz);
    for ($back = $backDays; $back >= 0; $back--) {
      $dt = clone $today;
      if ($back > 0) $dt->modify("-{$back} day");
      $dates[$dt->format('Y-m-d')] = $dt;
    }

  } else {
    // Default cron mode: yesterday + today in MSF tz
    $today     = new DateTime('today', $tz);
    $yesterday = new DateTime('yesterday', $tz);
    $dates[$yesterday->format('Y-m-d')] = $yesterday;
    $dates[$today->format('Y-m-d')]     = $today;
  }

  foreach ($dates as $dateSql => $dtObj) {
    echo "=== PBP import for {$dateSql} ({$tzName}) ===\n";
    $rows = fetch_games_for_date($pdo, $dateSql);
    if (!$rows) {
      echo "No games in msf_games for {$dateSql}\n\n";
      continue;
    }

    echo "Found " . count($rows) . " game(s) in msf_games for {$dateSql}\n";

    $matchedAny = false;

    foreach ($rows as $row) {
      // If date/range + --game=SEA-SJS was provided, filter to that matchup (either order)
      if ($filterAwayAbbr && $filterHomeAbbr) {
        $away = strtoupper($row['away_team_abbr']);
        $home = strtoupper($row['home_team_abbr']);

        $match =
          ($away === $filterAwayAbbr && $home === $filterHomeAbbr) ||
          ($away === $filterHomeAbbr && $home === $filterAwayAbbr);

        if (!$match) continue;
      }

      $matchedAny = true;

      $season   = $row['season']; // e.g. "2025-2026"
      $gameCode = build_game_code_from_row($row);

      echo "Game {$row['msf_game_id']} {$gameCode} season={$season}\n";

      // IMPORTANT: don’t let one busted game abort the whole date batch
      try {
        import_one_game_with_retry($pdo, $apiKey, $baseUrl, $season, $type, $gameCode, $dryRun, 3);
      } catch (Exception $e) {
        error_log('[cron_import_msf_pbp] game ' . $row['msf_game_id'] . ' failed: ' . $e->getMessage());
        echo "     [ERROR] " . $e->getMessage() . "\n";
        // continue to next game
      }
    }

    if (($filterAwayAbbr && $filterHomeAbbr) && !$matchedAny) {
      echo "No matching game found for {$dateSql} with matchup {$filterAwayAbbr}-{$filterHomeAbbr}\n";
    }

    echo "\n";
  }

} catch (Exception $e) {
  error_log('[cron_import_msf_pbp] ' . $e->getMessage());
  fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
  exit(2);
}
