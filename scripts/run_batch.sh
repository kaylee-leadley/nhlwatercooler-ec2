#!/usr/bin/env bash
set -Eeuo pipefail
umask 002

# ============================================================
# FORCE THIS SCRIPT TO RUN IN EASTERN TIME (EST/EDT)
# Fixes "tomorrow" date issues when server/system is UTC.
# ============================================================
export TZ="America/New_York"

BASE_DIR="/var/www/nhlwatercooler/public/scripts"
LOG_DIR="${BASE_DIR}/gameday-logs"
STATE_DIR="${LOG_DIR}/state"
PHP="/usr/bin/php"

mkdir -p "$LOG_DIR" "$STATE_DIR"
BATCH_LOG="${LOG_DIR}/batch.log"
LOCK_FILE="${STATE_DIR}/run_batch.lock"

cd "$BASE_DIR" || exit 1

# ============================================================
# LOG TRIMMING: keep only last N lines in batch.log
# ============================================================
MAX_LOG_LINES=500

trim_log() {
  [[ -f "$BATCH_LOG" ]] || return 0

  local n
  n="$(wc -l < "$BATCH_LOG" 2>/dev/null || echo 0)"
  (( n > MAX_LOG_LINES )) || return 0

  local tmp
  tmp="$(mktemp "${STATE_DIR}/batch.log.trim.XXXXXX")"
  tail -n "$MAX_LOG_LINES" "$BATCH_LOG" > "$tmp" && mv "$tmp" "$BATCH_LOG"
}

# Dates (now Eastern because TZ is exported)
TODAY="$(date +%F)"                           # YYYY-MM-DD
YESTERDAY="$(date -d 'yesterday' +%F 2>/dev/null || date -v-1d +%F)"  # linux/mac fallback

ts() { date '+%Y-%m-%d %H:%M:%S'; }   # Eastern (TZ exported)
epoch() { date +%s; }                 # epoch is epoch (timezone-independent)

# ===== ET helpers =====
et_hour() { TZ="America/New_York" date +%H; }         # 00..23
et_now_label() { TZ="America/New_York" date '+%F %T'; }
#=== Interval Label ===
interval_label() {
  local s="$1"
  if (( s % 3600 == 0 )); then
    echo "$(( s / 3600 ))h"
  else
    echo "$(( s / 60 ))min"
  fi
}
# Cross-platform: subtract N days from a base YYYY-MM-DD date.
date_minus_days() {
  local base="$1"  # YYYY-MM-DD
  local days="$2"  # integer

  if date -d "${base} -${days} days" +%F >/dev/null 2>&1; then
    date -d "${base} -${days} days" +%F
  else
    date -j -f "%F" "$base" -v-"${days}"d +%F
  fi
}

log() {
  echo "[$(ts)] $*" >> "$BATCH_LOG"
  trim_log
}

hr() { log "------------------------------------------------------------"; }

section() {
  hr
  log "$*"
  hr
}

# Prevent overlapping runs (cron safety)
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "SKIP: another run_batch.sh is already running (lock=$LOCK_FILE)"
  exit 0
fi

cleanup_done_files() {
  local deleted
  deleted="$(find "$BASE_DIR" -maxdepth 1 -type f -name "*.done" -print -delete 2>/dev/null || true)"
  if [[ -n "$deleted" ]]; then
    while IFS= read -r f; do
      [[ -n "$f" ]] && log "CLEAN: deleted $(basename "$f")"
    done <<< "$deleted"
  else
    log "CLEAN: no .done files"
  fi
}

