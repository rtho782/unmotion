<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;
function check(bool $v,string $m): void {global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function denied(string $xml,string $m):void{try{unmLiveInspectXml($xml);}catch(Throwable){check(true,$m);return;}throw new RuntimeException($m);}
$xml='<domain type="kvm"><name>Squid Proxy</name><uuid>11111111-1111-4111-8111-000000000001</uuid><memory unit="KiB">2097152</memory><vcpu>2</vcpu><os><type arch="x86_64" machine="pc-q35-9.2">hvm</type></os><cpu mode="custom" match="exact"><model fallback="forbid">Cascadelake-Server-v5</model></cpu><devices><emulator>/usr/bin/qemu-system-x86_64</emulator><disk type="file" device="disk"><driver type="qcow2" cache="none"/><source file="/mnt/cache/domains/Squid Proxy/vdisk.qcow2"/><target dev="vda" bus="virtio"/></disk><interface type="bridge"><source bridge="br0"/></interface></devices></domain>';
$s=unmLiveInspectXml($xml);check($s['vmName']==='Squid Proxy'&&$s['vcpus']===2&&$s['memoryKiB']===2097152,'eligible file disk/spaces/resources');
$vmx='<cpu><feature name="vmx" policy="require"/></cpu>';$svm='<cpu><feature name="svm" policy="require"/></cpu>';
unmLiveNestedCpu($vmx,fn($f)=>true);check(true,'Intel nested VMX has a fresh host gate');
foreach([[$vmx,fn($f)=>false],[$svm,fn($f)=>true]] as [$cpu,$enabled]){try{unmLiveNestedCpu($cpu,$enabled);}catch(RuntimeException){check(true,'unavailable VMX or unsafe AMD nested live migration rejected');continue;}throw new RuntimeException('Nested live gate missing.');}
unmLiveNestedCpu(str_replace('require','disable',$svm),fn($f)=>false);check(true,'disabled SVM is not an active nested guest');
foreach(['<tpm/>','<hostdev/>','<filesystem/>','<shmem/>','<redirdev/>','<memory/>'] as $device)denied(str_replace('</devices>',$device.'</devices>',$xml),'excluded device '.$device);
foreach(['<loader>/loader</loader>','<nvram>/vars</nvram>'] as $os)denied(str_replace('</os>',$os.'</os>',$xml),'UEFI not qualified');
$uefi=str_replace('</os>','<loader readonly="yes" type="pflash" format="raw">/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd</loader><nvram format="raw">/etc/libvirt/qemu/nvram/11111111-1111-4111-8111-000000000001_VARS-pure-efi.fd</nvram></os>',$xml);
$fw=unmLiveInspectXml($uefi)['firmware'];check($fw!==null&&str_ends_with($fw['nvramPath'],'_VARS-pure-efi.fd'),'reviewed explicit raw UEFI accepted');
$target=unmLiveTargetXml($uefi,'/mnt/cache/domains/Squid Proxy/new.qcow2','/etc/libvirt/qemu/nvram/11111111-1111-4111-8111-000000000001_VARS-unmotion-live-aaaaaaaaaaaaaaaaaaaaaaaa.fd');
check(str_contains($target,'_VARS-unmotion-live-aaaaaaaaaaaaaaaaaaaaaaaa.fd'),'target NVRAM remapped separately from the disk');
foreach(['readonly="yes"'=>'readonly="no"','format="raw"'=>'format="qcow2"','OVMF_CODE-pure-efi.fd'=>'../../other.fd','11111111-1111-4111-8111-000000000001_VARS'=>'22222222-2222-4222-8222-222222222222_VARS','type="pflash"'=>'type="rom"'] as $from=>$to)denied(str_replace($from,$to,$uefi),'UEFI narrow validation '.$from);
denied(str_replace('</devices>','<tpm model="tpm-tis"/></devices>',$uefi),'UEFI TPM still excluded');
unmLiveFirmwareDestination(null,null);check(true,'BIOS requires no firmware metadata');
try{unmLiveFirmwareDestination($fw,null);}catch(Throwable){check(true,'UEFI missing source evidence rejected');}
denied(str_replace('</domain>','<memoryBacking><hugepages/></memoryBacking></domain>',$xml),'huge pages excluded');
denied(str_replace('</domain>','<cputune/></domain>',$xml),'host pinning excluded');
foreach(['<numa/>','<cache mode="passthrough"/>','<maxphysaddr mode="passthrough"/>','<feature policy="require" name="invtsc"/>'] as $extra)denied(str_replace('</cpu>',$extra.'</cpu>',$xml),'non-migratable CPU option');
foreach(['pc-9.2','q35','microvm','pc-i440fx-unknown'] as $machine)denied(str_replace('pc-q35-9.2',$machine,$xml),'exact supported machine required');
denied(str_replace('x86_64','aarch64',$xml),'architecture excluded');
foreach(['<readonly/>','<shareable/>','<backingStore><source file="/etc/shadow"/></backingStore>','<mirror/>'] as $extra)denied(str_replace('</disk>',$extra.'</disk>',$xml),'dependent/shared disk excluded');
denied(str_replace('/mnt/cache/domains','/mnt/user/domains',$xml),'user share not direct pool');
denied(str_replace('cache="none"','cache="writeback"',$xml),'unsafe writeback blocked');
denied(str_replace('</devices>','<disk device="cdrom"/></devices>',$xml),'ISO/CD-ROM excluded');
denied(str_replace('<interface type="bridge">','<interface type="network">',$xml),'nonbridge NIC excluded');
denied(str_replace('</interface>','<vlan><tag id="10"/></vlan></interface>',$xml),'host VLAN excluded');
denied(str_replace('mode="custom"','mode="host-passthrough"',$xml),'host passthrough needs active CPU mode');
denied(str_replace('</domain>','<qemu:commandline xmlns:qemu="http://libvirt.org/schemas/domain/qemu/1.0"/></domain>',$xml),'QEMU overrides excluded');
denied(str_replace('</domain>','<uuid>11111111-1111-4111-8111-000000000001</uuid></domain>',$xml),'duplicate UUID excluded');
denied(str_replace('unit="KiB"','unit="GiB"',$xml),'resource units explicit');
denied(str_replace('</devices>','<graphics type="spice"/></devices>',$xml),'unqualified SPICE excluded');
denied(str_replace('</devices>','<graphics type="vnc"><gl enable="yes"/></graphics></devices>',$xml),'host GPU rendering excluded');
denied(str_replace('</devices>','<audio type="alsa"/></devices>',$xml),'host audio excluded');
$secure=str_replace('</devices>','<graphics type="vnc" passwd="test-only-secret"/></devices>',$xml);check(str_contains(unmLiveTargetXml($secure,'/mnt/cache/domains/Squid Proxy/vdisk.qcow2'),'passwd="test-only-secret"'),'console authentication preserved through target transformation');
$intel=['vendor'=>'Intel','model'=>'Cascadelake-Server-v5','family'=>6,'modelNumber'=>85];
check(unmLiveHostVendorCompatible($intel,$intel),'same vendor gate');
check(unmLiveHostVendorCompatible($intel,array_replace($intel,['model'=>'Skylake-Server-v5'])),'older physical generation is not a veto after guest CPU validation');
check(unmLiveHostVendorCompatible($intel,array_replace($intel,['model'=>'Icelake-Server-v6','modelNumber'=>106])),'newer physical generation is not a CPU proof');
check(!unmLiveHostVendorCompatible($intel,array_replace($intel,['vendor'=>'AMD'])),'cross vendor excluded');
check(unmLiveHostVendorCompatible($intel,array_replace($intel,['model'=>'UnknownCPU','modelNumber'=>255])),'unranked host model relies on fresh guest validation, not guessed chronology');
foreach([[],['vendor'=>'Other'],['vendor'=>null],['vendor'=>false]] as $bad)check(!unmLiveHostVendorCompatible($bad,$bad),'missing/unsupported vendor rejected');
$amd=['vendor'=>'AMD','model'=>'EPYC-Genoa'];check(unmLiveHostVendorCompatible($amd,['vendor'=>'AMD','model'=>'EPYC-Rome']),'AMD vendor boundary is independent of physical chronology; SVM gate stays separate');
$engine=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-engine.php');
$preview=substr($engine,strpos($engine,'function unmLivePreview('),strpos($engine,'function unmLivePeerJob(')-strpos($engine,'function unmLivePreview('));
$inspectAt=strpos($preview,"unmLiveRpc(\$peer,'inspect'");$vendorAt=strpos($preview,'unmLiveHostVendorCompatible(');check($inspectAt!==false&&$vendorAt!==false&&$inspectAt<$vendorAt,'preview completes receiver CPU validation before vendor gate');
$reserveAt=strpos($engine,"if(\$action==='reserve')");check($reserveAt!==false,'receiver reservation block found');$reserve=substr($engine,$reserveAt);$validateAt=strpos($reserve,'unmLiveValidateDestination($s)');$vendorAt=strpos($reserve,'unmLiveHostVendorCompatible(');check($validateAt!==false&&$vendorAt!==false&&$validateAt<$vendorAt,'receiver reservation repeats guest CPU validation before vendor gate');
check(str_contains($engine,"['virsh','hypervisor-cpu-compare'")&&str_contains($engine,"unmLiveNestedCpu(\$spec['cpuXml'])"),'full fresh hypervisor CPU comparison and nested validation retained');
check(unmLiveSameDisk('/mnt/cache/domains/Squid Proxy/disk.raw',['dev'=>1,'ino'=>2],'/mnt/user/domains/Squid Proxy/disk.raw',['physicalPath'=>'/mnt/cache/domains/Squid Proxy/disk.raw','candidates'=>[]]),'user-share alias is not independent');
check(unmLiveSameDisk('/mnt/cache/domains/Squid Proxy/disk.raw',['dev'=>1,'ino'=>2],'/mnt/user/domains/Squid Proxy/disk.raw',['physicalPath'=>'','candidates'=>['/mnt/disk1/domains/Squid Proxy/disk.raw','/mnt/cache/domains/Squid Proxy/disk.raw']]),'ambiguous alias still blocks an overlapping physical file');
check(!unmLiveSameDisk('/mnt/cache/domains/Squid Proxy/disk.raw',['dev'=>1,'ino'=>2],'/not-existing-other-file',['physicalPath'=>'/not-existing-other-file','candidates'=>[]]),'different absent file does not falsely overlap');
echo "Live preflight regressions passed: $checks checks.\n";
