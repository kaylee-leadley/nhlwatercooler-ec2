<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
$msfConfig = require __DIR__ . '/../../config/msf.php';

/**
 * ---------------------------------------------------------
 * NCAA LOGO MAP (normalized)
 * ---------------------------------------------------------
 */
$ncaaLogoMap = [];
$logoMapPath = __DIR__ . '/../includes/ncaa_logo_map.php';
if (file_exists($logoMapPath)) {
  $rawMap = require $logoMapPath;
  if (is_array($rawMap)) {
    foreach ($rawMap as $k => $v) {
      $nk = strtolower(trim((string)$k));
      $nk = str_replace(['&'], ['and'], $nk);
      $nk = str_replace(["'", ".", ","], ['', '', ''], $nk);
      $nk = preg_replace('/\s+/', ' ', $nk);
      $nk = trim($nk);

      if ($nk !== '' && $v !== '') {
        $ncaaLogoMap[$nk] = $v;
      }
    }
  }
}

/**
 * Helpers (same as index.php)
 */
function slugify($text) {
  $text = strtolower($text);
  $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
  return trim($text, '-') ?: 'thread';
}

/**
 * Normalize an NCAA team name the same way as the logo map keys.
 */
function ncaa_norm_key($name) {
  $k = strtolower(trim((string)$name));
  $k = str_replace(['&'], ['and'], $k);
  $k = str_replace(["'", ".", ","], ['', '', ''], $k);
  $k = preg_replace('/\s+/', ' ', $k);
  return trim($k);
}

/**
 * Logo resolver: NCAA (handles caps/whitespace/punctuation + a couple aliases)
 */
function ncaa_logo_slug($short, array $map) {
  $short = trim((string)$short);

  // Aliases you already needed
  if (strcasecmp($short, 'Army West Point') === 0) {
    $short = 'Army';
  }
  if (strcasecmp($short, 'RIT') === 0) {
    $short = 'Rochester Inst.';
  }

  $key = ncaa_norm_key($short);
  if ($key !== '' && isset($map[$key])) {
    return $map[$key];
  }

  // fallback auto-slugify
  return slugify($short);
}

function ncaa_format_team($short, $rank) {
  $short = trim($short ?? '');
  $rank  = trim($rank ?? '');
  if ($short === '') return '';
  return $rank !== '' ? "#{$rank} {$short}" : $short;
}

function ncaa_render_matchup($t, $isTodayGame) {
  $awayShort = $t['away_team_short'] ?? '';
  $homeShort = $t['home_team_short'] ?? '';
  $awayRank  = $t['away_rank'] ?? '';
  $homeRank  = $t['home_rank'] ?? '';

  $awayScore = isset($t['away_score']) ? (int)$t['away_score'] : null;
  $homeScore = isset($t['home_score']) ? (int)$t['home_score'] : null;
  $hasFinal  = ($awayScore !== null && $homeScore !== null);

  $awayName = ncaa_format_team($awayShort, $awayRank);
  $homeName = ncaa_format_team($homeShort, $homeRank);

  $awayClass = "thread-card__abbr thread-card__abbr--away";
  $homeClass = "thread-card__abbr thread-card__abbr--home";
  $asClass   = "thread-card__score-num thread-card__score-num--away";
  $hsClass   = "thread-card__score-num thread-card__score-num--home";

  if ($hasFinal && !$isTodayGame) {
    if ($homeScore > $awayScore) {
      $homeClass .= " thread-card__abbr--win";
      $hsClass   .= " thread-card__score-num--win";
      $awayClass .= " thread-card__abbr--loss";
      $asClass   .= " thread-card__score-num--loss";
    } else {
      $awayClass .= " thread-card__abbr--win";
      $asClass   .= " thread-card__score-num--win";
      $homeClass .= " thread-card__abbr--loss";
      $hsClass   .= " thread-card__score-num--loss";
    }
  }
  ?>
  <span class="thread-card__matchup">
    <span class="<?= $awayClass ?>"><?= htmlspecialchars($awayName) ?></span>
    <span class="<?= $asClass ?>"><?= ($hasFinal && !$isTodayGame) ? $awayScore : '' ?></span>

    <span class="thread-card__at">@</span>

    <span class="<?= $homeClass ?>"><?= htmlspecialchars($homeName) ?></span>
    <span class="<?= $hsClass ?>"><?= ($hasFinal && !$isTodayGame) ? $homeScore : '' ?></span>

    <span class="thread-card__pill <?= $hasFinal ? 'thread-card__pill--final' : '' ?>">
      <?= $hasFinal ? 'Final' : ($isTodayGame ? 'Game Day' : '&nbsp;') ?>
    </span>
  </span>
  <?php
}