run_php() {
  local script="$1"; shift
  local label="$1"; shift

  if [[ ! -f "$script" ]]; then
    log "SKIP: $label (missing file: $BASE_DIR/$script)"
    return 0
  fi

  local start end dur ec tmp
  tmp="$(mktemp "${STATE_DIR}/tmp.${script//\//_}.XXXXXX")"
  start="$(epoch)"

  log "RUN : $label"
  log "CMD : $PHP $BASE_DIR/$script $*"

  set +e
  "$PHP" "$script" "$@" 2>&1 | tee -a "$BATCH_LOG" > "$tmp"
  trim_log
  ec=${PIPESTATUS[0]}
  set -e

  end="$(epoch)"
  dur=$(( end - start ))

  if [[ $ec -eq 0 ]]; then
    log "OK  : $label (${dur}s)"
  else
    log "FAIL: $label exit=$ec (${dur}s)  (continuing batch)"
  fi

  if [[ "$label" =~ lineups ]]; then
    if grep -Eqi 'no lineups|0 lineups|0 games|no games|nothing to do|empty' "$tmp"; then
      log "NO DATA: lineups ($*)"
    fi
  fi

  if [[ "$label" =~ injuries ]]; then
    if grep -Eqi 'no injuries|0 injuries|0 games|no games|nothing to do|empty' "$tmp"; then
      log "NO DATA: injuries ($*)"
    fi
  fi

  if [[ "$label" =~ boxscores ]]; then
    if grep -Eqi 'no games|0 games|nothing to import|empty' "$tmp"; then
      log "NO DATA: boxscores ($*)"
    fi
  fi

  rm -f "$tmp" || true
  return 0
}

# Frequency helpers (stamp files)
stamp_path_daily() {
  local key="$1"
  echo "${STATE_DIR}/${key}.${TODAY}.stamp"
}
stamp_path_every_hours() {
  local key="$1"
  echo "${STATE_DIR}/${key}.stamp"
}
stamp_mtime_epoch() {
  local f="$1"
  stat -c %Y "$f" 2>/dev/null || stat -f %m "$f" 2>/dev/null || echo 0
}

should_run_daily() {
  local key="$1"
  local stamp
  stamp="$(stamp_path_daily "$key")"
  [[ ! -f "$stamp" ]]
}
mark_ran_daily() {
  local key="$1"
  local stamp
  stamp="$(stamp_path_daily "$key")"
  echo "$(ts)" > "$stamp"
}

should_run_every_seconds() {
  local key="$1"
  local interval="$2"
  local stamp
  stamp="$(stamp_path_every_hours "$key")"

  if [[ ! -f "$stamp" ]]; then
    return 0
  fi

  local last now age
  last="$(stamp_mtime_epoch "$stamp")"
  now="$(epoch)"
  age=$(( now - last ))
  (( age >= interval ))
}

mark_ran_every_hours() {
  local key="$1"
  local stamp
  stamp="$(stamp_path_every_hours "$key")"
  echo "$(ts)" > "$stamp"
}

# ===== Dynamic boxscore gate schedule (ET-based) =====
boxscore_interval_seconds() {
  local h
  h="$(et_hour)"

  # 15:00-23:59 OR 00:00-01:59 => every 1 hour (3pm -> 2am ET)
  if (( h >= 15 || h < 2 )); then
    echo 3600     # 1 hour
  else
    echo 10800    # 3 hours
  fi
}

# ===== PBP gate schedule (ET-based) =====
# Requirement:
#   "Run up to 2am every 15 minutes" and specifically "between 10pm and 2am"
pbp_interval_seconds() {
  local h
  h="$(et_hour)"

  # 22:00-23:59 OR 00:00-01:59 => every 15 minutes
  if (( h >= 20 || h < 2 )); then
    echo 900
  # 15:00-21:59 => every 1 hour
  elif (( h >= 15 )); then
    echo 3600
  else
    # Outside window: reduce churn
    echo 10800
  fi
}

log "==== RUN_BATCH START ===="
log "CTX : host=$(hostname) user=$(whoami) pwd=$BASE_DIR"
log "DATE: today=$TODAY yesterday=$YESTERDAY tz=$TZ"
log "LOG : $BATCH_LOG"

BATCH_START="$(epoch)"

############################################################
# YOUR REQUIRED ORDER
############################################################

#======================================
# File: cron_import_ncaa_games.php  Description:
#   Import NCAA games for a given date (today + yesterday).
#======================================
section "1) NCAA import games"
run_php "cron_import_ncaa_games.php" "NCAA import games (today)" "$TODAY"
cleanup_done_files
run_php "cron_import_ncaa_games.php" "NCAA import games (yesterday)" "$YESTERDAY"
cleanup_done_files

#======================================
# File: cron_import_ncaa_rankings.php  Description:
#   Import NCAA rankings.
#======================================
section "2) NCAA import rankings"
run_php "cron_import_ncaa_rankings.php" "NCAA import rankings"
cleanup_done_files

#======================================
# File: cron_update_ncaa_gamelogs.php  Description:
#   Update NCAA gamelogs (today + yesterday).
#======================================
section "3) NCAA update gamelogs"
run_php "cron_update_ncaa_gamelogs.php" "NCAA update gamelogs (today)" "$TODAY"
cleanup_done_files
run_php "cron_update_ncaa_gamelogs.php" "NCAA update gamelogs (yesterday)" "$YESTERDAY"
cleanup_done_files

#======================================
# File: cron_create_ncaa_threads.php / cron_create_gameday_threads.php  Description:
#   Create daily NCAA + NHL threads (daily gate).
#======================================
section "4/6) Create threads (daily gate: NCAA then NHL)"
if should_run_daily "create_threads"; then
  log "GATE: create_threads -> eligible (no stamp for today)"
  run_php "cron_create_ncaa_threads.php" "NCAA create threads (daily)" "$TODAY"
  cleanup_done_files

  #======================================
  # File: msf_import_season.php  Description:
  #   Sep 1 trigger: import season data.
  #======================================
  if [[ "$(date +%m-%d)" == "09-01" ]]; then
    section "5) MSF import season (Sep 1 trigger)"
    run_php "msf_import_season.php" "MSF import season (Sep 1)" || true
    cleanup_done_files
  else
    log "SKIP: MSF import season (only runs on Sep 1; today is $(date +%m-%d))"
  fi

  run_php "cron_create_gameday_threads.php" "NHL create gameday threads (daily)" "$TODAY"
  cleanup_done_files

  mark_ran_daily "create_threads"
  log "STAMP: create_threads set -> $(stamp_path_daily "create_threads")"
else
  log "GATE: create_threads -> SKIP (already ran today; stamp=$(stamp_path_daily "create_threads"))"
fi

#======================================
# File: msf_import_daily_boxscores.php  Description:
#   Dynamic ET gate; backfill 7 days + today.
#======================================
section "7/8) MSF import daily boxscores (dynamic ET gate)"
STAMP_BS="$(stamp_path_every_hours "msf_daily_boxscores")"

INTERVAL="$(boxscore_interval_seconds)"
INTERVAL_LABEL="$(( INTERVAL / "$INTERVAL" ))h"
ET_HOUR="$(et_hour)"

log "GATE: msf_daily_boxscores window check -> ET now=$(et_now_label) (hour=$ET_HOUR) interval=$INTERVAL_LABEL"

if should_run_every_seconds "msf_daily_boxscores" "$INTERVAL"; then
  if [[ -f "$STAMP_BS" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_BS")"
    log "GATE: msf_daily_boxscores -> eligible (last=$(date -d "@$last" '+%F %T' 2>/dev/null || echo "$last"))"
  else
    log "GATE: msf_daily_boxscores -> eligible (no stamp yet)"
  fi

  for back in 7 6 5 4 3 2 1 0; do
    d="$(date_minus_days "$TODAY" "$back")"
    run_php "msf_import_daily_boxscores.php" "MSF import daily boxscores ($d)" "$d"
    cleanup_done_files
  done

  mark_ran_every_hours "msf_daily_boxscores"
  log "STAMP: msf_daily_boxscores set -> $STAMP_BS"
else
  if [[ -f "$STAMP_BS" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_BS")"
    now="$(epoch)"
    age=$(( now - last ))
    next=$(( last + INTERVAL ))
    log "GATE: msf_daily_boxscores -> SKIP (age=${age}s; next eligible at $(date -d "@$next" '+%F %T' 2>/dev/null || echo "$next"))"
  else
    log "GATE: msf_daily_boxscores -> SKIP (unexpected: stamp missing but gate said no)"
  fi
fi

#======================================
# File: cron_import_msf_pbp.php  Description:
#   Dynamic ET gate; backfill 3 days + today.
#   10pm-2am ET => every 15 minutes.
#======================================
section "8.5) MSF play-by-play import (dynamic ET gate)"
STAMP_PBP="$(stamp_path_every_hours "msf_pbp")"

INTERVAL_PBP="$(pbp_interval_seconds)"
INTERVAL_PBP_LABEL="$(( INTERVAL_PBP / 60 ))min"
ET_HOUR="$(et_hour)"

log "GATE: msf_pbp window check -> ET now=$(et_now_label) (hour=$ET_HOUR) interval=${INTERVAL_PBP_LABEL}"

if should_run_every_seconds "msf_pbp" "$INTERVAL_PBP"; then
  if [[ -f "$STAMP_PBP" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_PBP")"
    log "GATE: msf_pbp -> eligible (last=$(date -d "@$last" '+%F %T' 2>/dev/null || echo "$last"))"
  else
    log "GATE: msf_pbp -> eligible (no stamp yet)"
  fi

  for back in 3 2 1 0; do
    d="$(date_minus_days "$TODAY" "$back")"
    run_php "cron_import_msf_pbp.php" "MSF PBP import ($d)" "$d"
    cleanup_done_files
  done

  mark_ran_every_hours "msf_pbp"
  log "STAMP: msf_pbp set -> $STAMP_PBP"
else
  if [[ -f "$STAMP_PBP" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_PBP")"
    now="$(epoch)"
    age=$(( now - last ))
    next=$(( last + INTERVAL_PBP ))
    log "GATE: msf_pbp -> SKIP (age=${age}s; next eligible at $(date -d "@$next" '+%F %T' 2>/dev/null || echo "$next"))"
  else
    log "GATE: msf_pbp -> SKIP (unexpected: stamp missing but gate said no)"
  fi
fi

#======================================
# File: cron_build_player_adv_stats.php  Description:
#   Precompute player advanced stats into nhl_players_advanced_stats (stable reproduction).
#   Runs every 6 hours.
#======================================
section "8.75) Build player advanced stats (gated every 6h)"
STAMP_ADV="$(stamp_path_every_hours "build_adv_stats")"
ADV_INTERVAL=21600  # 6h

if should_run_every_seconds "build_adv_stats" "$ADV_INTERVAL"; then
  log "GATE: build_adv_stats -> eligible"
  run_php "cron_build_player_adv_stats.php" "Build player advanced stats" --days-old=5 --max-games=10 --verbose
  cleanup_done_files
  mark_ran_every_hours "build_adv_stats"
  log "STAMP: build_adv_stats set -> $STAMP_ADV"
else
  if [[ -f "$STAMP_ADV" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_ADV")"
    now="$(epoch)"
    age=$(( now - last ))
    next=$(( last + ADV_INTERVAL ))
    log "GATE: build_adv_stats -> SKIP (age=${age}s; next eligible at $(date -d "@$next" '+%F %T' 2>/dev/null || echo "$next"))"
  else
    log "GATE: build_adv_stats -> SKIP (unexpected: stamp missing but gate said no)"
  fi
fi

#======================================
# File: cron_delete_old_nhl_pbp.php  Description:
#   Purge old PBP rows (default >10 days) to keep db lean.
#   Runs every 12 hours.
#======================================
section "8.9) Purge old NHL PBP (gated every 12h)"
STAMP_PURGE="$(stamp_path_every_hours "purge_pbp")"
PURGE_INTERVAL=43200  # 12h

if should_run_every_seconds "purge_pbp" "$PURGE_INTERVAL"; then
  log "GATE: purge_pbp -> eligible"
  run_php "cron_delete_old_nhl_pbp.php" "PBP purge (>10 days)" --days=10 --limit=200 --verbose
  cleanup_done_files
  mark_ran_every_hours "purge_pbp"
  log "STAMP: purge_pbp set -> $STAMP_PURGE"
else
  if [[ -f "$STAMP_PURGE" ]]; then
    last="$(stamp_mtime_epoch "$STAMP_PURGE")"
    now="$(epoch)"
    age=$(( now - last ))
    next=$(( last + PURGE_INTERVAL ))
    log "GATE: purge_pbp -> SKIP (age=${age}s; next eligible at $(date -d "@$next" '+%F %T' 2>/dev/null || echo "$next"))"
  else
    log "GATE: purge_pbp -> SKIP (unexpected: stamp missing but gate said no)"
  fi
fi

#======================================
# File: msf_injuries_daily.php  Description:
#   Daily injuries import (always).
#======================================
section "9) MSF injuries (always)"
run_php "msf_injuries_daily.php" "MSF injuries (today)" "$TODAY"
cleanup_done_files

#======================================
# File: msf_lineups_daily.php  Description:
#   Daily lineups import (always).
#======================================
section "10) MSF lineups (always)"
run_php "msf_lineups_daily.php" "MSF lineups (today)" "$TODAY"
cleanup_done_files

#======================================
# File: cron_import_dailyfaceoff_lines.php  Description:
#   DailyFaceoff lines import (always).
#======================================
section "11) DailyFaceoff lines import (ALWAYS)"
STAMP_DF="$(stamp_path_every_hours "df_lines")"

log "GATE: df_lines -> RUN (gate disabled; always execute)"
run_php "cron_import_dailyfaceoff_lines.php" "DailyFaceoff lines import (today)" --date="$TODAY"
cleanup_done_files

mark_ran_every_hours "df_lines"
log "STAMP: df_lines set -> $STAMP_DF"

#======================================
# File: cron_import_dfo_starting_goalies.php  Description:
#   DailyFaceoff starting goalies import (always).
#======================================
section "12) DailyFaceoff starting goalies import (ALWAYS)"
STAMP_DF_G="$(stamp_path_every_hours "df_goalies")"

log "GATE: df_goalies -> RUN (gate disabled; always execute)"
run_php "cron_import_dfo_starting_goalies.php" "DailyFaceoff starting goalies import (today)" --date="$TODAY"
cleanup_done_files

mark_ran_every_hours "df_goalies"
log "STAMP: df_goalies set -> $STAMP_DF_G"

BATCH_END="$(epoch)"
TOTAL=$(( BATCH_END - BATCH_START ))

log "==== RUN_BATCH END (total=${TOTAL}s) ===="

exit 0
