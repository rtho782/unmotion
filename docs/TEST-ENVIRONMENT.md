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
- dedicated-dataset and zvol VMs with scheduled replication policies at multiple RPO/retention settings;
- shared datasets, encrypted datasets and qcow2 backing chains that scheduled replication must reject;
- QEMU Guest Agent enabled, disabled and freeze-command-disabled guests;
- interrupted full and incremental replica receives with retained tokens and holds;
- multi-disk replica generations that must not become visible until every GUID is verified;
- destination reboot proving replica filesystems stay unmounted, zvol devices stay hidden and no replica domain is defined;
- TPM/NVRAM mutation during best-effort capture and a later powered-off safe checkpoint;
- foreign destination holds and exact retention cleanup failures.

Snapshot both outer Unraid VMs before destructive cases. Never reuse production VM disks. Confirm the target dataset/path immediately before every manual cleanup command.
