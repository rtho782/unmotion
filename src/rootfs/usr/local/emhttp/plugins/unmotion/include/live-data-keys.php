<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmLiveDataKeyPath(array $j): string {return unmLiveRuntime($j['id']).'/data-access.json';}
function unmLiveDataBootSeconds(): int {$text=(string)file_get_contents('/proc/uptime');if(!preg_match('/\A([0-9]+)\.[0-9]+ /D',$text,$m))throw new RuntimeException('Monotonic host uptime is unavailable.');return (int)$m[1];}
function unmLiveDataKeyRecord(array $j,string $publicKey): array {
    $publicKey=unmValidatePublicKey($publicKey);$key=unmManagedSshKeyId($publicKey);
    foreach(unmPeers() as $p)if(isset($p['incomingPublicKey'])&&unmManagedSshKeyId($p['incomingPublicKey'])===$key)throw new RuntimeException('A permanent pairing key cannot be used as a disposable key.');
    return ['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'role'=>$j['role'],'sourceHostId'=>$j['sourceHostId'],'destinationHostId'=>$j['destinationHostId'],'bootId'=>unmNativeBoot(),'keyId'=>$key,'publicKey'=>$publicKey,'state'=>'ACTIVE','expiresAt'=>min($j['expiresAt'],time()+UNM_LIVE_TRANSFER_SECONDS+UNM_LIVE_TRANSPORT_GRACE_SECONDS),'expiresBoot'=>unmLiveDataBootSeconds()+UNM_LIVE_TRANSFER_SECONDS+UNM_LIVE_TRANSPORT_GRACE_SECONDS];
}
function unmLiveDataKeyCheck(array $j,?string $key=null): array {
    $path=unmLiveDataKeyPath($j);if(is_link($path)||!is_file($path))throw new RuntimeException('Disposable migration key is unavailable.');$r=unmNativeJson($path);
    foreach(['jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'role'=>$j['role'],'sourceHostId'=>$j['sourceHostId'],'destinationHostId'=>$j['destinationHostId'],'bootId'=>unmNativeBoot(),'state'=>'ACTIVE','schemaVersion'=>1] as $k=>$v)if(($r[$k]??null)!==$v)throw new RuntimeException('Disposable migration key identity is invalid.');
    if(!is_int($r['expiresAt']??null)||$r['expiresAt']<=time()||$r['expiresAt']>$j['expiresAt']||!is_int($r['expiresBoot']??null)||$r['expiresBoot']<=unmLiveDataBootSeconds()||unmManagedSshKeyId((string)($r['publicKey']??''))!==($r['keyId']??'')||($key!==null&&!hash_equals($r['keyId'],$key)))throw new RuntimeException('Disposable migration key expired or changed.');return $r;
}
function unmLiveDataKeyLine(array $j,array $r): string {
    if(unmLiveTrusted($j))return unmLiveTrustedKeyLine($j,$r);
    $id=unmLiveId($j['id']);$key=unmManagedSshKeyId($r['publicKey']);$parts=preg_split('/\s+/',unmValidatePublicKey($r['publicKey']),3);
    if(($r['keyId']??'')!==$key||!is_int($r['expiresAt']??null))throw new RuntimeException('Invalid disposable key line.');
    return 'restrict,expiry-time="'.gmdate('YmdHis',$r['expiresAt']).'Z",command="/usr/local/sbin/unmotion-live-key-gate '.$id.' '.$key.'" ssh-ed25519 '.$parts[1].' unmotion-live:'.$id.':'.$key;
}
function unmLiveDataKeyRewrite(string $contents,array $j,array $r,bool $add): string {
    $line=unmLiveDataKeyLine($j,$r);$blob=unmAuthorizedKeyBlob($line);$lines=preg_split('/(?<=\n)/',$contents,-1,PREG_SPLIT_NO_EMPTY);$out='';$tag=unmLiveTrusted($j)?'unmotion-native':'unmotion-live';
    foreach($lines as $raw){$old=rtrim($raw,"\r\n");if(unmAuthorizedKeyBlob($old)===$blob){if(!str_contains($old,' '.$tag.':'.$j['id'].':'.$r['keyId']))throw new RuntimeException('Disposable key collides with another SSH credential.');continue;}$out.=$raw;}
    if($add)$out.=($out!==''&&!str_ends_with($out,"\n")?"\n":'').$line."\n";return $out;
}
function unmLiveDataKeyAuthorize(array $peer,array $a): array {
    $action=$a['action']??'';$expected=$action==='data-authorize'?['action','jobId','token','publicKey']:['action','jobId','token'];
    if(array_diff(array_keys($a),$expected)||array_diff($expected,array_keys($a)))throw new RuntimeException('Invalid disposable key operation.');
    $j=unmLivePeerJob($peer,(string)$a['jobId'],(string)$a['token']);if($j['role']!=='destination')throw new RuntimeException('Wrong disposable key direction.');
    if($action==='data-revoke'){unmLiveDataRevoke($j);return ['success'=>true];}
    if($action!=='data-authorize'||!unmLiveAlive($j))throw new RuntimeException('Live data authority is inactive.');
    $path=unmLiveDataKeyPath($j);if(file_exists($path)||is_link($path))throw new RuntimeException('This migration already has a data credential; reconcile rather than replace it.');
    $r=unmLiveDataKeyRecord($j,(string)$a['publicKey']);
    if(unmLiveTrusted($j)){unmLiveTrustedRequire();$connection=preg_split('/\s+/',trim((string)getenv('SSH_CONNECTION')));$ip=$connection[0]??'';if(!filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException('Native authorisation requires the authenticated SSH source address.');$r['sourceAddress']=$ip;}
    unmAtomicJson($path,$r); // Durable intent before accepting a key; partial setup fails closed.
    unmEditAuthorizedKeys(static fn($old)=>unmLiveDataKeyRewrite($old,$j,$r,true));return ['success'=>true,'keyId'=>$r['keyId'],'expiresAt'=>$r['expiresAt']];
}
function unmLiveDataPrepare(array $j,array $peer,string $token): void {
    $path=$j['runtime'].'/data-key';if(file_exists($path)||is_link($path)||file_exists(unmLiveDataKeyPath($j)))throw new RuntimeException('Disposable key already exists.');
    unmLiveCommand(['ssh-keygen','-q','-t','ed25519','-N','','-C','unmotion-live:'.$j['id'],'-f',$path]);chmod($path,0600);$pub=trim((string)file_get_contents($path.'.pub'));$r=unmLiveDataKeyRecord($j,$pub);unmAtomicJson(unmLiveDataKeyPath($j),$r);
    $reply=unmLiveRpc($peer,'data-authorize',['jobId'=>$j['id'],'token'=>$token,'publicKey'=>$pub]);if(($reply['keyId']??'')!==$r['keyId'])throw new RuntimeException('Destination accepted a different disposable key.');
}
function unmLiveDataSshAncestor(array $gate): ?array {
    if(!unmProcessIdentityMatches($gate))return null;$parent=$gate['parentPid'];
    for($i=0;$i<8&&$parent>1;$i++){$p=unmProcessSnapshot($parent);if(!$p)return null;if(basename($p['executable'])==='sshd-session')return $p;if(!in_array(basename($p['executable']),['bash','sh'],true))return null;$parent=$p['parentPid'];}return null;
}
function unmLiveDataProcessMatches(array $p,array $j,string $key): bool {
    $argv=$p['argv']??[];
    if($j['role']==='destination'&&unmProcessCommandMatches($p,'/usr/local/sbin/unmotion-live-key-gate',[$j['id'],$key]))return count($argv)===($argv[0]==='/usr/local/sbin/unmotion-live-key-gate'?3:4);
    if($j['role']==='destination'&&unmLiveTrusted($j)&&unmProcessCommandMatches($p,'/usr/local/sbin/unmotion-live-native-gate',[$j['id'],$key]))return count($argv)===($argv[0]==='/usr/local/sbin/unmotion-live-native-gate'?3:4);
    foreach(['duplex','benchmark'] as $mode)if(unmProcessCommandMatches($p,'/usr/local/sbin/unmotion-live-stream',[$mode,$j['id'],$j['role'],$key]))return count($argv)===5;return false;
}
function unmLiveDataQuiesce(array $j,string $key): void {
    $end=microtime(true)+3;
    do{$found=false;foreach(glob('/proc/[0-9]*/stat')?:[] as $path){$p=unmProcessSnapshot((int)basename(dirname($path)));if(!$p||$p['pid']===getmypid()||!unmLiveDataProcessMatches($p,$j,$key))continue;$found=true;unmProcessStop($p,500);}if(!$found)return;usleep(10000);}while(microtime(true)<$end);
    throw new RuntimeException('Exact migration data processes have not quiesced; keep execution fenced and retry.');
}
/** Boot/daemon housekeeping is local only. Canonical plugin-owned disposable
 * lines without a live, current-boot credential are removed; user keys remain.
 */
function unmLiveDataSweep(): void {
    unmEditAuthorizedKeys(static function(string $old): string {
        $lines=preg_split('/(?<=\n)/',$old,-1,PREG_SPLIT_NO_EMPTY);$out='';
        foreach($lines as $raw){$line=rtrim($raw,"\r\n");$pattern='~\Arestrict,expiry-time="[0-9]{14}Z",command="/usr/local/sbin/unmotion-live-key-gate (live-[a-f0-9]{24}) ([a-f0-9]{64})" ssh-ed25519 ([A-Za-z0-9+/=]+) unmotion-live:\\1:\\2\z~D';
            $nativePattern='~\Arestrict,port-forwarding,(?:permitopen="(?:none|unmotion\.invalid:65535)",)?permitlisten="(?:none|unmotion\.invalid:65535)",from="([0-9a-fA-F:.]+)",expiry-time="[0-9]{14}Z",command="/usr/local/sbin/unmotion-live-native-gate (live-[a-f0-9]{24}) ([a-f0-9]{64})" ssh-ed25519 ([A-Za-z0-9+/=]+) unmotion-native:\\2:\\3\z~D';
            if(preg_match($nativePattern,$line,$n)){
                if(!filter_var($n[1],FILTER_VALIDATE_IP)||unmManagedSshKeyId('ssh-ed25519 '.$n[4])!==$n[3])throw new RuntimeException('Corrupt native credential.');
                try{$j=unmLiveJob($n[2]);$r=unmLiveDataKeyCheck($j,$n[3]);if(unmLiveTrusted($j)&&$j['role']==='destination'&&unmLiveAlive($j)&&unmLiveDataKeyLine($j,$r)===$line)$out.=$raw;}catch(Throwable){}continue;
            }
            if(!preg_match($pattern,$line,$m)){$out.=$raw;continue;}
            if(unmManagedSshKeyId('ssh-ed25519 '.$m[3])!==$m[2])throw new RuntimeException('Corrupt disposable key fingerprint.');
            try{$j=unmLiveJob($m[1]);$r=unmLiveDataKeyCheck($j,$m[2]);if($j['role']==='destination'&&unmLiveAlive($j)&&unmLiveDataKeyLine($j,$r)===$line)$out.=$raw;}catch(Throwable){/* expired/rebooted/missing job cannot accept this key */}
        }return $out;
    });
    foreach(glob('/run/unmotion/live/live-*/data-access.json')?:[] as $p){try{$r=unmNativeJson($p);if(($r['schemaVersion']??null)===1&&($r['jobId']??'')===basename(dirname($p))&&($r['state']??'')==='REVOKED'&&($r['quiesced']??false)===true)continue;$j=unmLiveJob(basename(dirname($p)));try{unmLiveDataKeyCheck($j);$active=unmLiveAlive($j);}catch(Throwable){$active=false;}if(!$active)unmLiveDataRevoke($j);}catch(Throwable){/* native helper independently rejects corrupt/expired authority */}}
}
/** Revoke new logins first; current connections are separately stopped by exact
 * native/helper/sshd-session lifetime identities, never a broad SSH PID sweep.
 */
function unmLiveDataRevoke(array $j): void {
    $path=unmLiveDataKeyPath($j);if(!file_exists($path)&&!is_link($path))return;if(is_link($path))throw new RuntimeException('Redirected disposable key record.');$r=unmNativeJson($path);
    foreach(['jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'role'=>$j['role']] as $k=>$v)if(($r[$k]??null)!==$v)throw new RuntimeException('Foreign disposable key record.');
    if(!is_string($r['keyId']??null)||unmManagedSshKeyId((string)($r['publicKey']??''))!==$r['keyId'])throw new RuntimeException('Corrupt disposable key identity.');
    if(($r['state']??'')==='REVOKED'&&($r['quiesced']??false)===true)return;
    $r['state']='REVOKED';unmAtomicJson($path,$r);
    if($j['role']==='source'&&unmLiveTrusted($j))unmLiveTrustedStopOrphan($j);
    if($j['role']==='destination')unmEditAuthorizedKeys(static fn($old)=>unmLiveDataKeyRewrite($old,$j,$r,false));
    foreach(glob($j['runtime'].'/data-session-*.json')?:[] as $p){if(is_link($p))throw new RuntimeException('Redirected data session.');$raw=@file_get_contents($p);if($raw===false){clearstatcache(true,$p);if(!file_exists($p))continue;throw new RuntimeException('Unreadable data session.');}$s=json_decode($raw,true);if(!is_array($s)||($s['jobId']??'')!==$j['id']||($s['keyId']??'')!==$r['keyId'])throw new RuntimeException('Foreign/corrupt data session.');
        $gate=$s['gate']??null;$ssh=$s['ssh']??null;
        $gateName=unmLiveTrusted($j)?'/usr/local/sbin/unmotion-live-native-gate':'/usr/local/sbin/unmotion-live-key-gate';
        if(!is_array($gate)||!unmProcessCommandMatches($gate,$gateName,[$j['id'],$r['keyId']]))throw new RuntimeException('Unbound SSH gate record.');
        // Re-prove CURRENT ancestry as well as the saved process lifetime.
        // An exited gate's old parent/PID record cannot authorize any signal.
        if(unmProcessIdentityMatches($gate)&&$gate['pid']!==getmypid()){$current=unmLiveDataSshAncestor($gate);if($current!==null&&$current===$ssh&&unmProcessIdentityMatches($current))unmProcessStop($current,1000);else unmProcessStop($gate,1000);}
    }
    unmLiveDataQuiesce($j,$r['keyId']);
    if($j['role']==='source')foreach([$j['runtime'].'/data-key',$j['runtime'].'/data-key.pub'] as $p)if(is_file($p)&&!is_link($p)&&!@unlink($p))throw new RuntimeException('Private disposable key cleanup is incomplete.');
    $r['quiesced']=true;unmAtomicJson($path,$r);
}
