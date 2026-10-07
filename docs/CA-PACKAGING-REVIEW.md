# Community Apps packaging review — historical 0.4.1 record

Author: Richard Skinner

## Scope — 2026-10-03

Review and adopt [PR #5](https://github.com/rtho782/unmotion/pull/5): replace the opaque base64 TXZ in newly built plugin descriptors with a tagged HTTPS release-asset URL and native SHA-256 verification. Preserve the existing private package-cache mode (`0600`). No application, migration, recovery, pairing or protocol implementation changes.

The owner also authorized refreshing both live feeds and the CA stable installer pin using the **existing** 0.4.1 package. Its SHA-256 remains `b204544872403925e1c2ab64daf15851f44a2ddcd0817fe0dddc67e209322a0b`. The refreshed descriptor SHA-256 is `1127dd8b7e65bf93d87c8031906468453613cc581eb1b86b44ddaacdec208ef9`. Versions, cache name, release tag and historical release assets remain unchanged. Existing installations need no reinstall.

## Verification

- Public Unraid 7.0.0 plugin-manager source supports `<SHA256>` for both cached and downloaded files. DEV01's Unraid 7.3.2 manager implements the same checks.
- Full `scripts/verify.sh` passed on the Linux development VM at 192.168.3.6 with PHP, Node and Bash available. Privileged tests ran with `/run/lock` isolated in a private mount namespace; an earlier non-root attempt exposed host lock-file permissions, not an application regression.
- New regressions build both stable and beta descriptors, parse the XML, verify exact package hashes and persistent cache paths, check update channels/version ordering, and syntax-check their shell actions.
- DEV01's actual FILE processor passed fresh-download, valid-cache/offline, corrupt-cache replacement, corrupt-download rejection, missing-download rejection, package-install failure and removal-dispatch tests. Downloads and command execution were intercepted, so these tests did not install a package or restart services.
- Native `plugin validate` on DEV01 downloaded the actual GitHub 0.4.1 asset and returned `valid` for the refreshed descriptor.
- The original embedded archive was decoded and proven byte-identical to the published TXZ before changing its transport. Descriptor attributes and original release notes were preserved.

These are packaging and regression checks, not a new live-migration or destructive VM test campaign. No production-host change or lab package installation was performed for this adjustment.

## Separate review observations

The PR body also raised stale-PID signalling during uninstall, quote escaping in UI attributes, peer-number validation in shell arithmetic and unrestricted root pairing. These were separate from the packaging-only 0.4.1 patch. All four are addressed in stable 0.4.2, with focused regressions and lab validation; see [security and upgrade guidance](SECURITY-042.md) and [test evidence](TESTING-042.md). The historical 0.4.1 archive above remains unchanged.
