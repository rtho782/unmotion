#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner

# Never put unvalidated strings into Bash arithmetic: even variable references
# can be recursively evaluated there. Normalize leading zeroes without octal
# interpretation, and compare decimal strings before conversion to signed int64.
unm_uint(){
  local value="${1:-}" maximum="${2:-9223372036854775807}" LC_ALL=C
  [[ "$value" =~ ^[0-9]+$ && ${#value} -le 128 ]] || { echo 'Invalid unsigned decimal value' >&2; return 1; }
  value="${value#"${value%%[!0]*}"}"; value="${value:-0}"
  [[ "$maximum" =~ ^(0|[1-9][0-9]*)$ && ${#maximum} -le 19 ]] || return 1
  [[ ${#maximum} -lt 19 || "$maximum" < 9223372036854775808 ]] || return 1
  [[ ${#value} -lt ${#maximum} || ( ${#value} -eq ${#maximum} && ( "$value" == "$maximum" || "$value" < "$maximum" ) ) ]] || { echo 'Unsigned decimal value exceeds supported range' >&2; return 1; }
  printf '%s' "$value"
}

unm_uint_add(){
  local left right
  left="$(unm_uint "$1")" && right="$(unm_uint "$2")" || return 1
  ((left<=9223372036854775807-right)) || { echo 'Unsigned decimal addition overflow' >&2; return 1; }
  printf '%s' "$((left+right))"
}

unm_uint_multiply(){
  local left right
  left="$(unm_uint "$1")" && right="$(unm_uint "$2")" || return 1
  ((right==0 || left<=9223372036854775807/right)) || { echo 'Unsigned decimal multiplication overflow' >&2; return 1; }
  printf '%s' "$((left*right))"
}

# Parse exactly one successful `stat -f -c '%d %a %S'` response. Do not use
# process substitution here: it hides the SSH/stat command's failure status.
unm_stat_available_bytes(){
  local response="$1" inodes blocks block_size
  [[ "$response" =~ ^([0-9]+)[[:blank:]]+([0-9]+)[[:blank:]]+([0-9]+)$ ]] || { echo 'Invalid filesystem capacity response' >&2; return 1; }
  inodes="${BASH_REMATCH[1]}"; blocks="${BASH_REMATCH[2]}"; block_size="${BASH_REMATCH[3]}"
  unm_uint "$inodes" >/dev/null || return 1
  block_size="$(unm_uint "$block_size")" || return 1
  [[ "$block_size" != 0 ]] || { echo 'Invalid zero filesystem block size' >&2; return 1; }
  unm_uint_multiply "$blocks" "$block_size"
}

# Progress is advisory. Ignore malformed values; cap valid overshoot at 100.
unm_progress_percent(){
  local value
  value="$(unm_uint "$1")" || return 1
  ((value<=100)) || value=100
  printf '%s' "$value"
}
