<?php
// public/scripts/cron_import_dfo_starting_goalies.php
//
// Import DailyFaceoff "starting-goalies.html" snapshot into dailyfaceoff_lines
// Updates existing goalie rows (section='G') by matching on goalie name (case-insensitive).
//
// Usage:
//   php public/scripts/cron_import_dfo_starting_goalies.php
//   php public/scripts/cron_import_dfo_starting_goalies.php --date=2025-12-17
//   php public/scripts/cron_import_dfo_starting_goalies.php --file=/path/to/starting-goalies.html
//
// Requires columns (option A):
//   ALTER TABLE dailyfaceoff_lines
//     ADD COLUMN goalie_status VARCHAR(32) NULL,
//     ADD COLUMN goalie_start_score TINYINT UNSIGNED NULL;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';

$tz = new DateTimeZone('America/New_York');

/* ---------------- team slug aliases ----------------
   DFO sometimes changes slugs before your system does.
   Map "DFO slug" => "our DB slug".
----------------------------------------------------- */
$TEAM_SLUG_ALIASES = [
  'utah-mammoth' => 'utah-hockey-club',
];

/* ---------------- tiny helpers ---------------- */

function starts_with($s, $prefix) {
  return strncmp($s, $prefix, strlen($prefix)) === 0;
}

function norm_space($s) {
  $s = preg_replace('/\s+/u', ' ', (string)$s);
  return trim($s);
}

function slug_from_team_href($href) {
  // /teams/los-angeles-kings/line-combinations
  if (!$href) return null;
  if (preg_match('#/teams/([^/]+)/line-combinations#', $href, $m)) {
    return strtolower($m[1]);
  }
  return null;
}

function pick_status_from_text($text) {
  $t = strtoupper(norm_space($text));
  foreach (['CONFIRMED','EXPECTED','LIKELY','PROBABLE','UNCONFIRMED','TBD'] as $w) {
    if (strpos($t, $w) !== false) return $w;
  }
  return null;
}

function xp_nodes(DOMXPath $xp, string $expr, ?DOMNode $ctx = null) {
  $nl = $xp->query($expr, $ctx);
  return ($nl instanceof DOMNodeList) ? $nl : null;
}

function xp_first(DOMXPath $xp, string $expr, ?DOMNode $ctx = null) {
  $nl = xp_nodes($xp, $expr, $ctx);
  if ($nl && $nl->length) return $nl->item(0);
  return null;
}

/* ---------------- CLI ---------------- */

$date = (new DateTime('today', $tz))->format('Y-m-d');
$file = null;

foreach (array_slice($argv, 1) as $arg) {
  if (starts_with($arg, '--date=')) $date = substr($arg, 7);
  elseif (starts_with($arg, '--file=')) $file = substr($arg, 7);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  exit("Invalid --date. Use YYYY-MM-DD.\n");
}

if (!$file) $file = $root . "/dailyfaceoff_snapshots/$date/starting-goalies.html";

if (!is_file($file)) exit("starting-goalies file not found: $file\n");

$html = file_get_contents($file);
if ($html === false || $html === '') exit("Failed to read file or empty: $file\n");

/* ---------------- Parse ---------------- */

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
$xp = new DOMXPath($dom);

// Matchups are typically <article> blocks
$articles = xp_nodes($xp, '//article');
if (!$articles || !$articles->length) exit("No <article> nodes found (page layout changed?)\n");

$parsed = []; // ['team_slug','goalie_name','goalie_status','goalie_start_score']

