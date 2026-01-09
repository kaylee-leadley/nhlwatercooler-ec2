<?php
// public/includes/team_map.php
//
// Canonical NHL team mapping for DailyFaceoff slugs.
// Use ABBR everywhere in UI, and convert to/from slugs only for querying DFO tables.

if (!function_exists('sjms_team_maps')) {
  function sjms_team_maps(): array {
    static $maps = null;
    if ($maps !== null) return $maps;

    // ABBR => slug
    $abbrToSlug = [
      'ANA' => 'anaheim-ducks',
      'ARI' => 'arizona-coyotes', // historical (if you ever hit old data)
      'BOS' => 'boston-bruins',
      'BUF' => 'buffalo-sabres',
      'CAR' => 'carolina-hurricanes',
      'CBJ' => 'columbus-blue-jackets',
      'CGY' => 'calgary-flames',
      'CHI' => 'chicago-blackhawks',
      'COL' => 'colorado-avalanche',
      'DAL' => 'dallas-stars',
      'DET' => 'detroit-red-wings',
      'EDM' => 'edmonton-oilers',
      'FLO' => 'florida-panthers',
      'LAK' => 'los-angeles-kings',
      'MIN' => 'minnesota-wild',
      'MTL' => 'montreal-canadiens',
      'NJD' => 'new-jersey-devils',
      'NSH' => 'nashville-predators',
      'NYI' => 'new-york-islanders',
      'NYR' => 'new-york-rangers',
      'OTT' => 'ottawa-senators',
      'PHI' => 'philadelphia-flyers',
      'PIT' => 'pittsburgh-penguins',
      'SEA' => 'seattle-kraken',
      'SJS' => 'san-jose-sharks',
      'STL' => 'st-louis-blues',
      'TBL' => 'tampa-bay-lightning',
      'TOR' => 'toronto-maple-leafs',
      'VAN' => 'vancouver-canucks',
      'VGK' => 'vegas-golden-knights',
      'WPJ' => 'winnipeg-jets',
      'WSH' => 'washington-capitals',
      'UTA' => 'utah-hockey-club',
    ];

    // slug => ABBR (auto-built, but you can override below if needed)
    $slugToAbbr = [];
    foreach ($abbrToSlug as $abbr => $slug) {
      $slugToAbbr[$slug] = $abbr;
    }

    // OPTIONAL: manual overrides if DFO ever changes a slug
    // $slugToAbbr['utah-hc'] = 'UTA';

    $maps = [
      'abbr_to_slug' => $abbrToSlug,
      'slug_to_abbr' => $slugToAbbr,
    ];
    return $maps;
  }
}

if (!function_exists('sjms_team_abbr_to_slug')) {
  function sjms_team_abbr_to_slug(string $abbr): string {
    $abbr = strtoupper(trim($abbr));
    if ($abbr === '') return '';
    $maps = sjms_team_maps();
    // If unknown, fail “soft” by returning empty (so you don’t query garbage).
    return $maps['abbr_to_slug'][$abbr] ?? '';
  }
}

if (!function_exists('sjms_team_slug_to_abbr')) {
  function sjms_team_slug_to_abbr(string $slug): string {
    $slug = strtolower(trim($slug));
    if ($slug === '') return '';
    $maps = sjms_team_maps();
    // If unknown, fall back to uppercasing the slug (you’ll notice fast in UI/logs)
    return $maps['slug_to_abbr'][$slug] ?? strtoupper($slug);
  }
}
