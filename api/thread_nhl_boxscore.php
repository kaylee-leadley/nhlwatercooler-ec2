<?php
// public/includes/thread_nhl_boxscore.php
//
// NHL boxscore renderer used by thread.php + thread_boxscore_poll.php
//
// Provides:
//   sjms_get_boxscore_html(PDO $pdo, array $gameRow): string
//
// Behavior (updated):
//   - API-first for anything not completed (live/upcoming)
//   - Also API-first for completed games on game day + next day (ET) to keep it “fresh”
//   - DB fallback for older completed games (and when API unavailable)
//
// PHP 5.6-safe

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ============================================================
 * Time helpers (ET)
 * ========================================================== */

function sjms_et_today_ymd() {
  $old = date_default_timezone_get();
  date_default_timezone_set('America/New_York');
  $t = date('Y-m-d');
  date_default_timezone_set($old);
  return $t;
}

function sjms_et_yesterday_ymd() {
  $old = date_default_timezone_get();
  date_default_timezone_set('America/New_York');
  $y = date('Y-m-d', strtotime('-1 day'));
  date_default_timezone_set($old);
  return $y;
}

function sjms_nhl_should_prefer_api($gameDateYmd, $playedStatus) {
  $ps = strtolower(trim((string)$playedStatus));

  // If we can’t tell status, prefer API.
  if ($ps === '') return true;

  // Anything not “completed*” is live/upcoming → API.
  if (strncmp($ps, 'completed', 9) !== 0) return true;

  // Completed: still prefer API for freshness through next day (ET).
  $today = sjms_et_today_ymd();
  $yday  = sjms_et_yesterday_ymd();
  if ($gameDateYmd === $today || $gameDateYmd === $yday) return true;

  // Older finals can be DB.
  return false;
}

/* ============================================================
 * Helpers
 * ========================================================== */

function sjms_nhl_period_label($n) {
  $n = (int)$n;
  if ($n === 1) return '1st';
  if ($n === 2) return '2nd';
  if ($n === 3) return '3rd';
  if ($n === 4) return 'OT';
  if ($n === 5) return 'SO';
  return ($n > 0) ? ("P" . $n) : '';
}

function sjms_nhl_played_status_from_api($box) {
  if (isset($box['game']) && is_array($box['game']) && isset($box['game']['playedStatus'])) {
    return strtolower(trim((string)$box['game']['playedStatus']));
  }
  return '';
}

function sjms_nhl_is_final_from_api($box) {
  $playedStatus = sjms_nhl_played_status_from_api($box);
  // completed, completed_pending_review, etc.
  return ($playedStatus !== '' && strncmp($playedStatus, 'completed', 9) === 0);
}

function sjms_nhl_status_label_from_api($box) {
  $playedStatus = sjms_nhl_played_status_from_api($box);
  $scoring = (isset($box['scoring']) && is_array($box['scoring'])) ? $box['scoring'] : array();

  if ($playedStatus === 'completed_pending_review') {
    return 'Final Pending Review';
  }
  if (sjms_nhl_is_final_from_api($box)) {
    return 'Final';
  }

  // Intermission
  $intermission = isset($scoring['currentIntermission']) ? $scoring['currentIntermission'] : null;
  if ($intermission !== null && (int)$intermission > 0) {
    $intNum = (int)$intermission;
    if ($intNum === 1) return '1st INT';
    if ($intNum === 2) return '2nd INT';
    if ($intNum === 3) return '3rd INT';
    return 'OT INT';
  }

  // Live period + clock
  $periodNum  = isset($scoring['currentPeriod']) ? $scoring['currentPeriod'] : null;
  $secondsRem = isset($scoring['currentPeriodSecondsRemaining']) ? $scoring['currentPeriodSecondsRemaining'] : null;

  if ($periodNum !== null && (int)$periodNum > 0) {
    $p = sjms_nhl_period_label((int)$periodNum);

    if ($secondsRem !== null && is_numeric($secondsRem)) {
      $sec = (int)$secondsRem;
      $min = (int)floor($sec / 60);
      $s   = str_pad((string)($sec % 60), 2, '0', STR_PAD_LEFT);
      return $p . " – " . $min . ":" . $s;
    }
    return $p;
  }

  return 'Game Day';
}

function sjms_nhl_safe_name($name) {
  $name = trim((string)$name);
  return ($name !== '') ? $name : 'Unknown';
}

