<?php
declare(strict_types=1);

function unmProcessUpgradeInventory(): array {
    $workers=[];$daemons=[];
    $scripts=['unmotion-worker','unmotion-clone-worker','unmotion-seed-worker','unmotion-replication-worker','unmotion-recovery-worker','unmotion-soak-cleanup','unmotion-replication-scheduler','unmotion-replication-lifecycle',
        // Incoming receivers have no local migration job. The forced-command
        // gate remains alive while its rsync/ZFS child streams; agents and the
        // destination-start helper also cover legacy/in-flight remote calls.
        'unmotion-ssh-gate','unmotion-agent','unmotion-start-destination','unmotion-inventory','unmotion-live-worker','unmotion-live-progress','unmotion-live-io','unmotion-live-stream','unmotion-live-key-gate','unmotion-live-native-gate','unmotion-live-client','unmotion-live-ordinary','unmotion-live-cleanup-worker','unmotion-cpu-editor','unmotion-retire-worker','unmotion-seed-cleanup'];
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

/** Complete local-only native-hook dependency set, pinned from the target package. */
function unmProcessFencePaths(): array {
    $base='/usr/local/emhttp/plugins/unmotion/include/';
    return array_merge(['/etc/libvirt/hooks/qemu.d/50-unmotion-recovery'],array_map(static fn($p)=>$base.$p,['native-fence.php','live-native-fence.php','live-firmware.php','live-storage.php','live-zvol-native.php','live-tpm.php','xml-canonical.php']));
}
function unmProcessFenceCompatible(array $target): void {
    $paths=unmProcessFencePaths();$keys=array_keys($target);sort($paths);sort($keys);
    if($paths!==$keys)throw new RuntimeException('The update has no complete compatible native-fence contract; stop live-owned VMs before this fencing-code upgrade.');
    foreach($target as $path=>$sha)if(!is_string($sha)||!preg_match('/\A[a-f0-9]{64}\z/D',$sha)||is_link($path)||!is_file($path)||!hash_equals($sha,(string)hash_file('sha256',$path)))throw new RuntimeException('Native fencing code changes or differs locally; stop live-owned VMs before upgrading. No running VM was adopted.');
}
function unmProcessLiveOwnerUpgradeable(array $r,array $q,array $target,string $run='/run/unmotion/lifecycle'): void {
    if(($r['state']??'')!=='OWNER'||($r['workflow']??'')==='ordinary-pending')throw new RuntimeException('Only a settled live owner can remain running during an update.');
    unmProcessFenceCompatible($target);
    $read=static function(string $p):array{if(is_link($p)||!is_file($p))throw new RuntimeException('Existing native VM admission/proof is missing; update refused without adopting the running VM.');$r=json_decode((string)file_get_contents($p),true);if(!is_array($r))throw new RuntimeException('Existing native VM admission/proof is corrupt.');return $r;};
    $boot=trim((string)file_get_contents('/proc/sys/kernel/random/boot_id'));
    $proof=$read($run.'/native-hook-proof.json');$daemon=$proof['daemon']??[];$p=unmProcessSnapshot((int)($daemon['pid']??0));
    $base='/usr/local/emhttp/plugins/unmotion/include/';$hash='';foreach(['live-native-fence.php','live-firmware.php','live-storage.php','live-zvol-native.php','live-tpm.php','xml-canonical.php'] as $file)$hash.=$target[$base.$file];
    if(!$p||!in_array($p['executable'],['/usr/sbin/libvirtd','/usr/sbin/virtqemud','/usr/bin/libvirtd','/usr/bin/virtqemud'],true)||basename((string)($p['argv'][0]??''))!==basename($p['executable'])||($daemon['bootId']??'')!==$boot||($daemon['startTicks']??'')!==$p['startTicks']||($daemon['exe']??'')!==$p['executable']||($proof['hookSha256']??'')!==$target['/etc/libvirt/hooks/qemu.d/50-unmotion-recovery']||($proof['helperSha256']??'')!==$target[$base.'native-fence.php']||($proof['liveHelperSha256']??'')!==hash('sha256',$hash))throw new RuntimeException('The unchanged native fence is not proven active for this libvirt process; update refused.');
    $uuid=$r['vmUuid'];$a=$read($run.'/live-admissions/'.$uuid.'.json');$at=array_search('-uuid',$q['argv'],true);$name=$r['vmName']??'';
    $compact=['pid'=>$q['pid'],'startTicks'=>$q['startTicks'],'exe'=>$q['executable'],'bootId'=>$q['bootId'],'uuid'=>$uuid];
    if(!is_string($name)||$name===''||str_contains($name,'/')||str_contains($name,"\0")||$at===false||strtolower((string)($q['argv'][$at+1]??''))!==$uuid||$q['bootId']!==$boot||!str_starts_with(basename($q['executable']),'qemu-system-')||trim((string)@file_get_contents('/run/libvirt/qemu/'.$name.'.pid'))!==(string)$q['pid']||($a['jobId']??'')!==$r['jobId']||($a['bootId']??'')!==$boot||($a['phase']??'')!=='RUNNING'||($a['qemu']??null)!==$compact||!unmProcessIdentityMatches($q))throw new RuntimeException('The running live owner does not match its existing native admission; update refused without adopting or restarting it.');
}

function unmProcessPreinstall(): void {
    if(!function_exists('posix_kill'))throw new RuntimeException('PHP POSIX support is required for safe unMotion service upgrades.');
    $bootRestore=unmProcessEarlyBootRestore();
    $installed=@file_get_contents('/usr/local/emhttp/plugins/unmotion/include/lib.php');
    $installedProtocol=is_string($installed)&&preg_match('/const\s+UNM_PROTOCOL\s*=\s*(\d+)\s*;/',$installed,$match)?(int)$match[1]:0;
    // Do not break fencing restoration at boot or prevent an already-protocol-7
    // host from installing cleanup fixes. Legacy entries still cannot mutate.
    if(!$bootRestore&&$installedProtocol!==7)unmProcessAssertPreparedCompatible('/boot/config/plugins/unmotion',7);
    $fenceFiles=function_exists('unmProcessTargetFenceFiles')?unmProcessTargetFenceFiles():[];
    unmProcessAssertManagedGuestsStopped('/boot/config/plugins/unmotion',$bootRestore,$fenceFiles);
    if($bootRestore)echo "unMotion: restoring plugin during verified early boot, before VM Manager or QEMU. Durable recovery authority remains fenced.\n";
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
    unmProcessAssertManagedGuestsStopped('/boot/config/plugins/unmotion',$bootRestore,$fenceFiles);
    echo "unMotion upgrade guard: no active workers; verified scheduling/lifecycle daemons stopped. Do not start new operations until installation completes.\n";
}

/** Standalone: also embedded in the readable PLG before installed code changes. */
function unmProcessAssertPreparedCompatible(string $base,int $targetProtocol): void {
    foreach(glob($base.'/seeds/*/seed.json')?:[] as $path){
        $seed=json_decode((string)file_get_contents($path),true);
        if(!is_array($seed))throw new RuntimeException('A corrupt prepared-copy record prevents safe protocol upgrade.');
        if(($seed['state']??'')==='REMOVED')continue;
        $hasData=!empty($seed['storage'])||!empty($seed['storageStarted'])||!empty($seed['baseSnapshot'])||!empty($seed['pendingSnapshot'])||!empty($seed['generation']);
        if(!$hasData)continue;
        $capsPath=dirname($path).'/remote-capabilities.json';$caps=is_file($capsPath)?json_decode((string)file_get_contents($capsPath),true):[];
        $protocol=(int)($seed['protocolVersion']??$caps['protocolVersion']??0);
        if($protocol!==$targetProtocol)throw new RuntimeException('Prepared/partial Warm Move '.basename(dirname($path)).' has legacy or unproven protocol ownership. Finish/remove it on compatible old peers before upgrading; already-upgraded legacy copies need ownership review. No cleanup was attempted.');
    }
}

// Compatible settled owners keep their existing admission. Never adopt a QEMU.
function unmProcessAssertManagedGuestsStopped(string $base='/boot/config/plugins/unmotion',bool $verifiedEarlyBoot=false,array $fenceFiles=[]): void {
    $liveManaged=[];
    foreach(array_unique(array_merge(glob($base.'/live-managed/*.json')?:[],glob($base.'/live-authority/*.json')?:[])) as $candidate){
        $path=$base.'/live-managed/'.basename($candidate);
        $m=json_decode((string)@file_get_contents($path),true);$uuid=(string)($m['vmUuid']??'');$r=json_decode((string)@file_get_contents($base.'/live-authority/'.$uuid.'.json'),true);
        if(is_link($path)||is_link($candidate)||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid)||basename($path)!==$uuid.'.json'||($m['schemaVersion']??null)!==1||($r['schemaVersion']??null)!==1||($r['vmUuid']??'')!==$uuid||!preg_match('/\Alive-[a-f0-9]{24}\z/D',(string)($m['jobId']??''))||($r['jobId']??'')!==$m['jobId']||!in_array($r['state']??'',['OWNER','MOVING','RECEIVING','RETIRED','ABORTED'],true))throw new RuntimeException('Corrupt live migration authority prevents upgrade.');
        $host=trim((string)@file_get_contents($base.'/host_id'));$pattern='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D';
        if(isset($r['workflow'])&&!in_array($r['workflow'],['ordinary','ordinary-pending'],true)||($r['workflow']??'')==='ordinary-pending'&&(($r['state']??'')!=='OWNER'||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($r['handbackNonce']??''))))throw new RuntimeException('Corrupt ordinary hand-back authority prevents upgrade.');
        if(!$verifiedEarlyBoot&&($r['workflow']??'')==='ordinary-pending')throw new RuntimeException('Finish the pending ordinary hand-back before upgrading.');
        if(!in_array($r['role']??'',['source','destination'],true)||!preg_match($pattern,(string)($r['sourceHostId']??''))||!preg_match($pattern,(string)($r['destinationHostId']??''))||$r['sourceHostId']===$r['destinationHostId']||($r[$r['role'].'HostId']??'')!==$host||!preg_match($pattern,(string)($r['bootId']??''))||!is_int($r['expiresAt']??null)||!in_array($r['state'],$r['role']==='source'?['OWNER','MOVING','RETIRED']:['OWNER','RECEIVING','ABORTED'],true))throw new RuntimeException('Live authority host/role is invalid; upgrade refused.');
        if(!$verifiedEarlyBoot&&in_array($r['state'],['MOVING','RECEIVING'],true))throw new RuntimeException('Reconcile unfinished live migration '.$m['jobId'].' before upgrading.');$liveManaged[$uuid]=$r;
    }
    if(!$verifiedEarlyBoot)foreach(glob($base.'/live-migration/jobs/*/job.json')?:[] as $path){$j=json_decode((string)file_get_contents($path),true);if(!is_array($j)||!in_array($j['state']??'', ['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'],true))throw new RuntimeException('Finish or reconcile unfinished live migration '.basename(dirname($path)).' before upgrading.');}
    foreach(glob($base.'/native-managed/*.json')?:[] as $markerPath){$m=json_decode((string)@file_get_contents($markerPath),true);$id=(string)($m['replicationId']??'');$uuid=(string)($m['vmUuid']??'');$side=(string)($m['side']??'');if(!is_array($m)||($m['schemaVersion']??null)!==1||!preg_match('/^[a-f0-9-]{36}$/',$uuid)||!preg_match('/^repl-[a-f0-9]{24}$/',$id)||!in_array($side,['source','destination'],true))throw new RuntimeException('Corrupt native managed identity prevents safe upgrade.');$paths=$side==='source'?[$base.'/replications/'.$id.'/recovery.json']:(glob($base.'/replicas/*/'.$id.'/recovery.json')?:[]);$matched=0;foreach($paths as $path){$r=json_decode((string)@file_get_contents($path),true);if(is_array($r)&&($r['replicationId']??'')===$id&&strtolower((string)($r['vmUuid']??''))===$uuid&&($r['side']??'')===$side)++$matched;}if($matched!==1)throw new RuntimeException('Exact native managed authority is missing or ambiguous; reconcile it before upgrading.');}
    $managed=$liveManaged;foreach(array_merge(glob($base.'/replications/*/recovery.json')?:[],glob($base.'/replicas/*/*/recovery.json')?:[]) as $path){$r=json_decode((string)@file_get_contents($path),true);if(!is_array($r))throw new RuntimeException('Corrupt recovery authority must be reconciled before upgrade.');if(in_array((string)($r['state']??''),['REPLICATION_ONLY','DISARMED','REMOVED'],true)&&empty($r['armed'])&&empty($r['managedAutostart'])&&empty($r['activationId'])&&empty($r['claim'])&&empty($r['activation']))continue;$uuid=strtolower((string)($r['vmUuid']??''));if(!preg_match('/^[a-f0-9-]{36}$/',$uuid))throw new RuntimeException('Invalid managed VM identity prevents safe upgrade.');$managed[$uuid]=true;}
    if(!$managed)return;
    // Recovery entries deliberately replace any live record with true. An
    // overlapping recovery policy must never inherit the live-owner exception.
    $recoveryManaged=array_filter($managed,static fn($entry)=>$entry===true);
    // At genuine rc.local boot replay libvirt is not started yet. The caller
    // proves that phase and absence of QEMU; missing libvirt alone is NEVER proof.
    // Authority/marker validation above is deliberately retained in both paths.
    if($verifiedEarlyBoot)return;
    $hot=[];foreach(glob('/proc/[0-9]*/cmdline')?:[] as $file){$exe=(string)@readlink(dirname($file).'/exe');if(!str_starts_with(basename($exe),'qemu-system-'))continue;$args=explode("\0",rtrim((string)@file_get_contents($file),"\0"));$at=array_search('-uuid',$args,true);$uuid=$at===false?'':strtolower((string)($args[$at+1]??''));if(!isset($liveManaged[$uuid]))continue;
        if(!$fenceFiles||isset($recoveryManaged[$uuid])||$liveManaged[$uuid]['state']!=='OWNER'||isset($hot[$uuid]))throw new RuntimeException('Running fenced/managed VM '.$uuid.' cannot use a compatible settled-owner upgrade.');
        $q=unmProcessSnapshot((int)basename(dirname($file)));if(!$q)throw new RuntimeException('Managed QEMU lifetime changed during upgrade checks.');unmProcessLiveOwnerUpgradeable($liveManaged[$uuid],$q,$fenceFiles);$hot[$uuid]=$q;
    }
    $run=static function(array $args):string{$p=proc_open(array_merge(['timeout','-k','2','15','virsh'],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Cannot check managed VMs before upgrade.');$out=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Libvirt state is unavailable; upgrade cannot prove managed VMs are stopped.');return trim($out);};
    $inventory=strtolower($run(['list','--all','--uuid']));$defined=$inventory===''?[]:preg_split('/\s+/',$inventory);foreach($defined as $entry)if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$entry))throw new RuntimeException('Libvirt returned an invalid VM inventory; upgrade refused.');
    foreach(array_keys($managed) as $uuid){$state=in_array($uuid,$defined,true)?strtolower($run(['domstate',$uuid])):'undefined';
        if(isset($hot[$uuid])){if(!in_array($state,['running','idle','paused'],true)||!unmProcessIdentityMatches($hot[$uuid]))throw new RuntimeException('Running owner/libvirt state changed during upgrade checks; retry without bypassing its admission.');continue;}
        if(!in_array($state,['shut off','undefined'],true))throw new RuntimeException('Power off recovery-managed VM '.$uuid.' before upgrading unMotion. Existing VM authority and services were left unchanged.');
    }
}

