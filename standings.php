<?php
// public/standings.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

$pageTitle = 'NHL Standings';
$bodyClass = 'page-standings league-nhl';
$pageCss   = [
  '/assets/css/stats.css',
  '/assets/css/standings.css', // single CSS file (mobile cards can key off data-label + classes)
];

// NOTE: This PHP renders everything server-side.
// If you want the JS-driven version, we should remove the server rendering and update standings.js accordingly.
require_once __DIR__ . '/includes/header.php';

/**
 * =====================================================================
 * Team meta (since you don't have an msf_teams table)
 * =====================================================================
 */
$TEAM_META = [
  // EAST - Atlantic
  'BOS' => ['name'=>'Boston Bruins',          'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-bos'],
  'BUF' => ['name'=>'Buffalo Sabres',         'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-buf'],
  'DET' => ['name'=>'Detroit Red Wings',      'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-det'],
  'FLA' => ['name'=>'Florida Panthers',       'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-fla'],
  'MTL' => ['name'=>'Montreal Canadiens',     'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-mtl'],
  'OTT' => ['name'=>'Ottawa Senators',        'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-ott'],
  'TBL' => ['name'=>'Tampa Bay Lightning',    'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-tbl'],
  'TOR' => ['name'=>'Toronto Maple Leafs',    'conf'=>'Eastern', 'div'=>'Atlantic',      'class'=>'team-tor'],

  // EAST - Metropolitan
  'CAR' => ['name'=>'Carolina Hurricanes',    'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-car'],
  'CBJ' => ['name'=>'Columbus Blue Jackets',  'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-cbj'],
  'NJD' => ['name'=>'New Jersey Devils',      'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-njd'],
  'NYI' => ['name'=>'New York Islanders',     'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-nyi'],
  'NYR' => ['name'=>'New York Rangers',       'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-nyr'],
  'PHI' => ['name'=>'Philadelphia Flyers',    'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-phi'],
  'PIT' => ['name'=>'Pittsburgh Penguins',    'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-pit'],
  'WSH' => ['name'=>'Washington Capitals',    'conf'=>'Eastern', 'div'=>'Metropolitan',  'class'=>'team-wsh'],

  // WEST - Central
  'CHI' => ['name'=>'Chicago Blackhawks',     'conf'=>'Western', 'div'=>'Central',       'class'=>'team-chi'],
  'COL' => ['name'=>'Colorado Avalanche',     'conf'=>'Western', 'div'=>'Central',       'class'=>'team-col'],
  'DAL' => ['name'=>'Dallas Stars',           'conf'=>'Western', 'div'=>'Central',       'class'=>'team-dal'],
  'MIN' => ['name'=>'Minnesota Wild',         'conf'=>'Western', 'div'=>'Central',       'class'=>'team-min'],
  'NSH' => ['name'=>'Nashville Predators',    'conf'=>'Western', 'div'=>'Central',       'class'=>'team-nsh'],
  'STL' => ['name'=>'St. Louis Blues',        'conf'=>'Western', 'div'=>'Central',       'class'=>'team-stl'],
  'UTA' => ['name'=>'Utah Mammoth',           'conf'=>'Western', 'div'=>'Central',       'class'=>'team-uta'],
  'WPG' => ['name'=>'Winnipeg Jets',          'conf'=>'Western', 'div'=>'Central',       'class'=>'team-wpg'],

  // WEST - Pacific
  'ANA' => ['name'=>'Anaheim Ducks',          'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-ana'],
  'CGY' => ['name'=>'Calgary Flames',         'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-cgy'],
  'EDM' => ['name'=>'Edmonton Oilers',        'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-edm'],
  'LAK' => ['name'=>'Los Angeles Kings',      'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-lak'],
  'SJS' => ['name'=>'San Jose Sharks',        'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-sjs'],
  'SEA' => ['name'=>'Seattle Kraken',         'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-sea'],
  'VAN' => ['name'=>'Vancouver Canucks',      'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-van'],
  'VGK' => ['name'=>'Vegas Golden Knights',   'conf'=>'Western', 'div'=>'Pacific',       'class'=>'team-vgk'],
];

