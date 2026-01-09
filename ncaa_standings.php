<?php
// public/ncaa_standings.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/db.php';

$pageTitle = 'NCAA Standings';
$bodyClass = 'page-standings league-ncaa';
$pageCss   = ['/assets/css/ncaa-standings.css'];

require_once __DIR__ . '/includes/header.php';

/**
 * Conference logo map (filename in /assets/img/ncaa-logos/)
 * Keys must match slugified conference name.
 */
$confLogos = [
  'atlantic-hockey-america' => 'aha_lg.png',
  'big-ten'                 => 'b10_lg.gif',
  'ccha'                    => 'ccha_lg.gif',
  'ecac-hockey'             => 'ecac_lg.gif',
  'hockey-east'             => 'hea_lg.gif',
  'nchc'                    => 'nchc_lg.gif',
];

function conf_slug(string $name): string {
  $name = trim($name);
  $name = strtolower($name);
  $name = preg_replace('/[^a-z0-9]+/i', '-', $name);
  return trim($name, '-');
}

/**
 * ----------------------------
 * Rankings-style logo helpers
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

/**
 * Load normalized logo map (same approach as rankings page)
 */
$ncaaLogoMap = [];
$logoMapPath = __DIR__ . '/includes/ncaa_logo_map.php';
if (file_exists($logoMapPath)) {
  $raw = require $logoMapPath;
  if (is_array($raw)) {
    foreach ($raw as $k => $v) {
      $nk = ncaa_logo_alias_key(ncaa_logo_key($k));
      if ($nk !== '' && $v !== '') {
        $ncaaLogoMap[$nk] = $v; // svg slug (no extension)
      }
    }
  }
}

/**
 * --------------------------------------------------------------------
 * OFFICIAL CONFERENCE STANDINGS:
 * - Use ncaa_boxscores JSON
 * - Conference game = both teams share same ncaa_teams.conference_id
 * - Keep overall records too
 *
 * POINTS MODEL (matches your Atlantic Hockey screenshot):
 * - Win (reg or OT): 3 pts
 * - Tie after OT: 1 pt each
 * - Shootout winner: +1 bonus point
 * --------------------------------------------------------------------
 */

/** Load team roster with conference_id so we can classify conference games */
$teamsSql = "
  SELECT
    t.slug,
    t.short_name,
    t.full_name,
    t.conference_id,
    c.name AS conference
  FROM ncaa_teams t
  JOIN ncaa_conference c ON c.id = t.conference_id
  ORDER BY
    CASE WHEN c.name = 'Division I Independents' THEN 1 ELSE 0 END,
    c.name ASC,
    t.short_name ASC
";
$teamRows = $pdo->query($teamsSql)->fetchAll(PDO::FETCH_ASSOC);

/** Lookup by slug (slug is your bridge to JSON teams[*].seoname) */
$teamBySlug = [];
foreach ($teamRows as $tr) {
  $slug = strtolower(trim((string)($tr['slug'] ?? '')));
  if ($slug !== '') $teamBySlug[$slug] = $tr;
}

/** Stats helpers */
function blank_stats(): array {
  return [
    'gp' => 0,
    'w'  => 0,
    'l'  => 0,
    't'  => 0,      // ties (after OT, before shootout bonus)
    'otw' => 0,     // overtime wins (for display)
    'otl' => 0,     // overtime losses (for display)
    'sow' => 0,     // shootout wins (for display)
    'sol' => 0,     // shootout losses (for display)
    'gf' => 0,
    'ga' => 0,
    'pts' => 0,
  ];
}

/**
 * Apply conference/overall result using:
 * - regulation+OT goals (gf/ga) (shootout goals do NOT affect gf/ga)
 * - ot goals for/against (to classify OT vs REG)
 * - shootout goals for/against (to award SO bonus point)
 */
function apply_result(
  array &$st,
  int $gfRegOt,
  int $gaRegOt,
  int $otFor,
  int $otAgainst,
  int $soFor,
  int $soAgainst
): void {
  $st['gp'] += 1;
  $st['gf'] += $gfRegOt;
  $st['ga'] += $gaRegOt;

  $isSO = ($soFor > 0 || $soAgainst > 0);
  $isOT = (!$isSO && ($otFor > 0 || $otAgainst > 0));

  // Determine winner/loser
  if ($isSO) {
    // Shootout winner is based on SO goals, not final "goals"
    if ($soFor > $soAgainst) {
      $st['otw'] += 1; 
      $st['sow'] += 1;
      $st['pts'] += 2;
    } else {
      $st['l'] += 1;
      $st['otl'] += 1;
      $st['sol'] += 1;
      $st['pts'] += 1; 
    }
    return;
  }

  if ($isOT) {
    if ($gfRegOt > $gaRegOt) {
      $st['w'] += 1;
      $st['otw'] += 1;
      $st['pts'] += 2;
    } else {
      $st['l'] += 1;
      $st['otl'] += 1;
      $st['pts'] += 1; 
    }
    return;
  }

  // Regulation
  if ($gfRegOt > $gaRegOt) {
    $st['w'] += 1;
    $st['pts'] += 3;
  } else {
    $st['l'] += 1;
    // 0 points
  }
}


