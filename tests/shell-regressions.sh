#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RC="$ROOT/src/rootfs/etc/rc.d/rc.unmotion"
SEED="$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker"
WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-worker"
CLONE_WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-clone-worker"
CLONE_TRANSFORM="$ROOT/src/rootfs/usr/local/sbin/unmotion-clone-transform"
REPLICATION_WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-replication-worker"
REPLICATION_LIFECYCLE="$ROOT/src/rootfs/usr/local/sbin/unmotion-replication-lifecycle"
REPLICATION_START_GATE="$ROOT/src/rootfs/usr/local/sbin/unmotion-replication-start-gate"
CRITICAL_NOTIFY="$ROOT/src/rootfs/usr/local/sbin/unmotion-notify-critical"

row=$'zvol\tcache/Squid Proxy\t\tunmotion-seed-seed-test-g1\t\t'
IFS=$'\034' read -r kind src dst snap rest <<< "${row//$'\t'/$'\034'}"
[[ "$kind" == zvol ]]
[[ "$src" == 'cache/Squid Proxy' ]]
[[ -z "$dst" ]]
[[ "$snap" == 'unmotion-seed-seed-test-g1' ]]

grep -Fq 'IFS=$'"'"'\034'"'"' read -r kind src dst snap' "$SEED"
grep -Fq 'zfs get -H -o value receive_resume_token $(sq "$dst")' "$SEED"
grep -Fq 'remote(){ ssh -n "${SSH_OPTS[@]}" "$REMOTE" "$@"; }' "$SEED"
grep -Fq 'remote(){ ssh -n "${SSH_OPTS[@]}" "$REMOTE" "$@"; }' "$WORKER"
grep -Fq "awk -F '\\t' -v t=\"unmotion:\$SEED_ID\"" "$SEED"
! grep -F 'remote_resume_token(){' "$SEED" | grep -Fq '|| true'
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$SEED")" == 1 ]]
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$WORKER")" == 1 ]]
grep -Fq 'if ((WARM_MODE));then args+=(--inplace --no-whole-file);else args+=(--partial-dir=.unmotion-partial);fi' "$WORKER"
grep -Fq 'protect_warm_images(){' "$WORKER"
grep -Fq 'DEST_START_ATTEMPTED == 0' "$WORKER"
grep -Fq 'chmod 0400 $(sq "$dst")' "$WORKER"
[[ "$(grep -Fc 'protect_warm_images; restore_source' "$WORKER")" == 3 ]]

publish_line="$(grep -nF 'agent_call replication-publish' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
commit_line="$(grep -nF 'atomic_state_update commit' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
cleanup_line="$(grep -n '^cleanup_source_pending$' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
prune_line="$(grep -n '^reconcile_destination_retention$' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
[[ -n "$publish_line" && -n "$commit_line" && -n "$cleanup_line" && -n "$prune_line" ]]
((publish_line < commit_line && commit_line < cleanup_line && cleanup_line < prune_line))
grep -Fq 'zfs destroy "$full"' "$REPLICATION_WORKER"
! grep -Fq 'zfs destroy -r' "$REPLICATION_WORKER"
! grep -Fq 'zfs receive -A' "$REPLICATION_WORKER"
grep -Fq 'remote(){ ssh -n "${SSH_OPTS[@]}" "$REMOTE" "$@"; }' "$REPLICATION_WORKER"
[[ "$(grep -Fc "awk -F '\\t' -v t=" "$REPLICATION_WORKER")" == 3 ]]
grep -Fq '"lastScheduledSlot"=>(int)$argv[2]]);' "$REPLICATION_WORKER"
grep -Fq 'Committed incremental base snapshot for $src is missing on the source or destination; restore the exact base before retrying' "$REPLICATION_WORKER"
grep -Fq 'Committed incremental base snapshot GUID for $src does not match; refusing an unsafe incremental transfer' "$REPLICATION_WORKER"
grep -Fq 'Destination object for $src exists without the expected snapshot or resumable receive state; manual inspection is required' "$REPLICATION_WORKER"
grep -Fq 'Destination snapshot hold verification failed for $src; no recovery point was committed' "$REPLICATION_WORKER"
grep -Fq '/usr/local/sbin/unmotion-host-state tpm' "$REPLICATION_WORKER"
grep -Fq 'Multiple distinct TPM state directories match this VM UUID' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/host-state.php"
grep -Fq 'lastHostState.tpmCaptured' "$REPLICATION_WORKER"
grep -Fq '$compatible=is_array($s)&&(bool)($s["tpmPresent"]??false)===$h["tpmPresent"]' "$REPLICATION_WORKER"
grep -Fq 'if(($h["quality"]??"")==="safe")unset($h["safeFallback"])' "$REPLICATION_WORKER"
grep -Fq 'unset($s["safeFallback"]);$h["safeFallback"]=$s' "$REPLICATION_WORKER"
grep -Fq 'recovery_authority_guard pre-cleanup' "$REPLICATION_WORKER"
grep -Fq 'recovery_authority_guard publication' "$REPLICATION_WORKER"
grep -Fq 'recovery_authority_guard commit' "$REPLICATION_WORKER"
grep -Fq 'recovery_authority_guard pruning' "$REPLICATION_WORKER"
commit_line="$(grep -nF 'atomic_state_update commit "$PENDING_JSON"' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
ack_line="$(grep -nF 'ack_lifecycle_request || interrupt' "$REPLICATION_WORKER" | tail -n1 | cut -d: -f1)"
((commit_line < ack_line))
! grep -Fq 'rm -f -- "$LIFECYCLE_CHECKPOINT_REQUEST"' "$REPLICATION_WORKER"
grep -Fq 'LAST_REMOTE_OK' "$REPLICATION_WORKER"
grep -Fq 'Using a fresh VM/MAC-bound Guest Agent observation captured immediately before shutdown.' "$REPLICATION_WORKER"

