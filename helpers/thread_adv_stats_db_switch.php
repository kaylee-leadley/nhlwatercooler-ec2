<?php
//======================================
// File: public/helpers/thread_adv_stats_db_switch.php
// Description: Precomputed stats switch + scrubbed fallback.
// Provides:
//   - sjms_adv_game_date_ymd()
//   - sjms_adv_db_has_rows()
//   - sjms_adv_live_has_pbp()
//   - sjms_adv_live_pbp_has_rows()
//   - sjms_adv_use_precomputed()
//   - sjms_adv_use_db_fallback()
//   - sjms_adv_get_game_teams()   (optional but handy)
//======================================

if (!defined('SJMS_ADV_DB_TABLE')) define('SJMS_ADV_DB_TABLE', 'nhl_players_advanced_stats');
if (!defined('SJMS_ADV_DB_CUTOFF_DAYS')) define('SJMS_ADV_DB_CUTOFF_DAYS', 5);

if (!function_exists('sjms_adv_game_date_ymd')) {
  function sjms_adv_game_date_ymd(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return null;

    // Prefer msf_games.game_date
    try {
      $st = $pdo->prepare("SELECT game_date FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
      $st->execute([':gid' => $gid]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $d = isset($r['game_date']) ? trim((string)$r['game_date']) : '';
      if ($d !== '') return $d;
    } catch (Exception $e) {}

    // Fallback: msf_live_game
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
      $st->execute([':gid' => $gid, ':sk' => (string)$stateKey]);
      return (bool)$st->fetchColumn();
    } catch (Exception $e) {}

    return false;
  }
}

if (!function_exists('sjms_adv_live_has_pbp')) {
  /**
   * Weak check: msf_pbp_get_game_row returns a row.
   * Some scrubbed scenarios leave msf_live_game but purge event rows;
   * that's why sjms_adv_live_pbp_has_rows() exists too.
   */
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
  /**
   * Strong scrub check: do we have ANY pbp event rows for this game?
   */
  function sjms_adv_live_pbp_has_rows(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    try {
      $st = $pdo->prepare("SELECT 1 FROM msf_live_pbp_event WHERE game_id = :gid LIMIT 1");
      $st->execute([':gid' => $gid]);
      if ($st->fetchColumn()) return true;
    } catch (Exception $e) {}

    // fallback to weak check
    return sjms_adv_live_has_pbp($pdo, $gid);
  }
}

if (!function_exists('sjms_adv_use_precomputed')) {
  function sjms_adv_use_precomputed(PDO $pdo, $gameId, $cutoffDays = null, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    $days = ($cutoffDays === null) ? SJMS_ADV_DB_CUTOFF_DAYS : (int)$cutoffDays;
    if ($days < 0) $days = SJMS_ADV_DB_CUTOFF_DAYS;

    // Must exist in DB
    if (!sjms_adv_db_has_rows($pdo, $gid, $stateKey)) return false;

    // ✅ Scrubbed: if live pbp event rows are gone, use DB immediately.
    if (!sjms_adv_live_pbp_has_rows($pdo, $gid)) return true;

    // Otherwise, old-game cutoff.
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
  /**
   * Use DB if:
   *  - old-game cutoff says so, OR
   *  - live PBP is missing/scrubbed but DB rows exist.
   */
  function sjms_adv_use_db_fallback(PDO $pdo, $gameId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    if (sjms_adv_use_precomputed($pdo, $gid, SJMS_ADV_DB_CUTOFF_DAYS, $stateKey)) return true;

    // "weak" fallback case: game row missing but DB exists
    if (sjms_adv_db_has_rows($pdo, $gid, $stateKey) && !sjms_adv_live_has_pbp($pdo, $gid)) return true;

    return false;
  }
}

if (!function_exists('sjms_adv_get_game_teams')) {
  /**
   * Returns array(away, home). Uses:
   *  1) live PBP game row (if present)
   *  2) msf_games home/away (if present)
   *  3) distinct team_abbr from adv DB table
   */
  function sjms_adv_get_game_teams(PDO $pdo, $gameId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return array('', '');

    // 1) Live PBP
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

    // 2) msf_games
    try {
      $st = $pdo->prepare("SELECT away_team_abbr, home_team_abbr FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
      $st->execute([':gid' => $gid]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if (is_array($r)) {
        $away = strtoupper(trim((string)($r['away_team_abbr'] ?? '')));
        $home = strtoupper(trim((string)($r['home_team_abbr'] ?? '')));
        if ($home !== '' || $away !== '') return array($away, $home);
      }
    } catch (Exception $e) {}

    // 3) last resort: adv table distinct teams
    try {
      $st = $pdo->prepare("
        SELECT DISTINCT team_abbr
        FROM " . SJMS_ADV_DB_TABLE . "
        WHERE game_id = :gid AND state_key = :sk
        ORDER BY team_abbr
        LIMIT 2
      ");
      $st->execute([':gid' => $gid, ':sk' => (string)$stateKey]);
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
