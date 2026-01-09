<?php
// public/scripts/cron_import_ncaa_rankings.php
//
// Usage:
//   php cron_import_ncaa_rankings.php
//
// Pulls the USCHO D1 men's rankings from your rankings service and
// upserts into ncaa_rankings table.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Project root: /home/leadley/website/SJS/gameday-board
$root = dirname(__DIR__, 2);

require_once $root . '/config/db.php';

echo "----- NCAA rankings import START -----\n";

$pollSlug     = 'uschocom';
$sportSlug    = 'icehockey-men';
$divisionSlug = 'd1';

// Endpoint you just tested with curl
$url = "http://127.0.0.1:3000/rankings/{$sportSlug}/{$divisionSlug}/{$pollSlug}";

// -----------------------------
// Fetch JSON from rankings API
// -----------------------------
$json = @file_get_contents($url);
if ($json === false) {
    $err = error_get_last();
    echo "ERROR: Failed to fetch rankings from {$url}\n";
    if ($err && isset($err['message'])) {
        echo "PHP warning: {$err['message']}\n";
    }
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    echo "ERROR: Invalid JSON response from rankings API.\n";
    exit(1);
}

// Basic top-level fields
$sport       = $data['sport']  ?? "Men's Ice Hockey";
$pollTitle   = $data['title']  ?? 'USCHO.com';
$updatedRaw  = $data['updated'] ?? '';
$page        = $data['page']   ?? 1;
$pages       = $data['pages']  ?? 1;
$rows        = $data['data']   ?? [];

echo "Source: {$pollTitle} ({$pollSlug})\n";
echo "Sport:  {$sport} ({$sportSlug})\n";
echo "Div:    {$divisionSlug}\n";
echo "Updated: {$updatedRaw}\n";
echo "Page {$page}/{$pages}\n";

if (empty($rows)) {
    echo "No data rows found in rankings JSON. Aborting.\n";
    exit(0);
}

// -----------------------------
// Parse poll_date from updated
// Example: "Through Games DEC. 7, 2025"
// -----------------------------
$pollDate = null;

if ($updatedRaw) {
    // strip leading "Through Games " if present
    $clean = preg_replace('/^Through Games\s+/i', '', $updatedRaw);
    // Remove trailing punctuation weirdness like "DEC." -> "DEC"
    $clean = str_replace('DEC.', 'DEC', $clean);
    $clean = str_replace('NOV.', 'NOV', $clean);
    $clean = str_replace('OCT.', 'OCT', $clean);
    $clean = str_replace('SEPT.', 'SEP', $clean);
    $ts = strtotime($clean);
    if ($ts !== false) {
        $pollDate = date('Y-m-d', $ts);
    }
}

if (!$pollDate) {
    // Fallback: use "today"
    $pollDate = date('Y-m-d');
    echo "WARNING: Could not parse poll date from '{$updatedRaw}', using {$pollDate}\n";
} else {
    echo "Parsed poll_date: {$pollDate}\n";
}

// -----------------------------
// Prepare INSERT / UPSERT
// -----------------------------
$sql = "
  INSERT INTO ncaa_rankings (
    poll_slug,
    poll_title,
    sport_slug,
    division_slug,
    poll_date,
    updated_raw,
    team_rank,
    team_name,
    first_votes,
    record,
    points,
    previous_rank
  ) VALUES (
    :poll_slug,
    :poll_title,
    :sport_slug,
    :division_slug,
    :poll_date,
    :updated_raw,
    :team_rank,
    :team_name,
    :first_votes,
    :record,
    :points,
    :previous_rank
  )
  ON DUPLICATE KEY UPDATE
    poll_title    = VALUES(poll_title),
    updated_raw   = VALUES(updated_raw),
    team_name     = VALUES(team_name),
    first_votes   = VALUES(first_votes),
    record        = VALUES(record),
    points        = VALUES(points),
    previous_rank = VALUES(previous_rank)
";

$stmt = $pdo->prepare($sql);

$insertCount = 0;

// -----------------------------
// Iterate rows (rankings data)
// Example row keys:
//  "RANK":"1",
//  "TEAM (1ST VOTES)":"Michigan (26)",
//  "RECORD":"16-4-0",
//  "POINTS":"974",
//  "PREVIOUS RANK":"1"
// -----------------------------
foreach ($rows as $row) {
    // 1) Rank
    $rankStr = $row['RANK'] ?? '';
    $rank    = (int)$rankStr;

    // 2) Team name + first votes
    $teamField = $row['TEAM (1ST VOTES)'] ?? '';
    $teamName  = trim($teamField);
    $firstVotes = null;

    // If it looks like "Michigan (26)" extract both
    if (preg_match('/^(.*)\((\d+)\)\s*$/', $teamField, $m)) {
        $teamName   = trim($m[1]);
        $firstVotes = (int)$m[2];
    }

    // 3) Record
    $record = trim($row['RECORD'] ?? '');

    // 4) Points
    $pointsStr = $row['POINTS'] ?? '0';
    $points    = (int)$pointsStr;

    // 5) Previous rank (could be 'NR')
    $prevStr = trim($row['PREVIOUS RANK'] ?? '');
    $previousRank = null;
    if ($prevStr !== '' && strtoupper($prevStr) !== 'NR') {
        $previousRank = (int)$prevStr;
    }

    // Bind + execute
    $stmt->execute([
        ':poll_slug'     => $pollSlug,
        ':poll_title'    => $pollTitle,
        ':sport_slug'    => $sportSlug,
        ':division_slug' => $divisionSlug,
        ':poll_date'     => $pollDate,
        ':updated_raw'   => $updatedRaw,
        ':team_rank'     => $rank,
        ':team_name'     => $teamName,
        ':first_votes'   => $firstVotes,
        ':record'        => $record,
        ':points'        => $points,
        ':previous_rank' => $previousRank,
    ]);

    $insertCount++;
    echo "Upserted rank {$rank}: {$teamName} ({$record}, {$points} pts)\n";
}

echo "Done. Upserted {$insertCount} ranking rows for {$pollDate}.\n";
echo "----- NCAA rankings import END -----\n";