/**
 * =====================================================================
 * Helpers
 * =====================================================================
 */
function init_team_row(string $abbr, array $meta): array {
  return [
    'abbr'  => $abbr,
    'name'  => $meta['name'] ?? $abbr,
    'conf'  => $meta['conf'] ?? 'Other',
    'div'   => $meta['div']  ?? 'Other',
    'class' => $meta['class'] ?? '',

    'gp' => 0, 'w' => 0, 'l' => 0, 'otl' => 0, 'pts' => 0,
    'rw' => 0, 'row' => 0, 'sow' => 0, 'sol' => 0,

    'gf' => 0, 'ga' => 0,

    'home_w' => 0, 'home_l' => 0, 'home_otl' => 0,
    'away_w' => 0, 'away_l' => 0, 'away_otl' => 0,

    // last 10 results in chronological order; store 'W', 'L', 'O' (OT/SO loss)
    'last10' => [],
    'streak_type' => '',
    'streak_len' => 0,
  ];
}

function pick_first_existing(array $availableLower, array $candidates): ?string {
  foreach ($candidates as $c) {
    $lc = strtolower($c);
    if (isset($availableLower[$lc])) return $availableLower[$lc];
  }
  return null;
}

function cmp_team(array $a, array $b): int {
  // 1) Points (desc)
  if ($a['pts'] !== $b['pts']) return ($a['pts'] > $b['pts']) ? -1 : 1;

  // 2) Games Played (asc) — fewer games played ranks higher when points are equal
  if ($a['gp'] !== $b['gp']) return ($a['gp'] < $b['gp']) ? -1 : 1;

  // 3) NHL-ish tiebreakers (desc)
  foreach (['row','rw'] as $k) {
    if ($a[$k] !== $b[$k]) return ($a[$k] > $b[$k]) ? -1 : 1;
  }

  // 4) Goal differential (desc)
  $gdA = $a['gf'] - $a['ga'];
  $gdB = $b['gf'] - $b['ga'];
  if ($gdA !== $gdB) return ($gdA > $gdB) ? -1 : 1;

  // 5) Goals For (desc)
  if ($a['gf'] !== $b['gf']) return ($a['gf'] > $b['gf']) ? -1 : 1;

  // 6) Stable fallback
  return strcmp($a['abbr'], $b['abbr']);
}


function fmt_record(int $w, int $l, int $o): string { return "{$w}-{$l}-{$o}"; }

function fmt_l10(array $t): string {
  $w = 0; $l = 0; $o = 0;
  foreach ($t['last10'] as $r) {
    if ($r === 'W') $w++;
    elseif ($r === 'L') $l++;
    else $o++;
  }
  return "{$w}-{$l}-{$o}";
}

function fmt_streak(array $t): string {
  if (empty($t['streak_type']) || empty($t['streak_len'])) return '-';
  return $t['streak_type'] . $t['streak_len'];
}

/**
 * Render a full standings table with all columns.
 * $wcSpotTeams is a set of team abbrs that should be highlighted (WC top-2 per conference).
 */
