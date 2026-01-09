<?php
// public/ncaa_stats.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

// Force NCAA league context so header/nav/theme behave correctly
if (!isset($_GET['league'])) {
  $_GET['league'] = 'ncaa';
}

// Page-specific assets
$pageCss = ['/assets/css/ncaa-stats.css'];
$pageJs  = ['/assets/js/table-sort.js'];

/**
 * ----------------------------
 * Logo helpers
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
    // 'st cloud st' => 'st cloud state',
  ];
  return $aliases[$key] ?? $key;
}

function ncaa_logo_slug_for_team($teamName, $logoMap) {
  $key = ncaa_logo_alias_key(ncaa_logo_key($teamName));
  if ($key !== '' && isset($logoMap[$key])) return $logoMap[$key];
  return slugify($teamName);
}

// Load + normalize logo map keys
$ncaaLogoMap = [];
$logoMapPath = __DIR__ . '/includes/ncaa_logo_map.php';
if (file_exists($logoMapPath)) {
  $raw = require $logoMapPath;
  if (is_array($raw)) {
    foreach ($raw as $k => $v) {
      $nk = ncaa_logo_alias_key(ncaa_logo_key($k));
      if ($nk !== '' && $v !== '') $ncaaLogoMap[$nk] = $v;
    }
  }
}

/**
 * ------------------------------
 * Team filter
 * ------------------------------
 */
$teamParam  = isset($_GET['team']) ? trim((string)$_GET['team']) : '';
$teamFilter = ($teamParam === '' || strcasecmp($teamParam, 'ALL') === 0 || strcasecmp($teamParam, 'ALL_TEAMS') === 0)
  ? ''
  : $teamParam;

