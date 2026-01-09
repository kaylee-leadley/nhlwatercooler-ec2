<?php
// public/scripts/cron_import_dailyfaceoff_lines.php
// FILE MODE – DailyFaceoff blocks DC IPs
// Batch importer: import ALL snapshot HTML files in a date folder.
//
// REQUIREMENTS:
// - Forwards: exactly lines 1..4 (12 players max; chunked 3 per line: LW/C/RW)
// - Defense:  exactly pairs 1..3 (6 players max; chunked 2 per pair: LD/RD)
// - Goalies:  up to 2 (G1/G2)
// - Injuries: capture all injury tiles under Injuries header
// - player_id is NOT used (always NULL); player_name is primary.
//
// IMPORTANT FIX:
// - slot_no MUST be set for every inserted row.
//   Otherwise all injuries collide on (section='I', line_no=0, position_code='NA', slot_no=0)
//   and ON DUPLICATE KEY UPDATE collapses them into 1 row.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') {
  exit("CLI only\n");
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';

$tz = new DateTimeZone('America/New_York');

/* ---------------- CLI ---------------- */

function starts_with($s, $prefix) {
  return strncmp($s, $prefix, strlen($prefix)) === 0;
}

function norm_space($s) {
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim($s);
}

$date  = (new DateTime('today', $tz))->format('Y-m-d');
$dir   = null;
$teams = []; // optional filter list (slugs), e.g. "detroit-red-wings"

foreach (array_slice($argv, 1) as $arg) {
  if (starts_with($arg, '--date=')) {
    $date = substr($arg, 7);
  } elseif (starts_with($arg, '--dir=')) {
    $dir = substr($arg, 6);
  } else {
    $teams[] = strtolower(trim($arg));
  }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  exit("Invalid --date. Use YYYY-MM-DD.\n");
}

if (!$dir) {
  // /var/www/nhlwatercooler/dailyfaceoff_snapshots/YYYY-MM-DD/*.html
  $dir = $root . "/dailyfaceoff_snapshots/$date";
}

if (!is_dir($dir)) {
  exit("Snapshot dir not found: $dir\n");
}

/* ---------------- parse helpers ---------------- */

function parse_jersey_from_img($imgEl) {
  if (!$imgEl instanceof DOMElement) return null;
  $src = $imgEl->getAttribute('src');
  $srcset = $imgEl->getAttribute('srcset');

  // DailyFaceoff often uses TEAM_##_... patterns
  if ($src && preg_match('/\/[A-Z]{2,3}_(\d{1,3})_/', $src, $m)) return (int)$m[1];
  if ($srcset && preg_match('/\/[A-Z]{2,3}_(\d{1,3})_/', $srcset, $m)) return (int)$m[1];

  // fallback: any _##_ in src
  if ($src && preg_match('/_(\d{1,3})_/', $src, $m)) return (int)$m[1];
  if ($srcset && preg_match('/_(\d{1,3})_/', $srcset, $m)) return (int)$m[1];

  return null;
}

function injury_badge_text(DOMXPath $xp, DOMNode $tile) {
  $nodes = $xp->query('.//span[contains(@class,"bg-red") and contains(@class,"text-white")]', $tile);
  if ($nodes && $nodes->length) {
    $t = norm_space($nodes->item(0)->textContent);
    return $t !== '' ? strtoupper($t) : null;
  }
  return null;
}

/**
 * Extract player tiles between two header nodes using XPath-only traversal.
 * NOTE: We DO NOT parse/store player_id. player_id is always NULL.
 */
