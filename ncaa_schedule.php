<?php
// public/ncaa_schedule.php

require_once __DIR__ . '/../config/db.php';

// Page chrome
$pageTitle = 'NCAA Schedule';
$bodyClass = 'page-schedule league-ncaa';
$pageCss   = '/assets/css/schedule-grid.css';

require __DIR__ . '/includes/header.php';

$tz = new DateTimeZone('America/New_York');
$todayYmd = (new DateTime('today', $tz))->format('Y-m-d');

/**
 * ----------------------------
 * Helpers (match rankings style)
 * ----------------------------
 */
function slugify($text) {
  $text = strtolower((string)$text);
  $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
  return trim($text, '-') ?: 'item';
}

function ncaa_logo_key($name) {
  $name = strtolower(trim((string)$name));
  $name = str_replace(['&'], ['and'], $name);
  $name = str_replace(["'", "."], ['', ''], $name);
  $name = preg_replace('/\s+/', ' ', $name);
  $name = preg_replace('/[^a-z0-9 ]+/', '', $name);
  $name = preg_replace('/\s+/', ' ', $name);
  return trim($name);
}

function ncaa_logo_alias_key($key) {
  static $aliases = [
    // Add as needed
  ];
  return $aliases[$key] ?? $key;
}

function ncaa_logo_slug_for_team($teamName, $logoMap) {
  $key = ncaa_logo_alias_key(ncaa_logo_key($teamName));
  if ($key !== '' && isset($logoMap[$key])) {
    return $logoMap[$key];
  }
  return slugify($teamName);
}

function ncaa_team_code_from_row(array $g, string $side): string {
  $char6 = strtoupper(trim((string)($g[$side . '_team_char6'] ?? '')));
  if ($char6 !== '') return $char6;
  return strtoupper(trim((string)($g[$side . '_team_short'] ?? '')));
}

function ncaa_format_local_time(array $g, DateTimeZone $tz): string {
  $s = trim((string)($g['start_time_local_str'] ?? ''));
  if ($s !== '') return $s;

  $t = trim((string)($g['start_time'] ?? ''));
  if ($t === '') return '';

  $dt = DateTime::createFromFormat('H:i:s', $t, $tz);
  if ($dt instanceof DateTime) return $dt->format('g:i A');

  $dt2 = DateTime::createFromFormat('H:i', $t, $tz);
  if ($dt2 instanceof DateTime) return $dt2->format('g:i A');

  return $t;
}

/**
 * ----------------------------
 * Logo map loading (normalized)
 * ----------------------------
 */
$ncaaLogoMap = [];
$logoMapPath = __DIR__ . '/includes/ncaa_logo_map.php';
if (file_exists($logoMapPath)) {
  $raw = require $logoMapPath;
  if (is_array($raw)) {
    foreach ($raw as $k => $v) {
      $nk = ncaa_logo_alias_key(ncaa_logo_key($k));
      if ($nk !== '' && $v !== '') {
        $ncaaLogoMap[$nk] = $v;
      }
    }
  }
}

/**
 * ----------------------------
 * Team dropdown (char6/short)
 * ----------------------------
 */
$teamsStmt = $pdo->query("
  SELECT team_code FROM (
    SELECT DISTINCT COALESCE(NULLIF(TRIM(home_team_char6), ''), TRIM(home_team_short)) AS team_code
    FROM ncaa_games
    UNION
    SELECT DISTINCT COALESCE(NULLIF(TRIM(away_team_char6), ''), TRIM(away_team_short)) AS team_code
    FROM ncaa_games
  ) t
  WHERE team_code IS NOT NULL AND team_code <> ''
  ORDER BY team_code
");
$teams = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);
$teams = array_values(array_unique(array_map(function($t){
  return strtoupper(trim((string)$t));
}, $teams)));

/**
 * ----------------------------
 * Mode: team list vs league grid
 * ----------------------------
 */
