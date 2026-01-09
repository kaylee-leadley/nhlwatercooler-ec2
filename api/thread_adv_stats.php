<?php
//======================================
// File: public/api/thread_adv_stats.php
// Description: Canonical Advanced Stats entrypoint.
// Keeps backwards-compatible include path for API callers.
//======================================

$boot = __DIR__ . '/../helpers/thread_adv_stats_bootstrap.php';
if (!is_file($boot)) {
  // Fail loudly in logs, but don't fatal on prod pages that can survive without it.
  error_log('[ADV] Missing bootstrap: ' . $boot);
} else {
  require_once $boot;
}
