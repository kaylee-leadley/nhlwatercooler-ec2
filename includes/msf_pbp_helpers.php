<?php
//======================================
// File: public/includes/msf_pbp_helpers.php
// Description: Helpers for MySportsFeeds play-by-play stored in msf_live_* tables.
//              Focus: on-ice + individual advanced stats from:
//                - msf_live_pbp_event
//                - msf_live_pbp_on_ice
//                - msf_live_pbp_actor
//
//              IMPORTANT (refactor):
//                - Quadrant helper sjms_adv_xg_quadrant_points() was MOVED OUT of here.
//                  It now lives in: public/helpers/adv_quadrant_helpers.php
//
// Assumes tables:
//   msf_live_game
//   msf_live_pbp_event
//   msf_live_pbp_on_ice
//   msf_live_pbp_actor
//   msf_player_gamelogs   (for TOI buckets)
//
// Requires: PHP 7.0+
//
// Notes:
// - "5v5 TOI" is sourced from msf_player_gamelogs.ev_toi (evenStrengthTimeOnIceSeconds).
// - Strength-split TOI can be inconsistent; we sanitize and fall back to total.
// - Coordinates: MSF feeds often use x~[0..700], y~[0..300]. We clamp to avoid negative distances.
//======================================

require_once __DIR__ . '/../helpers/msf_toi_helpers.php';

/* ==========================================================
 *  Constants
 * ========================================================== */

if (!defined('MSF_RINK_X_MAX'))      define('MSF_RINK_X_MAX', 700.0);
if (!defined('MSF_RINK_Y_MAX'))      define('MSF_RINK_Y_MAX', 300.0);
if (!defined('MSF_RINK_LENGTH_FT'))  define('MSF_RINK_LENGTH_FT', 200.0);
if (!defined('MSF_RINK_WIDTH_FT'))   define('MSF_RINK_WIDTH_FT', 85.0);

// Chance buckets (distance-only approximation; tune later)
if (!defined('MSF_SC_DIST_FT')) define('MSF_SC_DIST_FT', 40.0);
if (!defined('MSF_HD_DIST_FT')) define('MSF_HD_DIST_FT', 20.0);
if (!defined('MSF_MD_DIST_FT')) define('MSF_MD_DIST_FT', 30.0);

// TOI storage table/columns (from msf_player_gamelogs)
if (!defined('MSF_PLAYER_GAMELOGS_TABLE')) define('MSF_PLAYER_GAMELOGS_TABLE', 'msf_player_gamelogs');

if (!defined('MSF_TOI_COL_TOTAL')) define('MSF_TOI_COL_TOTAL', 'total_toi');
if (!defined('MSF_TOI_COL_EV'))    define('MSF_TOI_COL_EV',    'ev_toi'); // treated as even-strength (your 5v5 bucket)
if (!defined('MSF_TOI_COL_PP'))    define('MSF_TOI_COL_PP',    'pp_toi');
if (!defined('MSF_TOI_COL_SH'))    define('MSF_TOI_COL_SH',    'sh_toi');

/* ==========================================================
 *  Small utilities
 * ========================================================== */

if (!function_exists('msf_as_int')) {
  function msf_as_int($v, $d = 0) { return is_numeric($v) ? (int)$v : (int)$d; }
}

if (!function_exists('msf_as_float')) {
  function msf_as_float($v, $d = 0.0) { return is_numeric($v) ? (float)$v : (float)$d; }
}

