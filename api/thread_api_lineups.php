<?php
// public/api/thread_api_lineups.php
//
// Primary source: dailyfaceoff_lines (sections F/D/G) using team_slug + lines_date (YYYY-MM-DD)
// Fallback:       lineups table (by msf game id)
//
// Advanced stats have been MOVED OUT to /public/api/thread_adv_stats.php
//
// Exposes:
//   sjms_get_lineups_html(PDO $pdo, $msfGameId, $homeAbbr = null, $awayAbbr = null)

require_once __DIR__ . '/../includes/team_map.php';

// Advanced stats bootstrap (chips + renderer)
$__sjms_thread_adv = __DIR__ . '/../helpers/thread_adv_stats_bootstrap.php';
if (is_file($__sjms_thread_adv)) {
  require_once $__sjms_thread_adv;
}

/* ============================================================
 *  Credit
 * ============================================================ */

if (!function_exists('sjms_dfo_credit_html')) {
  function sjms_dfo_credit_html() {
    return '<p class="thread-credit thread-credit--dfo">Source: DailyFaceoff.com</p>';
  }
}

/* ============================================================
 *  Tiny utils
 * ============================================================ */

if (!function_exists('sjms_norm_space')) {
  function sjms_norm_space($s) {
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return trim($s);
  }
}

if (!function_exists('sjms_slugify_key')) {
  function sjms_slugify_key($s) {
    $s = sjms_norm_space($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  }
}

if (!function_exists('sjms_player_key_from_parts')) {
  function sjms_player_key_from_parts($first, $last) {
    $first = sjms_slugify_key($first);
    $last  = sjms_slugify_key($last);
    if ($first === '' && $last === '') return '';
    return trim($first . ' ' . $last);
  }
}

if (!function_exists('sjms_player_key_from_full')) {
  function sjms_player_key_from_full($fullName) {
    $fullName = sjms_norm_space($fullName);
    if ($fullName === '') return '';
    $fullName = preg_replace('/\b(JR|SR|II|III|IV)\b\.?/i', '', $fullName);
    $fullName = sjms_norm_space($fullName);
    return sjms_slugify_key($fullName);
  }
}

/**
 * Canonicalize leading initial tokens:
 *  "j j peterka" -> "jj peterka"
 *  "j j j smith" -> "jjj smith"
 */
if (!function_exists('sjms_player_key_canon')) {
  function sjms_player_key_canon($slugKey) {
    $slugKey = sjms_norm_space($slugKey);
    if ($slugKey === '') return '';

    $parts = preg_split('/\s+/u', $slugKey);
    if (!$parts) return $slugKey;

    $i = 0;
    $acc = '';
    while ($i < count($parts)) {
      $t = $parts[$i];
      if (strlen($t) === 1 && preg_match('/^[a-z]$/', $t)) {
        $acc .= $t;
        $i++;
        continue;
      }
      break;
    }

    if ($acc !== '') {
      $rest = array_slice($parts, $i);
      array_unshift($rest, $acc);
      return implode(' ', $rest);
    }

    return implode(' ', $parts);
  }
}

if (!function_exists('sjms_team_logo_url')) {
  function sjms_team_logo_url($teamAbbr) {
    $abbr = strtoupper(trim((string)$teamAbbr));
    if ($abbr === '') return null;

    $fs = __DIR__ . '/../assets/img/logos/' . $abbr . '.png';
    if (!is_file($fs)) return null;

    return '/assets/img/logos/' . $abbr . '.png';
  }
}

/* ============================================================
 *  Timestamp helpers (DFO scraped_at -> "As of YYYY-MM-DD h:iAM EST/EDT")
 * ============================================================ */

if (!function_exists('sjms_format_asof_from_utc')) {
  function sjms_format_asof_from_utc($utcTs, $fallbackDate) {
    $fallbackDate = trim((string)$fallbackDate);
    $utcTs = ($utcTs === null) ? '' : trim((string)$utcTs);

    if ($utcTs === '') {
      return $fallbackDate !== '' ? ('As of ' . $fallbackDate) : '';
    }

    try {
      $tzUtc = new DateTimeZone('UTC');
      $tzEt  = new DateTimeZone('America/New_York');

      $dt = new DateTime($utcTs, $tzUtc);
      $dt->setTimezone($tzEt);

      $tzAbbr = $dt->format('T');
      return 'As of ' . $dt->format('Y-m-d g:iA') . ' ' . $tzAbbr;
    } catch (Exception $e) {
      return $fallbackDate !== '' ? ('As of ' . $fallbackDate) : '';
    }
  }
}

if (!function_exists('sjms_fetch_latest_dfo_scraped_at_lineups')) {
  function sjms_fetch_latest_dfo_scraped_at_lineups(PDO $pdo, $gameDate, $teamSlugs) {
    $gameDate = trim((string)$gameDate);
    if ($gameDate === '' || empty($teamSlugs) || !is_array($teamSlugs)) return null;

    $teamSlugs = array_values(array_unique(array_filter(array_map(function ($s) {
      return strtolower(trim((string)$s));
    }, $teamSlugs))));

    if (!$teamSlugs) return null;

    $ph = implode(',', array_fill(0, count($teamSlugs), '?'));

    $sql = "
      SELECT MAX(scraped_at) AS max_scraped
      FROM dailyfaceoff_lines
      WHERE lines_date = ?
        AND team_slug IN ($ph)
        AND section IN ('F','D','G')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array($gameDate), $teamSlugs));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    $v = trim((string)($r['max_scraped'] ?? ''));
    return $v !== '' ? $v : null;
  }
}

