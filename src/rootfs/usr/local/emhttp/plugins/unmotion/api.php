<?php
declare(strict_types=1);
require_once __DIR__ . '/include/lib.php';
header('Content-Type: application/json');

function reply(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(Throwable|string $e, int $status=400): never {
    reply(['success'=>false,'error'=>$e instanceof Throwable?$e->getMessage():(string)$e],$status);
}
function launchWorker(string $id, string $dir): int {
    $cmd='nohup setsid /usr/local/sbin/unmotion-worker '.escapeshellarg($id).' >>'.escapeshellarg($dir.'/launcher.log').' 2>&1 & echo $!';
    $r=unmRun($cmd,null,10); $pid=(int)trim($r['stdout']);
    if($r['code']!==0||$pid<=0) throw new RuntimeException('Unable to launch migration worker: '.trim($r['stderr']));
    return $pid;
}

try {
    // Unraid validates POST CSRF in local_prepend.php before this endpoint runs.
    unmEnsureDirs();
    $action=(string)($_GET['action']??$_POST['action']??'');
    switch ($action) {
        case 'status': reply(['success'=>true,'version'=>UNM_VERSION,'protocol'=>UNM_PROTOCOL,'hostId'=>unmHostId(),'hostname'=>gethostname()]);
        case 'inventory': reply(['success'=>true,'vms'=>unmInventory(),'seeds'=>unmSeeds()]);
        case 'localHealth': reply(['success'=>true,'health'=>unmHealth()]);
        case 'peerHealth': reply(['success'=>true,'health'=>unmPeerHealth((string)($_POST['peer_id']??''))]);
        case 'settings': reply(['success'=>true,'settings'=>unmLoadConfig(),'capabilities'=>unmCapabilities()]);
        case 'saveSettings':
            $payload=json_decode((string)($_POST['payload']??''),true); if(!is_array($payload)) throw new InvalidArgumentException('Invalid settings payload.');
            $cfg=unmSaveConfig($payload); @exec('/etc/rc.d/rc.unmotion restart >/dev/null 2>&1 &');
            reply(['success'=>true,'settings'=>$cfg]);
        case 'peers': reply(['success'=>true,'peers'=>unmPeers()]);
        case 'pairPeer':
            $peer=unmPair(['host'=>$_POST['host']??'','port'=>$_POST['port']??22,'password'=>$_POST['password']??'']);
            reply(['success'=>true,'peer'=>$peer]);
        case 'testPeer': reply(['success'=>true,'peer'=>unmTestPeer((string)($_POST['peer_id']??''))]);
        case 'removePeer':
        case 'breakPairing':
            unmRemovePeer((string)($_POST['peer_id']??''));
            reply(['success'=>true]);
        case 'discover': reply(['success'=>true]+unmDiscover());
        case 'seeds': reply(['success'=>true,'seeds'=>unmSeeds()]);
        case 'prepareWarm': reply(['success'=>true,'seed'=>unmStartSeed((string)($_POST['vm_id']??''),(string)($_POST['peer_id']??''),'prepare')]);
        case 'updateWarm': reply(['success'=>true,'seed'=>unmStartSeed((string)($_POST['vm_id']??''),(string)($_POST['peer_id']??''),'update')]);
        case 'resumeWarm': reply(['success'=>true,'seed'=>unmResumeSeed((string)($_POST['seed_id']??''))]);
        case 'removeWarm': reply(['success'=>true,'seed'=>unmRemoveSeed((string)($_POST['seed_id']??''))]);
        case 'seedLog':
            $sid=(string)($_REQUEST['seed_id']??'');$sd=unmSeedPath($sid);reply(['success'=>true,'log'=>is_file($sd.'/seed.log')?file_get_contents($sd.'/seed.log'):'']);
        case 'preflight':
            $opts=json_decode((string)($_POST['options']??'{}'),true)?:[];
            if(isset($_POST['migration_mode']))$opts['migration_mode']=(string)$_POST['migration_mode'];
            $vmId=(string)($_POST['vm_id']??$_POST['vm']??'');
            reply(['success'=>true,'preflight'=>unmPreflight($vmId,(string)($_POST['peer_id']??''),$opts)]);
        case 'startMigration':
            $vmId=(string)($_POST['vm_id']??$_POST['vm']??''); $peerId=(string)($_POST['peer_id']??'');
            $opts=json_decode((string)($_POST['options']??'{}'),true)?:[];
            $warmSeedId=(string)($opts['warm_seed_id']??'');
            $opts['migration_mode']=$warmSeedId!==''?'warm-cutover':'cold';
            $cfg=unmLoadConfig();
            $isoAction=(string)($opts['iso_action']??($cfg['copy_isos_default']?'copy':'remove'));
            $usbAction=(string)($opts['usb_action']??'cancel');
            if (!in_array($isoAction,['copy','remove','retain'],true)) throw new InvalidArgumentException('Invalid ISO action.');
            if (!in_array($usbAction,['cancel','retain','remove'],true)) throw new InvalidArgumentException('Invalid USB action.');
            $vcpuCount=(int)($opts['vcpu_count']??0); $memoryMib=(int)($opts['memory_mib']??0); $pinningAction=(string)($opts['cpu_pinning_action']??'');
            $sourceCleanupAction=(string)($opts['source_cleanup_action']??($cfg['source_cleanup_default']??'unregister'));
            $conflictAction=(string)($opts['destination_conflict_action']??'cancel');
            if($vcpuCount!==0&&($vcpuCount<1||$vcpuCount>4096)) throw new InvalidArgumentException('Invalid vCPU count.');
            if($memoryMib!==0&&($memoryMib<128||$memoryMib>1048576)) throw new InvalidArgumentException('Invalid memory amount.');
            if(!in_array($pinningAction,['','retain','remove','none'],true)) throw new InvalidArgumentException('Invalid CPU pinning action.');
            if(!in_array($sourceCleanupAction,['unregister','retain','delete'],true)) throw new InvalidArgumentException('Invalid source cleanup action.');
            if(!in_array($conflictAction,['cancel','overwrite'],true)) throw new InvalidArgumentException('Invalid destination conflict action.');
            $opts['iso_action']=$isoAction; $opts['usb_action']=$usbAction; $opts['source_cleanup_action']=$sourceCleanupAction; $opts['destination_conflict_action']=$conflictAction;
            if($vcpuCount>0)$opts['vcpu_count']=$vcpuCount; if($memoryMib>0)$opts['memory_mib']=$memoryMib; if($pinningAction!=='')$opts['cpu_pinning_action']=$pinningAction;
            $pre=unmPreflight($vmId,$peerId,$opts); if(!$pre['ready']) throw new RuntimeException(implode('; ',$pre['errors']));
            if ($warmSeedId==='' && strtolower(trim((string)($pre['vm']['state']??''))) !== 'shut off') throw new RuntimeException('Power off the VM before a cold migration.');
            if($warmSeedId!==''){ $seed=unmSeed($warmSeedId); if(($seed['vmUuid']??'')!==($pre['vm']['uuid']??'')||($seed['peerId']??'')!==$peerId||($seed['state']??'')!=='READY')throw new RuntimeException('The selected prepared copy is missing, stale or belongs to a different VM/peer.'); }
            $vm=$pre['vm']['name']; $vmUuid=$pre['vm']['uuid'];
            if ($vmUuid==='' || !preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid)) throw new RuntimeException('The selected VM has no valid UUID.');
            if (!empty($pre['vm']['usb']) && $usbAction==='cancel') throw new RuntimeException('Choose retain or remove for USB passthrough before starting.');
            if ($usbAction==='retain') {
                foreach(($pre['vm']['usb']??[]) as $device) {
                    if (empty($device['portable'])) throw new RuntimeException('A USB device could not be represented by an unambiguous VID:PID selector. Choose remove or cancel.');
                }
            }
            $jobId=date('Ymd-His').'-'.substr(bin2hex(random_bytes(5)),0,10); $dir=UNM_JOBS_DIR.'/'.$jobId; mkdir($dir,0700,true);
            $peer=unmPeer($peerId);
            $usbManifest=$dir.'/usb-manifest.json';
            unmAtomicJson($usbManifest,['devices'=>$pre['vm']['usb']??[]]);
            $resourcePlan=(array)($pre['resourcePlan']??[]); $requested=(array)($resourcePlan['requested']??[]); $pinning=(array)($resourcePlan['pinning']??[]);
            $request=[
                'JOB_ID'=>$jobId,'VM_UUID'=>$vmUuid,'VM_NAME'=>$vm,'PEER_ID'=>$peerId,
                'ISO_ACTION'=>$isoAction,
                'USB_ACTION'=>empty($pre['vm']['usb'])?'retain':$usbAction,
                'USB_MANIFEST'=>$usbManifest,
                'TARGET_VCPUS'=>(int)($requested['vcpus']??$pre['vm']['vcpus']),
                'TARGET_MEMORY_KIB'=>(int)($requested['memoryKiB']??$pre['vm']['memoryKiB']),
                'CPU_PINNING_ACTION'=>(string)($pinning['effectiveAction']??'none'),
                'REMOVE_CPU_PINNING'=>(string)($pinning['effectiveAction']??'none')==='remove',
                'START_DESTINATION'=>true,
                'SOURCE_CLEANUP_ACTION'=>$sourceCleanupAction,
                'DESTINATION_CONFLICT_ACTION'=>$conflictAction,
                'SOAK_SECONDS'=>300,
                'SNAPSHOT_NAME'=>'unmotion-'.$jobId,
                'WARM_SEED_ID'=>$warmSeedId,
            ];
            unmWriteCfg($dir.'/request.cfg',$request);
            $job=['id'=>$jobId,'vm'=>$vm,'peerId'=>$peerId,'peerName'=>$peer['name']??$peer['host'],'state'=>'STARTING','progress'=>0,'message'=>'Starting worker','createdAt'=>date(DATE_ATOM),'updatedAt'=>date(DATE_ATOM),'request'=>$request,'preflight'=>$pre];
            unmAtomicJson($dir.'/job.json',$job); file_put_contents($dir.'/migration.log',''); chmod($dir.'/migration.log',0600);
            $pid=launchWorker($jobId,$dir);
            $latest=unmLoadJson($dir.'/job.json',$job); $latest['pid']=$pid; $latest['updatedAt']=date(DATE_ATOM); unmAtomicJson($dir.'/job.json',$latest);
            reply(['success'=>true,'job'=>$latest]);
        case 'resumeJob':
            $id=(string)($_POST['job_id']??''); if(!preg_match('/^[A-Za-z0-9_.-]+$/',$id)) throw new InvalidArgumentException('Invalid job id.');
            $dir=UNM_JOBS_DIR.'/'.$id; $job=unmLoadJson($dir.'/job.json'); if(!$job) throw new RuntimeException('Job not found.');
            if (!in_array($job['state']??'', ['FAILED','INTERRUPTED'], true)) throw new RuntimeException('Only failed or interrupted pre-handoff jobs can be resumed automatically.');
            @unlink($dir.'/cancel.requested');
            $job['state']='STARTING'; $job['message']='Resume requested'; $job['updatedAt']=date(DATE_ATOM); unmAtomicJson($dir.'/job.json',$job);
            $pid=launchWorker($id,$dir); $job['pid']=$pid; unmAtomicJson($dir.'/job.json',$job);
            reply(['success'=>true,'pid'=>$pid]);
        case 'cancelJob':
            reply(['success'=>true,'job'=>unmCancelJob((string)($_POST['job_id']??''))]);
        case 'removeJob':
            unmRemoveJob((string)($_POST['job_id']??''));
            reply(['success'=>true]);
        case 'clearJobs':
            reply(['success'=>true,'removed'=>unmClearJobHistory()]);
        case 'jobs': reply(['success'=>true,'jobs'=>unmJobs()]);
        case 'job':
            $id=(string)($_REQUEST['job_id']??''); if(!preg_match('/^[A-Za-z0-9_.-]+$/',$id)) throw new InvalidArgumentException('Invalid job id.');
            $j=unmLoadJson(UNM_JOBS_DIR.'/'.$id.'/job.json'); if(!$j) throw new RuntimeException('Job not found.'); reply(['success'=>true,'job'=>$j]);
        case 'jobLog':
            $id=(string)($_REQUEST['job_id']??''); if(!preg_match('/^[A-Za-z0-9_.-]+$/',$id)) throw new InvalidArgumentException('Invalid job id.');
            $path=UNM_JOBS_DIR.'/'.$id.'/migration.log'; reply(['success'=>true,'log'=>is_file($path)?file_get_contents($path):'']);
        default: fail('Unknown action.',404);
    }
} catch(Throwable $e) { fail($e); }
