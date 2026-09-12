<?php
declare(strict_types=1);

// Post-recovery beta4: finish a manually recovered migration, never repeat the
// transfer or modify/start/stop the destination domain. Source policy is retained.
function unmResolutionDomainIdentity(string $xml): array {
    $doc=new DOMDocument();
    if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Invalid migration domain XML.');
    $xp=new DOMXPath($doc);
    $uuid=strtolower(trim($xp->evaluate('string(/domain/uuid)')));
    if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$uuid))throw new RuntimeException('Invalid migration VM UUID.');
    $disks=[];
    foreach($xp->query('/domain/devices/disk[@device="disk"]') as $disk){
        $sources=$xp->query('./source',$disk);
        if($sources->length!==1)throw new RuntimeException('Writable disk source is ambiguous.');
        $source=$sources->item(0);$type=$disk->getAttribute('type');
        if(!in_array($type,['file','block'],true))throw new RuntimeException('Unsupported writable disk source for resolution.');
        $path=$source->getAttribute($type==='file'?'file':'dev');
        if($path===''||$path[0]!=='/'||preg_match('~[\x00-\x1f\x7f]|//|/(?:\.|\.\.)(?:/|$)~',$path))throw new RuntimeException('Unsafe writable disk identity.');
        // Controller/target and passthrough corrections are legitimate manual
        // recovery edits. Bind the actual storage and its interpretation instead.
        $format=$xp->evaluate('string(./driver/@type)',$disk);
        $disks[]=$type.':'.$path.':'.$format;
    }
    if(!$disks||count($disks)!==count(array_unique($disks)))throw new RuntimeException('Missing or duplicate writable disks.');
    sort($disks,SORT_STRING);
    return ['uuid'=>$uuid,'disks'=>$disks];
}

function unmResolutionAssertDomain(string $expected,string $actual,string $uuid): void {
    $want=unmResolutionDomainIdentity($expected);$have=unmResolutionDomainIdentity($actual);
    if($want['uuid']!==$uuid||$have!==$want)throw new RuntimeException('VM UUID or writable disk paths/formats differ from this migration. Resolution was blocked.');
}

function unmResolutionXmlName(string $xml): string {
    $doc=new DOMDocument();if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Invalid migration domain XML.');
    return trim((new DOMXPath($doc))->evaluate('string(/domain/name)'));
}

function unmResolutionLock(string $path) {
    $lock=fopen($path,'c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Another unMotion operation is active; try resolving again after it finishes.');}
    return $lock;
}

// domstate failures are NOT proof that a VM is undefined: require successful
// libvirt inventory before allowing a journaled unregistration to resume.
function unmResolutionVmState(string $uuid): string {
    $r=unmRun(['virsh','domstate',$uuid],null,15);
    if($r['code']===0)return strtolower(trim($r['stdout']));
    $r=unmRun(['virsh','list','--all','--uuid'],null,15);
    if($r['code']!==0)throw new RuntimeException('Libvirt state and inventory are unavailable.');
    foreach(preg_split('/\R/',trim($r['stdout'])) as $found)if(strcasecmp(trim($found),$uuid)===0)throw new RuntimeException('VM is defined but its power state is unavailable.');
    return 'undefined';
}

function unmResolutionMarkerCheck(string $path,string $jobId,string $uuid,string $owner,array $previous=[]): void {
    if(!file_exists($path))return;
    $marker=unmLoadJson($path);
    // A recorded pre-migration marker may be superseded only if it is unchanged.
    // Callers separately constrain which previous ownership states are valid.
    if($previous&&$marker===$previous&&($marker['vmUuid']??'')===$uuid)return;
    if(!$marker||($marker['jobId']??'')!==$jobId||($marker['vmUuid']??'')!==$uuid||($marker['ownerHostId']??'')!==$owner
       ||!in_array($marker['state']??'',['owned','pending_cleanup','migrated_out_retained','migrated_out_unregistered'],true))
        throw new RuntimeException('A different or unreadable ownership record requires inspection before resolution.');
}

