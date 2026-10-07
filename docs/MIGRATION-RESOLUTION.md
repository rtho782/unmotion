# Resolving a manually recovered migration

Author: Richard Skinner

Post-recovery addition in 0.4.0-beta4.

An attempted destination start is a safety boundary: unMotion must not restart the source or repeat a transfer if the destination may have started. The job remains ATTENTION_REQUIRED until inspected.

After fixing the destination configuration and starting it manually, choose **Resolve migration** in the source Jobs table. Both peers need beta4. The action checks source shutdown, destination runtime, recorded host identities, reciprocal pairing and original writable disk paths/formats in both active and inactive XML. Harmless target names, USB corrections and NVRAM path edits are not overwritten.

The in-page confirmation describes the original source policy:

- Retain: rename the stopped source with the migrated-to suffix and leave autostart disabled.
- Unregister: remove only the stopped source definition, preserving disks, NVRAM and TPM.
- Delete: rename the stopped source and start a fresh five-minute continuous destination-runtime validation before the normal exact cleanup request. No deletion happens merely by acknowledging the dialog.

The operation journals progress before irreversible steps, so retries can continue an already-performed rename or unregistration without repeating it. Failed checks leave the job unresolved with an explanation. Destination XML, storage and power state are never changed by resolution.

Do not start the retained source while the destination is authoritative. Two running copies, unavailable libvirt/SSH, unrelated ownership markers, replaced disks or armed replica recovery authority block this action.

Older beta3 jobs can be resolved only when surviving evidence is sufficient. In particular, beta3 start failures have no pre-start exact deletion manifest: those delete requests cannot safely be reconstructed. Prepared Warm Move snapshots/staging are retained for inspection rather than blindly replaying cleanup. A partial destructive cleanup also requires inspection; the resolver does not guess which missing files were deleted.
