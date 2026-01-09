<?php
//======================================
// File: public/helpers/thread_adv_stats_chips.php
// Description: Chip grading + benchmarks + chips HTML (+ debug logging).
//              Suppresses placeholder 0.00 chips until live PBP/on-ice data exists.
// PHP 7+ friendly.
//======================================

$__adv_dir = __DIR__;

// If chips are ever included standalone, pull required deps.
$__adv_deps = array(
  $__adv_dir . '/thread_adv_stats_utils.php',
  $__adv_dir . '/thread_adv_stats_goalies.php',
  $__adv_dir . '/thread_adv_stats_db_switch.php',
  $__adv_dir . '/thread_adv_stats_rates.php',
  $__adv_dir . '/thread_adv_stats_maps.php',
);

foreach ($__adv_deps as $__f) {
  if (is_file($__f)) require_once $__f;
}

// Hard fail in logs if still missing (prevents silent empty chips)
if (!function_exists('sjms_adv_use_db_fallback')) {
  error_log('[ADV] Missing dependency: sjms_adv_use_db_fallback() (did you include thread_adv_stats_db_switch.php?)');
}

/* ==========================================================
 * Debug helpers (logs to php error_log)
 * Enable via:
 *   - define('SJMS_ADV_DEBUG_CHIPS', true);  (recommended in config)
 *   - OR add ?adv_dbg=1 to URL (temporary)
 * ========================================================== */

if (!function_exists('sjms_adv_dbg_chips_enabled')) {
  function sjms_adv_dbg_chips_enabled() {
    if (defined('SJMS_ADV_DEBUG_CHIPS') && SJMS_ADV_DEBUG_CHIPS) return true;
    if (!empty($_GET['adv_dbg']) && (string)$_GET['adv_dbg'] === '1') return true;
    return false;
  }
}

if (!function_exists('sjms_adv_dbg_ctx')) {
  function sjms_adv_dbg_ctx(array $ctx) {
    // Keep logs readable + one-line.
    if (function_exists('json_encode')) {
      return json_encode($ctx, JSON_UNESCAPED_SLASHES);
    }
    return print_r($ctx, true);
  }
}

if (!function_exists('sjms_adv_log_once')) {
  function sjms_adv_log_once($key, $msg) {
    static $seen = array();
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    error_log($msg);
  }
}

if (!function_exists('sjms_adv_dbg_bool')) {
  function sjms_adv_dbg_bool($b) { return $b ? 'yes' : 'no'; }
}

/* ==========================================================
 * Live PBP readiness gates
 * Prevent "0.00" placeholder stats before any PBP/on-ice is recorded.
 * ========================================================== */

if (!function_exists('sjms_adv_game_pbp_ready')) {
  function sjms_adv_game_pbp_ready(PDO $pdo, $gameId) {
    static $cache = array(); // gid => 0/1
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    if (array_key_exists($gid, $cache)) return (bool)$cache[$gid];

    try {
      // Table name assumed from your stack: msf_live_pbp_event
      $st = $pdo->prepare("SELECT 1 FROM msf_live_pbp_event WHERE game_id = :gid LIMIT 1");
      $st->execute(array(':gid' => $gid));
      $cache[$gid] = $st->fetchColumn() ? 1 : 0;
    } catch (Exception $e) {
      $cache[$gid] = 0;
      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] pbp_ready ERROR gid=$gid " . $e->getMessage());
      }
    }
    return (bool)$cache[$gid];
  }
}

if (!function_exists('sjms_adv_player_onice_ready')) {
  function sjms_adv_player_onice_ready(PDO $pdo, $gameId, $playerId) {
    static $cache = array(); // "gid:pid" => 0/1
    $gid = (int)$gameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return false;

    $k = $gid . ':' . $pid;
    if (array_key_exists($k, $cache)) return (bool)$cache[$k];

    try {
      $st = $pdo->prepare("
        SELECT 1
        FROM msf_live_pbp_event e
        JOIN msf_live_pbp_on_ice oi
          ON oi.event_id = e.event_id
        WHERE e.game_id = :gid
          AND oi.player_id = :pid
          AND oi.is_goalie = 0
        LIMIT 1
      ");
      $st->execute(array(':gid' => $gid, ':pid' => $pid));
      $cache[$k] = $st->fetchColumn() ? 1 : 0;
    } catch (Exception $e) {
      $cache[$k] = 0;
      if (function_exists('sjms_adv_dbg_chips_enabled') && sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] onice_ready ERROR gid=$gid pid=$pid " . $e->getMessage());
      }
    }

    if (function_exists('sjms_adv_dbg_chips_enabled') && sjms_adv_dbg_chips_enabled()) {
      error_log("[ADVCHIP] onice_ready " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'ok'  => (int)$cache[$k],
      )));
    }

    return (bool)$cache[$k];
  }
}


/* ==========================================================
 * Graders
 * ========================================================== */

