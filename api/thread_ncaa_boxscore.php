<?php
// public/api/thread_ncaa_boxscore.php
//
// NCAA boxscore renderer – designed to match NHL boxscore layout.
// Keeps existing behavior:
//   - LIVE / IN-PROGRESS: render from API
//   - FINAL: try DB first, else render from API
//
// Adds:
//   - Proper status label w/ period clock ("2nd – 12:10") and INT handling
//   - Pregame label uses thread start_time if provided
//   - Treat status "I" as in-progress (NCAA uses I a lot)
//   - PP% computed from goals/ops if API percent missing/wrong

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('NCAA_API_BASE')) {
  define('NCAA_API_BASE', 'http://127.0.0.1:3000');
}

/* ============================================================
 *  API fetcher
 * ============================================================ */

function sjms_ncaa_fetch_boxscore($contestId) {
  $url = rtrim(NCAA_API_BASE, '/') . "/game/" . intval($contestId) . "/boxscore";
  error_log("[NCAA boxscore] Fetching $url");

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FAILONERROR    => false,
  ]);

  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    error_log("[NCAA boxscore] CURL error: $err");
    return null;
  }
  if ($code < 200 || $code >= 300) {
    error_log("[NCAA boxscore] HTTP $code from $url – body: " . substr($raw, 0, 500));
    return null;
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    error_log("[NCAA boxscore] Non-JSON response from $url");
    return null;
  }

  return $json;
}

/* ============================================================
 *  Helpers
 * ============================================================ */

function sjms_ncaa_is_final(array $box) {
  $st = strtoupper(trim((string)($box['status'] ?? '')));
  $pd = strtoupper(trim((string)($box['period'] ?? '')));
  return ($st === 'F' || $st === 'FINAL' || $pd === 'FINAL' || $pd === 'FINAL/OT' || $pd === 'FINAL/SO');
}

function sjms_ncaa_is_liveish(array $box) {
  // NCAA service seems to use:
  //   status: "L" (live) OR "I" (in-progress)
  $st = strtoupper(trim((string)($box['status'] ?? '')));
  return ($st === 'L' || $st === 'I');
}

function sjms_ncaa_period_label($period) {
  $p = strtoupper(trim((string)$period));
  if ($p === '') return '';

  // common values from your service: "1ST", "2ND", "3RD", "OT", "SO", "FINAL"
  if ($p === 'OT' || $p === 'SO') return $p;
  if ($p === 'FINAL') return 'Final';

  if (preg_match('/^([123])(ST|ND|RD)$/', $p, $m)) {
    return $m[1] . strtolower($m[2]); // "2nd"
  }

  // fallback for weird period strings
  return trim((string)$period);
}

function sjms_ncaa_intermission_label($period) {
  $p = strtoupper(trim((string)$period));
  if ($p === '') return '';

  if (strpos($p, 'INT') === false && strpos($p, 'INTERMISSION') === false) {
    return '';
  }

  if (preg_match('/(\d+)/', $p, $m)) {
    $n = (int)$m[1];
    if ($n === 1) return '1st INT';
    if ($n === 2) return '2nd INT';
    if ($n === 3) return '3rd INT';
    return 'OT INT';
  }

  return 'INT';
}

function sjms_ncaa_clock_from_box(array $box) {
  // your service returns minutes + seconds at top-level
  $minRaw = $box['minutes'] ?? null;
  $secRaw = $box['seconds'] ?? null;

  if ($minRaw === null || $secRaw === null) return '';

  if (!is_numeric($minRaw) || !is_numeric($secRaw)) return '';

  $m = (int)$minRaw;
  $s = (int)$secRaw;

  return sprintf('%d:%02d', $m, $s);
}

/**
 * Live label like "2nd – 12:10" (or just "2nd" if clock missing)
 */
function sjms_ncaa_live_label(array $box) {
  $pLabel = sjms_ncaa_period_label($box['period'] ?? '');
  $clock  = sjms_ncaa_clock_from_box($box);

  if ($pLabel !== '' && $clock !== '') return $pLabel . ' – ' . $clock;
  if ($pLabel !== '') return $pLabel;
  if ($clock !== '') return $clock;

  return 'Live';
}

