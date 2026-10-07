#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
# Manual, destructive-to-fixture-only guest proof; never called by verify.sh.
# Run ONLY inside the disposable beta2-tpm-live guest, with tpm2-tools installed.
set -eu
[ "$(hostname)" = beta2-tpm-live ] || { echo 'Disposable guest only' >&2; exit 2; }
[ "$(id -u)" = 0 ] || exit 2
export TPM2TOOLS_TCTI=device:/dev/tpmrm0
proof=/var/lib/unmotion-tpm-proof
efi=/sys/firmware/efi/efivars/UnMotionLiveProof-54200000-1111-4111-8111-000000000001
case "${1:-}" in
 init)
  [ ! -e "$proof" ]; mkdir -m 0700 "$proof"; cd "$proof"
  dd if=/dev/urandom of=secret.bin bs=32 count=1 2>/dev/null
  sha256sum secret.bin | cut -d ' ' -f 1 >secret.sha256
  tpm2_createprimary -C o -g sha256 -G rsa -c primary.ctx -Q
  tpm2_flushcontext -t
  tpm2_create -C primary.ctx -i secret.bin -u seal.pub -r seal.priv -Q
  tpm2_flushcontext -t
  tpm2_nvdefine 0x01500042 -C o -s 32 -a 'ownerread|ownerwrite' -Q
  tpm2_nvwrite 0x01500042 -C o -i secret.bin
  rm -- secret.bin
  printf '\007\000\000\000unmotion-beta2-live-uefi-proof' >"$efi"
  sha256sum "$efi" >efi.sha256
  cat /proc/sys/kernel/random/boot_id >source-boot-id
  echo 0 >counter
  sync
  echo 'Sealed secret, TPM NV index and nonvolatile UEFI marker created.'
  ;;
 verify)
  cd "$proof"
  # Contexts are recreated after boot; no saved transient TPM context is trusted.
  tpm2_createprimary -C o -g sha256 -G rsa -c verify-primary.ctx -Q
  tpm2_flushcontext -t
  tpm2_load -C verify-primary.ctx -u seal.pub -r seal.priv -c verify-seal.ctx -Q
  tpm2_flushcontext -t
  tpm2_unseal -c verify-seal.ctx -o unsealed.bin
  tpm2_flushcontext -t
  actual=$(sha256sum unsealed.bin | cut -d ' ' -f 1)
  [ "$actual" = "$(cat secret.sha256)" ]
  tpm2_nvread 0x01500042 -C o -s 32 -o nv.bin
  [ "$(sha256sum nv.bin | cut -d ' ' -f 1)" = "$actual" ]
  sha256sum -c efi.sha256
  [ ! -f post-seed.sha256 ] || sha256sum -c post-seed.sha256
  rm -- unsealed.bin nv.bin
  printf 'TPM_UNSEAL_SHA256=%s\n' "$actual"
  printf 'BOOT_ID=%s\nSOURCE_BOOT_ID=%s\nCOUNTER=%s\n' "$(cat /proc/sys/kernel/random/boot_id)" "$(cat source-boot-id)" "$(cat counter)"
  [ ! -f clean-shutdown ] || { printf 'CLEAN_SHUTDOWN='; cat clean-shutdown; }
  ;;
 writer)
  cd "$proof"
  trap 'printf "%s\n" "$(cat counter)" >clean-shutdown; sync; exit 0' TERM INT
  while :; do n=$(cat counter); printf '%s\n' "$((n+1))" >counter.next; mv counter.next counter; sync; sleep 1; done
  ;;
 *) echo 'Usage: init|verify|writer' >&2; exit 2 ;;
esac
