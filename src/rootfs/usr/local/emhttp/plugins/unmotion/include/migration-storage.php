<?php
declare(strict_types=1);

function unmMigrationAssertSourceDataset(string $dataset,string $xml,string $uuid): void {
    if(!unmZfsObjectNameSafe($dataset))throw new RuntimeException('Unsafe source dataset identity.');
    $r=unmRun(['zfs','get','-H','-o','value','mountpoint',$dataset],null,15);
    $mount=rtrim(trim($r['stdout']),'/');
    if($r['code']!==0||!str_starts_with($mount,'/mnt/'))throw new RuntimeException('Source dataset mountpoint is unavailable.');
    $doc=new DOMDocument();
    if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Original source XML is invalid.');
    $xp=new DOMXPath($doc);
    if(strcasecmp(trim($xp->evaluate('string(/domain/uuid)')),$uuid)!==0)throw new RuntimeException('Source dataset VM identity changed.');
    $files=[];
    foreach($xp->query('/domain/devices/disk[@device="disk"][@type="file"]/source/@file') as $node){
        $resolution=unmResolveUserSharePath($node->value);
        $file=(string)($resolution['physicalPath']??'');
        if($file!==''&&str_starts_with($file,$mount.'/'))$files[]=$file;
    }
    if(!$files)throw new RuntimeException('Source dataset contains no verified VM disks.');
    $nvram=trim($xp->evaluate('string(/domain/os/nvram)'));
    if($nvram!==''&&str_starts_with($nvram,$mount.'/'))$files[]=$nvram;
    if(unmDatasetUsedByOtherVm($mount,$uuid)||!unmCloneDatasetIsolated($dataset,$mount,$files))throw new RuntimeException('Source dataset contains child datasets or contents whose VM ownership cannot be proved; source data was retained.');
}

function unmMigrationSourceSnapshots(string $dataset,string $jobId): array {
    if(!unmZfsObjectNameSafe($dataset)||!preg_match('/^[A-Za-z0-9_.-]+$/D',$jobId))throw new RuntimeException('Unsafe source cleanup identity.');
    $children=unmRun(['zfs','list','-H','-r','-o','name','-t','filesystem,volume',$dataset],null,20);
    if($children['code']!==0||trim($children['stdout'])!==$dataset)throw new RuntimeException('Child datasets or unavailable ZFS inventory block source cleanup.');
    $r=unmRun(['zfs','list','-H','-r','-t','snapshot','-o','name',$dataset],null,20);
    if($r['code']!==0)throw new RuntimeException('Source snapshot inventory is unavailable; source data was retained.');
    $snapshots=array_values(array_filter(preg_split('/\R/',trim($r['stdout']))?:[]));
    foreach($snapshots as $snapshot){
        if($snapshot!==$dataset.'@unmotion-'.$jobId)throw new RuntimeException('A snapshot outside this migration blocks automatic source cleanup: '.$snapshot);
        $holds=unmRun(['zfs','holds','-H',$snapshot],null,15);
        if($holds['code']!==0||trim($holds['stdout'])!=='')throw new RuntimeException('A held or unverifiable snapshot blocks source cleanup: '.$snapshot);
    }
    return $snapshots;
}

function unmDestroyMigrationSourceObject(string $dataset,string $jobId): void {
    foreach(unmMigrationSourceSnapshots($dataset,$jobId) as $snapshot){
        $r=unmRun(['zfs','destroy',$snapshot],null,60);
        if($r['code']!==0)throw new RuntimeException('Unable to remove exact migration snapshot '.$snapshot.': '.trim($r['stderr']));
    }
    // No -r/-R: a child or snapshot added after validation must make this fail.
    for($attempt=0;$attempt<40;$attempt++){
        $r=unmRun(['zfs','destroy',$dataset],null,180);
        if($r['code']===0)return;
        // ZFS can leave a filesystem unmounted on a transient busy failure.
        // Keep readonly protection while retrying only this exact object, and
        // stop immediately if new children, snapshots or holds appear.
        unmMigrationSourceSnapshots($dataset,$jobId);
        if($attempt<39)usleep(250000);
    }
    throw new RuntimeException('Unable to remove exact source ZFS object '.$dataset.'; remaining source data was retained: '.trim($r['stderr']));
}

function unmMigrationFreezeSourceDataset(string $dataset,?string $journalPath=null): array {
    if($journalPath!==null&&file_exists($journalPath))throw new RuntimeException('Interrupted source write protection requires inspection of '.$journalPath.' before retrying cleanup.');
    $r=unmRun(['zfs','get','-H','-o','value,source','readonly',$dataset],null,15);
    $parts=explode("\t",trim($r['stdout']),2);
    if($r['code']!==0||count($parts)!==2||!in_array($parts[0],['on','off'],true))throw new RuntimeException('Source dataset write protection cannot be verified.');
    $mounted=unmRun(['zfs','get','-H','-o','value','mounted',$dataset],null,15);
    if($mounted['code']!==0||trim($mounted['stdout'])!=='yes')throw new RuntimeException('Source dataset must be mounted for final contents verification.');
    // Persist the original property source BEFORE changing it. A killed worker
    // must not lose the information needed to restore inheritance/received state.
    if($journalPath!==null)unmAtomicJson($journalPath,['dataset'=>$dataset,'originalReadonly'=>$parts[0],'originalSource'=>$parts[1],'originalMounted'=>'yes','createdAt'=>gmdate('c')]);
    if($parts[0]==='off'){
        $r=unmRun(['zfs','set','readonly=on',$dataset],null,30);
        if($r['code']!==0)throw new RuntimeException('Unable to protect source dataset during final ownership checks.');
    }
    return ['source'=>$parts[1],'value'=>$parts[0]];
}

function unmMigrationRestoreSourceDataset(string $dataset,array $previous): void {
    if(!$previous)return;
    if(unmRun(['zfs','list','-H','-o','name',$dataset],null,15)['code']!==0)throw new RuntimeException('Unable to verify retained source dataset; inspect its recorded readonly property before retrying cleanup: '.$dataset);
    if($previous['value']==='off'){
        $source=$previous['source'];
        $args=$source==='local'?['zfs','set','readonly=off',$dataset]:($source==='received'?['zfs','inherit','-S','readonly',$dataset]:['zfs','inherit','readonly',$dataset]);
        $r=unmRun($args,null,30);
        if($r['code']!==0)throw new RuntimeException('Source data was retained but its dataset remains read-only; restore its recorded readonly property after inspecting cleanup: '.$dataset);
    }
    $mounted=unmRun(['zfs','get','-H','-o','value','mounted',$dataset],null,15);
    if($mounted['code']!==0||!in_array(trim($mounted['stdout']),['yes','no'],true))throw new RuntimeException('Retained dataset mount state is uncertain; inspect the source cleanup record: '.$dataset);
    if(trim($mounted['stdout'])==='no'&&unmRun(['zfs','mount',$dataset],null,30)['code']!==0)throw new RuntimeException('Source data was retained but its dataset could not be remounted: '.$dataset);
}

function unmMigrationValidateNativePlan(string $manifestPath,string $xmlPath): void {
    $manifest=unmLoadJson($manifestPath);$xml=@file_get_contents($xmlPath);
    if(!$manifest||$xml===false)throw new RuntimeException('Native transfer plan is unavailable.');
    foreach($manifest['datasetGroups']??[] as $group)unmMigrationAssertSourceDataset((string)($group['dataset']??''),$xml,(string)($manifest['uuid']??''));
}