function extract_player_tiles_between(DOMXPath $xp, DOMNode $root, ?DOMNode $start, ?DOMNode $end): array {
  $anchors = $xp->query('.//a[starts-with(@href,"/players/news/")]', $root);
  if (!$anchors) return [];

  $tiles = [];
  $seen  = []; // name-based

  foreach ($anchors as $a) {
    if (!$a instanceof DOMElement) continue;

    // anchor must be AFTER start
    if ($start) {
      $pre = $xp->query('preceding::*', $a);
      $found = false;
      if ($pre) {
        foreach ($pre as $pn) {
          if ($pn === $start) { $found = true; break; }
        }
      }
      if (!$found) continue;
    }

    // anchor must be BEFORE end
    if ($end) {
      $fol = $xp->query('following::*', $a);
      $found = false;
      if ($fol) {
        foreach ($fol as $fn) {
          if ($fn === $end) { $found = true; break; }
        }
      }
      if (!$found) continue;
    }

    $nameSpan = $xp->query('.//span[contains(@class,"uppercase")]', $a)->item(0);
    $name = $nameSpan ? norm_space($nameSpan->textContent) : norm_space($a->textContent);
    if ($name === '') continue;

    // climb to a reasonable tile root (find an img)
    $tileRoot = $a->parentNode;
    for ($i=0; $i<7 && $tileRoot; $i++) {
      if ($tileRoot instanceof DOMElement) {
        $imgTest = $xp->query('.//img', $tileRoot);
        if ($imgTest && $imgTest->length) break;
      }
      $tileRoot = $tileRoot->parentNode;
    }
    if (!$tileRoot) $tileRoot = $a;

    $img0 = $xp->query('.//img', $tileRoot)->item(0);
    $jersey = parse_jersey_from_img($img0);

    $injStatus = injury_badge_text($xp, $tileRoot);
    $isInj = $injStatus ? 1 : 0;

    $key = mb_strtolower($name, 'UTF-8');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $tiles[] = [
      'player_name'   => $name,
      'player_id'     => null,
      'jersey_number' => $jersey,
      'injury_status' => $injStatus,
      'is_injured'    => $isInj,
    ];
  }

  return $tiles;
}

/**
 * Parse a DFO team HTML snapshot to normalized DB rows.
 * ENSURES slot_no is always set (critical for uniqueness).
 */
