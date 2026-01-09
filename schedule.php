<?php
// public/schedule.php

// 1) DB first so session is started before header uses $_SESSION
require_once __DIR__ . '/../config/db.php';

// 2) Page-level vars for header
// Make browser tab title generic so it works for both 5- and 7-day views
$pageTitle = 'League Schedule';
$bodyClass = 'page-schedule';
$pageCss   = '../assets/css/schedule-grid.css';

require __DIR__ . '/includes/header.php';

// 3) Now load MSF config + build grid
$msfConfig = require __DIR__ . '/../config/msf.php';

$tzName = $msfConfig['timezone'] ?? 'America/New_York';
$tz     = new DateTimeZone($tzName);

// --- Date window: today + next 6 days (7 total) ---
$today     = new DateTime('now', $tz);
$startDate = (clone $today);
$startDate->setTime(0, 0, 0);
$endDate = (clone $startDate);
$endDate->modify('+6 days');

// For mobile: 5-day window (start + 4 days)
$mobileEndDate = (clone $startDate);
$mobileEndDate->modify('+4 days');

$dates      = [];
$dateObjs   = [];
$cursorDate = clone $startDate;

for ($i = 0; $i < 7; $i++) {
  $dates[]    = $cursorDate->format('Y-m-d');
  $dateObjs[] = clone $cursorDate;
  $cursorDate->modify('+1 day');
}
$dateIndex = array_flip($dates);

// Map PHP weekday to grid labels
$dowMap = [
  'Mon' => 'M',
  'Tue' => 'T',
  'Wed' => 'W',
  'Thu' => 'Th',
  'Fri' => 'F',
  'Sat' => 'S',
  'Sun' => 'Su',
];

// --- Team list (home ∪ away) ---
$teamsStmt = $pdo->query("
  SELECT abbr FROM (
    SELECT DISTINCT home_team_abbr AS abbr FROM msf_games
    UNION
    SELECT DISTINCT away_team_abbr AS abbr FROM msf_games
  ) t
  ORDER BY abbr
");
$teams = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);
$teams = array_values(array_unique(array_map('strtoupper', $teams)));

// Initialize grid structure
$teamGames = [];
foreach ($teams as $team) {
  $teamGames[$team] = array_fill(0, 7, []);
}

