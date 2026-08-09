# Local VM cloning

Local cloning is a post-recovery `0.3.1-RC2` feature. It does not alter peer protocol version 5 and does not require a paired host.

## RC2 safety contract

- The source VM must be powered off and its inactive libvirt XML must remain unchanged between preflight and execution.
- The source definition, disks, datasets, snapshots and autostart setting are not changed.
- The clone is a full independent copy. unMotion does not create dependent `zfs clone` datasets.
- Individual ZFS datasets and zvols use non-recursive snapshots and send/receive. Shared and non-ZFS images use sparse file copies.
- Destination VM names, paths and ZFS objects must not exist. RC2 has no clone-overwrite mode.
- The clone receives a new libvirt UUID, genid and MAC address on every NIC. Fixed graphics ports are released and autostart is disabled.
- UEFI NVRAM is copied to a distinct UUID-scoped path. PCIe passthrough and software-TPM VMs are blocked. USB host devices are removed.
- Attached ISO paths are retained; ISO files are not copied or treated as writable disks.
- A clone finishes powered off. Without successful guest customization its NIC links remain down.

## Ubuntu customization

The optional Ubuntu mode starts only the new clone, with all virtual NIC links forced down. It waits for QEMU Guest Agent, verifies that the guest reports Ubuntu, maps cloned MAC addresses to guest interface names, and then:

- archives existing `/etc/netplan/*.yaml` files inside `/etc/netplan/`;
- writes a DHCP Netplan definition for each cloned NIC;
- assigns a hostname derived from the clone name;
- regenerates `/etc/machine-id` and OpenSSH host keys;
- runs `netplan generate` and syncs the filesystem.

unMotion then requests a guest-agent shutdown and leaves the clone stopped. Only after all steps succeed is the libvirt definition rewritten without forced link-down state. If detection or customization fails, the independent clone is retained stopped with its NIC links down for console-based repair.

RC2 intentionally does not attempt generic in-guest static-IP editing. Distribution, renderer, NetworkManager, cloud-init and application-specific identity handling vary too widely for a safe universal mutation.
