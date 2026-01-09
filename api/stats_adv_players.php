<?php
// public/api/stats_adv_players.php
//
// Aggregate Advanced Stats into one row per player_id for cards/table.
// GET:
//   team=all|nyr|bos
//   state=5v5|ev|all|pp|sh   (ev maps to 5v5 db state_key)
//   season=2025-2026 (optional)
//   min_gp=0|5|10|15|20|25|30|40|50|60 (optional; 0 = no filter)
//   limit=50..5000 (optional)

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function qstr($k, $d='') { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function qint($k, $d=0)  { return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }

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

$table = 'nhl_players_advanced_stats';

$teamReq  = norm_team(qstr('team','all'));
$stateReq = strtolower(trim(qstr('state','5v5')));
$stateDb  = norm_state_for_db($stateReq);

$season = qstr('season','');
if ($season !== '' && !preg_match('/^\d{4}-\d{4}$/', $season)) $season = '';

$allowedGp = [0,5,10,15,20,25,30,40,50,60];
$minGp = qint('min_gp', 0);
if (!in_array($minGp, $allowedGp, true)) $minGp = 0;

$limit = qint('limit', 2000);
if ($limit < 50) $limit = 50;
if ($limit > 5000) $limit = 5000;

try {
  $where = [];
  $params = [];

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

  $where[] = "a.player_id IS NOT NULL AND a.player_id > 0";
  $whereSql = "WHERE " . implode(" AND ", $where);

  $havingSql = "";
  if ($minGp > 0) {
    $havingSql = "HAVING COUNT(DISTINCT a.game_id) >= :min_gp";
    $params[':min_gp'] = $minGp;
  }

  $sql = "
WITH names AS (
  SELECT
    l.player_id,
    MAX(l.first_name) AS first_name,
    MAX(l.last_name)  AS last_name
  FROM lineups l
  WHERE l.player_id IS NOT NULL
  GROUP BY l.player_id
)
SELECT
  a.player_id,

  MAX(a.team_abbr)  AS team_abbr,
  MAX(a.player_pos) AS player_pos,

  MAX(p.official_image_src) AS official_image_src,
  MAX(p.jersey_number)      AS jersey_number,

  COUNT(DISTINCT a.game_id) AS gp,
  SUM(a.toi_used)           AS toi_used,

  -- rates (per 60; toi_used seconds)
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.CF)  * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS cf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.CA)  * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS ca60,
  ROUND(CASE WHEN (SUM(a.CF) + SUM(a.CA)) > 0 THEN (SUM(a.CF) * 100.0 / (SUM(a.CF) + SUM(a.CA))) ELSE NULL END, 1) AS cfpct,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.xGF) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS xgf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.xGA) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS xga60,
  ROUND(CASE WHEN (SUM(a.xGF) + SUM(a.xGA)) > 0 THEN (SUM(a.xGF) * 100.0 / (SUM(a.xGF) + SUM(a.xGA))) ELSE NULL END, 1) AS xgfpct,

  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.SCF)  * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS scf60,
  ROUND(CASE WHEN SUM(a.toi_used) > 0 THEN (SUM(a.HDCF) * 3600.0 / SUM(a.toi_used)) ELSE NULL END, 2) AS hdcf60,

  ROUND(SUM(a.gar_total), 3) AS gar_total,
  ROUND(SUM(a.war_total), 3) AS war_total,

  TRIM(CONCAT(
    COALESCE(p.first_name, n.first_name, ''),
    ' ',
    COALESCE(p.last_name,  n.last_name,  '')
  )) AS player_name

FROM {$table} a
LEFT JOIN names n       ON n.player_id = a.player_id
LEFT JOIN msf_players p ON p.player_id = a.player_id

{$whereSql}
GROUP BY a.player_id
{$havingSql}
ORDER BY gp DESC, toi_used DESC
LIMIT {$limit}
  ";

  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) {
    $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok' => true,
    'rows' => $rows,
    'meta' => [
      'team' => $teamReq,
      'state' => $stateReq,
      'state_db' => $stateDb,
      'season' => $season,
      'min_gp' => $minGp,
      'limit' => $limit,
    ],
  ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'adv_stats_players query failed',
    'detail' => $e->getMessage(),
  ], JSON_UNESCAPED_SLASHES);
}