// --- Load games for the 7-day window ---
$gamesStmt = $pdo->prepare("
  SELECT game_date, start_time_utc, home_team_abbr, away_team_abbr
  FROM msf_games
  WHERE game_date BETWEEN :start AND :end
  ORDER BY game_date ASC, start_time_utc ASC
");
$gamesStmt->execute([
  ':start' => $startDate->format('Y-m-d'),
  ':end'   => $endDate->format('Y-m-d'),
]);
$games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fill grid: @ / vs + local time
foreach ($games as $g) {
  $dateKey = $g['game_date'];
  if (!isset($dateIndex[$dateKey])) {
    continue;
  }
  $dayIdx = $dateIndex[$dateKey];

  $home = strtoupper($g['home_team_abbr']);
  $away = strtoupper($g['away_team_abbr']);

  $startUtc = new DateTime($g['start_time_utc'], new DateTimeZone('UTC'));
  $startUtc->setTimezone($tz);
  $timeStr = $startUtc->format('g:i A');

  foreach ([$home, $away] as $code) {
    if (!isset($teamGames[$code])) {
      $teamGames[$code] = array_fill(0, 7, []);
    }
  }

  // Home view
  $teamGames[$home][$dayIdx][] = [
    'rel'      => 'vs',
    'opponent' => $away,
    'time'     => $timeStr,
  ];

  // Away view
  $teamGames[$away][$dayIdx][] = [
    'rel'      => '@',
    'opponent' => $home,
    'time'     => $timeStr,
  ];
}

// --- Back-to-back detection ---
$b2b = [];
foreach ($teams as $team) {
  $indices = [];
  for ($i = 0; $i < 7; $i++) {
    if (!empty($teamGames[$team][$i])) {
      $indices[] = $i;
    }
  }
  sort($indices);
  $b2b[$team] = [];
  $count = count($indices);
  for ($j = 0; $j < $count - 1; $j++) {
    if ($indices[$j + 1] === $indices[$j] + 1) {
      $b2b[$team][$indices[$j]]     = true;
      $b2b[$team][$indices[$j + 1]] = true;
    }
  }
}

// --- Goalie injuries, by team + day index ---
$goalieInjuries = [];

$injStmt = $pdo->prepare("
  SELECT injury_date, team_abbr, position
  FROM injuries
  WHERE injury_date BETWEEN :start AND :end
    AND UPPER(position) LIKE 'G%'  -- catch 'G' / 'GK' etc.
");
$injStmt->execute([
  ':start' => $startDate->format('Y-m-d'),
  ':end'   => $endDate->format('Y-m-d'),
]);

while ($row = $injStmt->fetch(PDO::FETCH_ASSOC)) {
  $injDate = $row['injury_date'];
  if (!isset($dateIndex[$injDate])) {
    continue;
  }

  $idx  = $dateIndex[$injDate];
  $team = strtoupper($row['team_abbr'] ?? '');
  if (!$team) {
    continue;
  }

  if (!isset($goalieInjuries[$team])) {
    $goalieInjuries[$team] = [];
  }

  // mark "this team has at least one injured goalie on this day"
  $goalieInjuries[$team][$idx] = true;
}
?>

<div class="schedule-grid-page">
  <div class="schedule-grid-page__header">
    <div>
      <h1 class="schedule-grid-page__title">
        <span class="schedule-grid-page__title--desktop">7-Day League Schedule</span>
        <span class="schedule-grid-page__title--mobile">5-Day League Schedule</span>
      </h1>

      <div class="schedule-grid-page__range">
        <span class="schedule-grid-page__range--desktop">
          <?= htmlspecialchars($startDate->format('M j')) ?>
          –
          <?= htmlspecialchars($endDate->format('M j')) ?>
        </span>

        <span class="schedule-grid-page__range--mobile">
          <?= htmlspecialchars($startDate->format('M j')) ?>
          –
          <?= htmlspecialchars($mobileEndDate->format('M j')) ?>
        </span>
      </div>
    </div>
  </div>

  <div class="schedule-grid-wrap">
    <table class="schedule-grid">
      <thead>
        <tr>
          <th class="schedule-grid__head schedule-grid__head-team schedule-grid__col-team">
            Team
          </th>
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
            <th scope="row" class="schedule-grid__team-label">
              <?= htmlspecialchars($team) ?>
            </th>

            <?php for ($i = 0; $i < 7; $i++): ?>
              <?php
                $cellGames        = $teamGames[$team][$i] ?? [];
                $hasGame          = !empty($cellGames);
                $isBackToBack     = !empty($b2b[$team][$i]);
                $hasGoalieInjury  = !empty($goalieInjuries[$team][$i]);

                $cellClasses = ['schedule-grid__cell'];
                if ($hasGame) {
                  $cellClasses[] = 'schedule-grid__cell--game';
                }
                if ($isBackToBack) {
                  $cellClasses[] = 'schedule-grid__cell--b2b';
                }
                if ($hasGame && $hasGoalieInjury) {
                  $cellClasses[] = 'schedule-grid__cell--goalie';
                }
              ?>
              <td class="<?= implode(' ', $cellClasses) ?>">
                <?php if ($hasGame && $hasGoalieInjury): ?>
                  <span class="schedule-grid__badge schedule-grid__badge--goalie">
                    G&nbsp;INJ
                  </span>
                <?php endif; ?>

                <?php if ($hasGame): ?>
                  <?php foreach ($cellGames as $info): ?>
                    <div class="schedule-grid__matchup">
                      <span class="schedule-grid__matchup-primary">
                        <?= htmlspecialchars($info['rel'] . ' ' . $info['opponent']) ?>
                      </span>
                      <span class="schedule-grid__time">
                        <?= htmlspecialchars($info['time']) ?>
                      </span>
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
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