if (!function_exists('msf_pbp_get_game_row')) {
  function msf_pbp_get_game_row(PDO $pdo, $gameId) {
    $st = $pdo->prepare("SELECT * FROM msf_live_game WHERE game_id=:gid LIMIT 1");
    $st->execute([':gid' => (int)$gameId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('msf_pbp_get_game_teams')) {
  function msf_pbp_get_game_teams(PDO $pdo, $gameId) {
    $st = $pdo->prepare("SELECT home_team_abbr, away_team_abbr FROM msf_live_game WHERE game_id=:gid LIMIT 1");
    $st->execute([':gid' => (int)$gameId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) return [null, null];
    $home = strtoupper(trim((string)($g['home_team_abbr'] ?? '')));
    $away = strtoupper(trim((string)($g['away_team_abbr'] ?? '')));
    return [$home !== '' ? $home : null, $away !== '' ? $away : null];
  }
}

/* ==========================================================
 *  State key helpers (NULL/'' treated as 5v5)
 * ========================================================== */

if (!function_exists('msf_pbp_state_key_norm_sql')) {
  /**
   * Normalize MSF state_key so NULL/'' are treated as '5v5'.
   * Use this in WHERE clauses when filtering by strength state.
   */
  function msf_pbp_state_key_norm_sql($col = 'e.state_key') {
    $col = trim((string)$col);
    if ($col === '') $col = 'e.state_key';
    return "COALESCE(NULLIF({$col},''),'5v5')";
  }
}

if (!function_exists('msf_pbp_state_filter_sql')) {
  /**
   * Back-compat helper (single state key).
   * Prefer msf_pbp_state_filter() if you want array support.
   *
   * CHANGE: treat NULL/'' as 5v5 when filtering for 5v5.
   */
  function msf_pbp_state_filter_sql($stateKey) {
    $stateKey = ($stateKey === null) ? '' : trim((string)$stateKey);
    if ($stateKey === '') return '';

    if (strcasecmp($stateKey, '5v5') === 0) {
      return " AND (e.state_key = :state_key OR e.state_key IS NULL OR e.state_key = '') ";
    }

    return " AND e.state_key = :state_key ";
  }
}

if (!function_exists('msf_pbp_state_filter')) {
  /**
   * Safer state filter builder that supports:
   *  - null/'' -> no filter
   *  - string  -> equals (with special handling for 5v5)
   *  - array   -> IN (...) (with special handling for 5v5 if included)
   *
   * Returns: [sqlFragment, paramsArray]
   *
   * CHANGE: when filtering for '5v5', also include NULL/''.
   */
  function msf_pbp_state_filter($stateKey, $col = 'e.state_key', $paramBase = 'state_key') {
    if ($stateKey === null || $stateKey === '') return array('', array());

    $mk5v5Clause = function() use ($col, $paramBase) {
      $p = ':' . $paramBase;
      return array(
        "({$col} = {$p} OR {$col} IS NULL OR {$col} = '')",
        array($p => '5v5')
      );
    };

    if (is_array($stateKey)) {
      $vals = array();
      $want5v5 = false;

      foreach ($stateKey as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        if (strcasecmp($v, '5v5') === 0) { $want5v5 = true; continue; }
        $vals[] = $v;
      }

      if (!$vals && $want5v5) {
        list($cl, $pa) = $mk5v5Clause();
        return array(" AND {$cl} ", $pa);
      }

      if (!$vals && !$want5v5) {
        return array('', array());
      }

      $ph = array();
      $params = array();
      foreach ($vals as $i => $v) {
        $k = ':' . $paramBase . $i;
        $ph[] = $k;
        $params[$k] = $v;
      }

      $inClause = "{$col} IN (" . implode(',', $ph) . ")";

      if ($want5v5) {
        list($cl5, $pa5) = $mk5v5Clause();
        $params = $params + $pa5;
        return array(" AND ({$inClause} OR {$cl5}) ", $params);
      }

      return array(" AND {$inClause} ", $params);
    }

    $v = trim((string)$stateKey);
    if ($v === '') return array('', array());

    if (strcasecmp($v, '5v5') === 0) {
      list($cl, $pa) = $mk5v5Clause();
      return array(" AND {$cl} ", $pa);
    }

    $p = ':' . $paramBase;
    return array(" AND {$col} = {$p} ", array($p => $v));
  }
}

/* ==========================================================
 *  Derived strength filter (EV/PP/SH)
 * ========================================================== */

if (!function_exists('msf_pbp_strength_filter_sql')) {
  /**
   * Derived strength filter (EV/PP/SH) relative to the player's side (oi.side).
   * Uses skater counts if present; else tries to parse e.state_key like '5v4'.
   *
   * NOTE: This is separate from state_key='5v5'. If you pass state_key, that's exact.
   */
  function msf_pbp_strength_filter_sql($strength, $oiAlias = 'oi', $eAlias = 'e') {
    $strength = strtoupper(trim((string)$strength));
    if ($strength !== 'EV' && $strength !== 'PP' && $strength !== 'SH') return '';

    $homeSk = "
      CASE
        WHEN {$eAlias}.state_key REGEXP '^[0-9]+v[0-9]+$'
          THEN CAST(SUBSTRING_INDEX({$eAlias}.state_key,'v',1) AS UNSIGNED)
        ELSE {$eAlias}.home_skaters
      END
    ";
    $awaySk = "
      CASE
        WHEN {$eAlias}.state_key REGEXP '^[0-9]+v[0-9]+$'
          THEN CAST(SUBSTRING_INDEX({$eAlias}.state_key,'v',-1) AS UNSIGNED)
        ELSE {$eAlias}.away_skaters
      END
    ";

    if ($strength === 'EV') {
      return " AND {$homeSk} = {$awaySk} ";
    }
    if ($strength === 'PP') {
      return " AND (
        ({$oiAlias}.side='HOME' AND {$homeSk} > {$awaySk}) OR
        ({$oiAlias}.side='AWAY' AND {$awaySk} > {$homeSk})
      ) ";
    }
    // SH
    return " AND (
      ({$oiAlias}.side='HOME' AND {$homeSk} < {$awaySk}) OR
      ({$oiAlias}.side='AWAY' AND {$awaySk} < {$homeSk})
    ) ";
  }
}

/* ==========================================================
 *  Event-type expressions (booleans)
 * ========================================================== */

if (!function_exists('msf_pbp_is_corsi_expr')) {
  // Corsi = all shot attempts (SHOT/GOAL in your storage)
  function msf_pbp_is_corsi_expr() {
    return "(e.event_type IN ('SHOT','GOAL'))";
  }
}

if (!function_exists('msf_pbp_is_fenwick_expr')) {
  // Fenwick = unblocked attempts
  function msf_pbp_is_fenwick_expr() {
    return "(e.event_type IN ('SHOT','GOAL') AND (e.is_blocked IS NULL OR e.is_blocked = 0))";
  }
}

if (!function_exists('msf_pbp_is_shot_on_goal_expr')) {
  // Shots-on-goal = goals + shots marked is_on_goal=1
  function msf_pbp_is_shot_on_goal_expr() {
    return "(
      e.event_type='GOAL'
      OR (e.event_type='SHOT' AND e.is_on_goal = 1)
    )";
  }
}

if (!function_exists('msf_pbp_is_goal_expr')) {
  function msf_pbp_is_goal_expr() {
    return "(e.event_type='GOAL')";
  }
}

/* ==========================================================
 *  Coordinate → distance/angle expressions (feet) [DB-only]
 * ========================================================== */

if (!function_exists('msf_pbp_sql_x_clamped')) {
  function msf_pbp_sql_x_clamped() {
    $xMax = (float)MSF_RINK_X_MAX;
    return "LEAST($xMax, GREATEST(0, COALESCE(e.x_raw,0)))";
  }
}

if (!function_exists('msf_pbp_sql_y_clamped')) {
  function msf_pbp_sql_y_clamped() {
    $yMax = (float)MSF_RINK_Y_MAX;
    return "LEAST($yMax, GREATEST(0, COALESCE(e.y_raw,0)))";
  }
}

if (!function_exists('msf_pbp_sql_dx_ft')) {
  function msf_pbp_sql_dx_ft() {
    $xMax   = (float)MSF_RINK_X_MAX;
    $xScale = (float)MSF_RINK_LENGTH_FT / $xMax; // ft per x-unit
    $x = msf_pbp_sql_x_clamped();
    return "(LEAST($x, $xMax - $x) * $xScale)";
  }
}

if (!function_exists('msf_pbp_sql_dy_ft')) {
  function msf_pbp_sql_dy_ft() {
    $yMax    = (float)MSF_RINK_Y_MAX;
    $yCenter = $yMax / 2.0;
    $yScale  = (float)MSF_RINK_WIDTH_FT / $yMax; // ft per y-unit
    $y = msf_pbp_sql_y_clamped();
    return "(ABS($y - $yCenter) * $yScale)";
  }
}

if (!function_exists('msf_pbp_sql_shot_dist_ft')) {
  function msf_pbp_sql_shot_dist_ft() {
    $dx = msf_pbp_sql_dx_ft();
    $dy = msf_pbp_sql_dy_ft();
    return "(SQRT(POW($dx,2) + POW($dy,2)))";
  }
}

if (!function_exists('msf_pbp_sql_shot_angle_deg')) {
  function msf_pbp_sql_shot_angle_deg() {
    $dx = msf_pbp_sql_dx_ft();
    $dy = msf_pbp_sql_dy_ft();
    return "(DEGREES(ATAN2($dy, $dx)))";
  }
}

/* ==========================================================
 *  Scoring Chances (approx) expressions
 * ========================================================== */

if (!function_exists('msf_pbp_is_sc_expr')) {
  function msf_pbp_is_sc_expr($includeBlocked = true) {
    $attempt = $includeBlocked ? msf_pbp_is_corsi_expr() : msf_pbp_is_fenwick_expr();
    $dist = msf_pbp_sql_shot_dist_ft();
    return "($attempt AND $dist <= " . (float)MSF_SC_DIST_FT . ")";
  }
}

if (!function_exists('msf_pbp_is_hd_expr')) {
  function msf_pbp_is_hd_expr($includeBlocked = true) {
    $attempt = $includeBlocked ? msf_pbp_is_corsi_expr() : msf_pbp_is_fenwick_expr();
    $dist = msf_pbp_sql_shot_dist_ft();
    return "($attempt AND $dist <= " . (float)MSF_HD_DIST_FT . ")";
  }
}

if (!function_exists('msf_pbp_is_md_expr')) {
  function msf_pbp_is_md_expr($includeBlocked = true) {
    $attempt = $includeBlocked ? msf_pbp_is_corsi_expr() : msf_pbp_is_fenwick_expr();
    $dist = msf_pbp_sql_shot_dist_ft();
    $hd = (float)MSF_HD_DIST_FT;
    $md = (float)MSF_MD_DIST_FT;
    return "($attempt AND $dist > $hd AND $dist <= $md)";
  }
}

if (!function_exists('msf_pbp_is_ld_expr')) {
  function msf_pbp_is_ld_expr($includeBlocked = true) {
    $attempt = $includeBlocked ? msf_pbp_is_corsi_expr() : msf_pbp_is_fenwick_expr();
    $dist = msf_pbp_sql_shot_dist_ft();
    $md = (float)MSF_MD_DIST_FT;
    $sc = (float)MSF_SC_DIST_FT;
    return "($attempt AND $dist > $md AND $dist <= $sc)";
  }
}

/* ==========================================================
 *  xG helpers
 * ========================================================== */

if (!function_exists('msf_pbp_detect_xg_column')) {
  function msf_pbp_detect_xg_column(PDO $pdo) {
    static $did = false;
    static $cached = null;
    if ($did) return $cached;

    $candidates = ['xg','xG','expected_goals','expectedGoals','exp_goals','shot_xg','xg_shot'];

    try {
      $sql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'msf_live_pbp_event'
          AND column_name IN (" . implode(',', array_fill(0, count($candidates), '?')) . ")
        LIMIT 1
      ";
      $st = $pdo->prepare($sql);
      $st->execute($candidates);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      $cached = $r ? (string)$r['column_name'] : null;
    } catch (Exception $e) {
      $cached = null;
    }

    $did = true;
    return $cached;
  }
}

if (!function_exists('msf_pbp_xg_event_expr')) {
  function msf_pbp_xg_event_expr($includeBlocked = false) {
    $base = "(e.event_type IN ('SHOT','GOAL'))";
    if ($includeBlocked) return $base;
    return $base . " AND (e.is_blocked IS NULL OR e.is_blocked = 0)";
  }
}

if (!function_exists('msf_pbp_xg_expr_sql')) {
  /**
   * If a feed xG column exists but is blank/0 on many rows,
   * fall back per-event to heuristic (prevents all-zero xG).
   */
  function msf_pbp_xg_expr_sql(PDO $pdo) {
    $col = function_exists('msf_pbp_detect_xg_column') ? msf_pbp_detect_xg_column($pdo) : null;

    $dist = function_exists('msf_pbp_sql_shot_dist_ft') ? msf_pbp_sql_shot_dist_ft() : "0";
    $ang  = function_exists('msf_pbp_sql_shot_angle_deg') ? msf_pbp_sql_shot_angle_deg() : "0";

    // Heuristic coefficients tuned for FEET + DEGREES
    $b0 = -1.90;
    $b1 = -0.025;   // distance (ft)
    $b2 = -0.008;   // angle (deg)
    $b3 =  0.00040; // interaction (ft*deg)

    $z    = "({$b0} + ({$b1}*{$dist}) + ({$b2}*{$ang}) + ({$b3}*{$dist}*{$ang}))";
    $heur = "LEAST(1, GREATEST(0, (1 / (1 + EXP(-{$z})))))";

    if ($col) {
      $feed = "CAST(COALESCE(NULLIF(e.`{$col}`,''), 0) AS DECIMAL(10,6))";
      return "(CASE WHEN {$feed} > 0 THEN {$feed} ELSE {$heur} END)";
    }

    return $heur;
  }
}

/* ==========================================================
 *  Team mapping expressions (for/against)
 * ========================================================== */

if (!function_exists('msf_pbp_for_team_expr')) {
  function msf_pbp_for_team_expr($oiAlias = 'oi', $eAlias = 'e', $gAlias = 'g') {
    return "(
      ({$oiAlias}.side='HOME' AND {$eAlias}.team_abbr = {$gAlias}.home_team_abbr) OR
      ({$oiAlias}.side='AWAY' AND {$eAlias}.team_abbr = {$gAlias}.away_team_abbr)
    )";
  }
}