/* ============================================================
 *  Player ID mapping (DFO name -> player_id) via lineups table
 *  Also builds team+jersey fallback: TEAM#NN -> player_id
 * ============================================================ */

if (!function_exists('sjms_build_playerid_map_for_game')) {
  function sjms_build_playerid_map_for_game(PDO $pdo, $msfGameId) {
    $gid = (int)$msfGameId;
    if ($gid <= 0) return array('by_name'=>array(), 'by_team_jersey'=>array());

    $sql = "
      SELECT player_id, first_name, last_name, team_abbr, jersey_number
      FROM lineups
      WHERE game_id = :gid
        AND player_id IS NOT NULL
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array(':gid' => $gid));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return array('by_name'=>array(), 'by_team_jersey'=>array());

    $byName = array();
    $byTJ   = array();

    foreach ($rows as $r) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;

      $first = (string)($r['first_name'] ?? '');
      $last  = (string)($r['last_name'] ?? '');

      $k = sjms_player_key_canon(sjms_player_key_from_parts($first, $last));
      if ($k !== '' && !isset($byName[$k])) $byName[$k] = $pid;

      $full = sjms_player_key_canon(sjms_player_key_from_full(trim($first . ' ' . $last)));
      if ($full !== '' && !isset($byName[$full])) $byName[$full] = $pid;

      $team = strtoupper(trim((string)($r['team_abbr'] ?? '')));
      $jn   = $r['jersey_number'] ?? null;
      if ($team !== '' && $jn !== null && $jn !== '' && is_numeric($jn)) {
        $tj = $team . '#' . (int)$jn;
        if (!isset($byTJ[$tj])) $byTJ[$tj] = $pid;
      }
    }

    return array(
      'by_name' => $byName,
      'by_team_jersey' => $byTJ,
    );
  }
}

/* ============================================================
 *  DFO row -> lineup_position mapper
 * ============================================================ */

if (!function_exists('sjms_dfo_to_lineup_position')) {
  function sjms_dfo_to_lineup_position($r) {
    $section = strtoupper(trim($r['section'] ?? ''));
    $lineNo  = (int)($r['line_no'] ?? 0);
    $pos     = strtoupper(trim($r['position_code'] ?? ''));
    $slotNo  = (int)($r['slot_no'] ?? 0);

    if ($section === 'F') {
      if ($pos === 'L'  || $pos === 'LW') $pos = 'LW';
      if ($pos === 'R'  || $pos === 'RW') $pos = 'RW';
      if ($pos === 'CTR' || $pos === 'CE') $pos = 'C';

      if ($lineNo >= 1 && $lineNo <= 4 && in_array($pos, array('LW','C','RW'), true)) {
        return "ForwardLine{$lineNo}-{$pos}";
      }
      return null;
    }

    if ($section === 'D') {
      if ($pos === 'LD') $pos = 'L';
      if ($pos === 'RD') $pos = 'R';

      if ($lineNo >= 1 && $lineNo <= 3 && in_array($pos, array('L','R'), true)) {
        return "DefensePair{$lineNo}-{$pos}";
      }
      return null;
    }

    if ($section === 'G') {
      if ($slotNo === 2 || $lineNo === 2) return 'Goalie-Backup';
      return 'Goalie-Starter';
    }

    return null;
  }
}