$selectedTeam = strtoupper(trim((string)($_GET['team'] ?? '')));
if ($selectedTeam === 'ALL') $selectedTeam = '';
if ($selectedTeam !== '' && !in_array($selectedTeam, $teams, true)) {
  $selectedTeam = '';
}

function build_url($base, array $params) {
  $q = http_build_query(array_filter($params, function($v) {
    return $v !== null && $v !== '';
  }));
  return $q ? ($base . '?' . $q) : $base;
}

$selfUrl = strtok($_SERVER['REQUEST_URI'], '?');

/**
 * ----------------------------
 * Date window + paging (GRID ONLY)
 * ----------------------------
 * Optional ?start=YYYY-MM-DD
 */
$startParam = $_GET['start'] ?? null;
if ($startParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)) {
  $startDate = new DateTime($startParam, $tz);
} else {
  $startDate = new DateTime('now', $tz);
}
$startDate->setTime(0, 0, 0);

$endDate       = (clone $startDate)->modify('+6 days');
$mobileEndDate = (clone $startDate)->modify('+4 days');

$prevStart = (clone $startDate)->modify('-7 days')->format('Y-m-d');
$nextStart = (clone $startDate)->modify('+7 days')->format('Y-m-d');

/**
 * ----------------------------
 * Build date columns (7) (GRID ONLY)
 * ----------------------------
 */
$dates = [];
$dateObjs = [];
$cursor = clone $startDate;
for ($i = 0; $i < 7; $i++) {
  $dates[] = $cursor->format('Y-m-d');
  $dateObjs[] = clone $cursor;
  $cursor->modify('+1 day');
}
$dateIndex = array_flip($dates);

$dowMap = [
  'Mon' => 'M',
  'Tue' => 'T',
  'Wed' => 'W',
  'Thu' => 'Th',
  'Fri' => 'F',
  'Sat' => 'S',
  'Sun' => 'Su',
];

/**
 * ----------------------------
 * GRID MODE data (only if no team selected)
 * ----------------------------
 */
$teamGames = [];
$b2b = [];

