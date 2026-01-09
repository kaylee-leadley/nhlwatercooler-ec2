<?php
//======================================
// File: public/helpers/thread_adv_stats_db.php
// Description: DB row fetch helpers.
// PHP 7+ friendly.
//======================================

if (!function_exists('sjms_adv_db_row')) {
  function sjms_adv_db_row(PDO $pdo, $gameId, $playerId, $stateKey = '5v5') {
    $gid = (int)$gameId;
    $pid = (int)$playerId;
    if ($gid <= 0 || $pid <= 0) return null;

    try {
      $st = $pdo->prepare("
        SELECT *
        FROM " . SJMS_ADV_DB_TABLE . "
        WHERE game_id = :gid AND player_id = :pid AND state_key = :sk
        LIMIT 1
      ");
      $st->execute(array(':gid' => $gid, ':pid' => $pid, ':sk' => (string)$stateKey));
      $r = $st->fetch(PDO::FETCH_ASSOC);
      return $r ? $r : null;
    } catch (Exception $e) {}

    return null;
  }
}