for legacy_worker in "$WORKER" "$CLONE_WORKER" "$SEED"; do
  grep -Fq 'recovery-legacy-interlock "$VM_UUID"' "$legacy_worker"
done
worker_vm_lock_line="$(grep -nF 'exec 8>"/var/lock/unmotion-$VM_UUID.lock"' "$WORKER" | head -n1 | cut -d: -f1)"
worker_interlock_line="$(grep -nF 'recovery-legacy-interlock "$VM_UUID"' "$WORKER" | head -n1 | cut -d: -f1)"
clone_vm_lock_line="$(grep -nF 'exec 9>"/var/lock/unmotion-$VM_UUID.lock"' "$CLONE_WORKER" | head -n1 | cut -d: -f1)"
clone_interlock_line="$(grep -nF 'recovery-legacy-interlock "$VM_UUID"' "$CLONE_WORKER" | head -n1 | cut -d: -f1)"
seed_vm_lock_line="$(grep -nF 'exec 8>"/var/lock/unmotion-$VM_UUID.lock"' "$SEED" | head -n1 | cut -d: -f1)"
seed_interlock_line="$(grep -nF 'recovery-legacy-interlock "$VM_UUID"' "$SEED" | head -n1 | cut -d: -f1)"
((worker_vm_lock_line < worker_interlock_line && clone_vm_lock_line < clone_interlock_line && seed_vm_lock_line < seed_interlock_line))

