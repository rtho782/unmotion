# Unraid plugin updates

Author: Richard Skinner

Starting in 0.4.0-beta3, the generated PLG includes this pluginURL:

https://raw.githubusercontent.com/rtho782/unmotion/codex/plugin-beta/unmotion.plg

Unraid reads the installed descriptor's pluginURL when checking for updates, downloads the candidate to /tmp/plugins/unmotion.plg and compares version strings. Its normal Plugins-tab update then runs the candidate installer. The stable installed name remains unmotion.plg; settings, pairings and job state are preserved by the upgrade.

The dedicated codex/plugin-beta branch contains a copy of the published PLG, not a pointer to unfinished package source. Publish the tagged GitHub prerelease and its PLG/TXZ assets first, then advance that branch to the byte-identical PLG. Do not replace published release assets to deliver later code changes; increment the release version.

## Release procedure

1. Run verification and the disposable Unraid lab tests.
2. Build the release, publish the tag and GitHub prerelease, and verify the downloadable asset hashes.
3. In a separate clean checkout of the feed branch, copy that release's descriptor to unmotion.plg. Keep author metadata exactly Richard Skinner.
4. Commit and push the feed without force-pushing. Never regenerate a different package just for the feed.
5. Check the raw feed hash against the published PLG. Run plugin check unmotion.plg on a lab host and exercise plugin update unmotion.plg from an older version.

The beta feed is opt-in through installation of the beta descriptor. It does not follow GitHub's latest-release redirect, which is unsuitable for selecting this prerelease channel.

## Existing installations

Beta2 and earlier descriptors have no pluginURL. They cannot discover beta3 automatically until bootstrapped. Install beta3 once manually, or back up the installed beta2 descriptor and add only the pluginURL attribute shown above to its PLUGIN element. The latter preserves its version and payload and lets the normal GUI discover beta3.

Never edit the installed version string to simulate an upgrade. Tests should use the real older package. Unraid's package manager may skip a same-version test build even when the plugin descriptor is forcibly reinstalled; in the disposable lab, explicitly reinstall the exact candidate TXZ and verify installed source hashes when testing successive candidates.

Unraid's updater compares versions lexically. If beta numbering reaches double digits, or the channel switches to differently-cased RC/stable version tokens, validate the ordering on Unraid before publishing. Do not silently change the existing version convention.
