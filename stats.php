<?php
// public/stats.php
session_start();

require_once __DIR__ . '/../config/db.php';

/**
 * Theme-based default:
 *   - If the user already has a ui_team saved in session, we use that
 *     as the default filter (so opening stats shows that team).
 *   - Otherwise we fall back to 'all'.
 */
$themeTeam = isset($_SESSION['ui_team']) ? strtolower($_SESSION['ui_team']) : 'all';
$themeTeam = preg_replace('/[^a-z0-9]+/i', '', $themeTeam);
if ($themeTeam === '' || $themeTeam === 'all_teams') {
  $themeTeam = 'all';
}

/**
 * Current filter:
 *   - ?team=xxx in the URL overrides themeTeam for the STATS FILTER
 *   - The actual global theme is handled in includes/header.php
 *     (which reads ?team= and updates $_SESSION['ui_team']).
 */
$teamCode = isset($_GET['team']) && $_GET['team'] !== ''
  ? strtolower($_GET['team'])
  : $themeTeam;

$teamCode = preg_replace('/[^a-z0-9]+/i', '', $teamCode);
if ($teamCode === '' || $teamCode === 'all_teams') {
  $teamCode = 'all';
}

// pull teams from msf_player_gamelogs
$table = 'msf_player_gamelogs';
$teamsStmt = $pdo->query("
  SELECT DISTINCT team_abbr
  FROM {$table}
  WHERE team_abbr <> ''
  ORDER BY team_abbr
");
$teamAbbrs = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'NHL Water Cooler – Team Stats';
$bodyClass = 'page-stats';

// Load core stats styles + player-card styles
$pageCss = [
  '/assets/css/stats.css',
  '/assets/css/player-card.css',
];

// Load core stats JS + player-card expansion JS
$pageJs = [
  '/assets/js/stats.js',
  '/assets/js/stats-player-cards.js',
];

require __DIR__ . '/includes/header.php';
?>

<div class="page-stats__inner">
  <div class="page-stats__top-bar">
    <h1>Team Stats</h1>

    <div class="page-stats__controls">
      <!-- Stats / Standings toggle -->
      <div class="stats-toggle">
        <a
          href="stats.php?team=<?= htmlspecialchars($teamCode) ?>"
          class="button stats-toggle__button stats-toggle__button--active"
        >
          Stats
        </a>
        <a
          href="stats_adv.php?team=all&state=5v5"
          class="button-ghost stats-toggle__button"
        >
          Advanced Stats
        </a>
        <a
          href="standings.php"
          class="button-ghost stats-toggle__button"
        >
          Standings
        </a>
      </div>

      <!-- Team dropdown -->
      <div class="team-filter">
        <label for="stats-team">Team:</label>
        <select id="stats-team" name="team">
          <option value="all" <?= $teamCode === 'all' ? 'selected' : '' ?>>
            All Teams
          </option>

          <?php foreach ($teamAbbrs as $abbr): ?>
            <?php $val = strtolower($abbr); ?>
            <option
              value="<?= htmlspecialchars($val) ?>"
              <?= $teamCode === $val ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($abbr) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <section class="stats-panel stats-panel--full">
    <div class="stats-panel__inner">
      <header class="stats-panel__header">
        <h2 class="stats-panel__title">
          <span id="stats-team-label">
            <?= $teamCode === 'all' ? 'All Teams' : strtoupper($teamCode) ?>
          </span>
          Stats
        </h2>
      </header>

      <div class="stats-table-wrapper">
        <table
          class="stats-table"
          id="stats-table"
          data-has-player-cards="1"
        >
      <thead>
        <tr>
          <th class="col-logo" aria-label="Team"></th>

          <th data-sort-key="player" class="col-player">Player</th>
          <th data-sort-key="position" class="col-pos">Pos</th>
          <th data-sort-key="games" class="col-gp">GP</th>
          <th data-sort-key="goals" class="col-g">G</th>
          <th data-sort-key="assists" class="col-a">A</th>
          <th data-sort-key="points" class="col-pts">PTS</th>
          <th data-sort-key="shots" class="col-sog">SOG</th>
          <th data-sort-key="pim" class="col-pim">PIM</th>

          <th class="col-team">Team</th>
          <th class="col-jersey">#</th>
        </tr>
      </thead>

          <tbody>
            <!-- JS injects rows (each row should set data-player-id="MSF_ID") -->
          </tbody>
        </table>
      </div>

      <div class="stats-empty" id="stats-empty" hidden>
        No stats found for this selection.
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
