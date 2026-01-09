<?php
// public/scripts/cron_import_ncaa_teams.php
//
// Usage (from project root or scripts dir):
//   php public/scripts/cron_import_ncaa_teams.php
//
// Imports / upserts NCAA schools into ncaa_teams table.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root = dirname(__DIR__, 2); // /home/leadley/website/SJS/gameday-board
require_once $root . '/config/db.php';
require_once $root . '/public/api/ncaa_api.php'; // adjust path if different

echo "----- NCAA Teams Import START " . date('Y-m-d H:i:s') . " -----\n";

// Fetch schools-index from NCAA API
$schools = ncaa_schools_index();
if ($schools === null) {
    echo "ERROR: Failed to fetch schools-index from NCAA API.\n";
    exit(1);
}

if (!is_array($schools) || count($schools) === 0) {
    echo "WARNING: schools-index returned empty or non-array.\n";
    exit(0);
}

// Prepare upsert statement
$sql = "
  INSERT INTO ncaa_teams (slug, short_name, full_name)
  VALUES (:slug, :short_name, :full_name)
  ON DUPLICATE KEY UPDATE
    short_name = VALUES(short_name),
    full_name  = VALUES(full_name),
    updated_at = CURRENT_TIMESTAMP
";

$stmt = $pdo->prepare($sql);

$inserted = 0;
$updated  = 0;
$skipped  = 0;

foreach ($schools as $row) {
    // slug is mandatory
    $slug = isset($row['slug']) ? trim($row['slug']) : '';
    if ($slug === '') {
        $skipped++;
        error_log("Skipping schools-index row with empty slug: " . json_encode($row));
        continue;
    }

    // Prefer name/long if present, but don't require them.
    $short = isset($row['name']) ? trim($row['name']) : '';
    $full  = isset($row['long']) ? trim($row['long']) : '';

    // Fill in missing bits:
    // - if both missing, fall back to slug for both
    // - if one missing, copy from the other
    if ($short === '' && $full === '') {
        $short = $slug;
        $full  = $slug;
    } elseif ($short === '' && $full !== '') {
        $short = $full;
    } elseif ($short !== '' && $full === '') {
        $full = $short;
    }

    $stmt->execute([
        ':slug'       => $slug,
        ':short_name' => $short,
        ':full_name'  => $full,
    ]);

    // crude updated/inserted estimate using rowCount (not perfect but fine for logging)
    if ($stmt->rowCount() === 1) {
        $inserted++;
    } elseif ($stmt->rowCount() === 2) {
        $updated++;
    }
}

echo "Import finished.\n";
echo "Inserted: $inserted\n";
echo "Updated:  $updated\n";
echo "Skipped (no slug): $skipped\n";
echo "Total processed rows: " . count($schools) . "\n";
echo "----- NCAA Teams Import END " . date('Y-m-d H:i:s') . " -----\n";
