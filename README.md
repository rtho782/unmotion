# unMotion stable installer

This branch serves the stable Unraid installer for **unMotion 0.4.8** (Plugins-tab version **2026.10.14.01-0.4.8**). Requires Unraid 7.3.2 or later.

Install or update using [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). It downloads a separate versioned TXZ release asset and verifies SHA-256. The current package is retained for offline boots; older cached downloads are pruned after successful registration.

Pairing displays the peer's Ed25519 fingerprint with **Accept and pair / Cancel** and optional manual verification instructions. Existing pins and restricted pairing keys are preserved. New pairing requires 0.4.7 or later on both hosts; existing protocol-7 pairings remain compatible.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.8), [pairing guide](https://github.com/rtho782/unmotion/blob/main/docs/PAIRING.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
