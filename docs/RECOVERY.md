# Recovery and fencing

## Scope and release boundary

This document specifies the recovery control plane that sits above the inert scheduled-replication inventory described in [REPLICATION.md](REPLICATION.md). Implemented capabilities and deferred designs are distinguished below.

Stable 0.4.2 requires protocol 7 on both peers, including signed recovery envelopes. The durable schema-1 authority record retains its historical `protocolVersion: 6` field; this does not permit protocol-6 wire traffic.

Current capabilities include a coordinated, manual-recovery path:

- explicit recovery arming between two protocol-7 peers;
- recovery eligibility based on QEMU Guest Agent evidence;
- manual selection and activation of a verified recovery point;
- TPM/NVRAM recommendation and exact authorized checkpoint reinstall while stopped;
- source autostart suppression, managed-start authorization, native pre-start fencing and secondary lifecycle fencing;
- graceful-shutdown holdoffs;
- authenticated source fencing and a short-lived coordinated activation grant;
- durable recovered/split-brain states, activation removal and cold-failback preflight.

unMotion does not automatically start a replica after a timer expires and does not claim authority from an unreachable source. Network-silence/two-host claims, witness voting, alternate-checkpoint selection, failback transfer, reverse replication after promotion, shared-dataset conversion, hardware fencing/STONITH and unattended split-brain resolution remain deferred.

Replication and recovery both require protocol 7 peers; recovery additionally requires recovery protocol 1 and verified native hooks. An upgrade must never arm recovery or alter autostart implicitly.

## Terms and authority record

- **Source**: the normal authoritative host and VM.
- **Destination**: the host retaining inert recovery points.
- **Recovery pair**: the exact source and destination for one replication policy.
- **Term**: a monotonically increasing durable authority generation.
- **Activation ID**: a random identifier for one recovery attempt within a term.
- **Coordinated claim**: the reachable source confirms that its VM is stopped and fenced before granting the destination authority.
- **Fenced**: unMotion will not permit a managed VM start on that host.
- **Holdoff**: a durable source notice that expected shutdown or reboot must not trigger recovery before a deadline, or until explicit release.

Each armed policy has one authority record:

```text
policyId, vmUuid, sourceHostId, destinationHostId
term, authority = SOURCE | DESTINATION | UNKNOWN
state, activationId, recoveryPointId, checkpointId
claimKind = coordinated
managedAutostart, desiredAutostart
holdoff, lastEvidenceHash
sourceBootId, destinationBootId, updatedAt
```

The source record is stored with the replication policy under `/boot/config/plugins/unmotion/replications/<policy-id>/recovery.json`. The destination stores its corresponding record below `/boot/config/plugins/unmotion/replicas/<source-host-id>/<policy-id>/`. Authority, terms, holds, grants, activation ownership and cleanup journals are durable transition state. Probe samples and progress belong under `/var/run` or `/var/lib`; logs belong under `/var/log`. High-frequency heartbeats must not write the USB boot device.

Every state-changing message is authenticated by the existing reciprocal pairing, contains all three durable identities, a term, sender boot ID, random nonce and expiry, and is idempotent by request ID. The initial per-pair recovery secret exchange is detached-signed by the sender's existing Ed25519 peer key and verified against the exact reciprocal peer record before the secret is installed; an SSH session alone is not accepted as proof of which paired host invoked the root agent. Subsequent protocol-7 recovery envelopes and responses use that exact per-pair secret. Receivers reject identity mismatches, stale terms, replayed nonces, expired or clock-ambiguous requests, reused request IDs with different content and a second activation ID in the same term. The durable state is written before an acknowledgement or external VM action.

## Recovery eligibility

Replication is allowed without a Guest Agent, but recovery is not. A recovery point is eligible only when:

1. every disk snapshot is `AVAILABLE`, GUID-verified and still has its exact unMotion hold;
2. its source XML and any selected TPM/NVRAM archive pass their recorded SHA-256 and exact-path checks;
3. the point records a successful Guest Agent observation with usable network metadata; for a powered-off capture this may be a VM/MAC-bound observation made immediately before shutdown or within a configured freshness limit;
4. the point was powered off or successfully filesystem-quiesced, rather than merely crash-consistent;
5. the destination has no defined VM with the same UUID and no activation object owned by another attempt;
6. no cleanup, receive or inventory transaction for the policy is incomplete; and
7. the recovery policy is armed under protocol 7.

