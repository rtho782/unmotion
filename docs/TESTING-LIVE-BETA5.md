# Beta5 qualification

Author: Richard Skinner

Status: lab-qualified build for the [0.5.0-beta5 prerelease](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta5), published on the beta feed. Stable remains 0.4.9. No production VMs or production plugin installations were changed by this work.

## Environment and restoration

The disposable DEV01/DEV02 hosts run Unraid 7.3.4 and 7.3.2 respectively, with libvirt 12.2.0 and QEMU 10.2.3. Both are nested Intel guests on the same physical host. Their four exact backing datasets were snapshotted while both outer VMs were powered off before Beta5 cutover tests. Licensing USB mappings were retained and both arrays restored through Unraid's native command interface.

The existing Linux checkouts were preserved. Builds and privileged filesystem/process tests run in isolated Linux staging/mount namespaces. Private helpers, credentials, original Unraid editor sources and local rules are excluded from source exports and packages.

## Results

- The complete Linux verification suite passed for successive candidate builds, including legacy migration/storage/TPM/NVRAM, transport, CSRF, native fencing, concurrency, process identity, cached CPU/inventory and browser-unit regressions. Root-only chroot/sparse rsync and isolated process tests also ran. New coverage includes CPU default/editor validation (30 checks), guarded ordinary handover (37, including released job UUID layouts), live native fencing (33), live preflight (59), live worker/transport progress (12), and browser-session/default-response isolation.
- Native Beta4-to-Beta5 installation succeeded on DEV02. DEV01 also exercised explicit same-version development rebuild installation; these are unpublished packages, not replaced public release assets.
- In a real browser on DEV01, selecting Skylake-Server-v5 as the default exposed it in Unraid's CPU dropdown. A new 512 MiB VM used it on its first start. Changing the default to Broadwell-v4 left the existing VM's Skylake profile selected as **Keep existing**. A native description edit preserved its CPU, topology, vCPU count and pinning.
- Saving a native form template retained its Skylake CPU despite the changed Broadwell default. Creating a VM from that template produced a distinct UUID and the retained CPU. A handed-back Intel nested fixture also preserved its complete CPU features, topology, vCPU count and pinning through a native description edit. Restore/reinstall of the adapter recovered the exact original editor hashes before reapplying it.
- An isolated Ubuntu 26.04 UEFI/no-TPM guest, using an independent qcow2 disk and private NAT bridge, migrated DEV01 to DEV02. Its guest boot ID remained unchanged and a nonvolatile EFI canary retained identical contents. A later full stop/start changed the boot ID but preserved that canary, verifying destination NVRAM persistence. The image was checked against [Ubuntu's published SHA-256 file](https://cloud-images.ubuntu.com/releases/resolute/release/SHA256SUMS).
- Isolated state-machine tests cover stale hand-back reviews, lost acknowledgements/retry, pre-retirement-only rollback, exact peer/nonce binding, unsupported peers, source retirement before destination requests, immutable definition input and rejection of changed committed-owner retries. Native tests retain fencing after non-owner hardware/name changes and reject pending hand-back and ordinary incoming-live-permit bypasses.
- Relative `xmlns="unraid"` metadata exposed libxml canonicalization failure. Namespace-safe structural fallback tests distinguish changed CPU/metadata instead of hashing an empty result; valid canonical XML keeps its previous hash.
- Real paired hand-back succeeded for the new UEFI guest and an existing Beta4 Intel-nested guest. A cold transfer returned the UEFI guest DEV02 to DEV01; a subsequent Warm Move returned it DEV01 to DEV02. The final cutovers kept exactly one owner, preserved the EFI canary and unregistered the source while retaining its storage. New ordinary jobs explicitly record their VM UUID for the ownership transaction.
- The cold test exercised a failed destination start because an administrative test `rc.unmotion stop` had installed a host-shutdown fence. Both VMs stayed off. After explicit lab restart/reconciliation and removal of only that test stop fence, **Finish guarded handover** was idempotent; normal migration resolution completed the original unregistration policy. Resolution permits only the exact retired ordinary job to finish its source policy, not generic operations on a non-owner.
- UEFI cancellation passed before copying and during disk/RAM copying. Libvirt aborted the transfer, the source remained running/owner, the incoming VM was stopped/unregistered, and partial disk/NVRAM state remained available for inspection.
- DEV01 rebooted with the matching installed descriptor and cached package. Beta5 and the editor adapter restored at boot without a public release. The CPU-default and saved-profile hashes were unchanged; licensing USB remained attached and the array was restored. A handed-back owner started after reboot, while an aborted incoming definition was rejected by the native hook before QEMU. The disposable owner-start probe was stopped afterwards.
- A real two-tab browser logout produced the session-expiry banner in the existing unMotion page. Isolated tests verify subsequent plugin API calls abort without network requests. Authentication was restored for continued lab use.

## Growing image seed regression

The original worker was reproduced failing after a running guest added 512 MiB during a qcow2 seed. The received image matched the file-list length, but the later source-size comparison rejected it. The revised Beta5 worker checks exactly one regular file's rsync statistics and the actual destination length. Transfer errors, malformed/duplicate statistics, wrong destination lengths and changing powered-off source lengths remain failures. Online copies are explicitly provisional.

The final mutable-image rsync pass uses `--ignore-times` only for Warm Move images, retaining delta/sparse copying and stable source/destination length checks. It runs after shutdown and cannot skip stale blocks merely because file size/timestamps match. Cold/ISO quick-check behavior and ZFS snapshot/hold/resume paths are unchanged.

The full Linux suite passed with 30 dedicated growing-file/parser/final-transfer checks and 24 confined-transport integration checks. Tests execute both shipped progress parsers, reject truncated destinations and malformed evidence, and repair differing data with identical size/mtime through the real confined rsync receiver. ShellCheck passed for the new helper/test (dynamic source exclusions only), and error-level checks passed for both workers.

In the lab, **Squid Proxy Growing QCOW2** used an independent Ubuntu 26.04 UEFI disk and private NAT networking. Each online test added a new 512 MiB guest file after copying had begun:

| Source | Operation | Verified rsync and destination bytes | Source bytes at verification |
| --- | --- | ---: | ---: |
| DEV02 | Prepare | 3,612,409,856 | 4,149,280,768 |
| DEV02 | Update | 4,149,280,768 | 4,686,348,288 |
| DEV01 | Prepare | 4,687,527,936 | 5,224,464,384 |
| DEV01 | Update | 5,224,464,384 | 5,761,335,296 |

All four operations reached READY while the source remained running; the destination stayed read-only with no VM definition. DEV02-to-DEV01 cutover completed through graceful shutdown, and all four existing 512 MiB guest files retained identical SHA-256 hashes. A subsequent powered-off Update on DEV01 synchronized the remaining changes. Repeating it transferred zero file data while verifying the full 5,761,531,904-byte image and reaching READY again.

Return cutover to DEV02 then completed. Its final pass processed one complete 5.76 GB image with zero literal data and 5.76 GB matched data, demonstrating a forced delta rather than a timestamp-based skip. All six 512 MiB guest files retained their SHA-256 hashes. Each cutover left only the destination defined/running and unregistered the source according to the selected policy; source storage was retained.

Failed disposable staging was removed only through the exact seed's normal removal workflow, with its reproduction logs retained privately and source disks unchanged. Temporary bandwidth/image-directory settings were restored without replacing unrelated settings. The new test guest was gracefully stopped on DEV02; DEV01 has no remaining definition for it. Source/partial test disks and the outer snapshots remain available for review.

## Qualification limits

Windows guests, i440fx end-to-end migration, heterogeneous physical CPU generations, physical 10/25 GbE and actual destination-host power loss during transfer are not qualified by this nested Linux lab. Lost acknowledgements, stale proofs and retry branches are covered by isolated tests, not every possible physical fault. Replication-only configuration is permitted by the hand-back interlock, but the shared-dataset test fixture is not eligible for native scheduled ZFS replication; this work does not claim a new end-to-end replication run for it.

TPM live migration, zvol/multiple-disk live migration, AMD nested live migration and automatic failover remain out of scope. Recovery arming after ordinary hand-back remains blocked pending an explicit authority-system handover design. Original lab storage settings were restored. Existing recovery fixtures were left unchanged, and the Beta5 test guests were left stopped; retained source/partial data and private snapshots remain for review.

## Build and publication

Release identity: `0.5.0-beta5`, installer `2026.11.09.02`. Protocol 7 is unchanged; optional live capability is 2 and guarded ordinary handover capability is 1. Both lab hosts have the tested build. Publication uses those exact TXZ/PLG bytes without rebuilding, advances only the beta feed and updates the companion Community Apps guide. The stable feed and Apps template remain on 0.4.9; production updates are left to the owner.

SHA-256 of the qualified release files:

```text
002dd8133cb65d5e5845d2a9dd3a76fe19f9976150bf456743a682dc3405302c  unmotion-0.5.0_beta5-noarch-1.txz
0ce7163d72848e399bd01c30479832e291e4fdfb58d0518bb3ae299a010f449c  unmotion-0.5.0-beta5.plg
```