/**
 * Status label used in the score strip.
 * - Final => "Final"
 * - Intermission => "1st INT" etc
 * - Live/InProgress => "2nd – 12:10"
 * - Pregame => start_time if provided, else "Game Day"
 */
function sjms_ncaa_status_label(array $box, $threadStartTime = '') {
  if (sjms_ncaa_is_final($box)) return 'Final';

  $int = sjms_ncaa_intermission_label($box['period'] ?? '');
  if ($int !== '') return $int;

  if (sjms_ncaa_is_liveish($box)) {
    return sjms_ncaa_live_label($box);
  }

  $st = strtoupper(trim((string)($box['status'] ?? '')));
  // If status unknown / pregame-ish, show start time if we have it
  $threadStartTime = trim((string)$threadStartTime);
  if ($threadStartTime !== '') {
    return $threadStartTime;
  }

  // fallback
  if ($st !== '') return $st;
  return 'Game Day';
}

/* ============================================================
 *  Percent helpers
 * ============================================================ */

function sjms_pp_pct($goals, $ops, $pctField = null) {
  $g = (int)$goals;
  $o = (int)$ops;

  // If API provided a sane percent, use it
  if ($pctField !== null && is_numeric($pctField)) {
    $p = (float)$pctField;
    // some feeds provide 0 even when goals/ops not zero; treat that as junk if it contradicts
    if (!($p == 0.0 && $g > 0 && $o > 0)) {
      return $p;
    }
  }

  if ($o <= 0) return null;
  return ($g / $o) * 100.0;
}

/* ============================================================
 *  DB normalization
 * ============================================================ */

function sjms_ncaa_team_from_db($row) {
  $ppGoals = intval($row['pp_goals'] ?? 0);
  $ppOps   = intval($row['pp_opportunities'] ?? 0);

  return [
    'short'     => $row['team_name_short'] ?? '',
    'full'      => $row['team_name_full']  ?? '',
    'goals'     => intval($row['goals'] ?? 0),
    'shots'     => intval($row['shots'] ?? 0),
    'pp_goals'  => $ppGoals,
    'pp_ops'    => $ppOps,
    'pp_pct'    => sjms_pp_pct($ppGoals, $ppOps, $row['pp_percentage'] ?? null),
    'pim'       => intval($row['pim_minutes'] ?? 0),
    'blocks'    => intval($row['blocks'] ?? 0),
    'fo_won'    => intval($row['faceoff_won'] ?? 0),
    'fo_lost'   => intval($row['faceoff_lost'] ?? 0),
    'saves'     => intval($row['saves'] ?? 0),
  ];
}

function sjms_ncaa_scorers_from_db(PDO $pdo, $contestId, $side) {
  $sql = "
    SELECT player_first_name, player_last_name, goals, assists
    FROM ncaa_player_gamelogs
    WHERE contest_id = :cid
      AND team_side = :side
      AND (goals > 0 OR assists > 0)
    ORDER BY goals DESC, assists DESC,
             player_last_name, player_first_name
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':cid' => $contestId, ':side' => $side]);

  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $g = (int)($r['goals'] ?? 0);
    $a = (int)($r['assists'] ?? 0);
    $out[] = [
      'name'   => trim(($r['player_first_name'] ?? '') . ' ' . ($r['player_last_name'] ?? '')),
      'goals'  => $g,
      'assists'=> $a,
      'points' => $g + $a,
    ];
  }

  return $out;
}

/* ============================================================
 *  API normalization
 * ============================================================ */

function sjms_ncaa_team_from_api($meta, $teamStats, $players) {
  $totalSaves = 0;
  foreach ($players as $p) {
    if (($p['position'] ?? '') === 'G') {
      $totalSaves += intval($p['saves'] ?? 0);
    }
  }

  $ppGoals = intval($teamStats['powerPlayGoals'] ?? 0);
  $ppOps   = intval($teamStats['powerPlayOpportunities'] ?? 0);

  return [
    'short'   => $meta['nameShort'] ?? ($meta['nameFull'] ?? ''),
    'full'    => $meta['nameFull'] ?? '',
    'goals'   => intval($teamStats['goals'] ?? 0),
    'shots'   => intval($teamStats['shots'] ?? 0),
    'pp_goals'=> $ppGoals,
    'pp_ops'  => $ppOps,
    'pp_pct'  => sjms_pp_pct($ppGoals, $ppOps, $teamStats['powerPlayPercentage'] ?? null),
    'pim'     => intval($teamStats['minutes'] ?? 0),
    'blocks'  => intval($teamStats['blk'] ?? 0),
    'fo_won'  => intval($teamStats['facewon'] ?? 0),
    'fo_lost' => intval($teamStats['facelost'] ?? 0),
    'saves'   => $totalSaves,
  ];
}

