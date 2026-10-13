# unMotion beta update feed

This branch currently serves **unMotion 0.4.7 stable** (Plugins-tab version **2026.10.13.02-0.4.7**) to graduate beta installations. No newer beta is published. Requires Unraid 7.3.2 or later.

The [graduation installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) is byte-identical to the released stable installer. It downloads a separate SHA-256-verified TXZ and selects future stable updates after installation. Future betas require explicit opt-in.

New pairing requires an independently obtained Ed25519 SSH host fingerprint and 0.4.7 or later on both hosts. Source storage isolation and exact cleanup checks retain unrelated contents and block deletion when ownership is uncertain. Persistent web actions require CSRF-protected POST.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.7) and [pairing and storage safety guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-047.md). Protocol 7 preserves existing pairings with 0.4.2–0.4.6; upgrade both peers for the complete protections. Earlier protocols require a [coordinated upgrade](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