foreach ($articles as $article) {
  // Find "Line Combos" links inside each matchup (one per team)
  $links = xp_nodes($xp, './/a[contains(@href,"/teams/") and contains(@href,"/line-combinations")]', $article);
  if (!$links || !$links->length) continue;

  foreach ($links as $a) {
    if (!$a instanceof DOMElement) continue;

    $teamSlug = slug_from_team_href($a->getAttribute('href'));
    if (!$teamSlug) continue;

    // normalize to OUR DB slug if needed
    if (isset($TEAM_SLUG_ALIASES[$teamSlug])) {
      $teamSlug = $TEAM_SLUG_ALIASES[$teamSlug];
    }

    // Climb to a stable container: a div that contains this link + goalie headshot
    $block = $a;
    for ($i=0; $i<10 && $block; $i++) {
      if ($block instanceof DOMElement && $block->tagName === 'div') {
        $hasThisLink = xp_nodes($xp, './/a[contains(@href,"/teams/'.$teamSlug.'/line-combinations")]', $block);
        // NOTE: if slug changed and we aliased it, the href still contains original slug.
        // So if thisLink fails, we accept headshot-only signal and stop anyway.
        $hasHeadshot = xp_nodes($xp, './/img[contains(@src,"/uploads/player") or contains(@src,"headshot")]', $block);

        if ($hasHeadshot && $hasHeadshot->length) {
          // Good enough: this div includes the goalie card.
          break;
        }
      }
      $block = $block->parentNode;
    }
    if (!$block) $block = $a;

    // Goalie name (DFO commonly uses text-lg / text-2xl on the goalie name)
    $nameNode = xp_first($xp, './/span[contains(@class,"text-lg") or contains(@class,"text-2xl")]', $block);
    $goalieName = $nameNode ? norm_space($nameNode->textContent) : null;
    if (!$goalieName) {
      // fallback: first non-empty heading-ish text
      $cand = xp_first($xp, './/strong|.//h3|.//h2', $block);
      $goalieName = $cand ? norm_space($cand->textContent) : null;
    }
    if (!$goalieName) continue;

    // Status: scan block text for keywords
    $goalieStatus = pick_status_from_text($block->textContent);

    // Score: find link to daily-goalie-rankings then pull first integer from its text
    $score = null;
    $rankLink = xp_first($xp, './/a[contains(@href,"/tools/daily-goalie-rankings")]', $block);
    if ($rankLink) {
      $t = norm_space($rankLink->textContent);
      if (preg_match('/\b(\d{1,3})\b/', $t, $m)) $score = (int)$m[1];
    } else {
      // fallback: any small bubble number in the block
      $spans = xp_nodes($xp, './/span', $block);
      if ($spans) {
        foreach ($spans as $s) {
          $v = norm_space($s->textContent);
          if (preg_match('/^\d{1,3}$/', $v)) { $score = (int)$v; break; }
        }
      }
    }

    // If we got neither status nor score, skip (name alone isn't useful)
    if ($goalieStatus === null && $score === null) continue;

    $parsed[] = [
      'team_slug' => $teamSlug,
      'goalie_name' => $goalieName,
      'goalie_status' => $goalieStatus,
      'goalie_start_score' => $score,
    ];
  }
}

if (!$parsed) exit("No goalie rows parsed from HTML (selectors may need update)\n");

/* ---------------- DB update (match by name) ---------------- */

$src = 'file://' . $file;
$scrapedAt = gmdate('Y-m-d H:i:s');

$sel = $pdo->prepare("
  SELECT COUNT(*) AS c
    FROM dailyfaceoff_lines
   WHERE lines_date = :d
     AND team_slug  = :t
     AND section    = 'G'
     AND LOWER(player_name) = LOWER(:n)
");

$upd = $pdo->prepare("
  UPDATE dailyfaceoff_lines
     SET goalie_status      = :status,
         goalie_start_score = :score,
         scraped_at         = :scraped_at,
         source_url         = :src
   WHERE lines_date = :d
     AND team_slug  = :t
     AND section    = 'G'
     AND LOWER(player_name) = LOWER(:n)
");

$ok = 0;
$miss = 0;

foreach ($parsed as $r) {
  $sel->execute([
    ':d' => $date,
    ':t' => $r['team_slug'],
    ':n' => $r['goalie_name'],
  ]);
  $found = (int)$sel->fetchColumn();

  if ($found <= 0) {
    $miss++;
    echo "WARN {$r['team_slug']} :: no goalie row to update for name='{$r['goalie_name']}' (date={$date})\n";
    continue;
  }

  $upd->execute([
    ':status' => $r['goalie_status'],
    ':score'  => $r['goalie_start_score'],
    ':scraped_at' => $scrapedAt,
    ':src' => $src,
    ':d' => $date,
    ':t' => $r['team_slug'],
    ':n' => $r['goalie_name'],
  ]);

  $ok += $found;
  echo "OK   {$r['team_slug']} :: {$r['goalie_name']} status={$r['goalie_status']} score={$r['goalie_start_score']}\n";
}

echo "DONE: matched_rows={$ok} missed={$miss} date={$date}\n";
