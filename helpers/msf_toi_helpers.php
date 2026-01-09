<?php
// public/helpers/msf_toi_helpers.php
//
// TOI helpers used by boxscore + PBP modules.
// Source: msf_player_gamelogs table.
// PHP 7+ friendly.

if (!defined('MSF_PLAYER_GAMELOGS_TABLE')) define('MSF_PLAYER_GAMELOGS_TABLE', 'msf_player_gamelogs');

if (!defined('MSF_TOI_COL_TOTAL')) define('MSF_TOI_COL_TOTAL', 'total_toi');
if (!defined('MSF_TOI_COL_EV'))    define('MSF_TOI_COL_EV',    'ev_toi'); // treated as even-strength bucket
if (!defined('MSF_TOI_COL_PP'))    define('MSF_TOI_COL_PP',    'pp_toi');
if (!defined('MSF_TOI_COL_SH'))    define('MSF_TOI_COL_SH',    'sh_toi');

/* ==========================================================
 * Small utils (only if not already defined elsewhere)
 * ========================================================== */
if (!function_exists('msf_as_int')) {
  function msf_as_int($v, $d = 0) { return is_numeric($v) ? (int)$v : (int)$d; }
}

/* ==========================================================
 * TOI: sanitize
 * ========================================================== */
if (!function_exists('msf_toi_sanitize')) {
  function msf_toi_sanitize($arr) {
    $tot = msf_as_int($arr['toi_total'] ?? 0, 0);
    $ev  = msf_as_int($arr['toi_ev'] ?? 0, 0);
    $pp  = msf_as_int($arr['toi_pp'] ?? 0, 0);
    $sh  = msf_as_int($arr['toi_sh'] ?? 0, 0);

    if ($tot < 0) $tot = 0;
    if ($ev < 0)  $ev = 0;
    if ($pp < 0)  $pp = 0;
    if ($sh < 0)  $sh = 0;

    $sum = $ev + $pp + $sh;

    // If total missing, rebuild from parts
    if ($tot <= 0 && $sum > 0) $tot = $sum;

    // If both exist, check if parts “roughly” match total
    $partsSane = false;
    if ($tot > 0 && $sum > 0) {
      $diff = abs($sum - $tot);
      $tol  = max(30, (int)round($tot * 0.10)); // allow up to 30s or 10%
      $partsSane = ($diff <= $tol);
    }

    return [
      'toi_total'  => $tot,
      'toi_ev'     => $ev,
      'toi_pp'     => $pp,
      'toi_sh'     => $sh,
      'parts_sane' => $partsSane,
    ];
  }
}

/* ==========================================================
 * TOI: fetch single player
 * ========================================================== */
if (!function_exists('msf_player_gamelogs_get_toi_seconds')) {
  function msf_player_gamelogs_get_toi_seconds(PDO $pdo, $msfGameId, $playerId) {
    static $cache = [];

    $gid = (int)$msfGameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return null;

    $key = $gid . ':' . $pid;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $tbl  = MSF_PLAYER_GAMELOGS_TABLE;
    $cTot = MSF_TOI_COL_TOTAL;
    $cEv  = MSF_TOI_COL_EV;
    $cPp  = MSF_TOI_COL_PP;
    $cSh  = MSF_TOI_COL_SH;

    // basic identifier safety (table/columns are constants but keep guard)
    foreach ([$tbl,$cTot,$cEv,$cPp,$cSh] as $ident) {
      if (!preg_match('/^[a-zA-Z0-9_]+$/', $ident)) {
        $cache[$key] = null;
        return null;
      }
    }

    try {
      $sql = "
        SELECT {$cTot} AS toi_total, {$cEv} AS toi_ev, {$cPp} AS toi_pp, {$cSh} AS toi_sh
        FROM {$tbl}
        WHERE msf_game_id = :gid AND player_id = :pid
        LIMIT 1
      ";
      $st = $pdo->prepare($sql);
      $st->execute([':gid' => $gid, ':pid' => $pid]);
      $r = $st->fetch(PDO::FETCH_ASSOC);

      if (!$r) {
        $cache[$key] = null;
        return null;
      }

      $cache[$key] = msf_toi_sanitize($r);
      return $cache[$key];

    } catch (Exception $e) {
      $cache[$key] = null;
      return null;
    }
  }
}

/* ==========================================================
 * TOI: fetch map (all players in game)
 * ========================================================== */