function sjms_ncaa_scorers_from_api($players) {
  $out = [];
  foreach ($players as $p) {
    $g = intval($p['goals'] ?? 0);
    $a = intval($p['assists'] ?? 0);
    if ($g <= 0 && $a <= 0) continue;

    $first = ucfirst(strtolower($p['firstName'] ?? ''));
    $last  = ucfirst(strtolower($p['lastName'] ?? ''));

    $out[] = [
      'name'   => trim("$first $last"),
      'goals'  => $g,
      'assists'=> $a,
      'points' => $g + $a,
    ];
  }
  return $out;
}

/* ============================================================
 *  Renderer (NHL-style)
 * ============================================================ */

function sjms_ncaa_render_boxscore($date, $away, $home, $awaySc, $homeSc, $statusLabel) {
  ob_start();
  ?>
  <section class="thread-boxscore thread-boxscore--ncaa">
    <header class="thread-boxscore__header">
      <h2>Box Score</h2>

      <div class="thread-boxscore__scoreline">
        <span class="team team--away">
          <?= htmlspecialchars($away['short']) ?> <?= (int)$away['goals'] ?>
        </span>

        <span class="status"><?= htmlspecialchars($statusLabel) ?></span>

        <span class="team team--home">
          <?= htmlspecialchars($home['short']) ?> <?= (int)$home['goals'] ?>
        </span>
      </div>

      <p class="thread-boxscore__date"><?= htmlspecialchars($date) ?></p>
    </header>

    <div class="thread-boxscore__grid">
      <div class="thread-boxscore__col thread-boxscore__col--table">
        <table class="boxscore-table">
          <thead>
            <tr>
              <th>Stat</th>
              <th><?= htmlspecialchars($away['short']) ?></th>
              <th><?= htmlspecialchars($home['short']) ?></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <th>Goals</th>
              <td><?= (int)$away['goals'] ?></td>
              <td><?= (int)$home['goals'] ?></td>
            </tr>
            <tr>
              <th>Shots</th>
              <td><?= (int)$away['shots'] ?></td>
              <td><?= (int)$home['shots'] ?></td>
            </tr>
            <tr>
              <th>Power Play</th>
              <td>
                <?= (int)$away['pp_goals'] ?>/<?= (int)$away['pp_ops'] ?>
                <?php if ($away['pp_pct'] !== null): ?>
                  (<?= number_format((float)$away['pp_pct'], 1) ?>%)
                <?php endif; ?>
              </td>
              <td>
                <?= (int)$home['pp_goals'] ?>/<?= (int)$home['pp_ops'] ?>
                <?php if ($home['pp_pct'] !== null): ?>
                  (<?= number_format((float)$home['pp_pct'], 1) ?>%)
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th>PIM</th>
              <td><?= (int)$away['pim'] ?></td>
              <td><?= (int)$home['pim'] ?></td>
            </tr>
            <tr>
              <th>Faceoffs</th>
              <td><?= (int)$away['fo_won'] ?> / <?= (int)$away['fo_lost'] ?></td>
              <td><?= (int)$home['fo_won'] ?> / <?= (int)$home['fo_lost'] ?></td>
            </tr>
            <tr>
              <th>Blocks</th>
              <td><?= (int)$away['blocks'] ?></td>
              <td><?= (int)$home['blocks'] ?></td>
            </tr>
            <tr>
              <th>Saves</th>
              <td><?= (int)$away['saves'] ?></td>
              <td><?= (int)$home['saves'] ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="thread-boxscore__col thread-boxscore__col--scorers">
        <div class="thread-scorers">

          <div class="thread-scorers__team">
            <table class="boxscore-table boxscore-table--scorers">
              <thead>
                <tr><th colspan="4"><?= htmlspecialchars($away['short']) ?> Scorers</th></tr>
                <tr><th></th><th>G</th><th>A</th><th>PTS</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($awaySc)): ?>
                  <?php foreach ($awaySc as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['name']) ?></td>
                      <td><?= (int)$p['goals'] ?></td>
                      <td><?= (int)$p['assists'] ?></td>
                      <td><?= (int)$p['points'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="4">No scorers.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="thread-scorers__team">
            <table class="boxscore-table boxscore-table--scorers">
              <thead>
                <tr><th colspan="4"><?= htmlspecialchars($home['short']) ?> Scorers</th></tr>
                <tr><th></th><th>G</th><th>A</th><th>PTS</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($homeSc)): ?>
                  <?php foreach ($homeSc as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['name']) ?></td>
                      <td><?= (int)$p['goals'] ?></td>
                      <td><?= (int)$p['assists'] ?></td>
                      <td><?= (int)$p['points'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="4">No scorers.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>
  </section>
  <?php
  return ob_get_clean();
}

