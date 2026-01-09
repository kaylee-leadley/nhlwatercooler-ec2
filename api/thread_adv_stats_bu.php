<?php
//======================================
// File: public/api/thread_adv_stats.php
// Description: Canonical Advanced Stats renderer + shared helper functions.
// Used by:
//   - /public/api/thread_adv_stats_block.php  (AJAX block loader)
//   - /public/api/thread_api_lineups.php      (for per-player chips)
//
// Depends on:
//   - /public/includes/msf_pbp_helpers.php
//   - /public/helpers/ois_helpers.php            (OIS config + footer)
//   - /public/helpers/adv_quadrant_helpers.php   (xG quadrant points + logo helper)
//
// NOTE (important):
//   If the game is >= 5 days old AND nhl_players_advanced_stats has rows for it,
//   we read precomputed stats from that table (faster + stable reproduction).
//   Otherwise we compute live from msf_live_* via msf_pbp_helpers.php.
//
// UPDATE (scrubbed fallback):
//   If the live PBP tables are scrubbed (live pbp rows missing)
//   but nhl_players_advanced_stats HAS rows, we ALSO use the precomputed table
//   (even if the game is not >= cutoff days).
//
// PHP 7+ friendly.
//======================================

$__sjms_pbp_helpers = __DIR__ . '/../includes/msf_pbp_helpers.php';
if (is_file($__sjms_pbp_helpers)) {
  require_once $__sjms_pbp_helpers;
}

// OIS helpers (weights + footer + shared title)
$__sjms_ois_helpers = __DIR__ . '/../helpers/ois_helpers.php';
if (is_file($__sjms_ois_helpers)) {
  require_once $__sjms_ois_helpers;
}

// Quadrant helpers (points builder lives here now)
$__sjms_adv_quadrant_helpers = __DIR__ . '/../helpers/adv_quadrant_helpers.php';
if (is_file($__sjms_adv_quadrant_helpers)) {
  require_once $__sjms_adv_quadrant_helpers;
}

/* ==========================================================
 * Tiny utils
 * ========================================================== */

if (!function_exists('sjms_as_int')) {
  function sjms_as_int($v, $d = 0) { return is_numeric($v) ? (int)$v : (int)$d; }
}
if (!function_exists('sjms_as_float')) {
  function sjms_as_float($v, $d = null) { return is_numeric($v) ? (float)$v : $d; }
}
if (!function_exists('sjms_h')) {
  function sjms_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sjms_norm_space')) {
  function sjms_norm_space($s) {
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return trim($s);
  }
}
if (!function_exists('sjms_player_name_from_row')) {
  function sjms_player_name_from_row($r) {
    $first = isset($r['first_name']) ? trim((string)$r['first_name']) : '';
    $last  = isset($r['last_name'])  ? trim((string)$r['last_name'])  : '';
    $nm = trim($first . ' ' . $last);
    return $nm !== '' ? $nm : ('Player ' . sjms_as_int($r['player_id'] ?? 0));
  }
}
if (!function_exists('sjms_fmt_pct')) {
  function sjms_fmt_pct($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return number_format((float)$v, 1);
  }
}
if (!function_exists('sjms_fmt_signed')) {
  function sjms_fmt_signed($v, $dec = 2) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    $s = number_format($f, $dec);
    if ($f > 0) $s = '+' . $s;
    return $s;
  }
}

/* ==========================================================
 * Readiness gate (avoid blank/empty charts)
 * ========================================================== */

if (!function_exists('sjms_adv_is_finite_num')) {
  function sjms_adv_is_finite_num($v) {
    return ($v !== null && $v !== '' && is_numeric($v) && is_finite((float)$v));
  }
}

if (!function_exists('sjms_adv_row_has_core_stats')) {
  /**
   * One skater row is "usable" if it has TOI + core 5v5 rate stats.
   * We grade readiness on what your impact chart actually needs.
   */
  function sjms_adv_row_has_core_stats(array $r, $minToi5v5 = 60) {
    $toi5 = (int)($r['toi_5v5'] ?? 0);
    if ($toi5 < (int)$minToi5v5) return false;

    // Core rate stats used by your impact score + charts
    $need = [
      'xgf60_5v5',
      'xga60_5v5',
      'cf60_5v5',
      'ca60_5v5',
      'scf60_5v5',
      'sca60_5v5',
      'gf60_5v5',
      'ga60_5v5',
    ];

    foreach ($need as $k) {
      if (!sjms_adv_is_finite_num($r[$k] ?? null)) return false;
    }

    return true;
  }
}

if (!function_exists('sjms_adv_rows_ready')) {
  /**
   * Team block is "ready" if enough skaters have core stats.
   * Tweak thresholds to taste.
   */
  function sjms_adv_rows_ready(array $rows, $minPlayers = 6, $minToi5v5 = 60) {
    $ok = 0;
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      if (sjms_adv_row_has_core_stats($r, $minToi5v5)) $ok++;
      if ($ok >= (int)$minPlayers) return true;
    }

    // Small sample fallback: allow showing if we have at least 4 usable skaters
    return ($ok >= 4);
  }
}

/* ==========================================================
 * Grading helpers (chips)
 * ========================================================== */

if (!function_exists('sjms_adv_grade_pct')) {
  // fixed thresholds for possession/percent (simple and readable)
  function sjms_adv_grade_pct($pct) {
    if ($pct === null || $pct === '' || !is_numeric($pct)) return '';
    $p = (float)$pct;
    if ($p >= 52.0) return 'is-good';
    if ($p < 45.0)  return 'is-ugly';
    if ($p < 48.0)  return 'is-bad';
    return 'is-neutral';
  }
}

if (!function_exists('sjms_adv_grade_signed')) {
  /**
   * For signed “diff” rates (SC diff/60, G diff/60, etc).
   * deadband avoids +/-0.00 noise looking “good/bad”.
   */
  function sjms_adv_grade_signed($v, $deadband = 0.05) {
    if ($v === null || $v === '' || !is_numeric($v)) return '';
    $x = (float)$v;
    if ($x >  $deadband) return 'is-good';
    if ($x < -$deadband) return 'is-bad';
    return 'is-neutral';
  }
}

if (!function_exists('sjms_percentile')) {
  function sjms_percentile(array $arr, $p) {
    $n = count($arr);
    if ($n === 0) return null;
    sort($arr, SORT_NUMERIC);

    if ($p <= 0) return $arr[0];
    if ($p >= 1) return $arr[$n - 1];

    $pos = ($n - 1) * $p;
    $lo = (int)floor($pos);
    $hi = (int)ceil($pos);
    if ($lo === $hi) return $arr[$lo];

    $t = $pos - $lo;
    return $arr[$lo] + ($arr[$hi] - $arr[$lo]) * $t;
  }
}

