<?php
// public/includes/header.php

if (!isset($pageTitle)) {
  $pageTitle = 'NHL Water Cooler';
}

$cssFiles = [];
$jsFiles  = [];

// Always load global CSS

$cssFiles[] = '/assets/css/ncaa-color-scheme.css';
$cssFiles[] = '/assets/css/color-scheme.css';
$cssFiles[] = '/assets/css/header.css';
$cssFiles[] = '/assets/css/main.css';
$cssFiles[] = '/assets/css/summernote.css';

// Always load global nav.js (hamburger)
$jsFiles[] = '/assets/js/nav.js';

// Add per-page CSS if provided
if (!empty($pageCss)) {
  if (is_array($pageCss)) $cssFiles = array_merge($cssFiles, $pageCss);
  else $cssFiles[] = $pageCss;
}

// Add per-page JS if provided
if (!empty($pageJs)) {
  if (is_array($pageJs)) $jsFiles = array_merge($jsFiles, $pageJs);
  else $jsFiles[] = $pageJs;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * ------------------------------
 * League resolution (routing/layout only)
 * ------------------------------
 */
$leagueKey = 'nhl';
if (isset($_GET['league']) && strtolower((string)$_GET['league']) === 'ncaa') {
  $leagueKey = 'ncaa';
}
$isNhl  = ($leagueKey === 'nhl');
$isNcaa = ($leagueKey === 'ncaa');

$threadsUrl   = '/index.php?league=' . $leagueKey;
$scheduleUrl  = '/schedule.php?league=' . $leagueKey;
$statsUrl     = '/stats.php?league=' . $leagueKey;
$standingsUrl = '/standings.php?league=' . $leagueKey;

$ncaaRankingsUrl  = '/ncaa_rankings.php?league=' . $leagueKey;
$ncaaScheduleUrl = '/ncaa_schedule.php?league=' . $leagueKey;
$ncaaStatsUrl     = '/ncaa_stats.php?league=' . $leagueKey;
$ncaaStandingsUrl = '/ncaa_standings.php?league=' . $leagueKey;

/**
 * ------------------------------
 * Theme (theme-team-*) resolution
 * ------------------------------
 * Canonical theme classes:
 *   theme-team-<slug>   (NHL or NCAA team/school)
 *   theme-team-all      (generic)
 *   theme-ncaa-all      (generic NCAA)
 *
 * Source of truth:
 *   - $_SESSION['theme_team_slug'] (set via /api/index_header_set_theme.php)
 * Optional sync from URL:
 *   - if ?theme= present -> update session
 *   - else if ?team= present AND not ALL -> update session to slugify(team)
 * NOTE: We do NOT clear/reset theme just because league changed.
 */
function theme_slugify($s) {
  $s = strtolower(trim((string)$s));
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  return trim($s, '-');
}

// Explicit theme override via URL (optional)
if (isset($_GET['theme'])) {
  $incoming = theme_slugify($_GET['theme']);
  if ($incoming === '' || $incoming === 'all' || $incoming === 'all-teams' || $incoming === 'all-schools') {
    unset($_SESSION['theme_team_slug']);
  } else {
    $_SESSION['theme_team_slug'] = $incoming;
  }
}

// If no explicit theme=, allow index-style ?team= to set the theme (only when meaningful)
if (!isset($_GET['theme']) && isset($_GET['team'])) {
  $t = trim((string)$_GET['team']);
  $tLower = strtolower($t);

  // treat ALL variants as "do not override existing theme"
  $isAll = (
    $t === '' ||
    $tLower === 'all' ||
    $tLower === 'all_teams' ||
    $tLower === 'all_schools'
  );

  if (!$isAll) {
    // Accept NHL (e.g. "ANA") or NCAA (e.g. "Air Force") with same slugify
    $_SESSION['theme_team_slug'] = theme_slugify($t);
  }
}

// Decide final theme class
$themeSlug = '';
$themeSource = 'default'; // default | session | override

if (isset($themeOverrideSlug) && trim((string)$themeOverrideSlug) !== '') {
  $themeSlug = theme_slugify($themeOverrideSlug);
  $themeSource = 'override';
} elseif (!empty($_SESSION['theme_team_slug'])) {
  $themeSlug = theme_slugify($_SESSION['theme_team_slug']);
  $themeSource = 'session';
}



if ($themeSlug !== '') {
  $themeClass = 'theme-team-' . $themeSlug;
} else {
  // fallback if nothing selected
  $themeClass = $isNcaa ? 'theme-ncaa-all' : 'theme-team-all';
}

$leagueClass = 'league-' . $leagueKey;

// Merge per-page body classes with content league + theme
$baseBodyClass = isset($bodyClass) ? trim((string)$bodyClass) : '';

$bodyParts = array_filter([
  $baseBodyClass,
  $leagueClass,
  $themeClass,
]);

$flat = preg_split('/\s+/', implode(' ', $bodyParts));
$flat = array_values(array_unique(array_filter(array_map('trim', $flat))));
$bodyClassFinal = trim(implode(' ', $flat));

/**
 * ------------------------------
 * SEO defaults
 * ------------------------------
 */
if (!isset($metaDescription) || $metaDescription === '') {
  $metaDescription = 'NHL Water Cooler – live NHL and NCAA hockey game discussion, gameday threads, lineups, and boxscores.';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$uri    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

if (!isset($canonicalUrl) || $canonicalUrl === '') {
  $canonicalUrl = $scheme . '://' . $host . $uri;
}

if (!isset($ogImage) || $ogImage === '') {
  $ogImage = $scheme . '://' . $host . '/assets/img/social-default.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
  <?php endif; ?>

  <?php if (!empty($canonicalUrl)): ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php endif; ?>

  <meta property="og:site_name" content="NHL Water Cooler">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>">
  <?php if (!empty($canonicalUrl)): ?>
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
  <?php endif; ?>
  <meta property="og:type" content="website">
  <?php if (!empty($ogImage)): ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
  <?php endif; ?>

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription) ?>">
  <?php if (!empty($ogImage)): ?>
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
  <?php endif; ?>

  <?php if (!empty($ldJson)): ?>
    <script type="application/ld+json">
<?= $ldJson . "\n" ?>
    </script>
  <?php endif; ?>

  <link rel="icon" type="image/x-icon" href="/assets/img/watercooler-favicon.ico">

  <?php foreach ($cssFiles as $href): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
  <?php endforeach; ?>

  <?php foreach ($jsFiles as $src): ?>
    <script src="<?= htmlspecialchars($src) ?>" defer></script>
  <?php endforeach; ?>
</head>
<body class="<?= htmlspecialchars($bodyClassFinal) ?>" data-theme-source="<?= htmlspecialchars($themeSource) ?>">
<header class="site-header">
  <div class="site-header__inner">
    <a href="<?= htmlspecialchars($threadsUrl) ?>" class="site-header__brand">
      <img
        src="/assets/img/nhl-water-cooler-logo.png"
        alt="NHL Water Cooler"
        class="site-header__logo"
      >
      <span class="site-header__title">NHL Water Cooler</span>
    </a>

    <button
      class="site-nav-toggle"
      id="site-nav-toggle"
      type="button"
      aria-label="Toggle navigation"
      aria-expanded="false"
      aria-controls="site-nav"
    >
      <span class="site-nav-toggle__bar"></span>
      <span class="site-nav-toggle__bar"></span>
      <span class="site-nav-toggle__bar"></span>
    </button>

    <nav class="site-nav" id="site-nav">
      <div class="site-nav__group site-nav__group--primary">
        <div class="site-nav__item site-nav__item--dropdown">
          <button
            type="button"
            class="site-nav__link site-nav__link--dropdown"
            aria-haspopup="true"
            aria-expanded="false"
          >
            Threads
            <span class="site-nav__caret">▾</span>
          </button>

          <div class="site-nav__dropdown">
            <a
              href="/index.php?league=nhl"
              class="site-nav__dropdown-link <?= $isNhl ? 'site-nav__dropdown-link--active' : '' ?>"
            >
              NHL Threads
            </a>
            <a
              href="/index.php?league=ncaa"
              class="site-nav__dropdown-link <?= $isNcaa ? 'site-nav__dropdown-link--active' : '' ?>"
            >
              NCAA Threads
            </a>
          </div>
        </div>

        <div class="site-nav__item site-nav__item--dropdown">
          <button
            type="button"
            class="site-nav__link site-nav__link--dropdown"
            aria-haspopup="true"
            aria-expanded="false"
          >
            Data
            <span class="site-nav__caret">▾</span>
          </button>

          <div class="site-nav__dropdown">
            <?php if ($isNhl): ?>
              <a href="<?= htmlspecialchars($scheduleUrl) ?>" class="site-nav__dropdown-link">Schedule</a>
              <a href="<?= htmlspecialchars($statsUrl) ?>" class="site-nav__dropdown-link">Statistics</a>
              <a href="<?= htmlspecialchars($standingsUrl) ?>" class="site-nav__dropdown-link">Standings</a>
            <?php else: ?>
              <a href="<?= htmlspecialchars($ncaaRankingsUrl) ?>" class="site-nav__dropdown-link">Rankings</a>
              <a href="<?= htmlspecialchars($ncaaScheduleUrl) ?>" class="site-nav__dropdown-link">Schedule</a>
              <a href="<?= htmlspecialchars($ncaaStatsUrl) ?>" class="site-nav__dropdown-link">Stats</a>
              <a href="<?= htmlspecialchars($ncaaStandingsUrl) ?>" class="site-nav__dropdown-link">Standings</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($_SESSION['is_admin'])): ?>
          <a href="/admin_new_thread.php" class="site-nav__link site-nav__link--primary">New Thread</a>
        <?php endif; ?>
      </div>

      <?php if (!empty($_SESSION['user_id'])): ?>
        <div class="site-nav__group site-nav__group--user">
          <span class="site-nav__user-pill">
            <?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : '' ?>
          </span>

          <a href="/account.php" class="site-nav__link">Account</a>
          <a href="/logout.php" class="site-nav__link site-nav__link--ghost">Logout</a>
        </div>
      <?php else: ?>
        <div class="site-nav__group site-nav__group--user">
          <a href="/login.php" class="site-nav__link site-nav__link--ghost">Login</a>
          <a href="/register.php" class="site-nav__link site-nav__link--primary">Register</a>
        </div>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="site-main">
