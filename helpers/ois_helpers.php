<?php
//======================================
// File: public/helpers/ois_helpers.php
// Description: Canonical helpers + config for Overall Impact Score (OIS).
//              Keeps weights + TOI_REF consistent between:
//                - thread_adv_stats.php (HTML data-* + footer)
//                - overall_impact_score.php (SQL endpoint)
//              AND provides a canonical PHP implementation of OIS computation,
//              so JS can become a pure renderer and cannot drift.
//
// Notes:
// - PHP 7+ friendly.
// - Defaults match your current overall_impact_score.php values.
// - OIS math matches the legacy JS (advsvg-impact.js):
//     * z-scores within slice
//     * TOI weight sqrt(toi/toi_ref) clamped [0.55..1.20]
//     * weights renormalized over available components
//======================================

if (!function_exists('ois_defaults')) {
  /**
   * Canonical OIS config.
   * Keep these values as the single source of truth.
   */
  function ois_defaults() {
    return array(
      // Weights
      'w_xg'  => 0.45,
      'w_sc'  => 0.25,
      'w_cf'  => 0.20,
      'w_g'   => 0.05,
      'w_pen' => 0.05,

      // TOI reference (seconds) for confidence weighting
      'toi_ref' => 600,

      // Label helpers
      'label' => 'Overall Impact Score',
      'state_default' => '5v5',
    );
  }
}

if (!function_exists('ois_norm_state')) {
  function ois_norm_state($s) {
    $s = trim((string)$s);
    return ($s === '') ? '5v5' : $s;
  }
}

if (!function_exists('ois_title')) {
  /**
   * Title used in chart headers/tooltips.
   */
  function ois_title($stateKey, $teamAbbr = '') {
    $cfg = ois_defaults();
    $stateKey = ois_norm_state($stateKey);
    $teamAbbr = strtoupper(trim((string)$teamAbbr));

    $base = (string)($cfg['label'] ?? 'Overall Impact Score');
    $parts = array(
      'xG±/60',
      'SC±/60',
      'CF±/60',
      'G±/60',
    );

    $s = $base . ' (' . implode(' + ', $parts) . ') • ' . $stateKey;
    if ($teamAbbr !== '') $s .= ' • ' . $teamAbbr;

    return $s;
  }
}

if (!function_exists('ois_footer_html')) {
  /**
   * End-user footer (short, readable).
   */
  function ois_footer_html() {
    return '
<div class="adv-impact-footer">
  <h4 class="adv-impact-footer__hdr">How the Impact Score works</h4>
  <p>
    <strong>Impact Score</strong> is a single-game composite built from on-ice <em>per-60</em> rates (5v5).
    It compares players <em>within this game</em>. Higher is better.
  </p>
  <ul class="adv-impact-footer__list">
    <li><strong>Per-60</strong> means “rate per 60 minutes of ice time.” Small TOI can swing results.</li>
    <li>The score blends <strong>xG±/60</strong>, <strong>SC±/60</strong>, <strong>CF±/60</strong>, and <strong>G±/60</strong>.</li>
  </ul>
</div>';
  }
}

/* ==========================================================
 * Canonical OIS computation (PHP)
 * ========================================================== */

if (!function_exists('ois_is_finite')) {
  function ois_is_finite($v) {
    return ($v !== null && $v !== '' && is_numeric($v) && is_finite((float)$v));
  }
}

if (!function_exists('ois_num')) {
  function ois_num($v, $d = null) {
    return ois_is_finite($v) ? (float)$v : $d;
  }
}

if (!function_exists('ois_clamp')) {
  function ois_clamp($v, $a, $b) {
    if (!is_finite($v)) return $a;
    if ($v < $a) return $a;
    if ($v > $b) return $b;
    return $v;
  }
}