/**
 * Extract a "shootout goals" count robustly.
 * Some boxscores populate teamStats.shootoutGoals,
 * others only populate playerStats[*].shootoutGoals.
 */
function extract_so_goals(array $teamBox): int {
  $ts = $teamBox['teamStats'] ?? [];
  if (!is_array($ts)) $ts = [];

  $so = (int)($ts['shootoutGoals'] ?? 0);

  // If teamStats doesn't carry it, sum playerStats[*].shootoutGoals
  if ($so <= 0) {
    $ps = $teamBox['playerStats'] ?? null;
    if (is_array($ps)) {
      $sum = 0;
      foreach ($ps as $p) {
        if (!is_array($p)) continue;
        $sum += (int)($p['shootoutGoals'] ?? 0);
      }
      $so = $sum;
    }
  }

  return (int)$so;
}

/** Containers */
$overall  = [];
$confOnly = [];

/** Pre-seed all teams so they show even with no games */
foreach ($teamRows as $tr) {
  $slug = strtolower(trim((string)($tr['slug'] ?? '')));
  if ($slug === '') continue;
  $overall[$slug]  = blank_stats();
  $confOnly[$slug] = blank_stats();
}

/** Pull boxscores */
$bsSql = "
  SELECT game_id, boxscore_json
  FROM ncaa_boxscores
  WHERE boxscore_json IS NOT NULL
    AND boxscore_json <> ''
