<?php
// public/api/thread_injuries_api.php
//
// Primary source: dailyfaceoff_lines (section I) using team_slug + lines_date (YYYY-MM-DD)
// Fallback:       injuries table (by injury_date + team_abbr)
//
// Exposes:
//   sjms_get_injuries_html(PDO $pdo, string $homeAbbr, string $awayAbbr, string $gameDate): string

require_once __DIR__ . '/../includes/team_map.php';

/* ============================================================
 *  Credit
 * ============================================================ */

if (!function_exists('sjms_dfo_credit_html_injury')) {
  function sjms_dfo_credit_html_injury(): string {
    return '<p class="thread-credit thread-credit--dfo">Source: DailyFaceoff.com</p>';
  }
}

/* ============================================================
 *  PRIMARY: DFO injuries (dailyfaceoff_lines)
 * ============================================================ */

if (!function_exists('sjms_fetch_injuries_primary')) {
  function sjms_fetch_injuries_primary(PDO $pdo, string $gameDate, array $teamSlugs): array {
    if (!$gameDate || !$teamSlugs) return [];

    // Remove empties + dedupe (unknown mapping returns '')
    $teamSlugs = array_values(array_unique(array_filter(array_map(function($s){
      return strtolower(trim((string)$s));
    }, $teamSlugs))));
    if (!$teamSlugs) return [];

    $ph = implode(',', array_fill(0, count($teamSlugs), '?'));

    $sql = "
      SELECT
        team_slug,
        player_name,
        position_code,
        jersey_number,
        injury_status,
        scraped_at
      FROM dailyfaceoff_lines
      WHERE lines_date = ?
        AND section = 'I'
        AND team_slug IN ($ph)
        AND (
          is_injured = 1
          OR (injury_status IS NOT NULL AND injury_status <> '')
        )
      ORDER BY team_slug, slot_no, line_no, player_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$gameDate], $teamSlugs));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bySlug = [];
    foreach ($rows as $r) {
      $slug = strtolower(trim($r['team_slug'] ?? ''));
      if ($slug === '') continue;
      $bySlug[$slug][] = $r;
    }
    return $bySlug;
  }
}

/**
 * Get latest scraped_at (UTC stored) for a given date + team slugs.
 * Returns raw DB value (string) or null.
 */
if (!function_exists('sjms_fetch_latest_dfo_scraped_at')) {
  function sjms_fetch_latest_dfo_scraped_at(PDO $pdo, string $gameDate, array $teamSlugs): ?string {
    if (!$gameDate || !$teamSlugs) return null;

    $teamSlugs = array_values(array_unique(array_filter(array_map(function($s){
      return strtolower(trim((string)$s));
    }, $teamSlugs))));
    if (!$teamSlugs) return null;

    $ph = implode(',', array_fill(0, count($teamSlugs), '?'));

    $sql = "
      SELECT MAX(scraped_at) AS max_scraped
      FROM dailyfaceoff_lines
      WHERE lines_date = ?
        AND section = 'I'
        AND team_slug IN ($ph)
        AND (
          is_injured = 1
          OR (injury_status IS NOT NULL AND injury_status <> '')
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$gameDate], $teamSlugs));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    $v = trim((string)($r['max_scraped'] ?? ''));
    return $v !== '' ? $v : null;
  }
}

/* ============================================================
 *  FALLBACK: injuries table
 * ============================================================ */

if (!function_exists('sjms_fetch_injuries_fallback')) {
  function sjms_fetch_injuries_fallback(PDO $pdo, string $gameDate, array $teamAbbrs): array {
    if (!$gameDate || !$teamAbbrs) return [];

    $teamAbbrs = array_values(array_unique(array_filter(array_map(function($t){
      return strtoupper(trim((string)$t));
    }, $teamAbbrs))));
    if (!$teamAbbrs) return [];

    $ph = implode(',', array_fill(0, count($teamAbbrs), '?'));

    $sql = "
      SELECT
        team_abbr,
        first_name,
        last_name,
        position,
        jersey_number,
        injury_description,
        playing_probability
      FROM injuries
      WHERE injury_date = ?
        AND team_abbr IN ($ph)
      ORDER BY team_abbr, last_name, first_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$gameDate], $teamAbbrs));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byAbbr = [];
    foreach ($rows as $r) {
      $abbr = strtoupper(trim($r['team_abbr'] ?? ''));
      if ($abbr === '') continue;
      $byAbbr[$abbr][] = $r;
    }
    return $byAbbr;
  }
}

/* ============================================================
 *  Public renderer
 * ============================================================ */

