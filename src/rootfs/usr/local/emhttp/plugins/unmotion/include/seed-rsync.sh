#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner

# Read only the two sender-side --stats fields captured by the progress parser.
# One explicit regular-file argument must describe exactly one file, including
# when rsync's quick check decides that no data needs transferring.
unm_seed_rsync_size(){
  local report="$1" line files=0 sizes=0 size=''
  while IFS= read -r line; do
    if [[ "$line" == 'Number of files: 1 (reg: 1)' ]]; then
      ((files+=1))
    elif [[ "$line" =~ ^Total\ file\ size:\ ([0-9]+)\ bytes$ ]]; then
      ((sizes+=1)); size="${BASH_REMATCH[1]}"
    else
      echo 'Invalid single-file rsync statistics' >&2; return 1
    fi
  done < "$report"
  [[ "$files" == 1 && "$sizes" == 1 ]] || { echo 'Missing or duplicate single-file rsync statistics' >&2; return 1; }
  unm_uint "$size"
}

unm_seed_rsync_verify(){
  local sent received before after mode="$5"
  sent="$(unm_uint "$1")" && received="$(unm_uint "$2")" && before="$(unm_uint "$3")" && after="$(unm_uint "$4")" || return 1
  [[ "$mode" == online || "$mode" == offline ]] || return 1
  [[ "$received" == "$sent" ]] || { echo 'Seed destination length differs from the rsync file list' >&2; return 1; }
  if [[ "$mode" == offline && ( "$before" != "$sent" || "$after" != "$sent" ) ]]; then
    echo 'Powered-off seed source changed size during transfer' >&2; return 1
  fi
  # An online seed is provisional, never start/recovery authority. The source
  # may grow after its file-list entry; final cutover must recopy while off.
}
