# Changelog

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