Guest metadata contains the observation time, interfaces, MAC addresses, all usable IPv4/IPv6 addresses and the preferred probe address. The source refreshes it whenever replication observes a change. A stale address can only make recovery more conservative: any positive response blocks activation, while silence is never used without the other evidence checks.

PCI-passthrough VMs remain ineligible until destination remapping has a separate reviewed design. Direct root/libvirt starts of recovery-managed VMs require native pre-start admission as described below. An administrator deliberately removing the hook or rewriting recovery identity/storage remains outside this enforcement boundary.

## TPM and NVRAM selection

Disk points and host-state checkpoints are separate immutable objects. The recovery wizard lists all hash-verified checkpoints and labels them `safe`, `best-effort`, `unstable` or `missing`. `unstable` and `missing` checkpoints are not selectable for a normal boot.

The recommendation order is:

1. the safe checkpoint referenced by the chosen disk point;
2. the closest safe checkpoint captured at or before the disk point;
3. the closest safe checkpoint captured after the disk point, with a forward-state warning; then
4. the chosen point's best-effort checkpoint, with a prominent risk warning.

Quality ranks before time distance. A safe checkpoint is never displaced by a closer best-effort checkpoint. Ties prefer the earlier checkpoint. The UI shows the signed time difference and whether TPM and NVRAM are both present. A checkpoint is compatible only when it covers exactly the devices declared by the recovery XML; a fallback for a different TPM/NVRAM layout is rejected.

Activation copies the selected archive into exact UUID-scoped libvirt paths only after confirming the VM is undefined and stopped. If the VM does not boot or the Guest Agent does not return within the configured boot-health timeout, the state becomes `RECOVERY_BOOT_FAILED`. unMotion may reinstall only the checkpoint already authorized by the coordinated claim. It validates before mutation, journals `PREPARED` and `SWAPPING`, backs up the exact activation-owned state, obtains a fresh source fence grant, and restores the backup on failure. Choosing a different checkpoint requires a new authority transaction and is deferred. TPM/NVRAM is never swapped while the VM is running; the disk activation remains unchanged during retry.

For a VM without a virtual TPM, the wizard still verifies NVRAM where present and explains that recovery is normally more reliable without TPM state coupling.

## Source autostart interlock

Arming recovery transfers responsibility for source startup to unMotion:

1. Require the source VM to be provably shut off. unMotion does not grandfather an already-running VM through the `ARMING` transition.
2. Record the user's previous desired autostart value in the recovery policy.
3. Disable Unraid's built-in autostart for the source VM and verify it remained disabled.
4. Verify the start-gate helper, running lifecycle monitor and proof of native pre-start hook invocation.
5. Ask the destination to durably arm its matching policy.
6. Mark the policy `STANDBY` only after both hosts acknowledge the same term and identities.

The lifecycle monitor detects attempts to re-enable native autostart, turns it off again and sends a critical Unraid notification. The native VM-page control is not guaranteed to be greyed out. Arming checks the installed start-gate helper and running lifecycle monitor.

The policy retains a separate `desiredAutostart` setting. On source boot, unMotion reconciles authority before its managed startup. It may start a desired VM only after the destination confirms matching authority and that no recovery activation is running. If the destination is unreachable or reports a recovered activation, unMotion does not start the source.

Use unMotion's recovery controls to start managed VMs. Native VM-page and direct `virsh` starts without a permit are rejected before QEMU. The lifecycle monitor remains a secondary fence: if it observes an unauthorized running source, it records the violation, requests shutdown and escalates to a forced stop if needed.

### Native pre-start fencing

unMotion installs `qemu.d/50-unmotion-recovery` alongside the existing Unraid hook. It checks local durable authority and a short-lived single-use permit before QEMU starts; it never calls libvirt or contacts a peer from inside the hook. This follows libvirt's [synchronous hook requirements](https://libvirt.org/hooks.html). Ordinary unmanaged VMs are not enrolled by installing the plugin.

