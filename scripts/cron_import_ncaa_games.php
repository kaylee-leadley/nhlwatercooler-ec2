<?php
// public/scripts/cron_import_ncaa_games.php
//
// Usage (on the server):
//   php public/scripts/cron_import_ncaa_games.php 2024-11-01
//   php public/scripts/cron_import_ncaa_games.php 2024-11-01 2024-11-03
//   php public/scripts/cron_import_ncaa_games.php            # defaults to today in ET
//
// This script:
//  - Loops over a date range
//  - For each date:
//      * Fetches NCAA DI men's hockey scoreboard
//      * Upserts rows into ncaa_games
//      * Fetches /game/{id}/boxscore and upserts into ncaa_boxscores
//  - Sleeps between each day (and optionally between games) to be gentle on the API.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Adjust this to your actual project root
$root = dirname(__DIR__, 2); // e.g. /home/leadley/website/SJS/gameday-board

require_once $root . '/config/db.php';
require_once __DIR__ . '/../api/ncaa_api.php';

$tz = new DateTimeZone('America/New_York');

// How long to sleep between days (seconds)
$DAY_DELAY_SEC   = 2;

// Optional: micro delay between games (seconds as float)
$GAME_DELAY_SEC  = 0.2; // set to 0 if you don't want any per-game delay

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}


// --------------------------
// CLI date arguments
// --------------------------
$startArg = $argv[1] ?? null;
$endArg   = $argv[2] ?? null;

if ($startArg) {
    $start = new DateTime($startArg, $tz);
} else {
    // default: today (like your NHL cron)
    $start = new DateTime('today', $tz);
}

if ($endArg) {
    $end = new DateTime($endArg, $tz);
} else {
    $end = clone $start;
}

$start->setTime(0, 0, 0);
$end->setTime(0, 0, 0);

$current = clone $start;

