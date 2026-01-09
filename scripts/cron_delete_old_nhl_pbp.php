<?php
// public/scripts/cron_delete_old_nhl_pbp.php
//
// Purge old NHL play-by-play “live” tables (msf_live_*).
//
// MODE A (retention cutoff, default):
//   Deletes any records for games whose msf_live_game.start_time_utc is older than N days.
//
// MODE B (date range, inclusive UTC calendar days):
//   Deletes games whose msf_live_game.start_time_utc falls within a UTC date range.
//
// Defaults:
//   - days  = 10
//   - limit = 200 games per run
//
// Usage:
//   php cron_delete_old_nhl_pbp.php
//   php cron_delete_old_nhl_pbp.php --days=10 --limit=200
//   php cron_delete_old_nhl_pbp.php --dry-run --verbose --days=10 --limit=10
//   php cron_delete_old_nhl_pbp.php --errorlog --verbose
//
// Date range (UTC calendar days):
//   php cron_delete_old_nhl_pbp.php --from=2025-10-08 --to=2025-10-31 --verbose
//   php cron_delete_old_nhl_pbp.php --from=2025-10-08 --to=2025-10-31 --limit=500 --dry-run --verbose
//
// Safety:
// - In retention mode, we will NOT delete anything newer than 7 days by default.
//   If you *really* want a smaller window, pass --force-recent.
// - In range mode, if your range touches the most recent 7 days, we refuse unless --force-recent.
//
// Logging:
// - Always appends to: /var/www/nhlwatercooler/public/scripts/gameday-logs/pbp_purge.log
// - In CLI, also echoes to stdout unless you pass --quiet
// - If you pass --errorlog, also writes to PHP error_log() (may appear duplicated depending on your setup)
//
// Tables purged per game_id (in safe order):
//   1) msf_live_pbp_on_ice    (by event_id join to event)
//   2) msf_live_pbp_actor     (by event_id join to event)
//   3) msf_live_pbp_event     (by game_id)
//   4) msf_live_game_official (by game_id)
//   5) msf_live_game_broadcaster (by game_id)
//   6) msf_live_game          (by game_id)
//
// PHP 7+ friendly.

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__, 2); // /var/www/nhlwatercooler
require_once $root . '/config/db.php';

// Expect $pdo from config/db.php
if (!isset($pdo) || !($pdo instanceof PDO)) {
  fwrite(STDERR, "[PBP_PURGE] FATAL: \$pdo not found (config/db.php did not create a PDO instance)\n");
  exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ============================================================
 * Logging: always append to gameday-logs/pbp_purge.log
 * ========================================================== */

$LOG_DIR  = $root . '/public/scripts/gameday-logs';
$LOG_FILE = $LOG_DIR . '/pbp_purge.log';

if (!is_dir($LOG_DIR)) {
  @mkdir($LOG_DIR, 0775, true);
}

/* ============================================================
 * Arg parsing
 * ========================================================== */

$opts = array(
  'days'         => 10,
  'limit'        => 200,
  'dry_run'      => false,
  'verbose'      => false,
  'quiet'        => false,
  'errorlog'     => false, // ALSO write to PHP error_log (can appear duplicated)

  // Date-range mode (UTC)
  'from'         => '',
  'to'           => '',

  // Safety
  'force_recent' => false,
);

foreach (array_slice($argv, 1) as $arg) {
  if ($arg === '--dry-run')      { $opts['dry_run'] = true; continue; }
  if ($arg === '--verbose')      { $opts['verbose'] = true; continue; }
  if ($arg === '--quiet')        { $opts['quiet']   = true; continue; }
  if ($arg === '--errorlog')     { $opts['errorlog']= true; continue; }
  if ($arg === '--force-recent') { $opts['force_recent'] = true; continue; }

  if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
    $opts['days'] = max(0, (int)$m[1]);
    continue;
  }
  if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
    $opts['limit'] = max(1, (int)$m[1]);
    continue;
  }
  if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
    $opts['from'] = $m[1];
    continue;
  }
  if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
    $opts['to'] = $m[1];
    continue;
  }
}

/* ============================================================
 * Logging helper
 * ========================================================== */

