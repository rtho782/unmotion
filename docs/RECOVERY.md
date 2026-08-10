# Recovery and failover design for 0.4.0-beta2

## Scope and release boundary

This document specifies the recovery control plane that sits above the inert scheduled-replication inventory described in [REPLICATION.md](REPLICATION.md). It is a post-recovery design, not recovered beta7 behavior.

Beta2 is manual-recovery first. It may expose:

- explicit recovery arming between two protocol-6 peers;
- recovery eligibility based on QEMU Guest Agent evidence;
- manual selection and activation of a verified recovery point;
- TPM/NVRAM recommendation, selection and stopped-VM retry;
- source autostart interlocking and source/destination startup fencing;
- graceful-shutdown holdoffs;
- a two-host evidence gate, with an explicit split-brain warning;
- optional protocol-6 witness votes from additional paired hosts;
- durable recovered/split-brain states, activation removal and cold failback.

Beta2 does not automatically start a replica after a timer expires. Automatic failover, warm failback, reverse replication after promotion, shared-dataset conversion, hardware fencing/STONITH and unattended split-brain resolution remain deferred. The beta2 evidence and witness protocol is designed so automatic failover can later use the same guards without weakening them.

Replication can continue to a compatible protocol-5 peer, but recovery cannot be armed unless the source, destination and every configured witness negotiate protocol 6 and recovery protocol 1. An upgrade must never arm recovery or alter autostart implicitly.

## Terms and authority record

- **Source**: the normal authoritative host and VM.
- **Destination**: the host retaining inert recovery points.
- **Witness**: an explicitly selected paired host that stores votes but no replica.
- **Recovery group**: source, destination and zero or more witnesses for one policy.
- **Term**: a monotonically increasing durable authority generation.
- **Activation ID**: a random identifier for one recovery attempt within a term.
- **Coordinated claim**: the reachable source confirms that its VM is stopped and fenced before granting the destination authority.
- **Evidence claim**: the source is unreachable and the destination claims authority using the configured two-host or witness evidence rule.
- **Fenced**: unMotion will not permit a managed VM start on that host.
- **Holdoff**: a durable source notice that expected shutdown or reboot must not trigger recovery before a deadline, or until explicit release.

Each armed policy has one authority record:

```text
policyId, vmUuid, sourceHostId, destinationHostId
term, authority = SOURCE | DESTINATION | UNKNOWN
state, activationId, recoveryPointId, checkpointId
claimKind = coordinated | two-host-evidence | witness-quorum
managedAutostart, desiredAutostart
holdoff, witnessGroup, lastEvidenceHash
sourceBootId, destinationBootId, updatedAt
```

The source record is stored with the replication policy under `/boot/config/plugins/unmotion/replications/<policy-id>/recovery.json`. The destination stores its corresponding record below `/boot/config/plugins/unmotion/replicas/<source-host-id>/<policy-id>/`. A witness stores its vote below `/boot/config/plugins/unmotion/witness/<group-id>/<policy-id>.json`. Authority, terms, holds, grants, activation ownership and cleanup journals are durable transition state. Probe samples and progress belong under `/var/run` or `/var/lib`; logs belong under `/var/log`. High-frequency heartbeats must not write the USB boot device.

Every state-changing message is authenticated by the existing reciprocal pairing, contains all three durable identities, a term, sender boot ID, random nonce and expiry, and is idempotent by request ID. Receivers reject identity mismatches, stale terms, replayed nonces, expired evidence and a second activation ID in the same term. The durable state is written before an acknowledgement or external VM action.

## Recovery eligibility

Replication is allowed without a Guest Agent, but recovery is not. A recovery point is eligible only when:

1. every disk snapshot is `AVAILABLE`, GUID-verified and still has its exact unMotion hold;
2. its source XML and any selected TPM/NVRAM archive pass their recorded SHA-256 and exact-path checks;
3. the point records a successful Guest Agent observation with usable network metadata; for a powered-off capture this may be a VM/MAC-bound observation made immediately before shutdown or within a configured freshness limit;
4. the point was powered off or successfully filesystem-quiesced, rather than merely crash-consistent;
5. the destination has no defined VM with the same UUID and no activation object owned by another attempt;
6. no cleanup, receive or inventory transaction for the policy is incomplete; and
7. the recovery policy is armed under protocol 6.