Source permits require fresh destination authorization. Recovered-VM permits bind the exact activation, disk paths, authority term, host boot and fresh source-fence grant. Native restore, incoming live migration and attach cannot bypass this admission. Accepted starts have a runtime launch journal; a source start still in progress blocks a destination authority grant even if libvirt has not yet reported the VM running. Admission subsequently binds the exact QEMU process lifetime, and release removes it. A libvirt reconnect is not permission to start a new QEMU process.

Arming and source-fence grants require callback proof for the current libvirt process, host boot and installed hook/helper hashes. The controller verifies this using an exact disposable no-disk/no-NIC probe that must be rejected before startup. Existing Unraid hooks are not overwritten and libvirt is never restarted automatically. If verification reports that a restart is needed, stop guests and restart VM Manager, then run `unmotion-native-fence probe`; the lifecycle also retries outside its recovery locks. The hook is kept in the active libvirt configuration and restored if a later libvirt-image mount hides the initial copy. Until verified, recovery remains blocked.

Use **Start source VM** in source Recovery controls for a one-off managed start without changing desired autostart. Existing destination activations use **Start recovered VM**. Native VM-page/direct starts without a permit are refused. Lifecycle monitoring remains as a secondary fence.

Upgrades require recovery-managed sources and recovered activations to be shut off; they are not silently grandfathered into new process admissions. Uninstallation is refused while recovery authority is armed or unresolved. Historical candidate results and final release verification are tracked in [TESTING-042.md](TESTING-042.md).

Disarming restores built-in autostart only after source and destination agree that no recovery activation, holdoff, failback or unresolved term exists. Peer removal and downgrade are blocked while a recovery policy is armed.

## Graceful holdoffs

Before an intentional VM power-off/restart, the user may send `recovery-hold` from the recovery UI. Before a source-host shutdown/reboot, the service automatically prepares a hold:

```text
requestId, policyId, term, sourceBootId
reason = vm-poweroff | vm-restart | host-shutdown | host-reboot
issuedAt, holdUntil = UTC timestamp | forever
```

The destination writes the hold durably before acknowledging it. A hold blocks evidence claims and activation until it expires or a matching `recovery-hold-release` is accepted. The configured duration may be finite or forever. The UI shows the reason, source boot ID, acknowledgement state and remaining time.

The shutdown hook writes its local shutdown fence before peer contact and retries for a bounded interval without deadlocking host shutdown. If the destination cannot acknowledge, the source records the unsent hold, sends a critical notification and remains startup-fenced on its next boot until it reconciles with the destination. An automatically prepared forever host hold is released only after a real source boot-ID change and authenticated peer reconciliation. A same-boot plugin restart remains fenced. Ordinary forever holds require an authenticated release from the source.

When a scheduled run observes a powered-off VM, it may opportunistically promote a safe TPM/NVRAM checkpoint. That observation does not itself clear an intentional hold.

## Coordinated authority

### Reachable source

If the source answers authenticated recovery status, manual recovery is coordinated. A running source VM is a hard blocker; the UI directs the user to migration or an intentional shutdown. A stopped source may grant recovery only after it records `SOURCE_FENCED`, confirms built-in autostart is off and advances the shared term. The destination persists the grant before activation.

### Deferred: two-host evidence claim

unMotion rejects recovery when the source is unreachable. Failed or ambiguous SSH/TCP contact, guest silence, gateway reachability and DNS success cannot prove that the source is powered off and therefore cannot grant authority. The probe helpers below remain design groundwork only; they are not advertised by capabilities, offered by the UI or accepted by the current evidence API.

All of these checks must pass for at least three samples spanning 30 seconds, and the final sample must be less than 15 seconds old:

- authenticated source contact fails by every recorded address;
- every recent Guest-Agent-reported VM address is silent to ICMP and, on directly attached networks, ARP/NDP neighbour probing;
- the destination can ping its current default gateway;
- the destination can resolve a configured DNS probe name through its normal resolver; and
- an optional configured external IP, such as `1.1.1.1`, responds when that stronger check is enabled.

Any guest response, authenticated source response, gateway failure, DNS failure, active holdoff, clock anomaly or local network change resets the evidence window. Even a passing window does not authorize current activation.

### Deferred: witness/quorum claim

Witness enrollment, voting and quorum claims are not implemented or advertised in the current release. The notes below describe a possible later protocol and are not executable behavior.