/* ============================================================
 * Renderer (shared)
 * ========================================================== */

function sjms_render_boxscore_common($date, $away, $home, $awaySc, $homeSc, $statusLabel, $extraClass) {
  ob_start();
  ?>
  <section class="thread-boxscore <?php echo htmlspecialchars($extraClass); ?>">
    <header class="thread-boxscore__header">
      <h2>Box Score</h2>
      <div class="thread-boxscore__scoreline">
        <span class="team team--away">
          <?php echo htmlspecialchars($away['short']); ?>
          <?php echo (int)$away['goals']; ?>
        </span>
        <span class="status"><?php echo htmlspecialchars($statusLabel); ?></span>
        <span class="team team--home">
          <?php echo htmlspecialchars($home['short']); ?>
          <?php echo (int)$home['goals']; ?>
        </span>
      </div>
      <p class="thread-boxscore__date"><?php echo htmlspecialchars($date); ?></p>
    </header>

    <div class="thread-boxscore__grid">
      <div class="thread-boxscore__col thread-boxscore__col--table">
        <table class="boxscore-table">
          <thead>
            <tr>
              <th>Stat</th>
              <th><?php echo htmlspecialchars($away['short']); ?></th>
              <th><?php echo htmlspecialchars($home['short']); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr><th>Goals</th><td><?php echo (int)$away['goals']; ?></td><td><?php echo (int)$home['goals']; ?></td></tr>
            <tr><th>Shots</th><td><?php echo (int)$away['shots']; ?></td><td><?php echo (int)$home['shots']; ?></td></tr>
            <tr>
              <th>Power Play</th>
              <td>
                <?php echo (int)$away['pp_goals']; ?>/<?php echo (int)$away['pp_ops']; ?>
                <?php if ($away['pp_pct'] !== null): ?>(<?php echo number_format((float)$away['pp_pct'], 1); ?>%)<?php endif; ?>
              </td>
              <td>
                <?php echo (int)$home['pp_goals']; ?>/<?php echo (int)$home['pp_ops']; ?>
                <?php if ($home['pp_pct'] !== null): ?>(<?php echo number_format((float)$home['pp_pct'], 1); ?>%)<?php endif; ?>
              </td>
            </tr>
            <tr><th>PIM</th><td><?php echo (int)$away['pim']; ?></td><td><?php echo (int)$home['pim']; ?></td></tr>
            <tr><th>Faceoffs</th><td><?php echo (int)$away['fo_won']; ?> / <?php echo (int)$away['fo_lost']; ?></td><td><?php echo (int)$home['fo_won']; ?> / <?php echo (int)$home['fo_lost']; ?></td></tr>
            <tr><th>Blocks</th><td><?php echo (int)$away['blocks']; ?></td><td><?php echo (int)$home['blocks']; ?></td></tr>
            <tr><th>Saves</th><td><?php echo (int)$away['saves']; ?></td><td><?php echo (int)$home['saves']; ?></td></tr>
          </tbody>
        </table>
      </div>

      <div class="thread-boxscore__col thread-boxscore__col--scorers">
        <div class="thread-scorers">
          <div class="thread-scorers__team">
            <table class="boxscore-table boxscore-table--scorers">
              <thead>
                <tr><th colspan="4"><?php echo htmlspecialchars($away['short']); ?> Scorers</th></tr>
                <tr><th></th><th>G</th><th>A</th><th>PTS</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($awaySc)): ?>
                  <?php foreach ($awaySc as $p): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($p['name']); ?></td>
                      <td><?php echo (int)$p['goals']; ?></td>
                      <td><?php echo (int)$p['assists']; ?></td>
                      <td><?php echo (int)$p['points']; ?></td>
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
                <tr><th colspan="4"><?php echo htmlspecialchars($home['short']); ?> Scorers</th></tr>
                <tr><th></th><th>G</th><th>A</th><th>PTS</th></tr>
              </thead>
              <tbody>
                <?php if (!empty($homeSc)): ?>
                  <?php foreach ($homeSc as $p): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($p['name']); ?></td>
                      <td><?php echo (int)$p['goals']; ?></td>
                      <td><?php echo (int)$p['assists']; ?></td>
                      <td><?php echo (int)$p['points']; ?></td>
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
 * DB loaders
 * ========================================================== */

