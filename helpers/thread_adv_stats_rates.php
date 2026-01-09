<?php
//======================================
// File: public/helpers/thread_adv_stats_rates.php
// Description: On-ice per-player rates (5v5), cached.
// PHP 7+ friendly.
//======================================

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