if (!function_exists('msf_pbp_vs_team_expr')) {
  function msf_pbp_vs_team_expr($oiAlias = 'oi', $eAlias = 'e', $gAlias = 'g') {
    return "(
      ({$oiAlias}.side='HOME' AND {$eAlias}.team_abbr = {$gAlias}.away_team_abbr) OR
      ({$oiAlias}.side='AWAY' AND {$eAlias}.team_abbr = {$gAlias}.home_team_abbr)
    )";
  }
}

/* ==========================================================
 *  Derived stats (DIFF helpers)
 * ========================================================== */

if (!function_exists('msf_pbp_apply_onice_derived')) {
  function msf_pbp_apply_onice_derived(array $row) {
    $pairs = [
      ['CF','CA','CDIFF'],
      ['FF','FA','FDIFF'],
      ['SF','SA','SDIFF'],
      ['GF','GA','GDIFF'],
      ['SCF','SCA','SCDIFF'],
      ['HDCF','HDCA','HCDIFF'],
      ['MDCF','MDCA','MCDIFF'],
      ['LDCF','LDCA','LCDIFF'],
    ];

    foreach ($pairs as $p) {
      list($f,$a,$d) = $p;
      if (array_key_exists($f, $row) || array_key_exists($a, $row)) {
        $fv = msf_as_float($row[$f] ?? 0, 0.0);
        $av = msf_as_float($row[$a] ?? 0, 0.0);
        $row[$d] = $fv - $av;
      }
    }

    if (array_key_exists('xGF', $row) || array_key_exists('xGA', $row)) {
      $xgf = msf_as_float($row['xGF'] ?? 0, 0.0);
      $xga = msf_as_float($row['xGA'] ?? 0, 0.0);
      $row['xGDIFF'] = $xgf - $xga;
    }

    return $row;
  }
}

