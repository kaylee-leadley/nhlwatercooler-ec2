<?php
// public/api/stats_player_card.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php'; // or lineups_config + getPdo()

header('Content-Type: text/html; charset=utf-8');

$playerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$playerId) {
  http_response_code(400);
  echo 'Missing player id';
  exit;
}

$stmt = $pdo->prepare("
  SELECT *
  FROM msf_players
  WHERE player_id = :id
");
$stmt->execute([':id' => $playerId]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
  http_response_code(404);
  echo 'Player not found';
  exit;
}

// Simple helper
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$fullName   = trim($player['first_name'] . ' ' . $player['last_name']);
$pos        = $player['primary_position'];
$team       = $player['current_team_abbr'];
$img        = $player['official_image_src'];
$height     = $player['height'];
$weight     = $player['weight'] ? $player['weight'] . ' lbs' : '';
$birthplace = trim(($player['birth_city'] ?? '') . ', ' . ($player['birth_country'] ?? ''));
$birthplace = rtrim($birthplace, ', ');
$birthDate  = $player['birth_date'];
$shoots     = $player['shoots'];
$catches    = $player['catches'];
$rookie     = $player['rookie'] ? 'Yes' : 'No';
$draftText  = '';

if ($player['draft_year']) {
  $parts = [];
  $parts[] = $player['draft_year'];
  if ($player['draft_team_abbr']) {
    $parts[] = $player['draft_team_abbr'];
  }
  if ($player['draft_round']) {
    $parts[] = 'Rnd ' . $player['draft_round'];
  }
  if ($player['draft_overall']) {
    $parts[] = '#' . $player['draft_overall'] . ' overall';
  }
  $draftText = implode(' · ', $parts);
}

?>
<div class="player-card">
  <div class="player-card__header">
    <?php if ($img): ?>
      <div class="player-card__avatar">
        <img src="<?= h($img) ?>" alt="<?= h($fullName) ?>">
      </div>
    <?php endif; ?>
    <div class="player-card__summary">
      <div class="player-card__name"><?= h($fullName) ?></div>
      <div class="player-card__meta">
        <?= h($pos) ?>
        <?php if ($team): ?> · <?= h($team) ?><?php endif; ?>
        <?php if ($player['jersey_number']): ?> · #<?= h($player['jersey_number']) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="player-card__body">
    <div class="player-card__row">
      <span class="label">Height / Weight</span>
      <span class="value">
        <?= $height ? h($height) : '—' ?>
        <?php if ($weight): ?> · <?= h($weight) ?><?php endif; ?>
      </span>
    </div>

    <div class="player-card__row">
      <span class="label">Born</span>
      <span class="value">
        <?= $birthDate ? h($birthDate) : '—' ?>
        <?php if ($birthplace): ?> · <?= h($birthplace) ?><?php endif; ?>
      </span>
    </div>

    <div class="player-card__row">
      <span class="label">Shoots / Catches</span>
      <span class="value">
        <?= $shoots ? 'Shoots ' . h($shoots) : '—' ?>
        <?php if ($catches): ?> · Catches <?= h($catches) ?><?php endif; ?>
      </span>
    </div>

    <div class="player-card__row">
      <span class="label">Rookie</span>
      <span class="value"><?= h($rookie) ?></span>
    </div>

    <?php if ($draftText): ?>
      <div class="player-card__row">
        <span class="label">Draft</span>
        <span class="value"><?= h($draftText) ?></span>
      </div>
    <?php endif; ?>
  </div>
</div>
