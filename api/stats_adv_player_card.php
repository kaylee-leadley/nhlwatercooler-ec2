<?php
// public/api/stats_adv_player_card.php
//
// "More stats" content for Advanced Stats cards.
// Returns HTML fragment (NOT full card header).
//
// GET:
//   id=PLAYER_ID (required)
//   team=all|bos|nyr (optional)
//   state=5v5|ev|all|pp|sh
//   season=2025-2026 (optional)

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/html; charset=utf-8');

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function qstr($k, $d='') { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

function norm_team($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace('/[^a-z0-9]+/i', '', $s);
  if ($s === '' || $s === 'all' || $s === 'allteams' || $s === 'all_teams') return 'all';
  return $s;
}

function norm_state_for_db($s) {
  $s = strtolower(trim((string)$s));
  if ($s === '') return '5v5';
  if ($s === 'ev') return '5v5';
  if (!in_array($s, ['5v5','all','pp','sh'], true)) return '5v5';
  return $s;
}

$playerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($playerId <= 0) {
  http_response_code(400);
  echo '<div class="adv-more__title">More stats</div><div>Missing player id.</div>';
  exit;
}

$table = 'nhl_players_advanced_stats';

$teamReq  = norm_team(qstr('team','all'));
$stateReq = strtolower(trim(qstr('state','5v5')));
$stateDb  = norm_state_for_db($stateReq);

$season = qstr('season','');
if ($season !== '' && !preg_match('/^\d{4}-\d{4}$/', $season)) $season = '';

try {
  $where = [];
  $params = [];

  $where[] = "a.player_id = :pid";
  $params[':pid'] = $playerId;

  $where[] = "a.state_key = :state_key";
  $params[':state_key'] = $stateDb;

  if ($season !== '') {
    $where[] = "a.season = :season";
    $params[':season'] = $season;
  }

  if ($teamReq !== 'all') {
    $where[] = "LOWER(a.team_abbr) = :team";
    $params[':team'] = $teamReq;
  }

  $where[] = "a.toi_used IS NOT NULL AND a.toi_used > 0";

  $whereSql = "WHERE " . implode(" AND ", $where);

  $sql = "
SELECT
  COUNT(DISTINCT a.game_id) AS gp,
  SUM(a.toi_used) AS toi_used,

  SUM(a.CF)  AS CF,  SUM(a.CA)  AS CA,
  SUM(a.GF)  AS GF,  SUM(a.GA)  AS GA,

  SUM(a.xGF) AS xGF, SUM(a.xGA) AS xGA,

  SUM(a.SCF) AS SCF, SUM(a.SCA) AS SCA,
  SUM(a.HDCF) AS HDCF, SUM(a.HDCA) AS HDCA,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.CF)  * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS cf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.CA)  * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS ca60,
  ROUND(CASE WHEN (SUM(a.CF) + SUM(a.CA)) > 0 THEN (SUM(a.CF) * 100.0 / (SUM(a.CF) + SUM(a.CA))) ELSE NULL END, 1) AS cfpct,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.xGF) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS xgf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.xGA) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS xga60,
  ROUND(CASE WHEN (SUM(a.xGF) + SUM(a.xGA)) > 0 THEN (SUM(a.xGF) * 100.0 / (SUM(a.xGF) + SUM(a.xGA))) ELSE NULL END, 1) AS xgfpct,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.SCF) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS scf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.SCA) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS sca60,
  ROUND(CASE WHEN (SUM(a.SCF) + SUM(a.SCA)) > 0 THEN (SUM(a.SCF) * 100.0 / (SUM(a.SCF) + SUM(a.SCA))) ELSE NULL END, 1) AS scfpct,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.HDCF) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS hdcf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.HDCA) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS hdca60,
  ROUND(CASE WHEN (SUM(a.HDCF) + SUM(a.HDCA)) > 0 THEN (SUM(a.HDCF) * 100.0 / (SUM(a.HDCF) + SUM(a.HDCA))) ELSE NULL END, 1) AS hdcfpct
FROM {$table} a
{$whereSql}
LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) {
    $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->execute();
  $r = $st->fetch(PDO::FETCH_ASSOC);

  if (!$r || (int)($r['gp'] ?? 0) === 0) {
    echo '<div class="adv-more__title">More stats</div><div>No additional stats available.</div>';
    exit;
  }

  $fmt = function($v, $dec=2) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return number_format((float)$v, $dec, '.', '');
  };
  $fmt1 = function($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return number_format((float)$v, 1, '.', '');
  };

  ?>
  <div class="adv-more__title">More stats</div>

  <div class="player-card__body">
    <div class="player-card__row"><span class="label">CA/60</span><span class="value"><?= h($fmt($r['ca60'] ?? null, 2)) ?></span></div>
    <div class="player-card__row"><span class="label">xGA/60</span><span class="value"><?= h($fmt($r['xga60'] ?? null, 2)) ?></span></div>

    <div class="player-card__row"><span class="label">SCF%</span><span class="value"><?= h($fmt1($r['scfpct'] ?? null)) ?></span></div>
    <div class="player-card__row"><span class="label">SCA/60</span><span class="value"><?= h($fmt($r['sca60'] ?? null, 2)) ?></span></div>

    <div class="player-card__row"><span class="label">HDCF%</span><span class="value"><?= h($fmt1($r['hdcfpct'] ?? null)) ?></span></div>
    <div class="player-card__row"><span class="label">HDCA/60</span><span class="value"><?= h($fmt($r['hdca60'] ?? null, 2)) ?></span></div>

    <div class="player-card__row"><span class="label">xGF/60</span><span class="value"><?= h($fmt($r['xgf60'] ?? null, 2)) ?></span></div>
    <div class="player-card__row"><span class="label">CF/60</span><span class="value"><?= h($fmt($r['cf60'] ?? null, 2)) ?></span></div>
  </div>

  <div class="adv-more__title" style="margin-top:12px;">Totals</div>
  <div class="player-card__body">
    <div class="player-card__row"><span class="label">CF</span><span class="value"><?= h((string)($r['CF'] ?? '—')) ?></span></div>
    <div class="player-card__row"><span class="label">CA</span><span class="value"><?= h((string)($r['CA'] ?? '—')) ?></span></div>

    <div class="player-card__row"><span class="label">xGF</span><span class="value"><?= h($fmt($r['xGF'] ?? null, 2)) ?></span></div>
    <div class="player-card__row"><span class="label">xGA</span><span class="value"><?= h($fmt($r['xGA'] ?? null, 2)) ?></span></div>

    <div class="player-card__row"><span class="label">SCF</span><span class="value"><?= h((string)($r['SCF'] ?? '—')) ?></span></div>
    <div class="player-card__row"><span class="label">SCA</span><span class="value"><?= h((string)($r['SCA'] ?? '—')) ?></span></div>

    <div class="player-card__row"><span class="label">HDCF</span><span class="value"><?= h((string)($r['HDCF'] ?? '—')) ?></span></div>
    <div class="player-card__row"><span class="label">HDCA</span><span class="value"><?= h((string)($r['HDCA'] ?? '—')) ?></span></div>

    <div class="player-card__row"><span class="label">GF</span><span class="value"><?= h((string)($r['GF'] ?? '—')) ?></span></div>
    <div class="player-card__row"><span class="label">GA</span><span class="value"><?= h((string)($r['GA'] ?? '—')) ?></span></div>
  </div>
  <?php

} catch (Exception $e) {
  http_response_code(500);
  echo '<div class="adv-more__title">More stats</div><div>Error loading additional stats.</div>';
}