while ($current <= $end) {
    $dateYmd = $current->format('Y-m-d');
    log_msg("=== Importing NCAA DI Men's hockey for {$dateYmd} ===");

    $scoreboard = ncaa_hockey_scoreboard($dateYmd);
    if (!$scoreboard) {
        log_msg("No data / error for {$dateYmd}");
        // sleep even on error so we don't hammer
        log_msg("Finished {$dateYmd}, sleeping {$DAY_DELAY_SEC} seconds before next day...");
        sleep($DAY_DELAY_SEC);
        $current->modify('+1 day');
        continue;
    }

    $games = $scoreboard['games'] ?? [];
    if (empty($games)) {
        log_msg("No games for {$dateYmd}");
        log_msg("Finished {$dateYmd}, sleeping {$DAY_DELAY_SEC} seconds before next day...");
        sleep($DAY_DELAY_SEC);
        $current->modify('+1 day');
        continue;
    }

    foreach ($games as $item) {
        $g = $item['game'] ?? null;
        if (!$g) {
            continue;
        }

        $gameId = isset($g['gameID']) ? (string)$g['gameID'] : null;
        if (!$gameId) {
            continue;
        }

        // -------- Parse date/time --------
        $startDateStr = $g['startDate'] ?? null; // "11-01-2024"
        $gameDate = $dateYmd;
        if ($startDateStr) {
            $dtStart = DateTime::createFromFormat('m-d-Y', $startDateStr, $tz);
            if ($dtStart) {
                $gameDate = $dtStart->format('Y-m-d');
            }
        }

        $epoch = isset($g['startTimeEpoch']) ? (int)$g['startTimeEpoch'] : null;
        $gameTime = null;
        if ($epoch) {
            $startDt = new DateTime('@' . $epoch);
            $startDt->setTimezone($tz);
            $gameTime = $startDt->format('H:i:s');
        }

        $startTimeLocalStr = $g['startTime'] ?? null; // "07:00PM ET"
        $state             = $g['gameState'] ?? null;
        $finalMessage      = $g['finalMessage'] ?? null;
        $ncaaUrl           = $g['url'] ?? null;

        // -------- Home team --------
        $home      = $g['home'] ?? [];
        $homeNames = $home['names'] ?? [];
        $homeShort  = $homeNames['short'] ?? 'Home';
        $homeFull   = $homeNames['full']  ?? $homeShort;
        $homeSeo    = $homeNames['seo']   ?? null;
        $homeChar6  = $homeNames['char6'] ?? null;
        $homeScore  = isset($home['score']) ? (int)$home['score'] : null;
        $homeRank   = $home['rank'] ?? null;
        $homeRecord = $home['description'] ?? null;

        // -------- Away team --------
        $away      = $g['away'] ?? [];
        $awayNames = $away['names'] ?? [];
        $awayShort  = $awayNames['short'] ?? 'Away';
        $awayFull   = $awayNames['full']  ?? $awayShort;
        $awaySeo    = $awayNames['seo']   ?? null;
        $awayChar6  = $awayNames['char6'] ?? null;
        $awayScore  = isset($away['score']) ? (int)$away['score'] : null;
        $awayRank   = $away['rank'] ?? null;
        $awayRecord = $away['description'] ?? null;

        // -------- Upsert ncaa_games --------
        $select = $pdo->prepare("SELECT id FROM ncaa_games WHERE game_id = :game_id");
        $select->execute([':game_id' => $gameId]);
        $existingId = $select->fetchColumn();

        if ($existingId) {
            $update = $pdo->prepare("
                UPDATE ncaa_games
                   SET sport = 'icehockey-men',
                       division = 'd1',
                       game_date = :game_date,
                       start_time = :start_time,
                       start_time_local_str = :start_time_local_str,
                       start_time_epoch = :start_time_epoch,
                       state = :state,
                       final_message = :final_message,
                       ncaa_url = :ncaa_url,
                       home_team_short = :home_team_short,
                       home_team_full  = :home_team_full,
                       home_team_seo   = :home_team_seo,
                       home_team_char6 = :home_team_char6,
                       home_score      = :home_score,
                       home_rank       = :home_rank,
                       home_record_str = :home_record_str,
                       away_team_short = :away_team_short,
                       away_team_full  = :away_team_full,
                       away_team_seo   = :away_team_seo,
                       away_team_char6 = :away_team_char6,
                       away_score      = :away_score,
                       away_rank       = :away_rank,
                       away_record_str = :away_record_str
                 WHERE id = :id
            ");
            $update->execute([
                ':game_date'            => $gameDate,
                ':start_time'           => $gameTime,
                ':start_time_local_str' => $startTimeLocalStr,
                ':start_time_epoch'     => $epoch,
                ':state'                => $state,
                ':final_message'        => $finalMessage,
                ':ncaa_url'             => $ncaaUrl,
                ':home_team_short'      => $homeShort,
                ':home_team_full'       => $homeFull,
                ':home_team_seo'        => $homeSeo,
                ':home_team_char6'      => $homeChar6,
                ':home_score'           => $homeScore,
                ':home_rank'            => $homeRank,
                ':home_record_str'      => $homeRecord,
                ':away_team_short'      => $awayShort,
                ':away_team_full'       => $awayFull,
                ':away_team_seo'        => $awaySeo,
                ':away_team_char6'      => $awayChar6,
                ':away_score'           => $awayScore,
                ':away_rank'            => $awayRank,
                ':away_record_str'      => $awayRecord,
                ':id'                   => $existingId,
            ]);

            log_msg("Updated ncaa_games for gameID {$gameId}");
        } else {
            $insert = $pdo->prepare("
                INSERT INTO ncaa_games (
                    game_id,
                    sport, division,
                    game_date,
                    start_time, start_time_local_str, start_time_epoch,
                    state, final_message,
                    ncaa_url,
                    home_team_short, home_team_full, home_team_seo, home_team_char6,
                    home_score, home_rank, home_record_str,
                    away_team_short, away_team_full, away_team_seo, away_team_char6,
                    away_score, away_rank, away_record_str
                ) VALUES (
                    :game_id,
                    'icehockey-men', 'd1',
                    :game_date,
                    :start_time, :start_time_local_str, :start_time_epoch,
                    :state, :final_message,
                    :ncaa_url,
                    :home_team_short, :home_team_full, :home_team_seo, :home_team_char6,
                    :home_score, :home_rank, :home_record_str,
                    :away_team_short, :away_team_full, :away_team_seo, :away_team_char6,
                    :away_score, :away_rank, :away_record_str
                )
            ");
            $insert->execute([
                ':game_id'              => $gameId,
                ':game_date'            => $gameDate,
                ':start_time'           => $gameTime,
                ':start_time_local_str' => $startTimeLocalStr,
                ':start_time_epoch'     => $epoch,
                ':state'                => $state,
                ':final_message'        => $finalMessage,
                ':ncaa_url'             => $ncaaUrl,
                ':home_team_short'      => $homeShort,
                ':home_team_full'       => $homeFull,
                ':home_team_seo'        => $homeSeo,
                ':home_team_char6'      => $homeChar6,
                ':home_score'           => $homeScore,
                ':home_rank'            => $homeRank,
                ':home_record_str'      => $homeRecord,
                ':away_team_short'      => $awayShort,
                ':away_team_full'       => $awayFull,
                ':away_team_seo'        => $awaySeo,
                ':away_team_char6'      => $awayChar6,
                ':away_score'           => $awayScore,
                ':away_rank'            => $awayRank,
                ':away_record_str'      => $awayRecord,
            ]);

            $newId = $pdo->lastInsertId();
            log_msg("Inserted ncaa_games for gameID {$gameId} (id={$newId})");
        }

        // -------- Boxscore upsert --------
        $boxscore = ncaa_hockey_boxscore($gameId);
        if (!$boxscore) {
            log_msg("  Boxscore missing for gameID {$gameId}");
        } else {
            $boxscoreJson = json_encode($boxscore);

            $bsSelect = $pdo->prepare("SELECT id FROM ncaa_boxscores WHERE game_id = :game_id");
            $bsSelect->execute([':game_id' => $gameId]);
            $bsExistingId = $bsSelect->fetchColumn();

            if ($bsExistingId) {
                $bsUpdate = $pdo->prepare("
                    UPDATE ncaa_boxscores
                       SET boxscore_json = :json,
                           fetched_at = NOW()
                     WHERE id = :id
                ");
                $bsUpdate->execute([
                    ':json' => $boxscoreJson,
                    ':id'   => $bsExistingId,
                ]);
                log_msg("  Updated boxscore for gameID {$gameId}");
            } else {
                $bsInsert = $pdo->prepare("
                    INSERT INTO ncaa_boxscores (game_id, boxscore_json, fetched_at)
                    VALUES (:game_id, :json, NOW())
                ");
                $bsInsert->execute([
                    ':game_id' => $gameId,
                    ':json'    => $boxscoreJson,
                ]);
                log_msg("  Inserted boxscore for gameID {$gameId}");
            }
        }

        // Optional per-game delay
        if ($GAME_DELAY_SEC > 0) {
            // PHP 7 on your box doesn't support 1_000_000 literal, so use plain 1000000
            $micro = (int) round($GAME_DELAY_SEC * 1000000);
            usleep($micro);
        }
    }

    // finished processing this date
    log_msg("Finished {$dateYmd}, sleeping {$DAY_DELAY_SEC} seconds before next day...");
    sleep($DAY_DELAY_SEC);

    $current->modify('+1 day');
}

log_msg('All done.');
