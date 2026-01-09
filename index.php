<?php
// public/index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../config/db.php';

/* --------------------------------------------
   Tiny file-cache helpers (JSON)
   Cache dir: /cache (sibling of /public)
-------------------------------------------- */
function file_cache_dir(): string {
  return __DIR__ . '/../cache';
}
function file_cache_path(string $key): string {
  $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
  return rtrim(file_cache_dir(), '/') . '/' . $safe . '.json';
}
function file_cache_get(string $key, int $ttlSeconds) {
  $path = file_cache_path($key);
  if (!is_file($path)) return null;
  if ($ttlSeconds > 0 && (time() - @filemtime($path)) >= $ttlSeconds) return null;
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return null;
  $data = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}
function file_cache_set(string $key, $data): void {
  $dir = file_cache_dir();
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $path = file_cache_path($key);
  @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
}

/**
 * NCAA LOGO MAP (short_name → slug)
 */
$ncaaLogoMapRaw = require __DIR__ . '/includes/ncaa_logo_map.php';
$ncaaLogoMap = [];
foreach ($ncaaLogoMapRaw as $k => $v) {
  $ncaaLogoMap[strtolower(trim($k))] = $v;
}

/**
 * Safe slugify fallback (if needed)
 */
function slugify($text) {
  $text = strtolower((string)$text);
  $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
  return trim($text, '-') ?: 'thread';
}

// convert military time
function format_puck_drop_time($mysqlTime) {
  $mysqlTime = trim((string)$mysqlTime);
  if ($mysqlTime === '') return '';

  // Accept "HH:MM" or "HH:MM:SS"
  if (preg_match('/^\d{2}:\d{2}$/', $mysqlTime)) {
    $mysqlTime .= ':00';
  }
  if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $mysqlTime)) return '';

  $dt = DateTime::createFromFormat('H:i:s', $mysqlTime);
  if (!$dt) return '';

  return $dt->format('g:i A'); // e.g. 7:30 PM
}

/**
 * Normalize NCAA team param → DB short_name
 */
function ncaa_normalize_team_param($team) {
  $team = trim($team ?? '');

  // Canonical mappings
  $map = [
    'long island' => 'LIU',
    'liu'         => 'LIU',
  ];

  $key = strtolower($team);
  return $map[$key] ?? $team;
}

/**
 * Normalize NCAA short names for logo lookup
 */
function ncaa_key($name) {
  return strtolower(trim($name ?? ''));
}

/**
 * Logo resolver: NCAA
 */
function ncaa_logo_slug($short, $logoMap) {
  $key = ncaa_key($short);
  if ($key && isset($logoMap[$key])) {
    return $logoMap[$key];
  }
  // fallback auto-slugify
  return slugify($short);
}

/**
 * NCAA TEAM NAME FORMATTER
 */
function ncaa_format_team($short, $rank) {
  $short = trim($short ?? '');
  $rank  = trim($rank ?? '');
  if ($short === '') return '';
  return $rank !== '' ? "#{$rank} {$short}" : $short;
}

/**
 * NCAA MATCHUP RENDERER
 * NOTE: does NOT declare "Final" based on DB scores. JS is source-of-truth for state.
 */
function ncaa_render_matchup($t, $isTodayGame) {

  $awayShort = $t['away_team_short'] ?? '';
  $homeShort = $t['home_team_short'] ?? '';
  $awayRank  = $t['away_rank'] ?? '';
  $homeRank  = $t['home_rank'] ?? '';

  $awayScoreDb = isset($t['away_score']) ? (int)$t['away_score'] : null;
  $homeScoreDb = isset($t['home_score']) ? (int)$t['home_score'] : null;
  $hasScoreDb  = ($awayScoreDb !== null && $homeScoreDb !== null);

  $awayName = ncaa_format_team($awayShort, $awayRank);
  $homeName = ncaa_format_team($homeShort, $homeRank);

  $awayClass   = "thread-card__abbr thread-card__abbr--away";
  $homeClass   = "thread-card__abbr thread-card__abbr--home";
  $ascoreClass = "thread-card__score-num thread-card__score-num--away";
  $hscoreClass = "thread-card__score-num thread-card__score-num--home";
  ?>
  <span class="thread-card__matchup">
    <span class="<?= $awayClass ?>"><?= htmlspecialchars($awayName) ?></span>
    <span class="<?= $ascoreClass ?>"><?= $hasScoreDb ? $awayScoreDb : '' ?></span>

    <span class="thread-card__at">@</span>

    <span class="<?= $homeClass ?>"><?= htmlspecialchars($homeName) ?></span>
    <span class="<?= $hscoreClass ?>"><?= $hasScoreDb ? $homeScoreDb : '' ?></span>

    <span class="thread-card__pill">
      <?php if ($isTodayGame): ?>
        <?= htmlspecialchars(format_puck_drop_time($t['start_time'] ?? '') ?: 'Game Day') ?>
      <?php else: ?>&nbsp;<?php endif; ?>
    </span>
  </span>
  <?php
}

