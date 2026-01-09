<?php

// Adjust if you change the IP/port:
if (!defined('NCAA_API_BASE')) {
    define('NCAA_API_BASE', 'http://127.0.0.1:3000');
}

/**
 * Generic GET helper for your NCAA API.
 */
function ncaa_api_get($path) {
    $base = rtrim(NCAA_API_BASE, '/');
    $url  = $base . '/' . ltrim($path, '/');

    $opts = [
        'http' => [
            'method'  => 'GET',
            'timeout' => 2,
            'header'  => [
                // If you configure NCAA_HEADER_KEY in ncaa-api.service, add:
                // 'x-ncaa-key: supersecretkey',
            ],
        ],
    ];

    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        //error_log("NCAA API fetch failed: $url");
        return null;
    } else {
      //  error_log("NCAA API fetch Success: $url");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        error_log("NCAA API non-JSON response for $url: " . substr($json, 0, 200));
        return null;
    }

    return $data;
}

/**
 * Scoreboard for DI Men's hockey for a given date (Y-m-d).
 */
function ncaa_hockey_scoreboard($dateYmd) {
    $dt = DateTime::createFromFormat('Y-m-d', $dateYmd);
    if (!$dt) {
        return null;
    }

    $year  = $dt->format('Y');
    $month = $dt->format('m');
    $day   = $dt->format('d');

    $path = sprintf('scoreboard/icehockey-men/d1/%s/%s/%s/all-conf',
        $year,
        $month,
        $day
    );

    $data = ncaa_api_get($path);
    if (!$data || !isset($data['games']) || !is_array($data['games'])) {
        return null;
    }

    return $data;
}

/**
 * Boxscore JSON for a single gameID.
 */
function ncaa_hockey_boxscore($gameId) {
    $path = sprintf('game/%s/boxscore', $gameId);
    $data = ncaa_api_get($path);
    if (!$data) {
        return null;
    }
    return $data;
}

/**
 * Schools index (for team filter dropdown, etc.)
 *
 * Returns an array like:
 * [
 *   ["slug" => "alas-fairbanks", "name" => "Alas. Fairbanks", "long" => "University of Alaska-Fairbanks"],
 *   ...
 * ]
 */
function ncaa_schools_index() {
    $data = ncaa_api_get('schools-index');
    if (!is_array($data)) {
        return null;
    }
    return $data;
}