/* -----------------------------------------------------------
   Timezone
----------------------------------------------------------- */
$tzName   = $msfConfig['timezone'] ?? 'America/New_York';
$tz       = new DateTimeZone($tzName);
$nowTz    = new DateTime('now', $tz);
$todayYmd = $nowTz->format('Y-m-d');

/* -----------------------------------------------------------
   REQUEST PARAMS
----------------------------------------------------------- */
$offset    = max(0, (int)($_GET['offset'] ?? 0));
$limit     = max(1, (int)($_GET['limit']  ?? 20));
$teamRaw   = trim($_GET['team']  ?? 'ALL');
$search    = trim($_GET['search'] ?? '');
$leagueKey = strtolower(trim($_GET['league'] ?? 'nhl'));

if ($teamRaw === '' || strcasecmp($teamRaw, 'ALL') === 0) {
  $teamRaw = 'ALL';
}

$leagueDb = ($leagueKey === 'ncaa') ? 'NCAAH' : 'NHL';

/**
 * Team filter normalization
 */
if ($leagueDb === 'NHL') {
  $teamFilter = strtoupper($teamRaw);
  if ($teamFilter !== 'ALL' && strlen($teamFilter) > 3) {
    $teamFilter = substr($teamFilter, 0, 3);
  }
} else {
  $teamFilter = $teamRaw;
}

/* -----------------------------------------------------------
   THREAD QUERY
----------------------------------------------------------- */
$sql = "
  SELECT
    t.id,
    t.title,
    t.game_date,
    t.created_at,
    t.header_image_url,
    t.external_game_id,
    t.ncaa_game_id,
    t.league,
    u.username AS author_name,
    COUNT(DISTINCT p.id) AS post_count,
";

if ($leagueDb === 'NHL') {
  $sql .= "
    g.home_team_abbr,
    g.away_team_abbr,
    MAX(CASE WHEN tgl.team_abbr = g.home_team_abbr THEN tgl.goals_for END) AS home_goals,
    MAX(CASE WHEN tgl.team_abbr = g.away_team_abbr THEN tgl.goals_for END) AS away_goals
  ";
}

if ($leagueDb === 'NCAAH') {
  $sql .= "
    MAX(ng.home_team_short) AS home_team_short,
    MAX(ng.away_team_short) AS away_team_short,
    MAX(ng.home_rank)       AS home_rank,
    MAX(ng.away_rank)       AS away_rank,
    MAX(ntg_home.goals)     AS home_score,
    MAX(ntg_away.goals)     AS away_score
  ";
}

$sql .= "
  FROM gameday_threads t
  JOIN users u ON u.id = t.created_by
  LEFT JOIN posts p ON p.thread_id = t.id AND p.is_deleted = 0
";

if ($leagueDb === 'NHL') {
  $sql .= "
    LEFT JOIN msf_games g
      ON g.msf_game_id = t.external_game_id
    LEFT JOIN msf_team_gamelogs tgl
      ON tgl.msf_game_id = t.external_game_id
  ";
}

