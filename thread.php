<?php
// public/thread.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------
// NCAA logo map + normalization helpers
// ---------------------------------------------------------
$ncaaLogoMap = array();
$logoMapPath = __DIR__ . '/includes/ncaa_logo_map.php';
if (file_exists($logoMapPath)) {
  $rawMap = require $logoMapPath;
  if (is_array($rawMap)) {
    foreach ($rawMap as $k => $v) {
      $nk = strtolower(trim((string)$k));
      $nk = str_replace(array('&'), array('and'), $nk);
      $nk = str_replace(array("'", ".", ","), array('', '', ''), $nk);
      $nk = preg_replace('/\s+/', ' ', $nk);
      $nk = trim($nk);
      if ($nk !== '' && $v !== '') $ncaaLogoMap[$nk] = $v;
    }
  }
}

function ncaa_logo_slug($teamName, $logoMap) {
  $k = strtolower(trim((string)$teamName));
  $k = str_replace(array('&'), array('and'), $k);
  $k = str_replace(array("'", ".", ","), array('', '', ''), $k);
  $k = preg_replace('/\s+/', ' ', $k);
  $k = trim($k);

  if ($k !== '' && isset($logoMap[$k])) return $logoMap[$k];

  $fallback = strtolower((string)$teamName);
  $fallback = preg_replace('/[^a-z0-9]+/i', '-', $fallback);
  $fallback = trim($fallback, '-') ?: 'team';
  return $fallback;
}

// ---------------------------------------------------------
// NCAA helpers
// ---------------------------------------------------------
function ncaa_slugify($name) {
  $name = trim((string)$name);
  if ($name === '') return '';
  $name = str_replace(array("'", ".", ","), "", $name);
  $name = preg_replace('/[\s\/]+/', '-', $name);
  $name = preg_replace('/[^a-zA-Z-]/', '', $name);
  return strtolower($name);
}

function ncaa_get_home_team_name($thread) {
  $home = '';

  if (!empty($thread['description_html'])) {
    if (preg_match('/<strong>(.*?)<\/strong>/i', $thread['description_html'], $m)) {
      $inner = $m[1];
      $parts = preg_split('/@/i', $inner);
      if (count($parts) >= 2) {
        $home = trim($parts[1]);
        $home = preg_replace('/#\d+\s*/', '', $home);
        $home = trim($home);
        if ($home !== '') return $home;
      }
    }
  }

  if (!empty($thread['title'])) {
    if (preg_match('/\bat\s+(?:#\d+\s*)?([A-Za-z .\'-]+?)(?=\s*\()/i', $thread['title'], $m)) {
      $home = trim($m[1]);
      if ($home !== '') return $home;
    }
  }

  return '';
}

function normalize_mysql_time($t) {
  $t = trim((string)$t);
  if ($t === '') return '';
  if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
  return '';
}

// ---------------------------------------------------------
// Thread ID
// ---------------------------------------------------------
$thread_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$thread_id) {
  http_response_code(404);
  echo "Thread not found";
  exit;
}

// ---------------------------------------------------------
// Load thread + author
// ---------------------------------------------------------
$stmt = $pdo->prepare("
  SELECT t.*, u.username AS author_name
  FROM gameday_threads t
  JOIN users u ON u.id = t.created_by
  WHERE t.id = :id
");
$stmt->execute(array(':id' => $thread_id));
$thread = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$thread) {
  http_response_code(404);
  echo "Thread not found";
  exit;
}

// ---------------------------------------------------------
// League detection (nhl / ncaa)
// ---------------------------------------------------------
$leagueType = 'nhl';

if (!empty($_GET['league'])) {
  $leagueType = strtolower((string)$_GET['league']);
} elseif (!empty($thread['league'])) {
  $dbLeague = strtoupper((string)$thread['league']);
  if ($dbLeague === 'NHL') $leagueType = 'nhl';
  elseif ($dbLeague === 'NCAAH') $leagueType = 'ncaa';
}

if ($leagueType !== 'nhl' && $leagueType !== 'ncaa') $leagueType = 'nhl';