";
$bsRows = $pdo->query($bsSql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($bsRows as $r) {
  $json = $r['boxscore_json'] ?? '';
  if (!is_string($json) || trim($json) === '') continue;

  $data = json_decode($json, true);
  if (!is_array($data)) continue;

  $status = strtoupper((string)($data['status'] ?? ''));
  $period = strtoupper((string)($data['period'] ?? ''));

  // only finished
  if (!in_array($status, ['F', 'FINAL'], true) && $period !== 'FINAL') continue;

  $teams = $data['teams'] ?? null;
  $tbox  = $data['teamBoxscore'] ?? null;
  if (!is_array($teams) || !is_array($tbox)) continue;

  // map teamId -> seoname (slug)
  $idToSlug = [];
  foreach ($teams as $t) {
    $tid = (string)($t['teamId'] ?? '');
    $seo = strtolower(trim((string)($t['seoname'] ?? '')));
    if ($tid !== '' && $seo !== '') $idToSlug[$tid] = $seo;
  }

  // gather per-team: gf/ot/so
  $per = [];
  foreach ($tbox as $tb) {
    if (!is_array($tb)) continue;

    $tid = (string)($tb['teamId'] ?? '');
    if ($tid === '') continue;

    $slug = $idToSlug[$tid] ?? '';
    $slug = strtolower(trim($slug));
    if ($slug === '') continue;

    $ts = $tb['teamStats'] ?? [];
    if (!is_array($ts)) $ts = [];

    $gf = (int)($ts['goals'] ?? 0);
    $ot = (int)($ts['overtimeGoals'] ?? 0);
    $so = extract_so_goals($tb);

    $per[$slug] = [
      'slug' => $slug,
      'gf'   => $gf,
      'ot'   => $ot,
      'so'   => $so,
    ];
  }

  // must be exactly 2 teams
  if (count($per) !== 2) continue;

  $slugs = array_keys($per);
  $aSlug = $slugs[0];
  $bSlug = $slugs[1];

  // only count if both teams exist in our ncaa_teams table
  if (!isset($teamBySlug[$aSlug]) || !isset($teamBySlug[$bSlug])) {
    continue;
  }

  $a = $per[$aSlug];
  $b = $per[$bSlug];

  // overall always applies
  apply_result(
    $overall[$aSlug],
    (int)$a['gf'], (int)$b['gf'],
    (int)$a['ot'], (int)$b['ot'],
    (int)$a['so'], (int)$b['so']
  );
  apply_result(
    $overall[$bSlug],
    (int)$b['gf'], (int)$a['gf'],
    (int)$b['ot'], (int)$a['ot'],
    (int)$b['so'], (int)$a['so']
  );

  // conference-only if both share conference_id
  $aConf = (int)($teamBySlug[$aSlug]['conference_id'] ?? 0);
  $bConf = (int)($teamBySlug[$bSlug]['conference_id'] ?? 0);

  if ($aConf > 0 && $aConf === $bConf) {
    apply_result(
      $confOnly[$aSlug],
      (int)$a['gf'], (int)$b['gf'],
      (int)$a['ot'], (int)$b['ot'],
      (int)$a['so'], (int)$b['so']
    );
    apply_result(
      $confOnly[$bSlug],
      (int)$b['gf'], (int)$a['gf'],
      (int)$b['ot'], (int)$a['ot'],
      (int)$b['so'], (int)$a['so']
    );
  }
}

/**
 * Build rows for rendering (conference grouped)
 * We render conference standings order using CONF stats,
 * while also showing overall columns.
 */
$rows = [];
foreach ($teamRows as $tr) {
  $slug = strtolower(trim((string)($tr['slug'] ?? '')));
  if ($slug === '') continue;

  $confSt = $confOnly[$slug] ?? blank_stats();
  $ovSt   = $overall[$slug]  ?? blank_stats();

  $rows[] = [
    'conference'   => $tr['conference'],
    'slug'         => $tr['slug'],
    'short_name'   => $tr['short_name'],
    'full_name'    => $tr['full_name'],

    // conference-only
    'c_gp'  => $confSt['gp'],
    'c_w'   => $confSt['w'],
    'c_l'   => $confSt['l'],
    'c_otw' => $confSt['otw'],
    'c_otl' => $confSt['otl'],
    'c_pts' => $confSt['pts'],

    // overall
    'o_gp'  => $ovSt['gp'],
    'o_w'   => $ovSt['w'],
    'o_l'   => $ovSt['l'],
    'o_otw' => $ovSt['otw'],
    'o_otl' => $ovSt['otl'],
    'o_pts' => $ovSt['pts'],
  ];
}

/** Sort within conference using CONFERENCE stats (official standings) */
usort($rows, function($a, $b) {
  $ca = (string)($a['conference'] ?? '');
  $cb = (string)($b['conference'] ?? '');

  $ia = ($ca === 'Division I Independents') ? 1 : 0;
  $ib = ($cb === 'Division I Independents') ? 1 : 0;
  if ($ia !== $ib) return $ia <=> $ib;

  if ($ca !== $cb) return strcmp($ca, $cb);

  $pa = (int)($a['c_pts'] ?? 0);
  $pb = (int)($b['c_pts'] ?? 0);
  if ($pa !== $pb) return $pb <=> $pa;

  $wa = (int)($a['c_w'] ?? 0);
  $wb = (int)($b['c_w'] ?? 0);
  if ($wa !== $wb) return $wb <=> $wa;

  $sa = (string)($a['short_name'] ?? '');
  $sb = (string)($b['short_name'] ?? '');
  return strcmp($sa, $sb);
});

/** Group by conference */
$byConf = [];
foreach ($rows as $r) {
  $conf = trim($r['conference'] ?? '') ?: 'Other';
  if (!isset($byConf[$conf])) $byConf[$conf] = [];
  $byConf[$conf][] = $r;
}
?>

<div class="page-standings__inner">
  <section class="standings-panel standings-panel--full">
    <div class="standings-panel__inner">

      <div class="page-standings__top-bar">
        <h1>NCAA Standings</h1>

        <div class="page-standings__controls">
          <div class="standings-filter">
            <label for="confJump">View</label>
            <select id="confJump" onchange="if(this.value) location.hash=this.value;">
              <option value="">All Conferences</option>
              <?php foreach (array_keys($byConf) as $confName): ?>
                <?php $id = 'conf-' . conf_slug($confName); ?>
                <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($confName, ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="standings-root">
        <?php foreach ($byConf as $confName => $teams): ?>
          <?php
            $cslug  = conf_slug($confName);
            $confId = 'conf-' . $cslug;
            $logo   = $confLogos[$cslug] ?? null;
          ?>
          <section class="standings-conf-card" id="<?= htmlspecialchars($confId, ENT_QUOTES, 'UTF-8') ?>">

            <div class="standings-conf-card__header">
              <?php if ($logo): ?>
                <img class="standings-conf-card__logo"
                     src="/assets/img/ncaa-logos/<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($confName, ENT_QUOTES, 'UTF-8') ?>">
              <?php else: ?>
                <h2 class="standings-conf-card__title">
                  <?= htmlspecialchars($confName, ENT_QUOTES, 'UTF-8') ?>
                </h2>
              <?php endif; ?>
            </div>

            <div class="standings-card">
              <div class="table-scroll">
                <table class="standings-table rankings-table">
                  <thead>
                    <tr>
                      <th class="standings-table__col-team">Team</th>

                      <th class="num">CGP</th>
                      <th class="num">CW</th>
                      <th class="num">CL</th>
                      <th class="num">COTW</th>
                      <th class="num">COTL</th>
                      <th class="num">CPTS</th>

                      <th class="num">OGP</th>
                      <th class="num">OW</th>
                      <th class="num">OL</th>
                      <th class="num">O-OTW</th>
                      <th class="num">O-OTL</th>
                      <th class="num">OPTS</th>
                    </tr>
                  </thead>

                  <tbody>
                    <?php $confRank = 0; ?>
                    <?php foreach ($teams as $t): ?>
                      <?php
                        $confRank++;

                        $teamName = (string)($t['short_name'] ?: $t['full_name'] ?: $t['slug']);

                        $logoSlug = null;
                        if (!empty($ncaaLogoMap)) {
                          $logoSlug = ncaa_logo_slug_for_team($teamName, $ncaaLogoMap);
                        }
                        if (!$logoSlug) $logoSlug = slugify($teamName);

                        $teamSlug = strtolower((string)$logoSlug);
                        $teamSlug = preg_replace('/[^a-z0-9\-]+/', '-', $teamSlug);
                        $teamSlug = preg_replace('/-+/', '-', $teamSlug);
                        $teamSlug = trim($teamSlug, '-');
                        $teamClass = $teamSlug ? ('ncaa-team-' . $teamSlug) : '';

                        $cgp  = (int)($t['c_gp'] ?? 0);
                        $cw   = (int)($t['c_w'] ?? 0);
                        $cl   = (int)($t['c_l'] ?? 0);
                        $cotw = (int)($t['c_otw'] ?? 0);
                        $cotl = (int)($t['c_otl'] ?? 0);
                        $cpts = (int)($t['c_pts'] ?? 0);

                        $ogp  = (int)($t['o_gp'] ?? 0);
                        $ow   = (int)($t['o_w'] ?? 0);
                        $ol   = (int)($t['o_l'] ?? 0);
                        $o_otw   = (int)($t['o_otw'] ?? 0);
                        $o_otl   = (int)($t['o_otl'] ?? 0);
                        $opts = (int)($t['o_pts'] ?? 0);
                      ?>

                      <tr class="rankings-table__row standings-table__row <?= htmlspecialchars($teamClass, ENT_QUOTES, 'UTF-8') ?>">
                        <td class="standings-table__cell-header rankings-table__cell-header" data-label="Team">
                          <div class="standings-table__headerline rankings-table__headerline">
                            <?php if ($logoSlug): ?>
                              <span class="standings-table__logo rankings-table__logo" aria-hidden="true">
                                <img
                                  src="/assets/img/ncaa-logos/<?= htmlspecialchars($logoSlug, ENT_QUOTES, 'UTF-8') ?>.svg"
                                  alt=""
                                  loading="lazy"
                                >
                              </span>
                            <?php endif; ?>

                            <span class="standings-table__team-name rankings-table__team-name">
                              <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <span class="standings-rankpill" aria-label="Conference rank <?= (int)$confRank ?>">
                              #<?= (int)$confRank ?>
                            </span>
                          </div>
                        </td>
                        <td class="num standings-table__cell" data-label="CGP"><?= $cgp ?></td>
                        <td class="num standings-table__cell" data-label="CW"><?= $cw ?></td>
                        <td class="num standings-table__cell" data-label="CL"><?= $cl ?></td>
                        <td class="num standings-table__cell" data-label="COTW"><?= $cotw ?></td>
                        <td class="num standings-table__cell" data-label="COTL"><?= $cotl ?></td>
                        <td class="num standings-table__cell standings-table__cell-pts" data-label="CPTS"><strong><?= $cpts ?></strong></td>
                        <td class="num standings-table__cell" data-label="OGP"><?= $ogp ?></td>
                        <td class="num standings-table__cell" data-label="OW"><?= $ow ?></td>
                        <td class="num standings-table__cell" data-label="OL"><?= $ol ?></td>
                        <td class="num standings-table__cell" data-label="O-OTW"><?= $o_otw ?></td>
                        <td class="num standings-table__cell" data-label="O-OTL"><?= $o_otl ?></td>
                        <td class="num standings-table__cell standings-table__cell-pts" data-label="OPTS"><strong><?= $opts ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