/* ------------------------------
   League resolution
------------------------------ */
$leagueKey = (isset($_GET['league']) && strtolower($_GET['league']) === 'ncaa') ? 'ncaa' : 'nhl';
$leagueDb  = ($leagueKey === 'ncaa') ? 'NCAAH' : 'NHL';

/* Timezone */
$msfConfig = require __DIR__ . '/../config/msf.php';
$tz        = new DateTimeZone($msfConfig['timezone'] ?? 'America/New_York');
$nowTz     = new DateTime('now', $tz);
$todayYmd  = $nowTz->format('Y-m-d');
$yesterdayYmd = (clone $nowTz)->modify('-1 day')->format('Y-m-d');
$nowSql = $nowTz->format('Y-m-d H:i:s'); // for SQL gating

/* Filters */
$teamFilterRaw = isset($_GET['team']) ? trim($_GET['team']) : 'ALL';
$searchTerm    = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($leagueDb === 'NCAAH' && $teamFilterRaw !== 'ALL') {
  $teamFilterRaw = ncaa_normalize_team_param($teamFilterRaw);
}
if ($teamFilterRaw === '' || strcasecmp($teamFilterRaw, 'ALL') === 0) {
  $teamFilterRaw = 'ALL';
}

$teamFilter = ($leagueDb === 'NHL')
  ? strtoupper($teamFilterRaw)
  : $teamFilterRaw;

/* -----------------------------------------------------------------
   ✅ FIX #1: Make the index dropdown authoritative for session theme
   - NHL: store 'all' or 3-letter code in $_SESSION['ui_team']
   - NCAA: store/clear $_SESSION['ncaa_selected_team']
----------------------------------------------------------------- */
if (isset($_GET['team'])) {

  if ($leagueDb === 'NHL') {
    $t = strtoupper(trim((string)$_GET['team']));

    if ($t === '' || $t === 'ALL' || $t === 'ALL_TEAMS') {
      $_SESSION['ui_team'] = 'all';
    } else {
      $_SESSION['ui_team'] = strtolower(substr($t, 0, 3));
    }
  }

  if ($leagueDb === 'NCAAH') {
    $t = trim((string)$_GET['team']);

    if (
      $t === '' ||
      strcasecmp($t, 'ALL') === 0 ||
      strcasecmp($t, 'ALL_TEAMS') === 0 ||
      strcasecmp($t, 'ALL_SCHOOLS') === 0
    ) {
      unset($_SESSION['ncaa_selected_team']);
    } else {
      $_SESSION['ncaa_selected_team'] = $t;
    }
  }
}
/* ----------------------------------------------------------------- */

/* Pagination */
$pageSize = 20;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $pageSize;

/* --------------------------------------------
   Cached dropdown lists
-------------------------------------------- */