function unmResolutionDestination(array $request): array {
    $id=(string)($request['jobId']??'');$uuid=strtolower((string)($request['vmUuid']??''));
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/',$id)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$uuid))throw new RuntimeException('Invalid resolution identity.');
    if(($request['destinationHostId']??'')!==unmHostId()||($request['sourceHostId']??'')===unmHostId())throw new RuntimeException('Resolution host identity mismatch.');
    $policy=(string)($request['sourcePolicy']??'');if(!in_array($policy,['retain','unregister','delete'],true))throw new RuntimeException('Invalid original source policy.');
    $paired=false;foreach(unmPeers() as $peer)if(($peer['hostId']??'')===($request['sourceHostId']??null))$paired=true;
    if(!$paired)throw new RuntimeException('Resolution source is not reciprocally paired.');
    $lock=unmResolutionLock('/var/lock/unmotion-'.$uuid.'.lock');
    try{
        unmRecoveryAssertLegacyVmAvailable($uuid,'Migration resolution');
        $dir=UNM_BOOT_DIR.'/incoming/'.$id;$expectedPath=$dir.'/destination.xml';
        if(!is_file($expectedPath))throw new RuntimeException('The incoming migration XML is unavailable.');
        $expected=file_get_contents($expectedPath);
        if(!hash_equals(hash('sha256',$expected),(string)($request['destinationXmlSha256']??'')))throw new RuntimeException('Incoming migration XML does not match the source job.');
        if(!in_array(unmResolutionVmState($uuid),['running','idle'],true))throw new RuntimeException('Destination VM is not provably running. Fix startup first, then resolve again.');
        foreach([['virsh','dumpxml',$uuid],['virsh','dumpxml',$uuid,'--inactive']] as $cmd){
            $r=unmRun($cmd,null,15);if($r['code']!==0)throw new RuntimeException('Destination VM XML is unavailable.');
            unmResolutionAssertDomain($expected,$r['stdout'],$uuid);
        }
        $markerPath=UNM_OWNERSHIP_DIR.'/'.$uuid.'.json';
        $previous=(array)($request['previousDestinationOwnership']??[]);
        if(($previous['vmUuid']??'')!==$uuid||($previous['ownerHostId']??'')!==$request['sourceHostId']||!in_array($previous['state']??'',['migrated_out_retained','migrated_out_unregistered','source_deleted'],true))$previous=[];
        unmResolutionMarkerCheck($markerPath,$id,$uuid,unmHostId(),$previous);
        if(!empty($request['commit'])){
            if(!in_array(unmResolutionVmState($uuid),['running','idle'],true))throw new RuntimeException('Destination state changed during resolution.');
            $marker=['vmUuid'=>$uuid,'vmName'=>(string)($request['vmName']??''),'sourceVmName'=>(string)($request['sourceVmName']??$request['vmName']??''),'ownerHostId'=>unmHostId(),'ownerHost'=>gethostname(),'state'=>'owned','sourcePolicy'=>$policy,'jobId'=>$id,'manualResolution'=>true,'updatedAt'=>date(DATE_ATOM)];
            unmAtomicJson($markerPath,$marker);
        }
        if(!empty($request['scheduleCleanup'])){
            if(empty($request['commit'])||$policy!=='delete')throw new RuntimeException('Cleanup scheduling requires the original delete policy and committed handoff.');
            // Never reset a completed watcher after a lost acknowledgement.
            $prior=unmLoadJson($dir.'/source-cleanup-request.json');
            if($prior&&(($prior['jobId']??'')!==$id||($prior['vmUuid']??'')!==$uuid||($prior['sourceHostId']??'')!==$request['sourceHostId']||($prior['destinationHostId']??'')!==unmHostId()))throw new RuntimeException('Existing cleanup request has a different identity.');
            $pid=(int)($prior['pid']??0);$command=$pid>1?@file_get_contents('/proc/'.$pid.'/cmdline'):false;
            $watcherAlive=is_string($command)&&str_contains($command,'/usr/local/sbin/unmotion-soak-cleanup'."\0".$id."\0");
            if(!$prior||(!$watcherAlive&&in_array($prior['state']??'',['failed','scheduled','watching','validating','requesting_source_cleanup'],true))){
                unmScheduleSourceCleanup(['jobId'=>$id,'vmUuid'=>$uuid,'sourceHostId'=>$request['sourceHostId'],'destinationHostId'=>unmHostId(),'soakSeconds'=>300]);
            }elseif(($prior['state']??'')==='failed')throw new RuntimeException('The previous cleanup watcher is still exiting after a failure. Retry resolution when it finishes.');
            elseif(!in_array($prior['state']??'',['scheduled','watching','validating','requesting_source_cleanup','complete'],true))throw new RuntimeException('Unknown existing source cleanup state.');
        }
        return ['success'=>true,'hostId'=>unmHostId(),'vmUuid'=>$uuid,'jobId'=>$id,'running'=>true,'committed'=>!empty($request['commit']),'cleanupScheduled'=>!empty($request['scheduleCleanup'])];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function unmResolutionSourceCheck(string $uuid,string $expected,array $journal): string {
    $state=unmResolutionVmState($uuid);
    if($state==='undefined'){
        if(($journal['policy']??'')!=='unregister'||empty($journal['unregisterIntent']))throw new RuntimeException('Source definition is missing without a recorded unregistration intent.');
        return $state;
    }
    if($state!=='shut off')throw new RuntimeException('Source VM is not provably shut off. Never run both copies; inspect both hosts first.');
    foreach([['virsh','dumpxml',$uuid],['virsh','dumpxml',$uuid,'--inactive']] as $cmd){
        $r=unmRun($cmd,null,15);if($r['code']!==0)throw new RuntimeException('Source VM XML is unavailable.');
        unmResolutionAssertDomain($expected,$r['stdout'],$uuid);
        $name=unmResolutionXmlName($r['stdout']);
        if(!in_array($name,[unmResolutionXmlName($expected),(string)($journal['desiredName']??'')],true))throw new RuntimeException('Source VM was renamed outside this resolution; inspect it before continuing.');
    }
    return $state;
}

function unmResolutionDeleteManifest(string $dir,array $job,string $expected,string $destinationHost): array {
    $manifest=unmLoadJson($dir.'/source-cleanup.json');$uuid=$job['request']['VM_UUID'];
    foreach(['jobId'=>$job['id']??basename($dir),'vmUuid'=>$uuid,'sourceHostId'=>unmHostId(),'destinationHostId'=>$destinationHost,'policy'=>'delete'] as $key=>$value)
        if(($manifest[$key]??null)!==$value)throw new RuntimeException('A verified source cleanup manifest is unavailable. Legacy jobs cannot safely reconstruct deletion; source data will be retained.');
    $doc=new DOMDocument();$doc->loadXML($expected,LIBXML_NONET);$xp=new DOMXPath($doc);
    $nvram=trim($xp->evaluate('string(/domain/os/nvram)'));
    if(($manifest['nvramPath']??'')!==$nvram)throw new RuntimeException('Cleanup NVRAM differs from the original VM.');
    $tpm=unmTpmStatePath($uuid,$expected,false);
    if(($manifest['tpmPath']??'')!==$tpm)throw new RuntimeException('Cleanup TPM differs from the original VM.');
    unmValidateHostStateManifest((array)($manifest['hostState']??[]),$uuid,$nvram,$tpm);
    $files=[];$zvols=[];
    foreach($xp->query('/domain/devices/disk[@device="disk"]') as $disk){
        $type=$disk->getAttribute('type');$path=$xp->evaluate('string(./source/@'.($type==='file'?'file':'dev').')',$disk);
        if($type==='file')$files[]=$path;
        elseif($type==='block'&&str_starts_with($path,'/dev/zvol/'))$zvols[]=substr($path,10);
        else throw new RuntimeException('Cleanup disk is not a supported owned image or zvol.');
    }
    $ownedPaths=array_map(static fn($p)=>realpath($p)?:$p,array_merge($files,array_map(static fn($p)=>'/dev/zvol/'.$p,$zvols)));
    $inventory=unmRun(['virsh','list','--all','--uuid'],null,30);
    if($inventory['code']!==0)throw new RuntimeException('VM inventory is unavailable for cleanup ownership checks.');
    foreach(preg_split('/\R/',trim($inventory['stdout'])) as $otherUuid){
        $otherUuid=trim($otherUuid);if($otherUuid===''||strcasecmp($otherUuid,$uuid)===0)continue;
        foreach([['virsh','dumpxml',$otherUuid],['virsh','dumpxml',$otherUuid,'--inactive']] as $args){
            $r=unmRun($args,null,15);$other=new DOMDocument();
            if($r['code']!==0||!@$other->loadXML($r['stdout'],LIBXML_NONET)||$other->doctype)throw new RuntimeException('Another VM definition cannot be inspected for cleanup ownership.');
            $otherXp=new DOMXPath($other);
            foreach($otherXp->query('/domain/devices/disk/source/@file | /domain/devices/disk/source/@dev') as $node){
                if(in_array(realpath($node->value)?:$node->value,$ownedPaths,true))throw new RuntimeException('A source disk is referenced by another VM; cleanup is blocked.');
            }
        }
    }
    $covered=[];$seen=[];
    foreach((array)($manifest['storage']??[]) as $item){
        $kind=$item['kind']??'';$path=$item['path']??'';$key=$kind.':'.$path;
        if(isset($seen[$key]))throw new RuntimeException('Duplicate cleanup storage entry.');$seen[$key]=true;
        if($kind==='image'&&in_array($path,$files,true)){
            if(!str_starts_with($path,'/mnt/')||is_link($path)||!is_file($path))throw new RuntimeException('Cleanup image is not a regular owned pool file.');
            $covered[]='file:'.$path;
        }elseif($kind==='zvol'&&in_array($path,$zvols,true)){
            if(unmReplicationZvolUsedByOtherVm($path,$uuid))throw new RuntimeException('Cleanup zvol has another VM owner.');
            $covered[]='block:/dev/zvol/'.$path;
        }elseif($kind==='dataset'){
            $r=unmRun(['zfs','get','-H','-o','value','mountpoint',$path],null,15);$mount=trim($r['stdout']);
            if($r['code']!==0||!str_starts_with($mount,'/mnt/'))throw new RuntimeException('Cleanup dataset mountpoint is unavailable.');
            $members=array_values(array_filter($files,static fn($file)=>str_starts_with($file,rtrim($mount,'/').'/')));
            $allowed=$members;if($nvram!==''&&str_starts_with($nvram,rtrim($mount,'/').'/'))$allowed[]=$nvram;
            if(!$members||unmDatasetUsedByOtherVm($mount,$uuid)||!unmCloneDatasetIsolated($path,$mount,$allowed))throw new RuntimeException('Cleanup dataset no longer has provably isolated VM contents.');
            foreach($members as $file)$covered[]='file:'.$file;
        }else throw new RuntimeException('Cleanup storage differs from the original VM disks.');
    }
    $want=array_merge(array_map(static fn($p)=>'file:'.$p,$files),array_map(static fn($p)=>'block:/dev/zvol/'.$p,$zvols));sort($want);sort($covered);
    if($want!==$covered)throw new RuntimeException('Cleanup manifest does not cover exactly the original VM disks.');
    // The normal finalizer repeats host-state validation when the watcher fires.
    return $manifest;
}

function unmResolveMigration(string $id,bool $commit=false): array {
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/',$id))throw new InvalidArgumentException('Invalid job id.');
    $global=unmResolutionLock('/var/run/unmotion.lock');$vmLock=null;
    try{
        $dir=UNM_JOBS_DIR.'/'.$id;$path=$dir.'/job.json';$job=unmLoadJson($path);
        if($job&&in_array($job['state']??'',['COMPLETE_WITH_WARNINGS','COMPLETE_CLEANED'],true)&&is_array($job['manualResolution']??null)&&($job['manualResolution']['phase']??'')==='COMPLETE')return $job;
        if(!$job||($job['jobType']??'migration')!=='migration'||($job['state']??'')!=='ATTENTION_REQUIRED')throw new RuntimeException('Only attention-required migration jobs can be resolved.');
        $uuid=strtolower((string)($job['request']['VM_UUID']??''));
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$uuid))throw new RuntimeException('Missing migration VM UUID.');
        $policy=(string)($job['request']['SOURCE_CLEANUP_ACTION']??'');
        if(!in_array($policy,['retain','unregister','delete'],true))throw new RuntimeException('The original source policy is unavailable.');
        $vmLock=unmResolutionLock('/var/lock/unmotion-'.$uuid.'.lock');
        unmRecoveryAssertLegacyVmAvailable($uuid,'Migration resolution');
        foreach(['source.xml','destination.xml'] as $file)if(!is_file($dir.'/'.$file))throw new RuntimeException('Original migration XML is unavailable.');
        $expected=file_get_contents($dir.'/source.xml');$destination=file_get_contents($dir.'/destination.xml');
        $peer=unmPeer((string)$job['peerId']);$caps=unmLoadJson($dir.'/remote-capabilities.json');$recordedHost=(string)($caps['hostId']??'');
        if($recordedHost===''||$recordedHost!==($peer['hostId']??'')||$recordedHost===unmHostId())throw new RuntimeException('Paired destination identity changed or is unavailable.');
        $journalPath=$dir.'/manual-resolution.json';$journal=unmLoadJson($journalPath);
        $binding=['jobId'=>$id,'vmUuid'=>$uuid,'policy'=>$policy,'sourceHostId'=>unmHostId(),'destinationHostId'=>$recordedHost,'sourceXmlSha256'=>hash('sha256',$expected),'destinationXmlSha256'=>hash('sha256',$destination)];
        if($journal){foreach($binding as $key=>$value)if(($journal[$key]??null)!==$value)throw new RuntimeException('Resolution journal no longer matches the job.');}
        else $journal=$binding+['createdAt'=>date(DATE_ATOM),'phase'=>'PREPARED'];
        $sourceState=unmResolutionSourceCheck($uuid,$expected,$journal);
        $markerPath=UNM_OWNERSHIP_DIR.'/'.$uuid.'.json';$previousSource=unmLoadJson($dir.'/source-ownership-before.json');
        if(($previousSource['vmUuid']??'')!==$uuid||($previousSource['ownerHostId']??'')!==unmHostId()||($previousSource['state']??'')!=='owned')$previousSource=[];
        unmResolutionMarkerCheck($markerPath,$id,$uuid,$recordedHost,$previousSource);
        $manifest=$policy==='delete'?unmResolutionDeleteManifest($dir,$job,$expected,$recordedHost):null;
        $originalName=unmResolutionXmlName($expected);$peerName=(string)($job['peerName']??$peer['host']);
        if(!isset($journal['desiredName'])){
            $journal['desiredName']=$policy==='unregister'?$originalName:$originalName.($policy==='retain'?' - Migrated to ':' - Pending deletion after migration to ').$peerName;
            if($policy!=='unregister'){
                $conflict=unmRun(['virsh','domuuid',$journal['desiredName']],null,15);
                if($conflict['code']===0&&strtolower(trim($conflict['stdout']))!==$uuid)$journal['desiredName'].=' - '.substr($id,strrpos($id,'-')+1);
            }
        }
        $payload=['jobId'=>$id,'vmUuid'=>$uuid,'vmName'=>$originalName,'sourceVmName'=>$journal['desiredName'],'sourcePolicy'=>$policy,'sourceHostId'=>unmHostId(),'destinationHostId'=>$recordedHost,'destinationXmlSha256'=>hash('sha256',$destination),'previousDestinationOwnership'=>(array)($job['preflight']['conflicts']['ownershipMarker']??[]),'commit'=>false];
        $call=static function(array $payload)use($peer):array{
            $r=unmRemote($peer,'/usr/local/sbin/unmotion-agent migration-resolve '.escapeshellarg(base64_encode(json_encode($payload))),45);
            $reply=json_decode($r['stdout'],true);
            if($r['code']!==0||empty($reply['success']))throw new RuntimeException('Destination resolution check failed (both peers need beta4): '.trim((string)($reply['error']??$r['stderr'])));
            foreach(['jobId','vmUuid'] as $key)if(($reply[$key]??null)!==$payload[$key])throw new RuntimeException('Mismatched destination resolution acknowledgement.');
            if(($reply['hostId']??'')!==$payload['destinationHostId']||empty($reply['running'])||(!empty($payload['commit'])&&empty($reply['committed']))||(!empty($payload['scheduleCleanup'])&&empty($reply['cleanupScheduled'])))throw new RuntimeException('Destination did not acknowledge this exact resolution.');
            return $reply;
        };
        $call($payload);
        if(!$commit)return ['ready'=>true,'vmName'=>$originalName,'destination'=>$peerName,'sourcePolicy'=>$policy,'sourceName'=>$journal['desiredName'],'soakSeconds'=>$policy==='delete'?300:null];
        unmAtomicJson($journalPath,$journal);
        if($sourceState!=='undefined'){
            $r=unmRun(['virsh','autostart',$uuid,'--disable'],null,15);
            if($r['code']!==0||unmRecoveryNativeAutostart($uuid)!==false)throw new RuntimeException('Source autostart could not be disabled.');
            unmResolutionSourceCheck($uuid,$expected,$journal);
        }
        $localState=['retain'=>'migrated_out_retained','unregister'=>'migrated_out_unregistered','delete'=>'pending_cleanup'][$policy];
        $marker=['vmUuid'=>$uuid,'vmName'=>$originalName,'sourceVmName'=>$journal['desiredName'],'ownerHostId'=>$recordedHost,'ownerHost'=>$peerName,'state'=>$localState,'sourcePolicy'=>$policy,'jobId'=>$id,'manualResolution'=>true,'updatedAt'=>date(DATE_ATOM)];
        // Protect source before remote acknowledgement. Lost replies are retried
        // with the same binding; no source policy is silently changed.
        unmAtomicJson($markerPath,$marker);
        $payload['commit']=true;$reply=$call($payload);
        $journal['phase']='DESTINATION_ACKNOWLEDGED';$journal['destination']=$reply;unmAtomicJson($journalPath,$journal);
        $sourceState=unmResolutionSourceCheck($uuid,$expected,$journal);
        if($policy==='unregister'&&$sourceState!=='undefined'){
            $journal['unregisterIntent']=true;$journal['phase']='UNREGISTER_INTENT';unmAtomicJson($journalPath,$journal);
            $doc=new DOMDocument();$doc->loadXML($expected,LIBXML_NONET);$xp=new DOMXPath($doc);
            $args=['virsh','undefine',$uuid];
            if(trim($xp->evaluate('string(/domain/os/nvram)'))!=='')$args[]='--keep-nvram';
            if($xp->query('/domain/devices/tpm')->length)$args[]='--keep-tpm';
            $r=unmRun($args,null,30);
            if($r['code']!==0||unmResolutionVmState($uuid)!=='undefined')throw new RuntimeException('Source unregistration did not complete; storage has been retained. Resolve again after inspecting the source.');
        }elseif($policy!=='unregister'){
            $current=unmRun(['virsh','domname',$uuid],null,15);
            if($current['code']!==0)throw new RuntimeException('Source name is unavailable.');
            if(trim($current['stdout'])!==$journal['desiredName']){
                // domrename is atomic; never undefine/redefine as a fallback.
                $r=unmRun(['virsh','domrename',$uuid,$journal['desiredName']],null,30);
                if($r['code']!==0)throw new RuntimeException('The source rename failed. Resolve again when the name conflict is fixed: '.trim($r['stderr']));
            }
            $check=unmRun(['virsh','domname',$uuid],null,15);
            if($check['code']!==0||trim($check['stdout'])!==$journal['desiredName'])throw new RuntimeException('The source rename could not be verified.');
        }
        unmResolutionSourceCheck($uuid,$expected,$journal);
        if($policy!=='unregister'&&unmRecoveryNativeAutostart($uuid)!==false)throw new RuntimeException('Source autostart changed during resolution.');
        if($manifest!==null){$manifest['retainedName']=$journal['desiredName'];unmAtomicJson($dir.'/source-cleanup.json',$manifest);}
        $journal['phase']='SOURCE_POLICY_APPLIED';unmAtomicJson($journalPath,$journal);
        if($policy==='delete'){$payload['scheduleCleanup']=true;$reply=$call($payload);}
        $journal['phase']='COMPLETE';$journal['destination']=$reply;$journal['completedAt']=date(DATE_ATOM);unmAtomicJson($journalPath,$journal);
        $job['state']='COMPLETE_WITH_WARNINGS';$job['progress']=100;$job['pid']=0;
        $job['message']='Manual recovery confirmed; destination owns the running VM. '.[
            'retain'=>'Source retained as '.$journal['desiredName'].' with autostart disabled.',
            'unregister'=>'Source VM unregistered; disks, NVRAM and TPM retained.',
            'delete'=>'Source renamed and autostart disabled; deletion waits for 5 minutes of continuous destination runtime.'
        ][$policy];
        if(!empty($job['request']['WARM_SEED_ID']))$job['message'].=' Prepared Warm Move snapshots/staging were retained for inspection; resolution did not replay seed deletion.';
        $job['updatedAt']=date(DATE_ATOM);$job['manualResolution']=$journal;unmAtomicJson($path,$job);
        file_put_contents($dir.'/migration.log',date('Y-m-d H:i:s').' '.$job['message']."\n",FILE_APPEND|LOCK_EX);
        return $job;
    }finally{if(is_resource($vmLock)){flock($vmLock,LOCK_UN);fclose($vmLock);}flock($global,LOCK_UN);fclose($global);}
}
