<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-firmware.php';
require_once __DIR__.'/live-storage.php';
require_once __DIR__.'/live-zvol-native.php';
require_once __DIR__.'/live-tpm.php';
function unmLiveNativeHelperHash(): string {return hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/live-firmware.php').hash_file('sha256',__DIR__.'/live-storage.php').hash_file('sha256',__DIR__.'/live-zvol-native.php').hash_file('sha256',__DIR__.'/live-tpm.php').hash_file('sha256',__DIR__.'/xml-canonical.php'));}
// Standalone synchronous hook helper. Never invoke libvirt, SSH or a peer here.
function unmLiveNativeRecord(string $base,string $uuid): ?array {
    $path=$base.'/live-authority/'.$uuid.'.json';$marker=$base.'/live-managed/'.$uuid.'.json';
    if(!file_exists($path)&&!file_exists($marker))return null;
    if(is_link($path)||is_link($marker)||!is_file($path)||!is_file($marker))throw new RuntimeException('Live migration authority is missing; VM start remains fenced.');
    $r=unmNativeJson($path);$m=unmNativeJson($marker);
    if(isset($r['workflow'])&&!in_array($r['workflow'],['ordinary','ordinary-pending'],true))throw new RuntimeException('Invalid live workflow authority.');
    if(($r['workflow']??'')==='ordinary-pending'&&(($r['state']??'')!=='OWNER'||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($r['handbackNonce']??''))))throw new RuntimeException('Invalid pending hand-back authority.');
    if(($r['schemaVersion']??null)!==1||($m['schemaVersion']??null)!==1||($r['vmUuid']??'')!==$uuid||($m['vmUuid']??'')!==$uuid||!preg_match('/\Alive-[a-f0-9]{24}\z/D',(string)($r['jobId']??''))||($r['jobId']??'')!==($m['jobId']??'')||!in_array($r['state']??'',['OWNER','MOVING','RECEIVING','RETIRED','ABORTED'],true)||!in_array($r['role']??'',['source','destination'],true)||!is_string($r['diskPath']??null))throw new RuntimeException('Invalid live migration authority; VM start remains fenced.');
    $pattern='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D';$local=trim((string)@file_get_contents($base.'/host_id'));
    if(!preg_match($pattern,(string)($r['sourceHostId']??''))||!preg_match($pattern,(string)($r['destinationHostId']??''))||$r['sourceHostId']===$r['destinationHostId']||($r[$r['role'].'HostId']??'')!==$local||!preg_match($pattern,(string)($r['bootId']??''))||!is_int($r['expiresAt']??null)||!in_array($r['state'],$r['role']==='source'?['OWNER','MOVING','RETIRED']:['OWNER','RECEIVING','ABORTED'],true))throw new RuntimeException('Live authority belongs to another host or role.');
    return $r;
}
function unmLiveNativeBinding(array $d,array $r): void {
    $xp=$d['xpath'];
    if(($r['workflow']??'')==='ordinary'){
        if($d['uuid']!==$r['vmUuid'])throw new RuntimeException('Ordinary ownership UUID changed.');
        return; // Non-owner states still reject every start, regardless of hardware or a renamed retained copy.
    }
    if($d['name']!==($r['vmName']??'')||$d['uuid']!==$r['vmUuid']||$xp->query('/domain/devices/hostdev|/domain/devices/filesystem')->length)throw new RuntimeException('Live-managed VM hardware binding changed.');
    $firmware=unmLiveFirmwareXml($xp,$d['uuid']);
    if(($r['firmware']??null)!==$firmware)throw new RuntimeException('Live-managed VM firmware binding changed.');
    if(($r['tpm']??null)!==unmLiveTpmXml($xp,$d['uuid']))throw new RuntimeException('Live-managed VM TPM binding changed.');
    if(isset($r['disks'])){
        $actual=unmLiveDisksXml($xp);$expected=array_map('unmLiveDiskIdentity',unmLiveDisks($r));
        if($actual!==$expected)throw new RuntimeException('Live-managed VM disk bindings changed.');
    }else{
        // Released one-disk authorities did not record format/target fields.
        $disk=$xp->query('/domain/devices/disk')->item(0);
        if($xp->query('/domain/devices/disk')->length!==1||!$disk||$disk->getAttribute('type')!=='file'||$disk->getAttribute('device')!=='disk'||$xp->evaluate('string(source/@file)',$disk)!==$r['diskPath'])throw new RuntimeException('Live-managed VM disk binding changed.');
    }
}
function unmLiveNativeDecision(array $r,array $d,string $op,string $phase,?array $a,string $boot,int $now,?array $qemu=null): ?array {
    unmLiveNativeBinding($d,$r);
    if($op==='release')return null;
    if(($r['workflow']??'')==='ordinary-pending')throw new RuntimeException('Reviewed hand-back is pending; native starts remain fenced.');
    if(($r['workflow']??'')==='ordinary'&&$op==='migrate')throw new RuntimeException('Ordinary receivers cannot consume a live incoming permit.');
    if($op==='reconnect'){
        if(!$a||!$qemu||($a['qemu']??null)!==$qemu||($a['bootId']??'')!==$boot||($a['jobId']??'')!==$r['jobId'])throw new RuntimeException('Cannot prove the exact admitted live QEMU during reconnect.');
        return $a;
    }
    if(in_array($op,['restore','attach'],true))throw new RuntimeException('Restore/attach is not supported for live-managed VMs in this beta.');
    if($phase!=='begin')return $a;
    if($op==='migrate'){
        if($r['state']!=='RECEIVING'||$r['role']!=='destination'||($r['bootId']??'')!==$boot||(int)($r['expiresAt']??0)<=$now||$a!==null||$qemu!==null)throw new RuntimeException('No fresh single-use incoming live migration authority.');
        return ['jobId'=>$r['jobId'],'bootId'=>$boot,'phase'=>'INCOMING','expiresAt'=>$now+30];
    }
    if($op==='prepare'){
        if($r['state']==='OWNER')return ['jobId'=>$r['jobId'],'bootId'=>$boot,'phase'=>'PREPARED','expiresAt'=>$now+30];
        if($r['state']!=='RECEIVING'||($a['phase']??'')!=='INCOMING'||($a['jobId']??'')!==$r['jobId']||($a['bootId']??'')!==$boot||(int)($a['expiresAt']??0)<=$now)throw new RuntimeException('This VM is fenced by an unresolved or retired live migration.');
        $a['phase']='PREPARED';return $a;
    }
    if($op==='start'){
        if(!in_array($r['state'],['OWNER','RECEIVING'],true)||($a['phase']??'')!=='PREPARED'||($a['jobId']??'')!==$r['jobId']||($a['bootId']??'')!==$boot||(int)($a['expiresAt']??0)<=$now)throw new RuntimeException('Live start admission is stale, missing or already consumed.');
        $a['phase']='STARTING';return $a;
    }
    if($op==='started'){
        if(($a['phase']??'')!=='STARTING'||($a['jobId']??'')!==$r['jobId']||($a['bootId']??'')!==$boot||!$qemu)throw new RuntimeException('Started live QEMU has no matching admission.');
        $a['phase']='RUNNING';$a['qemu']=$qemu;return $a;
    }
    return $a;
}
function unmLiveNativeFence(array $d,string $op,string $phase,string $base,string $run,string $locks,string $boot): void {
    $r=unmLiveNativeRecord($base,$d['uuid']);if($r===null)return;
    $lock=unmNativeLock('live-'.$d['uuid'],$locks);
    try{
        $r=unmLiveNativeRecord($base,$d['uuid']);if(!$r)throw new RuntimeException('Live authority changed during admission.');
        $path=$run.'/live-admissions/'.$d['uuid'].'.json';$a=is_file($path)?unmNativeJson($path):null;$qemu=null;
        if(in_array($op,['started','reconnect'],true))$qemu=unmNativeQemu($d['uuid'],$d['name']);
        if($op!=='release'&&is_file(dirname($run).'/shutdown-fence'))throw new RuntimeException('The host shutdown fence is active.');
        if($op!=='release'&&isset($r['disks']))unmLiveZvolNativeCheck($r,$boot,'/run/unmotion/live-zvol/'.$d['uuid'].'.json');
        $next=unmLiveNativeDecision($r,$d,$op,$phase,$a,$boot,time(),$qemu);
        if($next===null){if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot clear live admission.');}
        else unmNativeWrite($path,$next);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function unmLiveNativeAssertRemovable(string $base): void {
    $paths=array_unique(array_merge(glob($base.'/live-managed/*.json')?:[],glob($base.'/live-authority/*.json')?:[]));
    foreach($paths as $path){
        $uuid=basename($path,'.json');if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid))throw new RuntimeException('Invalid live fencing identity prevents removal.');$r=unmLiveNativeRecord($base,$uuid);
        if(!$r||in_array($r['state'],['MOVING','RECEIVING'],true)||($r['workflow']??'')==='ordinary-pending')throw new RuntimeException('Reconcile unfinished live migrations/hand-back before removing unMotion.');
        foreach(glob('/proc/[0-9]*/cmdline')?:[] as $cmd){$exe=(string)@readlink(dirname($cmd).'/exe');if(!str_starts_with(basename($exe),'qemu-system-'))continue;$a=explode("\0",rtrim((string)@file_get_contents($cmd),"\0"));$i=array_search('-uuid',$a,true);if($i!==false&&strtolower((string)($a[$i+1]??''))===$uuid)throw new RuntimeException('Power off live-managed VMs before removing unMotion.');}
        if($r['state']!=='OWNER')foreach(glob('/etc/libvirt/qemu/*.xml')?:[] as $xml){$text=(string)file_get_contents($xml);if(preg_match('~<uuid>\s*'.preg_quote($uuid,'~').'\s*</uuid>~i',$text))throw new RuntimeException('Remove the retired/aborted source definition before removing its start fence. Its disk may be retained.');}
    }
}
