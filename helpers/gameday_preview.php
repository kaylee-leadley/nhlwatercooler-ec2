<?php
//======================================
// File: public/helpers/gameday_preview.php
// Description: Builds HTML game preview (form, H2H list, players, prediction)
// Requires: msf_games, msf_team_gamelogs, msf_player_gamelogs
//======================================

if (!function_exists('sjms_h')) {
  function sjms_h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* ==========================================================
 * Math helpers
 * ========================================================== */

if (!function_exists('sjms_poisson')) {
  // Knuth Poisson sampler
  function sjms_poisson($lambda) {
    if ($lambda <= 0) return 0;
    $L = exp(-$lambda);
    $k = 0;
    $p = 1.0;
    do {
      $k++;
      $p *= (mt_rand() / mt_getrandmax());
    } while ($p > $L);
    return $k - 1;
  }
}

/* ==========================================================
 * Data fetch helpers
 * ========================================================== */

if (!function_exists('sjms_team_last_game_ids')) {
  /**
   * Return last N msf_game_id for a team strictly before $asOfDate (Y-m-d).
   */
  function sjms_team_last_game_ids(PDO $pdo, $teamAbbr, $asOfDate, $nGames = 10) {
    $teamAbbr = strtoupper(trim((string)$teamAbbr));
    $asOfDate = (string)$asOfDate;
    $nGames   = max(1, (int)$nGames);

    $st = $pdo->prepare("
      SELECT g.msf_game_id
      FROM msf_team_gamelogs t
      JOIN msf_games g ON g.msf_game_id = t.msf_game_id
      WHERE t.team_abbr = :abbr
        AND g.game_date < :asof
      ORDER BY g.game_date DESC, g.msf_game_id DESC
      LIMIT {$nGames}
    ");
    $st->execute([':abbr' => $teamAbbr, ':asof' => $asOfDate]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!$ids) return [];
    return array_map('intval', $ids);
  }
}

if (!function_exists('sjms_team_form')) {
  /**
   * Team form summary over last N games.
   * PP% is computed as sum(pp_goals)/sum(pp_opps) across the window.
   * IMPORTANT: streak is computed separately so it does not truncate totals.
   */
  function sjms_team_form(PDO $pdo, $teamAbbr, $asOfDate, $nGames = 10) {
    $teamAbbr = strtoupper(trim((string)$teamAbbr));
    $asOfDate = (string)$asOfDate;
    $nGames   = max(1, (int)$nGames);

    $st = $pdo->prepare("
      SELECT g.game_date, g.home_team_abbr, g.away_team_abbr,
             t.goals_for, t.goals_against,
             t.powerplays, t.powerplay_goals,
             t.ot_losses, t.so_losses
      FROM msf_team_gamelogs t
      JOIN msf_games g ON g.msf_game_id = t.msf_game_id
      WHERE t.team_abbr = :abbr
        AND g.game_date < :asof
      ORDER BY g.game_date DESC, g.msf_game_id DESC
      LIMIT {$nGames}
    ");
    $st->execute([':abbr' => $teamAbbr, ':asof' => $asOfDate]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    // totals across ALL rows
    $W=0; $L=0; $OTL=0;
    $GF=0; $GA=0;
    $ppOpp=0; $ppG=0;

    foreach ($rows as $r) {
      $gf = (int)$r['goals_for'];
      $ga = (int)$r['goals_against'];
      $GF += $gf;
      $GA += $ga;

      $isOTL = ((int)$r['ot_losses'] === 1 || (int)$r['so_losses'] === 1);

      if ($gf > $ga) $W++;
      else if ($isOTL) $OTL++;
      else $L++;

      $ppOpp += (int)($r['powerplays'] ?? 0);
      $ppG   += (int)($r['powerplay_goals'] ?? 0);
    }

    $games = count($rows);
    $gfAvg = $games ? round($GF / $games, 2) : null;
    $gaAvg = $games ? round($GA / $games, 2) : null;
    $ppPct = ($ppOpp > 0) ? round(100.0 * $ppG / $ppOpp, 1) : null;

    // streak computed from most recent until flip
    $streakType = null; // 'W' or 'L'
    $streakLen  = 0;

    foreach ($rows as $i => $r) {
      $gf = (int)$r['goals_for'];
      $ga = (int)$r['goals_against'];

      // bucket: treat OTL as L for vibe
      $bucket = ($gf > $ga) ? 'W' : 'L';

      if ($i === 0) {
        $streakType = $bucket;
        $streakLen  = 1;
      } else {
        if ($bucket === $streakType) $streakLen++;
        else break;
      }
    }

    // last game details (most recent row)
    $last = $rows[0];
    $lastOpp = (strtoupper($last['home_team_abbr']) === $teamAbbr)
      ? strtoupper($last['away_team_abbr'])
      : strtoupper($last['home_team_abbr']);

    $lastIsOTL = ((int)$last['ot_losses'] === 1 || (int)$last['so_losses'] === 1);
    $lastRes   = ((int)$last['goals_for'] > (int)$last['goals_against']) ? 'W' : ($lastIsOTL ? 'OTL' : 'L');

    return [
      'abbr'      => $teamAbbr,
      'games'     => $games,
      'W'         => $W,
      'L'         => $L,
      'OTL'       => $OTL,
      'GF'        => $GF,
      'GA'        => $GA,
      'GF_avg'    => $gfAvg,
      'GA_avg'    => $gaAvg,
      'pp_opp'    => $ppOpp,
      'pp_goals'  => $ppG,
      'pp_pct'    => $ppPct,
      'streak'    => ($streakType ? ($streakType . $streakLen) : ''),
      'last_opp'  => $lastOpp,
      'last_gf'   => (int)$last['goals_for'],
      'last_ga'   => (int)$last['goals_against'],
      'last_res'  => $lastRes,
      'last_date' => $last['game_date'],
    ];
  }
}

if (!function_exists('sjms_h2h')) {
  /**
   * Returns list of recent meetings (most recent first).
   */
  function sjms_h2h(PDO $pdo, $awayAbbr, $homeAbbr, $asOfDate, $nGames = 5) {
    $awayAbbr = strtoupper(trim((string)$awayAbbr));
    $homeAbbr = strtoupper(trim((string)$homeAbbr));
    $asOfDate = (string)$asOfDate;
    $nGames   = max(1, (int)$nGames);

    $st = $pdo->prepare("
      SELECT
        g.game_date,
        g.home_team_abbr,
        g.away_team_abbr,
        th.goals_for AS home_gf,
        ta.goals_for AS away_gf,
        (CASE
          WHEN (th.ot_losses=1 OR th.so_losses=1 OR ta.ot_losses=1 OR ta.so_losses=1) THEN 1
          ELSE 0
        END) AS is_ot
      FROM msf_games g
      JOIN msf_team_gamelogs th ON th.msf_game_id = g.msf_game_id AND th.team_abbr = g.home_team_abbr
      JOIN msf_team_gamelogs ta ON ta.msf_game_id = g.msf_game_id AND ta.team_abbr = g.away_team_abbr
      WHERE g.game_date < :asof
        AND (
          (g.home_team_abbr = :home AND g.away_team_abbr = :away)
          OR
          (g.home_team_abbr = :away AND g.away_team_abbr = :home)
        )
      ORDER BY g.game_date DESC, g.msf_game_id DESC
      LIMIT {$nGames}
    ");
    $st->execute([':asof'=>$asOfDate, ':home'=>$homeAbbr, ':away'=>$awayAbbr]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;

    $meetings = [];
    foreach ($rows as $r) {
      $meetings[] = [
        'date' => $r['game_date'],
        'away' => strtoupper($r['away_team_abbr']),
        'home' => strtoupper($r['home_team_abbr']),
        'ag'   => (int)$r['away_gf'],
        'hg'   => (int)$r['home_gf'],
        'is_ot'=> ((int)$r['is_ot'] === 1),
      ];
    }

    return [
      'count'    => count($meetings),
      'meetings' => $meetings,
    ];
  }
}

if (!function_exists('sjms_players_to_watch')) {
  /**
   * Top players by points in the LAST $nGames team games before $asOfDate.
   */
  function sjms_players_to_watch(PDO $pdo, $teamAbbr, $asOfDate, $nGames = 5, $limit = 4) {
    $teamAbbr = strtoupper(trim((string)$teamAbbr));
    $asOfDate = (string)$asOfDate;
    $nGames   = max(1, (int)$nGames);
    $limit    = max(1, (int)$limit);

    $gameIds = sjms_team_last_game_ids($pdo, $teamAbbr, $asOfDate, $nGames);
    if (!$gameIds) return [];

    $ph = implode(',', array_fill(0, count($gameIds), '?'));

    $sql = "
      SELECT p.player_id, p.first_name, p.last_name,
             SUM(p.goals) AS g,
             SUM(p.assists) AS a,
             SUM(p.points) AS pts,
             SUM(p.shots) AS shots,
             COUNT(*) AS gp
      FROM msf_player_gamelogs p
      WHERE p.team_abbr = ?
        AND p.msf_game_id IN ($ph)
      GROUP BY p.player_id, p.first_name, p.last_name
      ORDER BY pts DESC, g DESC, shots DESC
      LIMIT {$limit}
    ";

    $params = array_merge([$teamAbbr], $gameIds);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return $rows ?: [];
  }
}

if (!function_exists('sjms_predict_poisson')) {
  function sjms_predict_poisson(array $awayForm, array $homeForm, $sims = 6000) {
    $sims = max(1000, (int)$sims);

    $awayGFpg = ($awayForm['games'] > 0 && $awayForm['GF_avg'] !== null) ? (float)$awayForm['GF_avg'] : 2.8;
    $awayGApg = ($awayForm['games'] > 0 && $awayForm['GA_avg'] !== null) ? (float)$awayForm['GA_avg'] : 2.8;
    $homeGFpg = ($homeForm['games'] > 0 && $homeForm['GF_avg'] !== null) ? (float)$homeForm['GF_avg'] : 2.8;
    $homeGApg = ($homeForm['games'] > 0 && $homeForm['GA_avg'] !== null) ? (float)$homeForm['GA_avg'] : 2.8;

    // Blend offense + opponent defense, small home bump
    $lambdaAway = max(0.5, 0.55*$awayGFpg + 0.45*$homeGApg);
    $lambdaHome = max(0.5, (0.55*$homeGFpg + 0.45*$awayGApg) * 1.05);

    // If regulation ends tied, decide OT/SO winner.
    // Use a simple strength-weighted chance with a tiny home bump.
    // (Keeps it stable + intuitive without needing extra OT modeling.)
    $pHomeOT = $lambdaHome / max(0.000001, ($lambdaHome + $lambdaAway));
    $pHomeOT = min(0.65, max(0.35, $pHomeOT + 0.02)); // clamp + small home edge

    $homeWin = 0;
    $awayWin = 0;
    $regTies = 0;
    $scoreCounts = [];

    for ($i = 0; $i < $sims; $i++) {
      $a = sjms_poisson($lambdaAway);
      $h = sjms_poisson($lambdaHome);

      // Keep "common scorelines" as regulation scorelines (still useful)
      $key = "{$a}-{$h}";
      $scoreCounts[$key] = ($scoreCounts[$key] ?? 0) + 1;

      if ($h > $a) {
        $homeWin++;
      } else if ($a > $h) {
        $awayWin++;
      } else {
        // Regulation tie -> OT/SO winner
        $regTies++;
        $r = mt_rand() / mt_getrandmax();
        if ($r < $pHomeOT) $homeWin++;
        else $awayWin++;
      }
    }

    arsort($scoreCounts);
    $top = array_slice($scoreCounts, 0, 3, true);

    // These now add to ~100 because ties are assigned to a winner
    $homeWinPct = round(100.0 * $homeWin / $sims, 1);
    $awayWinPct = round(100.0 * $awayWin / $sims, 1);

    // Optional info (if you want to display it)
    $regTiePct  = round(100.0 * $regTies / $sims, 1);
    $pHomeOTPct = round(100.0 * $pHomeOT, 1);

    return [
      'lambdaAway' => round($lambdaAway, 2),
      'lambdaHome' => round($lambdaHome, 2),

      // NOTE: win odds include OT/SO resolution
      'homeWinPct' => $homeWinPct,
      'awayWinPct' => $awayWinPct,

      // Extra fields you can optionally show in UI
      'regTiePct'  => $regTiePct,   // how often it went to OT/SO
      'pHomeOTPct' => $pHomeOTPct,  // home chance in OT/SO when tied

      'topScores'  => $top,
      'sims'       => $sims,
    ];
  }
}


/* ==========================================================
 * HTML builder
 * ========================================================== */

if (!function_exists('sjms_build_gameday_preview_html')) {
  function sjms_build_gameday_preview_html(PDO $pdo, $awayAbbr, $homeAbbr, $gameDate /* Y-m-d */) {
    $asOfDate = (string)$gameDate;

    $awayAbbr = strtoupper(trim((string)$awayAbbr));
    $homeAbbr = strtoupper(trim((string)$homeAbbr));

    $awayForm = sjms_team_form($pdo, $awayAbbr, $asOfDate, 10);
    $homeForm = sjms_team_form($pdo, $homeAbbr, $asOfDate, 10);
    $h2h      = sjms_h2h($pdo, $awayAbbr, $homeAbbr, $asOfDate, 5);

    $awayStars = $awayForm ? sjms_players_to_watch($pdo, $awayAbbr, $asOfDate, 5, 4) : [];
    $homeStars = $homeForm ? sjms_players_to_watch($pdo, $homeAbbr, $asOfDate, 5, 4) : [];

    $pred = ($awayForm && $homeForm) ? sjms_predict_poisson($awayForm, $homeForm, 6000) : null;

    if (!$awayForm && !$homeForm && !$h2h && !$awayStars && !$homeStars) {
      return '';
    }

    $awayN = $awayForm ? (int)$awayForm['games'] : 0;
    $homeN = $homeForm ? (int)$homeForm['games'] : 0;

    ob_start();
    ?>
    <hr class="gdp__rule">

    <section class="gdp gdp--nhl" aria-label="Game preview">
      <header class="gdp__hdr">
        <h3 class="gdp__title">Game Preview</h3>
      </header>

      <?php if ($awayForm && $homeForm): ?>
        <section class="gdp__section gdp__section--form">
          <div class="gdp__form-grid">
            <article class="gdp__team gdp__team--away">
              <h5 class="gdp__team-name">
                <span class="gdp__abbr"><?= sjms_h($awayAbbr) ?></span>
                <span class="gdp__meta">(last <?= $awayN ?>)</span>
              </h5>

              <ul class="gdp__list">
                <li class="gdp__item gdp__item--record">
                  <span class="gdp__k">Record</span>
                  <span class="gdp__v"><?= (int)$awayForm['W'] ?>-<?= (int)$awayForm['L'] ?>-<?= (int)$awayForm['OTL'] ?></span>
                </li>
                <li class="gdp__item gdp__item--streak">
                  <span class="gdp__k">Streak</span>
                  <span class="gdp__v"><?= sjms_h($awayForm['streak']) ?></span>
                </li>
                <li class="gdp__item gdp__item--gfga">
                  <span class="gdp__k">GF/GP · GA/GP</span>
                  <span class="gdp__v"><?= sjms_h($awayForm['GF_avg']) ?> · <?= sjms_h($awayForm['GA_avg']) ?></span>
                </li>
                <li class="gdp__item gdp__item--pp">
                  <span class="gdp__k">Power play</span>
                  <span class="gdp__v">
                    <?php if ($awayForm['pp_pct'] === null): ?>
                      —
                    <?php else: ?>
                      <?= (int)$awayForm['pp_goals'] ?>/<?= (int)$awayForm['pp_opp'] ?> (<?= sjms_h($awayForm['pp_pct']) ?>%)
                    <?php endif; ?>
                  </span>
                </li>
                <li class="gdp__item gdp__item--last">
                  <span class="gdp__k">Last game</span>
                  <span class="gdp__v"><?= sjms_h($awayForm['last_res']) ?> vs <?= sjms_h($awayForm['last_opp']) ?> (<?= (int)$awayForm['last_gf'] ?>–<?= (int)$awayForm['last_ga'] ?>)</span>
                </li>
              </ul>
            </article>

            <article class="gdp__team gdp__team--home">
              <h5 class="gdp__team-name">
                <span class="gdp__abbr"><?= sjms_h($homeAbbr) ?></span>
                <span class="gdp__meta">(last <?= $homeN ?>)</span>
              </h5>

              <ul class="gdp__list">
                <li class="gdp__item gdp__item--record">
                  <span class="gdp__k">Record</span>
                  <span class="gdp__v"><?= (int)$homeForm['W'] ?>-<?= (int)$homeForm['L'] ?>-<?= (int)$homeForm['OTL'] ?></span>
                </li>
                <li class="gdp__item gdp__item--streak">
                  <span class="gdp__k">Streak</span>
                  <span class="gdp__v"><?= sjms_h($homeForm['streak']) ?></span>
                </li>
                <li class="gdp__item gdp__item--gfga">
                  <span class="gdp__k">GF/GP · GA/GP</span>
                  <span class="gdp__v"><?= sjms_h($homeForm['GF_avg']) ?> · <?= sjms_h($homeForm['GA_avg']) ?></span>
                </li>
                <li class="gdp__item gdp__item--pp">
                  <span class="gdp__k">Power play</span>
                  <span class="gdp__v">
                    <?php if ($homeForm['pp_pct'] === null): ?>
                      —
                    <?php else: ?>
                      <?= (int)$homeForm['pp_goals'] ?>/<?= (int)$homeForm['pp_opp'] ?> (<?= sjms_h($homeForm['pp_pct']) ?>%)
                    <?php endif; ?>
                  </span>
                </li>
                <li class="gdp__item gdp__item--last">
                  <span class="gdp__k">Last game</span>
                  <span class="gdp__v"><?= sjms_h($homeForm['last_res']) ?> vs <?= sjms_h($homeForm['last_opp']) ?> (<?= (int)$homeForm['last_gf'] ?>–<?= (int)$homeForm['last_ga'] ?>)</span>
                </li>
              </ul>
            </article>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($h2h && !empty($h2h['meetings'])): ?>
        <section class="gdp__section gdp__section--h2h">
          <h4 class="gdp__h4">Recent meetings:</h4>

          <ul class="gdp__h2h-list">
            <?php foreach ($h2h['meetings'] as $m): ?>
              <li class="gdp__h2h-item">
                <span class="gdp__h2h-date"><?= sjms_h($m['date']) ?></span>
                <span class="gdp__h2h-score">
                  <?= sjms_h($m['away']) ?> <?= (int)$m['ag'] ?>
                  <span class="gdp__h2h-at">@</span>
                  <?= sjms_h($m['home']) ?> <?= (int)$m['hg'] ?>
                </span>
                <?php if (!empty($m['is_ot'])): ?>
                  <span class="gdp__chip gdp__chip--ot">OT/SO</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($awayStars || $homeStars): ?>
        <section class="gdp__section gdp__section--stars">
          <h4 class="gdp__h4">Players to watch <span class="gdp__muted">(last 5 team games):</span></h4>

          <div class="gdp__stars">
            <?php if ($awayStars): ?>
              <div class="gdp__stars-team gdp__stars-team--away">
                <h5 class="gdp__team-mini"><?= sjms_h($awayAbbr) ?></h5>
                <ul class="gdp__stars-list">
                  <?php foreach ($awayStars as $p): ?>
                    <li class="gdp__star">
                      <span class="gdp__star-name"><?= sjms_h(trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''))) ?></span>
                      <span class="gdp__star-line"><?= (int)$p['pts'] ?> pts · <?= (int)$p['g'] ?> G · <?= (int)$p['shots'] ?> SOG</span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($homeStars): ?>
              <div class="gdp__stars-team gdp__stars-team--home">
                <h5 class="gdp__team-mini"><?= sjms_h($homeAbbr) ?></h5>
                <ul class="gdp__stars-list">
                  <?php foreach ($homeStars as $p): ?>
                    <li class="gdp__star">
                      <span class="gdp__star-name"><?= sjms_h(trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''))) ?></span>
                      <span class="gdp__star-line"><?= (int)$p['pts'] ?> pts · <?= (int)$p['g'] ?> G · <?= (int)$p['shots'] ?> SOG</span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($pred): ?>
        <section class="gdp__section gdp__section--pred">
          <h4 class="gdp__h4">Prediction:</h4>
          <p class="gdp__p gdp__p--pred">
            <span class="gdp__pred-xg">
              Expected goals: <strong><?= sjms_h($awayAbbr) ?></strong> <?= sjms_h($pred['lambdaAway']) ?> ·
              <strong><?= sjms_h($homeAbbr) ?></strong> <?= sjms_h($pred['lambdaHome']) ?>
            </span>
            <span class="gdp__pred-odds">
              · Win odds: <?= sjms_h($awayAbbr) ?> <?= sjms_h($pred['awayWinPct']) ?>% · <?= sjms_h($homeAbbr) ?> <?= sjms_h($pred['homeWinPct']) ?>%
            </span>
            <?php if (isset($pred['regTiePct'])): ?>
              <span class="gdp__pred-ot">
                · OT/SO chance: <?= sjms_h($pred['regTiePct']) ?>%
              </span>
            <?php endif; ?>
          </p>

          <?php $topTotal = array_sum($pred['topScores']); ?>
          <ul class="gdp__scores">
            <?php foreach ($pred['topScores'] as $k => $ct): ?>
              <li class="gdp__score">
                <span class="gdp__scoreline"><?= sjms_h($k) ?></span>
                <span class="gdp__scorepct"><?= sjms_h(round(100 * $ct / max(1, (int)$topTotal), 1)) ?>%</span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

    </section>
    <?php
    return trim(ob_get_clean());
  }
}
