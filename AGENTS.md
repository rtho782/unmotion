# AGENTS.md

## Scope and baseline

- This is an Unraid Community Applications-style plugin. Preserve released behavior unless a task explicitly changes it.
- `src/rootfs/` is the package root. Paths below it map directly to `/` on Unraid.
- Protocol version is `5`. Do not change it casually. A protocol change requires compatibility analysis, mixed-version tests and release notes.
- Never describe guessed or planned behavior as implemented behavior. Distinguish current functionality, new changes and proposed designs.

## Non-negotiable behavior

- SSH operations assume the Unraid `root` account. Do not silently substitute another user without a complete design change.
- Never pair a host with itself. Reject matching host IDs and local/loopback addresses even if names differ.
- Decode Avahi/DNS-SD escaped discovery names such as `\\032` before display or comparison. Host ID, not display name, is the durable peer identity.
- Pairing is reciprocal. Changes to pairing/authentication must consider both initiating and receiving hosts and preserve existing pair records when practical.
- Keep the stable plugin identity and installed descriptor filename: plugin name `unmotion`, installed PLG `unmotion.plg`.
- All state-changing web requests must retain Unraid CSRF protection. Never weaken CSRF checks to fix pairing or UI behavior.
- Shell command construction must quote untrusted values. Prefer argument arrays in PHP and validated identifiers in shell.

## Migration safety

- Cold migration requires the source VM to be powered off.
- Warm Move may seed while running, but final cutover must shut down the VM and transfer the final delta/state before destination start.
- Never leave the same VM running on both hosts. Destination conflicts, especially a running VM with the same UUID, are hard blockers.
- PCI-passthrough VMs remain blocked unless the task explicitly designs and tests safe destination remapping.
- Preserve UEFI NVRAM and software TPM state handling. Path deletion must remain constrained to verified libvirt locations and the expected VM UUID.
- Do not broaden recursive deletion. Destructive operations must target verified job/seed IDs, exact datasets, exact snapshots or paths proven to belong to the selected VM.
- Interrupted resumable ZFS receives must be retained unless the user explicitly chooses removal. Resume with the destination token; do not silently discard partial state.
- ZFS progress parsers must never write human-readable progress to the binary stream.
- Keep ZFS sends non-recursive for an individual VM dataset. Do not introduce `zfs snapshot -r`, `zfs send -R` or broad `zfs destroy -r` without an explicit, reviewed design.
- Preserve the destination dedup setting (`off` by default, with explicit `on`/`verify` choices) and its receive-time property handling. Do not silently alter source dedup settings or force a different destination policy without an explicit product decision and tests.
- Treat datasets with unrelated contents or child datasets as shared storage; use the file-copy path unless isolation is proven.
- Preserve sparse-file behavior in `rsync`/image transfers.
- ISO handling is separate from writable VM disk handling. Preserve copy/map/omit choices and do not accidentally treat an ISO as a mutable disk.

## Compatibility and tests

- Test mixed-peer versions where practical. If compatibility is impossible, fail clearly during preflight rather than mid-transfer.
- Test on disposable `UNRAID-DEV01` and `UNRAID-DEV02` hosts before considering migration changes complete.
- Tests involving deletion, cutover, receive abort, power loss or destination replacement require snapshots/backups of the outer lab VMs.
- Always test spaces in VM and dataset names, including a name like `Squid Proxy`.
- Minimum regression cases: local-host rejection, `\\032` discovery decoding, reciprocal pairing, CSRF-protected POSTs, cold-power-state gate, destination UUID/name conflict, ZFS resume token, held snapshots, rsync fallback, ISO mapping, UEFI/TPM state and transfer-progress stdout isolation.
- Run `scripts/verify.sh` and all syntax checks available on the development host. Absence of PHP/Bash/Node tools must be reported, not treated as a pass.

## Operational boundaries

- Production hosts and personal data are out of scope for destructive testing.
- Do not assume access to `UNRAID-DEV01`/`02`; verify connectivity and get explicit authorization before changing them.
- Preserve user changes and persistent settings under `/boot/config/plugins/unmotion/` during upgrades unless reset/removal is explicitly requested.

## User documentation maintenance

- Documentation is part of completing a feature or release, not a separate optional follow-up. Review and update it whenever a feature, user workflow, requirement, limitation, support/reporting process or release-channel availability changes. User-visible bug fixes need a documentation update only when they change the guidance.
- Maintain the Unraid-facing guide in `README.md` on `main` of https://github.com/rtho782/unmotion-community-apps, alongside relevant documentation in this source repository. Fetch and inspect the current companion repository before editing; preserve other contributors' changes.
- Write for new users: explain current capabilities, practical instructions and limitations concisely. Do not turn the guide into release notes or assume readers remember older versions. Keep historical changes in the changelog.
- Distinguish stable, published beta and unpublished development features. On every release, update the guide's availability table, feature labels and installation/update instructions against the actual published release and feed; a tested local build is not a published beta.
- Review `unmotion.xml` Overview/ReadMe and `ca_profile.xml` when their descriptions or links become inaccurate. The Apps template remains stable-facing: never switch its PluginURL to beta or advance its stable manifest pin merely to document a beta feature. Change the pin only as part of an authorized, published and verified stable release.
- Keep author fields exactly `Richard Skinner`. Validate changed XML, links and the diff. After authorized publication, verify that GitHub serves the intended guide/template and that advertised installer links are reachable.
- Follow the task's publication scope: local feature work must prepare any companion documentation changes and identify anything still awaiting publication; an authorized release must include publishing and verifying the corresponding guide update. Do not silently leave documentation stale or publish unfinished plugin code as a shortcut. If a documentation update is blocked, report it explicitly in the handoff.
