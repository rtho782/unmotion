# unMotion beta update feed

This branch currently serves **unMotion 0.4.8 stable** (Plugins-tab version **2026.10.14.01-0.4.8**) to graduate beta installations. No newer beta is published. Requires Unraid 7.3.2 or later.

The [graduation installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) is byte-identical to the released stable installer. It downloads a separate SHA-256-verified TXZ and selects future stable updates after installation. Future betas require explicit opt-in.

Pairing displays the peer's Ed25519 fingerprint with **Accept and pair / Cancel** and optional manual verification instructions. Existing pins and restricted pairing keys are preserved. New pairing requires 0.4.7 or later on both hosts; existing protocol-7 pairings remain compatible.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.8), [pairing guide](https://github.com/rtho782/unmotion/blob/main/docs/PAIRING.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
