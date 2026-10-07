# Custom UEFI NVRAM migration

Author: Richard Skinner

This document describes custom UEFI variables-file handling in unMotion 0.4.0.

## Supported layout

In addition to the existing libvirt NVRAM directories, migration accepts one direct, readable, regular .fd file on a direct /mnt/pool path. It must be a sibling of a mapped writable image disk in a directory named for the VM or its UUID. All sibling disk mappings must agree on the destination directory, and no other VM's live or inactive definition may reference that directory. Files must be non-empty, at most 64 MiB, and neither symbolic nor hard links.

For example, a VM's vdisk1.qcow2 and OVMF_VARS.fd under /mnt/cache/domains/HomeAssistant-ZHA-Test/ move together under the destination's configured image directory. The exact NVRAM XML text is rewritten; format and template attributes remain intact.

## Transfer and cutover

- A proven dedicated ZFS dataset carries NVRAM in the same non-recursive final snapshot as its disks.
- Shared storage uses the existing sparse disk-copy path plus a separate small NVRAM copy. NVRAM is not treated as a writable disk or ISO.
- Warm Move may seed disks while the source runs. The final variables-file hash is captured only after graceful shutdown and checked again before handoff.
- Destination SHA-256 and ownership checks must pass before libvirt definition or startup. Failure leaves the source stopped and does not authorize the destination to start.
- Existing resumable ZFS receives and held snapshots retain their existing handling. This feature does not discard partial receives.

## Deliberate restrictions

User-share aliases (/mnt/user and /mnt/user0), symlink parents, nested NVRAM storage XML, absent variables files, ambiguous mappings and shared ownership fail preflight. Separate arbitrary firmware directories and zvol-only VMs with nonstandard NVRAM have no sibling image mapping and are not supported by this patch.

An existing non-bundled destination NVRAM file is never silently overwritten, even if disk overwrite was selected. Resolve the stale firmware copy explicitly before retrying. Dataset-contained variables files use the existing dataset ownership/conflict authorization. Beta4 records exact path, inode, device and content evidence before cutover, then revalidates it before removing standalone source state. Unexpected files, links, mounts or changes block cleanup. Variables inside an already-authorized dedicated dataset follow its existing cleanup.

Beta4 extends owned custom NVRAM to Clone and replication/recovery. Clones receive independent UUID-scoped variables files with verified contents. Replication archives custom variables under a canonical UUID-scoped libvirt path; recovery transforms XML to that path. Exact owned variables files are allowed in dedicated datasets; unrelated files and child datasets remain blocked. This is not custom TPM relocation.

## Peer compatibility

Migration remains protocol 5. A new customNvramMigration capability gates the new destination validation commands. A beta3 source refuses a custom path when the destination lacks that capability; standard libvirt paths do not require it. Beta2 recovery negotiation remains unchanged.

Upgrade both peers before using custom NVRAM migration. Older sources still have their old path restriction, even when paired with beta3. Beta4 separately requires firmwareMapping for verified loader/template mapping and customNvramReplication for custom-state replication. Firmware mappings preserve XML format/security attributes and require byte-identical destination code/templates.