Guest metadata contains the observation time, interfaces, MAC addresses, all usable IPv4/IPv6 addresses and the preferred probe address. The source refreshes it whenever replication observes a change. A stale address can only make recovery more conservative: any positive response blocks activation, while silence is never used without the other evidence checks.

PCI-passthrough VMs remain ineligible until destination remapping has a separate reviewed design. Direct root/libvirt commands are outside the enforcement boundary; lifecycle monitoring must detect them, fence the managed VM where possible and emit a critical Unraid notification.

## TPM and NVRAM selection

Disk points and host-state checkpoints are separate immutable objects. The recovery wizard lists all hash-verified checkpoints and labels them `safe`, `best-effort`, `unstable` or `missing`. `unstable` and `missing` checkpoints are not selectable for a normal boot.

The recommendation order is:

1. the safe checkpoint referenced by the chosen disk point;
2. the closest safe checkpoint captured at or before the disk point;
3. the closest safe checkpoint captured after the disk point, with a forward-state warning; then
4. the chosen point's best-effort checkpoint, with a prominent risk warning.

Quality ranks before time distance. A safe checkpoint is never displaced by a closer best-effort checkpoint. Ties prefer the earlier checkpoint. The UI shows the signed time difference and whether TPM and NVRAM are both present. A checkpoint is compatible only when it covers exactly the devices declared by the recovery XML; a fallback for a different TPM/NVRAM layout is rejected.

Activation copies the selected archive into exact UUID-scoped libvirt paths only after confirming the VM is undefined and stopped. If the VM does not boot or the Guest Agent does not return within the configured boot-health timeout, the state becomes `RECOVERY_BOOT_FAILED`. The user may stop the VM and retry with another compatible checkpoint. unMotion must verify the VM is stopped, remove only the activation-owned TPM/NVRAM files, install the newly selected checkpoint, update the attempt journal and then start it again. TPM/NVRAM is never swapped while the VM is running. The disk activation remains unchanged during a checkpoint retry.

For a VM without a virtual TPM, the wizard still verifies NVRAM where present and explains that recovery is normally more reliable without TPM state coupling.

## Source autostart interlock

Arming recovery transfers responsibility for source startup to unMotion:

1. Record the user's previous desired autostart value in the recovery policy.
2. Disable Unraid's built-in autostart for the source VM and verify it remained disabled.
3. Install or verify the early-boot and libvirt start interlocks.
4. Ask the destination to durably arm its matching policy.
5. Mark the policy `STANDBY` only after both hosts acknowledge the same term and identities.

The normal VM page should grey out built-in autostart with an explanation. If the Unraid release does not provide a supported UI hook, a reconciliation hook must immediately turn an attempted change back off and send a critical Unraid notification. Failure to prove either mechanism works blocks arming.

The policy retains a separate `desiredAutostart` setting. On source boot, unMotion must run before managed VM startup and leave every armed VM in `SOURCE_START_FENCED`. It may start a desired VM only after the destination answers with the same or lower term, confirms no replica definition or activation is running, and acknowledges source authority. If the destination is unreachable or reports a recovered activation, the source remains off. A native UI start request goes through the same authorization check. A detected direct `virsh` start is stopped and notified as a critical policy violation.

Disarming restores built-in autostart only after source and destination agree that no recovery activation, holdoff, failback or unresolved term exists. Peer removal and downgrade are blocked while a recovery policy is armed.

## Graceful holdoffs

Before an intentional VM power-off/restart or source-host shutdown/reboot, the source sends `recovery-hold`:

```text
requestId, policyId, term, sourceBootId
reason = vm-poweroff | vm-restart | host-shutdown | host-reboot
issuedAt, holdUntil = UTC timestamp | forever
```

The destination writes the hold durably before acknowledging it. A hold blocks evidence claims and activation until it expires or a matching `recovery-hold-release` is accepted. The configured duration may be finite or forever. The UI shows the reason, source boot ID, acknowledgement state and remaining time.

The shutdown hook retries for a bounded interval but must not deadlock host shutdown. If the destination cannot acknowledge, the source records the unsent hold, sends a critical notification and remains startup-fenced on its next boot until it reconciles with the destination. A forever hold requires an authenticated release from the source. Breaking it while the source is unavailable is a separate high-risk split-brain resolution action, not part of the ordinary beta2 recovery wizard.

When a scheduled run observes a powered-off VM, it may opportunistically promote a safe TPM/NVRAM checkpoint. That observation does not itself clear an intentional hold.