grep -Fq 'vm_is_off(){ [[ "$1" == '\''shut off'\'' ]]; }' "$REPLICATION_LIFECYCLE"
grep -Fq '$unarmed=["REPLICATION_ONLY","DISARMED","REMOVED"]' "$REPLICATION_LIFECYCLE"
grep -Fq 'unmRecoveryLifecycleFence' "$REPLICATION_LIFECYCLE"
grep -Fq 'SPLIT_BRAIN_SUSPECTED' "$REPLICATION_LIFECYCLE"
grep -Fq 'SPLIT_BRAIN_FENCING' "$REPLICATION_LIFECYCLE"
grep -Fq 'SPLIT_BRAIN_UNRESOLVED' "$REPLICATION_LIFECYCLE"
grep -Fq '/var/run/unmotion/replication-lifecycle.pid' "$REPLICATION_LIFECYCLE"
grep -Fq 'virsh event --all --event lifecycle --loop --timestamp' "$REPLICATION_LIFECYCLE"
grep -Fq 'guest-network-get-interfaces' "$REPLICATION_LIFECYCLE"
grep -Fq 'shutdown-' "$REPLICATION_LIFECYCLE"
grep -Fq 'timeout 45 php -r' "$REPLICATION_LIFECYCLE"
grep -Fq 'unmRecoveryPrepareHostShutdownHolds($argv[1])' "$REPLICATION_LIFECYCLE"
shutdown_fence_line="$(grep -nF 'mv -f -- "$temporary" "$SHUTDOWN_FENCE"' "$REPLICATION_LIFECYCLE" | head -n1 | cut -d: -f1)"
shutdown_hold_line="$(grep -nF 'unmRecoveryPrepareHostShutdownHolds($argv[1])' "$REPLICATION_LIFECYCLE" | head -n1 | cut -d: -f1)"
[[ -n "$shutdown_fence_line" && -n "$shutdown_hold_line" ]]
((shutdown_fence_line < shutdown_hold_line))
grep -Fq 'unmRecoveryReconcileSourceStartAuthorization($argv[1])' "$REPLICATION_LIFECYCLE"
grep -Fq 'reconcile_after_real_boot(){' "$REPLICATION_LIFECYCLE"
grep -Fq '[[ "$source_boot" != "$BOOT_ID" ]] ||' "$REPLICATION_LIFECYCLE"
grep -Fq '[[ ! -e "$SHUTDOWN_FENCE" ]] || return 1' "$REPLICATION_LIFECYCLE"
grep -Fq 'timeout 40 php -r' "$REPLICATION_LIFECYCLE"
grep -Fq 'next_delay=$((delay*2)); ((next_delay<=300)) || next_delay=300' "$REPLICATION_LIFECYCLE"
grep -Fq '[[ "$recovery_valid" == 1 && "$sole_policy" == 1 && "$inventory_valid" == 1 ]] && reconcile_after_real_boot "$id" "$uuid" "$name" "$source_boot" "$term"' "$REPLICATION_LIFECYCLE"
grep -Fq '[[ "$armed" == 1 && "$desired" == 1 && "$boot_reconciled" == 1 && "$sole_policy" == 1 && "$inventory_valid" == 1 ]] && maybe_managed_start' "$REPLICATION_LIFECYCLE"
boot_reconcile_line="$(grep -nF 'reconcile_after_real_boot "$id" "$uuid" "$name" "$source_boot" "$term"' "$REPLICATION_LIFECYCLE" | tail -n1 | cut -d: -f1)"
managed_start_line="$(grep -nF 'boot_reconciled" == 1 && "$sole_policy" == 1 && "$inventory_valid" == 1 ]] && maybe_managed_start' "$REPLICATION_LIFECYCLE" | tail -n1 | cut -d: -f1)"
[[ -n "$boot_reconcile_line" && -n "$managed_start_line" ]]
((boot_reconcile_line < managed_start_line))
! grep -F 'reconcile_after_real_boot "$id"' "$REPLICATION_LIFECYCLE" | grep -Fq 'desired'
grep -Fq '[[ ! -e "$SHUTDOWN_FENCE" ]] && ! vm_is_off "$current"' "$REPLICATION_LIFECYCLE"
grep -Fq 'if [[ "$recovery_state" == HOLDOFF && "$authority" == SOURCE ]]' "$REPLICATION_LIFECYCLE"
grep -Fq 'the exact same-boot running marker is absent or stale' "$REPLICATION_LIFECYCLE"
grep -Fq 'more than one armed or non-reconciled recovery policy exists for this VM UUID' "$REPLICATION_LIFECYCLE"
grep -Fq 'running_authorized "$id" "$uuid" "$term" "$recovery_state" "$authority" "$sole_policy" "$inventory_valid"' "$REPLICATION_LIFECYCLE"
grep -Fq '"$STATE/$id.running-authorized"' "$REPLICATION_LIFECYCLE"
grep -Fq 'report_unavailable_vm_state "$id" "$uuid" "$name" "$armed"' "$REPLICATION_LIFECYCLE"
grep -Fq '((now-first>=60)) || return 0' "$REPLICATION_LIFECYCLE"
grep -Fq 'if [[ "$armed" != 1 ]]' "$REPLICATION_LIFECYCLE"
grep -Fq 'libvirt has not returned a provable VM state for at least 60 seconds' "$REPLICATION_LIFECYCLE"
grep -Fq 'unmRecoveryLifecycleReconcileDestination($argv[1])' "$REPLICATION_LIFECYCLE"
grep -Fq 'DESTINATION_RETRY_AT' "$REPLICATION_LIFECYCLE"
grep -Fq 'next_delay=$((delay*2)); ((next_delay<=60)) || next_delay=60' "$REPLICATION_LIFECYCLE"
grep -Fq "result='the exact policy or VM lock is busy'" "$REPLICATION_LIFECYCLE"
grep -Fq 'A domain with recovery UUID $uuid is present but is not owned' "$REPLICATION_LIFECYCLE"
grep -Fq 'unMotion did not stop or modify the foreign domain' "$REPLICATION_LIFECYCLE"
grep -Fq 'nohup setsid "$WORKER" "$id" "$slot" opportunistic >>"$logfile" 2>&1 9>&- &' "$REPLICATION_LIFECYCLE"
grep -Fq 'virsh event --all --event lifecycle --loop --timestamp >&7 2>/dev/null 9>&- &' "$REPLICATION_LIFECYCLE"
hold_continuation_line="$(grep -nF 'if [[ "$recovery_state" == HOLDOFF && "$authority" == SOURCE ]]' "$REPLICATION_LIFECYCLE" | head -n1 | cut -d: -f1)"
start_gate_check_line="$(grep -nF '$START_GATE "$id" "$uuid" --check' "$REPLICATION_LIFECYCLE" | head -n1 | cut -d: -f1)"
start_gate_consume_line="$(grep -nF '$START_GATE "$id" "$uuid" --consume-permit' "$REPLICATION_LIFECYCLE" | head -n1 | cut -d: -f1)"
[[ -n "$hold_continuation_line" && -n "$start_gate_check_line" && -n "$start_gate_consume_line" ]]
((hold_continuation_line < start_gate_check_line && hold_continuation_line < start_gate_consume_line))
grep -Fq 'start-permits' "$REPLICATION_START_GATE"
grep -Fq '$state!=="STANDBY"' "$REPLICATION_START_GATE"
grep -Fq 'glob($base."/replications/*/recovery.json")' "$REPLICATION_START_GATE"
grep -Fq 'this is not the sole armed or non-reconciled recovery policy for the VM UUID' "$REPLICATION_START_GATE"
grep -Fq 'schemaVersion"]??0)!==1' "$REPLICATION_START_GATE"
grep -Fq 'protocolVersion"]??0)!==6' "$REPLICATION_START_GATE"
grep -Fq 'recoveryProtocolVersion"]??0)!==1' "$REPLICATION_START_GATE"
! grep -Fq '"DISARMING"' "$REPLICATION_START_GATE"
! grep -Fq '"ARMING"' "$REPLICATION_START_GATE"
grep -Fq 'timeout 15 "$notify"' "$CRITICAL_NOTIFY"
! grep -Fq 'timeout 15 "$notify" -e '\''unMotion'\'' -s "$subject" -d "$description" -i alert >/dev/null 2>&1 || true' "$CRITICAL_NOTIFY"

