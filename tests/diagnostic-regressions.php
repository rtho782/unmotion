<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function reportCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/unmotion-report-test-'.bin2hex(random_bytes(6));mkdir($root,0700);
$dir=$root.'/job-123';mkdir($dir,0700);
$uuid='12345678-1234-4567-89ab-123456789012';
$path='/mnt/private-pool/domains/Squid Proxy/vdisk1.qcow2';
$xml='<domain type="kvm"><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><memory unit="KiB">524288</memory><vcpu>4</vcpu><cpu mode="custom"><model>EPYC</model><topology sockets="1" cores="2" threads="2"/></cpu><os><type arch="x86_64" machine="pc-q35-9.2">hvm</type></os><devices><disk type="file" device="disk"><driver type="qcow2"/><source file="'.$path.'"/><target dev="vda" bus="virtio"/><serial>NEVER-SERIAL</serial></disk><interface type="bridge"><mac address="52:54:00:ab:cd:ef"/><model type="virtio"/><source bridge="secret-br"/></interface><graphics passwd="NEVER-GRAPHICS"/><tpm/><hostdev type="usb"/></devices><metadata>NEVER-METADATA</metadata></domain>';
$job=['id'=>'job-123','vm'=>'Squid Proxy','peerId'=>'peer-secret','peerName'=>'private-host','state'=>'ATTENTION_REQUIRED','progress'=>88,'request'=>['VM_UUID'=>$uuid,'VM_NAME'=>'Squid Proxy','SOURCE_CLEANUP_ACTION'=>'retain','PASSWORD'=>'NEVER-PASSWORD'],'preflight'=>['capacity'=>['requiredBytes'=>123456789]],'privateKey'=>'NEVER-PRIVATE'];
$log="Transferring '$path' to private-host 192.168.3.119\nVM $uuid MAC 52:54:00:ab:cd:ef IP fd00::1234\npassword=NEVER-PASSWORD\nAuthorization: Bearer NEVER-BEARER\nhttps://rich:NEVER-URL@example.com/path\n-----BEGIN OPENSSH PRIVATE KEY-----\nNEVER-PEM\n-----END OPENSSH PRIVATE KEY-----\n".str_repeat('A',100)."\n[FAILED] destination startup failed\n";
$versions=['unmotion'=>UNM_VERSION,'php'=>'8.3','unraid'=>'7.2'];
try {
    $labels = new UnmReportRedactor();
    $labels->path('/mnt/cache/domains/Squid Proxy/vdisk1.img');
    $projected = $labels->structured(['cache size'=>'22528 KB','driver/cache'=>'writeback','pools'=>['cache'=>123], 'controller'=>'virtio-serial','message'=>'password=NEVER-PASSWORD']);
    reportCheck(($projected['cache size']??'')==='22528 KB'&&($projected['driver/cache']??'')==='writeback','Path alias damaged technical field labels');
    reportCheck(!isset($projected['pools']['cache'])&&$projected['controller']==='virtio-serial','Pool identity or controller projection incorrect');
    reportCheck(!str_contains(json_encode($projected),'NEVER-'),'Structured value leaked secret');
    file_put_contents($dir.'/job.json',json_encode($job));file_put_contents($dir.'/source.xml',$xml);file_put_contents($dir.'/destination.xml',$xml);
    file_put_contents($dir.'/migration.log',$log);
    file_put_contents($dir.'/remote-capabilities.json',json_encode(['hostname'=>'private-host','hostId'=>'NEVER-HOSTID','pluginVersion'=>'0.4.0','resources'=>['cpuOnlineCount'=>112,'memoryTotalBytes'=>1073741824],'ssh'=>['password'=>'NEVER-REMOTE']]));
    file_put_contents($dir.'/disks.json',json_encode(['fileDetails'=>[['source'=>$path,'format'=>'qcow2','virtualSizeBytes'=>2147483648,'zfsDataset'=>'private-pool/domains']]]));
    $before=[];foreach(glob($dir.'/*') as $f)$before[$f]=hash_file('sha256',$f);
    $report=unmDiagnosticReport('job-123',false,$root,$versions);$text=$report['text'];
    foreach(['NEVER-','Squid Proxy','private-pool','private-host','peer-secret','192.168.3.119','fd00::1234','52:54:00:ab:cd:ef',$uuid] as $secret)reportCheck(!str_contains($text,$secret),'Unredacted identifier: '.$secret);
    foreach(['524288','"vcpu": "4"','EPYC','qcow2','2147483648','123456789','112','1073741824','destination startup failed','SOURCE_CLEANUP_ACTION','retain','vdisk1.qcow2'] as $needed)reportCheck(str_contains($text,$needed),'Lost technical evidence: '.$needed);
    foreach($before as $f=>$hash)reportCheck(hash_file('sha256',$f)===$hash,'Collection changed job data');
    reportCheck(strlen($text)<1048576,'Report exceeds UI limit');
    $raw=unmDiagnosticReport('job-123',true,$root,$versions)['text'];
    reportCheck(str_contains($raw,'ORIGINAL FILENAMES/PATHS INCLUDED'),'Missing path warning');
    reportCheck(str_contains($raw,'private-pool'),'Original path not preserved');
    reportCheck(str_contains($raw,$path),'Original complete path not preserved');
    reportCheck(!str_contains($raw,'NEVER-'),'Secret leaked with original paths enabled');
    foreach(['../job-123','..','job-123/../job-123','job-123;touch x',''] as $bad) { $rejected=false;try{unmDiagnosticReport($bad,false,$root,$versions);}catch(Throwable $e){$rejected=true;}reportCheck($rejected,'Unsafe job id accepted'); }
    file_put_contents($root.'/outside','PRIVATE-OUTSIDE');unlink($dir.'/source.xml');symlink($root.'/outside',$dir.'/source.xml');
    reportCheck(!str_contains(unmDiagnosticReport('job-123',false,$root,$versions)['text'],'PRIVATE-OUTSIDE'),'Followed symlink');
    unlink($dir.'/source.xml');link($root.'/outside',$dir.'/source.xml');
    reportCheck(!str_contains(unmDiagnosticReport('job-123',false,$root,$versions)['text'],'PRIVATE-OUTSIDE'),'Read hardlink');
    file_put_contents($dir.'/destination.xml','<!DOCTYPE domain [<!ENTITY x SYSTEM "file://'.$root.'/outside">]><domain><name>&x;</name></domain>');
    reportCheck(!str_contains(unmDiagnosticReport('job-123',false,$root,$versions)['text'],'PRIVATE-OUTSIDE'),'Expanded external entity');
    file_put_contents($dir.'/migration.log',str_repeat("large log line\n",20000)."final line kept\n");
    $truncated=unmDiagnosticReport('job-123',false,$root,$versions)['text'];
    reportCheck(str_contains($truncated,'truncated')&&str_contains($truncated,'final line kept'),'Missing bounded log tail');
    symlink($dir,$root.'/linked-job');$rejected=false;try{unmDiagnosticReport('linked-job',false,$root,$versions);}catch(Throwable $e){$rejected=true;}reportCheck($rejected,'Followed linked job directory');
    unlink($root.'/linked-job');
    $api=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
    reportCheck((bool)preg_match('/case \'diagnosticReport\':\s*requirePostMutation\(\)/',$api),'Missing POST gate');
    $js=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/report.js');
    reportCheck(str_contains($js,"timeout:25000")&&str_contains($js,"requestSerial!==serial"),'Missing timeout/stale-response guard');
    reportCheck(!str_contains($js,'localStorage')&&!str_contains($js,'sessionStorage'),'Report persisted in browser storage');
    echo "Diagnostic allowlist, privacy, safe-file, size, nonmutation and UI guards passed.\n";
} finally {
    foreach(glob($dir.'/*')?:[] as $f)unlink($f);
    if(is_link($root.'/linked-job'))unlink($root.'/linked-job');
    if(is_file($root.'/outside'))unlink($root.'/outside');
    rmdir($dir);rmdir($root);
}