## Evidence rules

### Reachable source

If the source answers authenticated recovery status, manual recovery is coordinated. A running source VM is a hard blocker; the UI directs the user to migration or an intentional shutdown. A stopped source may grant recovery only after it records `SOURCE_FENCED`, confirms built-in autostart is off and advances the shared term. The destination persists the grant before activation.

### Two-host evidence claim

If the source is unreachable, beta2 permits a manual evidence claim because home installations may have only two hosts and one switch/router. It is explicitly not a proof of source power loss. The wizard displays this limitation and requires typed confirmation.

All of these checks must pass for at least three samples spanning 30 seconds, and the final sample must be less than 15 seconds old:

- authenticated source contact fails by every recorded address;
- every recent Guest-Agent-reported VM address is silent to ICMP and, on directly attached networks, ARP/NDP neighbour probing;
- the destination can ping its current default gateway;
- the destination can resolve a configured DNS probe name through its normal resolver; and
- an optional configured external IP, such as `1.1.1.1`, responds when that stronger check is enabled.

Any guest response, authenticated source response, gateway failure, DNS failure, active holdoff, clock anomaly or local network change resets the evidence window and blocks activation. Probe commands are argument-safe, bounded and record raw results in the evidence journal. Silence from a guest that blocks ICMP remains an acknowledged residual split-brain risk; beta2 does not disguise it as quorum.

### Witness/quorum claim

Witnesses are explicit recovery-group members, not every paired host. All must negotiate protocol 6 and recovery protocol 1. Three members (source, destination and one witness) are recommended; larger groups should have odd membership.

The destination first satisfies its local evidence rule, then requests a short-lived witness vote containing the proposed term, activation ID and evidence hash. Each witness independently repeats authenticated source contact, guest-address, gateway and DNS checks. It refuses a destination vote if it can contact the source, recently accepted a source heartbeat, cannot establish its own network health, or has already voted for another authority/activation in that term.

A claim requires `floor(member_count / 2) + 1` votes, including the destination's local evidence vote. Witness votes are durable per term and never transferable to another activation ID. For a three-member group, destination plus witness is a majority. An unavailable witness therefore blocks witness-mode recovery; the user may not silently fall back to two-host mode unless the policy was explicitly reconfigured while both source and destination were known safe.

Witness attestations include witness host/boot IDs, observed source and guest results, term, activation ID, expiry and request nonce. The destination revalidates the majority immediately before starting the VM. Beta2 uses witness votes only to guard a manual action; timer-driven automatic failover remains deferred.

## State machine

The durable policy state is one of:

| State | Meaning and permitted exit |
| --- | --- |
| `REPLICATION_ONLY` | Recovery is not armed. Replication may continue. `arm` begins interlock setup. |
| `ARMING` | Autostart and peer capabilities are being verified. Failure rolls back only if both hosts confirm no activation; otherwise enter `FENCED`. |
| `STANDBY` | Source authority is current, destination inventory is inert, and manual recovery may be requested. |
| `HOLDOFF` | A durable graceful hold is active. Only expiry or the matching release returns to `STANDBY`. |
| `SOURCE_START_FENCED` | Source boot/start is waiting for destination and term confirmation. Success returns to `STANDBY`; uncertainty remains fenced. |
| `EVIDENCE_GATHERING` | A manual claim is collecting coordinated, two-host or witness evidence. Any failed guard returns to `STANDBY` or `HOLDOFF`. |
| `RECOVERY_READY` | A short-lived claim and exact point/checkpoint selection are durable. Expiry returns to `EVIDENCE_GATHERING`. |
| `ACTIVATING` | Activation-owned ZFS clones, host-state files and XML are being prepared. Retry is idempotent by activation ID. |
| `RECOVERED_STOPPED` | Destination owns the term and activation, but the VM is stopped. It may start, be removed or begin failback. |
| `RECOVERED_RUNNING` | Destination is authoritative and its Guest Agent passed boot health. Source starts remain fenced. |
| `RECOVERY_BOOT_FAILED` | Destination VM is stopped or being fenced after failed health. The user may retry a compatible TPM/NVRAM checkpoint, choose another point through a new activation, or remove the activation. |
| `SPLIT_BRAIN_SUSPECTED` | Conflicting liveness, terms or authority records exist. No start, replication-base advance, prune or failback commit is allowed. |
| `SPLIT_BRAIN_FENCING` | unMotion is stopping its local non-authoritative/uncertain copy and verifying it stopped. |
| `SPLIT_BRAIN_UNRESOLVED` | Automatic fencing failed or both copies may have diverged. Both managed autostarts stay disabled; explicit survivor selection is required. |
| `FAILBACK_PREPARING` | Source receives an inert full/seed copy while both authority records still name the destination. |
| `FAILBACK_CUTOVER` | Destination is stopped and fenced; final disk and safe TPM/NVRAM state are transferring. |
| `SOURCE_RESTORING` | Source data and definition are verified while destination remains fenced. |
| `DISARMING` | Activation and coordination state are being removed after both hosts agree. |
| `FENCED` | A fail-closed administrative state caused by incompatible peers, missing interlocks or incomplete durable state. |