/* NHL team list */
$teamAbbrs = file_cache_get('nhl_team_abbrs', 86400);
if (!is_array($teamAbbrs)) {
  $teamAbbrs = $pdo->query("
    SELECT abbr FROM (
      SELECT DISTINCT home_team_abbr AS abbr FROM msf_games
      UNION
      SELECT DISTINCT away_team_abbr AS abbr FROM msf_games
    ) t ORDER BY abbr
  ")->fetchAll(PDO::FETCH_COLUMN);
  file_cache_set('nhl_team_abbrs', $teamAbbrs);
}

/* NCAA schools list */
$ncaaTeams = [];
if ($leagueDb === 'NCAAH') {
  $ncaaTeams = file_cache_get('ncaa_team_short_names', 86400);
  if (!is_array($ncaaTeams)) {
    $ncaaTeams = $pdo->query("
      SELECT DISTINCT short_name
      FROM ncaa_teams
      ORDER BY short_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    file_cache_set('ncaa_team_short_names', $ncaaTeams);
  }
}

/* -------------------------
   THREAD QUERY (ACTUALLY FAST)
   - Step A: fetch only thread IDs
   - Step B: fetch details for those IDs
   - IMPORTANT: gamelog aggregates are limited to IDs on this page
------------------------- */

// Cache bucket: 5 minutes (avoid constant cache churn)
$nowBucket = (int)floor(time() / 300);

$cacheKey = 'threads_v3_' . md5(json_encode([
  'leagueDb' => $leagueDb,
  'team'     => $teamFilter,
  'search'   => $searchTerm,
  'page'     => $page,
  'today'    => $todayYmd,
  'bucket'   => $nowBucket,
]));

$threads = file_cache_get($cacheKey, 60);

if (!is_array($threads)) {
  $params = [];
  $where  = [];

  // league
  $where[] = "t.league = :league";
  $params[':league'] = $leagueDb;

  // visibility gating
  $where[] = "t.game_date <= :today";
  $params[':today'] = $todayYmd;

  // if today, only show after 10:00 ET
  $where[] = "(t.game_date < :today OR (t.game_date = :today AND :now_dt >= CONCAT(t.game_date, ' 10:00:00')))";
  $params[':now_dt'] = $nowSql;

  // search needs users join
  $joinA = "JOIN users u ON u.id = t.created_by";
  if ($searchTerm !== '') {
    $where[] = "(t.title LIKE :s OR u.username LIKE :s)";
    $params[':s'] = "%{$searchTerm}%";
  }

  // team filters
  if ($leagueDb === 'NHL' && $teamFilter !== 'ALL') {
    $joinA .= " JOIN msf_games g ON g.msf_game_id = t.external_game_id";
    $where[] = "(g.home_team_abbr = :team OR g.away_team_abbr = :team)";
    $params[':team'] = $teamFilter;
  }

  if ($leagueDb === 'NCAAH' && $teamFilter !== 'ALL') {
    $where[] = "EXISTS (
      SELECT 1
      FROM ncaa_team_gamelogs x
      WHERE x.contest_id = t.ncaa_game_id
        AND x.team_name_short = :t
      LIMIT 1
    )";
    $params[':t'] = $teamFilter;
  }

  // Step A: IDs only
  $sqlA = "
    SELECT t.id
    FROM gameday_threads t
    $joinA
    WHERE " . implode(" AND ", $where) . "
    ORDER BY t.game_date DESC, t.created_at DESC
    LIMIT :limit OFFSET :offset
  ";

  $stmtA = $pdo->prepare($sqlA);
  foreach ($params as $k => $v) $stmtA->bindValue($k, $v);
  $stmtA->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
  $stmtA->bindValue(':offset', $offset,   PDO::PARAM_INT);
  $stmtA->execute();

  $ids = $stmtA->fetchAll(PDO::FETCH_COLUMN);

  if (!$ids) {
    $threads = [];
    file_cache_set($cacheKey, $threads);
  } else {
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // Step B: details
    $sqlB = "
      SELECT
        t.id,
        t.title,
        t.game_date,
        t.start_time,
        t.created_at,
        t.header_image_url,
        t.external_game_id,
        t.ncaa_game_id,
        t.league,
        u.username AS author_name,
        COALESCE(pc.post_count, 0) AS post_count
    ";

    if ($leagueDb === 'NHL') {
      $sqlB .= ",
        g.home_team_abbr,
        g.away_team_abbr,
        MAX(CASE WHEN tgl.team_abbr = g.home_team_abbr THEN tgl.goals_for END) AS home_goals,
        MAX(CASE WHEN tgl.team_abbr = g.away_team_abbr THEN tgl.goals_for END) AS away_goals
      ";
    }

    if ($leagueDb === 'NCAAH') {
      $sqlB .= ",
        MAX(ng.home_team_short) AS home_team_short,
        MAX(ng.away_team_short) AS away_team_short,
        MAX(ng.home_rank)       AS home_rank,
        MAX(ng.away_rank)       AS away_rank,
        MAX(CASE WHEN ntg.team_side = 'home' THEN ntg.goals END) AS home_score,
        MAX(CASE WHEN ntg.team_side = 'away' THEN ntg.goals END) AS away_score
      ";
    }

    $sqlB .= "
      FROM gameday_threads t
      JOIN users u ON u.id = t.created_by

      LEFT JOIN (
        SELECT thread_id, COUNT(*) AS post_count
        FROM posts
        WHERE is_deleted = 0
          AND thread_id IN ($ph)
        GROUP BY thread_id
      ) pc ON pc.thread_id = t.id
    ";

    if ($leagueDb === 'NHL') {
      $sqlB .= "
        LEFT JOIN msf_games g
          ON g.msf_game_id = t.external_game_id

        LEFT JOIN (
          SELECT msf_game_id, team_abbr, MAX(goals_for) AS goals_for
          FROM msf_team_gamelogs
          WHERE msf_game_id IN (
            SELECT DISTINCT external_game_id
            FROM gameday_threads
            WHERE id IN ($ph)
          )
          GROUP BY msf_game_id, team_abbr
        ) tgl ON tgl.msf_game_id = t.external_game_id
      ";
    }

    if ($leagueDb === 'NCAAH') {
      $sqlB .= "
        LEFT JOIN ncaa_games ng
          ON ng.game_id = t.ncaa_game_id

        LEFT JOIN (
          SELECT contest_id, team_side, MAX(goals) AS goals
          FROM ncaa_team_gamelogs
          WHERE contest_id IN (
            SELECT DISTINCT ncaa_game_id
            FROM gameday_threads
            WHERE id IN ($ph)
          )
          GROUP BY contest_id, team_side
        ) ntg ON ntg.contest_id = t.ncaa_game_id
      ";
    }

    $sqlB .= "
      WHERE t.id IN ($ph)
      GROUP BY t.id
      ORDER BY t.game_date DESC, t.created_at DESC
    ";

    // $ph appears 3x: pc IN, derived IN, main IN
    $bind = array_merge($ids, $ids, $ids);

    $stmtB = $pdo->prepare($sqlB);
    $stmtB->execute($bind);
    $threads = $stmtB->fetchAll(PDO::FETCH_ASSOC);

    file_cache_set($cacheKey, $threads);
  }
}

/* ----------------------
   Page settings
---------------------- */
$themeClass = '';

$classes = ['page-index', 'league-' . $leagueKey];

// Team theme class
if ($teamFilterRaw !== 'ALL') {
  if ($leagueKey === 'ncaa') {
    $classes[] = 'ncaa-team-' . slugify($teamFilterRaw);
  } else {
    $classes[] = 'nhl-team-' . strtolower($teamFilterRaw);
  }
} else {
  //$classes[] = 'team-all';
}

$bodyClass = implode(' ', $classes);

$pageTitle = 'Gameday Threads';
$pageCss   = ['/assets/css/index.css'];
$pageJs    = ['/assets/js/index.js', '/assets/js/index-live-scores.js'];

include __DIR__ . '/includes/header.php';
?>

<div class="top-bar page-index__top-bar">
  <h1>
    Gameday Threads
    <?= $leagueDb === 'NCAAH'
      ? '<span class="top-bar__league-label">(NCAA)</span>'
      : '<span class="top-bar__league-label">(NHL)</span>' ?>
  </h1>

  <div class="top-bar__controls">
    <form id="thread-filters" method="get" action="/index.php" class="thread-filters">
      <input type="hidden" name="league" value="<?= htmlspecialchars($leagueKey) ?>">

      <div class="thread-search">
        <label>Search:</label>
        <input
          type="search"
          id="thread-search"
          name="search"
          value="<?= htmlspecialchars($searchTerm) ?>"
          placeholder="Search threads…">
      </div>

      <div class="team-filter">
        <label><?= $leagueDb === 'NCAAH' ? 'School:' : 'Team:' ?></label>

        <?php if ($leagueDb === 'NHL'): ?>
          <select name="team" id="team">
            <option value="ALL">All Teams</option>
            <?php foreach ($teamAbbrs as $abbr): ?>
              <option value="<?= $abbr ?>" <?= ($teamFilter === $abbr) ? 'selected' : '' ?>>
                <?= htmlspecialchars($abbr) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <select name="team" id="team">
            <option value="ALL">All Schools</option>
            <?php foreach ($ncaaTeams as $short): ?>
              <option value="<?= htmlspecialchars($short) ?>" <?= ($teamFilter === $short) ? 'selected' : '' ?>>
                <?= htmlspecialchars($short) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$threads): ?>
  <div class="empty-state">
    <p>No <?= $leagueDb === 'NCAAH' ? 'NCAA' : 'NHL' ?> gameday threads found.</p>
  </div>
