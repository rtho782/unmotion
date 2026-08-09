# Reconstruction verification

Completed on 2026-08-09.

- The supplied PLG SHA-256 matches the historical release record: `20ace170707eaed65e55a7b2418e6e86fe5e98fbb48c1b5f2d5f509889e55656`.
- The TXZ extracted from that PLG is byte-identical to the separately supplied TXZ.
- The source tree extracted from the TXZ is byte-identical to `source/` in the separately supplied source tarball.
- All three original release artifacts pass `release/0.3.0-beta7/SHA256SUMS`.
- The embedded PLG package payload compares byte-for-byte with the retained release TXZ.
- Version `0.3.0-beta7` and peer protocol `5` agree across the source.
- Bash syntax checks pass for all shell executables and all repository build/test helpers.
- JavaScript parses successfully with Node.js `--check`.
- Static markers for ZFS receive resume, destination dedup configuration and the beta7 transfer-plan fix are present.
- The delivered ZIP was extracted into a clean directory and its file manifest was compared with the source repository package.

PHP CLI was not installed in the Windows reconstruction environment, so `php -l` could not be rerun here. The shipped source was not modified, and the original beta7 release record stated that PHP syntax checks passed. Re-run `scripts/verify.sh` on Unraid/Linux with PHP CLI before making or releasing code changes.

No live Unraid host or VM migration was invoked during reconstruction.

## RC1 development verification

`0.3.0-RC1` is the first post-recovery development release. Its verification is tracked separately from the immutable beta7 artifact record above. RC1 adds behavioral regressions for quoted Avahi TXT records, empty TSV cleanup fields, destination-unavailable resume classification, mutually exclusive rsync options, prepared-image re-protection after failed cutover and settings dirty-state reset. Live validation uses only the disposable `UNRAID-DEV01` and `UNRAID-DEV02` lab hosts.