if ($leagueDb === 'NCAAH') {
  $sql .= "
    LEFT JOIN ncaa_games ng
      ON ng.game_id = t.ncaa_game_id
    LEFT JOIN ncaa_team_gamelogs ntg_home
      ON ntg_home.contest_id = t.ncaa_game_id AND ntg_home.team_side='home'
    LEFT JOIN ncaa_team_gamelogs ntg_away
      ON ntg_away.contest_id = t.ncaa_game_id AND ntg_away.team_side='away'
  ";
}

$params = [];
$where  = ["t.league = :league"];
$params[':league'] = $leagueDb;

if ($teamFilter !== 'ALL') {
  if ($leagueDb === 'NHL') {
    $where[]         = "(g.home_team_abbr = :team OR g.away_team_abbr = :team)";
    $params[':team'] = $teamFilter;
  } else {
    $where[]      = "(ntg_home.team_name_short = :s OR ntg_away.team_name_short = :s)";
    $params[':s'] = $teamFilter;
  }
}

if ($search !== '') {
  $where[]           = "(t.title LIKE :search OR u.username LIKE :search)";
  $params[':search'] = "%{$search}%";
}

$sql .= " WHERE " . implode(" AND ", $where);

$sql .= "
  GROUP BY t.id
  ORDER BY t.game_date DESC, t.created_at DESC
  LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);

$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------
   Visibility gating (same as index.php)
----------------------------------------------------------- */
$visible = [];
foreach ($threads as $t) {

  if (empty($t['game_date'])) {
    $visible[] = $t;
    continue;
  }

  try {
    $dt = new DateTime($t['game_date'], $tz);
  } catch (Throwable $e) {
    $visible[] = $t;
    continue;
  }

  $gameYmd = $dt->format('Y-m-d');

  if ($gameYmd > $todayYmd) continue;

  if ($gameYmd === $todayYmd) {
    $ten = new DateTime($t['game_date'] . " 10:00:00", $tz);
    if ($nowTz < $ten) continue;
  }

  $visible[] = $t;
}

$threads = $visible;
$count   = count($threads);

if ($count === 0) {
  echo json_encode([
    'ok' => true,
    'html' => '',
    'count' => 0,
    'page_size' => $limit,
  ]);
  exit;
}

/* -----------------------------------------------------------
   RENDER HTML IDENTICAL TO index.php
----------------------------------------------------------- */
ob_start();

foreach ($threads as $t):

  $gameDate = $t['game_date'] ?? '';
  $isToday  = ($gameDate === $todayYmd);

  $slug     = slugify($t['title']);
  $dateSeg  = $gameDate ?: 'no-date';

  $isCustom = !empty($t['header_image_url']) &&
              $t['header_image_url'] !== 'assets/img/gameday-placeholder.png';

  // NHL
  $homeAbbr = $t['home_team_abbr'] ?? '';
  $awayAbbr = $t['away_team_abbr'] ?? '';

  // NCAA
  $homeShort = $t['home_team_short'] ?? '';
  $awayShort = $t['away_team_short'] ?? '';

  $homeSlug = ncaa_logo_slug($homeShort, $ncaaLogoMap);
  $awaySlug = ncaa_logo_slug($awayShort, $ncaaLogoMap);
  // --------------------------------------------------
  // Per-card HOME TEAM theme class (for CSS variables)
  // --------------------------------------------------
  $homeTeamClass = '';

  if ($leagueDb === 'NHL') {
    $home = strtolower(trim((string)($homeAbbr ?? '')));
    if ($home !== '') {
      $homeTeamClass = ' team-' . preg_replace('/[^a-z0-9\-]+/i', '', $home);
    }
  } else { // NCAA
    $home = slugify($homeShort ?? '');
    if ($home !== '' && $home !== 'thread') {
      $homeTeamClass = ' ncaa-team-' . $home;
    }
  }