<?php else: ?>

<div
  class="thread-grid"
  data-initial-count="<?= count($threads) ?>"
  data-today="<?= htmlspecialchars($todayYmd) ?>"
  data-yesterday="<?= htmlspecialchars($yesterdayYmd) ?>"
>

<?php foreach ($threads as $t): ?>
<?php
  $gameDate    = $t['game_date'] ?? '';
  $isTodayGame = ($gameDate === $todayYmd);

  $slug        = slugify($t['title']);
  $dateSegment = $gameDate ?: 'no-date';

  $isCustomHeader = !empty($t['header_image_url'])
    && $t['header_image_url'] !== 'assets/img/gameday-placeholder.png';

  // NHL abbreviations
  $homeAbbr = $t['home_team_abbr'] ?? '';
  $awayAbbr = $t['away_team_abbr'] ?? '';

  // NCAA teams
  $homeShort = $t['home_team_short'] ?? '';
  $awayShort = $t['away_team_short'] ?? '';

  $homeSlug  = ncaa_logo_slug($homeShort, $ncaaLogoMap);
  $awaySlug  = ncaa_logo_slug($awayShort, $ncaaLogoMap);

  // Build home-team class for theming
  $homeTeamClass = '';

  if ($leagueDb === 'NHL') {
    $home = strtolower(trim((string)($t['home_team_abbr'] ?? '')));
    if ($home !== '') {
      $homeTeamClass = ' team-' . preg_replace('/[^a-z0-9\-]+/i', '', $home);
    }
  } else {
    // NCAA: base it on home short name (matches your ncaa-team- slug style)
    $home = slugify($t['home_team_short'] ?? '');
    if ($home !== '' && $home !== 'thread') {
      $homeTeamClass = ' ncaa-team-' . $home;
    }
  }
