<?php
// public/standings-api.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';

$viewParam = isset($_GET['view']) ? strtolower(trim($_GET['view'])) : 'wildcard';

/**
 * Lightweight team metadata – conference & division.
 */
$teamMeta = [
  'ANA' => ['conference' => 'WEST', 'division' => 'PAC'],
  'ARI' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'BOS' => ['conference' => 'EAST', 'division' => 'ATL'],
  'BUF' => ['conference' => 'EAST', 'division' => 'ATL'],
  'CAR' => ['conference' => 'EAST', 'division' => 'METRO'],
  'CBJ' => ['conference' => 'EAST', 'division' => 'METRO'],
  'CGY' => ['conference' => 'WEST', 'division' => 'PAC'],
  'CHI' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'COL' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'DAL' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'DET' => ['conference' => 'EAST', 'division' => 'ATL'],
  'EDM' => ['conference' => 'WEST', 'division' => 'PAC'],
  'FLO' => ['conference' => 'EAST', 'division' => 'ATL'],
  'LAK' => ['conference' => 'WEST', 'division' => 'PAC'],
  'MIN' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'MTL' => ['conference' => 'EAST', 'division' => 'ATL'],
  'NJD' => ['conference' => 'EAST', 'division' => 'METRO'],
  'NSH' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'NYI' => ['conference' => 'EAST', 'division' => 'METRO'],
  'NYR' => ['conference' => 'EAST', 'division' => 'METRO'],
  'OTT' => ['conference' => 'EAST', 'division' => 'ATL'],
  'PHI' => ['conference' => 'EAST', 'division' => 'METRO'],
  'PIT' => ['conference' => 'EAST', 'division' => 'METRO'],
  'SEA' => ['conference' => 'WEST', 'division' => 'PAC'],
  'SJS' => ['conference' => 'WEST', 'division' => 'PAC'],
  'STL' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'TBL' => ['conference' => 'EAST', 'division' => 'ATL'],
  'TOR' => ['conference' => 'EAST', 'division' => 'ATL'],
  'UTA' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'VAN' => ['conference' => 'WEST', 'division' => 'PAC'],
  'VGK' => ['conference' => 'WEST', 'division' => 'PAC'],
  'WPJ' => ['conference' => 'WEST', 'division' => 'CENTRAL'],
  'WSH' => ['conference' => 'EAST', 'division' => 'METRO'],
];

try {
  if (!($pdo instanceof PDO)) {
    throw new Exception('PDO connection not available');
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /**
   * Aggregate per team from msf_team_gamelogs
   *
   * Definitions:
   *  - wins        = ANY win (reg + OT + SO)
   *  - reg_losses  = regulation losses ONLY (no OT/SO)
   *  - otw/otl     = OT wins / losses flags from msf_team_gamelogs
   *  - sow/sol     = SO wins / losses flags from msf_team_gamelogs
   */
  $sql = "
    SELECT
      team_abbr,
      COUNT(*) AS games,
      SUM(goals_for)     AS gf,
      SUM(goals_against) AS ga,

      -- total wins (reg + OT + SO)
      SUM(
        CASE
          WHEN goals_for > goals_against
          THEN 1 ELSE 0
        END
      ) AS wins,

      -- regulation losses only (no OT/SO)
      SUM(
        CASE
          WHEN goals_for < goals_against
               AND ot_losses = 0
               AND so_losses = 0
          THEN 1 ELSE 0
        END
      ) AS reg_losses,

      -- OT / SO flags from the gamelog table
      SUM(ot_wins)   AS otw,
      SUM(ot_losses) AS otl,
      SUM(so_wins)   AS sow,
      SUM(so_losses) AS sol
    FROM msf_team_gamelogs
    GROUP BY team_abbr
  ";

  $stmt = $pdo->query($sql);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $teams = [];

  foreach ($rows as $row) {
    $abbr      = strtoupper($row['team_abbr']);
    $games     = (int)$row['games'];

    // Core record
    $wins      = (int)$row['wins'];        // ANY win (reg + OT + SO)
    $regLosses = (int)$row['reg_losses'];  // regulation losses only

    $otw       = (int)$row['otw'];         // OT wins
    $otl       = (int)$row['otl'];         // OT losses
    $sow       = (int)$row['sow'];         // SO wins
    $sol       = (int)$row['sol'];         // SO losses

    $gf        = (int)$row['gf'];
    $ga        = (int)$row['ga'];
    $diff      = $gf - $ga;

    // OTL column (display) = ONLY OT losses (no SOL)
    $otlDisplay = $otl;

    // NHL-style points: 2 points for ANY win, 1 point for OT or SO loss
    $points = 2 * $wins + $otl;

    // Points percentage – primary tiebreaker when points are equal
    $pointsPct = ($games > 0) ? $points / (2 * $games) : 0.0;

    // Tiebreakers:
    // RW (Regulation Wins) = total wins minus all OT/SO wins
    $regWins = max(0, $wins - $otw - $sow);

    // ROW (Regulation + OT Wins) = total wins minus shootout wins
    $regOtWins = max(0, $wins - $sow);

    $meta = $teamMeta[$abbr] ?? ['conference' => 'UNK', 'division' => 'UNK'];

    $teams[] = [
      'team'       => $abbr,
      'conference' => $meta['conference'],
      'division'   => $meta['division'],

      'games'      => $games,
      'wins'       => $wins,        // any win
      'losses'     => $regLosses,   // regulation losses only
      'otl'        => $otlDisplay,  // **OT losses only, NO SOL**

      'points'     => $points,
      'points_pct' => $pointsPct,

      'gf'         => $gf,
      'ga'         => $ga,
      'diff'       => $diff,

      // extra tiebreaker fields for the JS comparator
      'rw'         => $regWins,     // Regulation Wins
      'row'        => $regOtWins,   // Regulation + OT Wins (no SO)
      'otw'        => $otw,         // OT wins
      'sow'        => $sow,         // SO wins
      'sol'        => $sol,         // SO losses (kept for reference)
    ];
  }

  echo json_encode([
    'ok'    => true,
    'view'  => $viewParam,
    'count' => count($teams),
    'teams' => $teams,
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'      => false,
    'error'   => 'Database error',
    'message' => $e->getMessage(),
  ]);
  exit;
}
