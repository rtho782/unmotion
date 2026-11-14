# CPU compatibility checks

unMotion provides CPU preparation, a pinned default for new VMs, nested-virtualization preservation and [fresh VM-specific live-migration checks](LIVE-MIGRATION-BETA.md); a cached host match alone never approves a migration.

Author: Richard Skinner

**Stable 0.5.2** can approve same-vendor newer-to-older physical generations when fresh checks validate the complete active CPU/nested contract. Cached suggestions are not authorization, and a newer host is not automatic compatibility proof. See [CPU-fix qualification](CPU-MASK-051-QUALIFICATION.md).

Available in **[stable 0.5.2](https://github.com/rtho782/unmotion/releases/tag/0.5.2)** through [Community Apps/the stable installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). Both feeds offer it until a future prerelease. Update both peers for live migration; older protocol-7 peers retain otherwise-supported ordinary workflows but may lack optional capabilities.

A shared active virtual CPU enables **live migration without powering the VM off**, apart from a brief handover pause. Fresh VM, machine, software, storage, device and ownership checks are also required. **Cold migration and Warm Move do not require a common CPU mode** because their handover occurs while powered off. They remain useful across generations and Intel/AMD hosts where the VM and guest are otherwise compatible; a vendor-specific custom CPU may need adjusting while the VM is off.

Older modes hide newer CPU instructions and can reduce performance or fall below an OS/application's minimum requirements. For an essential, lightly loaded VM, choosing an older supported mode can be a reasonable trade-off for mobility between older hosts. A compatibility suggestion is not a workload benchmark or a guest boot guarantee.

## Default CPU mode for new VMs

Select a validated compatibility level and choose **Use for new VMs** beside the bulk-application button. This stores a fixed definition on this host. **Clear default** restores Unraid's ordinary new-VM selection; neither action edits existing VMs or their running CPUs.

In Unraid's form editor, **unMotion default — model** appears in the CPU-mode dropdown and is preselected for new VMs unless a saved template specifies its own mode. The definition is validated against the actual machine before creation and is written before an optional first start. Existing custom CPUs use **Keep existing custom CPU (model)**; changing the default does not retarget them. Explicitly selecting the default for an existing VM changes its next-boot CPU, not its running CPU. Form-saved templates retain a private fixed CPU profile, independent of later default changes. Raw XML remains explicit.

The adapter adds small hooks to two reviewed native-editor files, saves originals privately and restores them on plugin removal. It is qualified against the identical editor files on the lab's Unraid 7.3.2 and 7.3.4 builds. Unknown or externally changed sources are not overwritten; Settings reports unavailable integration. Check that status after an Unraid update. CPU/hypervisor changes invalidate the cached default and require review rather than a silent substitute. Existing live-managed VMs need powered-off hand-back before form-based CPU editing.

Inventory badges show the observed active and next-boot CPU models. A different named mode needs a full stop/start. A matching name is not proof of full feature equivalence: live preflight still checks the complete running/pending CPU contract and destination freshly.

## Using the comparison

Open **Settings → unMotion → CPU compatibility levels**. Select **All paired hosts**, an individual peer, or local-only, then choose **Read cached results**. There is no machine-type selector: Q35 and i440fx reference checks are combined automatically into a host CPU overview. The table shows which exact definitions are common with each host. Initial local collection runs in the background with visible progress. Reading results again does not force a rescan.

The normal list and table show **one preferred revision per CPU family**. With peers selected, this is the highest preferred revision whose exact definition is confirmed on every selected host; for example, Cascade Lake v3 is shown if v5 is only local but v3 is shared. Without a confirmed shared revision, the family stays visible using its local top revision and the peer cells remain not common/unconfirmed. Pending or unavailable peers are never silently omitted. Local-only mode shows the preferred local revision.

Enable **Show all revisions** to inspect older validated definitions. The toggle uses the reports already on the page: it does not rescan CPUs or make additional peer requests. Explicitly collapsing again keeps the inspected family and selects its representative revision. If a late peer response would hide a manually inspected revision, the view expands with an explanatory note instead of silently replacing it.

Recognised CPU families for the local vendor appear first, newer families before older ones, with higher numeric revisions first within a family in the expanded view. For example, validated Cascade Lake Server levels precede Skylake Server and Broadwell. Within the legacy group, Pentium III precedes Pentium II, Pentium and 486. Synthetic KVM/QEMU baselines have no hardware-generation chronology and are grouped below named CPUs, with 64-bit baselines before 32-bit ones. Non-deprecated definitions are preferred; deprecated levels are listed last. Unknown future families and legacy/generic levels remain inspectable, but are not guessed into the suggestion.

**Suggested** identifies the highest-ranked non-deprecated level whose exact definition is validated locally and on **every selected peer**. With All paired hosts selected, an offline or unconfirmed peer prevents a suggestion rather than being silently ignored; selecting one peer limits the suggestion to that pair. The pairing list is refreshed on each read. While peer responses are pending, no all-host suggestion is shown. Local-only suggestions explicitly make no claim about paired hosts. You can still inspect another level; asynchronous updates preserve that selection.

This is a generation/revision preference, not a benchmark or proof that one mask contains every feature of another. Higher model revisions can change security features and omit instructions such as TSX. The suggestion does not apply a setting to any VM. The approach follows QEMU's guidance to choose the newest named model compatible across the desired hosts, with exact definitions checked separately: [QEMU CPU model guidance](https://www.qemu.org/docs/master/system/qemu-cpu-models.html).

When comparable hosts report different validated revisions of the same recognised CPU family, or different validated definitions for the same top revision, the page suggests checking Unraid updates on older installations. Unraid releases can update QEMU, libvirt and the kernel together; not every point release changes all of them. Firmware, microcode, stepping and exposed CPU features can also cause differences, so upgrading is not presented as a guaranteed fix or a requirement to use an already validated common revision. No OS update is performed by unMotion. See [Unraid's virtualization-stack release notes](https://docs.unraid.net/unraid-os/release-notes/7.3.0/).

### Automatic machine handling

The CPU overview covers **x86_64 KVM on Q35 and i440fx**. These are QEMU virtual motherboard/chipset families, not CPU models. Versioned names such as `pc-q35-9.2` and `pc-i440fx-10.2`, and their normal aliases, remain internal machine metadata. The page reads saved VM definitions to identify configurations outside this scope; it does not run storage, guest-agent or operational VM probes for that list.

Startup and first-time local collection warm the host's `q35` and `pc` reference contexts. A level is supported in the combined overview only when both cached references validate the **same exact CPU definition**. Different definitions, invalid hashes or missing levels remain unconfirmed; a missing/unavailable reference prevents a successful overview. The timestamp is the older observation, not the latest page request. Existing machine-specific cache records are preserved, including any collected for diagnostics.

Paired hosts supply their own cached reference reports through the existing interface. Different reference machine versions do not prevent a host CPU match if both hosts independently validated identical definitions across their Q35/i440fx references. A remote request never starts detection. If a peer's reference cache is unavailable, open CPU compatibility on that host to populate it locally.

Other machine families are marked unsupported **for this CPU compatibility/live-migration feature**. This does not add restrictions to existing cold/warm moves or modify any guest configuration. Recognizing a Q35/i440fx family does not prove that an exact legacy version is installed or supported on the destination.

A matching host overview is not approval for a particular VM. Applying a CPU mode reads the VM's actual persistent XML and freshly validates its exact machine type/version and selected CPU definition automatically. Future live migration will require its own fresh checks. unMotion does not upgrade the virtual chipset, substitute a newer version or use the cached overview as operational authority. CPU pinning, firmware, devices, storage, networking and migration authority still need separate checks before migration. See [QEMU's migration compatibility requirements](https://www.qemu.org/docs/master/devel/migration/compatibility.html).

Select a compatibility level to inspect its local and peer status and exact CPU definition. No manual machine-type choice is needed for this overview.

Reading results does not start, stop, redefine or migrate a VM.

## Applying a mode to local VM XMLs

Select the suggested mode or another validated level, then choose **Review and apply to all local VMs**. With peers selected, the button waits for an exact common definition on every selected host; local-only mode makes no claim about peer support. The review lists eligible, already-matching and skipped persistent VMs. Confirming changes this host only, not its paired hosts.

The server resolves the selected definition from its own cache, freshly expands and compares it for each actual VM machine version, and validates the proposed XML against the installed libvirt schema. It rejects changed reviews and respects existing operation locks. Unsupported VM families, 32-bit CPU levels, saved memory states, prepared/unresolved moves, replication/recovery-managed VMs and special CPU overrides are skipped with reasons. Resolve those workflows before reconfiguring their CPUs.

Original XMLs and a batch result are saved under `/boot/config/plugins/unmotion/cpu-config-backups/batch-…/` before the first definition changes. VM UUIDs/names, vCPU count, topology, NUMA, pinning, disks, devices, firmware, TPM and autostart are preserved. Only persistent CPU model/features are replaced; no VM is stopped, started or rebooted. **The mode takes effect after the next full stop/start. Rebooting inside the guest does not replace its running virtual CPU.**

The XML retains the selected versioned model name even when libvirt's internal baseline uses an equivalent name. Guest configuration makes the hypervisor bit explicit while preserving an existing hidden-bit policy. It preserves existing Intel VMX/AMD SVM exposure, including host-passthrough/host-model exposure when KVM nesting is enabled, and respects explicit feature opt-outs. Preserved nested requirements are added to the selected mask and freshly compared locally; unsupported requirements are skipped, never silently removed. A common base mask does not itself certify these additional guest-specific requirements on peers. Nested virtualization is not inferred from a VM's name, and unMotion does not alter host KVM module settings.

Startup uses libvirt's normal pre-start checking (`partial`), never `none`. Live migration validates the actual running CPU and destination, not merely the cached catalogue. Intel nested live preflight also requires KVM nesting on both hosts and retains the full active/pending CPU contract; AMD SVM-enabled guests cannot use live migration in 0.5.2. Their CPU configuration and otherwise-supported cold/warm moves remain available. See [libvirt CPU checking](https://www.libvirt.org/formatdomain.html#cpu-model-and-topology) and [the kernel's nested-migration restrictions](https://docs.kernel.org/virt/kvm/x86/running-nested-guests.html).

Persistent XML is read with console credentials included and backed up privately. Read-back verification tolerates only harmless ordering of known direct CPU children; it still detects changed feature policies, topology values, duplicate nodes or non-CPU settings. An already-matching definition is unchanged on a repeated review. The full guest workload still needs testing after a deliberate CPU change.

A batch is not atomic across libvirt definitions: if applying a VM fails, further changes stop, earlier successful changes remain and the result identifies what needs review. No automatic rollback overwrites later edits. After a lost connection, inspect the batch result and saved VM XMLs before retrying. XML backups can contain private VM configuration; they are not added to support reports.

## How levels are validated

Levels are discovered from libvirt's versioned custom CPU models, including Intel and AMD generations; host-passthrough and host-dependent aliases are not compatibility levels. Models reported unusable remain unsupported. A missing/unknown usability result remains unconfirmed.

For each usable model, unMotion asks `virsh hypervisor-cpu-baseline` for its expanded, migratable definition, requires an exact custom CPU with model fallback forbidden, and validates that exact definition using `virsh hypervisor-cpu-compare --error`. Feature order is normalized before hashing. Common levels require identical definition hashes, not merely equal model names. Versions are distinct levels, not an automatic performance ranking.

These are cached observations with timestamps, not operational permission. Successful local detection runs once per effective CPU/hypervisor and machine context and persists at `/boot/config/plugins/unmotion/cpu-compatibility/`. Page loads, repeated API requests, plugin upgrades and reboots reuse valid results. Cheap local fingerprints detect CPU model/vendor/stepping, microcode and feature changes; kernel/QEMU/libvirt/CPU-map changes also invalidate affected results because compatibility can change without replacing the CPU. Clock speed, processor numbering, load and unMotion's own version do not force a rescan.

Only validated definitions count as supported. Unknown/incomplete levels stay unconfirmed in the stored observation; normal reads do not repeatedly retry them. An unavailable VM Manager produces no supported result and uses a retry backoff rather than a request storm. There is one collector at a time, a five-minute per-context scan budget and at most 32 stored contexts. Cached observations never authorize migration: operational checks are fresh.

## API and peer compatibility

- Protocol remains **7**. Capability `cpuCompatibilityVersion: 2` advertises cached reports; older peers remain usable for existing workflows and show CPU comparison unavailable. No legacy live-query fallback is used.
- Web API: CSRF-protected POST `/plugins/unmotion/api.php` with `action=cpuCompatibility` and no `machine` reads the automatic local overview and may queue first-time local reference collection. `action=cpuCompatibilityPeer` with `peer_id` and no `machine` combines local/peer reference caches without queueing collection. Responses contain `local`, `peer`, `peerError`, `levels` and a `pairedPeers` snapshot. The local-only `cpuCompatibilityScope` action returns supported families, out-of-scope VM names and completeness/error metadata from saved definitions. It never requests peer inventory or CPU validation.
- Local-only `cpuConfigPreview` and `cpuConfigApply` require CSRF-protected POSTs with a mode ID, definition hash and cache identity. Apply additionally requires the reviewed fleet digest. Neither accepts supplied VM/CPU XML or a remote host, and neither is a paired RPC. These actions perform fresh selected-mode validation, not a full CPU rediscovery.
- Explicit `machine` parameters retain machine-specific diagnostic reads on the existing web actions; they are not exposed as GUI choices and do not expand the supported VM scope. Those diagnostic comparisons still require matching exact machine contexts.
- Paired RPC: `unmotion-agent cpu-compatibility BASE64_JSON`, where the decoded object contains only optional `machine`. The existing forced-command transport requires completed reciprocal pairing. No supplied shell command, emulator path or arbitrary CPU XML is executed.
- Underlying reports use schema version 2. The derived host view adds `overviewVersion: 1`, reference-context metadata and an identity covering both observations. A validated mode contains its versioned ID, expanded CPU XML and SHA-256 definition hash. Mode status is `supported`, `unsupported` or `unknown`; the comparison's `common` is true, false or null (unconfirmed). Overview and machine-specific observations cannot be mixed into a common result. Changed CPU contexts never expose the previous context's supported modes.
- A current capability response and the CPU report must match the paired host identity. No new unauthenticated peer HTTP API is introduced. Existing CSRF protection and migration/recovery gates are unchanged.

## Physical-host validation

Read-only checks on a Xeon Platinum 8173M (Skylake) and Xeon Gold 6226R (Cascade Lake), both running Unraid 7.3.4, QEMU 10.2.3 and libvirt 12.2.0, validated identical expanded definitions for `Skylake-Server-v3`, `Skylake-Server-v4`, `Skylake-Server-v5` and `Broadwell-v4` with `pc-q35-10.2`. The Cascade Lake level `Cascadelake-Server-v3` was supported only on Cascade Lake. Some other levels remained unconfirmed and were not counted as supported.

The exact no-TSX Skylake alias in these libvirt builds is `Skylake-Server-noTSX-IBRS`; the previously discussed `Skylake-Server-IBRS-noTSX` name is rejected. The UI discovers versioned names rather than assuming either alias exists.

These physical-host checks validated CPU compatibility only. No production VM migration or configuration change was performed. Separate nested-lab live-migration qualification is recorded in [Beta3 testing](TESTING-LIVE-BETA3.md).

Both candidates were installed on the disposable Unraid development hosts. Authenticated paired requests and browser comparison returned 30 common exact definitions; repeated reads and package upgrades/service restarts preserved existing observations. The complete automated suite passed, including CPU definitions, persistent caching, saved machine choices, native plugin-manager fixtures and UI ordering/suggestion/response-isolation tests. See the [Beta2 verification record](TESTING-CPU-BETA2.md) and [initial cache qualification](TESTING-CPU-COMPATIBILITY.md) for coverage and remaining limits.