function sjms_nhl_db_team_stats($pdo, $gid) {
  $stmt = $pdo->prepare("
    SELECT
      tgl.*,
      g.home_team_abbr,
      g.away_team_abbr
    FROM msf_team_gamelogs tgl
    JOIN msf_games g ON g.msf_game_id = tgl.msf_game_id
    WHERE tgl.msf_game_id = :gid
  ");
  $stmt->execute(array(':gid' => $gid));
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  return $rows ? $rows : array();
}

function sjms_nhl_normalize_team_from_db($row) {
  $shotsAgainst = (int)(isset($row['shots_against']) ? $row['shots_against'] : 0);
  $goalsAgainst = (int)(isset($row['goals_against']) ? $row['goals_against'] : 0);

  return array(
    'short'    => isset($row['team_abbr']) ? (string)$row['team_abbr'] : '',
    'goals'    => (int)(isset($row['goals_for']) ? $row['goals_for'] : 0),
    'shots'    => (int)(isset($row['shots']) ? $row['shots'] : 0),
    'pp_goals' => (int)(isset($row['powerplay_goals']) ? $row['powerplay_goals'] : 0),
    'pp_ops'   => (int)(isset($row['powerplays']) ? $row['powerplays'] : 0),
    'pp_pct'   => isset($row['powerplay_percent']) ? (float)$row['powerplay_percent'] : null,
    'pim'      => (int)(isset($row['penalty_minutes']) ? $row['penalty_minutes'] : 0),
    'fo_won'   => (int)(isset($row['faceoff_wins']) ? $row['faceoff_wins'] : 0),
    'fo_lost'  => (int)(isset($row['faceoff_losses']) ? $row['faceoff_losses'] : 0),
    'blocks'   => (int)(isset($row['blocked_shots']) ? $row['blocked_shots'] : 0),
    'saves'    => (int)max(0, $shotsAgainst - $goalsAgainst),
  );
}

function sjms_nhl_format_scorers_rows($rows) {
  $out = array();
  if (!$rows) return $out;

  foreach ($rows as $r) {
    $g = (int)(isset($r['goals']) ? $r['goals'] : 0);
    $a = (int)(isset($r['assists']) ? $r['assists'] : 0);
    if ($g <= 0 && $a <= 0) continue;

    $name = trim(
      (isset($r['first_name']) ? $r['first_name'] : '') . ' ' .
      (isset($r['last_name']) ? $r['last_name'] : '')
    );
    if ($name === '') $name = 'Unknown';

    $out[] = array(
      'name'    => $name,
      'goals'   => $g,
      'assists' => $a,
      'points'  => $g + $a,
    );
  }

  usort($out, function($x, $y) {
    if ($x['goals'] !== $y['goals']) return ($y['goals'] - $x['goals']);
    if ($x['assists'] !== $y['assists']) return ($y['assists'] - $x['assists']);
    return strcmp($x['name'], $y['name']);
  });

  return $out;
}

function sjms_nhl_db_scorers_by_abbr($pdo, $gid, $teamAbbr) {
  $teamAbbr = strtoupper(trim((string)$teamAbbr));
  if ($teamAbbr === '') return array();

  try {
    $stmt = $pdo->prepare("
      SELECT first_name, last_name, goals, assists
      FROM msf_player_gamelogs
      WHERE msf_game_id = :gid
        AND UPPER(team_abbr) = :abbr
        AND (goals > 0 OR assists > 0)
      ORDER BY goals DESC, assists DESC, last_name, first_name
    ");
    $stmt->execute(array(':gid' => $gid, ':abbr' => $teamAbbr));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return sjms_nhl_format_scorers_rows($rows);
  } catch (Exception $e) {
    error_log('[NHL boxscore] scorers by team_abbr failed: ' . $e->getMessage());
  }

  return array();
}

/* ============================================================
 * MSF fetch + parse (API)
 * ========================================================== */

function sjms_msf_fetch_boxscore_api($gameRow, $apiKey) {
  $season = isset($gameRow['season']) ? $gameRow['season'] : '';
  $date   = isset($gameRow['game_date']) ? $gameRow['game_date'] : '';
  $away   = strtoupper(isset($gameRow['away_team_abbr']) ? $gameRow['away_team_abbr'] : '');
  $home   = strtoupper(isset($gameRow['home_team_abbr']) ? $gameRow['home_team_abbr'] : '');

  if (!$season || !$date || !$away || !$home || !$apiKey) {
    error_log('[NHL boxscore] Missing season/date/away/home/apiKey; cannot fetch API');
    return null;
  }

  $url = "https://api.mysportsfeeds.com/v2.1/pull/nhl/{$season}-regular/games/"
       . str_replace('-', '', $date) . "-{$away}-{$home}/boxscore.json";

  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => 'gzip',
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => array(
      'Authorization: Basic ' . base64_encode($apiKey . ':MYSPORTSFEEDS'),
    ),
  ));

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    error_log('[NHL boxscore] CURL error: ' . $err);
    return null;
  }

  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($code < 200 || $code >= 300) {
    error_log('[NHL boxscore] HTTP ' . $code . ' from MSF boxscore endpoint');
    return null;
  }

  $data = json_decode($resp, true);
  if (!is_array($data)) {
    error_log('[NHL boxscore] Non-JSON response from MSF');
    return null;
  }

  return $data;
}

