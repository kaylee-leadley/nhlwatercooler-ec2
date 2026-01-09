<?php
// public/stats-api.php

// Turn on error reporting while we debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php'; 

$table = 'msf_player_gamelogs';

// Read team code from query (?team=sjs or ?team=all)
$teamParam = isset($_GET['team']) ? trim($_GET['team']) : 'all';
$teamParam = strtolower($teamParam);
if ($teamParam === '' || $teamParam === 'all_teams') {
    $teamParam = 'all';
}
$team = strtoupper($teamParam);

try {
    if (!($pdo instanceof PDO)) {
        throw new Exception('PDO connection not available');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Base query
    $sql = "
      SELECT
        team_abbr      AS team,
        player_id,
        first_name,
        last_name,
        position,
        jersey_number,
        COUNT(DISTINCT msf_game_id)        AS games,
        SUM(goals)                         AS goals,
        SUM(assists)                       AS assists,
        SUM(points)                        AS points,
        SUM(shots)                         AS shots,
        SUM(pim)                           AS pim
      FROM {$table}
    ";

    $params = [];

    if ($team !== 'ALL') {
        $sql .= " WHERE team_abbr = :team";
        $params[':team'] = $team;
    }

    $sql .= "
      GROUP BY
        team_abbr,
        player_id,
        first_name,
        last_name,
        position,
        jersey_number
      HAVING games > 0
      ORDER BY points DESC, goals DESC, shots DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'    => true,
        'team'  => $team,
        'count' => count($rows),
        'stats' => $rows,
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