function unmProcessEarlyBootEvidence(array $ancestors,array $processes,bool $installed): bool {
    if($installed)return false;
    $bootParent=false;
    foreach($ancestors as $process)if(unmProcessCommandMatches($process,'/etc/rc.d/rc.local'))$bootParent=true;
    if(!$bootParent)return false;
    foreach($processes as $process){
        $name=basename((string)($process['executable']??''));
        if(preg_match('/^(?:qemu(?:-.*)?|kvm|libvirtd|virtqemud|emhttpd?)$/',$name))return false;
    }
    return true;
}

function unmProcessEarlyBootRestore(): bool {
    // A manual install, array stop, broken daemon or missing socket must not
    // bypass upgrade fencing. Only the actual boot-script ancestry qualifies.
    $installed=is_file('/usr/local/emhttp/plugins/unmotion/VERSION');
    if($installed)return false;
    $ancestors=[];$pid=getmypid();
    for($i=0;$i<32&&$pid>1;$i++){
        $process=unmProcessSnapshot($pid);if($process===null)return false;
        $ancestors[]=$process;$pid=(int)$process['parentPid'];
    }
    if(!unmProcessEarlyBootEvidence($ancestors,[],false))return false;
    $processes=[];
    foreach(glob('/proc/[0-9]*/stat')?:[] as $path){$process=unmProcessSnapshot((int)basename(dirname($path)));if($process!==null)$processes[]=$process;}
    return unmProcessEarlyBootEvidence($ancestors,$processes,false);
}