function parse_df_html_to_rows(string $html, string $teamSlug): array {
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
  $xp = new DOMXPath($dom);

  $lineCombos = $xp->query('//*[@id="line_combos"]')->item(0);
  if (!$lineCombos) {
    throw new RuntimeException("Could not find #line_combos in HTML.");
  }

  $rows = [];

  // section headers (ids vary slightly)
  $forwardsHeader = $xp->query('.//span[@id="forwards"]', $lineCombos)->item(0);
  $defHeader      = $xp->query('.//span[@id="defense" or @id="defencemen"]', $lineCombos)->item(0);
  $goalieHeader   = $xp->query('.//span[@id="goalie_list" or @id="goalies" or @id="goalies_list"]', $lineCombos)->item(0);

  // Injuries header (end marker) – find any span containing "injuries"
  $injHeader = $xp->query('.//span[contains(translate(normalize-space(.),
    "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "injuries")]', $lineCombos)->item(0);

  /* ---- Forwards (exactly 4 lines max / 12 players) ---- */
  if ($forwardsHeader) {
    $players = extract_player_tiles_between($xp, $lineCombos, $forwardsHeader, $defHeader);

    // Hard limit: 12 skaters (4 lines)
    $players = array_slice($players, 0, 12);

    $pos = ['LW','C','RW'];
    $lineNo = 0;
    $slotNo = 0; // 1..12

    for ($i=0; $i+2 < count($players); $i += 3) {
      $lineNo++;
      for ($j=0; $j<3; $j++) {
        $slotNo++;
        $p = $players[$i+$j];
        $rows[] = [
          'section'       => 'F',
          'line_no'       => $lineNo,
          'position_code' => $pos[$j],
          'slot_no'       => $slotNo,
          'player_name'   => $p['player_name'],
          'player_id'     => null,
          'jersey_number' => $p['jersey_number'],
          'injury_status' => $p['injury_status'],
          'is_injured'    => $p['is_injured'],
        ];
      }
      if ($lineNo >= 4) break;
    }
  }

  /* ---- Defense (exactly 3 pairs max / 6 players) ---- */
  if ($defHeader) {
    $players = extract_player_tiles_between($xp, $lineCombos, $defHeader, $goalieHeader ?: $injHeader);

    // Hard limit: 6 skaters (3 pairs)
    $players = array_slice($players, 0, 6);

    $pos = ['LD','RD'];
    $pairNo = 0;
    $slotNo = 0; // 1..6

    for ($i=0; $i+1 < count($players); $i += 2) {
      $pairNo++;
      for ($j=0; $j<2; $j++) {
        $slotNo++;
        $p = $players[$i+$j];
        $rows[] = [
          'section'       => 'D',
          'line_no'       => $pairNo,
          'position_code' => $pos[$j],
          'slot_no'       => $slotNo,
          'player_name'   => $p['player_name'],
          'player_id'     => null,
          'jersey_number' => $p['jersey_number'],
          'injury_status' => $p['injury_status'],
          'is_injured'    => $p['is_injured'],
        ];
      }
      if ($pairNo >= 3) break;
    }
  }

  /* ---- Goalies (up to 2) ---- */
  if ($goalieHeader) {
    $players = extract_player_tiles_between($xp, $lineCombos, $goalieHeader, $injHeader);
    $players = array_slice($players, 0, 2);

    $gNo = 0;
    foreach ($players as $p) {
      $gNo++;
      $rows[] = [
        'section'       => 'G',
        'line_no'       => $gNo,
        'position_code' => 'G',
        'slot_no'       => $gNo, // 1/2
        'player_name'   => $p['player_name'],
        'player_id'     => null,
        'jersey_number' => $p['jersey_number'],
        'injury_status' => $p['injury_status'],
        'is_injured'    => $p['is_injured'],
      ];
      if ($gNo >= 2) break;
    }
  }

  /* ---- Injuries (scan within injuries wrapper; slot_no increments) ---- */
  if ($injHeader) {
    $wrap = $injHeader->parentNode;
    for ($i=0; $i<8 && $wrap; $i++) {
      if ($wrap instanceof DOMElement) {
        $anchors = $xp->query('.//a[starts-with(@href,"/players/news/")]', $wrap);
        if ($anchors && $anchors->length >= 1) break;
      }
      $wrap = $wrap->parentNode;
    }

    if ($wrap) {
      $anchors = $xp->query('.//a[starts-with(@href,"/players/news/")]', $wrap);
      if ($anchors) {
        $seen = [];
        $injSlot = 0;

        foreach ($anchors as $a) {
          if (!$a instanceof DOMElement) continue;

          $nameSpan = $xp->query('.//span[contains(@class,"uppercase")]', $a)->item(0);
          $name = $nameSpan ? norm_space($nameSpan->textContent) : norm_space($a->textContent);
          if ($name === '') continue;

          $tileRoot = $a->parentNode;
          for ($j=0; $j<7 && $tileRoot; $j++) {
            if ($tileRoot instanceof DOMElement) {
              $imgTest = $xp->query('.//img', $tileRoot);
              if ($imgTest && $imgTest->length) break;
            }
            $tileRoot = $tileRoot->parentNode;
          }
          if (!$tileRoot) $tileRoot = $a;

          $img0 = $xp->query('.//img', $tileRoot)->item(0);
          $jersey = parse_jersey_from_img($img0);

          $injStatus = injury_badge_text($xp, $tileRoot);

          $key = mb_strtolower($name, 'UTF-8');
          if (isset($seen[$key])) continue;
          $seen[$key] = true;

          $injSlot++;

          $rows[] = [
            'section'       => 'I',
            'line_no'       => 0,
            'position_code' => 'NA',
            'slot_no'       => $injSlot, // CRITICAL: unique per injury tile
            'player_name'   => $name,
            'player_id'     => null,
            'jersey_number' => $jersey,
            'injury_status' => $injStatus ?: 'IR',
            'is_injured'    => 1,
          ];
        }
      }
    }
  }

  /* ---- De-dupe within run (include slot_no) ---- */
  $uniq = [];
  $deduped = [];
  foreach ($rows as $r) {
    $pk = implode('|', [
      $teamSlug,
      $r['section'],
      (string)$r['line_no'],
      (string)$r['position_code'],
      (string)$r['slot_no'],
      (string)$r['player_name'],
    ]);
    if (isset($uniq[$pk])) continue;
    $uniq[$pk] = true;
    $deduped[] = $r;
  }

  return $deduped;
}

/* ---------------- file discovery ---------------- */

$files = glob(rtrim($dir, '/\\') . '/*.html');
if (!$files) {
  exit("No .html files found in: $dir\n");
}

$filter = [];
if (!empty($teams)) {
  foreach ($teams as $t) {
    if ($t !== '') $filter[$t] = true;
  }
}

$selected = [];
foreach ($files as $path) {
  $base = basename($path);
  $slug = strtolower(preg_replace('/\.html$/i', '', $base));
  if (!empty($filter) && !isset($filter[$slug])) continue;
  $selected[] = ['slug' => $slug, 'path' => $path];
}

if (!$selected) {
  exit("No matching team files found in: $dir\n");
}

/* ---------------- DB import per team ---------------- */

$sqlInsert = "
  INSERT INTO dailyfaceoff_lines
    (lines_date, team_slug, scraped_at, section, line_no, position_code, slot_no,
     player_name, player_id, jersey_number, injury_status, is_injured, source_url)
  VALUES
    (:lines_date,:team_slug,:scraped_at,:section,:line_no,:position_code,:slot_no,
     :player_name,:player_id,:jersey_number,:injury_status,:is_injured,:source_url)
  ON DUPLICATE KEY UPDATE
     scraped_at     = VALUES(scraped_at),
     player_name    = VALUES(player_name),
     player_id      = VALUES(player_id),
     jersey_number  = VALUES(jersey_number),
     injury_status  = VALUES(injury_status),
     is_injured     = VALUES(is_injured),
     source_url     = VALUES(source_url)
";
$ins = $pdo->prepare($sqlInsert);

$del = $pdo->prepare("DELETE FROM dailyfaceoff_lines WHERE lines_date = :d AND team_slug = :t");

$totalTeams = 0;
$totalRows  = 0;
$totalErrs  = 0;

foreach ($selected as $item) {
  $teamSlug = $item['slug'];
  $filePath = $item['path'];

  echo "== Import {$teamSlug} ({$filePath}) ==\n";

  try {
    $html = file_get_contents($filePath);
    if ($html === false || $html === '') {
      throw new RuntimeException("Failed to read file.");
    }

    $rows = parse_df_html_to_rows($html, $teamSlug);
    $scrapedAt = gmdate('Y-m-d H:i:s');
    $sourceUrl = 'file://' . $filePath;

    $pdo->beginTransaction();

    // wipe existing for this team/date
    $del->execute([':d' => $date, ':t' => $teamSlug]);

    $n = 0;
    foreach ($rows as $r) {
      // defensive: ensure slot_no exists
      if (!isset($r['slot_no'])) $r['slot_no'] = 0;

      $ins->execute([
        ':lines_date'    => $date,
        ':team_slug'     => $teamSlug,
        ':scraped_at'    => $scrapedAt,
        ':section'       => (string)$r['section'],
        ':line_no'       => (int)$r['line_no'],
        ':position_code' => (string)$r['position_code'],
        ':slot_no'       => (int)$r['slot_no'],
        ':player_name'   => (string)$r['player_name'],
        ':player_id'     => null,
        ':jersey_number' => $r['jersey_number'],
        ':injury_status' => $r['injury_status'],
        ':is_injured'    => (int)$r['is_injured'],
        ':source_url'    => $sourceUrl,
      ]);
      $n++;
    }

    $pdo->commit();

    $totalTeams++;
    $totalRows += $n;

    echo "OK: Imported {$n} rows for {$teamSlug} on {$date}\n\n";
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $totalErrs++;
    echo "ERROR: {$teamSlug} - " . $e->getMessage() . "\n\n";
  }
}

echo "DONE: teams={$totalTeams} rows={$totalRows} errors={$totalErrs} date={$date}\n";
