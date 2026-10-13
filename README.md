# unMotion stable installer

This branch serves the stable Unraid installer for **unMotion 0.4.7** (Plugins-tab version **2026.10.13.02-0.4.7**). Requires Unraid 7.3.2 or later.

Install or update using [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). It downloads the separate TXZ release asset and verifies SHA-256, retaining the current package for offline boots and pruning older cached downloads after successful registration.

New pairing requires an independently obtained Ed25519 SSH host fingerprint and 0.4.7 or later on both hosts. Source storage isolation and exact cleanup checks retain unrelated contents and block deletion when ownership is uncertain. Persistent web actions require CSRF-protected POST.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.7) and [pairing and storage safety guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-047.md). Protocol 7 preserves existing pairings with 0.4.2–0.4.6; upgrade both peers for the complete protections. Earlier protocols require [coordinated maintenance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md) on both peers.

Author: Richard Skinner. GPL-3.0-only.
