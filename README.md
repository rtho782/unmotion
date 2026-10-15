# unMotion beta update feed

This branch currently serves **unMotion 0.4.9 stable** (Plugins-tab version **2026.10.15.01-0.4.9**) to graduate beta installations. No newer beta is published. Requires Unraid 7.3.2 or later.

The [graduation installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) is byte-identical to the released stable installer. It downloads a separate SHA-256-verified TXZ and selects future stable updates after installation. Future betas require explicit opt-in.

VMs using the numeric OVMF `X-PciMmio64Mb` firmware setting require 0.4.9 or later on both hosts. The narrowly validated setting is preserved; arbitrary QEMU command lines remain blocked. Fresh preflight and worker checks reject unsupported destinations before transfer. Ordinary protocol-7 pairings remain compatible.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.4.9), [firmware guidance](https://github.com/rtho782/unmotion/blob/main/docs/OVMF-FIRMWARE.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