?>
<a
  href="/thread/<?= htmlspecialchars($dateSegment) ?>/<?= htmlspecialchars($slug) ?>-<?= (int)$t['id'] ?>?league=<?= $leagueKey ?>"
  class="thread-card thread-card--league-<?= strtolower($t['league']) ?><?= $homeTeamClass ?>"
  <?php if ($leagueDb === 'NHL' && !empty($t['external_game_id'])): ?>
    data-msf-game-id="<?= (int)$t['external_game_id'] ?>"
  <?php endif; ?>
  <?php if ($leagueDb === 'NCAAH' && !empty($t['ncaa_game_id'])): ?>
    data-ncaa-game-id="<?= htmlspecialchars($t['ncaa_game_id']) ?>"
  <?php endif; ?>
  data-is-today="<?= $isTodayGame ? '1' : '0' ?>"
  data-game-date="<?= htmlspecialchars($gameDate) ?>"
  data-start-time="<?= htmlspecialchars($t['start_time'] ?? '') ?>"
  data-live-status="other"
>

  <?php if ($isCustomHeader): ?>
    <div class="thread-card__image"
         style="background-image: url('<?= htmlspecialchars('/' . ltrim($t['header_image_url'], '/')) ?>');">
    </div>

  <?php elseif ($leagueDb === 'NHL' && $homeAbbr && $awayAbbr): ?>
    <div class="thread-card__logo-strip">
      <div class="thread-card__logo">
        <img src="/assets/img/logos/<?= $awayAbbr ?>.png" alt="<?= $awayAbbr ?>" loading="lazy">
      </div>
      <span class="thread-card__at">@</span>
      <div class="thread-card__logo">
        <img src="/assets/img/logos/<?= $homeAbbr ?>.png" alt="<?= $homeAbbr ?>" loading="lazy">
      </div>
    </div>

  <?php elseif ($leagueDb === 'NCAAH'): ?>
    <div class="thread-card__logo-strip">
      <div class="thread-card__logo">
        <img src="/assets/img/ncaa-logos/<?= $awaySlug ?>.svg"
             alt="<?= htmlspecialchars($awayShort) ?>" loading="lazy">
      </div>
      <span class="thread-card__at">@</span>
      <div class="thread-card__logo">
        <img src="/assets/img/ncaa-logos/<?= $homeSlug ?>.svg"
             alt="<?= htmlspecialchars($homeShort) ?>" loading="lazy">
      </div>
    </div>

  <?php else: ?>
    <div class="thread-card__placeholder"></div>
  <?php endif; ?>

  <div class="thread-card__body">
    <h2 class="thread-card__title">
      <?php if ($leagueDb === 'NCAAH'): ?>
        <span class="thread-card__league-pill">NCAA</span>
      <?php endif; ?>
      <?= htmlspecialchars($t['title']) ?>
    </h2>

    <div class="thread-card__meta">
      <span>Game day: <?= htmlspecialchars($t['game_date']) ?></span><br>
      <span>Posted by <?= htmlspecialchars($t['author_name']) ?></span><br>

      <?php if ($leagueDb === 'NCAAH'): ?>

        <?php ncaa_render_matchup($t, $isTodayGame); ?><br>

      <?php else: ?>

        <?php
          $homeClass      = "thread-card__abbr thread-card__abbr--home";
          $awayClass      = "thread-card__abbr thread-card__abbr--away";
          $homeScoreClass = "thread-card__score-num thread-card__score-num--home";
          $awayScoreClass = "thread-card__score-num thread-card__score-num--away";

          $homeGoalsDb = isset($t['home_goals']) ? (int)$t['home_goals'] : null;
          $awayGoalsDb = isset($t['away_goals']) ? (int)$t['away_goals'] : null;

          // DB may have partial/live scores — do NOT treat as final by default.
          $hasScoreDb = ($homeGoalsDb !== null && $awayGoalsDb !== null);

          // Only default to "Final" on initial render for games strictly older than yesterday.
          // Today + yesterday should be treated as "live-ish" and JS becomes authoritative.
          $isOlderThanYesterday = ($gameDate && $yesterdayYmd && $gameDate < $yesterdayYmd);
          $showFinalOnLoad = ($hasScoreDb && $isOlderThanYesterday);

          // Apply win/loss styling ONLY when we're showing Final on load.
          if ($showFinalOnLoad) {
            if ($homeGoalsDb > $awayGoalsDb) {
              $homeClass      .= " thread-card__abbr--win";
              $homeScoreClass .= " thread-card__score-num--win";
              $awayClass      .= " thread-card__abbr--loss";
              $awayScoreClass .= " thread-card__score-num--loss";
            } elseif ($awayGoalsDb > $homeGoalsDb) {
              $awayClass      .= " thread-card__abbr--win";
              $awayScoreClass .= " thread-card__score-num--win";
              $homeClass      .= " thread-card__abbr--loss";
              $homeScoreClass .= " thread-card__score-num--loss";
            } else {
              $awayClass      .= " thread-card__abbr--tie";
              $homeClass      .= " thread-card__abbr--tie";
              $awayScoreClass .= " thread-card__score-num--tie";
              $homeScoreClass .= " thread-card__score-num--tie";
            }
          }
        ?>

        <span class="thread-card__matchup">
          <span class="<?= $awayClass ?>"><?= htmlspecialchars($t['away_team_abbr']) ?></span>
          <span class="<?= $awayScoreClass ?>"><?= $hasScoreDb ? $awayGoalsDb : '' ?></span>

          <span class="thread-card__at">@</span>

          <span class="<?= $homeClass ?>"><?= htmlspecialchars($t['home_team_abbr']) ?></span>
          <span class="<?= $homeScoreClass ?>"><?= $hasScoreDb ? $homeGoalsDb : '' ?></span>

          <span class="thread-card__pill <?= $showFinalOnLoad ? 'thread-card__pill--final' : '' ?>">
            <?php
              if ($showFinalOnLoad) {
                echo 'Final';
              } elseif ($isTodayGame) {
                $pd = format_puck_drop_time($t['start_time'] ?? '');
                echo htmlspecialchars($pd ?: 'Game Day');
              } else {
                echo '&nbsp;';
              }
            ?>
          </span>
        </span><br>

      <?php endif; ?>

      <span class="thread-card__counts">
        <?= (int)$t['post_count'] ?> post<?= ((int)$t['post_count'] === 1) ? '' : 's' ?>
      </span>
    </div>
  </div>
</a>

<?php endforeach; ?>

</div>

<div id="thread-sentinel" class="thread-sentinel"></div>
<button id="thread-load-more" class="thread-load-more" hidden type="button">Load more threads</button>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
