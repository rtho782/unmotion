# unMotion beta update feed

This branch currently serves **unMotion 0.4.6 stable** (Plugins-tab version **2026.10.13.01-0.4.6**) to graduate beta installations. No newer beta is published. Requires Unraid 7.3.2 or later.

The [graduation installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) is byte-identical to the released stable installer. It downloads a separate SHA-256-verified TXZ and selects future stable updates after installation. Future betas require explicit opt-in.

Setup dialogs show progress immediately while fresh checks run. Background refreshes preserve unchanged VM details and controls; logs preserve your position when you scroll back.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.6) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md). Protocol 7 remains compatible with 0.4.2–0.4.5; earlier protocols require a coordinated upgrade.

Author: Richard Skinner. GPL-3.0-only.
