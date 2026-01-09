<?php
/**
 * patch_ncaa_color_scheme.php
 *
 * Updates NCAA team color CSS blocks so variables apply to:
 *  - body.ncaa-team-<slug>
 *  - .rankings-table__row.ncaa-team-<slug>
 *  - .stats-table__row.ncaa-team-<slug>
 *
 * Target file:
 *   public/assets/css/ncaa-color-scheme.css
 *
 * Run:
 *   php public/scripts/patch_ncaa_color_scheme.php
 */

declare(strict_types=1);

$path = __DIR__ . '/../assets/css/ncaa-color-scheme.css';
if (!file_exists($path)) {
  fwrite(STDERR, "ERROR: File not found: {$path}\n");
  exit(1);
}

$css = file_get_contents($path);
if ($css === false) {
  fwrite(STDERR, "ERROR: Unable to read: {$path}\n");
  exit(1);
}

// Backup first
$backupPath = $path . '.bak-' . date('Ymd-His');
if (file_put_contents($backupPath, $css) === false) {
  fwrite(STDERR, "ERROR: Unable to write backup: {$backupPath}\n");
  exit(1);
}

// Regex: match ONLY "body.ncaa-team-<slug> {" where <slug> is [a-z0-9-]+
// and replace it with a 3-selector list.
//
// We intentionally do NOT touch:
//  - body.ncaa-all,
//  - body[class*="ncaa-team-"] blocks,
//  - anything already multi-selector (body.ncaa-team-x, ...)
$pattern = '/(^\s*)body\.ncaa-team-([a-z0-9\-]+)\s*\{/mi';

$replacedCount = 0;

$css2 = preg_replace_callback($pattern, function ($m) use (&$replacedCount) {
  $indent = $m[1];
  $slug   = $m[2];

  // If the line already contains a comma selector list (rare), skip
  // (We only matched "body.ncaa-team-xxx {" so this is safe, but keeping defensive.)
  $replacedCount++;

  return $indent
    . "body.ncaa-team-{$slug},\n"
    . $indent . ".rankings-table__row.ncaa-team-{$slug},\n"
    . $indent . ".stats-table__row.ncaa-team-{$slug} {";
}, $css);

if ($css2 === null) {
  fwrite(STDERR, "ERROR: preg_replace failed.\n");
  exit(1);
}

if ($replacedCount === 0) {
  fwrite(STDERR, "WARN: No body.ncaa-team-* blocks were found to update.\n");
  fwrite(STDERR, "      Did the file use a different selector format?\n");
  exit(2);
}

// Write updated file
if (file_put_contents($path, $css2) === false) {
  fwrite(STDERR, "ERROR: Unable to write updated CSS: {$path}\n");
  exit(1);
}

echo "OK: Updated {$replacedCount} team blocks.\n";
echo "Backup created: {$backupPath}\n";
echo "Patched file:   {$path}\n";