/* ============================================================
 *  PRIMARY: Fetch DFO lineups from dailyfaceoff_lines
 * ============================================================ */

if (!function_exists('sjms_fetch_lineups_grouped_primary')) {
  function sjms_fetch_lineups_grouped_primary(PDO $pdo, $gameDate, $teamsAbbr, $playerIdMap) {
    $gameDate = trim((string)$gameDate);
    if ($gameDate === '' || empty($teamsAbbr)) return array();

    $slugs = array();
    $slugToAbbr = array();

    foreach ($teamsAbbr as $abbr) {
      $abbr = strtoupper(trim((string)$abbr));
      if ($abbr === '') continue;

      $slug = sjms_team_abbr_to_slug($abbr);
      $slug = strtolower(trim((string)$slug));
      if ($slug === '') continue;

      $slugs[] = $slug;
      $slugToAbbr[$slug] = $abbr;
    }

    $slugs = array_values(array_unique(array_filter($slugs)));
    if (!$slugs) return array();

    $ph = implode(',', array_fill(0, count($slugs), '?'));

    $sql = "
      SELECT
        team_slug,
        section,
        line_no,
        position_code,
        slot_no,
        player_name,
        jersey_number,
        goalie_status,
        goalie_start_score,
        scraped_at
      FROM dailyfaceoff_lines
      WHERE lines_date = ?
        AND team_slug IN ($ph)
        AND section IN ('F','D','G')
      ORDER BY team_slug, section, line_no, slot_no, position_code, player_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array($gameDate), $slugs));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return array();

    $maps  = is_array($playerIdMap) ? $playerIdMap : array();
    $byName = $maps['by_name'] ?? array();
    $byTJ   = $maps['by_team_jersey'] ?? array();

    $grouped = array('expected' => array());

    foreach ($rows as $r) {
      $slug = strtolower(trim($r['team_slug'] ?? ''));
      if ($slug === '') continue;

      $abbr = $slugToAbbr[$slug] ?? sjms_team_slug_to_abbr($slug);
      $abbr = strtoupper(trim((string)$abbr));
      if ($abbr === '') continue;

      $lp = sjms_dfo_to_lineup_position($r);
      if (!$lp) continue;

      $name = sjms_norm_space($r['player_name'] ?? '');
      if ($name === '') continue;

      $first = '';
      $last  = $name;
      if (strpos($name, ' ') !== false) {
        $parts = preg_split('/\s+/', $name);
        $first = trim((string)array_shift($parts));
        $last  = trim(implode(' ', $parts));
      }

      // Map DFO name -> PID (name first; fallback team+jersey)
      $playerKey = sjms_player_key_canon(sjms_player_key_from_full($name));
      $pid = 0;

      if ($playerKey !== '' && isset($byName[$playerKey])) {
        $pid = (int)$byName[$playerKey];
      } else {
        $jn = $r['jersey_number'] ?? null;
        if ($abbr !== '' && $jn !== null && $jn !== '' && is_numeric($jn)) {
          $tj = $abbr . '#' . (int)$jn;
          if (isset($byTJ[$tj])) $pid = (int)$byTJ[$tj];
        }
      }

      // Optional: log mapping misses when adv_dbg=1
      if ($pid <= 0 && function_exists('sjms_adv_dbg_chips_enabled') && sjms_adv_dbg_chips_enabled()) {
        error_log('[ADVCHIP] map_miss ' . json_encode(array(
          'gid' => (int)$gameDate,
          'team' => $abbr,
          'name' => $name,
          'playerKey' => $playerKey,
          'jersey_number' => $r['jersey_number'] ?? null,
        ), JSON_UNESCAPED_SLASHES));
      }

      $grouped['expected'][$abbr][] = array(
        'lineup_type'        => 'expected',
        'team_abbr'          => $abbr,
        'lineup_position'    => $lp,
        'first_name'         => $first,
        'last_name'          => $last,
        'player_id'          => $pid > 0 ? $pid : null,
        'jersey_number'      => $r['jersey_number'] ?? null,
        'goalie_status'      => $r['goalie_status'] ?? null,
        'goalie_start_score' => $r['goalie_start_score'] ?? null,
        'last_updated_on'    => $r['scraped_at'] ?? null,
      );
    }

    foreach ($teamsAbbr as $abbr) {
      $abbr = strtoupper(trim((string)$abbr));
      if ($abbr !== '' && !empty($grouped['expected'][$abbr])) {
        return $grouped;
      }
    }
    return array();
  }
}