if (!function_exists('sjms_adv_grade_pct')) {
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
  function sjms_adv_grade_signed($v, $deadband = 0.05) {
    if ($v === null || $v === '' || !is_numeric($v)) return '';
    $x = (float)$v;
    if ($x >  $deadband) return 'is-good';
    if ($x < -$deadband) return 'is-bad';
    return 'is-neutral';
  }
}

/* ==========================================================
 * Benchmarks (per game)
 * ========================================================== */

if (!function_exists('sjms_adv_chip_benchmarks')) {
  function sjms_adv_chip_benchmarks(PDO $pdo, $gameId) {
    static $cache = array();
    $gid = (int)$gameId;
    if ($gid <= 0) return array();
    if (isset($cache[$gid])) return $cache[$gid];

    if (sjms_adv_dbg_chips_enabled()) {
      sjms_adv_log_once("bench_start:$gid", "[ADVCHIP] benchmarks start gid=$gid");
    }

    $goalieIds = function_exists('sjms_adv_goalie_ids_for_game')
      ? sjms_adv_goalie_ids_for_game($pdo, $gid)
      : array();

    $vals = array(
      'toi'   => array(),
      'xgf60' => array(),
      'xga60' => array(),
      'cf60'  => array(),
    );

    $useDb = (function_exists('sjms_adv_use_db_fallback') && sjms_adv_use_db_fallback($pdo, $gid, '5v5'));

    if ($useDb) {
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
      } catch (Exception $e) {
        if (sjms_adv_dbg_chips_enabled()) {
          error_log("[ADVCHIP] benchmarks db_fallback ERROR gid=$gid " . $e->getMessage());
        }
      }

      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] benchmarks db_fallback gid=$gid " . sjms_adv_dbg_ctx(array(
          'goalies_filtered' => is_array($goalieIds) ? count($goalieIds) : null,
          'counts' => array(
            'toi'   => count($vals['toi']),
            'xgf60' => count($vals['xgf60']),
            'xga60' => count($vals['xga60']),
            'cf60'  => count($vals['cf60']),
          ),
        )));
      }

      $mk = function(array $arr) {
        $arr = array_values(array_filter($arr, function($x){ return is_numeric($x); }));
        if (count($arr) < 6) return array();
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

    // Live-calculated benchmarks path
    if (!function_exists('msf_boxscore_get_player_toi_seconds')) {
      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] benchmarks SKIP gid=$gid reason=missing msf_boxscore_get_player_toi_seconds()");
      }
      $cache[$gid] = array();
      return $cache[$gid];
    }

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
    } catch (Exception $e) {
      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] benchmarks lineup pid scan ERROR gid=$gid " . $e->getMessage());
      }
    }

    if (!$pids) {
      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] benchmarks empty pid set gid=$gid (no non-goalie player_ids found in lineups)");
      }
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

    if (sjms_adv_dbg_chips_enabled()) {
      error_log("[ADVCHIP] benchmarks live_calc gid=$gid " . sjms_adv_dbg_ctx(array(
        'pids' => count($pids),
        'goalies_filtered' => is_array($goalieIds) ? count($goalieIds) : null,
        'counts' => array(
          'toi'   => count($vals['toi']),
          'xgf60' => count($vals['xgf60']),
          'xga60' => count($vals['xga60']),
          'cf60'  => count($vals['cf60']),
        ),
      )));
    }

    $mk = function(array $arr) {
      $arr = array_values(array_filter($arr, function($x){ return is_numeric($x); }));
      if (count($arr) < 6) return array();
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

    if (isset($bench['p10']) && $x <= (float)$bench['p10']) return 'is-ugly';
    if ($x <= (float)$bench['p33']) return 'is-bad';
    if ($x >= (float)$bench['p66']) return 'is-good';
    return 'is-neutral';
  }
}

/* ==========================================================
 * Chips HTML
 * ========================================================== */