A future destination could first satisfy a local evidence rule, then request a short-lived witness vote containing the proposed term, activation ID and evidence hash. Each witness would independently repeat authenticated source contact, guest-address, gateway and DNS checks and refuse conflicting votes.

A future claim would require `floor(member_count / 2) + 1` durable votes bound to one activation ID. unMotion creates no witness records and accepts no witness vote RPC.

Automatic failover and timer-driven activation remain deferred regardless of future witness design.

## State machine

The durable policy state is one of:

| State | Meaning and permitted exit |
| --- | --- |
| `REPLICATION_ONLY` | Recovery is not armed. Replication may continue. `arm` begins interlock setup. |
| `ARMING` | Autostart and peer capabilities are being verified. Failure rolls back only if both hosts confirm no activation; otherwise enter `FENCED`. |
| `STANDBY` | Source authority is current, destination inventory is inert, and manual recovery may be requested. |
| `HOLDOFF` | A durable graceful hold is active. Only expiry or the matching release returns to `STANDBY`. |
| `SOURCE_START_FENCED` | Source boot/start is waiting for destination and term confirmation. Success returns to `STANDBY`; uncertainty remains fenced. |
| `EVIDENCE_GATHERING` | A manual claim is requesting an exact coordinated grant from the reachable source. Failure returns to `STANDBY`, `HOLDOFF` or a fenced/unknown state if authority is ambiguous. |
| `RECOVERY_READY` | A short-lived claim and exact point/checkpoint selection are durable. Expiry returns to `EVIDENCE_GATHERING`. |
| `ACTIVATING` | Activation-owned ZFS clones, host-state files and XML are being prepared. Retry is idempotent by activation ID. |
| `RECOVERED_STOPPED` | Destination owns the term and activation, but the VM is stopped. It may start, be removed or begin failback. |
| `RECOVERED_RUNNING` | Destination is authoritative and its Guest Agent passed boot health. Source starts remain fenced. |
| `RECOVERY_BOOT_FAILED` | Destination VM is stopped or being fenced after failed health. unMotion may reinstall only the already authorized checkpoint, or remove the activation. |
| `SPLIT_BRAIN_SUSPECTED` | Conflicting liveness, terms or authority records exist. No start, replication-base advance, prune or failback commit is allowed. |
| `SPLIT_BRAIN_FENCING` | unMotion is stopping its local non-authoritative/uncertain copy and verifying it stopped. |
| `SPLIT_BRAIN_UNRESOLVED` | Automatic fencing failed or both copies may have diverged. Both managed autostarts stay disabled; explicit survivor selection is required. |
| `FAILBACK_PREPARING` | Reserved for future failback transfer; not entered by current preflight. |
| `FAILBACK_CUTOVER` | Reserved for future failback cutover; no current transfer action. |
| `SOURCE_RESTORING` | Reserved for future source restoration after failback. |
| `DISARMING` | Activation and coordination state are being removed after both hosts agree. |
| `FENCED` | A fail-closed administrative state caused by incompatible peers, missing interlocks or incomplete durable state. |

The principal transition is:

```text
REPLICATION_ONLY -> ARMING -> STANDBY
STANDBY -> EVIDENCE_GATHERING -> RECOVERY_READY -> ACTIVATING
ACTIVATING -> RECOVERED_STOPPED -> RECOVERED_RUNNING
ACTIVATING -> RECOVERY_BOOT_FAILED
Deferred failback only:
RECOVERED_* -> FAILBACK_PREPARING -> FAILBACK_CUTOVER
             -> SOURCE_RESTORING -> STANDBY
any armed state -> SPLIT_BRAIN_SUSPECTED -> SPLIT_BRAIN_FENCING
                                             -> SPLIT_BRAIN_UNRESOLVED
```

No transition infers success from process exit alone. It verifies libvirt state, exact ZFS GUIDs/properties, authority term and peer acknowledgement. Power loss at any step resumes the recorded transition or remains fenced; it never falls back to normal Unraid autostart.

## Activation transaction

unMotion activation uses clones or exact activation-owned copies of the selected snapshots. It never makes retained replica datasets writable and never destroys a recovery point.