function pbp_log($msg) {
  global $opts, $LOG_FILE;

  $line = '[PBP_PURGE] ' . $msg;

  // Always append to our dedicated file
  if (!empty($LOG_FILE)) {
    @file_put_contents($LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
  }

  // In CLI: optionally echo (default yes)
  if (PHP_SAPI === 'cli' && empty($opts['quiet'])) {
    echo $line . "\n";
  }

  // Optional: also send to PHP error_log()
  if (!empty($opts['errorlog'])) {
    error_log($line);
  }
}

/* ============================================================
 * Date helpers
 * ========================================================== */

function pbp_is_ymd($s) {
  return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

/**
 * Build [fromUtc, toUtcExclusive] bounds for an inclusive UTC date range.
 * from=YYYY-MM-DD => fromUtc=YYYY-MM-DD 00:00:00
 * to=YYYY-MM-DD   => toUtcExcl=(to+1 day) 00:00:00
 */
function pbp_utc_bounds_from_range($fromYmd, $toYmd) {
  $tzUtc = new DateTimeZone('UTC');

  $from = DateTime::createFromFormat('Y-m-d', $fromYmd, $tzUtc);
  $to   = DateTime::createFromFormat('Y-m-d', $toYmd, $tzUtc);
  if (!$from || !$to) return array(null, null);

  $from->setTime(0,0,0);
  $to->setTime(0,0,0);

  if ($to < $from) return array(null, null);

  $toExcl = clone $to;
  $toExcl->modify('+1 day');

  return array($from->format('Y-m-d H:i:s'), $toExcl->format('Y-m-d H:i:s'));
}

/**
 * UTC midnight boundary for "today - N days"
 */
function pbp_utc_midnight_days_ago($daysAgo) {
  $tzUtc = new DateTimeZone('UTC');
  $d = new DateTime('now', $tzUtc);
  $d->setTime(0,0,0);
  $d->modify('-' . (int)$daysAgo . ' days');
  return $d->format('Y-m-d H:i:s');
}

/* ============================================================
 * Mode selection + safety enforcement
 * ========================================================== */

$rangeMode = false;
if ($opts['from'] !== '' || $opts['to'] !== '') {
  if (!pbp_is_ymd($opts['from']) || !pbp_is_ymd($opts['to'])) {
    pbp_log("FATAL: date-range mode requires BOTH --from=YYYY-MM-DD and --to=YYYY-MM-DD");
    exit(2);
  }
  $rangeMode = true;
}

if (!$rangeMode) {
  // Retention safety: minimum 7 days unless forced
  if ((int)$opts['days'] < 7 && empty($opts['force_recent'])) {
    pbp_log("SAFETY: --days={$opts['days']} is < 7; enforcing minimum days=7 (pass --force-recent to override)");
    $opts['days'] = 7;
  }
}

/* ============================================================
 * Cutoff / bounds calculation (UTC midnight)
 * ========================================================== */

$tzUtc = new DateTimeZone('UTC');

$cutoffUtc = null;          // retention mode only
$cutoffYmd = null;          // retention mode only

$rangeFromUtc   = null;     // range mode only
$rangeToUtcExcl = null;     // range mode only

if ($rangeMode) {
  list($rangeFromUtc, $rangeToUtcExcl) = pbp_utc_bounds_from_range($opts['from'], $opts['to']);
  if (!$rangeFromUtc || !$rangeToUtcExcl) {
    pbp_log("FATAL: invalid range; ensure --from <= --to and both are valid dates");
    exit(2);
  }

  // Range safety: refuse if range touches last 7 days (unless forced)
  if (empty($opts['force_recent'])) {
    $recentCut = pbp_utc_midnight_days_ago(7); // today-7 00:00:00
    // If the range end is after recentCut, the range intersects the last 7 days
    if ($rangeToUtcExcl > $recentCut) {
      pbp_log("SAFETY: requested range intersects the most recent 7 days (cutoff={$recentCut}). Refusing. Pass --force-recent to override.");
      exit(2);
    }
  }

  pbp_log(sprintf(
    "start mode=range from=%s to=%s from_utc=%s to_utc_excl=%s dry_run=%s limit=%d log=%s",
    $opts['from'],
    $opts['to'],
    $rangeFromUtc,
    $rangeToUtcExcl,
    ($opts['dry_run'] ? 'yes' : 'no'),
    (int)$opts['limit'],
    $LOG_FILE
  ));

} else {
  $now = new DateTime('now', $tzUtc);
  $now->setTime(0, 0, 0);

  $cut = clone $now;
  $cut->modify('-' . (int)$opts['days'] . ' days');

  $cutoffYmd = $cut->format('Y-m-d');
  $cutoffUtc = $cut->format('Y-m-d H:i:s');

  pbp_log(sprintf(
    "start mode=retention days=%d cutoff_ymd=%s cutoff_utc=%s dry_run=%s limit=%d log=%s",
    (int)$opts['days'],
    $cutoffYmd,
    $cutoffUtc,
    ($opts['dry_run'] ? 'yes' : 'no'),
    (int)$opts['limit'],
    $LOG_FILE
  ));
}

/* ============================================================
 * Select candidate game_ids
 * (Join pbp_event so we only pick games that actually have pbp)
 * ========================================================== */

$gameIds = array();

try {
  if ($rangeMode) {
    $sql = "
      SELECT DISTINCT e.game_id
      FROM msf_live_pbp_event e
      INNER JOIN msf_live_game g ON g.game_id = e.game_id
      WHERE g.start_time_utc IS NOT NULL
        AND g.start_time_utc >= :from_utc
        AND g.start_time_utc <  :to_utc_excl
      ORDER BY e.game_id ASC
      LIMIT " . (int)$opts['limit'] . "
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array(
      ':from_utc'    => $rangeFromUtc,
      ':to_utc_excl' => $rangeToUtcExcl,
    ));
    $gameIds = $st->fetchAll(PDO::FETCH_COLUMN, 0);

  } else {
    $sql = "
      SELECT DISTINCT e.game_id
      FROM msf_live_pbp_event e
      INNER JOIN msf_live_game g ON g.game_id = e.game_id
      WHERE g.start_time_utc IS NOT NULL
        AND g.start_time_utc < :cutoff_utc
      ORDER BY e.game_id ASC
      LIMIT " . (int)$opts['limit'] . "
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array(':cutoff_utc' => $cutoffUtc));
    $gameIds = $st->fetchAll(PDO::FETCH_COLUMN, 0);
  }

} catch (Exception $e) {
  pbp_log("ERROR selecting games to purge: " . $e->getMessage());
  exit(1);
}