/* ============================================================
 *  FALLBACK: Fetch lineups from lineups table
 * ============================================================ */

if (!function_exists('sjms_fetch_lineups_grouped_fallback')) {
  function sjms_fetch_lineups_grouped_fallback(PDO $pdo, $msfGameId) {
    $sql = "
      SELECT
        season,
        game_id,
        game_key,
        game_start,
        team_id,
        team_abbr,
        lineup_type,
        lineup_position,
        player_id,
        first_name,
        last_name,
        player_position,
        jersey_number,
        last_updated_on
      FROM lineups
      WHERE game_id = :gid
      ORDER BY lineup_type, team_abbr, lineup_position, last_name, first_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':gid' => (int)$msfGameId));

    $grouped = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $type = $row['lineup_type'] ? $row['lineup_type'] : 'expected';
      $team = $row['team_abbr'] ? $row['team_abbr'] : 'UNK';
      if (!isset($grouped[$type])) $grouped[$type] = array();
      if (!isset($grouped[$type][$team])) $grouped[$type][$team] = array();
      $grouped[$type][$team][] = $row;
    }

    return $grouped;
  }
}

/* ============================================================
 *  Goalie row class helper (applied to whole <tr>)
 * ============================================================ */

if (!function_exists('sjms_goalie_status_tr_class')) {
  function sjms_goalie_status_tr_class($row) {
    $status = trim((string)($row['goalie_status'] ?? ''));
    if ($status === '') return '';

    $k = strtolower($status);
    $k = preg_replace('/[^a-z0-9]+/', '-', $k);
    $k = trim($k, '-');
    return $k !== '' ? ('goalie-status--' . $k) : '';
  }
}

/* ============================================================
 *  Game date (ET) for DFO lookup
 * ============================================================ */

if (!function_exists('sjms_guess_game_date')) {
  function sjms_guess_game_date(PDO $pdo, $msfGameId) {
    $gid = (int)$msfGameId;

    try {
      $stmt = $pdo->prepare("SELECT game_date FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
      $stmt->execute(array(':gid' => $gid));
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!empty($r['game_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['game_date'])) {
        return $r['game_date'];
      }
    } catch (Exception $e) {}

    $tzEt  = new DateTimeZone('America/New_York');
    $tzUtc = new DateTimeZone('UTC');

    $candidates = array();

    try {
      $stmt = $pdo->prepare("
        SELECT start_time, game_start
        FROM msf_games
        WHERE msf_game_id = :gid
        LIMIT 1
      ");
      $stmt->execute(array(':gid' => $gid));
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($r) {
        foreach (array('start_time','game_start') as $k) {
          if (!empty($r[$k])) $candidates[] = $r[$k];
        }
      }
    } catch (Exception $e) {}

    try {
      $stmt = $pdo->prepare("SELECT game_start FROM lineups WHERE game_id = :gid AND game_start IS NOT NULL LIMIT 1");
      $stmt->execute(array(':gid' => $gid));
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!empty($r['game_start'])) $candidates[] = $r['game_start'];
    } catch (Exception $e) {}

    foreach ($candidates as $val) {
      $s = trim((string)$val);
      if ($s === '') continue;

      try {
        $dt = new DateTime($s, $tzUtc);
        $dt->setTimezone($tzEt);
        return $dt->format('Y-m-d');
      } catch (Exception $e) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
          return substr($s, 0, 10);
        }
      }
    }

    return null;
  }
}