/* ==========================================================
 * Goalie detection
 * ========================================================== */

if (!function_exists('sjms_adv_goalie_ids_for_game')) {
  function sjms_adv_goalie_ids_for_game(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    $goalieIds = array();
    try {
      $st = $pdo->prepare("
        SELECT player_id, lineup_position, player_position
        FROM lineups
        WHERE game_id = :gid
          AND player_id IS NOT NULL
      ");
      $st->execute(array(':gid' => $gid));
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($r['player_id'] ?? 0);
        if ($pid <= 0) continue;

        $lp = (string)($r['lineup_position'] ?? '');
        $pp = strtoupper(trim((string)($r['player_position'] ?? '')));

        if (strpos($lp, 'Goalie') === 0 || $pp === 'G') {
          $goalieIds[$pid] = true;
        }
      }
    } catch (Exception $e) {}

    return $goalieIds;
  }
}

/* ==========================================================
 * Precomputed stats switch (>= N days old => nhl_players_advanced_stats)
 * + scrubbed fallback: if live PBP is missing but DB rows exist, use DB.
 * ========================================================== */

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
      $st->execute(array(':gid' => $gid, ':sk' => (string)$stateKey));
      return (bool)$st->fetchColumn();
    } catch (Exception $e) {}

    return false;
  }
}

if (!function_exists('sjms_adv_live_has_pbp')) {
  /**
   * "Live PBP exists" == msf_pbp_get_game_row returns a row.
   * When scrubbed, this typically returns null/false.
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
   * Stronger scrub-check: do we have ANY event rows for this game?
   * This catches cases where msf_live_game still exists but pbp tables were purged.
   */
  function sjms_adv_live_pbp_has_rows(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    try {
      $st = $pdo->prepare("SELECT 1 FROM msf_live_pbp_event WHERE game_id = :gid LIMIT 1");
      $st->execute(array(':gid' => $gid));
      if ($st->fetchColumn()) return true;
    } catch (Exception $e) {}

    // fallback
    return sjms_adv_live_has_pbp($pdo, $gid);
  }
}

if (!function_exists('sjms_adv_use_precomputed')) {
  function sjms_adv_use_precomputed(PDO $pdo, $gameId, $cutoffDays = null, $stateKey = '5v5') {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    $days = ($cutoffDays === null) ? SJMS_ADV_DB_CUTOFF_DAYS : (int)$cutoffDays;
    if ($days < 0) $days = SJMS_ADV_DB_CUTOFF_DAYS;

    // Must exist in DB or we can’t use it.
    if (!sjms_adv_db_has_rows($pdo, $gid, $stateKey)) return false;

    // ✅ If live PBP rows are gone, use DB immediately (regardless of age).
    if (!sjms_adv_live_pbp_has_rows($pdo, $gid)) {
      return true;
    }

    // Otherwise, enforce the “old game” cutoff.
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
   *  - old-game cutoff rule says so, OR
   *  - live PBP is missing/scrubbed but DB rows exist.
   */
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
  /**
   * Returns array(away, home). Uses:
   *  1) live PBP game row (if present)
   *  2) msf_games home/away (if present)
   *  3) distinct team_abbr from computed table
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
      $st->execute(array(':gid' => $gid));
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
  /**
   * Logs once per request+game.
   */
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

/* ==========================================================
 * DB row fetch
 * ========================================================== */

if (!function_exists('sjms_adv_db_row')) {
  function sjms_adv_db_row(PDO $pdo, $gameId, $playerId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return null;

    try {
      $st = $pdo->prepare("
        SELECT *
        FROM " . SJMS_ADV_DB_TABLE . "
        WHERE game_id = :gid AND player_id = :pid AND state_key = :sk
        LIMIT 1
      ");
      $st->execute(array(':gid' => $gid, ':pid' => $pid, ':sk' => (string)$stateKey));
      $r = $st->fetch(PDO::FETCH_ASSOC);
      return $r ? $r : null;
    } catch (Exception $e) {}

    return null;
  }
}

/* ==========================================================
 * Availability
 * ========================================================== */

if (!function_exists('sjms_adv_available')) {
  function sjms_adv_available(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    // Available if DB rows exist (old-game rule OR scrubbed fallback).
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) return true;

    // Otherwise: live PBP must exist.
    return sjms_adv_live_has_pbp($pdo, $gid);
  }
}

/* ==========================================================
 * Cached maps (CF%, FF%, xGF%)
 * ========================================================== */

if (!function_exists('sjms_adv_maps')) {
  function sjms_adv_maps(PDO $pdo, $gameId) {
    static $cache = array(); // gid => maps
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    if (isset($cache[$gid])) return $cache[$gid];

    $out = array(
      'corsi'   => array(), // pid => ['CF'=>..,'CA'=>..,'CF_pct'=>..]
      'fenwick' => array(), // pid => ['FF'=>..,'FA'=>..,'FF_pct'=>..]
      'xg'      => array(), // pid => ['xGF'=>..,'xGA'=>..,'xGF_pct'=>..,'xG_total'=>..]
    );

    // DB path
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      try {
        $st = $pdo->prepare("
          SELECT player_id,
                 CF, CA, CF_pct,
                 FF, FA, FF_pct,
                 xGF, xGA, xGF_pct
          FROM " . SJMS_ADV_DB_TABLE . "
          WHERE game_id = :gid AND state_key = '5v5'
        ");
        $st->execute(array(':gid' => $gid));
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;

          $out['corsi'][$pid] = array(
            'CF' => (int)($r['CF'] ?? 0),
            'CA' => (int)($r['CA'] ?? 0),
            'CF_pct' => $r['CF_pct'] ?? null,
          );

          $out['fenwick'][$pid] = array(
            'FF' => (int)($r['FF'] ?? 0),
            'FA' => (int)($r['FA'] ?? 0),
            'FF_pct' => $r['FF_pct'] ?? null,
          );

          $xgf = isset($r['xGF']) ? (float)$r['xGF'] : 0.0;
          $xga = isset($r['xGA']) ? (float)$r['xGA'] : 0.0;

          $out['xg'][$pid] = array(
            'xGF'      => $xgf,
            'xGA'      => $xga,
            'xG_total' => ($xgf + $xga),
            'xGF_pct'  => $r['xGF_pct'] ?? null,
          );
        }
      } catch (Exception $e) {}

      $cache[$gid] = $out;
      return $out;
    }

    // Live path
    if (!sjms_adv_available($pdo, $gid)) {
      $cache[$gid] = $out;
      return $out;
    }

    // Corsi / Fenwick maps
    if (function_exists('msf_pbp_get_all_skaters_corsi')) {
      $cRows = msf_pbp_get_all_skaters_corsi($pdo, $gid, '5v5', false, 1);
      if (is_array($cRows)) {
        foreach ($cRows as $r) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          $out['corsi'][$pid] = array(
            'CF' => (int)($r['CF'] ?? 0),
            'CA' => (int)($r['CA'] ?? 0),
            'CF_pct' => isset($r['CF_pct']) ? $r['CF_pct'] : null,
          );
        }
      }

      $fRows = msf_pbp_get_all_skaters_corsi($pdo, $gid, '5v5', true, 1);
      if (is_array($fRows)) {
        foreach ($fRows as $r) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          $out['fenwick'][$pid] = array(
            'FF' => (int)($r['CF'] ?? 0), // returned as CF/CA in fenwick mode
            'FA' => (int)($r['CA'] ?? 0),
            'FF_pct' => isset($r['CF_pct']) ? $r['CF_pct'] : null,
          );
        }
      }
    }

    // xG map
    if (function_exists('msf_pbp_get_all_skaters_xgf')) {
      $xRows = msf_pbp_get_all_skaters_xgf($pdo, $gid, '5v5', false, 0.05);
      if (is_array($xRows)) {
        foreach ($xRows as $r) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          $out['xg'][$pid] = array(
            'xGF'      => isset($r['xGF']) ? (float)$r['xGF'] : 0.0,
            'xGA'      => isset($r['xGA']) ? (float)$r['xGA'] : 0.0,
            'xG_total' => isset($r['xG_total']) ? (float)$r['xG_total'] : (float)((float)($r['xGF'] ?? 0) + (float)($r['xGA'] ?? 0)),
            'xGF_pct'  => isset($r['xGF_pct']) ? $r['xGF_pct'] : null,
          );
        }
      }
    }

    $cache[$gid] = $out;
    return $out;
  }
}