startup_check_line="$(grep -nF '"$REPLICATION_LIFECYCLE" --startup-check' "$RC" | head -n1 | cut -d: -f1)"
daemon_start_line="$(grep -nF 'nohup setsid "$REPLICATION_LIFECYCLE" --daemon' "$RC" | head -n1 | cut -d: -f1)"
[[ -n "$startup_check_line" && -n "$daemon_start_line" ]]
((startup_check_line < daemon_start_line))
grep -Fq 'pid_matches "$pid" "$REPLICATION_LIFECYCLE" --daemon' "$RC"
grep -Fq 'stop_replication_process "$pid" "$REPLICATION_LIFECYCLE" --daemon' "$RC"
prepare_line="$(grep -nF 'prepare_recovery_shutdown || true' "$RC" | head -n1 | cut -d: -f1)"
lifecycle_stop_line="$(grep -nF '  stop_replication_lifecycle' "$RC" | tail -n1 | cut -d: -f1)"
worker_stop_line="$(grep -nF '  stop_replication_workers' "$RC" | tail -n1 | cut -d: -f1)"
[[ -n "$prepare_line" && -n "$lifecycle_stop_line" && -n "$worker_stop_line" ]]
((prepare_line < lifecycle_stop_line && lifecycle_stop_line < worker_stop_line))
grep -Fq 'restart) stop_replication_scheduler || exit 75; stop_replication_lifecycle || exit 75; init_config; advertise; start_replication_lifecycle && start_replication_scheduler ;;' "$RC"
grep -Fq 'pid_matches "$pid" "$script" "$expected_arg" && return 75' "$RC"
grep -Fq '<txt-record>protocol=5</txt-record>' "$RC"
grep -Fq '<txt-record>protocol_min=5</txt-record>' "$RC"
grep -Fq '<txt-record>protocol_max=6</txt-record>' "$RC"
grep -Fq '<txt-record>recovery_protocol=1</txt-record>' "$RC"

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

