<?php
//======================================
// File: public/api/thread_adv_stats_block.php
// Description: AJAX block loader for Advanced Stats (HTML snippet).
//
// Loads bootstrap (all split modules), renders HTML, disk-caches for short TTL,
// and supports ?debug=1 to emit server-side debug logs + bypass cache.
//======================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';

// ------------------------------------------------------------
// Canonical loader: bootstrap (loads all split modules)
// ------------------------------------------------------------
$bootstrap = __DIR__ . '/../helpers/thread_adv_stats_bootstrap.php';
if (!is_file($bootstrap)) {
  http_response_code(500);
  exit('Missing bootstrap: public/helpers/thread_adv_stats_bootstrap.php');
}
require_once $bootstrap;

if (!function_exists('sjms_threads_adv_stats_html')) {
  http_response_code(500);
  exit('Bootstrap loaded but sjms_threads_adv_stats_html() not found');
}

$gid = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
if ($gid <= 0) {
  http_response_code(400);
  exit('');
}

header('Content-Type: text/html; charset=utf-8');

// ------------------------------------------------------------
// Debug toggle (querystring)
// ------------------------------------------------------------
$debug = isset($_GET['debug']) && $_GET['debug'] !== '0' && $_GET['debug'] !== 'false';
if ($debug) {
  header('X-AdvStats-Debug: 1');
}

// ------------------------------------------------------------
// Cache config
// ------------------------------------------------------------
$cacheDir = __DIR__ . '/../../cache/advstats';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/game_' . $gid . '.html';
$ttl = 120;

// If the client is retrying with a cache-buster, bypass disk-cache reads.
$forceFresh = isset($_GET['_r']) || isset($_GET['nocache']);

// In debug mode, ALWAYS bypass disk-cache reads/writes (and discourage proxy caching)
if ($debug) {
  $forceFresh = true;
  header('Cache-Control: no-store, max-age=0');
  header('Pragma: no-cache');
}

// Serve cache
if (!$forceFresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
  header('X-AdvStats-Cache: hit');
  header('X-AdvStats-HTML-Len: ' . (int)filesize($cacheFile));
  readfile($cacheFile);
  exit;
}

// ------------------------------------------------------------
// Render
// ------------------------------------------------------------
$html = sjms_threads_adv_stats_html($pdo, $gid, array(
  'title'         => 'Advanced Stats',
  'show_quadrant' => true,
  'debug'         => $debug,   // <-- IMPORTANT: enables renderer-side debug logging
));

header('X-AdvStats-HTML-Len: ' . strlen((string)$html));

// ------------------------------------------------------------
// Cache only if it looks "real" (prevents caching empty shells)
// ------------------------------------------------------------
function sjms_advstats_cacheworthy($html) {
  if ($html === null) return false;

  $s = trim((string)$html);
  if ($s === '') return false;

  // Too small is almost always "not ready yet"
  if (strlen($s) < 200) return false;

  // Must contain at least ONE real marker.
  $markers = array(
    'adv-team',
    'adv-chart',
    'js-adv-impactscore',
    'js-xgq-svg',
    'js-gar',
    'js-war',
    'adv-block--quadrant',
  );

  foreach ($markers as $m) {
    if (strpos($s, $m) !== false) return true;
  }

  return false;
}

// Write cache ONLY when not debugging
if (!$debug && sjms_advstats_cacheworthy($html)) {
  @file_put_contents($cacheFile, $html, LOCK_EX);
  header('X-AdvStats-Cache: write');
} else if (!$debug) {
  // IMPORTANT: do not cache empties, and don’t let proxies cache it either
  header('X-AdvStats-Cache: skip-empty');
  header('Cache-Control: no-store, max-age=0');
  header('Pragma: no-cache');
}

echo $html;