/* ==========================================================
 * On-ice per-player rates (5v5) - CACHED
 * (DB for old games; live pbp for recent)
 * ========================================================== */

if (!function_exists('sjms_adv_player_onice_rates_5v5')) {
  function sjms_adv_player_onice_rates_5v5(PDO $pdo, $gameId, $playerId) {
    static $cache = array(); // gid => pid => rates|null

    $gid = (int)$gameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return null;

    if (isset($cache[$gid]) && array_key_exists($pid, $cache[$gid])) {
      return $cache[$gid][$pid];
    }
    if (!isset($cache[$gid])) $cache[$gid] = array();

    // DB path
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      $r = sjms_adv_db_row($pdo, $gid, $pid, '5v5');
      if (!is_array($r)) {
        $cache[$gid][$pid] = null;
        return null;
      }

      $rates = array(
        'TOI_seconds' => (int)($r['toi_used'] ?? 0),

        'xGF_60' => isset($r['xGF_60']) ? (float)$r['xGF_60'] : null,
        'xGA_60' => isset($r['xGA_60']) ? (float)$r['xGA_60'] : null,

        'CF_60'  => isset($r['CF_60']) ? (float)$r['CF_60'] : null,
        'CA_60'  => isset($r['CA_60']) ? (float)$r['CA_60'] : null,

        'SCF_60' => isset($r['SCF_60']) ? (float)$r['SCF_60'] : null,
        'SCA_60' => isset($r['SCA_60']) ? (float)$r['SCA_60'] : null,

        'GF_60'  => isset($r['GF_60']) ? (float)$r['GF_60'] : null,
        'GA_60'  => isset($r['GA_60']) ? (float)$r['GA_60'] : null,

        'xGDIFF_60' => isset($r['xGDIFF_60']) ? (float)$r['xGDIFF_60'] : null,
        'CDIFF_60'  => isset($r['CDIFF_60'])  ? (float)$r['CDIFF_60']  : null,
        'SCDIFF_60' => isset($r['SCDIFF_60']) ? (float)$r['SCDIFF_60'] : null,
        'GDIFF_60'  => isset($r['GDIFF_60'])  ? (float)$r['GDIFF_60']  : null,
      );

      $cache[$gid][$pid] = $rates;
      return $rates;
    }

    // Live path
    if (!function_exists('msf_pbp_get_player_onice_summary')) return null;
    if (!function_exists('msf_boxscore_get_player_toi_seconds')) return null;
    if (!function_exists('msf_boxscore_pick_toi_for_filter')) return null;
    if (!function_exists('msf_pbp_apply_onice_rates')) return null;

    $sum = msf_pbp_get_player_onice_summary($pdo, $gid, $pid, array(
      'state_key' => '5v5',
      'include_blocked_sc' => true,
      'include_blocked_xg' => false,
    ));
    if (!is_array($sum)) {
      $cache[$gid][$pid] = null;
      return null;
    }

    $toi = msf_boxscore_get_player_toi_seconds($pdo, $gid, $pid);

    $fallback = 0;
    if (is_array($toi)) {
      $fallback = (int)($toi['toi_ev'] ?? 0);
      if ($fallback <= 0) $fallback = (int)($toi['toi_total'] ?? 0);
    }

    $toiSec = (int)msf_boxscore_pick_toi_for_filter($toi, '5v5', $fallback);
    if ($toiSec <= 0 && $fallback > 0) $toiSec = $fallback;

    $rates = msf_pbp_apply_onice_rates($sum, $toiSec);
    if (is_array($rates)) $rates['TOI_seconds'] = $toiSec;

    $cache[$gid][$pid] = (is_array($rates) ? $rates : null);
    return $cache[$gid][$pid];
  }
}

/* ==========================================================
 * Benchmarks for grading top chips
 * TOI = ALL situations (toi_total)
 * Rates = 5v5 (xGF/60, xGA/60, CF/60)
 * ========================================================== */