/* ==========================================================
 *  Rates
 * ========================================================== */

if (!function_exists('msf_pbp_rate_per60')) {
  function msf_pbp_rate_per60($count, $toiSeconds) {
    $toi = (int)$toiSeconds;
    if ($toi <= 0) return null;
    return round(((float)$count) * 3600.0 / $toi, 2);
  }
}

if (!function_exists('msf_pbp_apply_onice_rates')) {
  function msf_pbp_apply_onice_rates(array $row, $toiSeconds) {
    $toi = (int)$toiSeconds;
    $row['TOI_seconds'] = $toi;

    $row = msf_pbp_apply_onice_derived($row);

    $countKeys = ['CF','CA','FF','FA','SF','SA','GF','GA','SCF','SCA','HDCF','HDCA','MDCF','MDCA','LDCF','LDCA'];
    foreach ($countKeys as $k) {
      if (array_key_exists($k, $row)) {
        $row[$k . '_60'] = msf_pbp_rate_per60((float)$row[$k], $toi);
      }
    }

    $floatKeys = ['xGF','xGA'];
    foreach ($floatKeys as $k) {
      if (array_key_exists($k, $row)) {
        $row[$k . '_60'] = msf_pbp_rate_per60((float)$row[$k], $toi);
      }
    }

    $diffKeys = ['CDIFF','FDIFF','SDIFF','GDIFF','SCDIFF','HCDIFF','MCDIFF','LCDIFF','xGDIFF'];
    foreach ($diffKeys as $k) {
      if (array_key_exists($k, $row)) {
        $row[$k . '_60'] = msf_pbp_rate_per60((float)$row[$k], $toi);
      }
    }

    return $row;
  }
}

if (!function_exists('msf_pbp_apply_individual_rates')) {
  function msf_pbp_apply_individual_rates(array $row, $toiSeconds) {
    $toi = (int)$toiSeconds;
    $row['TOI_seconds'] = $toi;

    foreach (['iCF','iFF','iSF','iG','iSC','iHDC','iMDC','iLDC'] as $k) {
      if (array_key_exists($k, $row)) {
        $row[$k . '_60'] = msf_pbp_rate_per60((float)$row[$k], $toi);
      }
    }
    foreach (['ixG'] as $k) {
      if (array_key_exists($k, $row)) {
        $row[$k . '_60'] = msf_pbp_rate_per60((float)$row[$k], $toi);
      }
    }

    return $row;
  }
}

/* ==========================================================
 *  Core: Player on-ice summary
 * ========================================================== */