/* ============================================================
 *  MAIN ENTRY (used by thread.php AND thread_boxscore_poll.php)
 * ============================================================ */

function sjms_get_ncaa_boxscore_html(PDO $pdo, $contestId, $dateYmd, $threadStartTime = '') {
  $contestId = (int)$contestId;
  if (!$contestId) {
    error_log("[NCAA boxscore] Empty contestId, abort");
    return '';
  }

  $box = sjms_ncaa_fetch_boxscore($contestId);
  if (!$box) {
    error_log("[NCAA boxscore] No box JSON returned");
    return '';
  }

  $status  = sjms_ncaa_status_label($box, $threadStartTime);
  $isFinal = sjms_ncaa_is_final($box);

  // FINAL -> try DB first
  if ($isFinal) {
    $stmt = $pdo->prepare("SELECT * FROM ncaa_team_gamelogs WHERE contest_id = :cid");
    $stmt->execute([':cid' => $contestId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) >= 2) {
      $home = null; $away = null;

      foreach ($rows as $r) {
        if (($r['team_side'] ?? '') === 'home') $home = sjms_ncaa_team_from_db($r);
        if (($r['team_side'] ?? '') === 'away') $away = sjms_ncaa_team_from_db($r);
      }

      // fallback if team_side missing
      if (!$home && isset($rows[0])) $home = sjms_ncaa_team_from_db($rows[0]);
      if (!$away && isset($rows[1])) $away = sjms_ncaa_team_from_db($rows[1]);

      if ($home && $away) {
        $awaySc = sjms_ncaa_scorers_from_db($pdo, $contestId, 'away');
        $homeSc = sjms_ncaa_scorers_from_db($pdo, $contestId, 'home');
        return sjms_ncaa_render_boxscore($dateYmd, $away, $home, $awaySc, $homeSc, $status);
      }
    }
  }

  // API render (LIVE / DB missing)
  $teams   = $box['teams']        ?? [];
  $teamBox = $box['teamBoxscore'] ?? [];

  if (count($teams) < 2 || count($teamBox) < 2) {
    error_log("[NCAA boxscore] Not enough teams/teamBoxscore entries");
    return '';
  }

  $meta = [];
  foreach ($teams as $t) {
    $tid = (int)($t['teamId'] ?? 0);
    if ($tid) $meta[$tid] = $t;
  }

  $home = null; $away = null;
  $homeSc = []; $awaySc = [];

  foreach ($teamBox as $tbox) {
    $tid = (int)($tbox['teamId'] ?? 0);
    if (!$tid) continue;

    $players  = $tbox['playerStats'] ?? [];
    $stats    = $tbox['teamStats']   ?? [];
    $teamMeta = $meta[$tid] ?? ['nameShort' => '', 'nameFull' => ''];

    $isHome = !empty($teamMeta['isHome']);

    $norm    = sjms_ncaa_team_from_api($teamMeta, $stats, $players);
    $scorers = sjms_ncaa_scorers_from_api($players);

    if ($isHome) { $home = $norm; $homeSc = $scorers; }
    else         { $away = $norm; $awaySc = $scorers; }
  }

  if (!$home || !$away) {
    error_log("[NCAA boxscore] Failed to identify home/away from API");
    return '';
  }

  return sjms_ncaa_render_boxscore($dateYmd, $away, $home, $awaySc, $homeSc, $status);
}