function sjms_nhl_api_team_from_stats($box, $side, $abbrOverride) {
  $side = strtolower((string)$side);
  $abbr = strtoupper((string)$abbrOverride);

  $statsRoot = isset($box['stats']) && is_array($box['stats']) ? $box['stats'] : array();
  $node = isset($statsRoot[$side]) && is_array($statsRoot[$side]) ? $statsRoot[$side] : array();

  $teamStatsArr = isset($node['teamStats']) && is_array($node['teamStats']) ? $node['teamStats'] : array();
  $teamStats0 = isset($teamStatsArr[0]) && is_array($teamStatsArr[0]) ? $teamStatsArr[0] : array();

  $faceoffs  = isset($teamStats0['faceoffs']) && is_array($teamStats0['faceoffs']) ? $teamStats0['faceoffs'] : array();
  $powerplay = isset($teamStats0['powerplay']) && is_array($teamStats0['powerplay']) ? $teamStats0['powerplay'] : array();
  $misc      = isset($teamStats0['miscellaneous']) && is_array($teamStats0['miscellaneous']) ? $teamStats0['miscellaneous'] : array();

  $goalsFor     = (int)(isset($misc['goalsFor']) ? $misc['goalsFor'] : 0);
  $goalsAgainst = (int)(isset($misc['goalsAgainst']) ? $misc['goalsAgainst'] : 0);
  $shotsFor     = (int)(isset($misc['shots']) ? $misc['shots'] : 0);
  $shotsAgainst = (int)(isset($misc['shAgainst']) ? $misc['shAgainst'] : 0);

  $saves = 0;
  if ($shotsAgainst > 0) {
    $saves = $shotsAgainst - $goalsAgainst;
    if ($saves < 0) $saves = 0;
  }

  return array(
    'short'   => ($abbr !== '' ? $abbr : strtoupper($side)),
    'goals'   => $goalsFor,
    'shots'   => $shotsFor,
    'pp_goals'=> (int)(isset($powerplay['powerplayGoals']) ? $powerplay['powerplayGoals'] : 0),
    'pp_ops'  => (int)(isset($powerplay['powerplays']) ? $powerplay['powerplays'] : 0),
    'pp_pct'  => isset($powerplay['powerplayPercent']) ? (float)$powerplay['powerplayPercent'] : null,
    'pim'     => (int)(isset($misc['penaltyMinutes']) ? $misc['penaltyMinutes'] : 0),
    'fo_won'  => (int)(isset($faceoffs['faceoffWins']) ? $faceoffs['faceoffWins'] : 0),
    'fo_lost' => (int)(isset($faceoffs['faceoffLosses']) ? $faceoffs['faceoffLosses'] : 0),
    'blocks'  => (int)(isset($misc['blockedShots']) ? $misc['blockedShots'] : 0),
    'saves'   => (int)$saves,
  );
}

function sjms_nhl_add_tally(&$tally, $name, $field, $inc) {
  if (!isset($tally[$name])) $tally[$name] = array('goals' => 0, 'assists' => 0);
  $tally[$name][$field] += (int)$inc;
}

function sjms_nhl_tally_to_sorted_list($tally) {
  $out = array();
  foreach ($tally as $name => $ga) {
    $g = (int)$ga['goals'];
    $a = (int)$ga['assists'];
    if ($g <= 0 && $a <= 0) continue;
    $out[] = array('name' => $name, 'goals' => $g, 'assists' => $a, 'points' => $g + $a);
  }

  usort($out, function($x, $y) {
    if ($x['goals'] !== $y['goals']) return ($y['goals'] - $x['goals']);
    if ($x['assists'] !== $y['assists']) return ($y['assists'] - $x['assists']);
    return strcmp($x['name'], $y['name']);
  });

  return $out;
}