if (!function_exists('msf_pbp_get_player_onice_summary')) {
  function msf_pbp_get_player_onice_summary(PDO $pdo, $gameId, $playerId, $opts = []) {
    $gid = (int)$gameId;
    $pid = (int)$playerId;

    $stateKey  = $opts['state_key'] ?? null;
    $strength  = $opts['strength'] ?? null;

    $includeBlockedSC = array_key_exists('include_blocked_sc', $opts) ? (bool)$opts['include_blocked_sc'] : true;
    $includeBlockedXG = array_key_exists('include_blocked_xg', $opts) ? (bool)$opts['include_blocked_xg'] : false;

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');
    $strengthSql = $strength ? msf_pbp_strength_filter_sql($strength, 'oi', 'e') : '';

    $isCF = msf_pbp_is_corsi_expr();
    $isFF = msf_pbp_is_fenwick_expr();
    $isSF = msf_pbp_is_shot_on_goal_expr();
    $isGF = msf_pbp_is_goal_expr();

    $scExpr  = msf_pbp_is_sc_expr($includeBlockedSC);
    $hdExpr  = msf_pbp_is_hd_expr($includeBlockedSC);
    $mdExpr  = msf_pbp_is_md_expr($includeBlockedSC);
    $ldExpr  = msf_pbp_is_ld_expr($includeBlockedSC);

    $xgEvent = msf_pbp_xg_event_expr($includeBlockedXG);
    $xgExpr  = msf_pbp_xg_expr_sql($pdo);

    $forTeam = msf_pbp_for_team_expr('oi', 'e', 'g');
    $vsTeam  = msf_pbp_vs_team_expr('oi', 'e', 'g');

    $sql = "
      SELECT
        :pid AS player_id,

        SUM(CASE WHEN {$isCF} AND {$forTeam} THEN 1 ELSE 0 END) AS CF,
        SUM(CASE WHEN {$isCF} AND {$vsTeam}  THEN 1 ELSE 0 END) AS CA,

        SUM(CASE WHEN {$isFF} AND {$forTeam} THEN 1 ELSE 0 END) AS FF,
        SUM(CASE WHEN {$isFF} AND {$vsTeam}  THEN 1 ELSE 0 END) AS FA,

        SUM(CASE WHEN {$isSF} AND {$forTeam} THEN 1 ELSE 0 END) AS SF,
        SUM(CASE WHEN {$isSF} AND {$vsTeam}  THEN 1 ELSE 0 END) AS SA,

        SUM(CASE WHEN {$isGF} AND {$forTeam} THEN 1 ELSE 0 END) AS GF,
        SUM(CASE WHEN {$isGF} AND {$vsTeam}  THEN 1 ELSE 0 END) AS GA,

        ROUND(SUM(CASE WHEN {$xgEvent} AND {$forTeam} THEN {$xgExpr} ELSE 0 END), 3) AS xGF,
        ROUND(SUM(CASE WHEN {$xgEvent} AND {$vsTeam}  THEN {$xgExpr} ELSE 0 END), 3) AS xGA,

        SUM(CASE WHEN {$scExpr} AND {$forTeam} THEN 1 ELSE 0 END) AS SCF,
        SUM(CASE WHEN {$scExpr} AND {$vsTeam}  THEN 1 ELSE 0 END) AS SCA,

        SUM(CASE WHEN {$hdExpr} AND {$forTeam} THEN 1 ELSE 0 END) AS HDCF,
        SUM(CASE WHEN {$hdExpr} AND {$vsTeam}  THEN 1 ELSE 0 END) AS HDCA,

        SUM(CASE WHEN {$mdExpr} AND {$forTeam} THEN 1 ELSE 0 END) AS MDCF,
        SUM(CASE WHEN {$mdExpr} AND {$vsTeam}  THEN 1 ELSE 0 END) AS MDCA,

        SUM(CASE WHEN {$ldExpr} AND {$forTeam} THEN 1 ELSE 0 END) AS LDCF,
        SUM(CASE WHEN {$ldExpr} AND {$vsTeam}  THEN 1 ELSE 0 END) AS LDCA

      FROM msf_live_pbp_on_ice oi
      JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      JOIN msf_live_game g      ON g.game_id  = e.game_id
      WHERE e.game_id = :gid
        AND oi.player_id = :pid
        AND oi.is_goalie = 0
        {$stateSql}
        {$strengthSql}
    ";

    $params = [':gid' => $gid, ':pid' => $pid] + $stateParams;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    $out = [
      'player_id' => $pid,
      'state_key' => $stateKey,
      'strength'  => $strength ? strtoupper(trim((string)$strength)) : null,
    ];

    $cf = (int)($r['CF'] ?? 0); $ca = (int)($r['CA'] ?? 0);
    $ff = (int)($r['FF'] ?? 0); $fa = (int)($r['FA'] ?? 0);
    $sf = (int)($r['SF'] ?? 0); $sa = (int)($r['SA'] ?? 0);
    $gf = (int)($r['GF'] ?? 0); $ga = (int)($r['GA'] ?? 0);

    $xgf = (float)($r['xGF'] ?? 0.0);
    $xga = (float)($r['xGA'] ?? 0.0);

    $scf  = (int)($r['SCF'] ?? 0);  $sca  = (int)($r['SCA'] ?? 0);
    $hdcf = (int)($r['HDCF'] ?? 0); $hdca = (int)($r['HDCA'] ?? 0);
    $mdcf = (int)($r['MDCF'] ?? 0); $mdca = (int)($r['MDCA'] ?? 0);
    $ldcf = (int)($r['LDCF'] ?? 0); $ldca = (int)($r['LDCA'] ?? 0);

    $out['CF'] = $cf; $out['CA'] = $ca; $out['CF_pct'] = ($cf + $ca) ? round(100 * $cf / ($cf + $ca), 1) : null;
    $out['FF'] = $ff; $out['FA'] = $fa; $out['FF_pct'] = ($ff + $fa) ? round(100 * $ff / ($ff + $fa), 1) : null;
    $out['SF'] = $sf; $out['SA'] = $sa; $out['SF_pct'] = ($sf + $sa) ? round(100 * $sf / ($sf + $sa), 1) : null;
    $out['GF'] = $gf; $out['GA'] = $ga; $out['GF_pct'] = ($gf + $ga) ? round(100 * $gf / ($gf + $ga), 1) : null;

    $out['xGF'] = round($xgf, 3);
    $out['xGA'] = round($xga, 3);
    $out['xGF_pct'] = ($xgf + $xga) > 0 ? round(100 * $xgf / ($xgf + $xga), 1) : null;

    $out['SCF'] = $scf; $out['SCA'] = $sca; $out['SCF_pct'] = ($scf + $sca) ? round(100 * $scf / ($scf + $sca), 1) : null;
    $out['HDCF'] = $hdcf; $out['HDCA'] = $hdca; $out['HDCF_pct'] = ($hdcf + $hdca) ? round(100 * $hdcf / ($hdcf + $hdca), 1) : null;
    $out['MDCF'] = $mdcf; $out['MDCA'] = $mdca; $out['MDCF_pct'] = ($mdcf + $mdca) ? round(100 * $mdcf / ($mdcf + $mdca), 1) : null;
    $out['LDCF'] = $ldcf; $out['LDCA'] = $ldca; $out['LDCF_pct'] = ($ldcf + $ldca) ? round(100 * $ldcf / ($ldcf + $ldca), 1) : null;

    $out = msf_pbp_apply_onice_derived($out);

    $shPct = ($sf > 0) ? (100.0 * $gf / $sf) : null;
    $svPct = ($sa > 0) ? (100.0 * (1.0 - ($ga / $sa))) : null;
    $out['SH_pct'] = ($shPct !== null) ? round($shPct, 1) : null;
    $out['SV_pct'] = ($svPct !== null) ? round($svPct, 1) : null;
    $out['PDO']    = ($shPct !== null && $svPct !== null) ? round(($shPct + $svPct), 1) : null;

    return $out;
  }
}

