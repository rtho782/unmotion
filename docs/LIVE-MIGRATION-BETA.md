# Live migration — 0.5.0-beta4

Author: Richard Skinner

[0.5.0-beta4](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta4) is an experimental prerelease on the [beta feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg). Stable and Community Apps remain on 0.4.9. Use disposable VMs and verified backups for initial testing.

Live migration copies a running VM's disk and RAM without a guest shutdown/reboot, preserving its UUID, MAC addresses, CPU profile and resources. A handover pause is unavoidable. A 500 ms stun is a target, not a guarantee: initial nested-lab tests observed approximately 1.85–2.13 second packet gaps, including ownership confirmation.

Cold migration and Warm Move retain their existing powered-off handover. They do not require a shared CPU mask and remain options for otherwise compatible Intel/AMD hosts.

## First-beta requirements

- Matching Beta4 builds, reciprocal pairing and a proven native start fence on both hosts.
- x86_64 KVM, a confirmed same/newer CPU generation from the same vendor and a compatible **active** custom CPU definition. Apply a shared mode and fully stop/start first; changing next-boot XML or rebooting inside the guest is not enough.
- Exact versioned Q35/i440fx machine, matching QEMU/libvirt versions and unchanged resources. The migration-only control adapter is qualified for **libvirt 12.2.0**; other versions fail preflight without an unrestricted fallback.
- One independent raw/qcow2 file on a direct pool/disk path with `cache="none"` or `directsync`. No user-share indirection, backing chain, internal image snapshot, encrypted image, hard link or disk used by another VM.
- BIOS firmware and ordinary bridge NICs. Bridge names are checked; equivalent physical networks and in-guest VLAN settings remain your responsibility.
- Intel VMX guests require KVM nesting enabled on both hosts, a fully validated nested CPU contract and compatible nested workloads. CPU application preserves existing VMX/SVM exposure, but cached base-mask matches alone do not certify nested requirements. AMD SVM-enabled guests are blocked from live migration; the kernel warns of unsafe behavior with active nested AMD workloads. See [kernel guidance](https://docs.kernel.org/virt/kvm/x86/running-nested-guests.html).

UEFI/NVRAM, TPM, PCI/GPU/USB passthrough, ISO/CD-ROM devices, zvols, multiple disks, host CPU/NUMA pinning, huge pages, memory hotplug, shared-memory/filesystem devices and QEMU overrides are excluded. Replication/recovery-managed guests and VMs with prepared Warm Moves are excluded. Other workflows keep their existing support for these configurations.

Only one live migration may run per host. Independent ordinary Warm Moves retain their existing concurrency. After entering live management, this beta permits live migration and native owner starts, but blocks that VM's Cold/Warm Move, cloning, replication-policy and bulk CPU changes. A safe hand-back/unmanage operation is not implemented; do not move a guest live if you need those workflows afterwards.

## Workflow

1. Opt into the beta feed through **Plugins → Install Plugin** on both **test** hosts, then refresh unMotion. Finish active operations and stop recovery/live-managed guests before updating. Verify pairing/native-fence readiness and keep restorable backups.
2. Select the intended destination and choose **Live migrate (beta)** on a running VM. The styled dialog opens immediately and enables submission only after fresh checks. Cached CPU/inventory displays never authorize migration.
3. Start the job. It reserves an independent destination file, measures the encrypted link and guest RAM dirty rate, then copies disk/RAM while the source runs. At least **4× headroom** is recommended; disk writes and other traffic also matter. A short sample is not a throughput guarantee. Very poor measured headroom aborts; unavailable dirty-rate evidence is logged as unknown.
4. Follow **Live migration — beta** and its log on the source. The destination row shows its reservation state until commit. **Request cancellation** asks libvirt to abort, not force-power-off the source. unMotion requests abort after a 30-minute transfer limit. No postcopy, unsafe flag, automatic CPU throttling or timeout-based offline fallback is used.
5. Validate networking and applications after completion. The source definition is unregistered, but its disk/XML evidence and partial destination disks are retained. Automatic storage reclamation is not included.

Source native autostart is disabled before transfer. A durable source fence prevents new starts while ownership is unresolved/retired. The destination receives the VM paused and freshly confirms that the source QEMU is absent and durably retired before resuming its exact admitted QEMU. Normal native owner starts remain possible on the committed destination.

## Interrupted jobs

Do not manually boot a restored source XML, resume an uncommitted incoming VM or delete authority files. Use **Reconcile** on the source after its worker and native libvirt jobs have stopped. Both hosts must be reachable.

Reconciliation either completes a proven stopped-source/paused-destination handover, or proves the incoming VM aborted before returning source ownership. A stopped source is not automatically booted. A paused original source can resume only after exact process verification and destination absence. Lost runtime credentials require an explicit local reconciliation permit and fresh authenticated callback; credential renewal never grants VM execution authority by itself.

Unknown state, missing/corrupt records, a changed QEMU lifetime, a busy job or an unreachable peer blocks action. There is no automatic failover/force takeover. Finish/reconcile jobs and power off live-managed guests before upgrading. Unresolved ownership blocks normal upgrades/removal; verified early-boot package restoration preserves fencing. Existing Unraid hooks and recovery one-use permits remain in place.

## Transport and qualification

Wire protocol 7 is unchanged. Live migration is optional and requires this implementation on both peers; older protocol-7 hosts retain their existing workflows.

Control/RAM/disk traffic uses the existing pinned, forced-command root SSH pairing. No general remote shell, unrestricted libvirt socket, SSH forwarding grant, public migration listener or HTTPS peer API is introduced. The control adapter validates the reviewed ABI, exact job XML, disk/socket identities, operation order and paused-destination flags. Unknown/replayed operations fail closed.

The test lab is nested on one physical Intel system. Those results do not qualify every guest OS, CPU generation or physical network. See the [qualification record](TESTING-LIVE-BETA3.md) for observed passes, issues and untested cases. Windows, i440fx end-to-end migration, heterogeneous physical hardware and destination-host power loss require separate qualification. This is not general-availability live-migration support.
