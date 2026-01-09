<?php
// public/scripts/cron_build_player_adv_stats.php
//
// Build per-player advanced stats into nhl_players_advanced_stats.
//
// Default (cron):
//   - process ONE day: today (America/New_York) minus 5 days
//   - build ALL slices: 5v5, pp, sh, ev, all
//
// CLI examples:
//   php cron_build_player_adv_stats.php
//   php cron_build_player_adv_stats.php --days-ago=3
//   php cron_build_player_adv_stats.php --date=2025-12-16
//   php cron_build_player_adv_stats.php --from=2025-12-10 --to=2025-12-16
//   php cron_build_player_adv_stats.php --game=151380
//   php cron_build_player_adv_stats.php --game=20251216-CGY-SJS
//   php cron_build_player_adv_stats.php --state=5v5
//   php cron_build_player_adv_stats.php --states=5v5,pp,sh,ev,all
//   php cron_build_player_adv_stats.php --calc-version=v1
//   php cron_build_player_adv_stats.php --dry-run
//   php cron_build_player_adv_stats.php --verbose
//
// Notes:
// - msf_games uses msf_game_id (NOT game_id). That msf_game_id matches msf_live_game.game_id.
// - This script reads games from msf_games, then builds stats from pbp/lineups/gamelogs.
// - IMPORTANT: If you only build one state_key (e.g., 5v5), then other filters (pp/sh/ev/all)
//   will return empty. This script now loops and builds multiple state slices per game.

ini_set('display_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';
require_once $root . '/public/includes/msf_pbp_helpers.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  fwrite(STDERR, "ERROR: PDO \$pdo not found. Check config/db.php\n");
  exit(2);
}

/* ---------------------------
   Args / utils
--------------------------- */

function cpas_arg_map(array $argv) {
  $out = [];
  foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (substr($a, 0, 2) !== '--') continue;
    $eq = strpos($a, '=');
    if ($eq === false) $out[substr($a, 2)] = true;
    else {
      $k = substr($a, 2, $eq - 2);
      $v = substr($a, $eq + 1);
      $out[$k] = $v;
    }
  }
  return $out;
}

function cpas_is_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