The principal transition is:

```text
REPLICATION_ONLY -> ARMING -> STANDBY
STANDBY -> EVIDENCE_GATHERING -> RECOVERY_READY -> ACTIVATING
ACTIVATING -> RECOVERED_STOPPED -> RECOVERED_RUNNING
ACTIVATING -> RECOVERY_BOOT_FAILED
RECOVERED_* -> FAILBACK_PREPARING -> FAILBACK_CUTOVER
             -> SOURCE_RESTORING -> STANDBY
any armed state -> SPLIT_BRAIN_SUSPECTED -> SPLIT_BRAIN_FENCING
                                             -> SPLIT_BRAIN_UNRESOLVED
```

No transition infers success from process exit alone. It verifies libvirt state, exact ZFS GUIDs/properties, authority term and peer acknowledgement. Power loss at any step resumes the recorded transition or remains fenced; it never falls back to normal Unraid autostart.

## Activation transaction

Beta2 activation uses clones or exact activation-owned copies of the selected snapshots. It never makes retained replica datasets writable and never destroys a recovery point.

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

Split brain is suspected when both peers report the VM running, guest liveness contradicts the authority record, a peer presents a higher or conflicting term, a witness reports a conflicting vote, or a source starts while a recovery activation exists.

The host detecting a conflict writes `SPLIT_BRAIN_SUSPECTED` before acting. A destination started by an uncoordinated two-host claim self-fences if the original source is later confirmed running. It requests a graceful guest shutdown, waits a bounded interval, then uses the configured emergency local-stop policy if required. A source that has not started remains fenced when it learns of a destination claim. A witness-majority or coordinated term prevents managed source startup, but an already-running conflicting source still requires explicit survivor review after local fencing.

The UI shows terms, activation IDs, last disk capture, last observed guest addresses and which copy was stopped. It offers no merge operation. The user must choose one survivor, confirm the other VM is stopped, and then choose discard/failback. Replication and pruning remain paused until both peers acknowledge the resolution term.

## Recovered VM actions and failback

Beta2 exposes these deliberate actions:

- **Stop recovered VM**: retains activation data and destination authority.
- **Retry TPM/NVRAM**: only from a verified stopped state, as described above.
- **Remove recovered activation**: requires the recovered VM stopped and removes only definition, activation clones and activation-owned host state. Recovery points remain inert. Source authority is not restored without a handshake.
- **Cold failback**: sends the stopped recovered VM's final storage and a safe powered-off TPM/NVRAM checkpoint to an inert source target, verifies it, defines/starts the source, receives source health acknowledgement, then retires the destination activation.

If the source returns after recovery, it stays fenced and the UI raises a critical failover notification with the destination term and point age. The user may keep the destination stopped/running while planning failback, remove a failed activation, or begin cold failback. Dropping stale source data or retained recovery points is a separate exact-ownership cleanup action and is never implied by acknowledging the notification.

Warm failback is deferred. Its later transaction may seed from the running destination, but final cutover must stop it, transfer the final delta and safe TPM/NVRAM state, confirm the source start, and only then remove the destination definition. Both cold and future warm failback preserve the invariant that the same UUID is never deliberately running on both hosts.

## Protocol 5/6 compatibility

Protocol-6 capabilities add:

```text
protocolVersion: 6
supportedProtocolVersions: [5, 6]
recoveryProtocolVersion: 1
features: recoveryActivation, managedAutostart, gracefulHoldoff,
          evidenceProbe, witnessVote, startupFence, coldFailback
```

