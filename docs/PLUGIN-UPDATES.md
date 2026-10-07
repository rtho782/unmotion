# Unraid plugin updates

Author: Richard Skinner

## Stable channel (0.4.0 onward)

Stable releases use https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg. Public/application version 0.4.0 uses descriptor version 0.4.0-stable and package token 0.4.0_stable. Unraid compares plugin versions with strcmp and package versions with sort -V; the suffix sorts after beta4 without changing Unraid itself. Build-time regressions cover this transition and subsequent versions.

Publish the stable GitHub release and verify its assets before advancing either feed. For graduation from beta4, publish that same stable descriptor to both feeds. Its embedded pluginURL switches beta users to stable updates after installation; it does not silently opt stable users into future betas. Keep published descriptors byte-identical and never edit an installed version to simulate an upgrade.

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
2. Build the release, publish the tag and GitHub prerelease, and verify the downloadable asset hashes.
3. In a separate clean checkout of the feed branch, copy that release's descriptor to unmotion.plg. Keep author metadata exactly Richard Skinner.
4. Commit and push the feed without force-pushing. Never regenerate a different package just for the feed.
5. Check the raw feed hash against the published PLG. Run plugin check unmotion.plg on a lab host and exercise plugin update unmotion.plg from an older version.

The beta feed is opt-in through installation of the beta descriptor. It does not follow GitHub's latest-release redirect, which is unsuitable for selecting this prerelease channel.

## Existing installations

Historical 0.4.0-beta2 and earlier descriptors have no pluginURL. They cannot discover an update until bootstrapped. Install the current chosen channel once manually, preserving the installed name `unmotion.plg`. Current 0.4.1 beta descriptors already contain the beta feed URL.

Never edit the installed version string to simulate an upgrade. Tests should use the real older package. Unraid's package manager may skip a same-version test build even when the plugin descriptor is forcibly reinstalled; in the disposable lab, explicitly reinstall the exact candidate TXZ and verify installed source hashes when testing successive candidates.

Unraid's updater compares versions lexically. If beta numbering reaches double digits, or the channel switches to differently-cased RC/stable version tokens, validate the ordering on Unraid before publishing. Do not silently change the existing version convention.