1. Revalidate the claim, term, holdoff, QGA eligibility, point GUIDs, XML hash and checkpoint hash.
2. Persist `ACTIVATING` with activation ID and an exact object/path plan.
3. Create activation datasets/zvols from the selected snapshots under activation-specific names.
4. Keep filesystem clones unmounted and zvols hidden until the complete multi-disk set is verified.
5. Copy TPM/NVRAM material into exact temporary activation paths, then atomically install the UUID-scoped files.
6. Transform only disk paths and other destination-specific runtime fields in the inert source XML. Preserve UUID and identity needed by the guest; disable native autostart.
7. Recheck that no destination VM with the UUID or name was defined during preparation.
8. Define the VM stopped, persist `RECOVERED_STOPPED`, and revalidate evidence/authority.
9. Start it only from `RECOVERED_STOPPED`, then require Guest Agent health and record `RECOVERED_RUNNING`.

An error before definition removes only activation-owned partial objects or journals exact retained cleanup; an error after definition leaves the VM stopped. No wildcard, recursive destroy or unverified path deletion is permitted.

## Split-brain handling

Split brain is suspected when both peers report the VM running, guest liveness contradicts the authority record, a peer presents a higher or conflicting term, or a source starts while a recovery activation exists.

The host detecting a conflict writes `SPLIT_BRAIN_SUSPECTED` before acting, then requests a graceful guest shutdown and uses bounded local force-stop when required. A source that has not started remains fenced when it learns of a destination claim. A coordinated term prevents managed source startup, but an already-running conflicting source still requires explicit survivor review after local fencing. unMotion offers no automatic survivor selection.

The UI shows terms, activation IDs, last disk capture, last observed guest addresses and which copy was stopped. It offers no merge operation. The user must identify the authoritative survivor and confirm the other VM is stopped before undertaking an explicit recovery action. Current controls support removal of a stopped activation and cold-failback preflight, not data merging or a failback transfer. Replication and pruning remain paused until both peers acknowledge the resolution term.

## Recovered VM actions and failback

unMotion exposes these deliberate actions:

- **Stop recovered VM**: retains activation data and destination authority.
- **Retry TPM/NVRAM**: only from a verified stopped state and only for the exact checkpoint already authorized by the coordinated claim.
- **Remove recovered activation**: requires the recovered VM stopped and removes only definition, activation clones and activation-owned host state. Recovery points remain inert. Source authority is not restored without a handshake.
- **Cold failback preflight**: verifies that the recovered VM is stopped, its activation and safe host state are exact, the source is reachable and fenced under the same term, no transfer/receive transaction is active, and capacity/conflict checks pass. unMotion does not begin the destructive failback transfer.

If the source returns after recovery, it stays fenced and the UI raises a critical failover notification with the destination term and point age. The user may keep the destination stopped/running while planning failback, remove a failed activation, or run cold-failback preflight. Dropping stale source data or retained recovery points is a separate exact-ownership cleanup action and is never implied by acknowledging the notification.

Warm failback is deferred. Its later transaction may seed from the running destination, but final cutover must stop it, transfer the final delta and safe TPM/NVRAM state, confirm the source start, and only then remove the destination definition. Both cold and future warm failback preserve the invariant that the same UUID is never deliberately running on both hosts.

## Protocol 7 compatibility

Current peer capabilities include:

```text
protocolVersion: 7
protocolMinVersion: 7
protocolMaxVersion: 7
recoveryProtocolVersion: 1
features: restrictedSshTransport, recoveryControlPlane, recoveryActivation,
          managedAutostart, gracefulHoldoff, evidenceProbe, checkpointRetry,
          activationRemoval, coldFailbackPreflight
witnessVote: false
automaticFailover: false
```

Both hosts must be upgraded to protocol 7. A protocol-5/6 peer cannot migrate, replicate or coordinate recovery with a protocol-7 host. Forced-command keys never fall back to unrestricted remote shells; unknown recovery commands fail before mutation. Existing durable identities and recovery authority are retained, not implicitly rearmed.

Discovery advertises `protocol=7`, `protocol_min=7` and `protocol_max=7`; decoded host ID remains the durable identity. Existing peer records are preserved on upgrade. Incompatible peers and lost recovery capability leave starts fenced and built-in autostart disabled until supported peers reconcile or safely disarm. See [upgrade requirements](SECURITY-042.md).

The recovery agent operations are versioned and idempotent:

