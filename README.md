# unMotion stable installer

This branch serves the stable Unraid installer for **unMotion 0.4.6** (Plugins-tab version **2026.10.13.01-0.4.6**). Requires Unraid 7.3.2 or later.

Install or update using [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). It downloads the separate TXZ release asset and verifies SHA-256, retaining the current package for offline boots and pruning older cached downloads after successful registration.

Setup dialogs show progress immediately while fresh checks run. Background refreshes preserve unchanged VM details and controls; logs preserve your position when you scroll back.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.6) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md). Protocol 7 remains compatible with 0.4.2–0.4.5. Earlier protocols require coordinated maintenance on both peers.

Author: Richard Skinner. GPL-3.0-only.