if (!function_exists('ois_mean_std')) {
  /**
   * Matches JS:
   * - mean over slice
   * - sample stddev using (n-1) denom
   * - std = 1 if invalid/tiny
   */
  function ois_mean_std(array $vals) {
    $n = count($vals);
    if ($n <= 0) return array('mean' => 0.0, 'std' => 1.0);

    $sum = 0.0;
    foreach ($vals as $v) $sum += (float)$v;
    $mu = $sum / (float)$n;

    if ($n === 1) return array('mean' => $mu, 'std' => 1.0);

    $var = 0.0;
    foreach ($vals as $v) {
      $d = (float)$v - $mu;
      $var += $d * $d;
    }
    $var = $var / (float)max(1, ($n - 1));
    $sd = sqrt($var);

    if (!is_finite($sd) || $sd < 1e-9) $sd = 1.0;
    return array('mean' => $mu, 'std' => $sd);
  }
}

if (!function_exists('ois_extract_components')) {
  /**
   * Extracts the same component values and fallbacks the legacy JS uses.
   * Expects row keys like:
   *   - toi_5v5
   *   - xgdiff60_5v5 OR xgf60_5v5/xga60_5v5
   *   - scdiff60_5v5 OR scf60_5v5/sca60_5v5
   *   - cfdiff60_5v5 OR cf60_5v5/ca60_5v5 OR cf60_5v5
   *   - gdiff60_5v5 OR gf60_5v5/ga60_5v5
   *   - pendiff60_5v5 OR pen_diff_5v5 OR pen_drawn_5v5/pen_taken_5v5 scaled by TOI
   */
  function ois_extract_components(array $r, $stateKey = '5v5') {
    $sk = trim((string)$stateKey);
    if ($sk === '') $sk = '5v5';

    $toi = (int)($r['toi_' . $sk] ?? ($r['toi_5v5'] ?? 0));
    if ($toi < 0) $toi = 0;

    // xG diff/60
    $xgd = ois_num($r['xgdiff60_' . $sk] ?? ($r['xgdiff60_5v5'] ?? null), null);
    if ($xgd === null) {
      $xgf = ois_num($r['xgf60_' . $sk] ?? ($r['xgf60_5v5'] ?? null), null);
      $xga = ois_num($r['xga60_' . $sk] ?? ($r['xga60_5v5'] ?? null), null);
      if ($xgf !== null && $xga !== null) $xgd = $xgf - $xga;
    }

    // SC diff/60
    $scd = ois_num($r['scdiff60_' . $sk] ?? ($r['scdiff60_5v5'] ?? null), null);
    if ($scd === null) {
      $scf = ois_num($r['scf60_' . $sk] ?? ($r['scf60_5v5'] ?? null), null);
      $sca = ois_num($r['sca60_' . $sk] ?? ($r['sca60_5v5'] ?? null), null);
      if ($scf !== null && $sca !== null) $scd = $scf - $sca;
    }

    // CF diff/60
    $cfd = ois_num($r['cfdiff60_' . $sk] ?? ($r['cfdiff60_5v5'] ?? null), null);
    if ($cfd === null) {
      $cf = ois_num($r['cf60_' . $sk] ?? ($r['cf60_5v5'] ?? null), null);
      $ca = ois_num($r['ca60_' . $sk] ?? ($r['ca60_5v5'] ?? null), null);
      if ($cf !== null && $ca !== null) $cfd = $cf - $ca;
      else $cfd = $cf; // JS fallback
    }

    // G diff/60
    $gd = ois_num($r['gdiff60_' . $sk] ?? ($r['gdiff60_5v5'] ?? null), null);
    if ($gd === null) {
      $gf = ois_num($r['gf60_' . $sk] ?? ($r['gf60_5v5'] ?? null), null);
      $ga = ois_num($r['ga60_' . $sk] ?? ($r['ga60_5v5'] ?? null), null);
      if ($gf !== null && $ga !== null) $gd = $gf - $ga;
    }

    // Pen diff/60
    $pd = ois_num($r['pendiff60_' . $sk] ?? ($r['pendiff60_5v5'] ?? null), null);
    if ($pd === null) {
      $pDiff = ois_num($r['pen_diff_' . $sk] ?? ($r['pen_diff_5v5'] ?? null), null);
      if ($pDiff !== null && $toi > 0) {
        $pd = $pDiff * 3600.0 / (float)$toi;
      } else {
        $pDrawn = ois_num($r['pen_drawn_' . $sk] ?? ($r['pen_drawn_5v5'] ?? null), null);
        $pTaken = ois_num($r['pen_taken_' . $sk] ?? ($r['pen_taken_5v5'] ?? null), null);
        if ($pDrawn !== null && $pTaken !== null && $toi > 0) {
          $pd = ($pDrawn - $pTaken) * 3600.0 / (float)$toi;
        }
      }
    }

    return array(
      'toi' => $toi,
      'xgd' => $xgd,
      'scd' => $scd,
      'cfd' => $cfd,
      'gd'  => $gd,
      'pd'  => $pd,
    );
  }
}

