# Unraid plugin updates

Author: Richard Skinner

## Installer housekeeping (0.4.3 onward)

unMotion 0.4.3 requires Unraid 7.3.2 or later. Historical 0.4.2 descriptors are not rewritten.

The package installs `post-hooks/unmotion-package-cache` alongside (without changing) Unraid's existing plugin-manager hooks. Only successful `install` or `update` events for `unmotion.plg` trigger pruning, after descriptor registration and the installer's successful service restart. Check, download, remove, failed and other-plugin events do nothing.

The hook reads the registered descriptor, verifies that its SHA-256-protected cache package matches the installed application version, and removes only recognized older unMotion TXZ files directly inside `/boot/config/plugins/unmotion/packages/`. It keeps the current archive for offline boots, newer or equal-version archives, unknown names, symlinks, hardlinks and directories. It does not touch settings, logs, credentials or VM/replica storage. A missing/corrupt package, redirected cache, concurrent cleanup or descriptor change retains remaining downloads and reports a skipped cleanup without failing the installed plugin. Removed downloads can be retrieved again from their original GitHub releases. An offline cached boot can run the same idempotent cleanup.

Cleanup takes effect when 0.4.3 is installed; publishing a release does not itself modify hosts.

Validation on 2026-10-04: 62 cleanup assertions and the complete Linux `scripts/verify.sh` suite passed, including PHP, Bash, Node and privileged isolated tests. Both disposable Unraid 7.3.2 hosts passed native 0.4.2-to-0.4.3 upgrades, with cache pruning, current-package preservation, unchanged settings/keys, protocol-7 pairing and native-fence readiness verified. Native FILE-processor fixtures cover fresh/cached/offline/error paths without rebooting hosts. The real installer test caught and corrected Unraid's omitted empty error argument; both omitted and explicitly empty arguments have regression coverage. See [verification details](../VERIFICATION.md).

## Stable channel (0.4.0 onward)

Stable releases use https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg. Public/application version 0.4.3 uses descriptor version `2026.10.04.01-0.4.3` and package token `0.4.3_stable`. Unraid compares PLG versions lexically, so a committed UTC date/sequence leads the semantic application version. `RELEASE_ID` binds that fixed identifier to the application version; rebuilds never read the clock. The Plugins tab shows the longer version; the application UI, Git tag and release title keep 0.4.3. Slackware package filenames retain their independent semantic version and stable token.

Publish the stable GitHub release and verify its assets before advancing either feed. For stable graduation, publish that same stable descriptor to both feeds. Both feeds currently offer `2026.10.04.01-0.4.3`. Its embedded pluginURL switches beta users to stable updates after installation; it does not silently opt stable users into future betas. Keep published descriptors byte-identical and never edit an installed version to simulate an upgrade.

## Initial feed-branch rename

Before CA submission, the owner requested removal of the development prefix from both feed branches. This is a deliberate metadata-only exception to descriptor byte identity: the initial 0.4.0 feed descriptor changes only its pluginURL, retaining the exact released payload and version. Original GitHub release assets and tags are not rewritten. The CA template initially pinned the corrected feed descriptor, not the historical asset; this pinning was superseded by the URL-identity correction below. New packages use the clean URLs from the builder.

Existing owner installations receive a backed-up, URL-only edit of their installed descriptor; no package installation, service restart or VM operation is required. Users of historical release assets must similarly update the feed URL. Do not rename or delete feeds with external installations without a compatibility migration.

The following beta-channel notes remain relevant for explicit prerelease installations.

Explicit beta installations use this pluginURL after the branch rename:

https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg

Unraid reads the installed descriptor's pluginURL when checking for updates, downloads the candidate to /tmp/plugins/unmotion.plg and compares version strings. Its normal Plugins-tab update then runs the candidate installer. The stable installed name remains unmotion.plg; settings, pairings and job state are preserved by the upgrade.

The dedicated plugin-beta branch contains the released installer, not unfinished package source. Apart from the explicit initial URL-only migration above, publish the tagged GitHub prerelease and its PLG/TXZ assets first, then advance that branch to the byte-identical PLG. Do not replace published release assets to deliver later code changes; increment the release version.

One owner-authorized exception was made for 0.4.1-beta2 before reported external adoption: its Plugins-tab heading was reduced from H1 to H4 without changing application behavior. The source tag, PLG/TXZ assets, checksum file and beta feed were refreshed together; the original source commit and local artifact backups were retained. This does not establish a general same-version update mechanism: an existing beta2 installation will not discover the cosmetic refresh as a newer version, and caches can briefly serve the original descriptor.

