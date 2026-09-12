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
function requirePostMutation(): void {
    if(($_SERVER['REQUEST_METHOD']??'POST')!=='POST')throw new RuntimeException('This action requires a CSRF-protected POST request.');
}
function requireRecoveryDirection(string $expected): void {
    if(!hash_equals($expected,(string)($_POST['direction']??'')))throw new RuntimeException('Recovery source/destination direction does not match this action.');
}
function launchWorker(string $id, string $dir, string $worker='/usr/local/sbin/unmotion-worker'): int {
    if(!in_array($worker,['/usr/local/sbin/unmotion-worker','/usr/local/sbin/unmotion-clone-worker'],true))throw new InvalidArgumentException('Invalid worker.');
    $cmd='nohup setsid '.escapeshellarg($worker).' '.escapeshellarg($id).' >>'.escapeshellarg($dir.'/launcher.log').' 2>&1 & echo $!';
    $r=unmRun($cmd,null,10); $pid=(int)trim($r['stdout']);
    if($r['code']!==0||$pid<=0) throw new RuntimeException('Unable to launch worker: '.trim($r['stderr']));
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
        case 'peers': reply(['success'=>true,'peers'=>unmPublicPeers(unmPeers())]);
        case 'pairPeer':
            $peer=unmPair(['host'=>$_POST['host']??'','port'=>$_POST['port']??22,'password'=>$_POST['password']??'']);
            reply(['success'=>true,'peer'=>unmPublicPeer($peer)]);
        case 'testPeer': reply(['success'=>true,'peer'=>unmPublicPeer(unmTestPeer((string)($_POST['peer_id']??'')))]);
        case 'removePeer':
        case 'breakPairing':
            unmRemovePeer((string)($_POST['peer_id']??''));
            reply(['success'=>true]);
        case 'discover': reply(['success'=>true]+unmDiscover());
        case 'replications': reply(['success'=>true,'replications'=>unmReplications()]);
        case 'incomingReplicas': reply(['success'=>true,'replicas'=>unmIncomingReplicas()]);
        case 'recoveryStatus': reply(['success'=>true]+unmRecoveryStatus((string)($_REQUEST['replication_id']??'')));
        case 'recoveryArm':
            requirePostMutation();requireRecoveryDirection('source');$desired=filter_var($_POST['desired_autostart']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($desired===null)throw new InvalidArgumentException('Invalid managed autostart setting.');$witnesses=json_decode((string)($_POST['witness_peer_ids']??'[]'),true);if(!is_array($witnesses))throw new InvalidArgumentException('Invalid recovery witness selection.');$record=unmRecoveryArm((string)($_POST['replication_id']??''),$desired,array_values(array_map('strval',$witnesses)));reply(['success'=>true,'message'=>'Recovery is armed; source startup is managed by unMotion.','recovery'=>unmRecoveryPublicRecord($record)]);
        case 'recoveryDisarm':
            requirePostMutation();requireRecoveryDirection('source');$record=unmRecoveryDisarm((string)($_POST['replication_id']??''));reply(['success'=>true,'message'=>'Recovery was disarmed on both hosts and native autostart was restored.','recovery'=>unmRecoveryPublicRecord($record)]);
        case 'recoveryHold':
            requirePostMutation();requireRecoveryDirection('source');$record=unmRecoverySetHold((string)($_POST['replication_id']??''),(string)($_POST['reason']??''),(string)($_POST['hold_until']??''));reply(['success'=>true,'message'=>'The destination durably acknowledged the recovery hold.','recovery'=>unmRecoveryPublicRecord($record)]);
        case 'recoveryHoldRelease':
            requirePostMutation();requireRecoveryDirection('source');$record=unmRecoveryReleaseHold((string)($_POST['replication_id']??''),(string)($_POST['hold_id']??''));reply(['success'=>true,'message'=>'The destination acknowledged recovery hold release.','recovery'=>unmRecoveryPublicRecord($record)]);
        case 'recoveryEvidence':
            requirePostMutation();requireRecoveryDirection('destination');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'evidence',['pointId'=>(string)($_POST['point_id']??''),'checkpointId'=>(string)($_POST['checkpoint_id']??''),'mode'=>(string)($_POST['mode']??''),'confirmation'=>(string)($_POST['confirmation']??''),'dnsProbe'=>(string)($_POST['dns_probe']??''),'externalProbe'=>(string)($_POST['external_probe']??'')]);reply(['success'=>true,'message'=>'Recovery evidence collection started.','operation'=>$operation]);
        case 'recoveryRenewClaim':
            requirePostMutation();requireRecoveryDirection('destination');$record=unmRecoveryRenewClaim((string)($_POST['replication_id']??''));reply(['success'=>true,'message'=>'The source renewed the exact fenced recovery claim.','recovery'=>unmRecoveryPublicRecord($record)]);
        case 'recoveryActivate':
            requirePostMutation();requireRecoveryDirection('destination');$start=filter_var($_POST['start_vm']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($start===null)throw new InvalidArgumentException('Invalid recovery start setting.');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'activate',['claimId'=>(string)($_POST['claim_id']??''),'startVm'=>$start]);reply(['success'=>true,'message'=>'Exact replica activation started.','operation'=>$operation]);
        case 'recoveryStartActivation':
            requirePostMutation();requireRecoveryDirection('destination');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'start-activation');reply(['success'=>true,'message'=>'Recovered VM start and authority revalidation started.','operation'=>$operation]);
        case 'recoveryRetryCheckpoint':
            requirePostMutation();requireRecoveryDirection('destination');$start=filter_var($_POST['start_vm']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($start===null)throw new InvalidArgumentException('Invalid recovery retry start setting.');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'retry-checkpoint',['checkpointId'=>(string)($_POST['checkpoint_id']??''),'startVm'=>$start]);reply(['success'=>true,'message'=>'Stopped activation checkpoint retry started.','operation'=>$operation]);
        case 'recoveryStopActivation':
            requirePostMutation();requireRecoveryDirection('destination');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'stop-activation');reply(['success'=>true,'message'=>'Recovered VM stop started.','operation'=>$operation]);
        case 'recoveryRemoveActivation':
            requirePostMutation();requireRecoveryDirection('destination');$operation=unmRecoveryLaunchOperation((string)($_POST['replication_id']??''),'remove-activation',['confirmation'=>(string)($_POST['confirmation']??'')]);reply(['success'=>true,'message'=>'Exact stopped activation removal started.','operation'=>$operation]);
        case 'recoveryFailback':
            requirePostMutation();requireRecoveryDirection('destination');if((string)($_POST['action']??'')!=='preflight')throw new RuntimeException('Beta2 supports cold-failback preflight only.');reply(['success'=>true,'message'=>'Cold-failback preflight completed; no transfer or authority change was started.','preflight'=>unmRecoveryColdFailbackPreflight((string)($_POST['replication_id']??''))]);
        case 'replicationPreflight':
            $opts=['rpo_seconds'=>$_POST['rpo_seconds']??3600,'retention_count'=>$_POST['retention_count']??1,'tpm_initial_mode'=>$_POST['tpm_initial_mode']??'none'];
            reply(['success'=>true,'preflight'=>unmReplicationPreflight((string)($_POST['vm_id']??''),(string)($_POST['peer_id']??''),$opts)]);
        case 'createReplication':
            requirePostMutation();
            reply(['success'=>true,'replication'=>unmCreateReplication(['vm_id'=>$_POST['vm_id']??'','peer_id'=>$_POST['peer_id']??'','rpo_seconds'=>$_POST['rpo_seconds']??3600,'retention_count'=>$_POST['retention_count']??1,'tpm_initial_mode'=>$_POST['tpm_initial_mode']??'none','enabled'=>$_POST['enabled']??true])]);
        case 'updateReplication':
            requirePostMutation();$rid=(string)($_POST['replication_id']??'');
            reply(['success'=>true,'replication'=>unmUpdateReplication($rid,['rpo_seconds'=>$_POST['rpo_seconds']??null,'retention_count'=>$_POST['retention_count']??null,'tpm_initial_mode'=>$_POST['tpm_initial_mode']??null])]);
        case 'setReplicationEnabled':
            requirePostMutation();$enabled=filter_var($_POST['enabled']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($enabled===null)throw new InvalidArgumentException('Invalid enabled state.');
            reply(['success'=>true,'replication'=>unmSetReplicationEnabled((string)($_POST['replication_id']??''),$enabled)]);
        case 'runReplicationNow':
            requirePostMutation();reply(['success'=>true,'replication'=>unmRunReplicationNow((string)($_POST['replication_id']??''))]);
        case 'removeReplication':
            requirePostMutation();unmRemoveReplication((string)($_POST['replication_id']??''));reply(['success'=>true,'destinationPreserved'=>true]);
        case 'replicationLog':
            reply(['success'=>true,'log'=>unmReplicationLog((string)($_REQUEST['replication_id']??''))]);
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
        case 'clonePreflight':
            $opts=json_decode((string)($_POST['options']??'{}'),true)?:[];
            reply(['success'=>true,'preflight'=>unmClonePreflight((string)($_POST['vm_id']??''),(string)($_POST['clone_name']??''),$opts)]);
        case 'startClone':
            $vmId=(string)($_POST['vm_id']??'');$cloneName=unmCloneName((string)($_POST['clone_name']??''));
            $opts=json_decode((string)($_POST['options']??'{}'),true)?:[];$customization=(string)($opts['guest_customization']??'ubuntu-dhcp');
            if(!in_array($customization,['ubuntu-dhcp','none-disconnected'],true))throw new InvalidArgumentException('Invalid guest customization mode.');
            $cloneUuid=unmNewUuid();$opts['clone_uuid']=$cloneUuid;$opts['guest_customization']=$customization;
            $pre=unmClonePreflight($vmId,$cloneName,$opts);if(!$pre['ready'])throw new RuntimeException(implode('; ',$pre['errors']));
            if(strtolower(trim((string)($pre['vm']['state']??'')))!=='shut off')throw new RuntimeException('Power off the source VM before cloning.');
            $vmUuid=(string)($pre['vm']['uuid']??'');if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid))throw new RuntimeException('The selected VM has no valid UUID.');
            $jobId=date('Ymd-His').'-clone-'.substr(bin2hex(random_bytes(5)),0,10);$dir=UNM_JOBS_DIR.'/'.$jobId;mkdir($dir,0700,true);
            $sourceXml=unmDomainXml($vmUuid,true);$sourceXmlPath=$dir.'/source.xml';file_put_contents($sourceXmlPath,$sourceXml,LOCK_EX);chmod($sourceXmlPath,0600);
            $sourceHash=hash('sha256',$sourceXml);if($sourceHash!==(string)($pre['clone']['sourceXmlSha256']??''))throw new RuntimeException('Source domain XML changed during clone setup.');
            $planPath=$dir.'/clone-plan.json';unmAtomicJson($planPath,$pre);
            $cfg=unmLoadConfig();$request=[
                'JOB_ID'=>$jobId,'JOB_TYPE'=>'clone','VM_UUID'=>$vmUuid,'VM_NAME'=>(string)$pre['vm']['name'],
                'CLONE_UUID'=>$cloneUuid,'CLONE_NAME'=>$cloneName,'PLAN_JSON'=>$planPath,'SOURCE_XML'=>$sourceXmlPath,'SOURCE_XML_SHA256'=>$sourceHash,
                'GUEST_CUSTOMIZATION'=>$customization,'DEST_DEDUP'=>(string)$cfg['dedup'],'DEST_COMPRESSION'=>(string)$cfg['compression'],'SOURCE_CLEANUP_ACTION'=>'unregister',
            ];
            unmWriteCfg($dir.'/request.cfg',$request);
            $job=['id'=>$jobId,'jobType'=>'clone','vm'=>(string)$pre['vm']['name'],'cloneName'=>$cloneName,'peerId'=>unmHostId(),'peerName'=>'This server: '.$cloneName,'state'=>'STARTING','progress'=>0,'message'=>'Starting local clone worker','createdAt'=>date(DATE_ATOM),'updatedAt'=>date(DATE_ATOM),'request'=>$request,'preflight'=>$pre];
            unmAtomicJson($dir.'/job.json',$job);file_put_contents($dir.'/migration.log','');chmod($dir.'/migration.log',0600);
            $pid=launchWorker($jobId,$dir,'/usr/local/sbin/unmotion-clone-worker');$job=unmLoadJson($dir.'/job.json',$job);$job['pid']=$pid;$job['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/job.json',$job);
            reply(['success'=>true,'job'=>$job]);
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
            if(($job['jobType']??'migration')==='clone')throw new RuntimeException('Local clone jobs cannot be resumed; source storage remains unchanged, so start a new clone after reviewing the log.');
            if (!in_array($job['state']??'', ['FAILED','INTERRUPTED'], true)) throw new RuntimeException('Only failed or interrupted pre-handoff jobs can be resumed automatically.');
            @unlink($dir.'/cancel.requested');
            $job['state']='STARTING'; $job['message']='Resume requested'; $job['updatedAt']=date(DATE_ATOM); unmAtomicJson($dir.'/job.json',$job);
            $pid=launchWorker($id,$dir); $job['pid']=$pid; unmAtomicJson($dir.'/job.json',$job);
            reply(['success'=>true,'pid'=>$pid]);
        case 'migrationResolution':
            requirePostMutation();
            reply(['success'=>true,'resolution'=>unmResolveMigration((string)($_POST['job_id']??''),false)]);
        case 'resolveMigration':
            requirePostMutation();
            if(($_POST['confirmation']??'')!=='finish-policy')throw new RuntimeException('Confirm completion of the original source policy.');
            reply(['success'=>true,'job'=>unmResolveMigration((string)($_POST['job_id']??''),true)]);
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
        case 'diagnosticReport':
            requirePostMutation();
            $original=filter_var($_POST['original_paths']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            $peer=filter_var($_POST['include_peer']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if($original===null||$peer===null)throw new InvalidArgumentException('Invalid diagnostic options.');
            header('Cache-Control: no-store');
            reply(['success'=>true,'report'=>unmDiagnosticReport((string)($_POST['job_id']??''),$original,null,null,$peer)]);
        default: fail('Unknown action.',404);
    }
} catch(Throwable $e) { fail($e); }