if (!function_exists('sjms_adv_chips_html')) {
  function sjms_adv_chips_html(PDO $pdo, $gameId, $playerId) {
    $pid = (int)$playerId;
    $gid = (int)$gameId;

    if (sjms_adv_dbg_chips_enabled()) {
      $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
      $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
      sjms_adv_log_once("chips_enter:$gid:$pid:$uri", "[ADVCHIP] enter " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'playerId_raw' => $playerId,
        'playerId_type' => gettype($playerId),
        'gameId_raw' => $gameId,
        'gameId_type' => gettype($gameId),
        'uri' => $uri,
        'ref' => $ref,
      )));
    }

    if ($pid <= 0 || $gid <= 0) {
      if (sjms_adv_dbg_chips_enabled()) {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        error_log("[ADVCHIP] SKIP invalid ids " . sjms_adv_dbg_ctx(array(
          'gid' => $gid,
          'pid' => $pid,
          'playerId_raw' => $playerId,
          'gameId_raw' => $gameId,
          'uri' => $uri,
          'hint' => 'Caller likely failed name->player_id mapping (abbrev/punctuation/accent).',
        )));
      }
      return '';
    }

    // --------------------------------------------------
    // Suppress placeholder 0.00 chips until live PBP/on-ice exists
    // --------------------------------------------------
    $pbpReady   = sjms_adv_game_pbp_ready($pdo, $gid);
    $onIceReady = $pbpReady ? sjms_adv_player_onice_ready($pdo, $gid, $pid) : false;

    if (sjms_adv_dbg_chips_enabled()) {
      error_log("[ADVCHIP] ready " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'pbpReady' => $pbpReady ? 1 : 0,
        'onIceReady' => $onIceReady ? 1 : 0,
      )));
    }

    $chips = array();

    $benchAll = function_exists('sjms_adv_chip_benchmarks') ? sjms_adv_chip_benchmarks($pdo, $gid) : array();
    $bToi   = $benchAll['toi']   ?? array();
    $bXgf60 = $benchAll['xgf60'] ?? array();
    $bXga60 = $benchAll['xga60'] ?? array();
    $bCf60  = $benchAll['cf60']  ?? array();

    // TOI (ALL situations)
    $toiAllSec = 0;

    $useDb = (function_exists('sjms_adv_use_db_fallback') && sjms_adv_use_db_fallback($pdo, $gid, '5v5'));

    if ($useDb) {
      if (function_exists('sjms_adv_db_row')) {
        $r = sjms_adv_db_row($pdo, $gid, $pid, '5v5');
        if (is_array($r)) $toiAllSec = (int)($r['toi_total'] ?? 0);
      } else {
        if (sjms_adv_dbg_chips_enabled()) {
          error_log("[ADVCHIP] missing sjms_adv_db_row() gid=$gid pid=$pid");
        }
      }
    } else {
      if (function_exists('msf_boxscore_get_player_toi_seconds')) {
        $toiArr = msf_boxscore_get_player_toi_seconds($pdo, $gid, $pid);
        if (is_array($toiArr)) $toiAllSec = (int)($toiArr['toi_total'] ?? 0);
      } else {
        if (sjms_adv_dbg_chips_enabled()) {
          error_log("[ADVCHIP] missing msf_boxscore_get_player_toi_seconds() gid=$gid pid=$pid");
        }
      }
    }

    if (sjms_adv_dbg_chips_enabled()) {
      error_log("[ADVCHIP] toi " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'db_fallback' => sjms_adv_dbg_bool($useDb),
        'toi_total' => (int)$toiAllSec,
      )));
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

    // 5v5 rate chips (only if we actually have on-ice data)
    $rates = null;
    if ($onIceReady && function_exists('sjms_adv_player_onice_rates_5v5')) {
      $rates = sjms_adv_player_onice_rates_5v5($pdo, $gid, $pid);
    }

    if (sjms_adv_dbg_chips_enabled()) {
      error_log("[ADVCHIP] rates " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'onIceReady' => $onIceReady ? 1 : 0,
        'rates_is_array' => is_array($rates) ? 1 : 0,
        'rates_keys' => is_array($rates) ? array_keys($rates) : null,
      )));
    }

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

    // % chips (CF%, xGF%) only after any PBP exists for the game
    if ($pbpReady) {
      $maps = function_exists('sjms_adv_maps') ? sjms_adv_maps($pdo, $gid) : array();

      if (sjms_adv_dbg_chips_enabled()) {
        $hasC = (is_array($maps) && isset($maps['corsi']) && isset($maps['corsi'][$pid])) ? 1 : 0;
        $hasX = (is_array($maps) && isset($maps['xg'])    && isset($maps['xg'][$pid]))    ? 1 : 0;

        error_log("[ADVCHIP] maps " . sjms_adv_dbg_ctx(array(
          'gid' => $gid,
          'pid' => $pid,
          'maps_is_array' => is_array($maps) ? 1 : 0,
          'has_corsi' => $hasC,
          'has_xg' => $hasX,
          'corsi_count' => (is_array($maps) && isset($maps['corsi']) && is_array($maps['corsi'])) ? count($maps['corsi']) : null,
          'xg_count'    => (is_array($maps) && isset($maps['xg'])    && is_array($maps['xg']))    ? count($maps['xg'])    : null,
        )));
      }

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
    }

    if (!$chips) {
      if (sjms_adv_dbg_chips_enabled()) {
        error_log("[ADVCHIP] NO_CHIPS " . sjms_adv_dbg_ctx(array(
          'gid' => $gid,
          'pid' => $pid,
          'note' => 'No TOI, no 5v5 rates, and no % maps for this pid.',
          'most_likely' => 'Upstream player_id mismatch (abbrev/punctuation/accents) OR pbp/boxscore missing rows for this pid.',
        )));
      }
      return '';
    }

    if (sjms_adv_dbg_chips_enabled()) {
      sjms_adv_log_once("chips_ok:$gid:$pid", "[ADVCHIP] OK " . sjms_adv_dbg_ctx(array(
        'gid' => $gid,
        'pid' => $pid,
        'chip_count' => count($chips),
      )));
    }

    return '<div class="adv-chips">' . implode('', $chips) . '</div>';
  }
}