- `recovery-status`
- `recovery-arm` / `recovery-disarm`
- `recovery-hold` / `recovery-hold-release`
- `recovery-grant`
- `recovery-claim-renew`
- `recovery-fence-status`
- `recovery-activation-commit`
- `recovery-failback-preflight` (read-only in the current release)

Read-only status/probe operations never grant authority. State-changing operations require the negotiated recovery capability and exact reciprocal host/policy/VM identities.

## Safety invariants

1. No recovery-managed VM start occurs without a durable authority term and a fresh start authorization.
2. An armed source VM's built-in Unraid autostart remains off; unMotion is the only managed autostart authority.
3. Boot, peer loss, downgrade, corrupt state and uncertain ownership fail closed with the VM stopped.
4. Replication-only or Guest-Agent-ineligible points cannot be activated.
5. A holdoff blocks coordinated recovery until valid release or expiry.
6. Source unreachability is a hard recovery blocker in the current release; failed network contact never grants authority.
7. Retained recovery points remain read-only and inert; activation uses exact owned clones/copies.
8. TPM/NVRAM replacement requires the VM undefined or stopped and exact UUID-scoped path/hash validation.
9. A current recovery point, foreign ZFS hold, resumable receive or partial multi-object transaction is never pruned implicitly.
10. Protocol-5/6 peers cannot transfer or coordinate recovery; capability-mismatched peers cannot arm or execute recovery.
11. Split-brain suspicion pauses starts, replication-base advancement, pruning and failback commit until fencing/resolution is durable.
12. Removing an activation, policy or peer never claims that remote data was removed without an authenticated exact cleanup acknowledgement.
13. unMotion permits only one armed or unreconciled recovery policy for a VM UUID; additional destinations fail closed rather than acting as an implicit quorum.
14. Legacy Move, Warm Move, Clone and replication-policy mutations are blocked for a recovery-managed VM and for an activation-owned recovered domain.
15. Armed retention never removes the last eligible or repairable recovery point merely because a newer replication-only point was published.

## Test gates

The regression matrix for recovery changes includes the following on disposable, outer-snapshotted lab hosts. This is a test plan, not a claim that every fault-injection permutation ran for each release; see [recorded 0.4.2 evidence](TESTING-042.md) for actual coverage.

- state-machine unit tests for every permitted transition and rejection of every invalid transition;
- power loss or process kill before and after every durable write, clone, XML definition, start, fence and failback acknowledgement;
- protocol-7 success and protocol-5/6 rejection in both directions, including upgrades, downgrade while armed and unknown-command rejection;
- CSRF-protected POST tests for all recovery mutations and replay/expiry tests for every remote mutation;
- reciprocal pairing and host-ID mismatch tests, including decoded `\032` discovery names;
- source-start fencing across clean/unclean source and destination reboots, with native autostart and attempted UI/direct starts;
- autostart UI greying where supported, fallback reversal and critical notification where it is not;
- finite, forever, released, expired, unacknowledged and power-loss holdoff cases;
- coordinated grant with source stopped/running/unreachable, active replication, lost reply, stale term, changed selection and expired grant;
- exact coordinated-claim renewal after expiry without changing the authority term, activation, point, checkpoint or selection hash;
- rejection of a second recovery destination for the same VM and worker-side rejection of legacy Move, Warm Move and Clone after a recovery arm wins a preflight race;
- explicit rejection of two-host, network-silence, witness and automatic-failover requests;
- exact recovery of a VM and dataset named `Squid Proxy`, including multi-disk zvol and isolated image-dataset layouts;
- QGA missing, stale and changing network metadata; only eligible powered-off/quiesced points may activate;
- TPM absent, safe, best-effort, changed, missing, incompatible fallback and exact-authorized-checkpoint reinstall/rollback cases;
- destination UUID/name conflict, activation partial cleanup, foreign holds and preserved receive tokens;
- deliberate split brain in the isolated lab, including successful self-fence, failed fence and explicit survivor resolution;
- recovered activation removal and cold-failback preflight, including refusal while either copy may be running;
- all existing cold migration, Warm Move, cloning and replication regressions.

Deletion, force-stop, split-brain and failback tests require backups/snapshots of the outer lab VMs. Production hosts and personal data remain out of scope.
