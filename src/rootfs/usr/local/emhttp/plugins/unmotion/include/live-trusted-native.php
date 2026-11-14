<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmLiveTrusted(array $job): bool {return ($job['nativeHandoverVersion']??null)===1;}
function unmLiveRequireNativeWorker(array $job): void {
    if(!unmLiveTrusted($job))throw new RuntimeException('This queued job predates native live handover. Cancel/reconcile it and create a fresh native job; existing authority and retained data are unchanged.');
}
function unmLiveTrustedRollbackPossible(array $state,?array $current,array $original): bool {
    return !$state['qemuAbsent']&&in_array($state['state'],['running','idle'],true)&&$current!==null&&$current===$original;
}
function unmLiveValidateJobMode(array $j): void {
    $schema=$j['schemaVersion']??null;
    if($schema===1&&!array_key_exists('nativeHandoverVersion',$j))return;
    if($schema!==2||!unmLiveTrusted($j))throw new RuntimeException('Unknown or corrupt native handover job mode; do not fall back to the legacy transport.');
    unmSshCipher(['ssh_cipher'=>$j['sshCipher']??null]);
}
function unmLiveTrustedCompletionEvidence(array $j,array $r,array $a,?array $q,string $boot,string $migration,string $state): bool {
    return unmLiveTrusted($j)&&$j['role']==='destination'&&$j['bootId']===$boot&&($r['jobId']??'')===$j['id']&&($r['bootId']??'')===$boot&&($r['role']??'')==='destination'&&in_array($r['state']??'',['RECEIVING','OWNER'],true)&&($a['jobId']??'')===$j['id']&&($a['bootId']??'')===$boot&&($a['phase']??'')==='RUNNING'&&$q!==null&&($a['qemu']??null)===$q&&$migration==='completed'&&in_array($state,['running','idle','paused'],true);
}
/** Only locally recorded native success, never a request-supplied flag. */
function unmLiveTrustedSuccessCheck(array $proof,array $j): bool {
    return unmLiveTrusted($j)&&($proof['schemaVersion']??null)===1&&($proof['jobId']??'')===$j['id']&&($proof['vmUuid']??'')===$j['vmUuid']&&($proof['sourceHostId']??'')===$j['sourceHostId']&&($proof['destinationHostId']??'')===$j['destinationHostId']&&is_string($j['sourceBootId']??null)&&$j['sourceBootId']!==''&&is_string($j['destinationBootId']??null)&&$j['destinationBootId']!==''&&($proof['sourceBootId']??'')===$j['sourceBootId']&&($proof['destinationBootId']??'')===$j['destinationBootId']&&($proof['contract']??'')===unmLiveCompletionContract($j)&&in_array($proof['evidence']??'',['native-cli-exit-0','authenticated-receiver-completed'],true);
}
function unmLiveTrustedSuccess(array $j): ?array {
    if(!unmLiveTrusted($j)||$j['role']!=='source')return null;
    $path=dirname(unmLiveJobPath($j['id'])).'/native-success.json';
    try{if(!is_file($path)||is_link($path))return null;$p=unmNativeJson($path);return unmLiveTrustedSuccessCheck($p,$j)?$p:null;}catch(Throwable){return null;}
}
/** Called after native CLI exit 0, or fresh authenticated receiver completion. */
function unmLiveTrustedRecordSuccess(array $j,string $evidence): void {
    if($j['role']!=='source')throw new RuntimeException('Native success must be journalled by its source.');
    $p=['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'sourceHostId'=>$j['sourceHostId'],'destinationHostId'=>$j['destinationHostId'],'sourceBootId'=>$j['sourceBootId']??null,'destinationBootId'=>$j['destinationBootId']??null,'contract'=>unmLiveCompletionContract($j),'evidence'=>$evidence,'observedAt'=>gmdate('c')];
    if(!unmLiveTrustedSuccessCheck($p,$j))throw new RuntimeException('Native success is not bound to both admitted boots and the migration contract.');
    $path=dirname(unmLiveJobPath($j['id'])).'/native-success.json';unmNativeWrite($path,$p);unmLiveCommand(['sync','-f',$path]);
}
/** Bookkeeping AFTER libvirt resumes the guest; never adds a normal-path pause. */
function unmLiveTrustedCommit(array $peer,array $j,array $status): array {
    unmLiveFenceProof();
    if($status['authority']==='OWNER'&&in_array($j['state'],['COMMITTING','COMPLETE'],true)){
        // Ownership was already durably granted. Finish an interrupted journal
        // write without creating a second grant or changing the VM power state.
        unmLiveDataRevoke($j);$j['state']='COMPLETE';$j['message']='Native handover ownership confirmed; current VM power state preserved';unmLiveStoreJob($j);return unmLiveStatus($j,false);
    }
    if($j['bootId']!==unmNativeBoot()||($j['destinationBootId']??'')!==unmNativeBoot()||$status['authority']!=='RECEIVING'||!in_array($status['vm']['state'],['running','idle','paused','shut off'],true))throw new RuntimeException('Native receiver boot or ownership changed; keep it fenced for review.');
    unmLiveNoJob($j['vmUuid'],$status['vm']);
    $record=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
    unmLiveNativeBinding(unmNativeDomain(unmDomainXml($j['vmUuid'],false)),$record);
    unmLiveNativeBinding(unmNativeDomain(unmDomainXml($j['vmUuid'],true)),$record);
    $q=$status['vm']['qemuAbsent']?null:unmNativeQemu($j['vmUuid'],$j['vmName']);
    if($q!==null){$a=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json');if(($a['jobId']??'')!==$j['id']||($a['bootId']??'')!==unmNativeBoot()||($a['phase']??'')!=='RUNNING'||($a['qemu']??null)!==$q)throw new RuntimeException('Native receiver is not this job\'s admitted QEMU.');}
    foreach(unmLiveDisks($j) as $disk)if(($disk['type']??'')==='zvol'&&unmLiveZvolInspect($disk['path'])!==($disk['volume']??null))throw new RuntimeException('Native destination volume identity changed.');
    $nonce=bin2hex(random_bytes(16));$proof=unmLiveRpc($peer,'source-proof',['jobId'=>$j['id'],'nonce'=>$nonce]);
    if(($proof['nonce']??'')!==$nonce||($proof['authority']??'')!=='RETIRED'||empty($proof['vm']['qemuAbsent'])||!unmLiveTrustedSuccessCheck($proof['nativeSuccess']??[],$j))throw new RuntimeException('No authenticated durable native success and fresh retired-source absence proof.');
    unmLiveDataRevoke($j);
    $j['state']='COMMITTING';$j['message']='Native handover complete; recording destination ownership';unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($j['id'])]);
    unmLiveAuthority($j,'OWNER',$j['diskPath'],$q);
    // Native libvirt performed the resume. Preserve any subsequent deliberate
    // guest shutdown or pause instead of booting/resuming it a second time.
    $j['state']='COMPLETE';$j['message']='Native live migration committed; current VM power state preserved';unmLiveStoreJob($j);return unmLiveStatus($j,false);
}
function unmLiveTrustedMigrationArgs(array $j): array {
    if(!unmLiveTrusted($j))throw new RuntimeException('Not a native handover job.');$id=unmLiveId($j['id']);$runtime=unmLiveRuntime($id);$uuid=unmTransportUuid($j['vmUuid']);
    return ['virsh','migrate',$uuid,'--live','--persistent','--copy-storage-all','--change-protection','--abort-on-error','--verbose','--desturi','qemu+unix:///system?socket='.$runtime.'/control.sock','--migrateuri','unix://'.$runtime.'/memory.sock','--disks-uri','unix://'.$runtime.'/disk.sock','--migrate-disks',implode(',',array_column(unmLiveDisks($j['spec']),'target')),'--migrate-disks-detect-zeroes',implode(',',array_column(unmLiveDisks($j['spec']),'target')),'--xml',$runtime.'/destination.xml','--persistent-xml',$runtime.'/persistent.xml'];
}
function unmLiveTrustedRequire(): void {
    if((unmLoadConfig()['trusted_native_live']??false)!==true)throw new RuntimeException('Enable native live migration trust in Settings on both paired hosts first. Native system-libvirt access is administrator-equivalent.');
}
function unmLiveTrustedKeyLine(array $j,array $r): string {
    $ip=$r['sourceAddress']??null;
    if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException('Native key has no authenticated source address.');
    $id=unmLiveId($j['id']);$key=unmManagedSshKeyId($r['publicKey']);$p=preg_split('/\s+/',unmValidatePublicKey($r['publicKey']),3);
    if(($r['keyId']??'')!==$key||!is_int($r['expiresAt']??null))throw new RuntimeException('Invalid native credential.');
    // OpenSSH permitopen applies to Unix sockets too, but its authorised-key
    // parser accepts only host:port values, not Unix paths or "none". Native
    // local forwarding is therefore an explicit administrator trust grant,
    // including TCP, not a per-path sandbox. Our client opens only job sockets.
    // Remote listeners are constrained to an intentionally invalid endpoint;
    // PTYs, agent/X11 forwarding and user rc remain disabled by restrict.
    return 'restrict,port-forwarding,permitlisten="unmotion.invalid:65535",from="'.$ip.'",expiry-time="'.gmdate('YmdHis',$r['expiresAt']).'Z",command="/usr/local/sbin/unmotion-live-native-gate '.$id.' '.$key.'" ssh-ed25519 '.$p[1].' unmotion-native:'.$id.':'.$key;
}
function unmLiveTrustedSshArgs(array $j,array $peer,string $token,bool $forward=true): array {
    unmAssertRemotePeerHost($peer['host']);
    if(!unmLiveTrusted($j)||$j['role']!=='source'||$j['destinationHostId']!==$peer['hostId']||($peer['username']??'root')!=='root')throw new RuntimeException('Native session is not this source job/peer.');
    unmLiveDataKeyCheck($j);
    $key=$j['runtime'].'/data-key';if(is_link($key)||!is_file($key))throw new RuntimeException('Native job key is missing.');
    $cipher=unmSshCipher(['ssh_cipher'=>$j['sshCipher']??'']);
    $a=['/usr/bin/ssh','-F','/dev/null','-T','-c',$cipher,'-p',(string)$peer['port'],'-i',$key,'-o','ControlMaster=no','-o','ControlPath=none','-o','BatchMode=yes','-o','Compression=no','-o','IdentitiesOnly=yes','-o','IdentityAgent=none','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=3','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='.$peer['knownHosts'],'-o','GlobalKnownHostsFile=/dev/null','-o','UpdateHostKeys=no'];
    if($forward){$a=array_merge($a,['-o','ExitOnForwardFailure=yes','-o','StreamLocalBindMask=0177','-o','StreamLocalBindUnlink=no']);foreach(['control'=>'/var/run/libvirt/libvirt-sock','memory'=>$j['runtime'].'/memory.sock','disk'=>$j['runtime'].'/disk.sock'] as $name=>$target)$a=array_merge($a,['-L',$j['runtime'].'/'.$name.'.sock:'.$target]);}
    else $a=array_merge($a,['-o','ClearAllForwardings=yes']);
    return array_merge($a,['root@'.$peer['host'],unmTransportEnvelope($forward?'live-native':'live-benchmark',['jobId'=>$j['id'],'token'=>$token])]);
}
function unmLiveTrustedTunnelStart(array $j,array $peer,string $token): array {
    $args=unmLiveTrustedSshArgs($j,$peer,$token);$paths=[];
    foreach(['control','memory','disk'] as $name){$p=$j['runtime'].'/'.$name.'.sock';if(file_exists($p)||is_link($p))throw new RuntimeException('Native socket already exists; reconcile stale sessions first.');$paths[]=$p;}
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(unmLiveJobPath($j['id'])).'/native-ssh.log','a']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Native SSH could not start.');$child=['process'=>$process,'identity'=>null,'pipes'=>$pipes,'sockets'=>[]];
    try{
        $child['identity']=unmLiveChildIdentity($process,$args);unmAtomicJson($j['runtime'].'/native-ssh-process.json',['jobId'=>$j['id'],'process'=>$child['identity']]);
        stream_set_blocking($pipes[1],false);$ready='';$end=microtime(true)+15;
        while(true){$part=stream_get_contents($pipes[1]);if($part===false)throw new RuntimeException('Native readiness failed.');$ready.=$part;if(strlen($ready)>128)throw new RuntimeException('Unexpected native readiness output.');if($ready==="unmotion-native-ready\n")break;if(microtime(true)>$end||!proc_get_status($process)['running'])throw new RuntimeException('Native SSH did not acknowledge its job lease.');usleep(10000);}
        foreach($paths as $path){clearstatcache(true,$path);$s=@lstat($path);if(!$s||is_link($path)||filetype($path)!=='socket'||($s['mode']&0777)!==0600)throw new RuntimeException('Native forwarding socket is not private.');$child['sockets'][$path]=[$s['dev'],$s['ino']];}
        return $child;
    }catch(Throwable $e){unmLiveTrustedTunnelClose($child);throw $e;}
}
function unmLiveTrustedTunnelClose(?array &$child): void {
    if($child===null)return;
    foreach($child['pipes'] as $p)if(is_resource($p))fclose($p);
    if($child['identity'])unmProcessStop($child['identity'],1000);
    proc_close($child['process']);
    foreach($child['sockets'] as $path=>$id){clearstatcache(true,$path);$s=@lstat($path);if($s&&!is_link($path)&&filetype($path)==='socket'&&[$s['dev'],$s['ino']]===$id)unlink($path);}
    $child=null;
}
function unmLiveTrustedStopOrphan(array $j): void {
    $path=$j['runtime'].'/native-ssh-process.json';if(!is_file($path))return;if(is_link($path))throw new RuntimeException('Redirected native session record.');$r=unmNativeJson($path);$p=$r['process']??null;
    if(($r['jobId']??'')!==$j['id']||!is_array($p)||($p['executable']??'')!=='/usr/bin/ssh'||!in_array($j['runtime'].'/data-key',$p['argv']??[],true)||!in_array('-L',$p['argv']??[],true))throw new RuntimeException('Native session record is not bound to its job.');
    if(unmProcessIdentityMatches($p)&&!unmProcessStop($p,1000))throw new RuntimeException('Native session has not stopped.');
}
/** Local, post-resume evidence. A running/paused VM alone proves nothing. */
function unmLiveTrustedReceiveComplete(array $j): bool {
    if(!unmLiveTrusted($j)||$j['role']!=='destination'||$j['bootId']!==unmNativeBoot())return false;
    try{
        $r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);if(($r['jobId']??'')!==$j['id']||!in_array($r['state'],['RECEIVING','OWNER'],true))return false;
        $q=unmNativeQemu($j['vmUuid'],$j['vmName']);$a=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json');
        if(($a['jobId']??'')!==$j['id']||($a['bootId']??'')!==unmNativeBoot()||($a['phase']??'')!=='RUNNING'||($a['qemu']??null)!==$q)return false;
        unmLiveNativeBinding(unmNativeDomain(unmDomainXml($j['vmUuid'],false)),$r);
        $migration=json_decode(unmLiveCommand(['virsh','qemu-monitor-command',$j['vmUuid'],'{"execute":"query-migrate"}']),true,32,JSON_THROW_ON_ERROR);
        if(($migration['return']['status']??'')!=='completed')return false;
        $state=unmLiveVmState($j['vmUuid']);if(!unmLiveTrustedCompletionEvidence($j,$r,$a,$q,unmNativeBoot(),$migration['return']['status'],$state['state']))return false;unmLiveNoJob($j['vmUuid'],$state);
        $receipt=['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'bootId'=>unmNativeBoot(),'contract'=>unmLiveCompletionContract($j),'qemu'=>$q,'nativeHandoverVersion'=>1,'observedAt'=>gmdate('c')];
        $path=dirname(unmLiveJobPath($j['id'])).'/receive-complete.json';unmNativeWrite($path,$receipt);unmLiveCommand(['sync','-f',$path]);return true;
    }catch(Throwable){return false;}
}