if ($selectedTeam === '') {
  foreach ($teams as $t) {
    $teamGames[$t] = array_fill(0, 7, []);
  }

  $stmt = $pdo->prepare("
    SELECT
      game_date,
      start_time,
      start_time_local_str,
      home_team_short, home_team_char6, home_rank,
      away_team_short, away_team_char6, away_rank
    FROM ncaa_games
    WHERE game_date BETWEEN :start AND :end
    ORDER BY game_date ASC, start_time ASC
  ");
  $stmt->execute([
    ':start' => $startDate->format('Y-m-d'),
    ':end'   => $endDate->format('Y-m-d'),
  ]);
  $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($games as $g) {
    $dateKey = $g['game_date'];
    if (!isset($dateIndex[$dateKey])) continue;
    $dayIdx = $dateIndex[$dateKey];

    $home = ncaa_team_code_from_row($g, 'home');
    $away = ncaa_team_code_from_row($g, 'away');

    $homeRank = trim((string)($g['home_rank'] ?? ''));
    $awayRank = trim((string)($g['away_rank'] ?? ''));

    $timeStr = ncaa_format_local_time($g, $tz);

    foreach ([$home, $away] as $code) {
      if (!isset($teamGames[$code])) {
        $teamGames[$code] = array_fill(0, 7, []);
        $teams[] = $code;
      }
    }

    $oppAway = ($awayRank !== '' ? ('#' . $awayRank . ' ') : '') . $away;
    $teamGames[$home][$dayIdx][] = ['rel' => 'vs', 'opponent' => $oppAway, 'time' => $timeStr];

    $oppHome = ($homeRank !== '' ? ('#' . $homeRank . ' ') : '') . $home;
    $teamGames[$away][$dayIdx][] = ['rel' => '@', 'opponent' => $oppHome, 'time' => $timeStr];
  }

  $teams = array_values(array_unique($teams));
  sort($teams);

  foreach ($teams as $t) {
    $indices = [];
    for ($i = 0; $i < 7; $i++) {
      if (!empty($teamGames[$t][$i])) $indices[] = $i;
    }
    sort($indices);
    $b2b[$t] = [];
    for ($j = 0; $j < count($indices) - 1; $j++) {
      if ($indices[$j + 1] === $indices[$j] + 1) {
        $b2b[$t][$indices[$j]] = true;
        $b2b[$t][$indices[$j + 1]] = true;
      }
    }
  }
}

/**
 * ----------------------------
 * TEAM MODE rows (UPCOMING FIRST, then recent past)
 * ----------------------------
 */
$teamRows = [];
$teamSummary = null;

if ($selectedTeam !== '') {
  $stmt = $pdo->prepare("
    SELECT
      g.game_id,
      g.game_date,
      g.start_time,
      g.start_time_local_str,
      g.home_team_short, g.home_team_char6, g.home_score,
      g.away_team_short, g.away_team_char6, g.away_score,
      CASE WHEN b.id IS NULL THEN 0 ELSE 1 END AS has_boxscore
    FROM ncaa_games g
    LEFT JOIN ncaa_boxscores b ON b.game_id = g.game_id
    WHERE (
      UPPER(COALESCE(NULLIF(TRIM(g.home_team_char6), ''), TRIM(g.home_team_short))) = :team
      OR
      UPPER(COALESCE(NULLIF(TRIM(g.away_team_char6), ''), TRIM(g.away_team_short))) = :team
    )
    ORDER BY
      CASE WHEN g.game_date >= :today THEN 0 ELSE 1 END ASC,
      CASE WHEN g.game_date >= :today THEN g.game_date END ASC,
      CASE WHEN g.game_date <  :today THEN g.game_date END DESC,
      CASE WHEN g.game_date >= :today THEN g.start_time END ASC,
      CASE WHEN g.game_date <  :today THEN g.start_time END DESC
  ");
  $stmt->execute([
    ':team'  => $selectedTeam,
    ':today' => $todayYmd,
  ]);
  $teamRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($teamRows)) {
    $minD = $teamRows[0]['game_date'];
    $maxD = $teamRows[count($teamRows)-1]['game_date'];
    $teamSummary = [
      'count' => count($teamRows),
      'min'   => $minD,
      'max'   => $maxD,
    ];
  }
}
?>

<div class="schedule-grid-page">

  <div class="schedule-grid-page__header <?= $selectedTeam ? 'schedule-grid-page__header--team' : '' ?>">
    <?php if ($selectedTeam === ''): ?>
      <div class="schedule-grid-page__nav">
        <a class="schedule-grid-page__arrow"
           href="<?= htmlspecialchars(build_url($selfUrl, ['start' => $prevStart])) ?>"
           aria-label="Previous 7 days" title="Previous 7 days">←</a>
      </div>
    <?php endif; ?>

    <div>
      <h1 class="schedule-grid-page__title">
        <span class="schedule-grid-page__title--desktop">
          <?= $selectedTeam ? htmlspecialchars($selectedTeam) . ' — Full Schedule' : '7-Day NCAA Schedule' ?>
        </span>
        <span class="schedule-grid-page__title--mobile">
          <?= $selectedTeam ? htmlspecialchars($selectedTeam) . ' — Full Schedule' : '5-Day NCAA Schedule' ?>
        </span>
      </h1>

      <?php if ($selectedTeam === ''): ?>
        <div class="schedule-grid-page__range">
          <span class="schedule-grid-page__range--desktop">
            <?= htmlspecialchars($startDate->format('M j')) ?> – <?= htmlspecialchars($endDate->format('M j')) ?>
          </span>
          <span class="schedule-grid-page__range--mobile">
            <?= htmlspecialchars($startDate->format('M j')) ?> – <?= htmlspecialchars($mobileEndDate->format('M j')) ?>
          </span>
        </div>
      <?php else: ?>
        <div class="schedule-grid-page__range schedule-grid-page__range--team">
          <?php if ($teamSummary): ?>
            <?= (int)$teamSummary['count'] ?> games •
            <?= htmlspecialchars($teamSummary['min']) ?> → <?= htmlspecialchars($teamSummary['max']) ?>
          <?php else: ?>
            No games found.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Team dropdown -->
      <form method="get" class="schedule-team-switch">
        <?php if ($selectedTeam === ''): ?>
          <input type="hidden" name="start" value="<?= htmlspecialchars($startDate->format('Y-m-d')) ?>">
        <?php endif; ?>

        <label>
          Team:
          <select name="team" onchange="this.form.submit()">
            <option value="" <?= $selectedTeam === '' ? 'selected' : '' ?>>League Grid</option>
            <?php foreach ($teams as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>" <?= $selectedTeam === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars($t) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
    </div>

    <?php if ($selectedTeam === ''): ?>
      <div class="schedule-grid-page__nav" style="justify-content:flex-end;">
        <a class="schedule-grid-page__arrow"
           href="<?= htmlspecialchars(build_url($selfUrl, ['start' => $nextStart])) ?>"
           aria-label="Next 7 days" title="Next 7 days">→</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($selectedTeam !== ''): ?>
    <!-- =========================================================
         TEAM MODE (UPCOMING FIRST)
         ========================================================= -->
    <section class="schedule-team-mode">
      <?php if (empty($teamRows)): ?>
        <div class="schedule-team-mode__empty">
          No games found for <?= htmlspecialchars($selectedTeam) ?>.
        </div>
      <?php else: ?>
        <div class="schedule-team-mode__card">
          <div class="table-scroll">
            <table class="schedule-team-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Game</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($teamRows as $g): ?>
                  <?php
                    $homeCode  = ncaa_team_code_from_row($g, 'home');
                    $awayCode  = ncaa_team_code_from_row($g, 'away');

                    $homeShort = (string)($g['home_team_short'] ?? $homeCode);
                    $awayShort = (string)($g['away_team_short'] ?? $awayCode);

                    $homeLogoSlug = ncaa_logo_slug_for_team($homeShort, $ncaaLogoMap);
                    $awayLogoSlug = ncaa_logo_slug_for_team($awayShort, $ncaaLogoMap);

                    $homeLogo = "/assets/img/ncaa-logos/" . $homeLogoSlug . ".svg";
                    $awayLogo = "/assets/img/ncaa-logos/" . $awayLogoSlug . ".svg";

                    $hasBox   = !empty($g['has_boxscore']);
                    $scoresOk = ($g['home_score'] !== null && $g['away_score'] !== null);

                    $isHomeSel = ($homeCode === $selectedTeam);
                    $rel = $isHomeSel ? 'vs' : '@';

                    $selScore = $isHomeSel ? $g['home_score'] : $g['away_score'];
                    $oppScore = $isHomeSel ? $g['away_score'] : $g['home_score'];

                    $wlClass = '';
                    if ($hasBox && $scoresOk) {
                      if ((int)$selScore > (int)$oppScore) $wlClass = 'schedule-team-table__row--win';
                      elseif ((int)$selScore < (int)$oppScore) $wlClass = 'schedule-team-table__row--loss';
                      else $wlClass = 'schedule-team-table__row--tie';
                    }

                    $isUpcoming = (!empty($g['game_date']) && $g['game_date'] >= $todayYmd);
                    $whenClass  = $isUpcoming ? 'schedule-team-table__row--upcoming' : 'schedule-team-table__row--past';

                    // Theme class uses HOME team slug (your original behavior)
                    $teamSlug = strtolower((string)$homeLogoSlug);
                    $teamSlug = preg_replace('/[^a-z0-9\-]+/', '-', $teamSlug);
                    $teamSlug = preg_replace('/-+/', '-', $teamSlug);
                    $teamSlug = trim($teamSlug, '-');
                    $themeClass = $teamSlug ? ('ncaa-team-' . $teamSlug) : '';

                    $dateLabel = '';
                    if (!empty($g['game_date'])) {
                      $dt = new DateTime($g['game_date'], $tz);
                      $dateLabel = $dt->format('D n/j');
                    }
                    $timeLabel = ncaa_format_local_time($g, $tz);
                  ?>

                  <tr class="thread-card schedule-team-table__row <?= htmlspecialchars(trim($themeClass . ' ' . $wlClass . ' ' . $whenClass), ENT_QUOTES, 'UTF-8') ?>">
                    <td class="schedule-team-table__date" data-label="Date">
                      <div class="schedule-team-table__dateline">
                        <span class="schedule-team-table__d"><?= htmlspecialchars($dateLabel) ?></span>
                        <?php if ($timeLabel): ?>
                          <span class="schedule-team-table__t"><?= htmlspecialchars($timeLabel) ?></span>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td class="schedule-team-table__game" data-label="Game">
                      <span class="schedule-team-table__side">
                        <span class="schedule-team-table__team"><?= htmlspecialchars($awayCode) ?></span>
                        <span class="schedule-team-table__score">
                          <?= ($hasBox && $scoresOk) ? (int)$g['away_score'] : '—' ?>
                        </span>
                        <img class="schedule-team-table__logo" src="<?= htmlspecialchars($awayLogo) ?>" alt="">
                      </span>

                      <span class="schedule-team-table__rel"><?= htmlspecialchars($rel) ?></span>

                      <span class="schedule-team-table__side">
                        <img class="schedule-team-table__logo" src="<?= htmlspecialchars($homeLogo) ?>" alt="">
                        <span class="schedule-team-table__score">
                          <?= ($hasBox && $scoresOk) ? (int)$g['home_score'] : '—' ?>
                        </span>
                        <span class="schedule-team-table__team"><?= htmlspecialchars($homeCode) ?></span>
                      </span>
                    </td>
                  </tr>

                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <!-- =========================================================
         LEAGUE GRID MODE (7-day window)
         ========================================================= -->
    <div class="schedule-grid-wrap">
      <table class="schedule-grid">
        <thead>
          <tr>
            <th class="schedule-grid__head schedule-grid__head-team schedule-grid__col-team">Team</th>
            <?php foreach ($dateObjs as $dt): ?>
              <?php
                $dowShort = $dt->format('D');
                $dowLabel = $dowMap[$dowShort] ?? $dowShort;
              ?>
              <th class="schedule-grid__head schedule-grid__col-day">
                <span class="schedule-grid__dow"><?= htmlspecialchars($dowLabel) ?></span>
                <span class="schedule-grid__date"><?= htmlspecialchars($dt->format('n/j')) ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($teams as $team): ?>
            <tr>
              <th scope="row" class="schedule-grid__team-label"><?= htmlspecialchars($team) ?></th>

              <?php for ($i = 0; $i < 7; $i++): ?>
                <?php
                  $cellGames    = $teamGames[$team][$i] ?? [];
                  $hasGame      = !empty($cellGames);
                  $isBackToBack = !empty($b2b[$team][$i]);

                  $cellClasses = ['schedule-grid__cell'];
                  if ($hasGame) $cellClasses[] = 'schedule-grid__cell--game';
                  if ($isBackToBack) $cellClasses[] = 'schedule-grid__cell--b2b';
                ?>
                <td class="<?= implode(' ', $cellClasses) ?>">
                  <?php if ($hasGame): ?>
                    <?php foreach ($cellGames as $info): ?>
                      <div class="schedule-grid__matchup">
                        <span class="schedule-grid__matchup-primary">
                          <?= htmlspecialchars($info['rel'] . ' ' . $info['opponent']) ?>
                        </span>
                        <?php if (!empty($info['time'])): ?>
                          <span class="schedule-grid__time"><?= htmlspecialchars($info['time']) ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