if (!function_exists('sjms_adv_chip_benchmarks')) {
  function sjms_adv_chip_benchmarks(PDO $pdo, $gameId) {
    static $cache = array(); // gid => benchmarks
    $gid = (int)$gameId;
    if ($gid <= 0) return array();
    if (isset($cache[$gid])) return $cache[$gid];

    $goalieIds = function_exists('sjms_adv_goalie_ids_for_game')
      ? sjms_adv_goalie_ids_for_game($pdo, $gid)
      : array();

    $vals = array(
      'toi'   => array(), // ALL situations, seconds
      'xgf60' => array(), // 5v5
      'xga60' => array(), // 5v5
      'cf60'  => array(), // 5v5
    );

    // DB path
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      try {
        $st = $pdo->prepare("
          SELECT player_id, toi_total, xGF_60, xGA_60, CF_60
          FROM " . SJMS_ADV_DB_TABLE . "
          WHERE game_id = :gid AND state_key = '5v5'
        ");
        $st->execute(array(':gid' => $gid));
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          if (!empty($goalieIds[$pid])) continue;

          $toiAll = (int)($r['toi_total'] ?? 0);
          if ($toiAll > 0) $vals['toi'][] = (float)$toiAll;

          if (isset($r['xGF_60']) && is_numeric($r['xGF_60'])) $vals['xgf60'][] = (float)$r['xGF_60'];
          if (isset($r['xGA_60']) && is_numeric($r['xGA_60'])) $vals['xga60'][] = (float)$r['xGA_60'];
          if (isset($r['CF_60'])  && is_numeric($r['CF_60']))  $vals['cf60'][]  = (float)$r['CF_60'];
        }
      } catch (Exception $e) {}

      $mk = function(array $arr) {
        $arr = array_values(array_filter($arr, function($x){ return is_numeric($x); }));
        if (count($arr) < 6) return array(); // too few skaters -> too noisy
        return array(
          'p10' => sjms_percentile($arr, 0.10),
          'p33' => sjms_percentile($arr, 0.33),
          'p66' => sjms_percentile($arr, 0.66),
          'p90' => sjms_percentile($arr, 0.90),
        );
      };

      $cache[$gid] = array(
        'toi'   => $mk($vals['toi']),
        'xgf60' => $mk($vals['xgf60']),
        'xga60' => $mk($vals['xga60']),
        'cf60'  => $mk($vals['cf60']),
      );
      return $cache[$gid];
    }

    // Live path (needs boxscore TOI)
    if (!function_exists('msf_boxscore_get_player_toi_seconds')) {
      $cache[$gid] = array();
      return $cache[$gid];
    }

    // Collect skater ids for this game
    $pids = array();
    try {
      $st = $pdo->prepare("
        SELECT DISTINCT player_id
        FROM lineups
        WHERE game_id = :gid
          AND player_id IS NOT NULL
      ");
      $st->execute(array(':gid' => $gid));
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($r['player_id'] ?? 0);
        if ($pid <= 0) continue;
        if (!empty($goalieIds[$pid])) continue;
        $pids[$pid] = true;
      }
    } catch (Exception $e) {}

    if (!$pids) {
      $cache[$gid] = array('toi'=>array(),'xgf60'=>array(),'xga60'=>array(),'cf60'=>array());
      return $cache[$gid];
    }

    foreach (array_keys($pids) as $pid) {
      $toiArr = msf_boxscore_get_player_toi_seconds($pdo, $gid, $pid);
      $toiAll = 0;
      if (is_array($toiArr)) $toiAll = (int)($toiArr['toi_total'] ?? 0);
      if ($toiAll > 0) $vals['toi'][] = (float)$toiAll;

      $rates = function_exists('sjms_adv_player_onice_rates_5v5')
        ? sjms_adv_player_onice_rates_5v5($pdo, $gid, $pid)
        : null;

      if (!is_array($rates)) continue;

      $xgf60 = $rates['xGF_60'] ?? null;
      $xga60 = $rates['xGA_60'] ?? null;
      $cf60  = $rates['CF_60']  ?? null;

      if ($xgf60 !== null && is_numeric($xgf60)) $vals['xgf60'][] = (float)$xgf60;
      if ($xga60 !== null && is_numeric($xga60)) $vals['xga60'][] = (float)$xga60;
      if ($cf60  !== null && is_numeric($cf60))  $vals['cf60'][]  = (float)$cf60;
    }

    $mk = function(array $arr) {
      $arr = array_values(array_filter($arr, function($x){ return is_numeric($x); }));
      if (count($arr) < 6) return array(); // too few skaters -> too noisy

      return array(
        'p10' => sjms_percentile($arr, 0.10),
        'p33' => sjms_percentile($arr, 0.33),
        'p66' => sjms_percentile($arr, 0.66),
        'p90' => sjms_percentile($arr, 0.90),
      );
    };

    $cache[$gid] = array(
      'toi'   => $mk($vals['toi']),
      'xgf60' => $mk($vals['xgf60']),
      'xga60' => $mk($vals['xga60']),
      'cf60'  => $mk($vals['cf60']),
    );

    return $cache[$gid];
  }
}

if (!function_exists('sjms_adv_grade_by_bench')) {
  /**
   * $dir: 'high' => higher is better (xGF/60, CF/60, TOI)
   *       'low'  => lower is better  (xGA/60)
   */
  function sjms_adv_grade_by_bench($v, array $bench, $dir = 'high') {
    if ($v === null || $v === '' || !is_numeric($v)) return '';

    if (empty($bench) || !isset($bench['p33'], $bench['p66'])) return 'is-neutral';

    $x = (float)$v;

    if ($dir === 'low') {
      if (isset($bench['p90']) && $x >= (float)$bench['p90']) return 'is-ugly';
      if ($x >= (float)$bench['p66']) return 'is-bad';
      if ($x <= (float)$bench['p33']) return 'is-good';
      return 'is-neutral';
    }

    // 'high'
    if (isset($bench['p10']) && $x <= (float)$bench['p10']) return 'is-ugly';
    if ($x <= (float)$bench['p33']) return 'is-bad';
    if ($x >= (float)$bench['p66']) return 'is-good';
    return 'is-neutral';
  }
}

/* ==========================================================
 * GAR/WAR per player (cached)
 * ========================================================== */

