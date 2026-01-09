<?php
//======================================
// File: public/helpers/adv_quadrant_helpers.php
// Description: Quadrant points helper (xGF/60 vs xGA/60) for Advanced Stats.
//              Keeps "sjms_*" quadrant logic OUT of msf_pbp_helpers.php.
//              Uses DB fallback (nhl_players_advanced_stats) for old/scrubbed games,
//              otherwise uses LIVE PBP (msf_live_*).
//
// Depends on (must be loaded by caller):
//   - public/includes/msf_pbp_helpers.php          (msf_pbp_get_all_skaters_xgf, etc.)
//   - public/helpers/msf_toi_helpers.php           (msf_player_gamelogs_get_toi_map_seconds, msf_boxscore_pick_toi_for_filter)
//   - public/api/thread_adv_stats.php (or similar) (sjms_adv_use_db_fallback, sjms_adv_goalie_ids_for_game)
//   - OPTIONAL: sjms_team_logo_url() exists in render layer; we guard it.
//
// Notes:
//   - Output fields match your xg-quadrant JS expectations:
//       player_id, name, team, pos, logo,
//       xgf, xga, xgf_total, xga_total,
//       toi, toi_5v5, toi_all
//======================================

// Avoid direct access if you want (optional)
// if (php_sapi_name() !== 'cli' && !defined('SJMS_BOOTSTRAPPED')) { exit; }

if (!defined('SJMS_ADV_DB_TABLE')) {
  define('SJMS_ADV_DB_TABLE', 'nhl_players_advanced_stats');
}

if (!function_exists('sjms_h')) {
  function sjms_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('sjms_team_logo_url')) {
  /**
   * Optional fallback if your main file didn’t define it.
   * Adjust path if needed.
   */
  function sjms_team_logo_url($teamAbbr) {
    $abbr = strtoupper(trim((string)$teamAbbr));
    if ($abbr === '') return null;
    $fs = __DIR__ . '/../assets/img/logos/' . $abbr . '.png';
    if (!is_file($fs)) return null;
    return '/assets/img/logos/' . $abbr . '.png';
  }
}

if (!function_exists('sjms_adv_quadrant_player_info_map')) {
  /**
   * Build player_id => ['name','team','pos'] from lineups.
   */
  function sjms_adv_quadrant_player_info_map(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    $info = array();
    try {
      $st = $pdo->prepare("
        SELECT player_id, first_name, last_name, team_abbr, player_position
        FROM lineups
        WHERE game_id = :gid AND player_id IS NOT NULL
      ");
      $st->execute(array(':gid' => $gid));
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($r['player_id'] ?? 0);
        if ($pid <= 0) continue;
        if (isset($info[$pid])) continue;

        $name = trim(trim((string)($r['first_name'] ?? '')) . ' ' . trim((string)($r['last_name'] ?? '')));
        $team = strtoupper(trim((string)($r['team_abbr'] ?? '')));
        $pos  = strtoupper(trim((string)($r['player_position'] ?? '')));

        $info[$pid] = array(
          'name' => ($name !== '' ? $name : ('Player ' . $pid)),
          'team' => $team,
          'pos'  => ($pos !== '' ? $pos : null),
        );
      }
    } catch (Exception $e) {}

    return $info;
  }
}