function sjms_nhl_parse_goal_play($desc) {
  $desc = trim((string)$desc);
  if ($desc === '') return array('', array());

  $scorer = '';
  if (preg_match('/Goal scored by\s+([^,(]+?)(?:\s*\(|,|\.|$)/i', $desc, $m)) {
    $scorer = sjms_nhl_safe_name($m[1]);
  }

  $assists = array();
  if (preg_match('/assisted by\s+(.+?)\./i', $desc, $m)) {
    $list = trim($m[1]);
    $list = str_replace(' and ', ', ', $list);
    $parts = explode(',', $list);
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p !== '') $assists[] = sjms_nhl_safe_name($p);
    }
  }

  return array($scorer, $assists);
}

function sjms_nhl_live_scorers_from_api($box, $awayAbbr, $homeAbbr) {
  $awayAbbr = strtoupper((string)$awayAbbr);
  $homeAbbr = strtoupper((string)$homeAbbr);

  $awayTally = array();
  $homeTally = array();

  $scoring = isset($box['scoring']) && is_array($box['scoring']) ? $box['scoring'] : array();
  $periods = isset($scoring['periods']) && is_array($scoring['periods']) ? $scoring['periods'] : array();
  if (empty($periods)) return array(array(), array());

  foreach ($periods as $per) {
    if (!is_array($per)) continue;
    $plays = isset($per['scoringPlays']) && is_array($per['scoringPlays']) ? $per['scoringPlays'] : array();
    if (empty($plays)) continue;

    foreach ($plays as $play) {
      if (!is_array($play)) continue;

      $teamAbbr = '';
      if (isset($play['team']) && is_array($play['team']) && isset($play['team']['abbreviation'])) {
        $teamAbbr = strtoupper((string)$play['team']['abbreviation']);
      }

      $desc = isset($play['playDescription']) ? $play['playDescription'] : '';
      if (stripos($desc, 'Goal scored by') === false) continue;

      list($scorer, $assists) = sjms_nhl_parse_goal_play($desc);

      if ($teamAbbr === $awayAbbr) {
        if ($scorer !== '') sjms_nhl_add_tally($awayTally, $scorer, 'goals', 1);
        foreach ($assists as $a) sjms_nhl_add_tally($awayTally, $a, 'assists', 1);
      } elseif ($teamAbbr === $homeAbbr) {
        if ($scorer !== '') sjms_nhl_add_tally($homeTally, $scorer, 'goals', 1);
        foreach ($assists as $a) sjms_nhl_add_tally($homeTally, $a, 'assists', 1);
      }
    }
  }

  return array(
    sjms_nhl_tally_to_sorted_list($awayTally),
    sjms_nhl_tally_to_sorted_list($homeTally),
  );
}

/* ============================================================
 * Public entrypoint
 * ========================================================== */