if (!function_exists('ois_compute_rows')) {
  /**
   * Compute OIS over the provided slice (rows array).
   * Adds:
   *  ois_score, ois_weight, ois_sumW
   *  ois_zx/zs/zc/zg/zp
   *  ois_cx/cs/cc/cg/cp
   *  ois_possum/ois_negsum
   *  ois_xgd/ois_scd/ois_cfd/ois_gd/ois_pd (raw values actually used)
   *
   * IMPORTANT:
   * - This is designed so JS can render only and never drift.
   */
  function ois_compute_rows(array $rows, array $cfg = array(), array $opts = array()) {
    $stateKey = isset($opts['state_key']) ? (string)$opts['state_key'] : '5v5';
    $minToi   = isset($opts['min_toi']) ? (int)$opts['min_toi'] : 0;

    $toiRef = isset($cfg['toi_ref']) ? (int)$cfg['toi_ref'] : 600;
    if ($toiRef <= 0) $toiRef = 600;

    $W_XG  = isset($cfg['w_xg'])  ? (float)$cfg['w_xg']  : 0.45;
    $W_SC  = isset($cfg['w_sc'])  ? (float)$cfg['w_sc']  : 0.25;
    $W_CF  = isset($cfg['w_cf'])  ? (float)$cfg['w_cf']  : 0.20;
    $W_G   = isset($cfg['w_g'])   ? (float)$cfg['w_g']   : 0.05;
    $W_PEN = isset($cfg['w_pen']) ? (float)$cfg['w_pen'] : 0.05;

    // 1) Keep rows that are computable (matches JS gate)
    $kept = array();
    foreach ($rows as $r) {
      if (!is_array($r)) continue;

      $c = ois_extract_components($r, $stateKey);
      $toi = (int)($c['toi'] ?? 0);
      if ($minToi > 0 && $toi < $minToi) continue;

      $xgd = $c['xgd']; $scd = $c['scd']; $cfd = $c['cfd']; $gd = $c['gd']; $pd = $c['pd'];

      $okOther = ois_is_finite($scd) || ois_is_finite($cfd) || ois_is_finite($gd) || ois_is_finite($pd);
      if (!ois_is_finite($xgd) || !$okOther) continue;

      $r['ois_xgd'] = (float)$xgd;
      $r['ois_scd'] = ois_is_finite($scd) ? (float)$scd : null;
      $r['ois_cfd'] = ois_is_finite($cfd) ? (float)$cfd : null;
      $r['ois_gd']  = ois_is_finite($gd)  ? (float)$gd  : null;
      $r['ois_pd']  = ois_is_finite($pd)  ? (float)$pd  : null;

      $kept[] = $r;
    }

    if (!$kept) return $rows;

    // 2) Build distributions
    $vals = array('xgd'=>array(),'scd'=>array(),'cfd'=>array(),'gd'=>array(),'pd'=>array());
    foreach ($kept as $r) {
      foreach ($vals as $k => $_) {
        $key = 'ois_' . $k;
        if (isset($r[$key]) && ois_is_finite($r[$key])) $vals[$k][] = (float)$r[$key];
      }
    }

    $bx = ois_mean_std($vals['xgd']);
    $bs = ois_mean_std($vals['scd']);
    $bc = ois_mean_std($vals['cfd']);
    $bg = ois_mean_std($vals['gd']);
    $bp = ois_mean_std($vals['pd']);

    $z = function($v, $b) {
      return ((float)$v - (float)$b['mean']) / (float)$b['std'];
    };

    // Map by player_id for stable merge back into original ordering
    $byPid = array();
    foreach ($kept as $r) {
      $pid = isset($r['player_id']) ? (string)$r['player_id'] : '';
      if ($pid !== '') $byPid[$pid] = $r;
    }

    $out = array();

    foreach ($rows as $r0) {
      if (!is_array($r0)) { $out[] = $r0; continue; }

      $pid = isset($r0['player_id']) ? (string)$r0['player_id'] : '';
      $r = ($pid !== '' && isset($byPid[$pid])) ? $byPid[$pid] : $r0;

      // Not in kept slice => leave unchanged
      if (!isset($r['ois_xgd']) || !ois_is_finite($r['ois_xgd'])) {
        $out[] = $r0;
        continue;
      }

      $toi = (int)($r['toi_' . $stateKey] ?? ($r['toi_5v5'] ?? 0));
      if ($toi < 0) $toi = 0;

      $zx = ois_is_finite($r['ois_xgd']) ? $z($r['ois_xgd'], $bx) : null;
      $zs = ois_is_finite($r['ois_scd']) ? $z($r['ois_scd'], $bs) : null;
      $zc = ois_is_finite($r['ois_cfd']) ? $z($r['ois_cfd'], $bc) : null;
      $zg = ois_is_finite($r['ois_gd'])  ? $z($r['ois_gd'],  $bg) : null;
      $zp = ois_is_finite($r['ois_pd'])  ? $z($r['ois_pd'],  $bp) : null;

      $w = sqrt(max(0.0, (float)$toi) / (float)$toiRef);
      $w = ois_clamp($w, 0.55, 1.20);

      $sumW = 0.0;
      if ($zx !== null) $sumW += $W_XG;
      if ($zs !== null) $sumW += $W_SC;
      if ($zc !== null) $sumW += $W_CF;
      if ($zg !== null) $sumW += $W_G;
      if ($zp !== null) $sumW += $W_PEN;
      if ($sumW <= 0.0) $sumW = 1.0;

      $contrib = function($zv, $baseW) use ($sumW, $w) {
        if ($zv === null) return 0.0;
        $ww = (float)$baseW / (float)$sumW;
        return ($ww * (float)$zv) * (float)$w;
      };

      $cx = $contrib($zx, $W_XG);
      $cs = $contrib($zs, $W_SC);
      $cc = $contrib($zc, $W_CF);
      $cg = $contrib($zg, $W_G);
      $cp = $contrib($zp, $W_PEN);

      $score = $cx + $cs + $cc + $cg + $cp;

      $pos = 0.0; $neg = 0.0;
      foreach (array($cx,$cs,$cc,$cg,$cp) as $v) {
        if ($v > 0) $pos += $v; else $neg += $v;
      }

      $r['ois_zx'] = ($zx === null ? null : (float)$zx);
      $r['ois_zs'] = ($zs === null ? null : (float)$zs);
      $r['ois_zc'] = ($zc === null ? null : (float)$zc);
      $r['ois_zg'] = ($zg === null ? null : (float)$zg);
      $r['ois_zp'] = ($zp === null ? null : (float)$zp);

      $r['ois_weight'] = (float)$w;
      $r['ois_sumW']   = (float)$sumW;

      $r['ois_cx'] = (float)$cx;
      $r['ois_cs'] = (float)$cs;
      $r['ois_cc'] = (float)$cc;
      $r['ois_cg'] = (float)$cg;
      $r['ois_cp'] = (float)$cp;

      $r['ois_score']  = (float)$score;
      $r['ois_possum'] = (float)$pos;
      $r['ois_negsum'] = (float)$neg;

      $out[] = $r;
    }

    return $out;
  }
}
