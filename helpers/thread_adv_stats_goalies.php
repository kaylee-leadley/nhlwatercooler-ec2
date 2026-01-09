<?php
//======================================
// File: public/helpers/thread_adv_stats_goalies.php
// Description: Goalie detection + team roster row helpers.
// PHP 7+ friendly.
//======================================

if (!function_exists('sjms_adv_goalie_ids_for_game')) {
  function sjms_adv_goalie_ids_for_game(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return array();

    $goalieIds = array();
    try {
      $st = $pdo->prepare("
        SELECT player_id, lineup_position, player_position
        FROM lineups
        WHERE game_id = :gid
          AND player_id IS NOT NULL
      ");
      $st->execute(array(':gid' => $gid));
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)($r['player_id'] ?? 0);
        if ($pid <= 0) continue;

        $lp = (string)($r['lineup_position'] ?? '');
        $pp = strtoupper(trim((string)($r['player_position'] ?? '')));

        if (strpos($lp, 'Goalie') === 0 || $pp === 'G') {
          $goalieIds[$pid] = true;
        }
      }
    } catch (Exception $e) {}

    return $goalieIds;
  }
}

if (!function_exists('sjms_adv_team_player_rows')) {
  function sjms_adv_team_player_rows(PDO $pdo, $gameId, $teamAbbr) {
    $gid = (int)$gameId;
    $abbr = strtoupper(trim((string)$teamAbbr));
    if ($gid <= 0 || $abbr === '') return array();

    try {
      $st = $pdo->prepare("
        SELECT DISTINCT player_id, first_name, last_name, player_position
        FROM lineups
        WHERE game_id = :gid
          AND team_abbr = :abbr
          AND player_id IS NOT NULL
        ORDER BY player_position, last_name, first_name
      ");
      $st->execute(array(':gid' => $gid, ':abbr' => $abbr));
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
      return array();
    }
  }
}