<?php
// public/stats_adv.php
session_start();

require_once __DIR__ . '/../config/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$themeTeam = isset($_SESSION['ui_team']) ? strtolower((string)$_SESSION['ui_team']) : 'all';
$themeTeam = preg_replace('/[^a-z0-9]+/i', '', $themeTeam);
if ($themeTeam === '' || $themeTeam === 'allteams' || $themeTeam === 'all_teams') $themeTeam = 'all';

$teamCode = (isset($_GET['team']) && $_GET['team'] !== '') ? strtolower((string)$_GET['team']) : $themeTeam;
$teamCode = preg_replace('/[^a-z0-9]+/i', '', $teamCode);
if ($teamCode === '' || $teamCode === 'allteams' || $teamCode === 'all_teams') $teamCode = 'all';

$stateKey = isset($_GET['state']) && $_GET['state'] !== '' ? strtolower(trim((string)$_GET['state'])) : '5v5';
if (!in_array($stateKey, ['5v5','ev','all','pp','sh'], true)) $stateKey = '5v5';

$season = isset($_GET['season']) ? trim((string)$_GET['season']) : '';
if ($season !== '' && !preg_match('/^\d{4}-\d{4}$/', $season)) $season = '';

$allowedGp = [0,5,10,15,20,25,30,40,50,60];
$minGp = isset($_GET['min_gp']) ? (int)$_GET['min_gp'] : 0;
if (!in_array($minGp, $allowedGp, true)) $minGp = 0;

$table = 'nhl_players_advanced_stats';

// Team list from adv table (like you already had)
$teamsStmt = $pdo->query("
  SELECT DISTINCT team_abbr
  FROM {$table}
  WHERE team_abbr <> ''
  ORDER BY team_abbr
");
$teamAbbrs = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

// Seasons list
$seasonRows = $pdo->query("
  SELECT DISTINCT season
  FROM {$table}
  WHERE season IS NOT NULL AND season <> ''
  ORDER BY season DESC
")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'NHL Water Cooler – Advanced Stats';
$bodyClass = 'page-stats page-adv-stats';

$pageCss = [
  '/assets/css/stats.css',
  '/assets/css/player-card.css',
  '/assets/css/adv-player-cards.css',
];

$pageJs = [
  '/assets/js/stats-adv.js?v=3',
  '/assets/js/stats-adv-player-cards.js?v=1',
];

require __DIR__ . '/includes/header.php';

// Advanced tab link should preserve filters
$qs = [
  'team' => $teamCode,
  'state' => $stateKey,
];
if ($season !== '') $qs['season'] = $season;
if ($minGp > 0) $qs['min_gp'] = $minGp;

$advHref = 'stats_adv.php?' . http_build_query($qs);
?>

<div class="page-stats__inner">
  <div class="page-stats__top-bar">
    <h1>Advanced Stats</h1>

    <div class="page-stats__controls">
      <div class="stats-toggle">
        <a href="stats.php?team=<?= h($teamCode) ?>" class="button-ghost stats-toggle__button">Stats</a>
        <a href="<?= h($advHref) ?>" class="button stats-toggle__button stats-toggle__button--active">Advanced</a>
        <a href="standings.php?team=<?= h($teamCode) ?>" class="button-ghost stats-toggle__button">Standings</a>
      </div>

      <div class="team-filter">
        <label for="stats-team">Team:</label>
        <select id="stats-team" name="team">
          <option value="all" <?= $teamCode === 'all' ? 'selected' : '' ?>>All Teams</option>
          <?php foreach ($teamAbbrs as $abbr): $val = strtolower($abbr); ?>
            <option value="<?= h($val) ?>" <?= $teamCode === $val ? 'selected' : '' ?>><?= h($abbr) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="team-filter">
        <label for="stats-state">State:</label>
        <select id="stats-state" name="state">
          <?php foreach (['5v5'=>'5v5','ev'=>'EV','all'=>'ALL','pp'=>'PP','sh'=>'SH'] as $k=>$lbl): ?>
            <option value="<?= h($k) ?>" <?= $stateKey === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="team-filter">
        <label for="stats-season">Season:</label>
        <select id="stats-season" name="season">
          <option value="" <?= $season === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($seasonRows as $s): ?>
            <option value="<?= h($s) ?>" <?= $season === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="team-filter">
        <label for="stats-min-gp">GP:</label>
        <select id="stats-min-gp" name="min_gp">
          <option value="0" <?= $minGp === 0 ? 'selected' : '' ?>>All</option>
          <?php foreach ([5,10,15,20,25,30,40,50,60] as $gp): ?>
            <option value="<?= h($gp) ?>" <?= $minGp === $gp ? 'selected' : '' ?>><?= h($gp) ?>+</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <section class="stats-panel stats-panel--full">
    <div class="stats-panel__inner">
      <header class="stats-panel__header">
        <h2 class="stats-panel__title">
          <span id="stats-team-label"><?= $teamCode === 'all' ? 'All Teams' : strtoupper($teamCode) ?></span>
          — <?= h(strtoupper($stateKey)) ?> Advanced Stats
        </h2>
        <div class="stats-panel__sub" id="adv-meta"></div>
      </header>

      <!-- Cards render here (ALL sizes; vertical scroll only) -->
      <div id="adv-cards" class="adv-cards" aria-label="Advanced Stats Player Cards"></div>

      <!-- Keep the table in markup for parity/debug; CSS hides it -->
      <div class="stats-table-wrapper" id="adv-table-wrap">
        <table class="stats-table" id="stats-table" data-has-player-cards="1">
          <thead>
            <tr>
              <th class="col-logo" aria-label="Team"></th>
              <th data-sort-key="player" class="col-player">Player</th>
              <th data-sort-key="pos" class="col-pos">Pos</th>
              <th data-sort-key="gp" class="col-gp">GP</th>
              <th data-sort-key="toi" class="col-gp">TOI</th>
              <th data-sort-key="impact" class="col-impact">Impact</th>
              <th data-sort-key="cf60" class="col-gp">CF/60</th>
              <th data-sort-key="ca60" class="col-gp">CA/60</th>
              <th data-sort-key="cfpct" class="col-gp">CF%</th>
              <th data-sort-key="xgf60" class="col-gp">xGF/60</th>
              <th data-sort-key="xga60" class="col-gp">xGA/60</th>
              <th data-sort-key="xgfpct" class="col-gp">xGF%</th>
              <th data-sort-key="scf60" class="col-gp">SCF/60</th>
              <th data-sort-key="hdcf60" class="col-gp">HDCF/60</th>
              <th data-sort-key="gar" class="col-gp">GAR</th>
              <th data-sort-key="war" class="col-gp">WAR</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <div class="stats-empty" id="stats-empty" hidden>No stats found for this selection.</div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
