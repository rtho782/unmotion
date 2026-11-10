# Beta7 qualification

Author: Richard Skinner.

Lab qualification completed before owner-approved publication of 0.5.0-beta7 on the opt-in beta feed. Stable and the Community Apps installer remain on 0.4.9. This record describes the tested scope, not general-availability live migration.

## Implemented scope

- Native live migration of one software TPM 2.0 emulator (`tpm-tis` or `tpm-crb`), with matching swtpm/libtpms binaries. TPM state uses the native libvirt/QEMU migration channel; no shared storage or copy of live TPM files is required. Each incoming attempt has an independent UUID-bound state directory.
- One to sixteen independent raw/qcow2 file disks, including mixed formats, with aggregate capacity checks and exact per-disk transfer/authority bindings. Empty read-only file CD-ROM drives may remain attached.
- A read-only Live readiness dialog for both eligible and ineligible VMs. It lists independent structural blockers; Start remains disabled until fresh full preflight succeeds and repeats operational checks when submitted.
- Source-side recovery-point counts observed from authenticated destination inventory/pruning replies. Counts are display information, not recovery authority.
- OpenZFS encoded zvol aliases resolved using kernel identity and a unique actual-volume inventory match. ZFS operations retain actual dataset names; disk XML uses the correct destination alias. A literal-plus/space ambiguity is a blocker, not a guessed mapping.

Wire protocol remains 7. Live capability 3 requires matching current beta builds. Optional `zvolPathVersion: 1` is required on both sides for affected encoded-zvol transfers; ordinary unaffected protocol-7 workflows remain compatible.

## Completed lab checks

Fresh powered-off outer-lab storage snapshots preceded destructive tests. No production guest was migrated, restarted or modified.

| Check | Result |
| --- | --- |
| Normal guarded installation on both disposable hosts | Passed; settings, pairings and existing native hook preserved. |
| Native pre-QEMU denial probe on both hosts | Passed. |
| Full Linux verification and available PHP/Bash/JavaScript syntax checks | Passed on the current candidate, including 52 Beta7 storage/TPM/transport checks and 27 encoded-path checks. |
| Three-disk Ubuntu UEFI/TPM guest, both live directions | Passed; identical guest boot ID, sealed-secret/public-object proof and both extra disk checksums. |
| Full power cycle after native TPM migration | Passed; the same sealed secret unsealed and disk checksums matched. |
| Cancellation during multi-disk/TPM copy | Passed; source retained ownership and the same boot ID/TPM proof; destination stopped and unregistered, partial state retained. |
| Browser readiness, blocked Start, recheck and close during a request | Passed; late response did not reopen the dialog. |
| Browser ready preview and reverse-job submission | Passed; showed all three disks and software TPM, and rechecked before creating the job. |
| Real spaced-name zvol full/incremental replication and pruning | Passed; source count was 1 after both generations and retention pruning. |
| Keep-data replication retirement | Passed; old replica stayed inert and source bases were released only after acknowledgement. |
| Spaced-name zvol Warm preparation and cutover | Passed; destination used the encoded device alias and checksum matched. |
| Spaced-name zvol cold conversion to raw image | Passed; checksum matched, 64 MiB logical image remained sparse, temporary receiver setting restored. |
| Later deletion of kept replica | Passed; partner outage blocked cleanup, retry retained the same operation identity, and acknowledged deletion displayed zero points. Source disks remained intact. |
| Exact source/package/installed bytes | Passed; all 98 source files match the TXZ and all 97 installed runtime files match on both labs. The remaining file is the package post-install script. |
| DEV02 host reboot with the cached unpublished package | Passed; same host identity, valid licensing, array restored, fresh native probe, authorized TPM-owner start and sealed-secret/disk proofs after reboot. |
| DEV01 host reboot with the corrected cached package | Passed; all guests were off, the same host identity and 12 saved settings/key/hook/package fingerprints survived, all 97 runtime files matched, licensing/array were restored and the fresh native probe passed. |
| Windows Server 2025 Core TPM/BitLocker baseline | Passed; XTS-AES-128 encryption reached 100% with protection on, TPM and recovery protectors present, recovery material saved privately outside the guest, and a full stop/start unlocked without supplying that recovery key. |
| Windows/BitLocker live migration in both directions | Passed on the corrected candidate; source definition retired before destination execution, with the same guest boot and in-memory probe token/process across each move. TPM endorsement-key hash, protector IDs, OS/data canaries and active encryption matched. |
| Windows protected cold boot on each destination | Passed; full stop/start on DEV02 and after return to DEV01 unlocked through the transferred TPM without entering recovery material or suspending BitLocker. Eight saved Windows evidence records compare successfully. |
| Running TPM/BitLocker owner upgrade admission | Passed; upgrade was refused before replacing files or stopping daemons. VM execution, authority and daemon identities were unchanged. |
| Independent TPM-TIS guest, three mixed-format disks | Passed; live move retained the same boot and PCR-sealed secret/public-object proof, and a full stop/start still unsealed it with both disk hashes unchanged. |
| Final-build cancellation during TPM-TIS return copy | Passed at about 11%; source retained the same boot, sealed secret and disk hashes. Receiver stopped and unregistered, with partial data retained. Four saved TIS proofs compare successfully. |

