# unMotion stable installer

This branch serves the stable Unraid installer for **unMotion 0.4.9** (Plugins-tab version **2026.10.15.01-0.4.9**). Requires Unraid 7.3.2 or later.

Install or update using [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). It downloads a separate versioned TXZ release asset and verifies SHA-256. The current package is retained for offline boots; older cached downloads are pruned after successful registration.

VMs using the numeric OVMF `X-PciMmio64Mb` firmware setting require 0.4.9 or later on both hosts. The narrowly validated setting is preserved; arbitrary QEMU command lines remain blocked. Fresh preflight and worker checks reject unsupported destinations before transfer. Ordinary protocol-7 pairings remain compatible.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.9), [firmware guidance](https://github.com/rtho782/unmotion/blob/main/docs/OVMF-FIRMWARE.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
