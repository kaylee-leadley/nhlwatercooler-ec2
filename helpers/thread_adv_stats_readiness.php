<?php
//======================================
// File: public/helpers/thread_adv_stats_readiness.php
// Description: Readiness gates to avoid blank/empty charts.
// PHP 7+ friendly.
//======================================

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

    $need = array(
      'xgf60_5v5',
      'xga60_5v5',
      'cf60_5v5',
      'ca60_5v5',
      'scf60_5v5',
      'sca60_5v5',
      'gf60_5v5',
      'ga60_5v5',
    );

    foreach ($need as $k) {
      if (!sjms_adv_is_finite_num($r[$k] ?? null)) return false;
    }

    return true;
  }
}

if (!function_exists('sjms_adv_rows_ready')) {
  /**
   * Team block is "ready" if enough skaters have core stats.
   */
  function sjms_adv_rows_ready(array $rows, $minPlayers = 6, $minToi5v5 = 60) {
    $ok = 0;
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      if (sjms_adv_row_has_core_stats($r, $minToi5v5)) $ok++;
      if ($ok >= (int)$minPlayers) return true;
    }
    return ($ok >= 4);
  }
}