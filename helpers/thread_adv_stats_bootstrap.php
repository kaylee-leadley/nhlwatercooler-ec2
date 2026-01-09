<?php
//======================================
// File: public/helpers/thread_adv_stats_bootstrap.php
// Description: Loads ALL Advanced Stats helpers and exposes:
//   - sjms_threads_adv_stats_html(PDO $pdo, int $gameId, array $opts = [])
//   - sjms_adv_chips_html(PDO $pdo, int $gameId, int $playerId)
// Depends on:
//   - /public/includes/msf_pbp_helpers.php
//   - /public/helpers/ois_helpers.php
//   - /public/helpers/adv_quadrant_helpers.php
//======================================

// PBP helpers
$__sjms_pbp_helpers = __DIR__ . '/../includes/msf_pbp_helpers.php';
if (is_file($__sjms_pbp_helpers)) require_once $__sjms_pbp_helpers;

// OIS helpers
$__sjms_ois_helpers = __DIR__ . '/ois_helpers.php';
if (is_file($__sjms_ois_helpers)) require_once $__sjms_ois_helpers;

// Quadrant helpers
$__sjms_adv_quadrant_helpers = __DIR__ . '/adv_quadrant_helpers.php';
if (is_file($__sjms_adv_quadrant_helpers)) require_once $__sjms_adv_quadrant_helpers;

// ---- Advanced stats modules (split) ----
// Adjust names to match whatever you actually create:
$mods = array(
  __DIR__ . '/thread_adv_stats_utils.php',
  __DIR__ . '/thread_adv_stats_readiness.php',
  __DIR__ . '/thread_adv_stats_db_switch.php',
  __DIR__ . '/thread_adv_stats_db.php',
  __DIR__ . '/thread_adv_stats_goalies.php',
  __DIR__ . '/thread_adv_stats_availability.php', 
  __DIR__ . '/thread_adv_stats_rates.php',        
  __DIR__ . '/thread_adv_stats_maps.php',
  __DIR__ . '/thread_adv_stats_garwar.php',
  __DIR__ . '/thread_adv_stats_chips.php',
  __DIR__ . '/thread_adv_stats_renderer.php',
);

foreach ($mods as $f) {
  if (is_file($f)) {
    error_log("[BOOT] loading $f");
    require_once $f;
  } else {
    error_log("[BOOT] MISSING $f");
  }
}

foreach ($mods as $f) {
  if (is_file($f)) require_once $f;
}