The integration tests found and fixed TPM-binding sensitivity to XML indentation, NBD stream serialization with multiple disks, and rejection of libvirt's harmless runtime metadata on an empty CD-ROM. The first Windows attempt also exposed a five-minute per-channel idle timer closing the small disk's NBD session during a longer transfer. The corrected candidate uses a bounded total transfer window and keeps checking current authority; both subsequent Windows directions passed, each taking more than eight minutes. Regression tests cover these cases. Real failed attempts retained their old/partial state rather than overwriting it on retry. After the Windows failure, the source retained the same guest boot, RAM probe, TPM identity, protectors and both disk canaries; the destination was stopped and unregistered.

Windows subprocess/temp-directory checks are not treated as passes where the local sandbox prevents them; the corresponding privileged Linux checks are authoritative for this Linux plugin.

## Review status and evidence scope

The planned lab acceptance gates are complete. Windows qualification used Server 2025 Standard Evaluation Core, 2 vCPUs, 4 GiB RAM, UEFI, TPM-CRB 2.0, a 40 GiB qcow2 OS disk and a 128 MiB raw data disk. BitLocker used an active TPM-only OS protector plus a saved recovery-password protector. This is not separate qualification of Windows 11, TPM+PIN policies or every Secure Boot/PCR configuration. Both software TPM frontends have real guest-level evidence. The handwritten Windows fixture's missing VNC WebSocket endpoint was corrected without changing VGA; its signed Server 2025 VirtIO drivers and guest agent work.

Qualified release package SHA-256: `8eceb269e6fcef073a62d9b0b4f53f77b57078b2ec764b1423392d4bd83b2b85`. Publication reuses these tested package bytes. At qualification completion the build was installed only on the two lab hosts; publication does not update production hosts. New test fixtures were stopped; retained source/partial storage and private evidence remain available. The Windows fixture's original disk, XML, NVRAM and TPM were preserved for inspection. On this slow lab link (about 31–34 MiB/s for Windows), background servicing caused substantially more dirtying than the initial sample. Update-download services were temporarily stopped for the retry and restored afterwards; their startup configuration, BitLocker and Defender were not disabled. No CPU throttling, post-copy or larger forced stun was used to obtain a pass.

## Deliberate limitations

No live zvol/block disks, attached ISO media, passthrough devices, AMD nested live guests, encrypted/custom-profile TPM backends, post-copy or automatic failover. Only the qualified libvirt 12.2.0 transport is admitted. Compatible CPU/machine/firmware, equivalent networking and sufficient measured bandwidth are still required. The beta admits one live migration per host at a time and retains old disks/TPM state for review. Cold/Warm Move and scheduled replication keep their separate support rules.

The encrypted-TPM-backend exclusion is separate from guest disk encryption: the qualified Windows guest kept BitLocker enabled. Keep guest recovery material available and qualify important workloads on the intended hosts; a short dirty-rate sample cannot guarantee convergence under later workload changes.