if (!function_exists('sjms_adv_xg_quadrant_points')) {
  /**
   * Quadrant points for ALL skaters.
   * - If sjms_adv_use_db_fallback() exists and returns true:
   *     use nhl_players_advanced_stats (works after PBP scrub).
   * - Otherwise use LIVE PBP (msf_live_*).
   *
   * Output fields:
   *   player_id, name, team, pos, logo,
   *   xgf, xga, xgf_total, xga_total,
   *   toi, toi_5v5, toi_all
   */
  function sjms_adv_xg_quadrant_points(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    $goalieIds = function_exists('sjms_adv_goalie_ids_for_game')
      ? sjms_adv_goalie_ids_for_game($pdo, $gid)
      : array();

    $info = function_exists('sjms_adv_quadrant_player_info_map')
      ? sjms_adv_quadrant_player_info_map($pdo, $gid)
      : array();

    $useDb = (function_exists('sjms_adv_use_db_fallback') && sjms_adv_use_db_fallback($pdo, $gid, '5v5'));

    // -------------------------------
    // DB fallback path (scrubbed/old)
    // -------------------------------
    if ($useDb) {
      $points = array();

      try {
        $st = $pdo->prepare("
          SELECT player_id, team_abbr,
                 xGF, xGA,
                 xGF_60, xGA_60,
                 toi_used, toi_total
          FROM " . SJMS_ADV_DB_TABLE . "
          WHERE game_id = :gid AND state_key = '5v5'
        ");
        $st->execute(array(':gid' => $gid));

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pid = (int)($r['player_id'] ?? 0);
          if ($pid <= 0) continue;
          if (!empty($goalieIds[$pid])) continue;

          $team = strtoupper(trim((string)($r['team_abbr'] ?? ($info[$pid]['team'] ?? ''))));
          $logo = ($team !== '' && function_exists('sjms_team_logo_url')) ? sjms_team_logo_url($team) : null;

          $xgfTot = (float)($r['xGF'] ?? 0.0);
          $xgaTot = (float)($r['xGA'] ?? 0.0);

          $toi5   = (int)($r['toi_used'] ?? 0);   // 5v5 seconds (your stored "toi_used")
          $toiAll = (int)($r['toi_total'] ?? 0);  // all situations seconds
          $den    = ($toi5 > 0) ? $toi5 : $toiAll;

          $xgf60 = (isset($r['xGF_60']) && is_numeric($r['xGF_60'])) ? (float)$r['xGF_60'] : null;
          $xga60 = (isset($r['xGA_60']) && is_numeric($r['xGA_60'])) ? (float)$r['xGA_60'] : null;

          // If DB is missing per-60, compute from totals + toi used
          if ($xgf60 === null) $xgf60 = ($den > 0) ? ($xgfTot * 3600.0 / $den) : $xgfTot;
          if ($xga60 === null) $xga60 = ($den > 0) ? ($xgaTot * 3600.0 / $den) : $xgaTot;

          $points[] = array(
            'player_id' => $pid,
            'name' => $info[$pid]['name'] ?? ('Player ' . $pid),
            'team' => $team,
            'pos'  => $info[$pid]['pos'] ?? null,
            'logo' => $logo,

            'xgf'  => round($xgf60, 3),
            'xga'  => round($xga60, 3),

            'xgf_total' => round($xgfTot, 3),
            'xga_total' => round($xgaTot, 3),

            'toi'     => ($den > 0 ? $den : 0),
            'toi_5v5' => ($toi5 > 0 ? $toi5 : 0),
            'toi_all' => ($toiAll > 0 ? $toiAll : 0),
          );
        }
      } catch (Exception $e) {
        return array();
      }

      return $points;
    }

    // -------------------------------
    // Live PBP path
    // -------------------------------
    if (!function_exists('msf_pbp_get_all_skaters_xgf')) return array();
    if (!function_exists('msf_player_gamelogs_get_toi_map_seconds')) return array();
    if (!function_exists('msf_boxscore_pick_toi_for_filter')) return array();

    $toiMap = msf_player_gamelogs_get_toi_map_seconds($pdo, $gid);

    // Small min total keeps weird 0-point skaters from stretching scales
    $rows = msf_pbp_get_all_skaters_xgf($pdo, $gid, '5v5', false, 0.01);
    if (!is_array($rows) || !$rows) return array();

    $points = array();

    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;
      if (!empty($goalieIds[$pid])) continue;

      $xGF = (float)($r['xGF'] ?? 0.0);
      $xGA = (float)($r['xGA'] ?? 0.0);

      $toiArr = $toiMap[$pid] ?? null;

      $toi5 = (int)msf_boxscore_pick_toi_for_filter($toiArr, '5v5', 0);
      if ($toi5 <= 0 && is_array($toiArr)) $toi5 = (int)($toiArr['toi_ev'] ?? 0);
      $toiAll = (is_array($toiArr) ? (int)($toiArr['toi_total'] ?? 0) : 0);

      $den = ($toi5 > 0) ? $toi5 : $toiAll;

      $xGF60 = ($den > 0) ? ($xGF * 3600.0 / $den) : $xGF;
      $xGA60 = ($den > 0) ? ($xGA * 3600.0 / $den) : $xGA;

      $name = $info[$pid]['name'] ?? ('Player ' . $pid);
      $team = $info[$pid]['team'] ?? '';
      $pos  = $info[$pid]['pos']  ?? null;
      $logo = ($team !== '' && function_exists('sjms_team_logo_url')) ? sjms_team_logo_url($team) : null;

      $points[] = array(
        'player_id' => $pid,
        'name' => $name,
        'team' => $team,
        'pos'  => $pos,
        'logo' => $logo,

        'xgf'  => round($xGF60, 3),
        'xga'  => round($xGA60, 3),

        'xgf_total' => round($xGF, 3),
        'xga_total' => round($xGA, 3),

        'toi'     => ($den > 0 ? $den : 0),
        'toi_5v5' => ($toi5 > 0 ? $toi5 : 0),
        'toi_all' => ($toiAll > 0 ? $toiAll : 0),
      );
    }

    return $points;
  }
}