if (!function_exists('msf_player_gamelogs_get_toi_map_seconds')) {
  function msf_player_gamelogs_get_toi_map_seconds(PDO $pdo, $msfGameId) {
    static $cache = [];

    $gid = (int)$msfGameId;
    if ($gid <= 0) return [];

    if (array_key_exists($gid, $cache)) return $cache[$gid];

    $tbl  = MSF_PLAYER_GAMELOGS_TABLE;
    $cTot = MSF_TOI_COL_TOTAL;
    $cEv  = MSF_TOI_COL_EV;
    $cPp  = MSF_TOI_COL_PP;
    $cSh  = MSF_TOI_COL_SH;

    foreach ([$tbl,$cTot,$cEv,$cPp,$cSh] as $ident) {
      if (!preg_match('/^[a-zA-Z0-9_]+$/', $ident)) {
        $cache[$gid] = [];
        return [];
      }
    }

    try {
      $sql = "
        SELECT
          player_id,
          {$cTot} AS toi_total,
          {$cEv}  AS toi_ev,
          {$cPp}  AS toi_pp,
          {$cSh}  AS toi_sh
        FROM {$tbl}
        WHERE msf_game_id = :gid
      ";
      $st = $pdo->prepare($sql);
      $st->execute([':gid' => $gid]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);

      $map = [];
      foreach ($rows as $r) {
        $pid = (int)($r['player_id'] ?? 0);
        if ($pid <= 0) continue;
        $map[$pid] = msf_toi_sanitize($r);
      }

      $cache[$gid] = $map;
      return $map;

    } catch (Exception $e) {
      $cache[$gid] = [];
      return [];
    }
  }
}

/* ==========================================================
 * Back-compat wrappers used by boxscore callers
 * ========================================================== */
if (!function_exists('msf_boxscore_get_player_toi_seconds')) {
  function msf_boxscore_get_player_toi_seconds(PDO $pdo, $gameId, $playerId) {
    return msf_player_gamelogs_get_toi_seconds($pdo, $gameId, $playerId);
  }
}

if (!function_exists('msf_boxscore_pick_toi_for_filter')) {
  /**
   * Pick correct TOI bucket from sanitized TOI array.
   *
   * Backwards compatible with BOTH call styles:
   *   1) msf_boxscore_pick_toi_for_filter($arr, '5v5', 0)
   *   2) msf_boxscore_pick_toi_for_filter($arr, $stateKey, $strengthString)
   */
  function msf_boxscore_pick_toi_for_filter($arr, $filter = 'total', $strengthOrDefault = 0, $defaultMaybe = null) {
    if (!is_array($arr) || !$arr) {
      $d = ($defaultMaybe !== null) ? (int)$defaultMaybe : (int)$strengthOrDefault;
      return $d;
    }

    $strength = null;
    $default  = 0;

    if (is_string($strengthOrDefault) && $defaultMaybe === null) {
      $strength = strtoupper(trim($strengthOrDefault));
      $default  = 0;
    } else {
      $default = (int)$strengthOrDefault;
      if (is_string($defaultMaybe)) {
        $strength = strtoupper(trim($defaultMaybe));
      }
    }

    $map = [
      'total' => $arr['toi_total'] ?? $arr['timeOnIceSeconds'] ?? null,
      'ev'    => $arr['toi_ev']    ?? $arr['evenStrengthTimeOnIceSeconds'] ?? null,
      'pp'    => $arr['toi_pp']    ?? $arr['powerplayTimeOnIceSeconds'] ?? null,
      'sh'    => $arr['toi_sh']    ?? $arr['shorthandedTimeOnIceSeconds'] ?? null,
    ];

    $want = 'total';
    if ($strength === 'EV') $want = 'ev';
    elseif ($strength === 'PP') $want = 'pp';
    elseif ($strength === 'SH') $want = 'sh';
    else {
      $f = is_string($filter) ? strtolower(trim($filter)) : '';
      if ($f === '' || $f === 'total') $want = 'total';
      elseif ($f === '5v5' || $f === 'ev' || $f === 'even') $want = 'ev';
      elseif ($f === 'pp') $want = 'pp';
      elseif ($f === 'sh') $want = 'sh';
      elseif (preg_match('/^[0-9]+v[0-9]+$/', $f)) {
        list($a,$b) = explode('v', $f, 2);
        $want = ((int)$a === (int)$b) ? 'ev' : 'total';
      } else $want = 'total';
    }

    $pick = (int)($map[$want] ?? 0);

    // EV fallback if parts exist but ev is 0
    if ($want === 'ev' && $pick <= 0 && isset($map['total'], $map['pp'], $map['sh'])) {
      $calc = (int)$map['total'] - (int)$map['pp'] - (int)$map['sh'];
      if ($calc > 0 && $calc < (int)$map['total']) $pick = $calc;
    }

    // sanity caps
    if ($pick < 0) $pick = 0;
    if ($pick > 7200) $pick = 0;

    return $pick > 0 ? $pick : (int)$default;
  }
}
