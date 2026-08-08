# Recommended test environment

Use an isolated development VLAN and disposable outer-VM snapshots:

```text
AI-DEV01       Git, Codex Desktop and build tools
UNRAID-DEV01   source test host, ZFS pools, file and zvol VMs
UNRAID-DEV02   destination test host with deliberately different mappings
```

Suggested fixtures:

- a stopped file-backed VM;
- a stopped zvol-backed VM;
- a running QEMU-guest-agent VM for Warm Move;
- VM and dataset names containing spaces;
- UEFI and software-TPM VM;
- shared-dataset image requiring `rsync` fallback;
- ISO mapped locally, copied and omitted;
- duplicate VM name and duplicate UUID conflicts;
- missing bridge/USB mapping;
- insufficient destination capacity;
- interrupted SSH/ZFS receive with a resume token;
- held source/destination snapshots;
- source and destination running different plugin versions.

Snapshot both outer Unraid VMs before destructive cases. Never reuse production VM disks. Confirm the target dataset/path immediately before every manual cleanup command.