A protocol-6 agent retains version-5 migration, cloning, pairing and replication operations. Pairing negotiates the highest common version and records it per peer. A 6-to-5 pairing uses version 5 and may continue migration and replication when their existing capabilities match, but recovery operations, witness membership and managed autostart are unavailable. A 5-to-6 peer receives version-5-shaped responses for version-5 commands. Unknown recovery commands fail before mutation.

Discovery advertises `protocol=6` plus an explicit compatible-version list; decoded host ID remains the durable identity. Existing version-5 peer records are preserved on upgrade. Upgrading only one host does not arm recovery. Downgrading or losing recovery capability while armed sets `FENCED`, keeps built-in autostart disabled and requires a protocol-6 reconciliation or explicit safe disarm.

The recovery agent operations are versioned and idempotent:

- `recovery-status`
- `recovery-arm` / `recovery-disarm`
- `recovery-hold` / `recovery-hold-release`
- `recovery-grant` / `recovery-claim` / `recovery-claim-ack`
- `recovery-witness-probe` / `recovery-witness-vote`
- `recovery-fence-status`
- `recovery-activation-commit`
- `recovery-failback-prepare` / `recovery-failback-commit` / `recovery-failback-abort`

Read-only status/probe operations never grant authority. State-changing operations require the negotiated recovery capability and exact reciprocal host/policy/VM identities.

## Safety invariants

1. No VM start occurs without a durable authority term and a fresh start authorization.
2. An armed source VM's built-in Unraid autostart remains off; unMotion is the only managed autostart authority.
3. Boot, peer loss, downgrade, corrupt state and uncertain ownership fail closed with the VM stopped.
4. Replication-only or Guest-Agent-ineligible points cannot be activated.
5. A holdoff blocks both two-host and witness recovery until valid release or expiry.
6. Source or guest liveness is a hard recovery blocker; gateway and DNS health are required evidence, not optional warnings.
7. Witnesses never vote twice in a term, and a majority is revalidated immediately before start.
8. Retained recovery points remain read-only and inert; activation uses exact owned clones/copies.
9. TPM/NVRAM replacement requires the VM undefined or stopped and exact UUID-scoped path/hash validation.
10. A current recovery point, foreign ZFS hold, resumable receive or partial multi-object transaction is never pruned implicitly.
11. Version-5 or capability-mismatched peers can replicate but cannot arm or execute recovery.
12. Split-brain suspicion pauses starts, replication-base advancement, pruning and failback commit until fencing/resolution is durable.
13. Removing an activation, policy or peer never claims that remote data was removed without an authenticated exact cleanup acknowledgement.

## Test gates

Beta2 is not releasable until these pass on disposable, outer-snapshotted lab hosts:

- state-machine unit tests for every permitted transition and rejection of every invalid transition;
- power loss or process kill before and after every durable write, clone, XML definition, start, fence and failback acknowledgement;
- protocol matrix tests for 5-to-5, 5-to-6, 6-to-5 and 6-to-6 peers, including upgrades, downgrade while armed and unknown-command rejection;
- CSRF-protected POST tests for all recovery mutations and replay/expiry tests for every remote mutation;
- reciprocal pairing and host-ID mismatch tests, including decoded `\032` discovery names;
- source-start fencing across clean/unclean source and destination reboots, with native autostart and attempted UI/direct starts;
- autostart UI greying where supported, fallback reversal and critical notification where it is not;
- finite, forever, released, expired, unacknowledged and power-loss holdoff cases;
- two-host evidence with source alive/down, guest alive/silent, reassigned guest IP, gateway failure, DNS failure, optional internet failure and network changes during the sample window;
- witness majority, unavailable witness, source-visible witness, stale/replayed vote, double-vote, even-member and partition scenarios;
- exact recovery of a VM and dataset named `Squid Proxy`, including multi-disk zvol and isolated image-dataset layouts;
- QGA missing, stale and changing network metadata; only eligible powered-off/quiesced points may activate;
- TPM absent, safe, best-effort, changed, missing, incompatible fallback and alternate-checkpoint boot retry cases;
- destination UUID/name conflict, activation partial cleanup, foreign holds and preserved receive tokens;
- deliberate split brain in the isolated lab, including successful self-fence, failed fence and explicit survivor resolution;
- recovered activation removal and cold failback, with failures at every handoff and proof that only one UUID is running;
- all existing cold migration, Warm Move, cloning and beta1 replication regressions.

Deletion, force-stop, split-brain and failback tests require backups/snapshots of the outer lab VMs. Production hosts and personal data remain out of scope.
