# Changelog

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