# A running source may finish its current same-boot/term execution after a
# manual hold, but graceful poweroff consumes that continuation marker.  A
# later start during HOLDOFF is new and must never reach the start-permit gate.
awk '/^running_authorized\(\)\{/{copy=1} copy{print} copy&&/^}$/{exit}' "$REPLICATION_LIFECYCLE" > "$TMP/running-authorized.sh"
# shellcheck source=/dev/null
source "$TMP/running-authorized.sh"
STATE="$TMP/lifecycle-state"
BOOT_ID=11111111-1111-4111-8111-111111111111
START_GATE="$TMP/start-gate-mock"
START_GATE_CALLS="$TMP/start-gate-calls"
export START_GATE_CALLS
mkdir -p "$STATE"
cat > "$START_GATE" <<'SH'
#!/bin/bash
printf '%s\n' "$*" >> "$START_GATE_CALLS"
exit 1
SH
chmod 0700 "$START_GATE"
write_runtime(){ printf '%s\n' "$2" > "$1"; }
hold_policy=repl-hold-aaaaaaaa
hold_uuid=22222222-2222-4222-8222-222222222222
printf '%s\n' "$BOOT_ID:7:$hold_uuid" > "$STATE/$hold_policy.running-authorized"
running_authorized "$hold_policy" "$hold_uuid" 7 HOLDOFF SOURCE 1 1
[[ ! -e "$START_GATE_CALLS" ]]
rm -f -- "$STATE/$hold_policy.running-authorized"
if running_authorized "$hold_policy" "$hold_uuid" 7 HOLDOFF SOURCE 1 1 >/dev/null; then
  echo 'A powered-off HOLDOFF VM was incorrectly authorized to restart.' >&2
  exit 1
fi
[[ ! -e "$START_GATE_CALLS" ]]