## Release procedure

1. Run verification and the disposable Unraid lab tests.
2. Build the release, publish the tag and GitHub release (prerelease for beta), upload the TXZ, and verify its downloadable SHA-256 before publishing the PLG. The PLG references `/releases/download/<public-version>/<package-name>` and Unraid checks its `<SHA256>` before running `upgradepkg`. It reuses a matching cached package at boot; an unavailable or mismatched download must block installation, not trigger a fallback.
3. In a separate clean checkout of the feed branch, copy that release's descriptor to unmotion.plg. Keep author metadata exactly Richard Skinner.
4. Run `php scripts/check-release-feed.php candidate.plg current-feed.plg` for each feed. It requires increasing installer IDs and application versions, or an exact-byte idempotent retry. A later date must never move a newer beta feed backwards to an older application. Commit and push without force-pushing; never regenerate a different package just for the feed.
5. Check the raw feed hash against the published PLG. Run plugin check unmotion.plg on a lab host and exercise plugin update unmotion.plg from an older version.
6. Fetch the public Community Apps `unmotion.xml` and stable PLG. Parse both and verify that the template's `PluginURL` exactly equals the PLG's `pluginURL` and the raw stable feed URL. Confirm HTTP success, XML validity, minimum Unraid version and exact author. Do not replace the template URL with a commit/tag pin or GitHub blob page.

The beta feed is opt-in through installation of the beta descriptor. It does not follow GitHub's latest-release redirect, which is unsuitable for selecting this prerelease channel.

## Community Apps packaging adjustment (0.4.1)

Following the Community Apps review, the owner authorized a descriptor-only refresh of both feeds and the CA stable pin. It replaces the base64 payload with a normal HTTPS release-asset download and SHA-256 check. The existing `0.4.1` tag, release assets, application version, archive bytes and installed package cache name remain unchanged. The TXZ SHA-256 is `b204544872403925e1c2ab64daf15851f44a2ddcd0817fe0dddc67e209322a0b`.

This is an explicit packaging-only exception to descriptor byte identity, not a same-version software update. The original release PLG remains a historical artifact; use the stable feed or current CA template for the reviewed download-based installer. At the time of that packaging-only adjustment, installed 0.4.1 hosts saw no newer version and needed no reinstall. Subsequent releases use the download-based builder and normal publish-assets-before-feeds procedure above.

## Community Apps URL identity correction — 2026-10-07

The Community Apps maintainer reported that the template URL must exactly match the PLG's own `pluginURL`. The commit-pinned raw URL served the correct installer but did not match that identity. The template now uses `https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg`, matching the stable descriptor. This is a catalogue-metadata correction, not a new application release: 0.4.3, both installer feeds, TXZ/PLG assets and installed hosts remain unchanged. The maintainer advised allowing up to four hours after the XML change for catalogue inclusion; this is not confirmation that the app is already visible.

## Existing installations

Historical 0.4.0-beta2 and earlier descriptors have no pluginURL. They cannot discover an update until bootstrapped. Install the current chosen channel once manually, preserving the installed name `unmotion.plg`. Historical 0.4.1 beta descriptors already contain the beta feed URL; installing the current stable descriptor graduates them to stable updates.

For a 0.4.1-to-0.4.2 upgrade, follow the [protocol-7 maintenance prerequisites](SECURITY-042.md): complete or remove old preparations while both peers still run the old version, pause replication, wait for active jobs/cleanup, shut down recovery-managed VMs and upgrade both hosts together. Preserve unresolved authority and partial data. The installer refuses an unsafe upgrade rather than bypassing these checks.

Never edit the installed version string to simulate an upgrade. Tests should use the real older package. Unraid's package manager may skip a same-version test build even when the plugin descriptor is forcibly reinstalled; in the disposable lab, explicitly reinstall the exact candidate TXZ and verify installed source hashes when testing successive candidates.

Allocate a unique `YYYY.MM.DD.NN` ID for every release, incrementing the two-digit sequence for same-day releases and never reusing an ID. Keep beta-to-stable IDs increasing and test double-digit public versions independently of date ordering. Historical `0.x` descriptors sort before the 0.4.3 date-prefixed installer; package upgrades still use their existing version ordering.
