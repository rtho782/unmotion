# Changelog

## 0.4.0-beta1 - 2026-08-10

- Added scheduled, resumable replication of VM zvols and raw/qcow2 images held in strictly dedicated ZFS datasets.
- Added RPO notches from 5 minutes through 24 hours and destination-side UTC-bucket retention of up to 24 recovery points from the latest 24 hours.
- Kept protocol-5 pairing and migration compatibility while adding an explicit scheduled-replication capability for beta1 peers.
- Kept replica datasets read-only, unmounted or hidden, and deliberately absent from libvirt; beta1 does not activate replicas or perform automatic failover.
- Added crash-safe pending/base generations, retained ZFS receive tokens, exact GUID verification, source holds and destination-publication-before-source-pruning ordering.
- Added QEMU Guest Agent command/network evidence and consistency classification. Replication may proceed without the agent, but those points are explicitly ineligible for later recovery.
- Added content-addressed TPM and UEFI NVRAM checkpoint evidence, preserving the latest verified safe checkpoint while distinguishing powered-off safe copies from running best-effort copies.
- Rejected shared datasets, non-ZFS storage, encrypted datasets and qcow2 backing chains for replication, with guidance to use ZFS Master for storage conversion.
- Added scheduled-policy and incoming-replica UI inventory. Recovery activation, split-brain arbitration, witness protocol, managed autostart and failback remain deferred.
- Hardened spaced-dataset hold parsing, multi-disk SSH control calls, source base commit/cleanup ordering, launch-state reconciliation and explicit incremental-base diagnostics during destructive lab regression testing.
- Canonicalized aliased Unraid swtpm paths while rejecting genuinely distinct TPM stores, and required safe fallback archives to cover the same TPM/NVRAM devices without nested stale fallbacks.
- Blocked replication reservation and recovery-point publication whenever the destination already defines any VM, running or stopped, with the replica UUID.
- Required every checkpoint labelled safe to have an exact verified archive covering each declared TPM/NVRAM device, rechecked destination UUID isolation immediately before inventory commit, and garbage-collected only hash-owned checkpoint archives no longer referenced by retained points or safe fallbacks.

## 0.3.1-RC2 — 2026-08-09

- Added cold same-host VM cloning as a post-recovery feature without changing peer protocol version 5.
- Made full independent copies of zvols and dedicated ZFS datasets with non-recursive send/receive, and sparse copies of shared raw/qcow2 images.
- Assigned each clone a new domain UUID, genid and NIC MAC addresses; removed fixed graphics ports and disabled autostart.
- Copied UEFI NVRAM to a new UUID-scoped libvirt path while blocking virtual TPM and PCIe passthrough clones in RC2.
- Removed USB passthrough definitions from clones and retained attached ISO paths as read-only media.
- Added an Ubuntu Guest Agent path that boots the clone with NIC links down, resets machine ID, hostname and SSH host keys, replaces Netplan with DHCP, then leaves the clone stopped.
- Kept NIC links down with a visible warning whenever guest customization is skipped or cannot be completed.
- Added clone preflight, job reporting, cancellation cleanup, UI controls and regression coverage.
- Accepted internal spaces in exact, ownership-authorized ZFS overwrite targets while retaining control-character, snapshot, traversal, root-dataset and prefix guards.
- Normalized every packaged directory to mode `0755` so installation cannot make Unraid's `/` group-writable and invalidate root SSH key authentication.
- Made clone cancellation wait for the exact domain to stop, retry exact ZFS cleanup, verify destinations are absent, and report cleanup failures instead of falsely claiming success during a QEMU start race.
- Removed successfully-destroyed source snapshots from the clone cleanup ledger without leaving empty sentinel entries that could falsely report incomplete cleanup.

## 0.3.0-RC1 — 2026-08-09

- Standardized the plugin and Community Applications author metadata as `Richard Skinner`.
- Preserved recoverable ZFS seed and migration state when SSH returns before the destination Unraid array and ZFS datasets are available.
- Distinguished a normally absent destination child dataset from an unavailable destination parent when probing for ZFS receive-resume state.
- Fixed prepared-copy removal records with empty TSV fields so source holds and exact destination objects are cleaned correctly.
- Rejected duplicate or active prepared-copy removal requests.
- Fixed Warm Move rsync cutover by using `--partial-dir` only for non-`--inplace` transfers.
- Restored read-only protection on prepared image files when Warm Move cutover fails or is cancelled before any destination start attempt, allowing safe retry.
- Fixed quoted multi-record Avahi TXT parsing while retaining `\\032` display-name decoding and protocol 5.
- Reset the settings Apply button after successful loading and saving.
- Added RC1 shell/PHP regressions while retaining independent verification of the recovered beta7 artifacts.
- Normalized the internal package version token to lowercase so Unraid correctly orders RC1 after the recovered beta7 package.
- Advanced the RC1 lab package build as live recovery fixes landed so already staged test installs upgrade cleanly; the current candidate is `noarch-3`.

## 0.3.0-beta7 — 2026-08-07

- Fixed a Warm Move cutover failure in the transfer-progress allocator under Bash nounset mode.
- Split local-variable declaration from arithmetic evaluation so `bytes` is initialized before use.
- Added a debug transfer-plan summary before storage transfer begins.
- Retained beta6 ISO handling, beta4 diagnostics and beta3 compressed/resumable ZFS replication.

This entry is recovered verbatim in substance from the beta7 PLG metadata. The detailed beta6 release artifact was not present locally, so beta6-specific changes beyond “ISO handling” are not reconstructed here.

## Earlier recovered lineage

- `0.3.0-beta5` and `0.3.0-beta4` PLGs were present on the workstation but are not treated as the repository baseline.
- `0.3.0-beta3` introduced stdout-silent ZFS progress parsing, compressed/resumable ZFS replication, retained interrupted receives, token-based resume and stronger failed-seed cleanup.
- Earlier `0.3.0-beta1.x`, `0.3.0-beta2.x`, `0.2.x` and `0.1.x` artifacts also existed locally.

The exact intermediate beta4–beta6 history is incomplete. Do not infer missing release notes from version numbers alone.
