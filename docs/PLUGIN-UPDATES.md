# Unraid plugin updates

Author: Richard Skinner

## Stable channel (0.4.0 onward)

Stable releases use https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg. Public/application version 0.4.2 uses descriptor version 0.4.2-stable and package token 0.4.2_stable. Unraid compares plugin versions with strcmp and package versions with sort -V; the stable suffix sorts after the corresponding beta releases without changing Unraid itself. Build-time regressions check stable/beta ordering; use the actual older descriptor and package when testing an upgrade.

Publish the stable GitHub release and verify its assets before advancing either feed. For stable graduation, publish that same stable descriptor to both feeds. Both feeds currently offer 0.4.2-stable. Its embedded pluginURL switches beta users to stable updates after installation; it does not silently opt stable users into future betas. Keep published descriptors byte-identical and never edit an installed version to simulate an upgrade.

## Initial feed-branch rename

Before CA submission, the owner requested removal of the development prefix from both feed branches. This is a deliberate metadata-only exception to descriptor byte identity: the initial 0.4.0 feed descriptor changes only its pluginURL, retaining the exact released payload and version. Original GitHub release assets and tags are not rewritten. The CA template pins the corrected feed descriptor, not the historical asset. New packages use the clean URLs from the builder.

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
4. Commit and push the feed without force-pushing. Never regenerate a different package just for the feed.
5. Check the raw feed hash against the published PLG. Run plugin check unmotion.plg on a lab host and exercise plugin update unmotion.plg from an older version.

The beta feed is opt-in through installation of the beta descriptor. It does not follow GitHub's latest-release redirect, which is unsuitable for selecting this prerelease channel.

## Community Apps packaging adjustment (0.4.1)

Following the Community Apps review, the owner authorized a descriptor-only refresh of both feeds and the CA stable pin. It replaces the base64 payload with a normal HTTPS release-asset download and SHA-256 check. The existing `0.4.1` tag, release assets, application version, archive bytes and installed package cache name remain unchanged. The TXZ SHA-256 is `b204544872403925e1c2ab64daf15851f44a2ddcd0817fe0dddc67e209322a0b`.

This is an explicit packaging-only exception to descriptor byte identity, not a same-version software update. The original release PLG remains a historical artifact; use the stable feed or current CA template for the reviewed download-based installer. At the time of that packaging-only adjustment, installed 0.4.1 hosts saw no newer version and needed no reinstall. Current 0.4.2 is a separately versioned application upgrade; releases use the download-based builder and normal publish-assets-before-feeds procedure above.

## Existing installations

Historical 0.4.0-beta2 and earlier descriptors have no pluginURL. They cannot discover an update until bootstrapped. Install the current chosen channel once manually, preserving the installed name `unmotion.plg`. Historical 0.4.1 beta descriptors already contain the beta feed URL; installing the current stable descriptor graduates them to stable updates.

For a 0.4.1-to-0.4.2 upgrade, follow the [protocol-7 maintenance prerequisites](SECURITY-042.md): complete or remove old preparations while both peers still run the old version, pause replication, wait for active jobs/cleanup, shut down recovery-managed VMs and upgrade both hosts together. Preserve unresolved authority and partial data. The installer refuses an unsafe upgrade rather than bypassing these checks.

Never edit the installed version string to simulate an upgrade. Tests should use the real older package. Unraid's package manager may skip a same-version test build even when the plugin descriptor is forcibly reinstalled; in the disposable lab, explicitly reinstall the exact candidate TXZ and verify installed source hashes when testing successive candidates.

Unraid's updater compares versions lexically. If beta numbering reaches double digits, or the channel switches to differently-cased RC/stable version tokens, validate the ordering on Unraid before publishing. Do not silently change the existing version convention.