?>
<a href="/thread/<?= htmlspecialchars($dateSeg) ?>/<?= htmlspecialchars($slug) ?>-<?= (int)$t['id'] ?>?league=<?= $leagueKey ?>"
      class="thread-card thread-card--league-<?= strtolower($t['league']) ?><?= $homeTeamClass ?>">
  <?php if ($isCustom): ?>
    <div class="thread-card__image"
         style="background-image:url('/<?= htmlspecialchars(ltrim($t['header_image_url'], '/')) ?>');"></div>

  <?php elseif ($leagueDb === 'NHL' && $homeAbbr && $awayAbbr): ?>
    <div class="thread-card__logo-strip">
      <div class="thread-card__logo">
        <img src="/assets/img/logos/<?= htmlspecialchars($awayAbbr) ?>.png" loading="lazy">
      </div>
      <span class="thread-card__at">@</span>
      <div class="thread-card__logo">
        <img src="/assets/img/logos/<?= htmlspecialchars($homeAbbr) ?>.png" loading="lazy">
      </div>
    </div>

  <?php elseif ($leagueDb === 'NCAAH'): ?>
    <div class="thread-card__logo-strip">
      <div class="thread-card__logo">
        <img src="/assets/img/ncaa-logos/<?= htmlspecialchars($awaySlug) ?>.svg"
             alt="<?= htmlspecialchars($awayShort) ?>" loading="lazy">
      </div>
      <span class="thread-card__at">@</span>
      <div class="thread-card__logo">
        <img src="/assets/img/ncaa-logos/<?= htmlspecialchars($homeSlug) ?>.svg"
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
      <span>Game day: <?= htmlspecialchars($gameDate) ?></span><br>
      <span>Posted by <?= htmlspecialchars($t['author_name']) ?></span><br>

      <?php if ($leagueDb === 'NCAAH'): ?>
        <?php ncaa_render_matchup($t, $isToday); ?><br>

      <?php elseif ($homeAbbr && $awayAbbr): ?>
        <?php
          $hg = isset($t['home_goals']) ? (int)$t['home_goals'] : null;
          $ag = isset($t['away_goals']) ? (int)$t['away_goals'] : null;

          $final = ($hg !== null && $ag !== null);

          $hc = "thread-card__abbr thread-card__abbr--home";
          $ac = "thread-card__abbr thread-card__abbr--away";
          $hsc = "thread-card__score-num thread-card__score-num--home";
          $asc = "thread-card__score-num thread-card__score-num--away";

          if ($final) {
            if ($hg > $ag) {
              $hc .= " thread-card__abbr--win";
              $hsc .= " thread-card__score-num--win";
              $ac .= " thread-card__abbr--loss";
              $asc .= " thread-card__score-num--loss";
            } else {
              $ac .= " thread-card__abbr--win";
              $asc .= " thread-card__score-num--win";
              $hc .= " thread-card__abbr--loss";
              $hsc .= " thread-card__score-num--loss";
            }
          }
        ?>

        <span class="thread-card__matchup">
          <span class="<?= $ac ?>"><?= htmlspecialchars($awayAbbr) ?></span>
          <span class="<?= $asc ?>"><?= $final ? $ag : '' ?></span>

          <span class="thread-card__at">@</span>

          <span class="<?= $hc ?>"><?= htmlspecialchars($homeAbbr) ?></span>
          <span class="<?= $hsc ?>"><?= $final ? $hg : '' ?></span>

          <span class="thread-card__pill <?= $final ? 'thread-card__pill--final' : '' ?>">
            <?= $final ? 'Final' : ($isToday ? 'Game Day' : '&nbsp;') ?>
          </span>
        </span><br>

      <?php endif; ?>

      <span class="thread-card__counts">
        <?= (int)$t['post_count'] ?> post<?= ((int)$t['post_count'] === 1 ? '' : 's') ?>
      </span>
    </div>
  </div>
</a>
<?php endforeach;

$html = ob_get_clean();

echo json_encode([
  'ok' => true,
  'html' => $html,
  'count' => $count,
  'page_size' => $limit,
]);
