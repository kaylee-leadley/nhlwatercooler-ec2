<?php
//======================================
// File: public/api/overall_impact_score.php
// Description: Canonical “Overall Impact Score” API endpoint.
// Use for:
//   - game thread (per-game impact rows)
//   - stats_adv.php (multi-game average impact rows)
//
// GET params (common):
//   state_key=5v5        (default: 5v5)
//
// Game mode:
//   game_id=151400
//
// Aggregate mode:
//   team=all|nyr|bos
//   season=2025-2026     (optional)
//   limit=500            (optional)
//
// Notes:
// - Requires MySQL 8+ (CTEs + window functions).
// - Reads from nhl_players_advanced_stats (your computed table).
// - Joins lineups (game mode) for names/pos/team if available.
// - Weights + TOI_REF sourced from public/helpers/ois_helpers.php
//======================================

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

// OIS helpers (weights + TOI_REF + title)
$__sjms_ois_helpers = __DIR__ . '/../helpers/ois_helpers.php';
if (is_file($__sjms_ois_helpers)) {
  require_once $__sjms_ois_helpers;
}

header('Content-Type: application/json; charset=utf-8');

function qstr(string $k, string $d = ''): string {
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}
function qint(string $k, int $d = 0): int {
  $v = isset($_GET[$k]) ? (int)$_GET[$k] : $d;
  return $v;
}
function norm_team(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/i', '', $s);
  if ($s === '' || $s === 'all' || $s === 'allteams' || $s === 'all_teams') return 'all';
  return $s;
}
function norm_state(string $s): string {
  $s = trim($s);
  return ($s === '') ? '5v5' : $s;
}

$tbl = 'nhl_players_advanced_stats';

$gameId   = qint('game_id', 0);
$team     = norm_team(qstr('team', 'all'));
$season   = qstr('season', '');
$stateKey = norm_state(qstr('state_key', '5v5'));
$limit    = qint('limit', 500);
if ($limit < 10) $limit = 10;
if ($limit > 2000) $limit = 2000;

// Canonical OIS config
$ois = function_exists('ois_defaults') ? ois_defaults() : [
  'w_xg' => 0.45, 'w_sc' => 0.25, 'w_cf' => 0.20, 'w_g' => 0.05, 'w_pen' => 0.05,
  'toi_ref' => 600,
];

$W_XG   = (float)($ois['w_xg'] ?? 0.45);
$W_SC   = (float)($ois['w_sc'] ?? 0.25);
$W_CF   = (float)($ois['w_cf'] ?? 0.20);
$W_G    = (float)($ois['w_g']  ?? 0.05);
$W_PEN  = (float)($ois['w_pen']?? 0.05);

$TOI_REF = (int)($ois['toi_ref'] ?? 600);
$MIN_TOI = 0; // raise if you want to prune tiny samples globally

