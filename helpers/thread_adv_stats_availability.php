<?php
//======================================
// File: public/helpers/thread_adv_stats_availability.php
// Description: "Is adv stats available for this game?"
// Provides:
//   - sjms_adv_available(PDO $pdo, int $gameId)
// Depends on:
//   - sjms_adv_use_db_fallback()
//   - sjms_adv_live_has_pbp()
//======================================

if (!function_exists('sjms_adv_available')) {
  function sjms_adv_available(PDO $pdo, $gameId) {
    $gid = (int)$gameId;
    if ($gid <= 0) return false;

    // Available if DB rows exist (old-game rule OR scrubbed fallback).
    if (function_exists('sjms_adv_use_db_fallback') && sjms_adv_use_db_fallback($pdo, $gid, '5v5')) {
      return true;
    }

    // Otherwise: live PBP must exist.
    if (function_exists('sjms_adv_live_has_pbp')) {
      return sjms_adv_live_has_pbp($pdo, $gid);
    }

    return false;
  }
}