/* ==========================================================
 *  Individual (player-as-shooter/scorer) summary
 * ========================================================== */

if (!function_exists('msf_pbp_get_player_individual_summary')) {
  function msf_pbp_get_player_individual_summary(PDO $pdo, $gameId, $playerId, $opts = []) {
    $gid = (int)$gameId;
    $pid = (int)$playerId;

    $stateKey = $opts['state_key'] ?? null;
    $includeBlockedSC = array_key_exists('include_blocked_sc', $opts) ? (bool)$opts['include_blocked_sc'] : true;
    $includeBlockedXG = array_key_exists('include_blocked_xg', $opts) ? (bool)$opts['include_blocked_xg'] : false;

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');

    $isCF = msf_pbp_is_corsi_expr();
    $isFF = msf_pbp_is_fenwick_expr();
    $isSF = msf_pbp_is_shot_on_goal_expr();
    $isG  = msf_pbp_is_goal_expr();

    $scExpr  = msf_pbp_is_sc_expr($includeBlockedSC);
    $hdExpr  = msf_pbp_is_hd_expr($includeBlockedSC);
    $mdExpr  = msf_pbp_is_md_expr($includeBlockedSC);
    $ldExpr  = msf_pbp_is_ld_expr($includeBlockedSC);

    $xgEvent = msf_pbp_xg_event_expr($includeBlockedXG);
    $xgExpr  = msf_pbp_xg_expr_sql($pdo);

    $sql = "
      SELECT
        :pid AS player_id,

        SUM(CASE WHEN {$isCF} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iCF,
        SUM(CASE WHEN {$isFF} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iFF,
        SUM(CASE WHEN {$isSF} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iSF,

        SUM(CASE WHEN {$isG}  AND (e.scorer_id = :pid OR e.shooter_id = :pid) THEN 1 ELSE 0 END) AS iG,

        ROUND(SUM(CASE WHEN {$xgEvent} AND e.shooter_id = :pid THEN {$xgExpr} ELSE 0 END), 3) AS ixG,

        SUM(CASE WHEN {$scExpr} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iSC,
        SUM(CASE WHEN {$hdExpr} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iHDC,
        SUM(CASE WHEN {$mdExpr} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iMDC,
        SUM(CASE WHEN {$ldExpr} AND e.shooter_id = :pid THEN 1 ELSE 0 END) AS iLDC

      FROM msf_live_pbp_event e
      WHERE e.game_id = :gid
        {$stateSql}
    ";

    $params = [':gid' => $gid, ':pid' => $pid] + $stateParams;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    return [
      'player_id' => $pid,
      'state_key' => $stateKey,
      'iCF' => (int)($r['iCF'] ?? 0),
      'iFF' => (int)($r['iFF'] ?? 0),
      'iSF' => (int)($r['iSF'] ?? 0),
      'iG'  => (int)($r['iG']  ?? 0),
      'ixG' => round((float)($r['ixG'] ?? 0.0), 3),
      'iSC' => (int)($r['iSC'] ?? 0),
      'iHDC'=> (int)($r['iHDC'] ?? 0),
      'iMDC'=> (int)($r['iMDC'] ?? 0),
      'iLDC'=> (int)($r['iLDC'] ?? 0),
    ];
  }
}

/* ==========================================================
 *  Minimal Corsi helper (used by GAR-lite)
 * ========================================================== */

if (!function_exists('msf_pbp_get_player_corsi')) {
  function msf_pbp_get_player_corsi(PDO $pdo, $gameId, $playerId, $stateKey = '5v5', $fenwick = false) {
    $gid = (int)$gameId;
    $pid = (int)$playerId;

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');
    $isAttempt = $fenwick ? msf_pbp_is_fenwick_expr() : msf_pbp_is_corsi_expr();

    $forTeam = msf_pbp_for_team_expr('oi', 'e', 'g');
    $vsTeam  = msf_pbp_vs_team_expr('oi', 'e', 'g');

    $sql = "
      SELECT
        SUM(CASE WHEN {$isAttempt} AND {$forTeam} THEN 1 ELSE 0 END) AS CF,
        SUM(CASE WHEN {$isAttempt} AND {$vsTeam}  THEN 1 ELSE 0 END) AS CA
      FROM msf_live_pbp_on_ice oi
      JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      JOIN msf_live_game g      ON g.game_id  = e.game_id
      WHERE e.game_id = :gid
        AND oi.player_id = :pid
        AND oi.is_goalie = 0
        {$stateSql}
    ";

    $params = [':gid' => $gid, ':pid' => $pid] + $stateParams;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);

    $cf = (int)($r['CF'] ?? 0);
    $ca = (int)($r['CA'] ?? 0);

    return [
      'player_id' => $pid,
      'state_key' => $stateKey,
      'fenwick'   => (bool)$fenwick,
      'CF' => $cf,
      'CA' => $ca,
      'CF_pct' => ($cf + $ca) ? round(100.0 * $cf / ($cf + $ca), 1) : null,
      'CDIFF' => $cf - $ca,
    ];
  }
}

/* ==========================================================
 *  Penalties (proxy) used by GAR-lite
 * ========================================================== */

if (!function_exists('msf_pbp_get_player_penalty_diff')) {
  function msf_pbp_get_player_penalty_diff(PDO $pdo, $gameId, $playerId, $stateKey = null) {
    $gid = (int)$gameId;
    $pid = (int)$playerId;

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');

    $sqlTaken = "
      SELECT
        COUNT(*) AS taken,
        COALESCE(SUM(e.penalty_minutes),0) AS pim_taken
      FROM msf_live_pbp_actor a
      JOIN msf_live_pbp_event e ON e.event_id = a.event_id
      WHERE e.game_id = :gid
        AND e.event_type = 'PENALTY'
        AND a.role = 'PENALIZED'
        AND a.player_id = :pid
        {$stateSql}
    ";
    $st = $pdo->prepare($sqlTaken);
    $params = [':gid' => $gid, ':pid' => $pid] + $stateParams;
    $st->execute($params);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $taken = $t ? (int)($t['taken'] ?? 0) : 0;
    $pimTaken = $t ? (int)($t['pim_taken'] ?? 0) : 0;

    $sqlDrawn = "
      SELECT
        SUM(CASE WHEN e.event_type='PENALTY' THEN 1 ELSE 0 END) AS drawn
      FROM msf_live_pbp_on_ice oi
      JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      JOIN msf_live_game g ON g.game_id = e.game_id
      WHERE e.game_id = :gid
        AND oi.player_id = :pid
        AND oi.is_goalie = 0
        AND (
          (oi.side='HOME' AND e.team_abbr = g.away_team_abbr) OR
          (oi.side='AWAY' AND e.team_abbr = g.home_team_abbr)
        )
        {$stateSql}
    ";
    $st2 = $pdo->prepare($sqlDrawn);
    $st2->execute($params);
    $d = $st2->fetch(PDO::FETCH_ASSOC);
    $drawn = $d ? (int)($d['drawn'] ?? 0) : 0;

    return [
      'taken' => $taken,
      'drawn' => $drawn,
      'diff'  => ($drawn - $taken),
      'pim_taken' => $pimTaken,
    ];
  }
}

/* ==========================================================
 *  GAR-lite / WAR-lite
 * ========================================================== */

if (!function_exists('msf_pbp_get_player_gar_lite')) {
  function msf_pbp_get_player_gar_lite(PDO $pdo, $gameId, $playerId, $stateKey = '5v5', $fenwick = false, $cfg = array()) {

    // NEW-STYLE: 4th arg is an options array
    if (is_array($stateKey)) {
      $opts = $stateKey;
      $stateKey = $opts['state_key'] ?? '5v5';
      $fenwick  = isset($opts['fenwick']) ? (bool)$opts['fenwick'] : false;
      $cfg      = $opts;
    }

    if (!is_array($cfg)) $cfg = array();

    $goalsPerPenalty = isset($cfg['goals_per_penalty']) ? (float)$cfg['goals_per_penalty'] : 0.15;
    $goalsPerCorsi   = isset($cfg['goals_per_corsi'])   ? (float)$cfg['goals_per_corsi']   : 0.01;
    $includeCorsi    = array_key_exists('include_corsi', $cfg) ? (bool)$cfg['include_corsi'] : true;

    $c = msf_pbp_get_player_corsi($pdo, $gameId, $playerId, $stateKey, $fenwick);
    $cf = (int)($c['CF'] ?? 0);
    $ca = (int)($c['CA'] ?? 0);
    $cdiff = $cf - $ca;

    $on = msf_pbp_get_player_onice_summary($pdo, $gameId, $playerId, ['state_key' => $stateKey]);
    $gf = (int)($on['GF'] ?? 0);
    $ga = (int)($on['GA'] ?? 0);
    $gdiff = $gf - $ga;

    $p = msf_pbp_get_player_penalty_diff($pdo, $gameId, $playerId, $stateKey);
    $penDiff = (int)($p['diff'] ?? 0);

    $garPen   = (float)$penDiff * $goalsPerPenalty;
    $garCorsi = $includeCorsi ? ((float)$cdiff * $goalsPerCorsi) : 0.0;
    $garGoals = (float)$gdiff;

    return array(
      'player_id' => (int)$playerId,
      'state_key' => $stateKey,
      'CF' => $cf, 'CA' => $ca, 'CDIFF' => $cdiff,
      'GF' => $gf, 'GA' => $ga, 'GDIFF' => $gdiff,
      'pen_taken' => (int)($p['taken'] ?? 0),
      'pen_drawn' => (int)($p['drawn'] ?? 0),
      'pen_diff'  => $penDiff,
      'gar_pen'   => (float)$garPen,
      'gar_corsi' => (float)$garCorsi,
      'gar_goals' => (float)$garGoals,
      'gar_total' => (float)($garPen + $garCorsi + $garGoals),
    );
  }
}

if (!function_exists('msf_pbp_gar_to_war')) {
  function msf_pbp_gar_to_war($gar, $goalsPerWin = 6.0) {
    $gpw = (float)$goalsPerWin;
    if ($gpw <= 0) return null;
    return (float)$gar / $gpw;
  }
}

/* ==========================================================
 *  Batch: all skaters Corsi/Fenwick (CF/CA + CF%)
 * ========================================================== */

if (!function_exists('msf_pbp_get_all_skaters_corsi')) {
  /**
   * Returns per-skater on-ice CF/CA (or FF/FA if $fenwick=true) at requested state.
   *
   * Signature matches your thread_adv_stats.php usage:
   *   msf_pbp_get_all_skaters_corsi($pdo, $gid, '5v5', $fenwick, $minToiSeconds)
   *
   * NOTE:
   * - We do NOT rate-scale here; this is map-style (counts + pct).
   * - If you want a TOI floor, pass $minToiSeconds > 0 and we’ll filter using gamelogs TOI.
   */
  function msf_pbp_get_all_skaters_corsi(PDO $pdo, $gameId, $stateKey = '5v5', $fenwick = false, $minToiSeconds = 0) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    if (!function_exists('msf_pbp_state_filter')) return array();
    if (!function_exists('msf_pbp_for_team_expr') || !function_exists('msf_pbp_vs_team_expr')) return array();

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');

    $isAttempt = $fenwick ? msf_pbp_is_fenwick_expr() : msf_pbp_is_corsi_expr();

    $forTeam = msf_pbp_for_team_expr('oi', 'e', 'g');
    $vsTeam  = msf_pbp_vs_team_expr('oi', 'e', 'g');

    $sql = "
      SELECT
        oi.player_id AS player_id,
        SUM(CASE WHEN {$isAttempt} AND {$forTeam} THEN 1 ELSE 0 END) AS CF,
        SUM(CASE WHEN {$isAttempt} AND {$vsTeam}  THEN 1 ELSE 0 END) AS CA
      FROM msf_live_pbp_on_ice oi
      JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      JOIN msf_live_game g      ON g.game_id  = e.game_id
      WHERE e.game_id = :gid
        AND oi.is_goalie = 0
        {$stateSql}
      GROUP BY oi.player_id
    ";

    $params = array(':gid' => $gid) + $stateParams;

    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      return array();
    }

    if (!$rows) return array();

    // Optional TOI filter using gamelogs map (fast single query in helper)
    $toiMin = (int)$minToiSeconds;
    $toiMap = null;
    if ($toiMin > 0 && function_exists('msf_player_gamelogs_get_toi_map_seconds')) {
      $toiMap = msf_player_gamelogs_get_toi_map_seconds($pdo, $gid); // pid => ['toi_total','toi_ev',...]
    }

    $out = array();
    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;

      if ($toiMin > 0 && is_array($toiMap)) {
        $t = $toiMap[$pid] ?? null;
        $sec = 0;
        if (is_array($t)) {
          // if asking for 5v5 map, treat EV as the best proxy
          $sec = (int)($t['toi_ev'] ?? 0);
          if ($sec <= 0) $sec = (int)($t['toi_total'] ?? 0);
        }
        if ($sec < $toiMin) continue;
      }

      $cf = (int)($r['CF'] ?? 0);
      $ca = (int)($r['CA'] ?? 0);
      $pct = ($cf + $ca) ? round(100.0 * $cf / ($cf + $ca), 1) : null;

      $out[] = array(
        'player_id' => $pid,
        'CF' => $cf,
        'CA' => $ca,
        'CF_pct' => $pct,
      );
    }

    return $out;
  }
}

/* ==========================================================
 *  Batch: all skaters xGF/xGA (cached by DB)
 * ========================================================== */

if (!function_exists('msf_pbp_get_all_skaters_xgf')) {
  /**
   * Returns per-skater on-ice xGF/xGA at requested state.
   * Uses state filter where '5v5' includes NULL/''.
   * Optional min_xg_total filter (keep it tiny for quadrant).
   */
  function msf_pbp_get_all_skaters_xgf(PDO $pdo, $gameId, $stateKey = '5v5', $includeBlockedXG = false, $minXgTotal = 0.01) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    if (!function_exists('msf_pbp_state_filter')) return array();
    if (!function_exists('msf_pbp_for_team_expr') || !function_exists('msf_pbp_vs_team_expr')) return array();
    if (!function_exists('msf_pbp_xg_event_expr') || !function_exists('msf_pbp_xg_expr_sql')) return array();

    list($stateSql, $stateParams) = msf_pbp_state_filter($stateKey, 'e.state_key', 'state_key');

    $xgEvent = msf_pbp_xg_event_expr((bool)$includeBlockedXG);
    $xgExpr  = msf_pbp_xg_expr_sql($pdo);

    $forTeam = msf_pbp_for_team_expr('oi', 'e', 'g');
    $vsTeam  = msf_pbp_vs_team_expr('oi', 'e', 'g');

    $sql = "
      SELECT
        oi.player_id AS player_id,
        ROUND(SUM(CASE WHEN {$xgEvent} AND {$forTeam} THEN {$xgExpr} ELSE 0 END), 3) AS xGF,
        ROUND(SUM(CASE WHEN {$xgEvent} AND {$vsTeam}  THEN {$xgExpr} ELSE 0 END), 3) AS xGA
      FROM msf_live_pbp_on_ice oi
      JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      JOIN msf_live_game g      ON g.game_id  = e.game_id
      WHERE e.game_id = :gid
        AND oi.is_goalie = 0
        {$stateSql}
      GROUP BY oi.player_id
    ";

    $params = array(':gid' => $gid) + $stateParams;

    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
      return array();
    }

    if (!$rows) return array();

    $out = array();
    $min = is_numeric($minXgTotal) ? (float)$minXgTotal : 0.0;

    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;

      $xgf = isset($r['xGF']) ? (float)$r['xGF'] : 0.0;
      $xga = isset($r['xGA']) ? (float)$r['xGA'] : 0.0;
      $tot = $xgf + $xga;

      if ($min > 0 && $tot < $min) continue;

      $pct = ($tot > 0) ? round(100.0 * $xgf / $tot, 1) : null;

      $out[] = array(
        'player_id' => $pid,
        'xGF'       => round($xgf, 3),
        'xGA'       => round($xga, 3),
        'xG_total'  => round($tot, 3),
        'xGF_pct'   => $pct,
      );
    }

    return $out;
  }
}
