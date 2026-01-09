<?php
//======================================
// File: public/helpers/thread_adv_stats_renderer.php
// Description: Public renderer sjms_threads_adv_stats_html()
// PHP 7+ friendly.
//======================================

/* ============================================================
 * Debug helpers
 * ========================================================== */

if (!function_exists('sjms_adv_dbg_enabled')) {
  function sjms_adv_dbg_enabled($opts) {
    if (!is_array($opts)) return false;
    return !empty($opts['debug']);
  }
}

if (!function_exists('sjms_adv_dbg')) {
  function sjms_adv_dbg($enabled, $msg) {
    if (!$enabled) return;
    error_log('[ADVDBG] ' . $msg);
  }
}

if (!function_exists('sjms_adv_dbg_kv')) {
  function sjms_adv_dbg_kv($enabled, $label, $arr) {
    if (!$enabled) return;
    if (!is_array($arr)) {
      sjms_adv_dbg(true, $label . '=<not-array>');
      return;
    }
    $pairs = array();
    foreach ($arr as $k => $v) {
      if (is_bool($v)) $v = $v ? 'true' : 'false';
      elseif ($v === null) $v = 'null';
      elseif (is_array($v)) $v = 'array(' . count($v) . ')';
      else $v = (string)$v;
      $pairs[] = $k . '=' . $v;
    }
    sjms_adv_dbg(true, $label . ' ' . implode(' ', $pairs));
  }
}

if (!function_exists('sjms_adv_dbg_rows_summary')) {
  function sjms_adv_dbg_rows_summary($enabled, $abbr, $rows, $minPlayers, $minToi5v5) {
    if (!$enabled) return;

    $n = (is_array($rows) ? count($rows) : 0);
    if ($n <= 0) {
      sjms_adv_dbg(true, "team=$abbr rows=0");
      return;
    }

    // Shape check (first row keys)
    $keys = array();
    $first = $rows[0];
    if (is_array($first)) {
      $keys = array_slice(array_keys($first), 0, 40);
    }

    $toiOk = 0;
    $toiMissing = 0;
    $minToi = 999999;
    $maxToi = 0;

    $hasXg = 0;
    $hasOther = 0;

    foreach ($rows as $r) {
      if (!is_array($r)) continue;

      // TOI candidates
      $toi = null;
      if (isset($r['toi_5v5']) && $r['toi_5v5'] !== '' && is_numeric($r['toi_5v5'])) $toi = (int)$r['toi_5v5'];
      elseif (isset($r['toi']) && $r['toi'] !== '' && is_numeric($r['toi'])) $toi = (int)$r['toi'];
      elseif (isset($r['ev_toi']) && $r['ev_toi'] !== '' && is_numeric($r['ev_toi'])) $toi = (int)$r['ev_toi'];
      elseif (isset($r['evenStrengthTimeOnIceSeconds']) && $r['evenStrengthTimeOnIceSeconds'] !== '' && is_numeric($r['evenStrengthTimeOnIceSeconds'])) $toi = (int)$r['evenStrengthTimeOnIceSeconds'];

      if ($toi === null) {
        $toiMissing++;
        $toi = 0;
      }

      if ($toi < $minToi) $minToi = $toi;
      if ($toi > $maxToi) $maxToi = $toi;
      if ($toi >= $minToi5v5) $toiOk++;

      // xG diff candidates
      $xgd = null;
      if (isset($r['xgdiff60_5v5']) && $r['xgdiff60_5v5'] !== '') {
        $xgd = $r['xgdiff60_5v5'];
      } elseif (
        isset($r['xgf60_5v5'], $r['xga60_5v5']) &&
        $r['xgf60_5v5'] !== '' && $r['xga60_5v5'] !== '' &&
        is_numeric($r['xgf60_5v5']) && is_numeric($r['xga60_5v5'])
      ) {
        $xgd = (float)$r['xgf60_5v5'] - (float)$r['xga60_5v5'];
      }
      if ($xgd !== null && is_numeric($xgd)) $hasXg++;

      // “Other” signal candidates
      $okOther = false;
      $cand = array(
        'scdiff60_5v5','scf60_5v5','sca60_5v5',
        'cfdiff60_5v5','cf60_5v5','ca60_5v5',
        'gdiff60_5v5','gf60_5v5','ga60_5v5',
        'pendiff60_5v5','pen_diff_5v5','pen_drawn_5v5','pen_taken_5v5'
      );
      foreach ($cand as $k) {
        if (isset($r[$k]) && $r[$k] !== '' && is_numeric($r[$k])) { $okOther = true; break; }
      }
      if ($okOther) $hasOther++;
    }

    sjms_adv_dbg(true,
      "team=$abbr rows=$n " .
      "keys=[" . implode(',', $keys) . "] " .
      "toi_ok=$toiOk(min_toI=$minToi5v5) toi_missing=$toiMissing toi_range={$minToi}..{$maxToi} " .
      "has_xg=$hasXg has_other=$hasOther min_players=$minPlayers"
    );
  }
}

