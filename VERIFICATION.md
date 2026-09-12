# Verification

## 0.4.0

Author: Richard Skinner

Verification combines the release's live lab campaign, automated regression coverage and final packaging/upgrade checks. Tests used disposable UNRAID-DEV01/DEV02 guests. Production guests were not used for destructive testing.

### Automated checks

The complete scripts/verify.sh suite passed with PHP, Bash and Node.js available. Coverage includes syntax, pairing/protocol safeguards, cold-power-state gates, storage isolation, resumable transfers, host-state ownership, firmware equivalence, ISO collisions, cleanup boundaries and migration-resolution transactions. Release tests cover feed selection and Unraid plugin/package version ordering. Community Applications plugin/profile XML parsed successfully.

### Targeted live validation

- Cold custom-NVRAM file and dedicated-ZFS migrations completed, including retained-source renaming.
- A source-only firmware path mapped to byte-identical destination firmware. A same-name ISO with different contents was rejected; copying the correct ISO and verifying its checksum passed.
- Same-host custom-NVRAM cloning produced an independent, stopped clone with a new UUID-scoped variables file and disconnected NICs.
- TPM migration and exact source VM/dataset/TPM cleanup passed after the five-minute validation period.
- Warm Move passed on file and native-ZFS paths, covering retained-source rename and unregister-with-storage-retained policies.
- Initial, delta and shutdown-triggered custom-NVRAM replication passed, including Guest Agent freeze/thaw, safe powered-off checkpoint metadata, archive checksums, isolation rejection and retention pruning.
- An intentional destination startup failure entered Attention required. Resolution refused a stopped destination, then completed the selected policy after manual repair/start without changing the repaired destination XML.
- Manual resolution passed retain-and-rename, unregister and delayed-delete cases. Repeated completion did not repeat renaming or deletion. Lost-response and partial-action recovery paths also have automated coverage.
- Incompatible-peer preflight refused unsupported firmware/custom-NVRAM operations before transfer. Legacy protocol 5 and the separately negotiated protocol-6 recovery plane remain unchanged.

### Packaging and upgrades

Both lab hosts passed actual plugin check/update tests into stable 0.4.0. Configuration, host identity and pairing-file hashes remained unchanged. Each host had one lifecycle daemon, one scheduler and one installed unmotion.plg entry; lab VMs were left stopped.

The installed licence is GPL-3.0-only. The published package SHA-256 is:

```text
c7a8a5f7adbd8b799adb270256a0b8c70cb2da44777533eb7295eaac5d88c9b5  unmotion-0.4.0_stable-noarch-1.txz
```

The current installer SHA-256 is:

```text
4f394188394a92f60e31e1e9236e8af4c0ad8b6ad993de4527f8ef9dd1574929  unmotion.plg
```

The feed-branch naming change was verified as a URL-only descriptor edit with the package unchanged. Approved owner-host URL migrations were backed up, passed plugin checks and left daemon PIDs unchanged; they did not install packages or perform VM operations.

### Explicit limitations

Browser checks verified the displayed version, Plugins description and shared styled confirmation/cancel flow. The Resolve-specific dialog was not visually exercised. Custom-NVRAM recovery archive validation passed, but live recovered activation was not performed for that disconnected fixture.

The final stable packaging promotion did not repeat the full destructive power-loss campaign, clean installation, uninstall, reboot or browser matrix. Those are not claimed as fresh stable-promotion passes. Documented feature limitations and backup requirements remain applicable.