/* ============================================================
 *  Slot mapping + labels
 * ============================================================ */

if (!function_exists('sjms_map_slot')) {
  function sjms_map_slot($lineupPosition) {
    $lineupPosition = (string)$lineupPosition;

    if (preg_match('/^ForwardLine([1-4])-(LW|C|RW)$/', $lineupPosition, $m)) {
      $line   = (int)$m[1];
      $side   = $m[2];
      $colKey = $side === 'LW' ? 'L' : ($side === 'RW' ? 'R' : 'C');
      $slot   = sprintf('F_%s%d', $colKey, $line);
      return array($slot, null);
    }

    if (preg_match('/^DefensePair([1-3])-(L|R)$/', $lineupPosition, $m)) {
      $pair = (int)$m[1];
      $side = $m[2];
      $slot = sprintf('D%d_%s', $pair, $side);
      return array($slot, null);
    }

    if ($lineupPosition === 'Goalie-Starter') return array('G1', null);
    if ($lineupPosition === 'Goalie-Backup')  return array('G2', null);

    return array(null, $lineupPosition);
  }
}

if (!function_exists('sjms_player_label')) {
  function sjms_player_label($row) {
    $first = isset($row['first_name']) ? trim((string)$row['first_name']) : '';
    $last  = isset($row['last_name'])  ? trim((string)$row['last_name'])  : '';
    return trim($first . ' ' . $last);
  }
}

if (!function_exists('sjms_goalie_label_html')) {
  function sjms_goalie_label_html($row) {
    $name = sjms_player_label($row);
    $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $status = trim((string)($row['goalie_status'] ?? ''));
    $scoreRaw = $row['goalie_start_score'] ?? null;

    $score = null;
    if ($scoreRaw !== null && $scoreRaw !== '' && is_numeric($scoreRaw)) {
      $score = (int)$scoreRaw;
      if ($score < 0) $score = 0;
      if ($score > 100) $score = 100;
    }

    if ($status === '' && $score === null) {
      return $nameEsc;
    }

    $parts = array();

    if ($status !== '') {
      $statusEsc = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
      $parts[] = '<span class="goalie-status-text"><span class="goalie-meta-label">Status:</span> '.$statusEsc.'</span>';
    }

    if ($score !== null) {
      $scoreEsc = htmlspecialchars((string)$score, ENT_QUOTES, 'UTF-8');
      $parts[] =
        '<span class="goalie-score-wrap">'
        . '<span class="goalie-meta-label">Start Score:</span> '
        . '<span class="goalie-score" style="--goalie-score: '.$score.';" data-score="'.$scoreEsc.'">'.$scoreEsc.'</span>'
        . '</span>';
    }

    return $nameEsc
      . ' <span class="goalie-meta">['
      . implode(' <span class="goalie-dot">•</span> ', $parts)
      . ']</span>';
  }
}

/* ============================================================
 *  Renderer: horizontal cards (lines/pairs intact)
 * ============================================================ */