if (!function_exists('sjms_adv_player_garwar')) {
  function sjms_adv_player_garwar(PDO $pdo, $gameId, $playerId) {
    static $cache = array(); // gid => pid => arr|null

    $gid = (int)$gameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return null;

    if (isset($cache[$gid]) && array_key_exists($pid, $cache[$gid])) {
      return $cache[$gid][$pid];
    }
    if (!isset($cache[$gid])) $cache[$gid] = array();

    // DB path
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      $r = sjms_adv_db_row($pdo, $gid, $pid, '5v5');
      if (!is_array($r)) {
        $cache[$gid][$pid] = null;
        return null;
      }
      $gar = (isset($r['gar_total']) && is_numeric($r['gar_total'])) ? (float)$r['gar_total'] : null;
      $war = (isset($r['war_total']) && $r['war_total'] !== null && is_numeric($r['war_total'])) ? (float)$r['war_total'] : null;

      $cache[$gid][$pid] = array('GAR' => $gar, 'WAR' => $war);
      return $cache[$gid][$pid];
    }

    // Live path
    if (!sjms_adv_available($pdo, $gid) || !function_exists('msf_pbp_get_player_gar_lite')) {
      $cache[$gid][$pid] = null;
      return null;
    }

    $gar = msf_pbp_get_player_gar_lite($pdo, $gid, $pid, '5v5', false, array(
      'include_corsi'      => true,
      'goals_per_penalty'  => 0.15,
      'goals_per_corsi'    => 0.01,
    ));

    if (!is_array($gar)) {
      $cache[$gid][$pid] = null;
      return null;
    }

    $garTotal = isset($gar['gar_total']) ? (float)$gar['gar_total'] : null;
    $war = null;
    if ($garTotal !== null && function_exists('msf_pbp_gar_to_war')) {
      $w = msf_pbp_gar_to_war($garTotal, 6.0);
      if (is_numeric($w)) $war = (float)$w;
    }

    $cache[$gid][$pid] = array(
      'GAR' => $garTotal,
      'WAR' => $war,
    );
    return $cache[$gid][$pid];
  }
}

/* ==========================================================
 * Chips HTML (used by thread_api_lineups.php)
 * TOI chip = ALL situations (toi_total)
 * Rate chips = 5v5 (/60 + diffs)
 * ========================================================== */

if (!function_exists('sjms_adv_chips_html')) {
  function sjms_adv_chips_html(PDO $pdo, $gameId, $playerId) {
    $pid = (int)$playerId;
    $gid = (int)$gameId;
    if ($pid <= 0 || $gid <= 0) return '';

    $chips = array();

    $benchAll = function_exists('sjms_adv_chip_benchmarks') ? sjms_adv_chip_benchmarks($pdo, $gid) : array();
    $bToi   = $benchAll['toi']   ?? array();
    $bXgf60 = $benchAll['xgf60'] ?? array();
    $bXga60 = $benchAll['xga60'] ?? array();
    $bCf60  = $benchAll['cf60']  ?? array();

    // TOI (ALL situations)
    $toiAllSec = 0;

    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      $r = sjms_adv_db_row($pdo, $gid, $pid, '5v5');
      if (is_array($r)) $toiAllSec = (int)($r['toi_total'] ?? 0);
    } else {
      if (function_exists('msf_boxscore_get_player_toi_seconds')) {
        $toiArr = msf_boxscore_get_player_toi_seconds($pdo, $gid, $pid);
        if (is_array($toiArr)) $toiAllSec = (int)($toiArr['toi_total'] ?? 0);
      }
    }

    if ($toiAllSec > 0) {
      $cls = function_exists('sjms_adv_grade_by_bench')
        ? sjms_adv_grade_by_bench((float)$toiAllSec, $bToi, 'high')
        : '';

      $m = (int)floor($toiAllSec / 60);
      $s = (int)($toiAllSec % 60);
      $toiFmt = sprintf('%d:%02d', $m, $s);

      $chips[] = '<span class="adv-chip adv-chip--toi ' . $cls . '">TOI ' . sjms_h($toiFmt) . '</span>';
    }

    // 5v5 rate chips
    $rates = function_exists('sjms_adv_player_onice_rates_5v5')
      ? sjms_adv_player_onice_rates_5v5($pdo, $gid, $pid)
      : null;

    if (is_array($rates)) {
      $xgf60 = $rates['xGF_60'] ?? null;
      $xga60 = $rates['xGA_60'] ?? null;
      $cf60  = $rates['CF_60']  ?? null;

      $scf60 = $rates['SCF_60'] ?? null;
      $sca60 = $rates['SCA_60'] ?? null;
      $gf60  = $rates['GF_60']  ?? null;
      $ga60  = $rates['GA_60']  ?? null;

      $scdiff60 = (is_numeric($scf60) && is_numeric($sca60)) ? ((float)$scf60 - (float)$sca60) : null;
      $gdiff60  = (is_numeric($gf60)  && is_numeric($ga60))  ? ((float)$gf60  - (float)$ga60)  : null;

      if ($xgf60 !== null && is_numeric($xgf60)) {
        $cls = function_exists('sjms_adv_grade_by_bench') ? sjms_adv_grade_by_bench((float)$xgf60, $bXgf60, 'high') : '';
        $chips[] = '<span class="adv-chip adv-chip--xgf60 ' . $cls . '">xGF/60 ' . sjms_h(number_format((float)$xgf60, 2)) . '</span>';
      }
      if ($xga60 !== null && is_numeric($xga60)) {
        $cls = function_exists('sjms_adv_grade_by_bench') ? sjms_adv_grade_by_bench((float)$xga60, $bXga60, 'low') : '';
        $chips[] = '<span class="adv-chip adv-chip--xga60 ' . $cls . '">xGA/60 ' . sjms_h(number_format((float)$xga60, 2)) . '</span>';
      }
      if ($cf60 !== null && is_numeric($cf60)) {
        $cls = function_exists('sjms_adv_grade_by_bench') ? sjms_adv_grade_by_bench((float)$cf60, $bCf60, 'high') : '';
        $chips[] = '<span class="adv-chip adv-chip--cf60 ' . $cls . '">CF/60 ' . sjms_h(number_format((float)$cf60, 1)) . '</span>';
      }

      if ($scdiff60 !== null && is_numeric($scdiff60)) {
        $cls = function_exists('sjms_adv_grade_signed') ? sjms_adv_grade_signed($scdiff60, 0.10) : '';
        $chips[] = '<span class="adv-chip adv-chip--scdiff60 ' . $cls . '">SC±/60 ' . sjms_h(sjms_fmt_signed($scdiff60, 2)) . '</span>';
      }

      if ($gdiff60 !== null && is_numeric($gdiff60)) {
        $cls = function_exists('sjms_adv_grade_signed') ? sjms_adv_grade_signed($gdiff60, 0.25) : '';
        $chips[] = '<span class="adv-chip adv-chip--gdiff60 ' . $cls . '">G±/60 ' . sjms_h(sjms_fmt_signed($gdiff60, 2)) . '</span>';
      }
    }

    // % chips (CF%, xGF%)
    $maps = function_exists('sjms_adv_maps') ? sjms_adv_maps($pdo, $gid) : array();

    $c = isset($maps['corsi'][$pid]) ? $maps['corsi'][$pid] : null;
    $x = isset($maps['xg'][$pid])    ? $maps['xg'][$pid]    : null;

    $cf_raw  = $c ? ($c['CF_pct'] ?? null) : null;
    $xgf_raw = $x ? ($x['xGF_pct'] ?? null) : null;

    $cfp  = ($c && function_exists('sjms_fmt_pct')) ? sjms_fmt_pct($cf_raw) : null;
    $xgfp = ($x && function_exists('sjms_fmt_pct')) ? sjms_fmt_pct($xgf_raw) : null;

    $xg_total = $x ? (float)($x['xG_total'] ?? 0) : 0.0;
    if ($xgfp !== null && $xg_total < 0.50) $xgfp = null;

    if ($cfp !== null) {
      $cls = function_exists('sjms_adv_grade_pct') ? sjms_adv_grade_pct($cf_raw) : '';
      $chips[] = '<span class="adv-chip adv-chip--cf ' . $cls . '">CF% ' . sjms_h($cfp) . '</span>';
    }

    if ($xgfp !== null) {
      $cls = function_exists('sjms_adv_grade_pct') ? sjms_adv_grade_pct($xgf_raw) : '';
      $chips[] = '<span class="adv-chip adv-chip--xgf ' . $cls . '">xGF% ' . sjms_h($xgfp) . '</span>';
    }

    if (!$chips) return '';
    return '<div class="adv-chips">' . implode('', $chips) . '</div>';
  }
}