function render_table(string $title, array $teams, array $wcSpotTeams = []): void {
  usort($teams, 'cmp_team');

  echo '<div class="standings-group">';
  echo '  <div class="standings-group__title">' . htmlspecialchars($title) . '</div>';
  echo '  <div class="stats-table-wrapper standings-group__table">';
  echo '    <table class="stats-table">';
  echo '      <thead>';
  echo '        <tr>';
  echo '          <th style="width:44px;">#</th>';
  echo '          <th>Team</th>';
  echo '          <th style="text-align:right;">GP</th>';
  echo '          <th style="text-align:right;">W</th>';
  echo '          <th style="text-align:right;">L</th>';
  echo '          <th style="text-align:right;">OTL</th>';
  echo '          <th style="text-align:right;">PTS</th>';
  echo '          <th style="text-align:right;">RW</th>';
  echo '          <th style="text-align:right;">ROW</th>';
  echo '          <th style="text-align:right;">SOW</th>';
  echo '          <th style="text-align:right;">SOL</th>';
  echo '          <th style="text-align:right;">HOME</th>';
  echo '          <th style="text-align:right;">AWAY</th>';
  echo '          <th style="text-align:right;">GF</th>';
  echo '          <th style="text-align:right;">GA</th>';
  echo '          <th style="text-align:right;">DIFF</th>';
  echo '          <th style="text-align:right;">L10</th>';
  echo '          <th style="text-align:right;">STRK</th>';
  echo '        </tr>';
  echo '      </thead>';
  echo '      <tbody>';

  $rank = 1;
  foreach ($teams as $t) {
    $diff = (int)$t['gf'] - (int)$t['ga'];
    $home = fmt_record((int)$t['home_w'], (int)$t['home_l'], (int)$t['home_otl']);
    $away = fmt_record((int)$t['away_w'], (int)$t['away_l'], (int)$t['away_otl']);
    $l10  = fmt_l10($t);
    $strk = fmt_streak($t);

    $rowClass = 'standings-table__row';
    if (!empty($t['class'])) $rowClass .= ' ' . $t['class'];
    if (isset($wcSpotTeams[$t['abbr']])) $rowClass .= ' standings-row--wc-spot';

    echo '<tr class="' . htmlspecialchars($rowClass) . '">';

    echo '<td class="standings-cell standings-cell--rank" data-label="#">'
      . '<span class="standings-rankbadge">' . $rank++ . '</span>'
      . '</td>';

    $abbr = strtoupper(trim($t['abbr']));
    $logoWeb = "/assets/img/logos/{$abbr}.png";
    $logoFs  = __DIR__ . $logoWeb; // __DIR__ is /public

    $logoHtml = '';
    if ($abbr && is_file($logoFs)) {
      $logoHtml =
        '<img class="standings-teamlogo"'
        . ' src="' . htmlspecialchars($logoWeb) . '"'
        . ' alt="' . htmlspecialchars($abbr) . ' logo"'
        . ' loading="lazy" decoding="async">';
    } else {
      // fallback if logo file is missing
      $logoHtml = '<span class="standings-teamabbr">' . htmlspecialchars($abbr) . '</span>';
    }

    echo '<td class="standings-cell standings-cell--team">'
      . '<div class="standings-teamline">'
      .   '<span class="standings-teammark">' . $logoHtml . '</span>'
      .   '<span class="standings-teamname">' . htmlspecialchars($t['name']) . '</span>'
      . '</div>'
      . '</td>';


    echo '<td class="standings-cell" data-label="GP"  style="text-align:right;">' . (int)$t['gp'] . '</td>';
    echo '<td class="standings-cell" data-label="W"   style="text-align:right;">' . (int)$t['w'] . '</td>';
    echo '<td class="standings-cell" data-label="L"   style="text-align:right;">' . (int)$t['l'] . '</td>';
    echo '<td class="standings-cell" data-label="OTL" style="text-align:right;">' . (int)$t['otl'] . '</td>';
    echo '<td class="standings-cell" data-label="PTS" style="text-align:right;"><strong>' . (int)$t['pts'] . '</strong></td>';

    echo '<td class="standings-cell" data-label="RW"  style="text-align:right;">' . (int)$t['rw'] . '</td>';
    echo '<td class="standings-cell" data-label="ROW" style="text-align:right;">' . (int)$t['row'] . '</td>';
    echo '<td class="standings-cell" data-label="SOW" style="text-align:right;">' . (int)$t['sow'] . '</td>';
    echo '<td class="standings-cell" data-label="SOL" style="text-align:right;">' . (int)$t['sol'] . '</td>';

    echo '<td class="standings-cell" data-label="HOME" style="text-align:right;">' . htmlspecialchars($home) . '</td>';
    echo '<td class="standings-cell" data-label="AWAY" style="text-align:right;">' . htmlspecialchars($away) . '</td>';

    echo '<td class="standings-cell" data-label="GF"   style="text-align:right;">' . (int)$t['gf'] . '</td>';
    echo '<td class="standings-cell" data-label="GA"   style="text-align:right;">' . (int)$t['ga'] . '</td>';
    echo '<td class="standings-cell" data-label="DIFF" style="text-align:right;">' . (int)$diff . '</td>';

    echo '<td class="standings-cell" data-label="L10"  style="text-align:right;">' . htmlspecialchars($l10) . '</td>';
    echo '<td class="standings-cell" data-label="STRK" style="text-align:right;">' . htmlspecialchars($strk) . '</td>';

    echo '</tr>';
  }

  echo '      </tbody>';
  echo '    </table>';
  echo '  </div>';
  echo '</div>';
}