function cpas_date_range_inclusive($from, $to) {
  $out = [];
  $d1 = new DateTime($from);
  $d2 = new DateTime($to);
  if ($d2 < $d1) return $out;
  for ($d = clone $d1; $d <= $d2; $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
  return $out;
}

function cpas_as_int($v, $d = 0) { return is_numeric($v) ? (int)$v : (int)$d; }

function cpas_as_dec($v, $dec = 3) {
  if ($v === null || $v === '' || !is_numeric($v)) return null;
  return round((float)$v, $dec);
}

function cpas_as_rate($v, $dec = 2) {
  if ($v === null || $v === '' || !is_numeric($v)) return null;
  return round((float)$v, $dec);
}

function cpas_norm_state($s) {
  $s = strtolower(trim((string)$s));
  $allowed = ['5v5','ev','all','pp','sh'];
  return in_array($s, $allowed, true) ? $s : '5v5';
}

function cpas_parse_states(array $args) {
  // --states=5v5,pp,sh,ev,all
  if (!empty($args['states'])) {
    $raw = explode(',', (string)$args['states']);
    $out = [];
    foreach ($raw as $s) {
      $s = cpas_norm_state($s);
      if (!in_array($s, $out, true)) $out[] = $s;
    }
    return $out ?: ['5v5'];
  }

  // --state=... (single slice)
  if (!empty($args['state'])) {
    return [ cpas_norm_state((string)$args['state']) ];
  }

  // Default: build everything
  return ['5v5','pp','sh','ev','all'];
}

$args = cpas_arg_map($argv);
$tz = new DateTimeZone('America/New_York');

$statesToBuild = cpas_parse_states($args);

$daysAgo = isset($args['days-ago']) ? (int)$args['days-ago'] : 5;
if ($daysAgo < 0) $daysAgo = 5;

$dryRun  = !empty($args['dry-run']);
$verbose = !empty($args['verbose']);

$calcVersion = isset($args['calc-version']) ? trim((string)$args['calc-version']) : 'v1';
if ($calcVersion === '') $calcVersion = 'v1';

/* ---------------------------
   Game selection (msf_games.msf_game_id!)
--------------------------- */

function cpas_resolve_game_id(PDO $pdo, string $gameArg, bool $verbose = false): ?int {
  $gameArg = trim($gameArg);
  if ($gameArg === '') return null;

  // Numeric msf_game_id
  if (preg_match('/^\d+$/', $gameArg)) return (int)$gameArg;

  // game_code like 20251216-CGY-SJS
  try {
    $st = $pdo->prepare("SELECT msf_game_id FROM msf_games WHERE game_code = :c LIMIT 1");
    $st->execute([':c' => $gameArg]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $gid = (int)($r['msf_game_id'] ?? 0);
    if ($gid > 0) return $gid;
  } catch (Exception $e) {
    if ($verbose) fwrite(STDERR, "resolveGameId error: " . $e->getMessage() . "\n");
  }

  return null;
}

function cpas_game_ids_for_date(PDO $pdo, string $date, bool $verbose = false): array {
  $ids = [];

  // Prefer msf_games.game_date
  try {
    $st = $pdo->prepare("SELECT msf_game_id FROM msf_games WHERE game_date = :d");
    $st->execute([':d' => $date]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $gid = (int)($r['msf_game_id'] ?? 0);
      if ($gid > 0) $ids[] = $gid;
    }
    return $ids;
  } catch (Exception $e) {
    if ($verbose) fwrite(STDERR, "game_ids_for_date(game_date) error: " . $e->getMessage() . "\n");
  }

  // Fallback: start_date (if your msf_games has it)
  try {
    $st = $pdo->prepare("SELECT msf_game_id FROM msf_games WHERE start_date = :d");
    $st->execute([':d' => $date]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $gid = (int)($r['msf_game_id'] ?? 0);
      if ($gid > 0) $ids[] = $gid;
    }
  } catch (Exception $e2) {
    if ($verbose) fwrite(STDERR, "game_ids_for_date(start_date) error: " . $e2->getMessage() . "\n");
  }

  return $ids;
}

function cpas_game_meta(PDO $pdo, int $msfGameId, bool $verbose = false): array {
  $meta = ['game_date' => null, 'season' => null, 'game_code' => null];

  // msf_games is canonical for date/season
  try {
    $st = $pdo->prepare("SELECT game_date, season, game_code FROM msf_games WHERE msf_game_id = :gid LIMIT 1");
    $st->execute([':gid' => $msfGameId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      if (!empty($r['game_date'])) $meta['game_date'] = (string)$r['game_date'];
      if (!empty($r['season']))    $meta['season']    = (string)$r['season'];
      if (!empty($r['game_code'])) $meta['game_code'] = (string)$r['game_code'];
      return $meta;
    }
  } catch (Exception $e) {
    if ($verbose) fwrite(STDERR, "game_meta(msf_games) error: " . $e->getMessage() . "\n");
  }

  // Optional fallback
  try {
    $st = $pdo->prepare("SELECT game_date, season FROM msf_live_game WHERE game_id = :gid LIMIT 1");
    $st->execute([':gid' => $msfGameId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      if (!empty($r['game_date'])) $meta['game_date'] = (string)$r['game_date'];
      if (!empty($r['season']))    $meta['season']    = (string)$r['season'];
    }
  } catch (Exception $e) {}

  return $meta;
}

/* ---------------------------
   Player selection helpers
--------------------------- */

function cpas_goalie_id_map(PDO $pdo, int $gameId): array {
  $ids = [];
  try {
    $st = $pdo->prepare("
      SELECT player_id, lineup_position, player_position
      FROM lineups
      WHERE game_id = :gid
        AND player_id IS NOT NULL
    ");
    $st->execute([':gid' => $gameId]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;
      $lp = (string)($r['lineup_position'] ?? '');
      $pp = strtoupper(trim((string)($r['player_position'] ?? '')));
      if (strpos($lp, 'Goalie') === 0 || $pp === 'G') $ids[$pid] = true;
    }
  } catch (Exception $e) {}
  return $ids;
}

function cpas_skaters_for_game(PDO $pdo, int $gameId, array $goalies): array {
  $out = [];
  try {
    $st = $pdo->prepare("
      SELECT DISTINCT player_id, team_abbr, player_position
      FROM lineups
      WHERE game_id = :gid
        AND player_id IS NOT NULL
    ");
    $st->execute([':gid' => $gameId]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $pid = (int)($r['player_id'] ?? 0);
      if ($pid <= 0) continue;
      if (!empty($goalies[$pid])) continue;

      $out[] = [
        'player_id' => $pid,
        'team_abbr' => strtoupper(trim((string)($r['team_abbr'] ?? ''))),
        'player_pos'=> strtoupper(trim((string)($r['player_position'] ?? ''))),
      ];
    }
  } catch (Exception $e) {}
  return $out;
}

/* ---------------------------
   UPSERT (your table)
--------------------------- */

$sqlUpsert = "
INSERT INTO nhl_players_advanced_stats (
  game_id, player_id, team_abbr, player_pos, state_key, strength,
  calc_version, calc_ts,
  game_date, season,
  toi_total, toi_ev, toi_pp, toi_sh, toi_used,

  CF, CA, FF, FA, SF, SA, GF, GA,
  SCF, SCA, HDCF, HDCA, MDCF, MDCA, LDCF, LDCA,
  xGF, xGA,

  CF_pct, FF_pct, SF_pct, xGF_pct,

  CF_60, CA_60, FF_60, FA_60, SF_60, SA_60, GF_60, GA_60,
  SCF_60, SCA_60, HDCF_60, HDCA_60, MDCF_60, MDCA_60, LDCF_60, LDCA_60,
  xGF_60, xGA_60,

  CDIFF, FDIFF, SDIFF, GDIFF, SCDIFF, HCDIFF, MCDIFF, LCDIFF, xGDIFF,
  CDIFF_60, FDIFF_60, SDIFF_60, GDIFF_60, SCDIFF_60, HCDIFF_60, MCDIFF_60, LCDIFF_60, xGDIFF_60,

  iCF, iFF, iSF, iG, iSC, iHDC, iMDC, iLDC, ixG,
  iCF_60, iFF_60, iSF_60, iG_60, iSC_60, iHDC_60, iMDC_60, iLDC_60, ixG_60,

  pen_taken, pen_drawn, pen_diff,
  gar_pen, gar_corsi, gar_goals, gar_total, war_total
) VALUES (
  :game_id, :player_id, :team_abbr, :player_pos, :state_key, :strength,
  :calc_version, NOW(),
  :game_date, :season,
  :toi_total, :toi_ev, :toi_pp, :toi_sh, :toi_used,

  :CF, :CA, :FF, :FA, :SF, :SA, :GF, :GA,
  :SCF, :SCA, :HDCF, :HDCA, :MDCF, :MDCA, :LDCF, :LDCA,
  :xGF, :xGA,

  :CF_pct, :FF_pct, :SF_pct, :xGF_pct,

  :CF_60, :CA_60, :FF_60, :FA_60, :SF_60, :SA_60, :GF_60, :GA_60,
  :SCF_60, :SCA_60, :HDCF_60, :HDCA_60, :MDCF_60, :MDCA_60, :LDCF_60, :LDCA_60,
  :xGF_60, :xGA_60,

  :CDIFF, :FDIFF, :SDIFF, :GDIFF, :SCDIFF, :HCDIFF, :MCDIFF, :LCDIFF, :xGDIFF,
  :CDIFF_60, :FDIFF_60, :SDIFF_60, :GDIFF_60, :SCDIFF_60, :HCDIFF_60, :MCDIFF_60, :LCDIFF_60, :xGDIFF_60,

  :iCF, :iFF, :iSF, :iG, :iSC, :iHDC, :iMDC, :iLDC, :ixG,
  :iCF_60, :iFF_60, :iSF_60, :iG_60, :iSC_60, :iHDC_60, :iMDC_60, :iLDC_60, :ixG_60,

  :pen_taken, :pen_drawn, :pen_diff,
  :gar_pen, :gar_corsi, :gar_goals, :gar_total, :war_total
)
ON DUPLICATE KEY UPDATE
  team_abbr = VALUES(team_abbr),
  player_pos = VALUES(player_pos),
  strength = VALUES(strength),
  calc_version = VALUES(calc_version),
  calc_ts = NOW(),
  game_date = VALUES(game_date),
  season = VALUES(season),

  toi_total = VALUES(toi_total),
  toi_ev    = VALUES(toi_ev),
  toi_pp    = VALUES(toi_pp),
  toi_sh    = VALUES(toi_sh),
  toi_used  = VALUES(toi_used),

  CF = VALUES(CF), CA = VALUES(CA),
  FF = VALUES(FF), FA = VALUES(FA),
  SF = VALUES(SF), SA = VALUES(SA),
  GF = VALUES(GF), GA = VALUES(GA),

  SCF = VALUES(SCF), SCA = VALUES(SCA),
  HDCF = VALUES(HDCF), HDCA = VALUES(HDCA),
  MDCF = VALUES(MDCF), MDCA = VALUES(MDCA),
  LDCF = VALUES(LDCF), LDCA = VALUES(LDCA),

  xGF = VALUES(xGF), xGA = VALUES(xGA),

  CF_pct = VALUES(CF_pct),
  FF_pct = VALUES(FF_pct),
  SF_pct = VALUES(SF_pct),
  xGF_pct = VALUES(xGF_pct),

  CF_60 = VALUES(CF_60), CA_60 = VALUES(CA_60),
  FF_60 = VALUES(FF_60), FA_60 = VALUES(FA_60),
  SF_60 = VALUES(SF_60), SA_60 = VALUES(SA_60),
  GF_60 = VALUES(GF_60), GA_60 = VALUES(GA_60),

  SCF_60 = VALUES(SCF_60), SCA_60 = VALUES(SCA_60),
  HDCF_60 = VALUES(HDCF_60), HDCA_60 = VALUES(HDCA_60),
  MDCF_60 = VALUES(MDCF_60), MDCA_60 = VALUES(MDCA_60),
  LDCF_60 = VALUES(LDCF_60), LDCA_60 = VALUES(LDCA_60),

  xGF_60 = VALUES(xGF_60), xGA_60 = VALUES(xGA_60),

  CDIFF = VALUES(CDIFF),
  FDIFF = VALUES(FDIFF),
  SDIFF = VALUES(SDIFF),
  GDIFF = VALUES(GDIFF),
  SCDIFF = VALUES(SCDIFF),
  HCDIFF = VALUES(HCDIFF),
  MCDIFF = VALUES(MCDIFF),
  LCDIFF = VALUES(LCDIFF),
  xGDIFF = VALUES(xGDIFF),

  CDIFF_60 = VALUES(CDIFF_60),
  FDIFF_60 = VALUES(FDIFF_60),
  SDIFF_60 = VALUES(SDIFF_60),
  GDIFF_60 = VALUES(GDIFF_60),
  SCDIFF_60 = VALUES(SCDIFF_60),
  HCDIFF_60 = VALUES(HCDIFF_60),
  MCDIFF_60 = VALUES(MCDIFF_60),
  LCDIFF_60 = VALUES(LCDIFF_60),
  xGDIFF_60 = VALUES(xGDIFF_60),

  iCF = VALUES(iCF), iFF = VALUES(iFF), iSF = VALUES(iSF), iG = VALUES(iG),
  iSC = VALUES(iSC), iHDC = VALUES(iHDC), iMDC = VALUES(iMDC), iLDC = VALUES(iLDC),
  ixG = VALUES(ixG),

  iCF_60 = VALUES(iCF_60), iFF_60 = VALUES(iFF_60), iSF_60 = VALUES(iSF_60),
  iG_60 = VALUES(iG_60), iSC_60 = VALUES(iSC_60), iHDC_60 = VALUES(iHDC_60),
  iMDC_60 = VALUES(iMDC_60), iLDC_60 = VALUES(iLDC_60), ixG_60 = VALUES(ixG_60),

  pen_taken = VALUES(pen_taken),
  pen_drawn = VALUES(pen_drawn),
  pen_diff  = VALUES(pen_diff),

  gar_pen   = VALUES(gar_pen),
  gar_corsi = VALUES(gar_corsi),
  gar_goals = VALUES(gar_goals),
  gar_total = VALUES(gar_total),
  war_total = VALUES(war_total)
";

$stUpsert = null;
if (!$dryRun) $stUpsert = $pdo->prepare($sqlUpsert);

function cpas_upsert_row(?PDOStatement $st, array $params, bool $dryRun) {
  if ($dryRun) {
    echo "DRY upsert game={$params[':game_id']} player={$params[':player_id']} state={$params[':state_key']}\n";
    return true;
  }
  return $st->execute($params);
}

/* ---------------------------
   Core builder for one game + one state slice
--------------------------- */

function cpas_build_game(PDO $pdo, ?PDOStatement $stUpsert, int $gameId, string $stateKey, string $calcVersion, bool $dryRun, bool $verbose) {
  $stateKey = cpas_norm_state($stateKey);

  if (!function_exists('msf_pbp_get_game_row')) {
    echo "SKIP game {$gameId}: msf_pbp_get_game_row missing\n";
    return;
  }

  $gRow = msf_pbp_get_game_row($pdo, $gameId);
  if (!$gRow) {
    echo "SKIP game {$gameId}: no msf_live_game row\n";
    return;
  }

  $meta = cpas_game_meta($pdo, $gameId, $verbose);

  $goalies = cpas_goalie_id_map($pdo, $gameId);
  $skaters = cpas_skaters_for_game($pdo, $gameId, $goalies);
  if (!$skaters) {
    echo "SKIP game {$gameId}: no skaters from lineups\n";
    return;
  }

  $toiMap = function_exists('msf_player_gamelogs_get_toi_map_seconds')
    ? msf_player_gamelogs_get_toi_map_seconds($pdo, $gameId)
    : [];

  $ok = 0;

  foreach ($skaters as $p) {
    $pid = (int)$p['player_id'];
    if ($pid <= 0) continue;

    // TOI buckets from gamelogs
    $toiArr = $toiMap[$pid] ?? (function_exists('msf_player_gamelogs_get_toi_seconds') ? msf_player_gamelogs_get_toi_seconds($pdo, $gameId, $pid) : null);
    $toi_total = is_array($toiArr) ? (int)($toiArr['toi_total'] ?? 0) : 0;
    $toi_ev    = is_array($toiArr) ? (int)($toiArr['toi_ev'] ?? 0) : 0;
    $toi_pp    = is_array($toiArr) ? (int)($toiArr['toi_pp'] ?? 0) : 0;
    $toi_sh    = is_array($toiArr) ? (int)($toiArr['toi_sh'] ?? 0) : 0;

    // Denominator: for 5v5 we want EV bucket (picker maps 5v5->ev)
    $toi_used = 0;
    if (function_exists('msf_boxscore_pick_toi_for_filter')) {
      $toi_used = (int)msf_boxscore_pick_toi_for_filter($toiArr, $stateKey, 0);
    }
    if ($toi_used <= 0) $toi_used = ($toi_ev > 0 ? $toi_ev : $toi_total);

    // On-ice summary
    $on = msf_pbp_get_player_onice_summary($pdo, $gameId, $pid, [
      'state_key' => $stateKey,
      'include_blocked_sc' => true,
      'include_blocked_xg' => false,
    ]);
    if (!is_array($on)) continue;

    // Rates & derived diffs
    $rates = function_exists('msf_pbp_apply_onice_rates') ? msf_pbp_apply_onice_rates($on, $toi_used) : $on;

    // Individual summary
    $ind = function_exists('msf_pbp_get_player_individual_summary')
      ? msf_pbp_get_player_individual_summary($pdo, $gameId, $pid, [
          'state_key' => $stateKey,
          'include_blocked_sc' => true,
          'include_blocked_xg' => false,
        ])
      : null;

    if (is_array($ind) && function_exists('msf_pbp_apply_individual_rates')) {
      $ind = msf_pbp_apply_individual_rates($ind, $toi_used);
    }

    // GAR-lite
    $pen_taken = 0; $pen_drawn = 0; $pen_diff = 0;
    $gar_pen = 0.0; $gar_corsi = 0.0; $gar_goals = 0.0; $gar_total = 0.0;
    $war_total = null;

    if (function_exists('msf_pbp_get_player_gar_lite')) {
      $gar = msf_pbp_get_player_gar_lite($pdo, $gameId, $pid, $stateKey, false, [
        'include_corsi'     => true,
        'goals_per_penalty' => 0.15,
        'goals_per_corsi'   => 0.01,
      ]);
      if (is_array($gar)) {
        $pen_taken = (int)($gar['pen_taken'] ?? 0);
        $pen_drawn = (int)($gar['pen_drawn'] ?? 0);
        $pen_diff  = (int)($gar['pen_diff']  ?? 0);

        $gar_pen   = (float)($gar['gar_pen']   ?? 0.0);
        $gar_corsi = (float)($gar['gar_corsi'] ?? 0.0);
        $gar_goals = (float)($gar['gar_goals'] ?? 0.0);
        $gar_total = (float)($gar['gar_total'] ?? 0.0);

        if (function_exists('msf_pbp_gar_to_war')) {
          $w = msf_pbp_gar_to_war($gar_total, 6.0);
          $war_total = is_numeric($w) ? (float)$w : null;
        }
      }
    }

    $params = [
      ':game_id' => $gameId,
      ':player_id' => $pid,
      ':team_abbr' => $p['team_abbr'] ?: '',
      ':player_pos'=> ($p['player_pos'] !== '' ? $p['player_pos'] : null),
      ':state_key' => $stateKey,
      ':strength'  => null,
      ':calc_version' => $calcVersion,
      ':game_date' => $meta['game_date'],
      ':season'    => $meta['season'],

      ':toi_total' => $toi_total,
      ':toi_ev'    => $toi_ev,
      ':toi_pp'    => $toi_pp,
      ':toi_sh'    => $toi_sh,
      ':toi_used'  => $toi_used,

      ':CF' => cpas_as_int($rates['CF'] ?? 0),
      ':CA' => cpas_as_int($rates['CA'] ?? 0),
      ':FF' => cpas_as_int($rates['FF'] ?? 0),
      ':FA' => cpas_as_int($rates['FA'] ?? 0),
      ':SF' => cpas_as_int($rates['SF'] ?? 0),
      ':SA' => cpas_as_int($rates['SA'] ?? 0),
      ':GF' => cpas_as_int($rates['GF'] ?? 0),
      ':GA' => cpas_as_int($rates['GA'] ?? 0),

      ':SCF'  => cpas_as_int($rates['SCF']  ?? 0),
      ':SCA'  => cpas_as_int($rates['SCA']  ?? 0),
      ':HDCF' => cpas_as_int($rates['HDCF'] ?? 0),
      ':HDCA' => cpas_as_int($rates['HDCA'] ?? 0),
      ':MDCF' => cpas_as_int($rates['MDCF'] ?? 0),
      ':MDCA' => cpas_as_int($rates['MDCA'] ?? 0),
      ':LDCF' => cpas_as_int($rates['LDCF'] ?? 0),
      ':LDCA' => cpas_as_int($rates['LDCA'] ?? 0),

      ':xGF' => cpas_as_dec($rates['xGF'] ?? 0.0, 3) ?? 0.000,
      ':xGA' => cpas_as_dec($rates['xGA'] ?? 0.0, 3) ?? 0.000,

      ':CF_pct'  => cpas_as_dec($rates['CF_pct']  ?? null, 1),
      ':FF_pct'  => cpas_as_dec($rates['FF_pct']  ?? null, 1),
      ':SF_pct'  => cpas_as_dec($rates['SF_pct']  ?? null, 1),
      ':xGF_pct' => cpas_as_dec($rates['xGF_pct'] ?? null, 1),

      ':CF_60' => cpas_as_rate($rates['CF_60'] ?? null, 2),
      ':CA_60' => cpas_as_rate($rates['CA_60'] ?? null, 2),
      ':FF_60' => cpas_as_rate($rates['FF_60'] ?? null, 2),
      ':FA_60' => cpas_as_rate($rates['FA_60'] ?? null, 2),
      ':SF_60' => cpas_as_rate($rates['SF_60'] ?? null, 2),
      ':SA_60' => cpas_as_rate($rates['SA_60'] ?? null, 2),
      ':GF_60' => cpas_as_rate($rates['GF_60'] ?? null, 2),
      ':GA_60' => cpas_as_rate($rates['GA_60'] ?? null, 2),

      ':SCF_60'  => cpas_as_rate($rates['SCF_60']  ?? null, 2),
      ':SCA_60'  => cpas_as_rate($rates['SCA_60']  ?? null, 2),
      ':HDCF_60' => cpas_as_rate($rates['HDCF_60'] ?? null, 2),
      ':HDCA_60' => cpas_as_rate($rates['HDCA_60'] ?? null, 2),
      ':MDCF_60' => cpas_as_rate($rates['MDCF_60'] ?? null, 2),
      ':MDCA_60' => cpas_as_rate($rates['MDCA_60'] ?? null, 2),
      ':LDCF_60' => cpas_as_rate($rates['LDCF_60'] ?? null, 2),
      ':LDCA_60' => cpas_as_rate($rates['LDCA_60'] ?? null, 2),

      ':xGF_60' => cpas_as_rate($rates['xGF_60'] ?? null, 2),
      ':xGA_60' => cpas_as_rate($rates['xGA_60'] ?? null, 2),

      ':CDIFF'  => cpas_as_int($rates['CDIFF']  ?? 0),
      ':FDIFF'  => cpas_as_int($rates['FDIFF']  ?? 0),
      ':SDIFF'  => cpas_as_int($rates['SDIFF']  ?? 0),
      ':GDIFF'  => cpas_as_int($rates['GDIFF']  ?? 0),
      ':SCDIFF' => cpas_as_int($rates['SCDIFF'] ?? 0),
      ':HCDIFF' => cpas_as_int($rates['HCDIFF'] ?? 0),
      ':MCDIFF' => cpas_as_int($rates['MCDIFF'] ?? 0),
      ':LCDIFF' => cpas_as_int($rates['LCDIFF'] ?? 0),
      ':xGDIFF' => cpas_as_dec($rates['xGDIFF'] ?? 0.0, 3) ?? 0.000,

      ':CDIFF_60'  => cpas_as_rate($rates['CDIFF_60']  ?? null, 2),
      ':FDIFF_60'  => cpas_as_rate($rates['FDIFF_60']  ?? null, 2),
      ':SDIFF_60'  => cpas_as_rate($rates['SDIFF_60']  ?? null, 2),
      ':GDIFF_60'  => cpas_as_rate($rates['GDIFF_60']  ?? null, 2),
      ':SCDIFF_60' => cpas_as_rate($rates['SCDIFF_60'] ?? null, 2),
      ':HCDIFF_60' => cpas_as_rate($rates['HCDIFF_60'] ?? null, 2),
      ':MCDIFF_60' => cpas_as_rate($rates['MCDIFF_60'] ?? null, 2),
      ':LCDIFF_60' => cpas_as_rate($rates['LCDIFF_60'] ?? null, 2),
      ':xGDIFF_60' => cpas_as_rate($rates['xGDIFF_60'] ?? null, 2),

      ':iCF'  => cpas_as_int($ind['iCF']  ?? 0),
      ':iFF'  => cpas_as_int($ind['iFF']  ?? 0),
      ':iSF'  => cpas_as_int($ind['iSF']  ?? 0),
      ':iG'   => cpas_as_int($ind['iG']   ?? 0),
      ':iSC'  => cpas_as_int($ind['iSC']  ?? 0),
      ':iHDC' => cpas_as_int($ind['iHDC'] ?? 0),
      ':iMDC' => cpas_as_int($ind['iMDC'] ?? 0),
      ':iLDC' => cpas_as_int($ind['iLDC'] ?? 0),
      ':ixG'  => cpas_as_dec($ind['ixG']  ?? 0.0, 3) ?? 0.000,

      ':iCF_60'  => cpas_as_rate($ind['iCF_60']  ?? null, 2),
      ':iFF_60'  => cpas_as_rate($ind['iFF_60']  ?? null, 2),
      ':iSF_60'  => cpas_as_rate($ind['iSF_60']  ?? null, 2),
      ':iG_60'   => cpas_as_rate($ind['iG_60']   ?? null, 2),
      ':iSC_60'  => cpas_as_rate($ind['iSC_60']  ?? null, 2),
      ':iHDC_60' => cpas_as_rate($ind['iHDC_60'] ?? null, 2),
      ':iMDC_60' => cpas_as_rate($ind['iMDC_60'] ?? null, 2),
      ':iLDC_60' => cpas_as_rate($ind['iLDC_60'] ?? null, 2),
      ':ixG_60'  => cpas_as_rate($ind['ixG_60']  ?? null, 2),

      ':pen_taken' => $pen_taken,
      ':pen_drawn' => $pen_drawn,
      ':pen_diff'  => $pen_diff,

      ':gar_pen'   => cpas_as_dec($gar_pen, 3) ?? 0.000,
      ':gar_corsi' => cpas_as_dec($gar_corsi, 3) ?? 0.000,
      ':gar_goals' => cpas_as_dec($gar_goals, 3) ?? 0.000,
      ':gar_total' => cpas_as_dec($gar_total, 3) ?? 0.000,
      ':war_total' => ($war_total === null ? null : cpas_as_dec($war_total, 3)),
    ];

    cpas_upsert_row($stUpsert, $params, $dryRun);
    $ok++;
  }

  $extra = $meta['game_code'] ? " ({$meta['game_code']})" : "";
  echo "OK game {$gameId}{$extra}: stored {$ok} skaters (state={$stateKey})\n";
}

/* ---------------------------
   Run
--------------------------- */

$gameOnly = null;
$dates = [];

if (!empty($args['game'])) {
  $gameOnly = cpas_resolve_game_id($pdo, (string)$args['game'], $verbose);
  if (!$gameOnly) {
    fwrite(STDERR, "ERROR: could not resolve --game={$args['game']}\n");
    exit(2);
  }
} elseif (!empty($args['date']) && cpas_is_date($args['date'])) {
  $dates = [ $args['date'] ];
} elseif (!empty($args['from']) && !empty($args['to']) && cpas_is_date($args['from']) && cpas_is_date($args['to'])) {
  $dates = cpas_date_range_inclusive($args['from'], $args['to']);
} else {
  $d = new DateTime('now', $tz);
  $d->modify('-' . $daysAgo . ' days');
  $dates = [ $d->format('Y-m-d') ];
}

if ($verbose) {
  echo "STATES: " . implode(',', $statesToBuild) . "\n";
}

if ($gameOnly) {
  foreach ($statesToBuild as $stateKey) {
    cpas_build_game($pdo, $stUpsert, (int)$gameOnly, (string)$stateKey, $calcVersion, $dryRun, $verbose);
  }
  exit(0);
}

foreach ($dates as $date) {
  $gids = cpas_game_ids_for_date($pdo, $date, $verbose);
  echo "DATE {$date}: games=" . count($gids) . "\n";
  foreach ($gids as $gid) {
    foreach ($statesToBuild as $stateKey) {
      cpas_build_game($pdo, $stUpsert, (int)$gid, (string)$stateKey, $calcVersion, $dryRun, $verbose);
    }
  }
}

echo "DONE\n";
