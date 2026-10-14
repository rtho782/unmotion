<?php
declare(strict_types=1);
namespace PairingTrustFixture;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

$include=__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/';
$lib=file_get_contents($include.'lib.php');
function loadFunctions(string $source,string $first,?string $next=null): void {
    $start=strpos($source,'function '.$first.'(');$end=$next===null?strlen($source):strpos($source,'function '.$next.'(',$start);
    if($start===false||$end===false)throw new RuntimeException('Fixture function boundaries changed.');
    eval('namespace PairingTrustFixture; use RuntimeException; use InvalidArgumentException; use Throwable;'.substr($source,$start,$end-$start));
}
loadFunctions($lib,'unmValidatePublicKey','unmAppendAuthorizedKey');
loadFunctions(file_get_contents($include.'ssh-keys.php'),'unmAssertPairIdentityAvailable');
loadFunctions(file_get_contents($include.'pairing-trust.php'),'unmSshHostPublicKey','unmLocalSshHostPublicKey');
loadFunctions($lib,'unmCreatePeerMaterial','unmSavePeerRecord');
loadFunctions($lib,'unmPrepareReciprocalPeer','unmFinalizeReciprocalPeer');
loadFunctions($lib,'unmPair','unmRemovePeer');
$root=sys_get_temp_dir().'/unmotion-pairing-'.bin2hex(random_bytes(6));mkdir($root,0700);define(__NAMESPACE__.'\UNM_PEERS_DIR',$root);define(__NAMESPACE__.'\UNM_PROTOCOL',7);define(__NAMESPACE__.'\UNM_VERSION','0.4.7');
function key(string $byte): string {return 'ssh-ed25519 '.base64_encode(pack('N',11).'ssh-ed25519'.pack('N',32).str_repeat($byte,32));}
$hostKey=key('h');$localKey=key('l');$clientKey=key('c');$otherKey=key('x');$scan='peer '.$hostKey."\n";$auth=0;$remoteCalls=[];$saved=[];$created=0;$oldPeer=false;$adds=[];$forgot=[];$failKnownWrite=false;
function check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function reject(callable $fn,string $why): void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted: '.$why);}
function unmEnsureDirs(): void {}
function unmAssertRemotePeerHost(string $host): void {if($host==='localhost')throw new InvalidArgumentException('Local peer');}
function unmPeerIdForAddress(string $host,int $port): string {return substr(hash('sha256',$host.':'.$port),0,24);}
function unmPeerPath(string $id): string {return UNM_PEERS_DIR.'/'.$id.'.json';}
function unmPeers(): array {return [];}
function unmHostId(): string {return 'local-host';}
function unmSshStatus(): array {return ['enabled'=>true,'running'=>true,'listening'=>true,'port'=>2222];}
function unmLocalSshHostPublicKey(): string {return $GLOBALS['localKey'];}
function unmLocalAddressForPeer(string $host): string {return '192.0.2.1';}
function unmCapabilities(): array {return ['protocolVersion'=>7,'features'=>['restrictedSshTransport'=>true,'verifiedHostKeyPairing'=>true]];}
function unmAppendAuthorizedKey(string $key): void {$GLOBALS['adds'][]=$key;}
function unmRemoveAuthorizedKey(string $key): void {}
function unmSavePeerRecord(array $peer): void {$GLOBALS['saved'][]=$peer;}
function unmForgetPeerLocal(string $id): void {$GLOBALS['forgot'][]=$id;}
function tempnam(string $dir,string $prefix): string|false {return \tempnam(sys_get_temp_dir(),$prefix);}
function file_put_contents(string $path,string $contents,int $flags=0): int|false {if($GLOBALS['failKnownWrite']&&str_ends_with($path,'/known_hosts'))return false;return \file_put_contents($path,$contents,$flags);}
function unmRun(array|string $cmd,?string $stdin=null,int $timeout=60): array {
    if(is_array($cmd)&&$cmd[0]==='ssh-keyscan'){
        check(in_array('ed25519',$cmd,true),'Scan only Ed25519 keys');$out=$GLOBALS['scan'];
    }elseif(is_array($cmd)&&$cmd[0]==='ssh-keygen'){
        $key=end($cmd);file_put_contents($key,'private test fixture');file_put_contents($key.'.pub',$GLOBALS['clientKey']);$out='';$GLOBALS['created']++;
    }elseif(is_string($cmd)&&str_starts_with($cmd,'setsid ')){
        check(str_contains($cmd,'StrictHostKeyChecking=yes')&&str_contains($cmd,'GlobalKnownHostsFile=/dev/null')&&str_contains($cmd,'HostKeyAlgorithms=ssh-ed25519'),'Bootstrap strictly authenticates the pinned host key');$GLOBALS['auth']++;$out='{}';
    }else throw new RuntimeException('Unexpected command');
    return ['code'=>0,'stdout'=>$out,'stderr'=>''];
}
function unmRemote(array $peer,string $cmd,int $timeout): array {
    $GLOBALS['remoteCalls'][]=$cmd;$out='{}';
    if(str_ends_with($cmd,' capabilities'))$out=json_encode(['protocolVersion'=>7,'hostId'=>'remote-host','hostname'=>'DEV02','features'=>['verifiedHostKeyPairing'=>!$GLOBALS['oldPeer']]]);
    if(str_contains($cmd,' reciprocal-prepare ')){
        $encoded=trim(substr($cmd,strpos($cmd,' reciprocal-prepare ')+strlen(' reciprocal-prepare ')),"\"'");$request=json_decode(base64_decode($encoded),true);
        check($request['sshHostKey']===$GLOBALS['localKey'],'Return host key travels over authenticated channel');
        $out=json_encode(['peerId'=>'return-peer','publicKey'=>$GLOBALS['otherKey']]);
    }
    return ['code'=>0,'stdout'=>$out,'stderr'=>''];
}
function removeFixture(string $path): void {foreach(scandir($path) as $name){if($name==='.'||$name==='..')continue;$p=$path.'/'.$name;if(is_dir($p)&&!is_link($p))removeFixture($p);else unlink($p);}rmdir($path);}
try {
    check(unmSshHostFingerprint($hostKey)==='SHA256:'.rtrim(base64_encode(hash('sha256',base64_decode(explode(' ',$hostKey)[1]),true)),'='),'OpenSSH SHA256 fingerprint');
    check(unmScannedSshHostKey($scan.$scan)===$hostKey,'Identical duplicate scan keys accepted');
    reject(fn()=>unmScannedSshHostKey($scan.'peer '.$otherKey), 'ambiguous host keys');
    reject(fn()=>unmScannedSshHostKey('peer ssh-rsa AAAA'), 'wrong algorithm');
    $preview=unmPairingHostKey(['host'=>'192.0.2.2','port'=>'2222']);
    check($preview===['host'=>'192.0.2.2','port'=>2222,'algorithm'=>'Ed25519','fingerprint'=>unmSshHostFingerprint($hostKey)],'Preview binds the displayed fingerprint to the selected endpoint');
    check($auth===0&&$created===0&&!$saved&&!$adds,'Preview sends no password and creates no pairing or client keys');
    reject(fn()=>unmPairingHostKey(['host'=>'localhost']),'local-host preview');
    reject(fn()=>unmPairingHostKey(['host'=>'192.0.2.2;id']),'untrusted hostname');
    foreach(['0','65536','22invalid','-1','2.2'] as $port)reject(fn()=>unmPairingHostKey(['host'=>'192.0.2.2','port'=>$port]),'invalid preview port');
    $scan='peer '.$otherKey."\n";
    reject(fn()=>unmPair(['host'=>$preview['host'],'port'=>$preview['port'],'password'=>'fixture-password','host_fingerprint'=>$preview['fingerprint']]),'key changes after acceptance');
    check($auth===0&&$created===0,'Changed preview key fails before password authentication and key creation');$scan='peer '.$hostKey."\n";
    $input=['host'=>'192.0.2.2','port'=>2222,'password'=>'fixture-password','host_fingerprint'=>unmSshHostFingerprint($otherKey)];
    reject(fn()=>unmPair($input),'MITM host key');check($auth===0&&$created===0,'Mismatch fails before password authentication or peer key creation');
    reject(fn()=>unmPair(array_replace($input,['host_fingerprint'=>''])),'missing accepted fingerprint');check($auth===0,'Missing fingerprint never sends password');
    $input['host_fingerprint']=unmSshHostFingerprint($hostKey);$peer=unmPair($input);
    check($auth===1&&$peer['pairingState']==='paired','Verified reciprocal pairing completes');
    $known=file_get_contents($peer['knownHosts']);check($known==='[192.0.2.2]:2222 '.$hostKey."\n",'Only the verified key is pinned for the exact address/port');
    \file_put_contents($peer['knownHosts'],'[192.0.2.2]:2222 '.$otherKey."\n");$failKnownWrite=true;$before=$auth;reject(fn()=>unmPair($input),'failed pin write with stale host key');check($auth===$before,'A failed verified-pin write never sends the password using a stale key');$failKnownWrite=false;
    $before=$auth;$scan='peer '.$otherKey."\n";reject(fn()=>unmPair($input),'changed key at retry');check($auth===$before,'Changed key fails before password authentication');$scan='peer '.$hostKey."\n";
    $input['host']='192.0.2.3';$oldPeer=true;reject(fn()=>unmPair($input),'old peer lacks verified reciprocal exchange');check(count($forgot)===1,'Incompatible new pairing rolls back');$oldPeer=false;
    $request=['host'=>'192.0.2.4','port'=>2222,'hostId'=>'other-host','incomingPublicKey'=>$clientKey,'capabilities'=>unmCapabilities()];
    $before=count($adds);try{unmPrepareReciprocalPeer($request);throw new RuntimeException('Old initiator accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'0.4.7 or later on both hosts'),'Old initiator receives actionable upgrade guidance');}check(count($adds)===$before,'Invalid reciprocal request installs no client key');
    $request['sshHostKey']=$localKey;$reciprocal=unmPrepareReciprocalPeer($request);$returnPeer=end($saved);
    check(file_get_contents($returnPeer['knownHosts'])==='[192.0.2.4]:2222 '.$localKey."\n",'Reciprocal pin uses the supplied authenticated host key, not the scanner');
    $id=unmPeerIdForAddress('192.0.2.5',22);file_put_contents(unmPeerPath($id),'existing-pair');
    reject(fn()=>unmCreatePeerMaterial('192.0.2.5',22,$hostKey),'existing pairing');check(file_get_contents(unmPeerPath($id))==='existing-pair','Existing pairing preserved');
    echo "Verified fingerprints, pre-password rejection, reciprocal key exchange, mixed-version refusal and existing-pair preservation passed.\n";
} finally {removeFixture($root);}