try {

  // ------------------------------------------------------------
  // GAME MODE: ?game_id=...
  // ------------------------------------------------------------
  if ($gameId > 0) {

    // We bring in names/pos/team from lineups if available.
    // lineups may have duplicates; aggregate to 1 row per player_id.
    $sql = "
WITH base AS (
  SELECT
    a.game_id,
    a.player_id,
    a.team_abbr,
    a.player_pos,
    a.toi_used,

    COALESCE(a.xGDIFF_60, (a.xGF_60 - a.xGA_60)) AS xgd60,
    COALESCE(a.SCDIFF_60, (a.SCF_60 - a.SCA_60)) AS scd60,
    COALESCE(a.CDIFF_60, (a.CF_60  - a.CA_60 )) AS cfd60,
    COALESCE(a.GDIFF_60, (a.GF_60  - a.GA_60 )) AS gd60,

    CASE WHEN a.toi_used > 0 THEN (a.pen_diff * 3600.0 / a.toi_used) ELSE NULL END AS pd60,

    a.pen_taken,
    a.pen_drawn,
    a.pen_diff
  FROM {$tbl} a
  WHERE a.game_id = :gid
    AND a.state_key = :state_key
    AND a.toi_used >= :min_toi
),
names AS (
  SELECT
    l.player_id,
    MAX(l.first_name) AS first_name,
    MAX(l.last_name)  AS last_name,
    MAX(l.team_abbr)  AS team_abbr_lu,
    MAX(l.player_position) AS pos_lu
  FROM lineups l
  WHERE l.game_id = :gid
    AND l.player_id IS NOT NULL
  GROUP BY l.player_id
),
norm AS (
  SELECT
    b.*,

    AVG(b.xgd60) OVER () AS mu_xgd,
    NULLIF(STDDEV_SAMP(b.xgd60) OVER (), 0) AS sd_xgd,

    AVG(b.scd60) OVER () AS mu_scd,
    NULLIF(STDDEV_SAMP(b.scd60) OVER (), 0) AS sd_scd,

    AVG(b.cfd60) OVER () AS mu_cfd,
    NULLIF(STDDEV_SAMP(b.cfd60) OVER (), 0) AS sd_cfd,

    AVG(b.gd60) OVER ()  AS mu_gd,
    NULLIF(STDDEV_SAMP(b.gd60) OVER (), 0)  AS sd_gd,

    AVG(b.pd60) OVER ()  AS mu_pd,
    NULLIF(STDDEV_SAMP(b.pd60) OVER (), 0)  AS sd_pd
  FROM base b
),
scored AS (
  SELECT
    n.*,

    CASE WHEN n.sd_xgd IS NULL OR n.xgd60 IS NULL THEN NULL ELSE (n.xgd60 - n.mu_xgd) / n.sd_xgd END AS zx,
    CASE WHEN n.sd_scd IS NULL OR n.scd60 IS NULL THEN NULL ELSE (n.scd60 - n.mu_scd) / n.sd_scd END AS zs,
    CASE WHEN n.sd_cfd IS NULL OR n.cfd60 IS NULL THEN NULL ELSE (n.cfd60 - n.mu_cfd) / n.sd_cfd END AS zc,
    CASE WHEN n.sd_gd  IS NULL OR n.gd60  IS NULL THEN NULL ELSE (n.gd60  - n.mu_gd ) / n.sd_gd  END AS zg,
    CASE WHEN n.sd_pd  IS NULL OR n.pd60  IS NULL THEN NULL ELSE (n.pd60  - n.mu_pd ) / n.sd_pd  END AS zp,

    LEAST(1.20, GREATEST(0.55, SQRT(GREATEST(0, n.toi_used) / :toi_ref))) AS toi_w
  FROM norm n
),
impact AS (
  SELECT
    s.*,

    (
      (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
      (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
      (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
      (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
      (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
    ) AS sumW,

    (
      s.toi_w * (
        (CASE WHEN s.zx IS NULL THEN 0 ELSE (:w_xg  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zx END) +

        (CASE WHEN s.zs IS NULL THEN 0 ELSE (:w_sc  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zs END) +

        (CASE WHEN s.zc IS NULL THEN 0 ELSE (:w_cf  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zc END) +

        (CASE WHEN s.zg IS NULL THEN 0 ELSE (:w_g   / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zg END) +

        (CASE WHEN s.zp IS NULL THEN 0 ELSE (:w_pen / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zp END)
      )
    ) AS impact_score
  FROM scored s
)
SELECT
  i.player_id,

  -- prefer lineups name when available
  TRIM(CONCAT(COALESCE(n.first_name,''),' ',COALESCE(n.last_name,''))) AS name,

  COALESCE(n.team_abbr_lu, i.team_abbr) AS team,
  COALESCE(n.pos_lu, i.player_pos) AS pos,

  i.toi_used AS toi_5v5,

  -- expose per-60 inputs using the same names your JS already expects
  ROUND(i.xgd60, 2) AS xgdiff60_5v5,
  ROUND(i.scd60, 2) AS scdiff60_5v5,
  ROUND(i.cfd60, 2) AS cfdiff60_5v5,
  ROUND(i.gd60,  2) AS gdiff60_5v5,

  i.pen_taken AS pen_taken_5v5,
  i.pen_drawn AS pen_drawn_5v5,
  i.pen_diff  AS pen_diff_5v5,
  ROUND(i.pd60, 2) AS pendiff60_5v5,

  ROUND(i.impact_score, 3) AS impact_score
FROM impact i
LEFT JOIN names n ON n.player_id = i.player_id
ORDER BY impact_score DESC, toi_5v5 DESC
LIMIT :lim
";

    $st = $pdo->prepare($sql);

    // MySQL requires binding LIMIT as int with PDO::PARAM_INT
    $st->bindValue(':gid', $gameId, PDO::PARAM_INT);
    $st->bindValue(':state_key', $stateKey, PDO::PARAM_STR);
    $st->bindValue(':min_toi', $MIN_TOI, PDO::PARAM_INT);

    $st->bindValue(':w_xg',  $W_XG);
    $st->bindValue(':w_sc',  $W_SC);
    $st->bindValue(':w_cf',  $W_CF);
    $st->bindValue(':w_g',   $W_G);
    $st->bindValue(':w_pen', $W_PEN);
    $st->bindValue(':toi_ref', $TOI_REF, PDO::PARAM_INT);

    $st->bindValue(':lim', $limit, PDO::PARAM_INT);

    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
      'ok' => true,
      'mode' => 'game',
      'game_id' => $gameId,
      'state_key' => $stateKey,
      'cfg' => [
        'w_xg' => $W_XG, 'w_sc' => $W_SC, 'w_cf' => $W_CF, 'w_g' => $W_G, 'w_pen' => $W_PEN,
        'toi_ref' => $TOI_REF,
        'min_toi' => $MIN_TOI,
      ],
      'rows' => $rows
    ], JSON_UNESCAPED_SLASHES);

    exit;
  }

  // ------------------------------------------------------------
  // AGG MODE: no game_id -> compute per-game impact then AVG()
  // ------------------------------------------------------------
  $where = [];
  $params = [];

  $where[] = "a.state_key = :state_key";
  $params[':state_key'] = $stateKey;

  if ($season !== '') {
    $where[] = "a.season = :season";
    $params[':season'] = $season;
  }

  if ($team !== 'all') {
    $where[] = "LOWER(a.team_abbr) = :team";
    $params[':team'] = $team;
  }

  $where[] = "a.toi_used >= :min_toi";
  $params[':min_toi'] = $MIN_TOI;

  $whereSql = "WHERE " . implode(" AND ", $where);

  $sql = "
WITH base AS (
  SELECT
    a.game_id,
    a.player_id,
    a.team_abbr,
    a.player_pos,
    a.toi_used,

    COALESCE(a.xGDIFF_60, (a.xGF_60 - a.xGA_60)) AS xgd60,
    COALESCE(a.SCDIFF_60, (a.SCF_60 - a.SCA_60)) AS scd60,
    COALESCE(a.CDIFF_60, (a.CF_60  - a.CA_60 )) AS cfd60,
    COALESCE(a.GDIFF_60, (a.GF_60  - a.GA_60 )) AS gd60,

    CASE WHEN a.toi_used > 0 THEN (a.pen_diff * 3600.0 / a.toi_used) ELSE NULL END AS pd60,

    a.pen_taken,
    a.pen_drawn,
    a.pen_diff,

    a.CF, a.CA, a.xGF, a.xGA, a.SCF, a.SCA, a.GF, a.GA
  FROM {$tbl} a
  {$whereSql}
),
norm AS (
  SELECT
    b.*,

    AVG(b.xgd60) OVER (PARTITION BY b.game_id) AS mu_xgd,
    NULLIF(STDDEV_SAMP(b.xgd60) OVER (PARTITION BY b.game_id), 0) AS sd_xgd,

    AVG(b.scd60) OVER (PARTITION BY b.game_id) AS mu_scd,
    NULLIF(STDDEV_SAMP(b.scd60) OVER (PARTITION BY b.game_id), 0) AS sd_scd,

    AVG(b.cfd60) OVER (PARTITION BY b.game_id) AS mu_cfd,
    NULLIF(STDDEV_SAMP(b.cfd60) OVER (PARTITION BY b.game_id), 0) AS sd_cfd,

    AVG(b.gd60)  OVER (PARTITION BY b.game_id) AS mu_gd,
    NULLIF(STDDEV_SAMP(b.gd60)  OVER (PARTITION BY b.game_id), 0) AS sd_gd,

    AVG(b.pd60)  OVER (PARTITION BY b.game_id) AS mu_pd,
    NULLIF(STDDEV_SAMP(b.pd60)  OVER (PARTITION BY b.game_id), 0) AS sd_pd
  FROM base b
),
scored AS (
  SELECT
    n.*,

    CASE WHEN n.sd_xgd IS NULL OR n.xgd60 IS NULL THEN NULL ELSE (n.xgd60 - n.mu_xgd) / n.sd_xgd END AS zx,
    CASE WHEN n.sd_scd IS NULL OR n.scd60 IS NULL THEN NULL ELSE (n.scd60 - n.mu_scd) / n.sd_scd END AS zs,
    CASE WHEN n.sd_cfd IS NULL OR n.cfd60 IS NULL THEN NULL ELSE (n.cfd60 - n.mu_cfd) / n.sd_cfd END AS zc,
    CASE WHEN n.sd_gd  IS NULL OR n.gd60  IS NULL THEN NULL ELSE (n.gd60  - n.mu_gd ) / n.sd_gd  END AS zg,
    CASE WHEN n.sd_pd  IS NULL OR n.pd60  IS NULL THEN NULL ELSE (n.pd60  - n.mu_pd ) / n.sd_pd  END AS zp,

    LEAST(1.20, GREATEST(0.55, SQRT(GREATEST(0, n.toi_used) / :toi_ref))) AS toi_w
  FROM norm n
),
impact AS (
  SELECT
    s.*,

    (
      (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
      (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
      (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
      (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
      (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
    ) AS sumW,

    (
      s.toi_w * (
        (CASE WHEN s.zx IS NULL THEN 0 ELSE (:w_xg  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zx END) +

        (CASE WHEN s.zs IS NULL THEN 0 ELSE (:w_sc  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zs END) +

        (CASE WHEN s.zc IS NULL THEN 0 ELSE (:w_cf  / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zc END) +

        (CASE WHEN s.zg IS NULL THEN 0 ELSE (:w_g   / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zg END) +

        (CASE WHEN s.zp IS NULL THEN 0 ELSE (:w_pen / NULLIF((
          (CASE WHEN s.zx IS NULL THEN 0 ELSE :w_xg  END) +
          (CASE WHEN s.zs IS NULL THEN 0 ELSE :w_sc  END) +
          (CASE WHEN s.zc IS NULL THEN 0 ELSE :w_cf  END) +
          (CASE WHEN s.zg IS NULL THEN 0 ELSE :w_g   END) +
          (CASE WHEN s.zp IS NULL THEN 0 ELSE :w_pen END)
        ),0)) * s.zp END)
      )
    ) AS impact_score
  FROM scored s
)
SELECT
  i.player_id,
  MAX(i.team_abbr) AS team,
  MAX(i.player_pos) AS pos,

  COUNT(DISTINCT i.game_id) AS gp,
  SUM(i.toi_used) AS toi_5v5,

  -- totals (optional; handy columns for table)
  SUM(i.CF) AS CF, SUM(i.CA) AS CA,
  SUM(i.xGF) AS xGF, SUM(i.xGA) AS xGA,
  SUM(i.SCF) AS SCF, SUM(i.SCA) AS SCA,
  SUM(i.GF) AS GF, SUM(i.GA) AS GA,

  ROUND(AVG(i.impact_score), 3) AS impact_avg,

  ROUND(
    CASE WHEN SUM(i.toi_used) > 0
      THEN SUM(i.impact_score * i.toi_used) / SUM(i.toi_used)
      ELSE AVG(i.impact_score)
    END
  , 3) AS impact_wavg

FROM impact i
GROUP BY i.player_id
ORDER BY impact_avg DESC, toi_5v5 DESC
LIMIT :lim
";

  $st = $pdo->prepare($sql);

  // bind filters
  foreach ($params as $k => $v) {
    $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }

  // bind weights
  $st->bindValue(':w_xg',  $W_XG);
  $st->bindValue(':w_sc',  $W_SC);
  $st->bindValue(':w_cf',  $W_CF);
  $st->bindValue(':w_g',   $W_G);
  $st->bindValue(':w_pen', $W_PEN);
  $st->bindValue(':toi_ref', $TOI_REF, PDO::PARAM_INT);

  $st->bindValue(':lim', $limit, PDO::PARAM_INT);

  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok' => true,
    'mode' => 'agg',
    'state_key' => $stateKey,
    'team' => $team,
    'season' => $season,
    'cfg' => [
      'w_xg' => $W_XG, 'w_sc' => $W_SC, 'w_cf' => $W_CF, 'w_g' => $W_G, 'w_pen' => $W_PEN,
      'toi_ref' => $TOI_REF,
      'min_toi' => $MIN_TOI,
    ],
    'rows' => $rows
  ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'overall impact query failed'
  ], JSON_UNESCAPED_SLASHES);
}
