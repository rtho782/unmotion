#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
# FD is opened by the caller. wait/cancel callbacks must not change VM state.
unm_wait_migration_lock(){
  local fd="$1" mode="$2" status announced=0
  [[ "$mode" == shared || "$mode" == exclusive ]] || return 2
  while true; do
    if unm_migration_wait_cancelled; then return 130; fi
    if flock --"$mode" -E 75 -w 1 "$fd"; then return 0; else status=$?; fi
    [[ "$status" == 75 ]] || return "$status"
    if ((announced==0)); then unm_migration_wait_status; announced=1; fi
  done
}
