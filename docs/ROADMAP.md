# Feature backlog

Author: Richard Skinner.

This backlog distinguishes accepted work, proposals and unpublished changes. Unless explicitly marked otherwise, entries are not implemented features or release commitments. Current published capabilities are documented in the [README](../README.md).

## Plugin conventions and release housekeeping

Reviewed 2026-10-04. These priorities do not imply that Community Apps requires every item or that any item caused a moderation decision. No host cleanup, feed update or release publication is authorized by recording this backlog.

| Item | Priority | Status / next step |
| --- | --- | --- |
| Prune superseded TXZ package downloads | Completed in 0.4.3 | Native post-install hook prunes older downloads after successful registration, retaining the verified current package for offline boots. |
| Future-proof installer version ordering | Completed in 0.4.3 | Fixed UTC date/sequence prefixes the installer comparison version; public semantic versions stay familiar. |
| Support and documentation links in the installed plugin | Medium | Add PLG `support` metadata and links in the installed description. Link a dedicated Unraid support thread if one is established; GitHub issues remain a valid support destination. |
| Align the minimum Unraid version with the lab | Completed in 0.4.3; older-version testing is low priority / not planned | Requires Unraid 7.3.2. Historical 0.4.2 assets remain unchanged; no extra servers are planned for older versions. |
| Separate uninstall from explicit settings purge | Low | Design retention of settings/history separately from removal, including credential revocation and recovery reconciliation. Current uninstall behaviour is unchanged. |
| App/profile icon and screenshots | Low | Supply a dedicated icon URL in the CA template/profile and useful screenshots. Presentation work, not a confirmed submission blocker. |

### Package-cache cleanup acceptance criteria

- Remove only superseded, recognized unMotion TXZ package files directly inside `/boot/config/plugins/unmotion/packages/`; never recursively delete directories or follow symlinks.
- Retain the verified package for the successfully installed release so subsequent boots do not require a download. Do not prune before download verification, package installation and service startup succeed; protect the package named by the currently registered descriptor until the upgrade is committed.
- Leave settings, credentials, job logs, VM disks, snapshots, partial receives, unrelated archives and staged newer packages untouched. This is not an uninstall or a VM-storage cleanup.
- Test multiple accumulated versions, repeat runs, stable/beta ordering, symlinks/unrelated files, missing or corrupt current packages, failed downloads/installs/restarts and upgrade registration failure. Confirm offline cached boot still works.
- Report which cached downloads were removed. They can be downloaded again from their releases; they are not VM backups. Do not perform manual production cleanup without a separately scoped request.

### Installer versioning (0.4.3 onward)

Application/UI versions, Git tags, release titles and documentation retain semantic versions. Only the PLG's comparison/display version gains a UTC release date and zero-padded publication sequence: release `0.4.3` uses `2026.10.04.01-0.4.3`.

The Unraid Plugins tab shows the longer installer version, including the familiar unMotion version; the plugin UI and GitHub continue to lead with the short semantic version. A date prefix makes the initial transition newer than existing `0.x` descriptors and avoids lexical `0.4.10 < 0.4.9` / `beta10 < beta9` problems.

`RELEASE_ID` binds one fixed identifier to each release, reused on rebuilds and feed copies. `scripts/check-release-feed.php` requires both the installer ID and application version to advance, preventing cross-channel application downgrades. Tests cover historical descriptors, same-day sequencing and double-digit versions. Slackware packages keep their separate semantic-version tokens. Plugin identity and historical assets remain unchanged. See [the release procedure](PLUGIN-UPDATES.md).

### Publication and documentation gate

At every authorized stable release, review the CA template `MinVer`, guide requirements and release availability after verifying the published assets. Keep its `PluginURL` exactly equal to the stable PLG's `pluginURL`; the raw stable branch URL is the catalogue identity, not a per-release commit pin. The 0.4.3 requirement is 7.3.2; documentation of future work must not advance installer feeds ahead of publication.

## Expand the peer API and reduce SSH round trips

Status: accepted for future work. Target release: unassigned.

Replace remaining small, command-shaped remote queries with named unMotion operations. The receiving host should own its local inspection and validation, returning structured results instead of making the source orchestrate individual commands.

Planned stages:

- [ ] Add named, batched agent requests for destination inspection: VM conflicts/state, storage capacity, dataset properties and relevant file metadata. Reuse the existing restricted SSH transport initially.
- [ ] Measure connection count and preflight/status latency; evaluate safe SSH connection reuse where beneficial. Cache only information that can tolerate staleness, never start authority or safety-critical state.
- [ ] Design an optional authenticated HTTPS peer API for inventory, health, preflight, job management and recovery coordination. Keep restricted SSH for bulk ZFS/rsync transfers initially; removing SSH entirely is a separate design decision.

Design requirements:

- Keep peer authentication, exact VM/storage ownership, protocol negotiation, bounded requests and structured errors independent of the transport.
- An HTTPS peer API must use verified TLS and dedicated machine-to-machine authentication, with credential revocation and replay protection. Evaluate mutual TLS; do not reuse browser login cookies or weaken Unraid CSRF checks.
- Revalidate safety-critical conditions at mutation time. Preserve locking, recovery authority, single-use start permits, resumable transfers and retry-safe operations; batching alone does not make a multi-step operation atomic.
- Keep the synchronous native pre-start hook entirely local, with no SSH, HTTP or libvirt calls from the hook.
- Retain the root-account assumption for existing SSH operations and do not introduce an unrestricted fallback.

Completion requires before/after measurements, mixed-version rejection/negotiation tests, authentication and stale/replayed-request tests, and migration/replication/recovery regressions on both disposable Unraid hosts. Update user guidance when functionality becomes available; planning alone does not change release feeds or Community Apps capabilities.

Related: [architecture](ARCHITECTURE.md), [protocol-7 security model](SECURITY-042.md), [recovery safety](RECOVERY.md).
