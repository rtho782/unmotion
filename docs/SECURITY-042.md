# Protocol 7 and security hardening

Applies to **stable 0.4.2**. Both installer feeds serve `0.4.2-stable` and select future stable updates after installation. Author: Richard Skinner.

## Pairing trust

Pairing still uses the Unraid `root` account. The password is used to bootstrap the plugin-managed key; subsequent connections use that key. Managed keys have an OpenSSH forced command with forwarding, PTY and user startup-file execution disabled. The endpoint accepts versioned unMotion requests, not arbitrary root shell commands.

Each request is associated with the peer identified by the authenticated key. Transfers reserve their exact destination storage before writing. ZFS operations, file transfers, VM definition/start and host-state restoration are validated against that peer's admitted VM/storage. General-purpose SSH/SFTP, arbitrary rsync destinations and arbitrary libvirt XML are not available through these keys.

Pair only hosts you trust. A paired host can still request privileged unMotion operations and read the metadata needed for them. These restrictions are defence in depth, not an isolation boundary for mutually hostile administrators or a sandbox for untrusted guests. They do not restrict separate administrator passwords or SSH keys.

unMotion uses native libvirt pre-start fencing for recovery-managed VMs. Source starts require a fresh peer-authorized permit; recovered starts require an exact activation and fresh source-fence grant. Direct starts without those permits are rejected before QEMU starts. Unmanaged VMs are not enrolled implicitly. Existing Unraid hooks are preserved, and recovery arming/grants require proof that libvirt invokes the installed hook. Native-path live verification is recorded in [testing](TESTING-042.md).

The hook is not protection against an administrator deliberately removing hooks or changing recovery identity/storage. See [recovery boundaries](RECOVERY.md#source-autostart-interlock).

## Upgrade both hosts together

1. Keep verified VM backups. Finish or explicitly remove prepared Warm Moves and partial receives while both hosts still use the old version. Do not delete partial storage merely to get past an error.
2. Pause scheduled replication and wait for all transfer/recovery/cleanup jobs to finish. Shut down recovery-managed source VMs and recovered activations before upgrading; the installer refuses to replace their fencing while they are running. Resolve outstanding recovery operations; do not clear fencing records or enable native VM autostart to bypass an upgrade problem.
3. Install 0.4.2 on both hosts during the same maintenance window, then test each pairing from Settings before resuming work.
4. Review any rejected existing target or host-state path. Unproven legacy receive ownership is preserved and blocked, not inferred from a matching filename. Begin a new preparation only after deliberately resolving old state.

Protocol 7 does not negotiate transfers or recovery with protocol 5/6. Old and new hosts fail closed rather than falling back to unrestricted root commands. Host IDs, pairing records, settings and durable recovery authority records are retained. Matching managed entries in `authorized_keys`, including duplicate unrestricted entries, are replaced with restricted entries; unrelated administrator keys and deliberately revoked missing entries are left alone.

The existing durable recovery record retains its schema-1 `protocolVersion: 6` field. This is a persisted data-format identifier, not permission to communicate over protocol 6; recovery request envelopes use protocol 7. A temporary communication failure leaves recovery fencing in place.

Downgrading only one host is not a supported recovery method. Never manually remove forced-command restrictions to make an old version connect.

After installation, the lifecycle service checks the native hook with an exact transient probe that has no disks or network and is rejected before startup. If libvirt has not loaded the hook, stop guests and restart VM Manager in a maintenance window, then run `unmotion-native-fence probe` (or allow the lifecycle retry). The installer never restarts libvirt automatically. `unmotion-native-fence status` reports current callback proof. Keep recovery-managed VMs stopped until verification succeeds. Uninstallation is refused while recovery authority remains armed or unresolved.

Use **Recovery → Start source VM** for a one-off authorized source start, or the managed-autostart option chosen when arming. A one-off start does not enable autostart. Use **Start recovered VM** for an existing destination activation. The native VM page and direct `virsh start` are not substitutes for those authorization steps.

## Storage and VM limitations

Existing cold/Warm Move power-state rules, exclusive ownership, sparse-file transfer, ZFS resumability and source-deletion checks still apply. Restricted rsync copies regular files without granting remote device, symlink or arbitrary owner/permission creation. Data and timestamps are preserved; transferred private VM files use receiver-controlled permissions.

ZFS transfer uses a single filesystem or volume stream, whose type is checked before receive. Recursive/property-package streams are refused. Destination deduplication, compression, mount/exposure and read-only policies remain explicitly controlled by unMotion; arbitrary source dataset properties are not imported. Source properties are not changed.

Custom emulator paths, injected QEMU command lines and unbounded host-file access in VM definitions cannot be imported through a pairing key. A legacy libvirt NVRAM filename must be scoped to the VM UUID; otherwise, with the VM off, use a UUID filename and update the XML, or move the variables file beside its disk using the supported pool-resident layout. Ordinary UUID-scoped NVRAM and software TPM state remain part of the test matrix.

## Other hardening

Cancellation, service stopping and removal verify a process's boot ID, start ticks, executable and arguments before signalling it. An unrelated process that inherited an old PID is not killed. Inconsistent process records block the unsafe operation rather than authorizing a broad process-group kill.

Dynamic HTML escapes quotes as well as markup characters. Resource, capacity and progress values received from peers are parsed as bounded decimal integers before Bash arithmetic; malformed values and overflowing totals fail clearly.

## Verification

Run `scripts/verify.sh` on Linux, including PHP/Bash/Node syntax and process/key/input/transport regressions. Live testing must include upgraded existing pairings, fresh reciprocal pairing, both mixed-version directions, blocked arbitrary commands, cold and Warm Move with spaces in names, file/ZFS/zvol transfers, interrupted receives, NVRAM/TPM, and replication/recovery. Snapshots of both outer lab VMs are required before destructive cases.

The [0.4.2 testing record](TESTING-042.md) separates historical candidate validation from final stable artifact verification. Release assets and both installer feeds must match the published stable checksums; independently rebuilt archives may have different metadata. Installing a release does not authorize changes to production VMs.