// ---------------------------------------------------------
// Pull in only what we need depending on league
// ---------------------------------------------------------
if ($leagueType === 'nhl') {
  require_once __DIR__ . '/api/thread_api_lineups.php';
  require_once __DIR__ . '/api/thread_injuries_api.php';
  require_once __DIR__ . '/api/thread_nhl_boxscore.php';
  require_once __DIR__ . '/api/thread_adv_stats.php';
} else {
  require_once __DIR__ . '/api/thread_ncaa_boxscore.php';
}

$isAdmin       = !empty($_SESSION['is_admin']);
$currentUserId = (int)(isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
$isLoggedIn    = $currentUserId > 0;

// NHL team abbrs
$homeAbbr = '';
$awayAbbr = '';

// NCAA header logos/data
$ncaaHomeShort = '';
$ncaaAwayShort = '';
$ncaaHomeLogo  = '';
$ncaaAwayLogo  = '';

// Boxscore polling attrs
$boxscoreLeague    = $leagueType;
$boxscoreMsfGameId = '';
$boxscoreContestId = '';
$boxscoreGameDate  = trim(isset($thread['game_date']) ? (string)$thread['game_date'] : '');
$boxscoreGameTime  = normalize_mysql_time(isset($thread['start_time']) ? (string)$thread['start_time'] : '');

// ---------------------------------------------------------
// Lineups + Injuries + Boxscore block
// ---------------------------------------------------------
$lineupsHtml  = '';
$injuriesHtml = '';
$boxscoreHtml = '';

$gid = 0;
$gameRow = null;

if ($leagueType === 'nhl' && !empty($thread['external_game_id'])) {
  $gid = (int)$thread['external_game_id'];
  $boxscoreMsfGameId = (string)$gid;

  try {
    $stmtGame = $pdo->prepare("
      SELECT *
      FROM msf_games
      WHERE msf_game_id = :gid
      LIMIT 1
    ");
    $stmtGame->execute(array(':gid' => $gid));
    $gameRow = $stmtGame->fetch(PDO::FETCH_ASSOC);

    if ($gameRow) {
      $homeAbbr = isset($gameRow['home_team_abbr']) ? (string)$gameRow['home_team_abbr'] : '';
      $awayAbbr = isset($gameRow['away_team_abbr']) ? (string)$gameRow['away_team_abbr'] : '';

      if (!empty($gameRow['game_date'])) {
        $boxscoreGameDate = trim((string)$gameRow['game_date']);
      }
    }
  } catch (Exception $e) {
    error_log('[thread.php] NHL gameRow load failed: ' . $e->getMessage());
    $gameRow = null;
  }
}

$contestId = 0;
$gameDate  = '';
if ($leagueType === 'ncaa' && !empty($thread['ncaa_game_id'])) {
  $contestId = (int)$thread['ncaa_game_id'];
  $boxscoreContestId = (string)$contestId;
  $gameDate = isset($thread['game_date']) ? (string)$thread['game_date'] : '';
}

// 1) LINEUPS
if ($leagueType === 'nhl' && $gameRow && $gid) {
  try {
    $ha = $homeAbbr ? $homeAbbr : (isset($gameRow['home_team_abbr']) ? (string)$gameRow['home_team_abbr'] : '');
    $aa = $awayAbbr ? $awayAbbr : (isset($gameRow['away_team_abbr']) ? (string)$gameRow['away_team_abbr'] : '');
    $lineupsHtml = sjms_get_lineups_html($pdo, $gid, $ha, $aa);
  } catch (Exception $e) {
    error_log('[thread.php] NHL lineups failed: ' . $e->getMessage());
    $lineupsHtml = '';
  }
}

// 2) INJURIES
if ($leagueType === 'nhl' && $gameRow) {
  try {
    $ha = $homeAbbr ? $homeAbbr : (isset($gameRow['home_team_abbr']) ? (string)$gameRow['home_team_abbr'] : '');
    $aa = $awayAbbr ? $awayAbbr : (isset($gameRow['away_team_abbr']) ? (string)$gameRow['away_team_abbr'] : '');

    $gdate = isset($gameRow['game_date']) ? (string)$gameRow['game_date'] : '';
    $injuriesHtml = sjms_get_injuries_html($pdo, $ha, $aa, $gdate);
  } catch (Exception $e) {
    error_log('[thread.php] NHL injuries failed: ' . $e->getMessage());
    $injuriesHtml = '';
  }
}

// 3) BOXSCORE
if ($leagueType === 'nhl' && $gameRow) {
  try {
    $boxscoreHtml = sjms_get_boxscore_html($pdo, $gameRow);
  } catch (Exception $e) {
    error_log('[thread.php] NHL boxscore failed: ' . $e->getMessage());
    $boxscoreHtml = '';
  }
}

if ($leagueType === 'ncaa' && $contestId) {
  try {
    $threadStartTime = (string)(isset($thread['start_time']) ? $thread['start_time'] : '');
    $boxscoreHtml = sjms_get_ncaa_boxscore_html($pdo, $contestId, $gameDate, $threadStartTime);
  } catch (Exception $e) {
    error_log('[thread.php] NCAA boxscore failed: ' . $e->getMessage());
    $boxscoreHtml = '';
  }

  try {
    $stmtNcaa = $pdo->prepare("
      SELECT home_team_short, away_team_short
      FROM ncaa_games
      WHERE game_id = :gid
      LIMIT 1
    ");
    $stmtNcaa->execute(array(':gid' => $contestId));
    $ng = $stmtNcaa->fetch(PDO::FETCH_ASSOC);

    if ($ng) {
      $ncaaHomeShort = trim(isset($ng['home_team_short']) ? (string)$ng['home_team_short'] : '');
      $ncaaAwayShort = trim(isset($ng['away_team_short']) ? (string)$ng['away_team_short'] : '');
    }

    if (($ncaaHomeShort === '' || $ncaaAwayShort === '') && !empty($thread['title'])) {
      if (preg_match('/^NCAA:\s*(.+?)\s+at\s+(?:#\d+\s*)?(.+?)\s*\(/i', (string)$thread['title'], $m)) {
        if ($ncaaAwayShort === '') $ncaaAwayShort = trim($m[1]);
        if ($ncaaHomeShort === '') $ncaaHomeShort = trim($m[2]);
      }
    }

    if ($ncaaHomeShort !== '') $ncaaHomeLogo = ncaa_logo_slug($ncaaHomeShort, $ncaaLogoMap);
    if ($ncaaAwayShort !== '') $ncaaAwayLogo = ncaa_logo_slug($ncaaAwayShort, $ncaaLogoMap);
  } catch (Exception $e) {
    error_log('[thread.php] NCAA logo resolution failed: ' . $e->getMessage());
  }
}

// ---------------------------------------------------------
// Theme: DO NOT persist auto theme from thread to session
// ---------------------------------------------------------
// thread.php (BEFORE include header.php)
$themeOverrideSlug = '';

if (empty($_SESSION['theme_team_slug'])) {
  if ($leagueType === 'nhl') {
    $fallback = $homeAbbr ? strtolower($homeAbbr) : '';
    if ($fallback === '' && $awayAbbr) $fallback = strtolower($awayAbbr);
    if ($fallback !== '') $themeOverrideSlug = $fallback;
  } else {
    $homeName = ncaa_get_home_team_name($thread);
    $fallback = $homeName !== '' ? ncaa_slugify($homeName) : '';
    if ($fallback !== '') $themeOverrideSlug = $fallback;
  }
}

// ---------------------------------------------------------
// SEO helpers
// ---------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$canonicalUrl = $scheme . '://' . $host . '/thread.php?id=' . $thread_id . '&league=' . $leagueType;

$rawDesc = trim(strip_tags(isset($thread['description_html']) ? (string)$thread['description_html'] : ''));
$rawDesc = preg_replace('/\s+/', ' ', $rawDesc);
if ($rawDesc === '') $rawDesc = 'Live hockey game discussion on NHL Water Cooler.';
$metaDescription = mb_substr($rawDesc, 0, 160);

$ogImage = null;
if (!empty($thread['header_image_url'])) {
  $relPath = ltrim(preg_replace('#^\.\./#', '', (string)$thread['header_image_url']), '/');
  $ogImage = $scheme . '://' . $host . '/' . $relPath;
}

$ldJson = json_encode(array(
  '@context' => 'https://schema.org',
  '@type'    => 'DiscussionForumPosting',
  'headline' => isset($thread['title']) ? (string)$thread['title'] : '',
  'datePublished' => isset($thread['created_at']) ? (string)$thread['created_at'] : '',
  'author'   => array(
    '@type' => 'Person',
    'name'  => isset($thread['author_name']) ? (string)$thread['author_name'] : '',
  ),
  'mainEntityOfPage' => $canonicalUrl,
  'description'      => $metaDescription,
  'image'            => $ogImage ? array($ogImage) : array(),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------
// Page meta + assets
// ---------------------------------------------------------
$pageTitle = (isset($thread['title']) ? (string)$thread['title'] : '') . ' – Sharks Message Board';

// IMPORTANT: do NOT set old theme classes here (team-*, ncaa-team-*).
// Let includes/header.php emit: league-* + theme-team-* based on session theme_team_slug.
$bodyClass = 'page-thread';

$pageCss = array(
  '/assets/css/thread.css',
  '/assets/css/injuries.css',
  '/assets/css/lineups.css',
  '/assets/css/post.css',
  '/assets/css/boxscore.css',
  '/assets/css/adv-stats.css'
);

$pageJs  = array(
  'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js',
  '/assets/js/thread-theme.js',
  '/assets/js/thread-core.js',
  '/assets/js/thread-data.js',
  '/assets/js/thread-boxscore-live.js',
  '/assets/js/thread.js',
  '/assets/js/thread-replied-to.js',
  '/assets/js/thread_editor.js',
  '/assets/js/charts/svg-impact.js',
  '/assets/js/charts/xg-quadrant.js',
  '/assets/js/charts/svg-gar.js',
  '/assets/js/charts/svg-war.js',
  '/assets/js/charts/svg-boot.js',
  '/assets/js/thread-advstats-load.js'
);

include __DIR__ . '/includes/header.php';

// data-home-team / data-away-team
if ($leagueType === 'nhl') {
  $dtHome = $homeAbbr ? strtolower($homeAbbr) : '';
  $dtAway = $awayAbbr ? strtolower($awayAbbr) : '';
} else {
  $dtHome = $ncaaHomeShort ? strtolower($ncaaHomeShort) : '';
  $dtAway = $ncaaAwayShort ? strtolower($ncaaAwayShort) : '';
}
?>

<div
  id="thread-root"
  class="thread-page"
  data-thread-id="<?php echo (int)$thread_id; ?>"
  data-is-admin="<?php echo $isAdmin ? 1 : 0; ?>"
  data-current-user-id="<?php echo (int)$currentUserId; ?>"
  data-home-team="<?php echo htmlspecialchars($dtHome, ENT_QUOTES); ?>"
  data-away-team="<?php echo htmlspecialchars($dtAway, ENT_QUOTES); ?>"
>
  <article class="thread-header">
    <?php if ($isAdmin): ?>
      <div class="thread-admin-actions">
        <a href="/admin_edit_thread.php?id=<?php echo (int)$thread_id; ?>" class="thread-admin-actions__edit button">
          Edit Thread
        </a>

        <form method="post" action="/admin_delete_thread.php" class="thread-admin-actions__form">
          <input type="hidden" name="thread_id" value="<?php echo (int)$thread_id; ?>">
          <button type="submit" class="thread-admin-actions__delete">
            Delete Thread
          </button>
        </form>
      </div>
    <?php endif; ?>

    <div class="thread-header__title-row">
      <?php if ($leagueType === 'ncaa' && ($ncaaHomeLogo || $ncaaAwayLogo)): ?>
        <div class="thread-header__logo-strip thread-header__logo-strip--inline">
          <?php if ($ncaaAwayLogo): ?>
            <span class="thread-header__logo thread-header__logo--away">
              <img
                src="/assets/img/ncaa-logos/<?php echo htmlspecialchars($ncaaAwayLogo); ?>.svg"
                alt="<?php echo htmlspecialchars($ncaaAwayShort); ?>"
                loading="lazy"
              >
            </span>
          <?php endif; ?>

          <span class="thread-header__at">@</span>

          <?php if ($ncaaHomeLogo): ?>
            <span class="thread-header__logo thread-header__logo--home">
              <img
                src="/assets/img/ncaa-logos/<?php echo htmlspecialchars($ncaaHomeLogo); ?>.svg"
                alt="<?php echo htmlspecialchars($ncaaHomeShort); ?>"
                loading="lazy"
              >
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h1 class="thread-header__title">
        <?php echo htmlspecialchars(isset($thread['title']) ? (string)$thread['title'] : ''); ?>
      </h1>
    </div>

    <div class="thread-header__meta">
      <span class="thread-header__game-date">
        Game date: <?php echo htmlspecialchars(isset($thread['game_date']) ? (string)$thread['game_date'] : ''); ?>
      </span>
      <span class="thread-header__author">
        Posted by <?php echo htmlspecialchars(isset($thread['author_name']) ? (string)$thread['author_name'] : ''); ?>
        on <?php echo htmlspecialchars(isset($thread['created_at']) ? (string)$thread['created_at'] : ''); ?>
      </span>
    </div>

    <?php if (!empty($thread['header_image_url'])): ?>
      <?php
        $img = trim((string)$thread['header_image_url']);
        if (!preg_match('#^(https?://|/)#i', $img)) {
          $img = '/' . ltrim($img, '/');
        }
      ?>
      <div class="thread-header__image-wrap">
        <img src="<?php echo htmlspecialchars($img); ?>" alt="">
      </div>
    <?php endif; ?>

    <div class="thread-description">
      <?php echo isset($thread['description_html']) ? (string)$thread['description_html'] : ''; ?>
    </div>

    <?php if (!empty($boxscoreHtml)): ?>
      <section class="thread-boxscore-section <?php echo ($leagueType === 'ncaa') ? 'thread-boxscore-section--ncaa' : ''; ?>">
        <div
          id="boxscore-root"
          data-league="<?php echo htmlspecialchars($boxscoreLeague); ?>"
          data-msf-game-id="<?php echo htmlspecialchars($boxscoreMsfGameId); ?>"
          data-contest-id="<?php echo htmlspecialchars($boxscoreContestId); ?>"
          data-game-date="<?php echo htmlspecialchars($boxscoreGameDate); ?>"
          data-game-time="<?php echo htmlspecialchars($boxscoreGameTime); ?>"
        >
          <?php echo $boxscoreHtml; ?>
        </div>
      </section>
    <?php endif; ?>
  </article>

  <?php if (!empty($lineupsHtml)): ?>
    <section class="thread-lineups-section">
      <?php echo $lineupsHtml; ?>
    </section>
  <?php endif; ?>

  <?php if (!empty($injuriesHtml)): ?>
    <section class="thread-injuries-section">
      <?php echo $injuriesHtml; ?>
    </section>
  <?php endif; ?>

  <?php if ($leagueType === 'nhl' && $gid): ?>
    <section class="thread-adv-stats-shell" id="thread-adv-stats" data-game-id="<?php echo (int)$gid; ?>">
      <div class="adv-loading">Loading advanced stats…</div>
    </section>
  <?php endif; ?>

  <section class="thread-posts">
    <?php if ($isLoggedIn): ?>
      <div class="new-post-form">
        <h3>New Post</h3>
        <div id="replying-to" class="replying-to" style="display:none;"></div>

        <div class="new-post-form__toolbar">
          <button id="emoji-toggle" type="button" class="button">😀 Emoji</button>
          <div id="emoji-panel" class="emoji-panel" hidden></div>
        </div>

        <div id="editor" class="wysiwyg" contenteditable="true"></div>
        <input type="hidden" id="parent_id" value="">

        <div class="new-post-form__actions">
          <button id="submit-post" type="button" class="button">Post</button>
          <button id="cancel-reply" type="button" class="button" style="display:none;">Cancel reply</button>
        </div>
      </div>
    <?php else: ?>
      <div class="thread-login-prompt">
        <p>Sign in or register to comment.</p>
        <div class="thread-login-prompt__actions">
          <a href="/login.php?redirect=thread.php%3Fid=<?php echo (int)$thread_id; ?>" class="button">Sign In</a>
          <a href="/register.php" class="button">Register</a>
        </div>
      </div>
    <?php endif; ?>

    <div id="post-list" class="post-list"></div>
    <div id="post-sentinel" class="thread-sentinel"></div>
    <button id="post-load-more" class="thread-load-more" type="button" hidden>
      Load more posts
    </button>
  </section>
</div>

<div
  id="image-modal"
  class="image-modal"
  role="dialog"
  aria-modal="true"
  aria-label="Enlarged image"
  hidden
>
  <div class="image-modal__inner">
    <button type="button" class="image-modal__close" aria-label="Close image">&times;</button>
    <img src="" alt="" class="image-modal__img">
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
