<?php
//======================================
// File: public/helpers/thread_adv_stats_precomputed.php
// Description: Precomputed stats switch + scrubbed fallback + team lookup + logging.
// PHP 7+ friendly.
//======================================

if (!defined('SJMS_ADV_DB_TABLE')) define('SJMS_ADV_DB_TABLE', 'nhl_players_advanced_stats');
if (!defined('SJMS_ADV_DB_CUTOFF_DAYS')) define('SJMS_ADV_DB_CUTOFF_DAYS', 5);

if (!function_exists('sjms_adv_game_date_ymd')) {
  function sjms_adv_game_date_ymd(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return null;

    try {
      $st = $pdo->prepare("SELECT game_date FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
      $st->execute([':gid' => $gid]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $d = isset($r['game_date']) ? trim((string)$r['game_date']) : '';
      if ($d !== '') return $d;
    } catch (Exception $e) {}

    try {
      $st = $pdo->prepare("SELECT game_date FROM msf_live_game WHERE game_id = :gid LIMIT 1");
      $st->execute([':gid' => $gid]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $d = isset($r['game_date']) ? trim((string)$r['game_date']) : '';
      if ($d !== '') return $d;
    } catch (Exception $e) {}

    return null;
  }
}

if (!function_exists('sjms_adv_db_has_rows')) {
  function sjms_adv_db_has_rows(PDO $pdo, $gameId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    try {
      $st = $pdo->prepare("
        SELECT 1
        FROM " . SJMS_ADV_DB_TABLE . "
        WHERE game_id = :gid AND state_key = :sk
        LIMIT 1
      ");
      $st->execute(array(':gid' => $gid, ':sk' => (string)$stateKey));
      return (bool)$st->fetchColumn();
    } catch (Exception $e) {}

    return false;
  }
}

if (!function_exists('sjms_adv_live_has_pbp')) {
  function sjms_adv_live_has_pbp(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;
    if (!function_exists('msf_pbp_get_game_row')) return false;

    try {
      $r = msf_pbp_get_game_row($pdo, $gid);
      return !empty($r);
    } catch (Exception $e) {
      return false;
    }
  }
}

if (!function_exists('sjms_adv_live_pbp_has_rows')) {
  function sjms_adv_live_pbp_has_rows(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    try {
      $st = $pdo->prepare("SELECT 1 FROM msf_live_pbp_event WHERE game_id = :gid LIMIT 1");
      $st->execute(array(':gid' => $gid));
      if ($st->fetchColumn()) return true;
    } catch (Exception $e) {}

    return sjms_adv_live_has_pbp($pdo, $gid);
  }
}

if (!function_exists('sjms_adv_use_precomputed')) {
  function sjms_adv_use_precomputed(PDO $pdo, $gameId, $cutoffDays = null, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    $days = ($cutoffDays === null) ? SJMS_ADV_DB_CUTOFF_DAYS : (int)$cutoffDays;
    if ($days < 0) $days = SJMS_ADV_DB_CUTOFF_DAYS;

    if (!sjms_adv_db_has_rows($pdo, $gid, $stateKey)) return false;

    // scrubbed => DB now
    if (!sjms_adv_live_pbp_has_rows($pdo, $gid)) return true;

    $ymd = sjms_adv_game_date_ymd($pdo, $gid);
    if (!$ymd) return false;

    $tz = new DateTimeZone('America/New_York');
    $gd = DateTime::createFromFormat('Y-m-d', $ymd, $tz);
    if (!$gd) return false;
    $gd->setTime(0, 0, 0);

    $cut = new DateTime('now', $tz);
    $cut->setTime(0, 0, 0);
    $cut->modify('-' . $days . ' days');

    return ($gd <= $cut);
  }
}

if (!function_exists('sjms_adv_use_db_fallback')) {
  function sjms_adv_use_db_fallback(PDO $pdo, $gameId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    if (sjms_adv_use_precomputed($pdo, $gid, SJMS_ADV_DB_CUTOFF_DAYS, $stateKey)) return true;

    if (sjms_adv_db_has_rows($pdo, $gid, $stateKey) && !sjms_adv_live_has_pbp($pdo, $gid)) {
      return true;
    }

    return false;
  }
}

if (!function_exists('sjms_adv_get_game_teams')) {
  function sjms_adv_get_game_teams(PDO $pdo, $gameId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return array('', '');

    if (function_exists('msf_pbp_get_game_row')) {
      try {
        $gr = msf_pbp_get_game_row($pdo, $gid);
        if (is_array($gr)) {
          $home = strtoupper(trim((string)($gr['home_team_abbr'] ?? '')));
          $away = strtoupper(trim((string)($gr['away_team_abbr'] ?? '')));
          if ($home !== '' || $away !== '') return array($away, $home);
        }
      } catch (Exception $e) {}
    }

    try {
      $st = $pdo->prepare("SELECT away_team_abbr, home_team_abbr FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
      $st->execute(array(':gid' => $gid));
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if (is_array($r)) {
        $away = strtoupper(trim((string)($r['away_team_abbr'] ?? '')));
        $home = strtoupper(trim((string)($r['home_team_abbr'] ?? '')));
        if ($home !== '' || $away !== '') return array($away, $home);
      }
    } catch (Exception $e) {}

    try {
      $st = $pdo->prepare("
        SELECT DISTINCT team_abbr
        FROM " . SJMS_ADV_DB_TABLE . "
        WHERE game_id = :gid AND state_key = :sk
        ORDER BY team_abbr
        LIMIT 2
      ");
      $st->execute(array(':gid' => $gid, ':sk' => (string)$stateKey));
      $teams = array();
      while ($x = $st->fetchColumn()) {
        $t = strtoupper(trim((string)$x));
        if ($t !== '') $teams[] = $t;
      }
      return array($teams[0] ?? '', $teams[1] ?? '');
    } catch (Exception $e) {}

    return array('', '');
  }
}

if (!function_exists('sjms_adv_log_mode')) {
  function sjms_adv_log_mode(PDO $pdo, $gameId, array $opts = array()) {
    static $seen = array();

    $gid = (int)$gameId;
    if ($gid <= 0) return;
    if (!empty($seen[$gid])) return;
    $seen[$gid] = true;

    $stateKey = isset($opts['state_key']) && $opts['state_key'] !== '' ? (string)$opts['state_key'] : '5v5';

    $ymd   = sjms_adv_game_date_ymd($pdo, $gid);
    $hasDb = sjms_adv_db_has_rows($pdo, $gid, $stateKey);
    $useDb = sjms_adv_use_db_fallback($pdo, $gid, $stateKey);
    $mode  = $useDb ? 'DB' : 'LIVE';

    $ageStr = '';
    if ($ymd) {
      $tz = new DateTimeZone('America/New_York');
      $gd = DateTime::createFromFormat('Y-m-d', $ymd, $tz);
      if ($gd) {
        $gd->setTime(0, 0, 0);
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);
        $ageStr = (string)((int)$gd->diff($now)->days);
      }
    }

    error_log(sprintf(
      "[ADV] game_id=%d state=%s mode=%s game_date=%s age_days=%s cutoff_days=%d db_rows=%s pbp_live=%s pbp_events=%s",
      $gid,
      $stateKey,
      $mode,
      ($ymd ?: 'null'),
      ($ageStr !== '' ? $ageStr : 'null'),
      (int)SJMS_ADV_DB_CUTOFF_DAYS,
      ($hasDb ? 'yes' : 'no'),
      (sjms_adv_live_has_pbp($pdo, $gid) ? 'yes' : 'no'),
      (sjms_adv_live_pbp_has_rows($pdo, $gid) ? 'yes' : 'no')
    ));
  }
}

if (!function_exists('sjms_adv_available')) {
  function sjms_adv_available(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) return true;
    return sjms_adv_live_has_pbp($pdo, $gid);
  }
}