/* ============================================================
 * Public renderer
 * ========================================================== */

if (!function_exists('sjms_threads_adv_stats_html')) {
  function sjms_threads_adv_stats_html(PDO $pdo, $gameId, $opts = array()) {

    $gid = (int)$gameId;
    if ($gid <= 0) return '';

    $debug = sjms_adv_dbg_enabled($opts);

    // Cheap “mode” log (keep this)
    error_log("[ADV] gid=$gid use_db_fallback=" .
      (function_exists('sjms_adv_use_db_fallback') && sjms_adv_use_db_fallback($pdo,$gid) ? 'yes' : 'no') .
      " live_has_pbp=" .
      (function_exists('sjms_adv_live_has_pbp') && sjms_adv_live_has_pbp($pdo,$gid) ? 'yes' : 'no')
    );

    if (function_exists('sjms_adv_log_mode')) {
      sjms_adv_log_mode($pdo, $gid, $opts);
    }

    $stateKey = (isset($opts['state_key']) && $opts['state_key'] !== '') ? (string)$opts['state_key'] : '5v5';

    // OIS defaults (weights + TOI ref)
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

    // Teams
    $pair = function_exists('sjms_adv_get_game_teams')
      ? sjms_adv_get_game_teams($pdo, $gid, $stateKey)
      : array('', '');

    $away = strtoupper(trim((string)($pair[0] ?? '')));
    $home = strtoupper(trim((string)($pair[1] ?? '')));
    if ($home === '' && $away === '') {
      sjms_adv_dbg($debug, "gid=$gid early-exit: no teams from sjms_adv_get_game_teams()");
      return '';
    }

    $title        = isset($opts['title']) ? (string)$opts['title'] : 'Advanced Stats';
    $showQuadrant = array_key_exists('show_quadrant', $opts) ? (bool)$opts['show_quadrant'] : true;

    $teams = array();
    if ($away !== '') $teams[] = $away;
    if ($home !== '' && $home !== $away) $teams[] = $home;

    $minPlayers = isset($opts['min_players']) ? (int)$opts['min_players'] : 6;
    $minToi5v5  = isset($opts['min_toi_5v5']) ? (int)$opts['min_toi_5v5'] : 60;

    // This is the cut you apply before rendering the impact chart
    $minToiForChart = isset($opts['min_toi_for_chart']) ? (int)$opts['min_toi_for_chart'] : 240;

    sjms_adv_dbg($debug, "gid=$gid stateKey=$stateKey teams=" . implode(',', $teams) . " showQuadrant=" . ($showQuadrant ? '1' : '0'));
    sjms_adv_dbg($debug, "minPlayers=$minPlayers minToi5v5=$minToi5v5 minToiForChart=$minToiForChart toi_ref=$TOI_REF");
    sjms_adv_dbg_kv($debug, "ois", $ois);

    $blocks = array();
    $anyReady = false;

    foreach ($teams as $abbr) {

      if (!function_exists('sjms_adv_team_garwar')) {
        sjms_adv_dbg($debug, "team=$abbr FAIL: sjms_adv_team_garwar() missing");
        continue;
      }

      $rows = sjms_adv_team_garwar($pdo, $gid, $abbr);

      sjms_adv_dbg($debug, "team=$abbr fetched_rows=" . (is_array($rows) ? count($rows) : 0));
      sjms_adv_dbg_rows_summary($debug, $abbr, $rows, $minPlayers, $minToi5v5);

      if (!$rows || !is_array($rows)) {
        sjms_adv_dbg($debug, "team=$abbr SKIP: no rows");
        continue;
      }

      if (!function_exists('sjms_adv_rows_ready')) {
        sjms_adv_dbg($debug, "team=$abbr FAIL: sjms_adv_rows_ready() missing");
        continue;
      }

      if (!sjms_adv_rows_ready($rows, $minPlayers, $minToi5v5)) {
        sjms_adv_dbg($debug, "team=$abbr FAIL rows_ready(): false");
        continue;
      }

      sjms_adv_dbg($debug, "team=$abbr PASS rows_ready()");

      if (function_exists('ois_compute_rows')) {
        $before = count($rows);
        $rows = ois_compute_rows($rows, $ois, array(
          'state_key' => $stateKey,
          'min_toi'   => $minToiForChart,
        ));
        $after = is_array($rows) ? count($rows) : 0;
        sjms_adv_dbg($debug, "team=$abbr ois_compute_rows before=$before after=$after (min_toi=$minToiForChart)");
      } else {
        sjms_adv_dbg($debug, "team=$abbr NOTE: ois_compute_rows() missing (JS may fallback)");
      }

      $anyReady = true;

      $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($debug) {
        sjms_adv_dbg(true, "team=$abbr json_len=" . strlen((string)$json));
      }

      $impactTitle = function_exists('ois_title')
        ? ois_title($stateKey, $abbr)
        : ('Overall Impact Score (xG±/60 + SC±/60 + CF±/60 + G±/60) • ' . $stateKey . ' • ' . $abbr);

      // NOTE: sjms_h() should exist in your utils module
      $blocks[] =
        '<div class="adv-team">' .
          '<h3 class="adv-team__hdr">' . sjms_h($abbr) . '</h3>' .

          '<div class="adv-chart adv-chart--impact js-adv-impactscore" ' .
            'data-team="' . sjms_h($abbr) . '" ' .
            'data-title="' . sjms_h($impactTitle) . '" ' .
            'data-min-toi="' . sjms_h($minToiForChart) . '" ' .
            'data-toi-ref="' . sjms_h($TOI_REF) . '" ' .
            'data-scale="2.2" ' .

            'data-w-xg="'  . sjms_h($W_XG)  . '" ' .
            'data-w-sc="'  . sjms_h($W_SC)  . '" ' .
            'data-w-cf="'  . sjms_h($W_CF)  . '" ' .
            'data-w-g="'   . sjms_h($W_G)   . '" ' .
            'data-w-pen="' . sjms_h($W_PEN) . '" ' .

            'data-rows="' . sjms_h($json) . '"' .
          '></div>' .

        '</div>';
    }

    if (!$anyReady) {
      error_log("[ADV] gid=$gid skip reason=no_ready_teams");
      sjms_adv_dbg($debug, "gid=$gid returning '' because NO teams passed rows_ready()");
      return '';
    }

    $quadHtml = '';

    if ($showQuadrant) {

      if (!function_exists('sjms_adv_xg_quadrant_points')) {
        sjms_adv_dbg($debug, "quadrant FAIL: sjms_adv_xg_quadrant_points() missing");
      } else {
        $points = sjms_adv_xg_quadrant_points($pdo, $gid);
        sjms_adv_dbg($debug, "quadrant points=" . (is_array($points) ? count($points) : 0));

        if (!empty($points) && is_array($points)) {
          $pointsJson = json_encode($points, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

          $quadHtml =
            '<div class="adv-block adv-block--quadrant">' .
              '<div class="xgq js-xgq-svg" ' .
                'data-auto-scale="1" ' .
                'data-title="' . sjms_h('Expected Goals For vs. Against - 5v5') . '" ' .
                'data-points="' . sjms_h($pointsJson) . '"' .
              '></div>' .
            '</div>';
        } else {
          sjms_adv_dbg($debug, "quadrant skipped: no points");
        }
      }
    }

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