if (!function_exists('sjms_get_injuries_html')) {
  /**
   * Render injuries for away + home teams on a given gameDate.
   * Shows "As of YYYY-MM-DD h:iAM EST/EDT" using latest DFO scraped_at (converted from UTC),
   * OR falls back to "As of YYYY-MM-DD" if DFO isn't used.
   * Adds DFO credit under the date inside the header box if ANY primary DFO data is used.
   */
  function sjms_get_injuries_html(PDO $pdo, string $homeAbbr, string $awayAbbr, string $gameDate): string {
    if (!$gameDate) return '';

    // Keep display order away -> home
    $teamsAbbr = [];
    if ($awayAbbr) $teamsAbbr[] = strtoupper(trim($awayAbbr));
    if ($homeAbbr) $teamsAbbr[] = strtoupper(trim($homeAbbr));
    $teamsAbbr = array_values(array_unique(array_filter($teamsAbbr)));
    if (!$teamsAbbr) return '';

    // Primary lookup by slug (DFO)
    $teamSlugs = [];
    foreach ($teamsAbbr as $abbr) {
      $slug = sjms_team_abbr_to_slug($abbr);
      if ($slug !== '') $teamSlugs[] = $slug;
    }

    $primaryBySlug = sjms_fetch_injuries_primary($pdo, $gameDate, $teamSlugs);

    // Did we use any DFO data?
    $usedDfo = false;
    foreach ($primaryBySlug as $rows) {
      if (!empty($rows)) { $usedDfo = true; break; }
    }

    // Build "As of" label
    $asOfLabel = 'As of ' . $gameDate;

    if ($usedDfo) {
      $maxScraped = sjms_fetch_latest_dfo_scraped_at($pdo, $gameDate, $teamSlugs);
      if ($maxScraped) {
        try {
          $tzUtc = new DateTimeZone('UTC');
          $tzEt  = new DateTimeZone('America/New_York');

          $dt = new DateTime($maxScraped, $tzUtc); // stored as UTC (gmdate)
          $dt->setTimezone($tzEt);

          // "EST" vs "EDT"
          $abbrTz = $dt->format('T');

          $asOfLabel = 'As of ' . $dt->format('Y-m-d g:iA') . ' ' . $abbrTz;
        } catch (Throwable $e) {
          // keep date-only label
        }
      }
    }

    // Fallback only for teams missing primary rows
    $needFallback = [];
    foreach ($teamsAbbr as $abbr) {
      $slug = sjms_team_abbr_to_slug($abbr);
      if ($slug === '' || empty($primaryBySlug[$slug])) {
        $needFallback[] = $abbr;
      }
    }
    $fallbackByAbbr = $needFallback ? sjms_fetch_injuries_fallback($pdo, $gameDate, $needFallback) : [];

    ob_start();
    ?>
    <section class="thread-injuries" aria-label="Injuries">
      <header class="thread-injuries__header">
        <h2>Injuries</h2>
        <p class="thread-injuries__subtitle"><?= htmlspecialchars($asOfLabel) ?></p>
        <?php if ($usedDfo): ?>
          <?= sjms_dfo_credit_html_injury() ?>
        <?php endif; ?>
      </header>

      <div class="thread-injuries__grid" style="display:flex;flex-wrap:wrap;gap:24px;">
        <?php foreach ($teamsAbbr as $abbr): ?>
          <?php
            $slug = sjms_team_abbr_to_slug($abbr);
            $primaryRows  = ($slug !== '') ? ($primaryBySlug[$slug] ?? []) : [];
            $fallbackRows = $fallbackByAbbr[$abbr] ?? [];
            $hasAny = !empty($primaryRows) || !empty($fallbackRows);
          ?>
          <div class="thread-injuries__team" style="flex:1 1 340px;min-width:280px;">
            <h3 class="thread-injuries__team-heading" style="margin:0 0 6px;">
              <?= htmlspecialchars($abbr) ?>
            </h3>

            <?php if ($hasAny): ?>
              <table class="injury-table">
                <thead>
                  <tr>
                    <th>Player</th>
                    <th>Pos</th>
                    <th>#</th>
                    <th>Description</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($primaryRows as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['player_name'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['position_code'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['jersey_number'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['injury_status'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['injury_status'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>

                  <?php foreach ($fallbackRows as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) ?></td>
                      <td><?= htmlspecialchars($p['position'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['jersey_number'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['injury_description'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['playing_probability'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="thread-injuries__none">No listed injuries.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
    return ob_get_clean();
  }
}