/**
 * =====================================================================
 * Auto-detect msf_games columns
 * =====================================================================
 */
$MSF_GAMES_TABLE = 'msf_games';

$colsStmt = $pdo->prepare("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = :t
");
$colsStmt->execute([':t' => $MSF_GAMES_TABLE]);
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

$availableLower = [];
foreach ($cols as $c) $availableLower[strtolower($c)] = $c;

$dtCol = pick_first_existing($availableLower, [
  'start_time','starttime','game_time','gametime','game_date','gamedate','scheduled','scheduled_time',
  'date_time','datetime','start_datetime','startDateTime','startDate'
]);

$homeCol = pick_first_existing($availableLower, [
  'home_team_abbr','home_abbr','home_team','home_team_code','hometeamabbr','hometeam_abbr','homeTeamAbbreviation'
]);

$awayCol = pick_first_existing($availableLower, [
  'away_team_abbr','away_abbr','away_team','away_team_code','awayteamabbr','awayteam_abbr','awayTeamAbbreviation'
]);

$statusCol = pick_first_existing($availableLower, [
  'played_status','playedstatus','status','game_status','gamestatus','schedule_status','schedulestatus','played','is_final','final'
]);

$finalWhere = '';
if ($statusCol) {
  // best-effort filter for finals (if your schema uses different values, remove this)
  $finalWhere = " AND (g.`$statusCol` IN ('COMPLETED','FINAL','FINISHED',1,'1','true','TRUE')) ";
}

if (!$dtCol || !$homeCol || !$awayCol) {
  echo '<div style="padding:16px; background:#2a2a2a; border:1px solid #444; border-radius:10px; margin:16px;">';
  echo '<h2 style="margin:0 0 8px;">Standings needs msf_games column mapping</h2>';
  echo '<div style="opacity:.85;">I auto-scanned <code>msf_games</code> but could not find:</div>';
  echo '<ul style="margin:8px 0 0 18px; opacity:.9;">';
  if (!$dtCol)   echo '<li>game datetime column (e.g. <code>start_time</code> or <code>game_date</code>)</li>';
  if (!$homeCol) echo '<li>home team abbr column (e.g. <code>home_team_abbr</code>)</li>';
  if (!$awayCol) echo '<li>away team abbr column (e.g. <code>away_team_abbr</code>)</li>';
  echo '</ul>';
  echo '<div style="margin-top:10px; opacity:.85;">Paste the <strong>Structure</strong> view of <code>msf_games</code> and I’ll lock it in.</div>';
  echo '</div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

/**
 * =====================================================================
 * Pull per-team per-game results (with msf_games join)
 * =====================================================================
 * Uses your msf_team_gamelogs schema:
 *  - msf_game_id
 *  - team_abbr
 *  - goals_for
 *  - ot_wins, ot_losses, so_wins, so_losses
 */
$sql = "
SELECT
  g1.msf_game_id,
  UPPER(TRIM(g1.team_abbr)) AS team_abbr,
  UPPER(TRIM(g2.team_abbr)) AS opp_abbr,

  COALESCE(g1.goals_for, 0) AS gf,
  COALESCE(g2.goals_for, 0) AS ga,

  COALESCE(g1.ot_wins, 0)   AS ot_wins,
  COALESCE(g1.ot_losses, 0) AS ot_losses,
  COALESCE(g1.so_wins, 0)   AS so_wins,
  COALESCE(g1.so_losses, 0) AS so_losses,

  g.`$dtCol` AS game_dt,
  UPPER(TRIM(g.`$homeCol`)) AS home_abbr,
  UPPER(TRIM(g.`$awayCol`)) AS away_abbr

FROM msf_team_gamelogs g1
JOIN msf_team_gamelogs g2
  ON g2.msf_game_id = g1.msf_game_id
 AND UPPER(TRIM(g2.team_abbr)) <> UPPER(TRIM(g1.team_abbr))

JOIN `$MSF_GAMES_TABLE` g
  ON g.msf_game_id = g1.msf_game_id

WHERE g1.msf_game_id IS NOT NULL
  AND g1.team_abbr IS NOT NULL
  AND g1.team_abbr <> ''
  $finalWhere

ORDER BY game_dt ASC
";

$stmt = $pdo->query($sql);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * =====================================================================
 * Aggregate standings + HOME/AWAY + L10 + STRK
 * =====================================================================
 */
$teams = [];
foreach ($TEAM_META as $abbr => $meta) {
  $teams[$abbr] = init_team_row($abbr, $meta);
}

foreach ($games as $g) {
  $abbr = strtoupper(trim($g['team_abbr'] ?? ''));
  if (!$abbr) continue;

  if (!isset($teams[$abbr])) {
    $teams[$abbr] = init_team_row($abbr, [
      'name'  => $abbr,
      'conf'  => 'Other',
      'div'   => 'Other',
      'class' => 'team-' . strtolower($abbr),
    ]);
  }
  $t =& $teams[$abbr];

  $gf = (int)($g['gf'] ?? 0);
  $ga = (int)($g['ga'] ?? 0);

  $ot_wins   = (int)($g['ot_wins'] ?? 0);
  $ot_losses = (int)($g['ot_losses'] ?? 0);
  $so_wins   = (int)($g['so_wins'] ?? 0);
  $so_losses = (int)($g['so_losses'] ?? 0);

  $isSO = (($so_wins + $so_losses) > 0);
  $isOT = (!$isSO && ($ot_wins + $ot_losses) > 0);

  $home_abbr = strtoupper(trim($g['home_abbr'] ?? ''));
  $away_abbr = strtoupper(trim($g['away_abbr'] ?? ''));

  $isHome = ($home_abbr && $abbr === $home_abbr);
  $isAway = ($away_abbr && $abbr === $away_abbr);

  $t['gp'] += 1;
  $t['gf'] += $gf;
  $t['ga'] += $ga;

  $win  = ($gf > $ga);
  $loss = ($gf < $ga);

  if ($win) {
    $t['w']   += 1;
    $t['pts'] += 2;

    if ($isSO) {
      $t['sow'] += 1;
      // ROW excludes shootout wins
    } elseif ($isOT) {
      $t['row'] += 1;
    } else {
      $t['rw']  += 1;
      $t['row'] += 1;
    }

    if ($isHome) $t['home_w'] += 1;
    if ($isAway) $t['away_w'] += 1;

    $t['last10'][] = 'W';
  } elseif ($loss) {
    if ($isSO || $isOT) {
      $t['otl'] += 1;
      $t['pts'] += 1;
      if ($isSO) $t['sol'] += 1;

      if ($isHome) $t['home_otl'] += 1;
      if ($isAway) $t['away_otl'] += 1;

      $t['last10'][] = 'O';
    } else {
      $t['l'] += 1;

      if ($isHome) $t['home_l'] += 1;
      if ($isAway) $t['away_l'] += 1;

      $t['last10'][] = 'L';
    }
  }

  if (count($t['last10']) > 10) {
    $t['last10'] = array_slice($t['last10'], -10);
  }
}
unset($t);

// Streak from last10 (games were ordered ASC, so last entry is most recent)
foreach ($teams as &$t) {
  if (empty($t['last10'])) continue;
  $rev = array_reverse($t['last10']); // most recent first
  $first = $rev[0];
  $type = ($first === 'W') ? 'W' : (($first === 'L') ? 'L' : 'OTL');
  $len = 0;
  foreach ($rev as $r) {
    $rType = ($r === 'W') ? 'W' : (($r === 'L') ? 'L' : 'OTL');
    if ($rType !== $type) break;
    $len++;
  }
  $t['streak_type'] = $type;
  $t['streak_len']  = $len;
}
unset($t);

// Drop teams with no games
$teamsPlayed = [];
foreach ($teams as $abbr => $t) {
  if ((int)$t['gp'] > 0) $teamsPlayed[$abbr] = $t;
}

/**
 * =====================================================================
 * Precompute Wild Card ranking per conference (top 2 WC spots)
 * =====================================================================
 */
$wcSpotTeams = ['Eastern' => [], 'Western' => []];

foreach (['Eastern' => ['Atlantic','Metropolitan'], 'Western' => ['Central','Pacific']] as $conf => $divs) {
  $confTeams = array_values(array_filter($teamsPlayed, fn($t) => $t['conf'] === $conf));
  usort($confTeams, 'cmp_team');

  $taken = [];
  foreach ($divs as $div) {
    $divTeams = array_values(array_filter($confTeams, fn($t) => $t['div'] === $div));
    usort($divTeams, 'cmp_team');
    foreach (array_slice($divTeams, 0, 3) as $top) {
      $taken[$top['abbr']] = true;
    }
  }

  $wc = array_values(array_filter($confTeams, fn($t) => empty($taken[$t['abbr']])));
  usort($wc, 'cmp_team');
  foreach (array_slice($wc, 0, 2) as $spot) {
    $wcSpotTeams[$conf][$spot['abbr']] = true;
  }
}

// flatten for easy highlight in any table
$wcSpotFlat = $wcSpotTeams['Eastern'] + $wcSpotTeams['Western'];

/**
 * =====================================================================
 * Determine view
 * =====================================================================
 */
$allowedView = ['wildcard','conference','division','league'];
$view = strtolower(trim($_GET['view'] ?? 'wildcard'));
if (!in_array($view, $allowedView, true)) $view = 'wildcard';

function option_selected(string $cur, string $val): string {
  return $cur === $val ? 'selected' : '';
}

// Build buckets used by views
$byConference = [
  'Eastern' => array_values(array_filter($teamsPlayed, fn($t) => $t['conf'] === 'Eastern')),
  'Western' => array_values(array_filter($teamsPlayed, fn($t) => $t['conf'] === 'Western')),
];

$byDivision = [
  'Atlantic'      => array_values(array_filter($teamsPlayed, fn($t) => $t['div'] === 'Atlantic')),
  'Metropolitan'  => array_values(array_filter($teamsPlayed, fn($t) => $t['div'] === 'Metropolitan')),
  'Central'       => array_values(array_filter($teamsPlayed, fn($t) => $t['div'] === 'Central')),
  'Pacific'       => array_values(array_filter($teamsPlayed, fn($t) => $t['div'] === 'Pacific')),
];

$leagueAll = array_values($teamsPlayed);

// Wildcard structures (top3 per division, rest WC)
$wildcardStruct = [
  'Eastern' => ['AtlanticTop3'=>[], 'MetroTop3'=>[], 'WildCard'=>[]],
  'Western' => ['CentralTop3'=>[],  'PacificTop3'=>[], 'WildCard'=>[]],
];

foreach (['Eastern' => ['Atlantic','Metropolitan'], 'Western' => ['Central','Pacific']] as $conf => $divs) {
  $confTeams = $byConference[$conf] ?? [];
  usort($confTeams, 'cmp_team');

  $taken = [];

  // top 3 per division
  foreach ($divs as $div) {
    $divTeams = array_values(array_filter($confTeams, fn($t) => $t['div'] === $div));
    usort($divTeams, 'cmp_team');
    $top3 = array_slice($divTeams, 0, 3);
    foreach ($top3 as $tt) $taken[$tt['abbr']] = true;

    if ($conf === 'Eastern' && $div === 'Atlantic')      $wildcardStruct[$conf]['AtlanticTop3'] = $top3;
    if ($conf === 'Eastern' && $div === 'Metropolitan')  $wildcardStruct[$conf]['MetroTop3']    = $top3;
    if ($conf === 'Western' && $div === 'Central')       $wildcardStruct[$conf]['CentralTop3']  = $top3;
    if ($conf === 'Western' && $div === 'Pacific')       $wildcardStruct[$conf]['PacificTop3']  = $top3;
  }

  $wc = array_values(array_filter($confTeams, fn($t) => empty($taken[$t['abbr']])));
  usort($wc, 'cmp_team');
  $wildcardStruct[$conf]['WildCard'] = $wc;
}

?>
<div class="page-standings__inner">
  <div class="page-standings__top-bar">
    <div>
      <h1>NHL Standings</h1>
      <div class="page-standings__subtitle">Wild Card / Conference / Division / League</div>
    </div>

    <div class="page-standings__controls">
      <div class="standings-filter">
        <label for="standings-view">View</label>
        <select id="standings-view" onchange="location.href='?view=' + encodeURIComponent(this.value)">
          <option value="wildcard"   <?= option_selected($view,'wildcard') ?>>Wild Card</option>
          <option value="conference" <?= option_selected($view,'conference') ?>>Conference</option>
          <option value="division"   <?= option_selected($view,'division') ?>>Division</option>
          <option value="league"     <?= option_selected($view,'league') ?>>League</option>
        </select>
      </div>
    </div>
  </div>

  <section class="stats-panel stats-panel--full">
    <div class="stats-panel__inner">

      <?php if (empty($games) || empty($teamsPlayed)): ?>
        <div class="stats-empty" id="standings-empty">
          No standings data yet.
        </div>
      <?php else: ?>

        <div class="standings-root standings-root--grid">

          <?php if ($view === 'wildcard'): ?>

            <?php foreach (['Eastern','Western'] as $conf): ?>
              <article class="standings-conf standings-conf--card">
                <div class="standings-conf__header">
                  <h2 class="standings-conf__title"><?= htmlspecialchars($conf) ?> Conference</h2>
                </div>

                <?php if ($conf === 'Eastern'): ?>
                  <?php render_table('Atlantic (Top 3)', $wildcardStruct[$conf]['AtlanticTop3'], $wcSpotFlat); ?>
                  <?php render_table('Metropolitan (Top 3)', $wildcardStruct[$conf]['MetroTop3'], $wcSpotFlat); ?>
                <?php else: ?>
                  <?php render_table('Central (Top 3)', $wildcardStruct[$conf]['CentralTop3'], $wcSpotFlat); ?>
                  <?php render_table('Pacific (Top 3)', $wildcardStruct[$conf]['PacificTop3'], $wcSpotFlat); ?>
                <?php endif; ?>

                <?php render_table('Wild Card', $wildcardStruct[$conf]['WildCard'], $wcSpotFlat); ?>
              </article>
            <?php endforeach; ?>

          <?php elseif ($view === 'conference'): ?>

            <article class="standings-conf standings-conf--card">
              <div class="standings-conf__header">
                <h2 class="standings-conf__title">Eastern Conference</h2>
              </div>
              <?php render_table('Eastern Conference', $byConference['Eastern'], $wcSpotFlat); ?>
            </article>

            <article class="standings-conf standings-conf--card">
              <div class="standings-conf__header">
                <h2 class="standings-conf__title">Western Conference</h2>
              </div>
              <?php render_table('Western Conference', $byConference['Western'], $wcSpotFlat); ?>
            </article>

          <?php elseif ($view === 'division'): ?>

            <?php foreach (['Atlantic','Metropolitan','Central','Pacific'] as $div): ?>
              <article class="standings-conf standings-conf--card">
                <div class="standings-conf__header">
                  <h2 class="standings-conf__title"><?= htmlspecialchars($div) ?></h2>
                </div>
                <?php render_table($div, $byDivision[$div], $wcSpotFlat); ?>
              </article>
            <?php endforeach; ?>

          <?php else: /* league */ ?>

            <article class="standings-conf standings-conf--card">
              <div class="standings-conf__header">
                <h2 class="standings-conf__title">NHL (League)</h2>
              </div>
              <?php render_table('League', $leagueAll, $wcSpotFlat); ?>
            </article>

          <?php endif; ?>

        </div>

      <?php endif; ?>

    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
