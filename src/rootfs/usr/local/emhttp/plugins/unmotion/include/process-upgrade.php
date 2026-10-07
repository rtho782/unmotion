<?php
declare(strict_types=1);

function unmProcessUpgradeInventory(): array {
    $workers=[];$daemons=[];
    $scripts=['unmotion-worker','unmotion-clone-worker','unmotion-seed-worker','unmotion-replication-worker','unmotion-recovery-worker','unmotion-soak-cleanup','unmotion-replication-scheduler','unmotion-replication-lifecycle',
        // Incoming receivers have no local migration job. The forced-command
        // gate remains alive while its rsync/ZFS child streams; agents and the
        // destination-start helper also cover legacy/in-flight remote calls.
        'unmotion-ssh-gate','unmotion-agent','unmotion-start-destination'];
    foreach(glob('/proc/[0-9]*/stat')?:[] as $path){
        $identity=unmProcessSnapshot((int)basename(dirname($path)));if($identity===null)continue;
        foreach($scripts as $name){
            $script='/usr/local/sbin/'.$name;
            if(!unmProcessCommandMatches($identity,$script))continue;
            $offset=($identity['argv'][0]??'')===$script?0:1;$arguments=array_slice($identity['argv'],$offset+1);
            if(unmProcessLegacyServiceAllowed($script,$arguments))$daemons[]=$identity;
            else $workers[]=$identity;
            break;
        }
    }
    return ['workers'=>$workers,'daemons'=>$daemons];
}

function unmProcessPreinstall(): void {
    if(!function_exists('posix_kill'))throw new RuntimeException('PHP POSIX support is required for safe unMotion service upgrades.');
    unmProcessAssertManagedGuestsStopped();
    $inventory=unmProcessUpgradeInventory();
    if($inventory['workers'])throw new RuntimeException('An unMotion migration, prepared-copy, replication, recovery, incoming transfer, remote request or delayed-cleanup worker is active. Let it finish or cancel it, then retry the upgrade. Do not start new operations while upgrading. No package or pairing settings have been changed.');
    // Use fresh exact /proc identities, never old PID files or the old rc stop
    // implementation. Preserve fencing hooks and all durable recovery state.
    foreach($inventory['daemons'] as $identity){
        if(!unmProcessStop($identity,10000))throw new RuntimeException('An exact unMotion runtime daemon did not stop. Upgrade refused. Inspect the runtime and retry; stopped daemons will restart on a successful installation or host boot.');
    }
    // A scheduler could launch a job between the first inventory and its stop.
    // Never terminate that worker or replace its scripts/transport underneath it.
    $remaining=unmProcessUpgradeInventory();
    if($remaining['workers']||$remaining['daemons'])throw new RuntimeException('An unMotion worker or daemon appeared while stopping scheduling. Upgrade refused; let active work finish and retry. Stopped runtime daemons restart on successful installation or host boot.');
    echo "unMotion upgrade guard: no active workers; verified scheduling/lifecycle daemons stopped. Do not start new operations until installation completes.\n";
}

// No adoption of an already-running managed QEMU into a new native start fence.
// Run before stopping lifecycle services or replacing any installed file.
function unmProcessAssertManagedGuestsStopped(string $base='/boot/config/plugins/unmotion'): void {
    foreach(glob($base.'/native-managed/*.json')?:[] as $markerPath){$m=json_decode((string)@file_get_contents($markerPath),true);$id=(string)($m['replicationId']??'');$uuid=(string)($m['vmUuid']??'');$side=(string)($m['side']??'');if(!is_array($m)||($m['schemaVersion']??null)!==1||!preg_match('/^[a-f0-9-]{36}$/',$uuid)||!preg_match('/^repl-[a-f0-9]{24}$/',$id)||!in_array($side,['source','destination'],true))throw new RuntimeException('Corrupt native managed identity prevents safe upgrade.');$paths=$side==='source'?[$base.'/replications/'.$id.'/recovery.json']:(glob($base.'/replicas/*/'.$id.'/recovery.json')?:[]);$matched=0;foreach($paths as $path){$r=json_decode((string)@file_get_contents($path),true);if(is_array($r)&&($r['replicationId']??'')===$id&&strtolower((string)($r['vmUuid']??''))===$uuid&&($r['side']??'')===$side)++$matched;}if($matched!==1)throw new RuntimeException('Exact native managed authority is missing or ambiguous; reconcile it before upgrading.');}
    $managed=[];foreach(array_merge(glob($base.'/replications/*/recovery.json')?:[],glob($base.'/replicas/*/*/recovery.json')?:[]) as $path){$r=json_decode((string)@file_get_contents($path),true);if(!is_array($r))throw new RuntimeException('Corrupt recovery authority must be reconciled before upgrade.');if(in_array((string)($r['state']??''),['REPLICATION_ONLY','DISARMED','REMOVED'],true)&&empty($r['armed'])&&empty($r['managedAutostart'])&&empty($r['activationId'])&&empty($r['claim'])&&empty($r['activation']))continue;$uuid=strtolower((string)($r['vmUuid']??''));if(!preg_match('/^[a-f0-9-]{36}$/',$uuid))throw new RuntimeException('Invalid managed VM identity prevents safe upgrade.');$managed[$uuid]=true;}
    if(!$managed)return;
    $run=static function(array $args):string{$p=proc_open(array_merge(['timeout','-k','2','15','virsh'],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Cannot check managed VMs before upgrade.');$out=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Libvirt state is unavailable; upgrade cannot prove managed VMs are stopped.');return trim($out);};
    $inventory=strtolower($run(['list','--all','--uuid']));$defined=$inventory===''?[]:preg_split('/\s+/',$inventory);foreach($defined as $entry)if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$entry))throw new RuntimeException('Libvirt returned an invalid VM inventory; upgrade refused.');
    foreach(array_keys($managed) as $uuid)if(in_array($uuid,$defined,true)&&strtolower($run(['domstate',$uuid]))!=='shut off')throw new RuntimeException('Power off recovery-managed VM '.$uuid.' before upgrading unMotion. Existing VM authority and services were left unchanged.');
}
