<?php
//======================================
// File: public/helpers/thread_adv_stats_garwar.php
// Description: GAR/WAR helpers + team rows builder.
// PHP 7+ friendly.
//======================================

if (!function_exists('sjms_adv_player_garwar')) {
  function sjms_adv_player_garwar(PDO $pdo, $gameId, $playerId) {
    static $cache = array();

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
      $gar = (isset($r['gar_total']) && is_numeric($r['gar_total'])) ? (float)$r['gar_total'] : null;
      $war = (isset($r['war_total']) && $r['war_total'] !== null && is_numeric($r['war_total'])) ? (float)$r['war_total'] : null;

      $cache[$gid][$pid] = array('GAR' => $gar, 'WAR' => $war);
      return $cache[$gid][$pid];
    }

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

    // Live path
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
