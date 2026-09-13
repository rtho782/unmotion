<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner

/** Scope host health to proven transfer dependencies; unknown storage stays conservative. */
function unmMigrationHealthScope(array $plan,array $caps): array {
    $scope=['image'=>false,'iso'=>false,'zvol'=>false,'pools'=>[],'arrayDisks'=>[],'unknownStorage'=>false];
    $storage=(array)($caps['storage']??[]);
    foreach($plan as $item){
        $kind=(string)($item['kind']??'');$path=(string)($item['destination']??'');
        if($kind==='iso'&&($item['action']??'')==='remove')continue;
        if($kind==='zvol'||$kind==='zfs-filesystem'){
            $scope[$kind==='zvol'?'zvol':'image']=true;
            if(str_contains($path,'/')&&!str_starts_with($path,'/'))$scope['pools'][explode('/',$path,2)[0]]=true;
            else $scope['unknownStorage']=true;
            continue;
        }
        if(!in_array($kind,['image','zvol-to-image','iso','custom'],true)){$scope['unknownStorage']=true;continue;}
        $category=$kind==='iso'?'iso':'image';
        $retained=$kind==='iso'&&($item['action']??'')==='retain';
        if(!$retained)$scope[$category]=true;
        if($retained)$path=(string)($item['source']??'');
        $base=rtrim((string)($storage[$category.'Directory']??''),'/');
        $dataset=(string)($storage[$category.'ZfsContainingDataset']??'');
        if($base!==''&&($path===$base||str_starts_with($path,$base.'/'))&&$dataset!=='')$scope['pools'][explode('/',$dataset,2)[0]]=true;
        elseif(preg_match('~^/mnt/(disk[0-9]+)(?:/|$)~D',$path,$m))$scope['arrayDisks'][$m[1]]=true;
        else $scope['unknownStorage']=true;
    }
    return $scope;
}

/** Return error/warning/note/ignore; unknown critical codes are never suppressed. */
function unmMigrationHealthDisposition(array $issue,array $scope,bool $storageOnly,string $vmUuid): string {
    $code=(string)($issue['code']??'');$context=(array)($issue['context']??[]);
    foreach(['IMAGE_PATH_'=>'image','ISO_PATH_'=>'iso','ZVOL_DATASET_'=>'zvol'] as $prefix=>$category)
        if(str_starts_with($code,$prefix)&&empty($scope[$category]))return 'ignore';
    if($code==='VM_CRASHED'&&($context['vmUuid']??'')!==$vmUuid)return 'ignore';
    if(empty($scope['unknownStorage'])){
        if((str_starts_with($code,'ZFS_POOL_')||in_array($code,['ZFS_SCAN_ACTIVE','ZFS_DATA_ERRORS'],true))&&isset($context['pool'])&&!isset($scope['pools'][(string)$context['pool']]))return 'ignore';
        if($code==='ARRAY_DISK_FAULT'&&isset($context['disk'])){
            $disk=(string)$context['disk'];
            if(preg_match('/^disk[0-9]+$/D',$disk)&&!isset($scope['arrayDisks'][$disk]))return 'ignore';
            if(preg_match('/^parity[0-9]*$/D',$disk)&&empty($scope['arrayDisks']))return 'ignore';
        }
        if($code==='ARRAY_NOT_STARTED'&&empty($scope['arrayDisks']))return 'ignore';
        if($code==='ZFS_VERSION_MISMATCH'&&empty($scope['pools']))return 'ignore';
    }
    // Keep real host memory pressure visible, but VM-start capacity is checked separately.
    if($storageOnly&&in_array($code,['MEMORY_CRITICAL','MEMORY_LOW','MEMORY_UNKNOWN'],true))return 'note';
    if($code==='ZFS_SCAN_ACTIVE'&&stripos((string)($issue['message']??''),'scrub in progress')!==false)return 'note';
    return !empty($issue['blocksMigration'])?'error':'warning';
}

function unmMigrationResourceMessages(int $cpus,int $memoryKiB,array $resources,bool $storageOnly): array {
    $errors=[];$warnings=[];$notes=[];$cpuCount=(int)($resources['cpuOnlineCount']??0);
    $available=(int)($resources['availableBytes']??0);$total=(int)($resources['totalBytes']??0);
    if($cpuCount<=0)$errors[]='Unable to determine the destination online CPU count.';
    elseif($cpus>$cpuCount)$errors[]="Requested vCPU count ($cpus) exceeds the destination online CPU count ($cpuCount).";
    if($available<=0)$errors[]='Unable to determine destination available memory.';
    elseif($memoryKiB*1024>$available)$errors[]='Insufficient currently available RAM on the destination for the requested VM memory.';
    elseif(($available-$memoryKiB*1024)<max(1073741824,(int)($total*.05)))$warnings[]='The requested VM memory leaves little immediately available RAM on the destination.';
    if($storageOnly){
        foreach(array_merge($errors,$warnings) as $message)$notes[]=$message.' This does not prevent storage preparation; resources will be checked again at cutover.';
        $errors=[];$warnings=[];
    }
    return ['errors'=>$errors,'warnings'=>$warnings,'notes'=>$notes];
}

/** Timestamped OOM warnings expire after one hour; do not call unparseable lines recent. */
function unmRecentOomEvent(string $line,int $now): ?array {
    $line=trim($line);$stamp=false;
    if(preg_match('/^(?:<[0-9]+>[0-9]+ )?([0-9]{4}-[0-9]{2}-[0-9]{2}T\S+)/',$line,$m))$stamp=strtotime($m[1]);
    elseif(preg_match('/^([A-Z][a-z]{2}\s+[0-9]{1,2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})\s/',$line,$m)){
        $stamp=strtotime($m[1].' '.date('Y',$now));
        // Traditional syslog has no year. Only Dec->Jan needs rollover inference.
        if($stamp!==false&&$stamp>$now&&date('n',$now)==='1'&&str_starts_with($m[1],'Dec'))$stamp=strtotime($m[1].' '.((int)date('Y',$now)-1));
    }
    if($stamp===false||$stamp>$now||$now-$stamp>3600||!preg_match('/out of memory|oom-killer|killed process/i',$line))return null;
    return ['event'=>$line,'occurredAt'=>date(DATE_ATOM,$stamp),'ageSeconds'=>$now-$stamp];
}