// Get distinct teams for dropdown
$teamsStmt = $pdo->prepare("
  SELECT DISTINCT team_name_short
  FROM ncaa_player_gamelogs
  WHERE league = 'ncaa'
    AND team_name_short IS NOT NULL
    AND team_name_short <> ''
  ORDER BY team_name_short ASC
");
$teamsStmt->execute();
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * ------------------------------
 * Aggregate player stats
 * ------------------------------
 */
$sql = "
  SELECT
    player_name,
    player_first_name,
    player_last_name,
    team_name_short,
    SUM(goals)         AS goals,
    SUM(assists)       AS assists,
    SUM(points)        AS points,
    SUM(pim_minutes)   AS pim,
    SUM(plus_minus)    AS plus_minus,
    SUM(shots)         AS shots,
    SUM(faceoff_won)   AS fo_won,
    SUM(faceoff_lost)  AS fo_lost,
    SUM(blocks)        AS blocks,
    COUNT(*)           AS games_played
  FROM ncaa_player_gamelogs
  WHERE league = 'ncaa'
    AND participated = 1
";

$params = [];

if ($teamFilter !== '') {
  $sql .= " AND team_name_short = :team_name_short";
  $params[':team_name_short'] = $teamFilter;
}

$sql .= "
  GROUP BY
    player_name,
    player_first_name,
    player_last_name,
    team_name_short
  HAVING SUM(points) > 0
  ORDER BY points DESC, goals DESC, player_last_name ASC
  LIMIT 250
";

$statsStmt = $pdo->prepare($sql);
$statsStmt->execute($params);
$players = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * ------------------------------
 * Page chrome
 * ------------------------------
 */
$pageTitle = 'NCAA Player Stats';
$bodyClass = 'page-stats';

include __DIR__ . '/includes/header.php';
?>

<div class="page-section page-stats__section">
  <div class="page-stats__header">
    <div>
      <h1>NCAA Player Stats</h1>
      <p class="page-stats__subtitle">
        Skater leaders – total points (goals + assists)<br>
        <span class="page-stats__note">
          Includes only games where the player participated.
        </span>
      </p>
    </div>

    <form method="get" class="page-stats__filters">
      <input type="hidden" name="league" value="ncaa">
      <label>
        Team:
        <select name="team" onchange="this.form.submit()">
          <option value="">All teams</option>
          <?php foreach ($teams as $teamRow): ?>
            <?php $shortName = (string)$teamRow['team_name_short']; ?>
            <option
              value="<?= htmlspecialchars($shortName, ENT_QUOTES, 'UTF-8') ?>"
              <?= ($teamFilter === $shortName) ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($shortName, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>

  <?php if (!$players): ?>
    <p class="page-stats__empty">
      No skater stats found<?= $teamFilter ? ' for ' . htmlspecialchars($teamFilter, ENT_QUOTES, 'UTF-8') : '' ?>.
    </p>
  <?php else: ?>
    <div class="stats-card">
      <div class="table-scroll">
        <table class="stats-table js-sortable-table">
          <thead>
            <tr>
              <th class="stats-table__col-player" data-sort-type="string">Player</th>
              <th class="stats-table__col-gp" data-sort-type="number">GP</th>
              <th class="stats-table__col-g" data-sort-type="number">G</th>
              <th class="stats-table__col-a" data-sort-type="number">A</th>
              <th class="stats-table__col-pts" data-sort-type="number">PTS</th>
              <th class="stats-table__col-plusminus" data-sort-type="number">+/-</th>
              <th class="stats-table__col-pim" data-sort-type="number">PIM</th>
              <th class="stats-table__col-shots" data-sort-type="number">SOG</th>
              <th class="stats-table__col-fo" data-sort-type="number">FO W</th>
              <th class="stats-table__col-fo" data-sort-type="number">FO L</th>
              <th class="stats-table__col-blocks" data-sort-type="number">BLK</th>
              <th class="stats-table__col-team" data-sort-type="string">Team</th>
            </tr>
          </thead>

          <tbody>
            <?php $rank = 0; ?>
            <?php foreach ($players as $p): ?>
              <?php
                $rank++;

                $fullName = (string)($p['player_name'] ?? '');
                if ($fullName === '') {
                  $fullName = trim(((string)($p['player_first_name'] ?? '')) . ' ' . ((string)($p['player_last_name'] ?? '')));
                }
                if ($fullName === '') $fullName = 'Unknown Player';

                $teamShort = (string)($p['team_name_short'] ?? '');

                $logoSlug  = null;
                if ($teamShort !== '' && !empty($ncaaLogoMap)) {
                  $logoSlug = ncaa_logo_slug_for_team($teamShort, $ncaaLogoMap);
                }
                $logoSrc = $logoSlug ? '/assets/img/ncaa-logos/' . $logoSlug . '.svg' : '';

                // Row theming class (matches public/assets/css/ncaa-color-scheme.css)
                $teamSlug = $logoSlug ?: slugify($teamShort);
                $teamSlug = strtolower((string)$teamSlug);
                $teamSlug = preg_replace('/[^a-z0-9\-]+/', '-', $teamSlug);
                $teamSlug = preg_replace('/-+/', '-', $teamSlug);
                $teamSlug = trim($teamSlug, '-');
                $teamClass = $teamSlug ? ('ncaa-team-' . $teamSlug) : '';
              ?>

              <tr class="stats-table__row <?= htmlspecialchars($teamClass, ENT_QUOTES, 'UTF-8') ?>">
                <td class="stats-table__cell-player" data-label="Player">
                  <div class="stats-table__player">
                    <span class="stats-table__rank" aria-label="Rank #<?= (int)$rank ?>">#<?= (int)$rank ?></span>

                    <?php if ($logoSrc): ?>
                      <span class="stats-table__player-logo" aria-hidden="true">
                        <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                      </span>
                    <?php endif; ?>

                    <span class="stats-table__player-name">
                      <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </td>

                <!-- 6 stats first row on mobile: GP, G, A, PTS, +/-, PIM -->
                <td class="stats-table__cell-gp" data-label="GP"><?= (int)($p['games_played'] ?? 0) ?></td>
                <td class="stats-table__cell-g" data-label="G"><?= (int)($p['goals'] ?? 0) ?></td>
                <td class="stats-table__cell-a" data-label="A"><?= (int)($p['assists'] ?? 0) ?></td>
                <td class="stats-table__cell-pts" data-label="PTS"><?= (int)($p['points'] ?? 0) ?></td>
                <td class="stats-table__cell-plusminus" data-label="+/-"><?= (int)($p['plus_minus'] ?? 0) ?></td>
                <td class="stats-table__cell-pim" data-label="PIM"><?= (int)($p['pim'] ?? 0) ?></td>

                <!-- rest on second row on mobile -->
                <td class="stats-table__cell-shots" data-label="SOG"><?= (int)($p['shots'] ?? 0) ?></td>
                <td class="stats-table__cell-fo" data-label="FO W"><?= (int)($p['fo_won'] ?? 0) ?></td>
                <td class="stats-table__cell-fo" data-label="FO L"><?= (int)($p['fo_lost'] ?? 0) ?></td>
                <td class="stats-table__cell-blocks" data-label="BLK"><?= (int)($p['blocks'] ?? 0) ?></td>

                <td class="stats-table__cell-team" data-label="Team">
                  <?= htmlspecialchars($teamShort, ENT_QUOTES, 'UTF-8') ?>
                </td>
              </tr>

            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
