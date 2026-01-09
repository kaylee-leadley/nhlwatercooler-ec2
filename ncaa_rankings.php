<?php
// public/ncaa_rankings.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

// Page chrome
$pageTitle = 'NCAA Rankings – USCHO.com';
$bodyClass = 'page-rankings';
$pageCss   = '/assets/css/ncaa-rankings.css';

/**
 * ----------------------------
 * Helpers
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
 * Poll selection (fixed for now)
 * ----------------------------
 */
$pollSlug     = 'uschocom';
$sportSlug    = 'icehockey-men';
$divisionSlug = 'd1';

/**
 * ----------------------------
 * Choose poll_date
 * ----------------------------
 */
$dateParam = isset($_GET['date']) ? trim($_GET['date']) : '';

// Latest available poll date
$sqlLatest = "
  SELECT poll_date, updated_raw
  FROM ncaa_rankings
  WHERE poll_slug = :poll_slug
    AND sport_slug = :sport_slug
    AND division_slug = :division_slug
  GROUP BY poll_date, updated_raw
  ORDER BY poll_date DESC
  LIMIT 1
";
$stmt = $pdo->prepare($sqlLatest);
$stmt->execute([
  ':poll_slug'     => $pollSlug,
  ':sport_slug'    => $sportSlug,
  ':division_slug' => $divisionSlug,
]);
$latestRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$latestRow) {
  include __DIR__ . '/includes/header.php';
  ?>
  <div class="page-section">
    <h1>NCAA Rankings</h1>
    <p>No rankings data found. Try running the import script.</p>
  </div>
  <?php
  include __DIR__ . '/includes/footer.php';
  exit;
}

$latestDate    = $latestRow['poll_date'];
$latestUpdated = $latestRow['updated_raw'];