$gameIds = array_values(array_filter(array_map('intval', (array)$gameIds), function($x){ return $x > 0; }));

if (!$gameIds) {
  pbp_log("no games matched; nothing to purge.");
  exit(0);
}

pbp_log("found " . count($gameIds) . " game(s) to purge");

/* ============================================================
 * Helpers: counts + delete statements
 * ========================================================== */

function pbp_counts_for_game(PDO $pdo, $gid) {
  $gid = (int)$gid;
  $out = array(
    'event'       => 0,
    'on_ice'      => 0,
    'actor'       => 0,
    'official'    => 0,
    'broadcaster' => 0,
    'game'        => 0,
  );

  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM msf_live_pbp_event WHERE game_id = :gid");
    $st->execute(array(':gid' => $gid));
    $out['event'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM msf_live_pbp_on_ice oi
      INNER JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
      WHERE e.game_id = :gid
    ");
    $st->execute(array(':gid' => $gid));
    $out['on_ice'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM msf_live_pbp_actor a
      INNER JOIN msf_live_pbp_event e ON e.event_id = a.event_id
      WHERE e.game_id = :gid
    ");
    $st->execute(array(':gid' => $gid));
    $out['actor'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM msf_live_game_official WHERE game_id = :gid");
    $st->execute(array(':gid' => $gid));
    $out['official'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM msf_live_game_broadcaster WHERE game_id = :gid");
    $st->execute(array(':gid' => $gid));
    $out['broadcaster'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM msf_live_game WHERE game_id = :gid");
    $st->execute(array(':gid' => $gid));
    $out['game'] = (int)$st->fetchColumn();
  } catch (Exception $e) {}

  return $out;
}

function pbp_delete_for_game(PDO $pdo, $gid) {
  $gid = (int)$gid;
  $deleted = array(
    'on_ice'      => 0,
    'actor'       => 0,
    'event'       => 0,
    'official'    => 0,
    'broadcaster' => 0,
    'game'        => 0,
  );

  // 1) on_ice
  $st = $pdo->prepare("
    DELETE oi
    FROM msf_live_pbp_on_ice oi
    INNER JOIN msf_live_pbp_event e ON e.event_id = oi.event_id
    WHERE e.game_id = :gid
  ");
  $st->execute(array(':gid' => $gid));
  $deleted['on_ice'] = $st->rowCount();

  // 2) actor
  $st = $pdo->prepare("
    DELETE a
    FROM msf_live_pbp_actor a
    INNER JOIN msf_live_pbp_event e ON e.event_id = a.event_id
    WHERE e.game_id = :gid
  ");
  $st->execute(array(':gid' => $gid));
  $deleted['actor'] = $st->rowCount();

  // 3) events
  $st = $pdo->prepare("DELETE FROM msf_live_pbp_event WHERE game_id = :gid");
  $st->execute(array(':gid' => $gid));
  $deleted['event'] = $st->rowCount();

  // 4) officials
  $st = $pdo->prepare("DELETE FROM msf_live_game_official WHERE game_id = :gid");
  $st->execute(array(':gid' => $gid));
  $deleted['official'] = $st->rowCount();

  // 5) broadcasters
  $st = $pdo->prepare("DELETE FROM msf_live_game_broadcaster WHERE game_id = :gid");
  $st->execute(array(':gid' => $gid));
  $deleted['broadcaster'] = $st->rowCount();

  // 6) game row
  $st = $pdo->prepare("DELETE FROM msf_live_game WHERE game_id = :gid");
  $st->execute(array(':gid' => $gid));
  $deleted['game'] = $st->rowCount();

  return $deleted;
}

/* ============================================================
 * Purge loop
 * ========================================================== */

$totalDeleted = array(
  'on_ice'      => 0,
  'actor'       => 0,
  'event'       => 0,
  'official'    => 0,
  'broadcaster' => 0,
  'game'        => 0,
);

$purgedGames = 0;

foreach ($gameIds as $gid) {
  $gid = (int)$gid;
  if ($gid <= 0) continue;

  if (!empty($opts['verbose'])) {
    // Pull a little context from msf_live_game
    $meta = array('start_time_utc' => null, 'away' => '', 'home' => '');
    try {
      $st = $pdo->prepare("SELECT start_time_utc, away_team_abbr, home_team_abbr FROM msf_live_game WHERE game_id = :gid LIMIT 1");
      $st->execute(array(':gid' => $gid));
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if ($r) {
        $meta['start_time_utc'] = $r['start_time_utc'] ?? null;
        $meta['away'] = (string)($r['away_team_abbr'] ?? '');
        $meta['home'] = (string)($r['home_team_abbr'] ?? '');
      }
    } catch (Exception $e) {}

    $c = pbp_counts_for_game($pdo, $gid);
    pbp_log(sprintf(
      "game_id=%d %s@%s start=%s counts event=%d on_ice=%d actor=%d official=%d broadcaster=%d game=%d",
      $gid,
      $meta['away'],
      $meta['home'],
      ($meta['start_time_utc'] ? $meta['start_time_utc'] : 'null'),
      $c['event'], $c['on_ice'], $c['actor'], $c['official'], $c['broadcaster'], $c['game']
    ));
  } else {
    pbp_log("purging game_id=" . $gid);
  }

  if (!empty($opts['dry_run'])) {
    $purgedGames++;
    continue;
  }

  try {
    $pdo->beginTransaction();

    $deleted = pbp_delete_for_game($pdo, $gid);

    $pdo->commit();

    foreach ($totalDeleted as $k => $v) {
      $totalDeleted[$k] += (int)($deleted[$k] ?? 0);
    }

    $purgedGames++;

    if (!empty($opts['verbose'])) {
      pbp_log(sprintf(
        "deleted game_id=%d on_ice=%d actor=%d event=%d official=%d broadcaster=%d game=%d",
        $gid,
        (int)$deleted['on_ice'],
        (int)$deleted['actor'],
        (int)$deleted['event'],
        (int)$deleted['official'],
        (int)$deleted['broadcaster'],
        (int)$deleted['game']
      ));
    }

  } catch (Exception $e) {
    try { $pdo->rollBack(); } catch (Exception $e2) {}
    pbp_log("ERROR purging game_id=" . $gid . ": " . $e->getMessage());
    // continue to next game
  }
}

/* ============================================================
 * Summary
 * ========================================================== */

if (!empty($opts['dry_run'])) {
  pbp_log("DRY RUN complete. games_matched=" . $purgedGames . " (no deletes executed)");
} else {
  pbp_log(sprintf(
    "complete games_purged=%d totals on_ice=%d actor=%d event=%d official=%d broadcaster=%d game=%d",
    $purgedGames,
    (int)$totalDeleted['on_ice'],
    (int)$totalDeleted['actor'],
    (int)$totalDeleted['event'],
    (int)$totalDeleted['official'],
    (int)$totalDeleted['broadcaster'],
    (int)$totalDeleted['game']
  ));
}

exit(0);