# Transient libvirt outages fail closed immediately, but only an armed policy
# that remains continuously unavailable for 60 seconds may notify.  Ordinary
# replication-only policies must never be described as recovery-managed.
awk '/^report_unavailable_vm_state\(\)\{/{copy=1} copy{print} copy&&/^}$/{exit}' "$REPLICATION_LIFECYCLE" > "$TMP/report-unavailable.sh"
# shellcheck source=/dev/null
source "$TMP/report-unavailable.sh"
STATE="$TMP/unavailable-state"; mkdir -p "$STATE"
BOOT_ID=33333333-3333-4333-8333-333333333333
UNAVAILABLE_CALLS="$TMP/unavailable-calls"
write_runtime(){ printf '%s\n' "$2" > "$1"; }
notify_once(){ printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" >> "$UNAVAILABLE_CALLS"; }
unavailable_policy=repl-unavailable-aaaa
unavailable_uuid=44444444-4444-4444-8444-444444444444
printf '%s\n' "$(( $(date +%s)-120 ))" > "$STATE/$unavailable_policy.libvirt-unavailable-since"
report_unavailable_vm_state "$unavailable_policy" "$unavailable_uuid" 'Replication only VM' 0
[[ ! -e "$UNAVAILABLE_CALLS" && ! -e "$STATE/$unavailable_policy.libvirt-unavailable-since" ]]
report_unavailable_vm_state "$unavailable_policy" "$unavailable_uuid" 'Armed VM' 1
[[ ! -e "$UNAVAILABLE_CALLS" && -e "$STATE/$unavailable_policy.libvirt-unavailable-since" ]]
printf '%s\n' "$(( $(date +%s)-61 ))" > "$STATE/$unavailable_policy.libvirt-unavailable-since"
report_unavailable_vm_state "$unavailable_policy" "$unavailable_uuid" 'Armed VM' 1
[[ "$(wc -l < "$UNAVAILABLE_CALLS")" == 1 ]]
grep -Fq 'Managed VM state remains unavailable' "$UNAVAILABLE_CALLS"

# Two active policies targeting different destinations for the same source VM
# are globally non-unique.  Both lifecycle rows must be start-fenced, and even
# an otherwise exact running marker cannot bypass that policy-level gate.
awk '/^policy_rows\(\)\{/{copy=1} copy{print} copy&&/^}$/{exit}' "$REPLICATION_LIFECYCLE" > "$TMP/policy-rows.sh"
# shellcheck source=/dev/null
source "$TMP/policy-rows.sh"
POLICIES="$TMP/policies"
for duplicate_policy in repl-dest-aaaaaaaa repl-dest-bbbbbbbb; do
  mkdir -p "$POLICIES/$duplicate_policy"
  cat > "$POLICIES/$duplicate_policy/policy.json" <<JSON
{"id":"$duplicate_policy","vmUuid":"$hold_uuid","vmName":"Duplicate source","peerHostId":"peer-$duplicate_policy","enabled":true}
JSON
  cat > "$POLICIES/$duplicate_policy/recovery.json" <<JSON
{"schemaVersion":1,"protocolVersion":6,"recoveryProtocolVersion":1,"replicationId":"$duplicate_policy","vmUuid":"$hold_uuid","state":"STANDBY","authority":"SOURCE","term":7,"armed":true}
JSON
done
mapfile -t duplicate_rows < <(policy_rows)
[[ "${#duplicate_rows[@]}" == 2 ]]
for duplicate_row in "${duplicate_rows[@]}"; do
  sole_b="$(awk -F '\t' '{print $13}' <<< "$duplicate_row")"
  [[ "$(printf '%s' "$sole_b" | base64 -d)" == 0 ]]
done
printf '%s\n' "$BOOT_ID:7:$hold_uuid" > "$STATE/repl-dest-aaaaaaaa.running-authorized"
if running_authorized repl-dest-aaaaaaaa "$hold_uuid" 7 HOLDOFF SOURCE 0 1 >/dev/null; then
  echo 'A duplicate recovery policy incorrectly authorized a running VM.' >&2
  exit 1
fi
[[ ! -e "$START_GATE_CALLS" ]]

cat >"$TMP/source.xml" <<'XML'
<domain type='kvm'><name>Squid Proxy</name><uuid>11111111-1111-4111-8111-111111111111</uuid><genid>22222222-2222-4222-8222-222222222222</genid><os><nvram>/etc/libvirt/qemu/nvram/source_VARS.fd</nvram></os><devices><disk type='file' device='disk'><source file='/mnt/pool/domains/Squid Proxy/vdisk1.qcow2'/></disk><interface type='bridge'><mac address='52:54:00:00:00:01'/><source bridge='br0'/><target dev='vnet7'/></interface><hostdev mode='subsystem' type='usb'><source><vendor id='0x1234'/><product id='0xabcd'/></source></hostdev><graphics type='vnc' port='5907'/></devices></domain>
XML
cat >"$TMP/plan.json" <<'JSON'
{"clone":{"name":"Squid Proxy - Clone","uuid":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","genid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","macs":["52:54:00:12:34:56"]},"pathMap":{"/mnt/pool/domains/Squid Proxy/vdisk1.qcow2":"/mnt/pool/domains/Squid Proxy - Clone/vdisk1.qcow2","/etc/libvirt/qemu/nvram/source_VARS.fd":"/etc/libvirt/qemu/nvram/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa_VARS.fd"}}
JSON
php "$CLONE_TRANSFORM" "$TMP/source.xml" "$TMP/disconnected.xml" "$TMP/plan.json" disconnected
grep -Fq '<name>Squid Proxy - Clone</name>' "$TMP/disconnected.xml"
grep -Fq '<uuid>aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa</uuid>' "$TMP/disconnected.xml"
grep -Fq 'address="52:54:00:12:34:56"' "$TMP/disconnected.xml"
grep -Fq 'state="down"' "$TMP/disconnected.xml"
grep -Fq 'Squid Proxy - Clone/vdisk1.qcow2' "$TMP/disconnected.xml"
! grep -Fq '<hostdev' "$TMP/disconnected.xml"
! grep -Fq 'vnet7' "$TMP/disconnected.xml"
php "$CLONE_TRANSFORM" "$TMP/source.xml" "$TMP/connected.xml" "$TMP/plan.json" connected
! grep -Fq '<link' "$TMP/connected.xml"

echo 'Shell migration and scheduled-replication regressions passed.'
