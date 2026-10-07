<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php';
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/ssh-keys.php';
function unmValidatePublicKey(string $key):string{return $key;}
function unmLoadConfig():array{return ['image_dir'=>'/mnt/cache/domains','iso_dir'=>'/mnt/user/isos','zvol_dataset'=>'cache/vm-zvols'];}
function unmPeers():array{return [];}
function unmTpmRoots():array{return ['/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm'];}
function unmTpmPathAllowed(string $p,string $uuid):bool{return in_array($p,array_map(fn($r)=>$r.'/'.$uuid,unmTpmRoots()),true);}
$passed=0;
function ok(bool $v,string $message):void{global $passed;if(!$v)throw new RuntimeException($message);$passed++;}
function denied(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){ok(true,$message);return;}throw new RuntimeException('Not rejected: '.$message);}
$uuid='11111111-2222-4333-8444-555555555555';
ok(unmTransportRuntimeDirectory()==='/run/unmotion/transport','Transport runtime avoids /var/run symlink alias');
// OpenZFS DRR_BEGIN fixed prefix, both sender byte orders, including a resume
// feature bit. Compound property-packages must never reach the receiver.
foreach(['V','N'] as $order){
    $u32=static fn(int $v):string=>pack($order,$v);
    $u64=static fn(int $low,int $high=0):string=>$order==='V'?pack('V2',$low,$high):pack('N2',$high,$low);
    $header=$u32(0).$u32(0).$u64(0xf5bacbac,2).$u64(1|(1<<24)).$u64(0).$u32(2).$u32(0);
    unmTransportZfsStreamPrefix($header,'dataset');ok(true,'typed filesystem stream accepted');
    $volume=substr_replace($header,$u32(3),32,4);unmTransportZfsStreamPrefix($volume,'zvol');ok(true,'typed volume stream accepted');
    denied(fn()=>unmTransportZfsStreamPrefix($header,'zvol'),'filesystem/volume type mismatch');
    denied(fn()=>unmTransportZfsStreamPrefix($volume,'dataset'),'volume/filesystem type mismatch');
    denied(fn()=>unmTransportZfsStreamPrefix(substr_replace($header,$u64(2),16,8),'dataset'),'compound stream rejected');
    denied(fn()=>unmTransportZfsStreamPrefix(substr_replace($header,$u32(1048577),4,4),'dataset'),'oversize begin rejected');
    denied(fn()=>unmTransportZfsStreamPrefix(substr_replace($header,$u32(1),0,4),'dataset'),'non-BEGIN rejected');
}
denied(fn()=>unmTransportZfsStreamPrefix(str_repeat('x',40),'dataset'),'bad stream magic');
denied(fn()=>unmTransportZfsStreamPrefix(str_repeat('x',39),'dataset'),'short stream header');
$valid=[
    "/usr/local/sbin/unmotion-agent capabilities",
    "virsh domuuid 'Squid Proxy' 2>/dev/null",
    "zfs get -pH -o value available 'cache/vm-zvols'",
    "zfs receive -su -o readonly=on -o dedup='off' -o mountpoint='/mnt/cache/domains/Squid Proxy' 'cache/domains/Squid Proxy'",
    "if zfs list -H -o name 'cache/vm-zvols/disk' >/dev/null 2>&1; then zfs get -H -o value receive_resume_token 'cache/vm-zvols/disk' 2>/dev/null; elif zfs list -H -o name 'cache/vm-zvols' >/dev/null 2>&1; then printf '%s\\n' -; else exit 75; fi",
    "mkdir -p '/boot/config/plugins/unmotion/incoming/seeds'; printf %s 'e30=' | base64 -d > '/boot/config/plugins/unmotion/incoming/seeds/a.json'; chmod 600 '/boot/config/plugins/unmotion/incoming/seeds/a.json'",
    "if [ -f '/mnt/cache/domains/Squid Proxy/vdisk1.raw' ] && [ ! -L '/mnt/cache/domains/Squid Proxy/vdisk1.raw' ]; then sha256sum -- '/mnt/cache/domains/Squid Proxy/vdisk1.raw' 2>/dev/null | awk '{print \$1}'; fi",
    "virsh domuuid 'Squid '\\''Proxy'",
];
foreach($valid as $command){$encoded=unmTransportEncodeCommand($command);$r=unmTransportDecode($encoded);ok($r['v']===7&&$r['op']==='operations','legacy compile');}
$quiet=unmTransportParseLegacy("zfs list 'cache/vm-zvols/x' >/dev/null 2>&1");ok($quiet['stdoutNull']&&$quiet['stderrToOut'],'Discarded output redirect retained in RPC');
$quiet=unmTransportParseLegacy("virsh domstate '$uuid' 2>/dev/null");ok(!$quiet['stdoutNull']&&$quiet['stderrNull'],'Stderr-only redirect retained in RPC');
foreach(['ssh-rsa AAAA','ssh-ed25519 '.base64_encode(str_repeat('x',51))] as $bad)denied(fn()=>unmTransportKeyId($bad),'key wire validation');
foreach(['bash','unmotion-rpc e30=; id','unmotion-rpc e30= --server --sender .','unmotion-rpc '.base64_encode('{"v":6,"op":"operations","args":{}}')] as $s)denied(fn()=>unmTransportDecode($s),'envelope reject');
foreach(['echo $(id)','cat `id`','echo $PATH','echo /tmp/*','echo x > /tmp/y & id','echo "${PATH}"',"echo 'unterminated"] as $s)denied(fn()=>unmTransportEncodeCommand($s),'shell expansion reject');
foreach(['/mnt/cache/domains/../outside','/mnt/cache//domains/x',"/mnt/cache/domains/a\nfile",'/mnt/cache/domains/./x'] as $p)denied(fn()=>unmTransportPath($p),'path lexical reject');
denied(fn()=>unmTransportStoragePath('/root/.ssh/authorized_keys'),'root write reject');
denied(fn()=>unmTransportStoragePath('/mnt/cache/domains'),'storage root write reject');
denied(fn()=>unmTransportDataset('cache/vm-zvols/x; reboot'),'dataset shell chars reject');
denied(fn()=>unmTransportDataset('cache/vm-zvols/../root'),'dataset traversal reject');
foreach([['exec','on'],['mountpoint','/etc'],['keylocation','file:///root/key'],['sharenfs','on'],['dedup','arbitrary']] as [$k,$v])denied(fn()=>unmTransportZfsProperty($k,$v,['kind'=>'dataset','mountpoint'=>'/mnt/cache/domains/Squid Proxy'],false),'zfs property reject');
denied(fn()=>unmTransportZfsProperty('readonly','off',['kind'=>'zvol'],true),'replica inert');
unmTransportZfsProperty('dedup','verify',['kind'=>'zvol'],false);ok(true,'dedup verify retained');
$base="<domain type='kvm'><name>Squid Proxy</name><uuid>$uuid</uuid><os><type>hvm</type></os><devices><emulator>/usr/bin/qemu-system-x86_64</emulator><disk type='file' device='disk'><driver name='qemu' type='raw'/><source file='/mnt/cache/domains/Squid Proxy/vdisk.raw'/><target dev='vda' bus='virtio'/></disk><channel type='unix'><target type='virtio' name='org.qemu.guest_agent.0'/></channel><rng model='virtio'><backend model='random'>/dev/urandom</backend></rng></devices></domain>";
unmTransportValidateDomainXml($base,$uuid,null);ok(true,'normal VM accepted');
$attacks=[
    str_replace('/usr/bin/qemu-system-x86_64','/bin/sh',$base),
    str_replace('</devices>',"<filesystem type='mount'><source dir='/'/><target dir='host'/></filesystem></devices>",$base),
    str_replace('</domain>',"<qemu:commandline xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'><qemu:arg value='-chardev'/></qemu:commandline></domain>",$base),
    str_replace('</os>','<kernel>/tmp/program</kernel></os>',$base),
    str_replace('/mnt/cache/domains/Squid Proxy/vdisk.raw','/etc/shadow',$base),
    str_replace('</disk>',"<backingStore><source file='/etc/shadow'/></backingStore></disk>",$base),
    str_replace("type='raw'","type='vmdk'",$base),
    str_replace("device='disk'","device='floppy'",$base),
    str_replace("device='disk'","device='lun'",$base),
    str_replace(["device='disk'","type='raw'"],["device='cdrom'","type='qcow2'"],$base),
    str_replace('</devices>',"<interface type='ethernet'><script path='/bin/sh'/></interface></devices>",$base),
    str_replace('</devices>',"<serial type='file'><source path='/root/.ssh/authorized_keys'/></serial></devices>",$base),
    "<!DOCTYPE domain [<!ENTITY p SYSTEM 'file:///etc/shadow'>]>".$base,
];
foreach($attacks as $xml)denied(fn()=>unmTransportValidateDomainXml($xml,$uuid,null),'malicious XML reject');
unmTransportValidateDomainXml(str_replace('</disk>','<backingStore/></disk>',$base),$uuid,null);ok(true,'empty libvirt backingStore accepted');
$a=['vmUuid'=>$uuid,'sourceHostId'=>'a','storage'=>[['kind'=>'dataset','destination'=>'cache/domains/a','mountpoint'=>'/mnt/cache/domains/a']]];
denied(fn()=>unmTransportNoOverlap($a,['vmUuid'=>$uuid,'sourceHostId'=>'b','storage'=>[]]),'cross-peer empty-storage VM takeover');
denied(fn()=>unmTransportNoOverlap($a,['vmUuid'=>'other','sourceHostId'=>'b','storage'=>[['kind'=>'image','destination'=>'/mnt/cache/domains/a/vdisk.raw']]]),'dataset/file overlap');
denied(fn()=>unmTransportNoOverlap($a,['vmUuid'=>'other','sourceHostId'=>'b','storage'=>[['kind'=>'dataset','destination'=>'cache/domains/a/child']]]),'dataset/child overlap');
unmTransportNoOverlap($a,['vmUuid'=>'other','sourceHostId'=>'b','storage'=>[['kind'=>'dataset','destination'=>'cache/domains/ab']]]);ok(true,'distinct prefix accepted');
$peer=['id'=>'aaaa1111','hostId'=>'source-1','pairingState'=>'paired'];
foreach([['bash','-c','id'],['/usr/local/sbin/unmotion-agent','pairing-bootstrap'],['/usr/local/sbin/unmotion-agent','reciprocal-forget','another-peer']] as $argv)denied(fn()=>unmTransportCall($argv,$peer,str_repeat('a',64)),'command deny');
denied(fn()=>unmTransportCall(['/usr/local/sbin/unmotion-agent','health'],null,str_repeat('a',64)),'bootstrap query deny');
denied(fn()=>unmTransportTree(['op'=>'json-write','path'=>'/root/a','data'=>'e30='],['pairingState'=>'pending'],str_repeat('a',64)),'pending mutation deny');
$config=unmTransportRsyncConfiguration('/tmp/Squid Proxy','/tmp/filters');ok(str_contains($config,'use chroot = yes')&&str_contains($config,'write only = yes')&&str_contains($config,'refuse options = *'),'rsync constrained config');
ok(!str_contains($config,'pre-xfer exec')&&!str_contains($config,'post-xfer exec'),'no command hooks');
echo "transport tests: $passed assertions passed\n";
