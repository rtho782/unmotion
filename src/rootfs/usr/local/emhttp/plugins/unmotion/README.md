#### unMotion

Clone virtual machines, migrate them between paired Unraid hosts with Cold or Warm Move, and maintain scheduled ZFS replicas with guarded manual recovery.

Keep verified backups and review migration and recovery preflight checks before proceeding. Warm Move requires a powered-off final cutover. This beta also supports experimental live migration of eligible file/zvol VMs, including supported software TPM 2.0. Live moves require matching beta peers and compatible CPU, firmware and virtualization software. Retained inactive copies can be explicitly reviewed for cleanup; current owner data and start fences are preserved. Automatic failover is not supported.

Author: Richard Skinner. Copyright (C) 2026 Richard Skinner.
Licensed under GPL-3.0-only. See LICENSE for the terms and warranty disclaimer.