function sjms_get_boxscore_html($pdo, $gameRow) {
  $gid  = (int)(isset($gameRow['msf_game_id']) ? $gameRow['msf_game_id'] : 0);
  $date = (string)(isset($gameRow['game_date']) ? $gameRow['game_date'] : '');

  if (!$gid || !$date) {
    error_log('[NHL boxscore] Missing gid/date in gameRow');
    return '';
  }

  // Load MSF key
  $msfConfigPath = __DIR__ . '/../../config/msf.php';
  $msfConfig = file_exists($msfConfigPath) ? require $msfConfigPath : array();
  $apiKey = isset($msfConfig['api_key']) ? $msfConfig['api_key'] : '';

  // Probe API first so we can decide DB vs API
  $box = null;
  $playedStatus = '';
  $statusLabelFromApi = '';

  if ($apiKey) {
    $box = sjms_msf_fetch_boxscore_api($gameRow, $apiKey);
    if (is_array($box)) {
      $playedStatus = sjms_nhl_played_status_from_api($box);
      $statusLabelFromApi = sjms_nhl_status_label_from_api($box);
    }
  }

  // API-first for live/upcoming, and for completed games on game day + next day ET
  if (is_array($box) && sjms_nhl_should_prefer_api($date, $playedStatus)) {
    $awayAbbr = strtoupper(isset($gameRow['away_team_abbr']) ? $gameRow['away_team_abbr'] : 'AWAY');
    $homeAbbr = strtoupper(isset($gameRow['home_team_abbr']) ? $gameRow['home_team_abbr'] : 'HOME');

    $away = sjms_nhl_api_team_from_stats($box, 'away', $awayAbbr);
    $home = sjms_nhl_api_team_from_stats($box, 'home', $homeAbbr);

    $pair = sjms_nhl_live_scorers_from_api($box, $awayAbbr, $homeAbbr);
    $awaySc = (isset($pair[0]) && is_array($pair[0])) ? $pair[0] : array();
    $homeSc = (isset($pair[1]) && is_array($pair[1])) ? $pair[1] : array();

    $label = $statusLabelFromApi ? $statusLabelFromApi : 'Game Day';
    return sjms_render_boxscore_common($date, $away, $home, $awaySc, $homeSc, $label, 'thread-boxscore--nhl');
  }

  // DB fallback path (older finals, or API unavailable)
  $rows = array();
  try {
    $rows = sjms_nhl_db_team_stats($pdo, $gid);
  } catch (Exception $e) {
    error_log('[NHL boxscore] team stats load failed: ' . $e->getMessage());
    $rows = array();
  }

  if (count($rows) >= 2) {
    $home = null;
    $away = null;

    $homeAbbr = '';
    $awayAbbr = '';
    if (!empty($rows[0]['home_team_abbr'])) $homeAbbr = strtoupper((string)$rows[0]['home_team_abbr']);
    if (!empty($rows[0]['away_team_abbr'])) $awayAbbr = strtoupper((string)$rows[0]['away_team_abbr']);

    foreach ($rows as $r) {
      $abbr = strtoupper((string)(isset($r['team_abbr']) ? $r['team_abbr'] : ''));
      if ($abbr !== '' && $homeAbbr !== '' && $abbr === $homeAbbr) $home = sjms_nhl_normalize_team_from_db($r);
      if ($abbr !== '' && $awayAbbr !== '' && $abbr === $awayAbbr) $away = sjms_nhl_normalize_team_from_db($r);
    }

    if (!$away && isset($rows[0])) $away = sjms_nhl_normalize_team_from_db($rows[0]);
    if (!$home && isset($rows[1])) $home = sjms_nhl_normalize_team_from_db($rows[1]);

    $awaySc = array();
    $homeSc = array();
    if ($awayAbbr !== '') $awaySc = sjms_nhl_db_scorers_by_abbr($pdo, $gid, $awayAbbr);
    if ($homeAbbr !== '') $homeSc = sjms_nhl_db_scorers_by_abbr($pdo, $gid, $homeAbbr);

    // Older DB finals: label as Final (DB does not store a live clock/period)
    return sjms_render_boxscore_common($date, $away, $home, $awaySc, $homeSc, 'Final', 'thread-boxscore--nhl');
  }

  // No DB rows and API unavailable
  if (!$apiKey) {
    error_log('[NHL boxscore] Missing api_key in config/msf.php');
    return '';
  }

  // If we get here, we didn't have $box earlier (or it wasn't array) — try again.
  if (!is_array($box)) {
    $box = sjms_msf_fetch_boxscore_api($gameRow, $apiKey);
  }
  if (!$box || !isset($box['scoring']) || !is_array($box['scoring'])) {
    error_log('[NHL boxscore] No scoring node from MSF');
    return '';
  }

  $awayAbbr = strtoupper(isset($gameRow['away_team_abbr']) ? $gameRow['away_team_abbr'] : 'AWAY');
  $homeAbbr = strtoupper(isset($gameRow['home_team_abbr']) ? $gameRow['home_team_abbr'] : 'HOME');

  $away = sjms_nhl_api_team_from_stats($box, 'away', $awayAbbr);
  $home = sjms_nhl_api_team_from_stats($box, 'home', $homeAbbr);

  $statusLabel = sjms_nhl_status_label_from_api($box);

  $pair = sjms_nhl_live_scorers_from_api($box, $awayAbbr, $homeAbbr);
  $awaySc = (isset($pair[0]) && is_array($pair[0])) ? $pair[0] : array();
  $homeSc = (isset($pair[1]) && is_array($pair[1])) ? $pair[1] : array();

  return sjms_render_boxscore_common($date, $away, $home, $awaySc, $homeSc, $statusLabel, 'thread-boxscore--nhl');
}
