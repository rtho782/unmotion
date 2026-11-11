# Unraid 7.4.0 qualification

Author: Richard Skinner

Test date: 2026-11-11. This is a lab compatibility check of published **unMotion 0.5.0-beta8**, not a new plugin release or qualification of every Unraid subsystem. Production hosts and public installer feeds were not changed.

## Platform and upgrade

UNRAID-DEV02 was upgraded through the official Unraid updater from 7.3.2 to 7.4.0. Its two outer storage datasets were snapshotted while the VM was cleanly powered off. The updater verified downloaded boot images; the previous boot files match the original rollback hashes. Licensing USB identity and its existing VM attachment rule were checked. No ZFS feature upgrade was requested.

UNRAID-DEV01 stayed on 7.3.4 as the mixed-version peer. Both hosts retained the same published Beta8 package.

| Component on DEV02 | Before | After |
| --- | --- | --- |
| Unraid | 7.3.2 | 7.4.0 |
| Kernel | 6.18.38-Unraid | 6.18.60-Unraid |
| QEMU | 10.2.3 | 10.2.3 |
| libvirt | 12.2.0 | 12.2.0 |
| OpenZFS | 2.4.3-1 | 2.4.4-1 |
| PHP | 8.4.23 | 8.4.27 |

The initial normal reboot into 7.4.0 succeeded. Licensing remained valid, the array started, pools were healthy and native start fencing automatically re-proved itself. Settings, pairing files, existing Unraid hook and all original inactive VM definitions were unchanged immediately after the OS upgrade. All 105 packaged files and 104 installed runtime files matched the qualified Beta8 artifact.

## Checks completed

- **Full isolated verification on 7.4.0:** `scripts/verify.sh` passed with PHP, Bash and Node available, including privileged namespace/process and real sparse/chroot transport tests. No unavailable-tool skips were reported. Native Unraid Plugin Manager FILE processing passed its fresh-download, cached/offline, corruption, failed-upgrade and removal tests with command execution mocked; these tests did not install or remove the running plugin.
- **Mixed-version live zvol migration:** the independent 1 GiB non-OS probe moved from DEV01 to DEV02. Cancellation and loss of its owned disk relay preserved the original running source process and authority, with the receiver absent. A successful move retired the source; attempting to start that retired source was rejected before QEMU. The destination owner passed a native stop/start/stop cycle.
- **Mixed-version live TPM/file migration:** the Linux three-disk UEFI/TPM guest moved from 7.4.0 to 7.3.4 and back. Guest boot ID, sealed-secret hash, TPM public-object hash and both full data-disk hashes matched before and after each move. TPM unsealing succeeded without replacing or resetting the TPM. The guest was gracefully powered off afterward.
- **Protected and mixed-storage cold boot:** the existing TPM guest booted on 7.4.0 and unsealed its original secret. The Linux two-zvol/one-qcow2 UEFI guest also booted with all three canaries and persisted heartbeat counters intact.
- **Cold native ZFS transfer:** a new small, dedicated VM dataset containing raw/qcow2 disks moved from DEV02 to DEV01, booted at the destination and passed exact data-marker checks. The source definition was unregistered and source storage retained.
- **Warm sparse-file transfer:** a separate running raw/qcow2 fixture on shared ZFS storage prepared successfully from DEV01 to DEV02. After shutdown, physical QCOW2 allocation was grown, Update completed and final cutover passed. Destination disk markers matched and the raw image remained sparse. Both ordinary transfer cases copied and remapped synthetic optical-media files with matching hashes; this checks ISO transport, not ISO bootability.
- **Browser and API:** login, populated VM inventory, healthy paired-host display, cached CPU comparison, native VM form, Plugins status and migration preview rendering worked. The preview immediately displayed loading feedback, then populated configuration and correctly blocked retained destination data. No real CPU default change or native VM form submission was performed. The reviewed CPU-editor integration reported available.
- **CSRF:** authenticated API status returned 200; missing and incorrect tokens returned native HTTP 403 JSON errors; a valid CSRF-protected read-only POST returned 200. No VM/settings mutation was used for this test.

**Normal reboot persistence:** the array stopped cleanly and a second, ordinary 7.4.0-to-7.4.0 reboot succeeded without force/reset. All 34 protected file fingerprints and 13 inactive VM XMLs were preserved, including settings, pairing/authorization files, existing Unraid hook and durable live authority. The CPU observation was reused rather than recollected. Licensing, array and pool health remained valid. The lifecycle daemon automatically re-proved the native pre-QEMU hook on its normal retry after VM Manager became available; no guard was bypassed.

The TPM guest then passed a native-authorized full cold start on the new host boot. Its guest boot ID changed, as expected, while the original sealed secret, TPM public object and both disk hashes remained identical. It was gracefully powered off. Both lab hosts were left with their arrays started and test guests off.

## Implications and limits

No unMotion application patch or protocol change was needed for the completed checks. QEMU/libvirt remained unchanged, and the real mixed-Unraid-version live tests passed the existing fresh compatibility gates. This does not remove the requirement for matching Beta8 peers and compatible VM/CPU, machine, firmware and TPM contracts.

The OS/context change invalidated the old CPU observation as expected. Opening CPU compatibility collected a new cache, which supplied the local and paired display thereafter. Unraid's updated CSRF rejection status is handled without weakening protection. Preserve the previous boot backup and avoid upgrading pool features if OS rollback is required, as described in the [official update guidance](https://docs.unraid.net/unraid-os/updating-unraid/).

The Beta8 installer description still contains pre-live-beta wording that says live migration is unsupported. That is a pre-existing packaging-description issue, not a 7.4 regression; its wording should be aligned in a later plugin release.

This run does not independently qualify Windows/BitLocker on 7.4, AMD or bare-metal hardware, passthrough devices, Docker workloads, every third-party plugin, or a new full end-to-end replication/recovery fault campaign. The isolated existing regression suite covers those implemented workflows where applicable; the previous [Beta8 qualification](BETA8-QUALIFICATION.md) remains separate evidence. Retained source and interrupted-transfer data were not discarded.

See the [official 7.4.0 release notes](https://docs.unraid.net/unraid-os/release-notes/7.4.0/) for Unraid changes outside this lab check.