if (!function_exists('sjms_player_card_html')) {
  function sjms_player_card_html(PDO $pdo, $gameId, $row, $posLabel) {
    $name = sjms_player_label($row);
    if ($name === '') return '';

    $jn = $row['jersey_number'] ?? null;
    $jnTxt = ($jn !== null && $jn !== '' && is_numeric($jn)) ? ('#' . (int)$jn) : '';

    $pid = isset($row['player_id']) && $row['player_id'] ? (int)$row['player_id'] : 0;

    // Debug caller context
    if (function_exists('sjms_adv_dbg_chips_enabled') && sjms_adv_dbg_chips_enabled()) {
      error_log('[ADVCHIP] caller_card ' . json_encode(array(
        'gid' => (int)$gameId,
        'name' => $name,
        'pid' => $pid,
        'pos' => $posLabel,
        'row_keys' => is_array($row) ? array_keys($row) : array(),
        'first_name' => $row['first_name'] ?? null,
        'last_name' => $row['last_name'] ?? null,
        'jersey' => $jnTxt,
      ), JSON_UNESCAPED_SLASHES));
    }

    // Advanced stats chips are now provided by thread_adv_stats.php
    $chips = '';
    if (function_exists('sjms_adv_chips_html')) {
      // In debug mode, call even if pid==0 so we can see chip-side logs.
      if ($pid > 0 || (function_exists('sjms_adv_dbg_chips_enabled') && sjms_adv_dbg_chips_enabled())) {
        $chips = sjms_adv_chips_html($pdo, (int)$gameId, (int)$pid);
      }
    }

    $metaBits = array();
    if ($jnTxt !== '') $metaBits[] = $jnTxt;
    if ($posLabel !== '') $metaBits[] = $posLabel;

    $meta = $metaBits ? ('<div class="player-card__meta">' . htmlspecialchars(implode(' · ', $metaBits), ENT_QUOTES, 'UTF-8') . '</div>') : '';

    return
      '<div class="player-card"' . ($pid > 0 ? ' data-player-id="'.(int)$pid.'"' : '') . '>'
        . '<div class="player-card__name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>'
        . $meta
        . $chips
      . '</div>';
  }
}

if (!function_exists('sjms_line_card_html')) {
  function sjms_line_card_html($label, $playerCardsHtml) {
    if (!$playerCardsHtml) return '';
    return
      '<div class="line-card">'
        . '<div class="line-card__hdr">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="line-card__players">' . implode('', $playerCardsHtml) . '</div>'
      . '</div>';
  }
}

