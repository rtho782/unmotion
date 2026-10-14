# Pairing and migration safety in 0.4.7

[0.4.7](https://github.com/rtho782/unmotion/releases/tag/0.4.7) is available through the stable and beta-graduation feeds. It adds independent SSH host verification for new pairing and stricter storage ownership checks. Both feeds install the stable release and select future stable updates.

## Upgrading

Finish active jobs, pause scheduled replication and stop recovery-managed VMs before updating. Retain existing pairings, receive state, host identities and recovery authority. Check pairing and native-hook readiness before resuming operations. Protocol 7 and recovery authority are unchanged; existing 0.4.2–0.4.6 pairings remain usable. New pairings require 0.4.7 or later on both hosts. Upgrade both peers to apply the source-side transfer and cleanup protections in both directions.

For the current **Accept and pair / Cancel** workflow in 0.4.8, see [Pairing hosts](PAIRING.md). Manual comparison is optional in that workflow; the following section describes 0.4.7's original fingerprint-entry interface.

## Pair a host with 0.4.7

On the peer's local console, or through a connection whose host identity you have already verified, run:

```bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256
```

Copy the `SHA256:…` value into **Settings → unMotion → Peer Ed25519 fingerprint**, alongside the peer address, SSH port and root password. Obtain it independently of the connection you are establishing; accepting a key returned by an unverified scan would not prove the peer's identity.

unMotion scans only the peer's Ed25519 host key, compares it with the supplied fingerprint, and sends the password only after they match. A changed key also fails SSH's pinned-key check. The return host key is sent through the authenticated pairing connection, so reciprocal pairing does not trust a second network scan. Both hosts must offer an Ed25519 SSH host key. Existing pins are preserved during upgrades. If a host key legitimately changes, verify it from the console and explicitly remove/recreate the pairing after resolving any dependent replication/recovery policies.

Pair only hosts you fully trust. The root password is used for initial setup and discarded; the installed client keys remain restricted to peer-bound unMotion operations, which include privileged VM and storage actions.

Upgrading preserves existing pins and does not independently authenticate an earlier pairing. If its original trust is uncertain, compare the stored peer `known_hosts` fingerprints with the peer's console before continuing to use it.

## Storage and source cleanup

Native image transfers require a dataset containing only the selected VM's declared disks and supported, ownership-verified UEFI variables file. A child dataset, unrelated file/directory, symbolic or hard link, nested mount, missing disk or unavailable isolation inventory makes the layout shared. Cold and Warm Move then use sparse file transfer for the declared disks. Scheduled replication continues to require isolated ZFS storage. A prepared native copy whose source has become shared is blocked; inspect its retained state rather than discarding receive tokens.

**Delete after validation** still requires five minutes of continuous destination runtime, the original VM/disk manifest, the exact job/peer ownership and a stopped source. Cleanup now checks ordinary jobs as strictly as manually resolved migrations. It never recursively destroys a source ZFS object. It can remove only the exact current migration snapshot and exact VM dataset/zvol. Other snapshots, holds or child datasets block automatic cleanup and retain the source for inspection. Do not remove backups or holds merely to dismiss an error; choose a retain policy if the source storage must remain.

Before deleting a filesystem dataset, cleanup records its original readonly property and mount state in the job's `source-readonly-*.json`, temporarily sets it read-only and repeats contents/ownership checks. Busy failures receive bounded retries against the exact object. A failed cleanup restores the retained dataset's property and mount state. An interruption during that protected interval can leave it read-only or unmounted; cleanup blocks retries until that evidence is inspected. Preserve the record, inspect the job and restore the recorded state before manually resolving the record. Partial cleanup errors retain remaining objects and require inspection before retrying.

Persistent web actions, including clearing job history, require POST and continue to use Unraid's CSRF token validation. History deletion removes eligible job records/logs; it does not delete VM storage.
