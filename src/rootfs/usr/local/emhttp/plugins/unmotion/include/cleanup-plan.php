<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Destructive cleanup uses a stored exact plan, never a recursive destroy. */
function unmCleanupRun(array $args): string {
    $r=unmRun($args,null,60);
    if($r['code']!==0)throw new RuntimeException('Cleanup inspection/action failed: '.implode(' ',$args).': '.trim($r['stderr']));
    return trim($r['stdout']);
}
function unmCleanupRead(string $path): array {
    if(!file_exists($path))return [];
    if(is_link($path))throw new RuntimeException('Linked cleanup records are not permitted.');
    $r=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($r))throw new RuntimeException('Invalid cleanup record.');
    return $r;
}
function unmCleanupStore(string $path,array $record): void {
    unmTransportPath($path);unmAtomicJson($path,$record);
    unmCleanupRun(['sync','-f',$path]);
}
function unmCleanupHash(array $value): string {return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function unmCleanupAssertUnreferenced(array $storage): void {
    $uuids=preg_split('/\s+/',unmCleanupRun(['virsh','list','--all','--uuid']),-1,PREG_SPLIT_NO_EMPTY);
    foreach($uuids as $uuid){
        unmTransportUuid($uuid);$doc=new DOMDocument();
        if(!$doc->loadXML(unmCleanupRun(['virsh','dumpxml',$uuid,'--inactive']),LIBXML_NONET))throw new RuntimeException('Cannot inspect VM disk references.');
        $xp=new DOMXPath($doc);
        foreach($xp->query('/domain/devices/disk/source/@file | /domain/devices/disk/source/@dev') as $node){
            $path=$node->nodeValue;$references=array_unique([$path,realpath($path)?:$path]);
            foreach($storage as $s){
                $dst=(string)$s['destination'];$mount=(string)($s['destinationMountpoint']??$s['mountpoint']??'');
                $files=[$dst];$dirs=$mount!==''?[$mount]:[];
                if(($s['kind']??'')==='zvol'){$files[]='/dev/zvol/'.$dst;$files[]='/dev/zvol/'.str_replace(' ','+',$dst);}
                // Conservative lexical FUSE aliases also protect stopped VMs
                // whose inert replica path cannot currently be resolved.
                foreach([$dst,$mount] as $target)if(str_starts_with($target,'/mnt/'))foreach(['user','user0'] as $share){$alias=preg_replace('~\A/mnt/[^/]+/~','/mnt/'.$share.'/',$target);if($target===$mount)$dirs[]=$alias;else $files[]=$alias;}
                foreach($references as $reference){if(in_array($reference,$files,true))throw new RuntimeException('A VM still references cleanup storage: '.$uuid);foreach($dirs as $dir)if($reference===$dir||str_starts_with($reference,rtrim($dir,'/').'/'))throw new RuntimeException('A VM still references cleanup storage: '.$uuid);}
            }
        }
    }
}

/** Successful parent enumeration distinguishes absence from an unavailable pool. */
function unmCleanupZfsRows(string $base): array {
    unmTransportDataset($base);if(str_contains($base,'@'))throw new RuntimeException('Cleanup enumeration requires an exact dataset, not a snapshot.');$rows=[];
    $text=unmCleanupRun(['zfs','list','-H','-p','-r','-t','filesystem,volume,snapshot','-o','name,type,guid',dirname($base)]);
    foreach(explode("\n",$text) as $line){
        $v=explode("\t",$line);if(count($v)!==3||!ctype_digit($v[2]))throw new RuntimeException('Invalid ZFS cleanup inventory.');
        if($v[0]===$base||str_starts_with($v[0],$base.'@')||str_starts_with($v[0],$base.'/'))$rows[$v[0]]=['name'=>$v[0],'type'=>$v[1],'guid'=>$v[2]];
    }
    ksort($rows);return $rows;
}
function unmCleanupSnapshotSafe(string $full,string $hold): void {
    if(unmReplicaZfsProperty($full,'clones')!=='-')throw new RuntimeException('Dependent clones block cleanup: '.$full);
    if(array_diff(unmReplicaSnapshotHoldTags($full),[$hold]))throw new RuntimeException('Foreign holds block cleanup: '.$full);
}
function unmCleanupZfsPlan(array $storage,string $pattern,string $hold,bool $discardPartial,array $knownGuids=[]): array {
    $base=unmTransportDataset((string)$storage['destination']);$rows=unmCleanupZfsRows($base);
    if(!$rows)return ['kind'=>$storage['kind'],'destination'=>$base,'rows'=>[],'hold'=>$hold,'receiveToken'=>'-'];
    if(!isset($rows[$base]))throw new RuntimeException('Missing parent for replica objects.');
    foreach($rows as $name=>$row){
        if($name===$base)continue;
        if(!str_starts_with($name,$base.'@')||$row['type']!=='snapshot'||!preg_match($pattern,substr($name,strlen($base)+1)))throw new RuntimeException('Unrelated snapshot or child dataset blocks cleanup: '.$name);
        if(!in_array($hold,unmReplicaSnapshotHoldTags($name),true)&&($knownGuids[$name]??null)!==$row['guid'])throw new RuntimeException('Snapshot name alone is not cleanup ownership: '.$name);
        unmCleanupSnapshotSafe($name,$hold);
    }
    $token=unmReplicaZfsProperty($base,'receive_resume_token');
    if($token!==''&&$token!=='-'&&!$discardPartial)throw new RuntimeException('A partial receive exists. Explicitly authorize discarding it, or retain the replicas.');
    return ['kind'=>$storage['kind'],'destination'=>$base,'rows'=>$rows,'hold'=>$hold,'receiveToken'=>$token];
}
function unmCleanupFilePlan(string $path): array {
    unmTransportPath($path);clearstatcache(true,$path);$s=@lstat($path);
    if($s===false)return ['path'=>$path,'identity'=>null];
    if(($s['mode']&0170000)!==0100000||$s['nlink']!==1)throw new RuntimeException('Cleanup requires an unlinked regular file: '.$path);
    return ['path'=>$path,'identity'=>array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']))];
}
function unmCleanupDeleteFile(array $file): void {
    $fresh=unmCleanupFilePlan($file['path']);if($fresh['identity']===null)return;
    if($fresh!==$file)throw new RuntimeException('Cleanup file identity changed: '.$file['path']);
    if(!unlink($file['path']))throw new RuntimeException('Unable to delete exact cleanup file: '.$file['path']);
}
function unmCleanupValidateBatch(array $plan): void {
    foreach((array)$plan['storage'] as $p){
        foreach(unmCleanupZfsRows($p['destination']) as $name=>$row){
            if(!isset($p['rows'][$name])||$p['rows'][$name]!==$row)throw new RuntimeException('Stored cleanup plan no longer matches ZFS identity: '.$name);
            if($name!==$p['destination'])unmCleanupSnapshotSafe($name,$p['hold']);
        }
        if(unmCleanupZfsRows($p['destination'])){
            $token=unmReplicaZfsProperty($p['destination'],'receive_resume_token');
            if($token!==''&&$token!=='-'&&$token!==$p['receiveToken'])throw new RuntimeException('Partial receive changed since cleanup was planned.');
        }
    }
    foreach((array)$plan['files'] as $file){$fresh=unmCleanupFilePlan($file['path']);if($fresh['identity']!==null&&$fresh!==$file)throw new RuntimeException('A planned cleanup file changed identity.');}
}
/** A journal must be durable BEFORE this is called; missing planned objects are retry-safe. */
function unmCleanupDeleteZfs(array $plan,callable $guard): void {
    $base=$plan['destination'];$fresh=unmCleanupZfsRows($base);$expected=$plan['rows'];
    foreach($fresh as $name=>$row)if(!isset($expected[$name])||$expected[$name]!==$row)throw new RuntimeException('ZFS cleanup identity changed: '.$name);
    if(!$fresh)return;
    foreach($fresh as $name=>$row)if($name!==$base)unmCleanupSnapshotSafe($name,$plan['hold']);
    $token=unmReplicaZfsProperty($base,'receive_resume_token');
    if($token!==''&&$token!=='-'){
        if(($plan['receiveToken']??'-')!==$token)throw new RuntimeException('Partial receive changed since cleanup review.');
        $guard();unmCleanupRun(['zfs','receive','-A',$base]);
    }
    foreach($fresh as $name=>$row){
        if($name===$base)continue;
        // Abort can remove the pending snapshot; enumerate again to prove absence.
        $current=unmCleanupZfsRows($base);if(!isset($current[$name]))continue;
        if($current[$name]!==$row)throw new RuntimeException('Snapshot identity changed immediately before cleanup.');
        unmCleanupSnapshotSafe($name,$plan['hold']);$guard();
        if(in_array($plan['hold'],unmReplicaSnapshotHoldTags($name),true))unmCleanupRun(['zfs','release',$plan['hold'],$name]);
        if(unmReplicaSnapshotHoldTags($name))throw new RuntimeException('Snapshot gained a hold.');
        $again=unmCleanupZfsRows($base);if(!isset($again[$name])||$again[$name]!==$row)throw new RuntimeException('Snapshot changed after hold release.');
        $guard();unmCleanupRun(['zfs','destroy',$name]);
    }
    $last=unmCleanupZfsRows($base);
    if($last&&($last!==[$base=>$expected[$base]]))throw new RuntimeException('New objects block final dataset removal.');
    if($last){$guard();unmCleanupRun(['zfs','destroy',$base]);}
}
function unmCleanupSourceSnapshots(array $items,string $pattern,string $hold,array $knownGuids=[]): array {
    $plans=[];
    foreach($items as $item){
        $source=unmTransportDataset((string)$item['source']);
        foreach(unmCleanupZfsRows($source) as $name=>$row){
            if(!str_starts_with($name,$source.'@')||!preg_match($pattern,substr($name,strlen($source)+1)))continue;
            if(!in_array($hold,unmReplicaSnapshotHoldTags($name),true)&&($knownGuids[$name]??null)!==$row['guid'])throw new RuntimeException('Source snapshot has no exact hold or recorded GUID proving ownership: '.$name);
            unmCleanupSnapshotSafe($name,$hold);$plans[$name]=$row+['hold'=>$hold,'source'=>$source];
        }
    }
    ksort($plans);return $plans;
}
function unmCleanupDeleteSourceSnapshots(array $plans): void {
    // Validate the complete remaining source plan before releasing ANY hold.
    foreach($plans as $name=>$p){$rows=unmCleanupZfsRows($p['source']);if(!isset($rows[$name]))continue;if($rows[$name]['guid']!==$p['guid'])throw new RuntimeException('Source snapshot identity changed.');unmCleanupSnapshotSafe($name,$p['hold']);}
    foreach($plans as $name=>$p){
        $rows=unmCleanupZfsRows($p['source']);if(!isset($rows[$name]))continue;
        if($rows[$name]['guid']!==$p['guid'])throw new RuntimeException('Source snapshot identity changed.');
        unmCleanupSnapshotSafe($name,$p['hold']);
        if(in_array($p['hold'],unmReplicaSnapshotHoldTags($name),true))unmCleanupRun(['zfs','release',$p['hold'],$name]);
        if(unmReplicaSnapshotHoldTags($name))throw new RuntimeException('Source snapshot gained a hold.');
        $again=unmCleanupZfsRows($p['source']);if(!isset($again[$name])||$again[$name]['guid']!==$p['guid'])throw new RuntimeException('Source snapshot changed after hold release.');
        unmCleanupRun(['zfs','destroy',$name]);
    }
}
