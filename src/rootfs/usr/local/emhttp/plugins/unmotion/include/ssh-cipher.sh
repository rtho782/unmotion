#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
# Settings are captured once by each worker. Never accept an SSH option string.
SSH_CIPHER="${SSH_CIPHER:-aes128-gcm@openssh.com}"
case "$SSH_CIPHER" in
  aes128-gcm@openssh.com|aes256-gcm@openssh.com) ;;
  *) echo 'Invalid SSH encryption setting; transfer not started.' >&2; exit 4 ;;
esac
