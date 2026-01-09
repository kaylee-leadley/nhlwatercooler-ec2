<?php
//======================================
// File: public/helpers/thread_adv_stats_maps.php
// Description: Cached maps (CF%, FF%, xGF%).
// PHP 7+ friendly.
//======================================

if (!function_exists('sjms_adv_maps')) {
  function sjms_adv_maps(PDO $pdo, $gameId) {
    static $cache = array();
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    if (isset($cache[$gid])) return $cache[$gid];

    $out = array(
      'corsi'   => array(),
      'fenwick' => array(),
      'xg'      => array(),
    );

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

    if (!sjms_adv_available($pdo, $gid)) {
      $cache[$gid] = $out;
      return $out;
    }

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
            'FF' => (int)($r['CF'] ?? 0),
            'FA' => (int)($r['CA'] ?? 0),
            'FF_pct' => isset($r['CF_pct']) ? $r['CF_pct'] : null,
          );
        }
      }
    }

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