if (!function_exists('sjms_build_lineups_html')) {
  function sjms_build_lineups_html(PDO $pdo, $gameId, $grouped, $homeAbbr, $awayAbbr, $meta) {
    if (empty($grouped) || !is_array($grouped)) return '';

    $typesWithData = array();
    foreach ($grouped as $type => $teams) {
      if (!empty($teams)) $typesWithData[] = $type;
    }
    if (!$typesWithData) return '';

    $preferredType = null;
    if (in_array('actual', $typesWithData, true)) {
      $preferredType = 'actual';
    } else {
      if (count($typesWithData) > 1 && in_array('expected', $typesWithData, true)) {
        foreach ($typesWithData as $t) {
          if ($t !== 'expected') { $preferredType = $t; break; }
        }
      }
    }
    if ($preferredType === null) $preferredType = $typesWithData[0];

    $grouped = array($preferredType => $grouped[$preferredType]);

    $teamSet = array();
    foreach ($grouped as $type => $teams) {
      foreach ($teams as $abbr => $_rows) $teamSet[$abbr] = true;
    }
    $teams = array_keys($teamSet);
    if (!$teams) return '';

    $homeAbbr = $homeAbbr ?: ($teams[0] ?? null);
    $awayAbbr = $awayAbbr ?: ($teams[1] ?? null);

    if ($homeAbbr === null && $awayAbbr !== null) $homeAbbr = $awayAbbr;
    elseif ($awayAbbr === null && $homeAbbr !== null) $awayAbbr = $homeAbbr;

    $typeLabels = array('expected' => 'Expected lineup', 'actual' => 'Final lineup');
    $asOfLabel = isset($meta['as_of_label']) ? trim((string)$meta['as_of_label']) : '';
    $usedDfo   = !empty($meta['used_dfo']);

    ob_start();
    ?>
    <section class="thread-lineups thread-lineups--cards" aria-label="Lineups">
      <header class="thread-lineups__header">
        <h2>Lineups</h2>

        <?php if ($asOfLabel !== ''): ?>
          <p class="thread-lineups__subtitle"><?= htmlspecialchars($asOfLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($usedDfo): ?>
          <?= sjms_dfo_credit_html() ?>
        <?php endif; ?>
      </header>

      <?php foreach ($grouped as $type => $teamsForType): ?>
        <?php
          if (empty($teamsForType)) continue;
          $typeLabel = $typeLabels[$type] ?? ucfirst($type);
        ?>
        <div class="thread-lineups__row">
          <div class="thread-lineups__row-header">
            <span class="thread-lineups__type-badge thread-lineups__type-badge--<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
          </div>

          <div class="thread-lineups__grid">
            <?php
              $teamOrder = array();
              if ($awayAbbr && isset($teamsForType[$awayAbbr])) $teamOrder[] = $awayAbbr;
              if ($homeAbbr && isset($teamsForType[$homeAbbr]) && $homeAbbr !== $awayAbbr) $teamOrder[] = $homeAbbr;
              foreach ($teamsForType as $abbr => $_rows) {
                if (!in_array($abbr, $teamOrder, true)) $teamOrder[] = $abbr;
              }

              foreach ($teamOrder as $abbr):
                $rows = $teamsForType[$abbr];

                $forwards = array();
                $defense  = array();
                $goalies  = array();
                $extras   = array();

                foreach ($rows as $r) {
                  list($slotKey, $extraLabel) = sjms_map_slot((string)($r['lineup_position'] ?? ''));

                  if ($slotKey === null) {
                    $extras[] = array('label' => $extraLabel, 'row' => $r);
                    continue;
                  }
                  if (strpos($slotKey, 'F_') === 0) $forwards[$slotKey] = $r;
                  elseif (strpos($slotKey, 'D') === 0) $defense[$slotKey] = $r;
                  elseif (strpos($slotKey, 'G') === 0) $goalies[$slotKey] = $r;
                }
            ?>
              <div class="thread-lineups__team">
                <h3 class="thread-lineups__team-name">
                  <?= htmlspecialchars($abbr, ENT_QUOTES, 'UTF-8') ?>
                  <span class="thread-lineups__team-pill">
                    <?= ($abbr === $homeAbbr) ? 'Home' : (($abbr === $awayAbbr) ? 'Away' : 'Team') ?>
                  </span>
                </h3>

                <?php if ($forwards): ?>
                  <div class="thread-lineups__section">
                    <h4 class="thread-lineups__section-title">Forwards</h4>

                    <div class="line-cards line-cards--forwards">
                      <?php for ($line = 1; $line <= 4; $line++): ?>
                        <?php
                          $lwKey = sprintf('F_L%d', $line);
                          $cKey  = sprintf('F_C%d', $line);
                          $rwKey = sprintf('F_R%d', $line);

                          $cards = array();

                          if (isset($forwards[$lwKey])) $cards[] = sjms_player_card_html($pdo, $gameId, $forwards[$lwKey], 'LW');
                          if (isset($forwards[$cKey]))  $cards[] = sjms_player_card_html($pdo, $gameId, $forwards[$cKey],  'C');
                          if (isset($forwards[$rwKey])) $cards[] = sjms_player_card_html($pdo, $gameId, $forwards[$rwKey], 'RW');

                          $cards = array_values(array_filter($cards, function($x){ return trim((string)$x) !== ''; }));

                          if (!$cards) continue;

                          echo sjms_line_card_html('Line ' . $line, $cards);
                        ?>
                      <?php endfor; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($defense): ?>
                  <div class="thread-lineups__section">
                    <h4 class="thread-lineups__section-title">Defense</h4>

                    <div class="line-cards line-cards--defense">
                      <?php for ($pair = 1; $pair <= 3; $pair++): ?>
                        <?php
                          $ldKey = sprintf('D%d_L', $pair);
                          $rdKey = sprintf('D%d_R', $pair);

                          $cards = array();

                          if (isset($defense[$ldKey])) $cards[] = sjms_player_card_html($pdo, $gameId, $defense[$ldKey], 'LD');
                          if (isset($defense[$rdKey])) $cards[] = sjms_player_card_html($pdo, $gameId, $defense[$rdKey], 'RD');

                          $cards = array_values(array_filter($cards, function($x){ return trim((string)$x) !== ''; }));
                          if (!$cards) continue;

                          echo sjms_line_card_html('Pair ' . $pair, $cards);
                        ?>
                      <?php endfor; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($goalies): ?>
                  <div class="thread-lineups__section">
                    <h4 class="thread-lineups__section-title">Goalies</h4>
                    <table class="lineup-table lineup-table--goalies">
                      <thead><tr><th>Slot</th><th>Goalie</th></tr></thead>
                      <tbody>
                        <?php
                          $g1Row = $goalies['G1'] ?? null;
                          $g2Row = $goalies['G2'] ?? null;

                          $g1Html = $g1Row ? sjms_goalie_label_html($g1Row) : '';
                          $g2Html = $g2Row ? sjms_goalie_label_html($g2Row) : '';

                          $g1Cls = $g1Row ? sjms_goalie_status_tr_class($g1Row) : '';
                          $g2Cls = $g2Row ? sjms_goalie_status_tr_class($g2Row) : '';
                        ?>

                        <?php if ($g1Html): ?>
                          <tr class="<?= htmlspecialchars(trim('goalie-row goalie-row--g1 ' . $g1Cls), ENT_QUOTES, 'UTF-8') ?>">
                            <th>G1</th>
                            <td><?= $g1Html ?></td>
                          </tr>
                        <?php endif; ?>

                        <?php if ($g2Html): ?>
                          <tr class="<?= htmlspecialchars(trim('goalie-row goalie-row--g2 ' . $g2Cls), ENT_QUOTES, 'UTF-8') ?>">
                            <th>G2</th>
                            <td><?= $g2Html ?></td>
                          </tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>

                <?php if ($extras): ?>
                  <div class="thread-lineups__section">
                    <h4 class="thread-lineups__section-title">Extras</h4>
                    <ul class="thread-lineups__extras-list">
                      <?php foreach ($extras as $ex): ?>
                        <?php
                          $name  = sjms_player_label($ex['row']);
                          $label = $ex['label'] ? $ex['label'] : 'Slot';
                        ?>
                        <li>
                          <span class="thread-lineups__slot"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>:</span>
                          <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php
    return ob_get_clean();
  }
}

/* ============================================================
 *  Public entry point
 * ============================================================ */

if (!function_exists('sjms_get_lineups_html')) {
  function sjms_get_lineups_html(PDO $pdo, $msfGameId, $homeAbbr = null, $awayAbbr = null) {
    $gid = (int)$msfGameId;
    if ($gid <= 0) return '';

    $teams = array();
    if ($awayAbbr) $teams[] = strtoupper(trim((string)$awayAbbr));
    if ($homeAbbr) $teams[] = strtoupper(trim((string)$homeAbbr));
    $teams = array_values(array_unique(array_filter($teams)));

    $gameDate = sjms_guess_game_date($pdo, $gid);

    $playerIdMap = sjms_build_playerid_map_for_game($pdo, $gid);

    $teamSlugs = array();
    foreach ($teams as $abbr) {
      $slug = sjms_team_abbr_to_slug($abbr);
      if ($slug !== '') $teamSlugs[] = $slug;
    }

    // PRIMARY (DFO)
    if ($gameDate && $teams) {
      $primary = sjms_fetch_lineups_grouped_primary($pdo, $gameDate, $teams, $playerIdMap);
      if (!empty($primary)) {
        $maxScraped = sjms_fetch_latest_dfo_scraped_at_lineups($pdo, $gameDate, $teamSlugs);
        $asOfLabel  = sjms_format_asof_from_utc($maxScraped, $gameDate);

        return sjms_build_lineups_html($pdo, $gid, $primary, $homeAbbr, $awayAbbr, array(
          'as_of_label' => $asOfLabel,
          'used_dfo'    => true,
        ));
      }
    }

    // FALLBACK
    $fallback = sjms_fetch_lineups_grouped_fallback($pdo, $gid);
    if (empty($fallback)) return '';

    $asOfLabel = ($gameDate ? ('As of ' . $gameDate) : '');

    return sjms_build_lineups_html($pdo, $gid, $fallback, $homeAbbr, $awayAbbr, array(
      'as_of_label' => $asOfLabel,
      'used_dfo'    => false,
    ));
  }
}