/* ==========================================================
 * Team roster rows (from lineups table)
 * ========================================================== */

if (!function_exists('sjms_adv_team_player_rows')) {
  function sjms_adv_team_player_rows(PDO $pdo, $gameId, $teamAbbr) {
    $gid = (int)$gameId;
    $abbr = strtoupper(trim((string)$teamAbbr));
    if ($gid <= 0 || $abbr === '') return array();

    try {
      $st = $pdo->prepare("
        SELECT DISTINCT player_id, first_name, last_name, player_position
        FROM lineups
        WHERE game_id = :gid
          AND team_abbr = :abbr
          AND player_id IS NOT NULL
        ORDER BY player_position, last_name, first_name
      ");
      $st->execute(array(':gid' => $gid, ':abbr' => $abbr));
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
      return array();
    }
  }
}

/* ==========================================================
 * Team GAR/WAR rows for impact chart
 * - live: compute via pbp helper + rates helper
 * - DB fallback (old OR scrubbed): read from nhl_players_advanced_stats
 * ========================================================== */

if (!function_exists('sjms_adv_team_garwar')) {
  function sjms_adv_team_garwar(PDO $pdo, $gameId, $teamAbbr) {
    $gid  = (int)$gameId;
    $abbr = strtoupper(trim((string)$teamAbbr));
    if ($gid <= 0 || $abbr === '') return array();

    $goalieIds = sjms_adv_goalie_ids_for_game($pdo, $gid);
    $players   = sjms_adv_team_player_rows($pdo, $gid, $abbr);
    if (!$players) return array();

    // DB path
    if (sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      $pInfo = array();
      foreach ($players as $p) {
        $pid = (int)($p['player_id'] ?? 0);
        if ($pid <= 0) continue;
        $pInfo[$pid] = array(
          'name' => sjms_player_name_from_row($p),
          'pos'  => strtoupper(trim((string)($p['player_position'] ?? ''))),
        );
      }

      $out = array();

      try {
        $st = $pdo->prepare("
          SELECT player_id,
                 toi_used,
                 xGF_60, xGA_60, CF_60, CA_60, SCF_60, SCA_60, GF_60, GA_60,
                 gar_pen, gar_corsi, gar_goals, gar_total, war_total
          FROM " . SJMS_ADV_DB_TABLE . "
          WHERE game_id = :gid AND state_key = '5v5' AND team_abbr = :abbr
        ");
        $st->execute(array(':gid' => $gid, ':abbr' => $abbr));

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          if (!empty($goalieIds[$pid])) continue;

          $toi5 = (int)($r['toi_used'] ?? 0);

          $xgf60 = (isset($r['xGF_60']) && is_numeric($r['xGF_60'])) ? (float)$r['xGF_60'] : null;
          $xga60 = (isset($r['xGA_60']) && is_numeric($r['xGA_60'])) ? (float)$r['xGA_60'] : null;

          $cf60  = (isset($r['CF_60'])  && is_numeric($r['CF_60']))  ? (float)$r['CF_60']  : null;
          $ca60  = (isset($r['CA_60'])  && is_numeric($r['CA_60']))  ? (float)$r['CA_60']  : null;

          $scf60 = (isset($r['SCF_60']) && is_numeric($r['SCF_60'])) ? (float)$r['SCF_60'] : null;
          $sca60 = (isset($r['SCA_60']) && is_numeric($r['SCA_60'])) ? (float)$r['SCA_60'] : null;

          $gf60  = (isset($r['GF_60'])  && is_numeric($r['GF_60']))  ? (float)$r['GF_60']  : null;
          $ga60  = (isset($r['GA_60'])  && is_numeric($r['GA_60']))  ? (float)$r['GA_60']  : null;

          $xgdiff60 = (is_numeric($xgf60) && is_numeric($xga60)) ? ($xgf60 - $xga60) : null;
          $cfdiff60 = (is_numeric($cf60)  && is_numeric($ca60))  ? ($cf60  - $ca60)  : null;
          $scdiff60 = (is_numeric($scf60) && is_numeric($sca60)) ? ($scf60 - $sca60) : null;
          $gdiff60  = (is_numeric($gf60)  && is_numeric($ga60))  ? ($gf60  - $ga60)  : null;

          $garPen   = (float)($r['gar_pen'] ?? 0.0);
          $garCorsi = (float)($r['gar_corsi'] ?? 0.0);
          $garGoals = (float)($r['gar_goals'] ?? 0.0);
          $garTotal = (float)($r['gar_total'] ?? ($garPen + $garCorsi + $garGoals));
          $garEV    = $garGoals + $garCorsi;

          $war = (isset($r['war_total']) && $r['war_total'] !== null && is_numeric($r['war_total'])) ? (float)$r['war_total'] : null;
          $gar60 = ($toi5 > 0) ? ($garTotal * 3600.0 / $toi5) : null;

          $out[] = array(
            'player_id' => $pid,
            'name' => $pInfo[$pid]['name'] ?? ('Player ' . $pid),
            'pos'  => $pInfo[$pid]['pos'] ?? '',
            'team' => $abbr,

            'ev'  => (float)$garEV,
            'pp'  => 0.0,
            'sh'  => 0.0,
            'pen' => (float)$garPen,

            'gar' => (float)$garTotal,
            'war' => $war,

            'toi_5v5'       => $toi5,

            'xgf60_5v5'     => ($xgf60 === null ? null : (float)$xgf60),
            'xga60_5v5'     => ($xga60 === null ? null : (float)$xga60),
            'xgdiff60_5v5'  => ($xgdiff60 === null ? null : (float)$xgdiff60),

            'cf60_5v5'      => ($cf60 === null ? null : (float)$cf60),
            'ca60_5v5'      => ($ca60 === null ? null : (float)$ca60),
            'cfdiff60_5v5'  => ($cfdiff60 === null ? null : (float)$cfdiff60),

            'scf60_5v5'     => ($scf60 === null ? null : (float)$scf60),
            'sca60_5v5'     => ($sca60 === null ? null : (float)$sca60),
            'scdiff60_5v5'  => ($scdiff60 === null ? null : (float)$scdiff60),

            'gf60_5v5'      => ($gf60 === null ? null : (float)$gf60),
            'ga60_5v5'      => ($ga60 === null ? null : (float)$ga60),
            'gdiff60_5v5'   => ($gdiff60 === null ? null : (float)$gdiff60),

            'gar60_5v5'     => ($gar60 === null ? null : (float)$gar60),
          );
        }
      } catch (Exception $e) {}

      usort($out, function($a, $b) {
        $ad = isset($a['xgdiff60_5v5']) && $a['xgdiff60_5v5'] !== null ? (float)$a['xgdiff60_5v5'] : -9999.0;
        $bd = isset($b['xgdiff60_5v5']) && $b['xgdiff60_5v5'] !== null ? (float)$b['xgdiff60_5v5'] : -9999.0;
        if ($ad == $bd) {
          $ag = (float)($a['gar'] ?? 0);
          $bg = (float)($b['gar'] ?? 0);
          if ($ag == $bg) return 0;
          return ($ag > $bg) ? -1 : 1;
        }
        return ($ad > $bd) ? -1 : 1;
      });

      return $out;
    }

    // Live path (ONLY if live PBP exists)
    if (!sjms_adv_live_has_pbp($pdo, $gid)) return array();
    if (!function_exists('msf_pbp_get_player_gar_lite')) return array();

    $out = array();

    foreach ($players as $p) {
      $pid = (int)($p['player_id'] ?? 0);
      if ($pid <= 0) continue;
      if (!empty($goalieIds[$pid])) continue;

      $gar = msf_pbp_get_player_gar_lite($pdo, $gid, $pid, '5v5', false, array(
        'include_corsi'      => true,
        'goals_per_penalty'  => 0.15,
        'goals_per_corsi'    => 0.01,
      ));
      if (!is_array($gar)) continue;

      $garEV = (float)($gar['gar_goals'] ?? 0) + (float)($gar['gar_corsi'] ?? 0);
      $garPen = (float)($gar['gar_pen'] ?? 0);
      $garTotal = (float)($gar['gar_total'] ?? ($garEV + $garPen));

      $war = null;
      if (function_exists('msf_pbp_gar_to_war')) {
        $war = msf_pbp_gar_to_war($garTotal, 6.0);
        if (!is_numeric($war)) $war = null;
      }

      $rates = function_exists('sjms_adv_player_onice_rates_5v5')
        ? sjms_adv_player_onice_rates_5v5($pdo, $gid, $pid)
        : null;

      $toi5  = is_array($rates) ? (int)($rates['TOI_seconds'] ?? 0) : 0;

      $xgf60 = is_array($rates) ? (isset($rates['xGF_60']) ? (float)$rates['xGF_60'] : null) : null;
      $xga60 = is_array($rates) ? (isset($rates['xGA_60']) ? (float)$rates['xGA_60'] : null) : null;

      $cf60  = is_array($rates) ? (isset($rates['CF_60'])  ? (float)$rates['CF_60']  : null) : null;
      $ca60  = is_array($rates) ? (isset($rates['CA_60'])  ? (float)$rates['CA_60']  : null) : null;

      $scf60 = is_array($rates) ? (isset($rates['SCF_60']) ? (float)$rates['SCF_60'] : null) : null;
      $sca60 = is_array($rates) ? (isset($rates['SCA_60']) ? (float)$rates['SCA_60'] : null) : null;

      $gf60  = is_array($rates) ? (isset($rates['GF_60'])  ? (float)$rates['GF_60']  : null) : null;
      $ga60  = is_array($rates) ? (isset($rates['GA_60'])  ? (float)$rates['GA_60']  : null) : null;

      $xgdiff60 = (is_numeric($xgf60) && is_numeric($xga60)) ? ((float)$xgf60 - (float)$xga60) : null;
      $cfdiff60 = (is_numeric($cf60)  && is_numeric($ca60))  ? ((float)$cf60  - (float)$ca60)  : null;
      $scdiff60 = (is_numeric($scf60) && is_numeric($sca60)) ? ((float)$scf60 - (float)$sca60) : null;
      $gdiff60  = (is_numeric($gf60)  && is_numeric($ga60))  ? ((float)$gf60  - (float)$ga60)  : null;

      $gar60 = ($toi5 > 0) ? ((float)$garTotal * 3600.0 / $toi5) : null;

      $out[] = array(
        'player_id' => $pid,
        'name' => sjms_player_name_from_row($p),
        'pos'  => strtoupper(trim((string)($p['player_position'] ?? ''))),
        'team' => $abbr,

        'ev'  => (float)$garEV,
        'pp'  => 0.0,
        'sh'  => 0.0,
        'pen' => (float)$garPen,

        'gar' => (float)$garTotal,
        'war' => ($war === null ? null : (float)$war),

        'toi_5v5'       => $toi5,

        'xgf60_5v5'     => ($xgf60 === null ? null : (float)$xgf60),
        'xga60_5v5'     => ($xga60 === null ? null : (float)$xga60),
        'xgdiff60_5v5'  => ($xgdiff60 === null ? null : (float)$xgdiff60),

        'cf60_5v5'      => ($cf60 === null ? null : (float)$cf60),
        'ca60_5v5'      => ($ca60 === null ? null : (float)$ca60),
        'cfdiff60_5v5'  => ($cfdiff60 === null ? null : (float)$cfdiff60),

        'scf60_5v5'     => ($scf60 === null ? null : (float)$scf60),
        'sca60_5v5'     => ($sca60 === null ? null : (float)$sca60),
        'scdiff60_5v5'  => ($scdiff60 === null ? null : (float)$scdiff60),

        'gf60_5v5'      => ($gf60 === null ? null : (float)$gf60),
        'ga60_5v5'      => ($ga60 === null ? null : (float)$ga60),
        'gdiff60_5v5'   => ($gdiff60 === null ? null : (float)$gdiff60),

        'gar60_5v5'     => ($gar60 === null ? null : (float)$gar60),
      );
    }

    usort($out, function($a, $b) {
      $ad = isset($a['xgdiff60_5v5']) && $a['xgdiff60_5v5'] !== null ? (float)$a['xgdiff60_5v5'] : -9999.0;
      $bd = isset($b['xgdiff60_5v5']) && $b['xgdiff60_5v5'] !== null ? (float)$b['xgdiff60_5v5'] : -9999.0;
      if ($ad == $bd) {
        $ag = (float)($a['gar'] ?? 0);
        $bg = (float)($b['gar'] ?? 0);
        if ($ag == $bg) return 0;
        return ($ag > $bg) ? -1 : 1;
      }
      return ($ad > $bd) ? -1 : 1;
    });

    return $out;
  }
}

/* ==========================================================
 * Public renderer
 * ========================================================== */

if (!function_exists('sjms_threads_adv_stats_html')) {
  function sjms_threads_adv_stats_html(PDO $pdo, $gameId, $opts = array()) {
    $gid = (int)$gameId;
    if ($gid <= 0) return '';

    if (function_exists('sjms_adv_log_mode')) {
      sjms_adv_log_mode($pdo, $gid, $opts);
    }

    $stateKey = isset($opts['state_key']) && $opts['state_key'] !== '' ? (string)$opts['state_key'] : '5v5';

    // OIS canonical config (weights + TOI_REF)
    $ois = function_exists('ois_defaults') ? ois_defaults() : array(
      'w_xg' => 0.45, 'w_sc' => 0.25, 'w_cf' => 0.20, 'w_g' => 0.05, 'w_pen' => 0.05,
      'toi_ref' => 600,
    );
    $TOI_REF = (int)($ois['toi_ref'] ?? 600);
    $W_XG    = (float)($ois['w_xg'] ?? 0.45);
    $W_SC    = (float)($ois['w_sc'] ?? 0.25);
    $W_CF    = (float)($ois['w_cf'] ?? 0.20);
    $W_G     = (float)($ois['w_g']  ?? 0.05);
    $W_PEN   = (float)($ois['w_pen']?? 0.05);

    $pair = function_exists('sjms_adv_get_game_teams') ? sjms_adv_get_game_teams($pdo, $gid, $stateKey) : array('', '');

    $away = strtoupper(trim((string)($pair[0] ?? '')));
    $home = strtoupper(trim((string)($pair[1] ?? '')));
    if ($home === '' && $away === '') return '';

    $title        = isset($opts['title']) ? (string)$opts['title'] : 'Advanced Stats';
    $showQuadrant = array_key_exists('show_quadrant', $opts) ? (bool)$opts['show_quadrant'] : true;

    $teams = array();
    if ($away !== '') $teams[] = $away;
    if ($home !== '' && $home !== $away) $teams[] = $home;

    $minPlayers = isset($opts['min_players']) ? (int)$opts['min_players'] : 6;
    $minToi5v5  = isset($opts['min_toi_5v5']) ? (int)$opts['min_toi_5v5'] : 60;

    $blocks = array();
    $anyReady = false;

    foreach ($teams as $abbr) {
      $rows = function_exists('sjms_adv_team_garwar') ? sjms_adv_team_garwar($pdo, $gid, $abbr) : array();
      if (!$rows) continue;

      if (!function_exists('sjms_adv_rows_ready') || !sjms_adv_rows_ready($rows, $minPlayers, $minToi5v5)) {
        continue;
      }

      $minToiForChart = 240; // must match data-min-toi you output
      if (function_exists('ois_compute_rows')) {
        $rows = ois_compute_rows($rows, $ois, array(
          'state_key' => $stateKey,
          'min_toi'   => $minToiForChart,
        ));
      }
      $anyReady = true;

      $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      $impactTitle = function_exists('ois_title')
        ? ois_title($stateKey, $abbr)
        : ('Overall Impact Score (xG±/60 + SC±/60 + CF±/60 + G±/60) • ' . $stateKey . ' • ' . $abbr);

      $blocks[] =
        '<div class="adv-team">' .
          '<h3 class="adv-team__hdr">' . sjms_h($abbr) . '</h3>' .

          '<div class="adv-chart adv-chart--impact js-adv-impactscore" ' .
            'data-team="' . sjms_h($abbr) . '" ' .
            'data-title="' . sjms_h($impactTitle) . '" ' .
            'data-min-toi="240" ' .
            'data-toi-ref="' . sjms_h($TOI_REF) . '" ' .
            'data-scale="2.2" ' .

            // Optional: expose weights (safe even if JS ignores)
            'data-w-xg="'  . sjms_h($W_XG)  . '" ' .
            'data-w-sc="'  . sjms_h($W_SC)  . '" ' .
            'data-w-cf="'  . sjms_h($W_CF)  . '" ' .
            'data-w-g="'   . sjms_h($W_G)   . '" ' .
            'data-w-pen="' . sjms_h($W_PEN) . '" ' .

            'data-rows="' . sjms_h($json) . '"' .
          '></div>' .

        '</div>';
    }

    if (!$anyReady) return '';

    $quadHtml = '';

    if ($showQuadrant) {
      $points = function_exists('sjms_adv_xg_quadrant_points')
        ? sjms_adv_xg_quadrant_points($pdo, $gid)
        : array();

      if (!empty($points)) {
        $pointsJson = json_encode($points, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $quadHtml =
          '<div class="adv-block adv-block--quadrant">' .
            '<div class="xgq js-xgq-svg" ' .
              'data-auto-scale="1" ' .
              'data-title="' . sjms_h('Expected Goals For vs. Against - 5v5') . '" ' .
              'data-points="' . sjms_h($pointsJson) . '"' .
            '></div>' .
          '</div>';
      }
    }

    // Footer: prefer OIS helper footer
    $footer = '';
    if (function_exists('ois_footer_html')) {
      $footer = (string)ois_footer_html();
    } elseif (function_exists('sjms_adv_impact_footer_html')) {
      $footer = (string)sjms_adv_impact_footer_html();
    }

    return
      '<section class="thread-adv-stats" aria-label="Advanced Stats">' .
        '<header class="thread-adv-stats__header">' .
          '<h2>' . sjms_h($title) . '</h2>' .
        '</header>' .

        $quadHtml .

        '<div class="thread-adv-stats__grid">' . implode('', $blocks) . '</div>' .

        $footer .

      '</section>';
  }
}