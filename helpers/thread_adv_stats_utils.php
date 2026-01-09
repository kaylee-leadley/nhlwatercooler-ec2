<?php
//======================================
// File: public/helpers/thread_adv_stats_utils.php
// Description: Tiny utilities used across advanced stats helpers.
// PHP 7+ friendly.
//======================================

if (!function_exists('sjms_as_int')) {
  function sjms_as_int($v, $d = 0) { return is_numeric($v) ? (int)$v : (int)$d; }
}
if (!function_exists('sjms_as_float')) {
  function sjms_as_float($v, $d = null) { return is_numeric($v) ? (float)$v : $d; }
}
if (!function_exists('sjms_h')) {
  function sjms_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sjms_norm_space')) {
  function sjms_norm_space($s) {
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return trim($s);
  }
}
if (!function_exists('sjms_player_name_from_row')) {
  function sjms_player_name_from_row($r) {
    $first = isset($r['first_name']) ? trim((string)$r['first_name']) : '';
    $last  = isset($r['last_name'])  ? trim((string)$r['last_name'])  : '';
    $nm = trim($first . ' ' . $last);
    return $nm !== '' ? $nm : ('Player ' . sjms_as_int($r['player_id'] ?? 0));
  }
}
if (!function_exists('sjms_fmt_pct')) {
  function sjms_fmt_pct($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return number_format((float)$v, 1);
  }
}
if (!function_exists('sjms_fmt_signed')) {
  function sjms_fmt_signed($v, $dec = 2) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    $s = number_format($f, $dec);
    if ($f > 0) $s = '+' . $s;
    return $s;
  }
}
if (!function_exists('sjms_percentile')) {
  function sjms_percentile(array $arr, $p) {
    $n = count($arr);
    if ($n === 0) return null;
    sort($arr, SORT_NUMERIC);

    if ($p <= 0) return $arr[0];
    if ($p >= 1) return $arr[$n - 1];

    $pos = ($n - 1) * $p;
    $lo = (int)floor($pos);
    $hi = (int)ceil($pos);
    if ($lo === $hi) return $arr[$lo];

    $t = $pos - $lo;
    return $arr[$lo] + ($arr[$hi] - $arr[$lo]) * $t;
  }
}