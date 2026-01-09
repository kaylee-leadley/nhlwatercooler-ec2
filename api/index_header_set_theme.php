<?php
// public/api/index_header_index_header_set_theme.php
session_start();

header('Content-Type: application/json; charset=utf-8');

$theme = isset($_POST['theme']) ? (string)$_POST['theme'] : '';
$theme = trim($theme);

// Legacy callers that still send team=
if ($theme === '' && isset($_POST['team'])) {
  $team = trim((string)$_POST['team']);
  if ($team !== '' && strcasecmp($team, 'ALL') !== 0) {
    $theme = $team;
  }
}

// Slugify (STL -> stl, Air Force -> air-force, Arizona St -> arizona-st)
$slug = strtolower($theme);
$slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
$slug = trim($slug, '-');

// Treat ALL / empty as "clear"
if (
  $slug === '' ||
  $slug === 'all' ||
  $slug === 'all-teams' ||
  $slug === 'all-schools' ||
  $slug === 'team-all' ||
  $slug === 'ncaa-all' ||
  $slug === 'theme-team-all' ||
  $slug === 'theme-ncaa-all'
) {
  unset($_SESSION['theme_team_slug']);
  $slug = '';
} else {
  $_SESSION['theme_team_slug'] = $slug;
}

echo json_encode([
  'ok'    => true,
  'theme' => $slug,
]);