// Validate requested date; fall back to latest
$pollDate = $latestDate;
if ($dateParam !== '') {
  $stmt = $pdo->prepare("
    SELECT 1
    FROM ncaa_rankings
    WHERE poll_slug = :poll_slug
      AND sport_slug = :sport_slug
      AND division_slug = :division_slug
      AND poll_date = :poll_date
    LIMIT 1
  ");
  $stmt->execute([
    ':poll_slug'     => $pollSlug,
    ':sport_slug'    => $sportSlug,
    ':division_slug' => $divisionSlug,
    ':poll_date'     => $dateParam,
  ]);
  if ($stmt->fetchColumn()) {
    $pollDate = $dateParam;
  }
}

// Available dates dropdown (latest first)
$stmt = $pdo->prepare("
  SELECT poll_date, MIN(updated_raw) AS updated_raw
  FROM ncaa_rankings
  WHERE poll_slug = :poll_slug
    AND sport_slug = :sport_slug
    AND division_slug = :division_slug
  GROUP BY poll_date
  ORDER BY poll_date DESC
  LIMIT 20
");
$stmt->execute([
  ':poll_slug'     => $pollSlug,
  ':sport_slug'    => $sportSlug,
  ':division_slug' => $divisionSlug,
]);
$availableDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Rankings rows for selected date
$stmt = $pdo->prepare("
  SELECT
    team_rank,
    team_name,
    first_votes,
    record,
    points,
    previous_rank,
    updated_raw
  FROM ncaa_rankings
  WHERE poll_slug = :poll_slug
    AND sport_slug = :sport_slug
    AND division_slug = :division_slug
    AND poll_date = :poll_date
  ORDER BY team_rank ASC
");
$stmt->execute([
  ':poll_slug'     => $pollSlug,
  ':sport_slug'    => $sportSlug,
  ':division_slug' => $divisionSlug,
  ':poll_date'     => $pollDate,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedUpdatedRaw = $rows[0]['updated_raw'] ?? $latestUpdated;

include __DIR__ . '/includes/header.php';
?>

<div class="page-section page-rankings__section">
  <div class="page-rankings__header">
    <div>
      <h1>NCAA Rankings</h1>
      <p class="page-rankings__subtitle">
        USCHO.com – Men’s Ice Hockey, Division I<br>
        <span class="page-rankings__updated">
          <?= htmlspecialchars($selectedUpdatedRaw) ?> (poll date <?= htmlspecialchars($pollDate) ?>)
        </span>
      </p>
    </div>

    <form method="get" class="page-rankings__controls">
      <input type="hidden" name="league" value="ncaa">
      <label>
        Poll date:
        <select name="date" onchange="this.form.submit()">
          <?php foreach ($availableDates as $d): ?>
            <option
              value="<?= htmlspecialchars($d['poll_date']) ?>"
              <?= ($d['poll_date'] === $pollDate) ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($d['poll_date']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>

  <?php if (!$rows): ?>
    <p>No rankings found for this date.</p>
  <?php else: ?>
    <div class="rankings-card">
      <div class="table-scroll">
        <table class="rankings-table">
          <thead>
            <tr>
              <th class="rankings-table__col-rank">Rank / Team</th>
              <th class="rankings-table__col-record">Record</th>
              <th class="rankings-table__col-points">Points</th>
              <th class="rankings-table__col-prev">Prev</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $rank       = (int)$row['team_rank'];
                $teamName   = (string)$row['team_name'];
                $firstVotes = $row['first_votes'];
                $record     = (string)$row['record'];
                $points     = (int)$row['points'];
                $previous   = $row['previous_rank'];

                // Use your logo map resolver (best source of canonical slug),
                // and fall back to slugify(teamName) if needed.
                $logoSlug = null;
                if (!empty($ncaaLogoMap)) {
                  $logoSlug = ncaa_logo_slug_for_team($teamName, $ncaaLogoMap);
                }
                if (!$logoSlug) {
                  $logoSlug = slugify($teamName);
                }

                // Ensure it matches body.ncaa-team-<slug> naming:
                // - lower
                // - only a-z 0-9 and hyphen
                // - no double hyphens
                $teamSlug = strtolower((string)$logoSlug);
                $teamSlug = preg_replace('/[^a-z0-9\-]+/', '-', $teamSlug);
                $teamSlug = preg_replace('/-+/', '-', $teamSlug);
                $teamSlug = trim($teamSlug, '-');

                $teamClass = $teamSlug ? ('ncaa-team-' . $teamSlug) : '';

                $trend = '';
                if (!is_null($previous) && (int)$previous > 0) {
                  $prevInt = (int)$previous;
                  if ($rank < $prevInt) $trend = 'up';
                  elseif ($rank > $prevInt) $trend = 'down';
                  else $trend = 'same';
                }
              ?>

              <tr class="rankings-table__row <?= htmlspecialchars($teamClass, ENT_QUOTES, 'UTF-8') ?>">
                <td class="rankings-table__cell-header" data-rank="<?= $rank ?>">
                  <div class="rankings-table__headerline">
                    <span class="rankings-table__rankbadge" aria-label="Rank #<?= $rank ?>">#<?= $rank ?></span>

                    <?php if ($logoSlug): ?>
                      <span class="rankings-table__logo" aria-hidden="true">
                        <img
                          src="/assets/img/ncaa-logos/<?= htmlspecialchars($logoSlug, ENT_QUOTES, 'UTF-8') ?>.svg"
                          alt=""
                          loading="lazy"
                        >
                      </span>
                    <?php endif; ?>

                    <span class="rankings-table__team-name">
                      <?= htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <?php if (!is_null($firstVotes) && (int)$firstVotes > 0): ?>
                      <span class="rankings-table__team-votes">
                        (<?= (int)$firstVotes ?>)
                      </span>
                    <?php endif; ?>
                  </div>
                </td>

                <td class="rankings-table__cell-record" data-label="Record">
                  <?= htmlspecialchars($record, ENT_QUOTES, 'UTF-8') ?>
                </td>

                <td class="rankings-table__cell-points" data-label="Points">
                  <?= $points ?>
                </td>

                <td class="rankings-table__cell-prev" data-label="Prev">
                  <?php if (is_null($previous) || (int)$previous <= 0): ?>
                    NR
                  <?php else: ?>
                    <span class="rankings-table__prev-rank"><?= (int)$previous ?></span>
                    <?php if ($trend === 'up'): ?>
                      <span class="rankings-table__trend rankings-table__trend--up">▲</span>
                    <?php elseif ($trend === 'down'): ?>
                      <span class="rankings-table__trend rankings-table__trend--down">▼</span>
                    <?php elseif ($trend === 'same'): ?>
                      <span class="rankings-table__trend rankings-table__trend--same">■</span>
                    <?php endif; ?>
                  <?php endif; ?>
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
