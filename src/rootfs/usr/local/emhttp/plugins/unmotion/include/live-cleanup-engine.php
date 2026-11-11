<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmLiveCleanupRecord(string $id): ?array {
    $path=unmLiveCleanupPath($id);if(!file_exists($path)&&!is_link($path))return null;if(is_link($path))throw new RuntimeException('Cleanup journal is redirected.');$r=unmNativeJson($path);
    if(($r['schemaVersion']??null)!==1||($r['jobId']??'')!==$id||($r['vmUuid']??'')!==(unmLiveJob($id)['vmUuid']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($r['nonce']??''))||!in_array($r['state']??'',['PENDING','RUNNING','BLOCKED','COMPLETE'],true)||!is_array($r['objects']??null)||!is_array($r['items']??null)||!is_array($r['done']??null)||($r['planHash']??'')!==unmLiveCleanupHash([$r['objects'],$r['items']]))throw new RuntimeException('Cleanup journal is corrupt; retained data remains protected.');return $r;
}
function unmLiveCleanupStore(array $r): void {unmAtomicJson(unmLiveCleanupPath($r['jobId']),$r);unmLiveCommand(['sync','-f',unmLiveCleanupPath($r['jobId'])]);}
function unmLiveCleanupPublic(?array $r): ?array {
    if($r===null)return null;return ['state'=>$r['state'],'message'=>$r['message']??'','completedObjects'=>count($r['done']),'totalObjects'=>count($r['items']),'updatedAt'=>$r['updatedAt']??$r['createdAt']??null];
}
function unmLiveCleanupDerivedDone(array $r): array {
    $done=$r['done'];if(!isset($r['deleting']))return $done;$found=null;foreach($r['items'] as $item)if($item['id']===$r['deleting'])$found=$item;
    if($found===null||($r['deletingHash']??'')!==unmLiveCleanupHash($found)||!is_string($r['deletingAt']??null))throw new RuntimeException('Uncertain cleanup deletion intent; manual review required.');
    if(!isset($done[$found['id']])&&!unmLiveCleanupItemPresent($found)){
        for($p=dirname($found['path']);$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new RuntimeException('Interrupted cleanup path was redirected.');
        $done[$found['id']]=['deletedAt'=>$r['deletingAt'],'recoveredIntent'=>true];
    }return $done;
}
function unmLiveCleanupItemPresent(array $item): bool {
    if($item['kind']!=='zvol')return file_exists($item['path'])||is_link($item['path']);$ds=$item['identity']['dataset'];unmLiveVolumeContract($item['identity']);$rows=preg_split('/\R/',trim(unmLiveCommand(['zfs','list','-H','-o','name','-r',dirname($ds)])),-1,PREG_SPLIT_NO_EMPTY);if(!in_array(dirname($ds),$rows,true))throw new RuntimeException('Cannot prove retained zvol absence from its parent inventory.');return in_array($ds,$rows,true);
}
function unmLiveCleanupPeer(array $j): array {
    $peer=unmLivePeerForJob($j);$r=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);$caps=$r['code']===0?json_decode($r['stdout'],true):null;
    unmLiveCleanupRequireCapability(is_array($caps)?$caps:[],$peer['hostId']);return $peer;
}
function unmLiveCleanupRequireCapability(array $caps,string $host): void {
    if(($caps['hostId']??'')!==$host||($caps['protocolVersion']??null)!==7||($caps['liveCleanupVersion']??null)!==1)throw new RuntimeException('Retained-copy cleanup requires live cleanup capability 1 on both paired hosts. Update both before retrying; no storage was changed.');
}
function unmLiveCleanupRemote(array $peer,array $a): array {
    $action=$a['action']??'';$keys=match($action){'cleanup-owner'=>['action','jobId'],'cleanup-proof'=>['action','jobId','nonce','planHash'],'cleanup-lease'=>['action','jobId','nonce','planHash','ownerHash'],'cleanup-release'=>['action','jobId','nonce'],default=>throw new RuntimeException('Unknown live cleanup operation.')};
    if(array_diff(array_keys($a),$keys)||count($a)!==count($keys)||($peer['pairingState']??'')!=='paired')throw new RuntimeException('Invalid authenticated live cleanup fields.');$j=unmLivePeerJob($peer,(string)$a['jobId']);
    if($action==='cleanup-proof'){
        $r=unmLiveCleanupRecord($j['id']);unmLiveCleanupLocalGuard($j);
        if(!$r||$r['state']!=='RUNNING'||$r['nonce']!==$a['nonce']||$r['planHash']!==$a['planHash']||$r['bootId']!==unmNativeBoot())throw new RuntimeException('No matching current-boot cleanup intent.');
        return ['success'=>true,'hostId'=>unmHostId(),'vmUuid'=>$j['vmUuid'],'jobId'=>$j['id'],'nonce'=>$r['nonce'],'planHash'=>$r['planHash'],'nonOwner'=>true];
    }
    if($action==='cleanup-release'){
        if(!preg_match('/\A[a-f0-9]{32}\z/D',(string)$a['nonce']))throw new RuntimeException('Invalid cleanup nonce.');$lock=unmLiveVmLock($j['vmUuid']);$path=unmLiveCleanupLeasePath($j['vmUuid']);$l=unmLiveCleanupLeaseRead($j['vmUuid']);if($l===null)throw new RuntimeException('No cleanup lease was granted.');
        if(($l['peerHostId']??'')!==$peer['hostId']||($l['jobId']??'')!==$j['id']||($l['nonce']??'')!==$a['nonce'])throw new RuntimeException('Cleanup release does not own this lease.');$l['expiresAt']=0;$l['monotonicExpiresNs']=0;$l['releasedAt']=gmdate('c');unmNativeWrite($path,$l);return ['success'=>true];
    }
    if(!unmLiveCleanupTerminal((string)$j['state']))throw new RuntimeException('The paired live job is not settled.');
    $lock=unmLiveVmLock($j['vmUuid']);$owner=unmLiveCleanupOwnerProof($j);
    if($action==='cleanup-owner')return ['success'=>true,'owner'=>$owner];
    foreach(['nonce'=>32,'planHash'=>64,'ownerHash'=>64] as $k=>$n)if(!is_string($a[$k])||!preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$a[$k]))throw new RuntimeException('Invalid cleanup lease binding.');
    if(!hash_equals($owner['authorityHash'],$a['ownerHash']))throw new RuntimeException('Owner authority changed since cleanup review.');
    $path=unmLiveCleanupLeasePath($j['vmUuid']);$old=unmLiveCleanupLeaseRead($j['vmUuid']);if($old!==null&&unmLiveCleanupLeaseActive($old)&&($old['jobId']!==$j['id']||$old['peerHostId']!==$peer['hostId']||$old['nonce']!==$a['nonce']))throw new RuntimeException('Another retained-copy cleanup holds the owner lease.');
    // Callback reads the durable intent without trying to reacquire its VM lock.
    $proof=unmLiveRpc($peer,'cleanup-proof',['jobId'=>$j['id'],'nonce'=>$a['nonce'],'planHash'=>$a['planHash']]);
    foreach(['hostId'=>$peer['hostId'],'vmUuid'=>$j['vmUuid'],'jobId'=>$j['id'],'nonce'=>$a['nonce'],'planHash'=>$a['planHash'],'nonOwner'=>true] as $k=>$v)if(($proof[$k]??null)!==$v)throw new RuntimeException('Fresh non-owner cleanup callback failed.');
    $again=unmLiveCleanupOwnerProof($j);if($again!==$owner)throw new RuntimeException('Owner changed during cleanup callback.');
    $lease=['schemaVersion'=>1,'vmUuid'=>$j['vmUuid'],'jobId'=>$j['id'],'peerHostId'=>$peer['hostId'],'nonce'=>$a['nonce'],'planHash'=>$a['planHash'],'ownerHash'=>$owner['authorityHash'],'bootId'=>unmNativeBoot(),'expiresAt'=>time()+30,'monotonicExpiresNs'=>hrtime(true)+30000000000];unmNativeWrite($path,$lease);unmLiveCommand(['sync','-f',$path]);return ['success'=>true,'owner'=>$owner,'leaseSeconds'=>30,'nonce'=>$a['nonce']];
}
/** Verify the exact pinned plan again, including partially removed host state. */
function unmLiveCleanupPlanCheck(array $j,array $objects,array $items,array $done): void {
    $admitted=[];foreach(unmLiveCleanupManifest($j) as $a)$admitted[$a['id']]=$a;
    foreach($objects as $o){$expected=$o;unset($expected['observed']);if(($admitted[$o['id']]??null)!==$expected)throw new RuntimeException('Pinned cleanup objects differ from admitted identities.');}
    if(unmLiveCleanupItems($objects)!==$items)throw new RuntimeException('Pinned deletion entries differ from the exact reviewed objects.');
    $refs=unmLiveCleanupReferences();$known=[];$pendingObjects=[];foreach($items as $item){$known[$item['id']]=true;if(isset($done[$item['id']])&&unmLiveCleanupItemPresent($item))throw new RuntimeException('Previously deleted storage has reappeared; cleanup will not delete its replacement.');}
    if(array_diff(array_keys($done),array_keys($known)))throw new RuntimeException('Unknown cleanup receipt entries.');
    foreach($objects as $o){unmLiveCleanupObjectPath($o['kind'],$o['path'],$j['vmUuid']);unmLiveCleanupReferenceGuard($o,$refs);$remaining=array_values(array_filter($items,fn($i)=>$i['objectId']===$o['id']&&!isset($done[$i['id']])));if(!$remaining)continue;$pendingObjects[]=$o;
        if($o['kind']==='zvol'){unmLiveCleanupZvolGuard($o);continue;}
        if(unmLiveCleanupFileIdentity($o['path'],$o['kind']==='tpm')!==$o['identity'])throw new RuntimeException('Retained object was replaced.');unmHostStateNoNestedMounts($o['path']);
        if($o['kind']==='image'){if(unmLiveCleanupInspect($j,$o)!==$o['observed'])throw new RuntimeException('Retained image changed after review.');continue;}
        $e=unmHostStateEvidence($o['path'],$o['kind'],$j['vmUuid']);$expected=$o['observed']['evidence'];foreach($items as $i)if($i['objectId']===$o['id']&&isset($done[$i['id']])){$rel=ltrim(substr($i['path'],strlen($o['path'])),'/');unset($expected['entries'][$rel]);}
        if($e!==$expected)throw new RuntimeException('Retained host-state contents changed; unknown files are not deleted.');
    }
    unmLiveCleanupKernelGuard($pendingObjects);unmLiveCleanupOpenGuard($objects);
}
function unmLiveCleanupPreview(string $id): array {
    $j=unmLiveJob($id);$lock=unmLiveVmLock($j['vmUuid']);$old=unmLiveCleanupRecord($id);if(($old['state']??'')==='COMPLETE')return ['success'=>true,'complete'=>true,'id'=>$id,'vmName'=>$j['vmName'],'cleanup'=>unmLiveCleanupPublic($old),'objects'=>$old['objects'],'retained'=>$old['retained']??[]];
    $local=unmLiveCleanupLocalGuard($j);$peer=unmLiveCleanupPeer($j);$owner=unmLiveRpc($peer,'cleanup-owner',['jobId'=>$id])['owner']??null;if(!is_array($owner)||($owner['hostId']??'')!==$peer['hostId']||($owner['vmUuid']??'')!==$j['vmUuid'])throw new RuntimeException('The paired host did not prove current ownership.');
    if($old!==null){$objects=$old['objects'];$items=$old['items'];$done=unmLiveCleanupDerivedDone($old);$retained=$old['retained']??[];}
    else{$objects=unmLiveCleanupManifest($j,$retained);$refs=unmLiveCleanupReferences();foreach($objects as &$o){unmLiveCleanupReferenceGuard($o,$refs);$o['observed']=unmLiveCleanupInspect($j,$o);}unset($o);unmLiveCleanupOpenGuard($objects);$items=unmLiveCleanupItems($objects);$done=[];}
    unmLiveCleanupPlanCheck($j,$objects,$items,$done);$planHash=unmLiveCleanupHash([$objects,$items]);
    $review=unmLiveCleanupHash([$id,unmNativeBoot(),$local,$owner,$planHash,$done]);
    return ['success'=>true,'complete'=>false,'id'=>$id,'vmName'=>$j['vmName'],'owner'=>$owner,'review'=>$review,'planHash'=>$planHash,'objects'=>$objects,'items'=>$items,'done'=>$done,'retained'=>$retained,'allocatedBytes'=>array_sum(array_column(array_column($objects,'observed'),'allocatedBytes')),'cleanup'=>unmLiveCleanupPublic($old)];
}
function unmLiveCleanupStart(string $id,string $review): array {
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$review))throw new RuntimeException('Invalid retained-copy cleanup review.');$admission=unmTransportAdmissionLock();$j=unmLiveJob($id);$r=unmLiveCleanupRecord($id);
    if(($r['state']??'')==='COMPLETE')return ['success'=>true,'cleanup'=>unmLiveCleanupPublic($r)];
    if($r!==null){$active=unmProcessRecordedWorker(dirname(unmLiveJobPath($id)).'/cleanup-process.json','/usr/local/sbin/unmotion-live-cleanup-worker',[$id]);if($active||$r['state']==='PENDING'&&$r['bootId']===unmNativeBoot()&&time()-strtotime($r['updatedAt'])<30)throw new RuntimeException('Retained-copy cleanup is already queued or running.');}
    $view=unmLiveCleanupPreview($id);if(!$view['items'])throw new RuntimeException('No proven retained data is eligible for cleanup.');if(!hash_equals($view['review'],$review))throw new RuntimeException('Retained data, ownership or receipts changed; review cleanup again.');$lock=unmLiveVmLock($j['vmUuid']);unmLiveCleanupLocalGuard($j);
    $r=$r??['schemaVersion'=>1,'jobId'=>$id,'vmUuid'=>$j['vmUuid'],'nonce'=>bin2hex(random_bytes(16)),'objects'=>$view['objects'],'items'=>$view['items'],'planHash'=>$view['planHash'],'done'=>[],'createdAt'=>gmdate('c')];$r['retained']=$view['retained'];$r['done']=$view['done'];if(isset($r['deleting'])&&isset($r['done'][$r['deleting']]))unset($r['deleting'],$r['deletingHash'],$r['deletingAt']);$r['bootId']=unmNativeBoot();$r['state']='PENDING';$r['owner']=$view['owner'];$r['message']='Reviewed cleanup queued; native start fences and job history will be retained';$r['updatedAt']=gmdate('c');unmLiveCleanupStore($r);
    flock($lock,LOCK_UN);fclose($lock);flock($admission,LOCK_UN);fclose($admission);
    $p=unmRun('nohup setsid /usr/local/sbin/unmotion-live-cleanup-worker '.escapeshellarg($id).' >>'.escapeshellarg(dirname(unmLiveJobPath($id)).'/cleanup.log').' 2>&1 </dev/null &',null,5);if($p['code']!==0)throw new RuntimeException('Cleanup is queued but worker launch failed. Review its state before retrying.');return ['success'=>true,'cleanup'=>unmLiveCleanupPublic($r)];
}
function unmLiveCleanupDeleteItem(array $item,?callable $command=null): void {
    $command??='unmLiveCommand';
    $path=$item['path'];if($item['kind']==='zvol'){
        if(unmLiveZvolInspect($path)!==$item['identity'])throw new RuntimeException('Zvol identity changed immediately before deletion.');$command(['zfs','destroy',$item['identity']['dataset']],null,10);$command(['zpool','sync',explode('/',$item['identity']['dataset'])[0]],null,10);return;
    }
    $dir=$item['kind']==='directory';if(unmLiveCleanupFileIdentity($path,$dir)!==$item['identity'])throw new RuntimeException('File identity changed immediately before deletion.');
    if(is_array($item['stat']??null)&&array_intersect_key(lstat($path),array_flip(['size','mtime','ctime']))!==$item['stat'])throw new RuntimeException('Retained image metadata changed immediately before deletion.');
    if(isset($item['sha256'])&&!hash_equals($item['sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('Host-state content changed immediately before deletion.');
    if($dir){if(!rmdir($path))throw new RuntimeException('Unable to remove the exact empty state directory.');}
    elseif(!unlink($path))throw new RuntimeException('Unable to unlink the exact retained file.');
    $command(['sync','-f',dirname($path)],null,10);
}
function unmLiveCleanupRun(string $id): void {
    $admission=unmTransportAdmissionLock();$j=unmLiveJob($id);$lock=unmLiveVmLock($j['vmUuid']);$r=unmLiveCleanupRecord($id);if(!$r||$r['state']!=='PENDING'||$r['bootId']!==unmNativeBoot())throw new RuntimeException('No current-boot reviewed cleanup is pending.');
    $identity=unmProcessSnapshot(getmypid());if(!$identity||!unmProcessCommandMatches($identity,'/usr/local/sbin/unmotion-live-cleanup-worker',[$id]))throw new RuntimeException('Cannot prove cleanup worker lifetime.');unmAtomicJson(dirname(unmLiveJobPath($id)).'/cleanup-process.json',$identity);$peer=null;
    try{
        $r['state']='RUNNING';$r['message']='Rechecking peer ownership and the complete retained-data plan';$r['updatedAt']=gmdate('c');unmLiveCleanupStore($r);$peer=unmLiveCleanupPeer($j);unmLiveCleanupLocalGuard($j);unmLiveCleanupPlanCheck($j,$r['objects'],$r['items'],$r['done']);
        foreach($r['items'] as $item){if(isset($r['done'][$item['id']]))continue;
            $started=hrtime(true);$lease=unmLiveRpc($peer,'cleanup-lease',['jobId'=>$id,'nonce'=>$r['nonce'],'planHash'=>$r['planHash'],'ownerHash'=>$r['owner']['authorityHash']]);
            if(($lease['nonce']??'')!==$r['nonce']||($lease['leaseSeconds']??null)!==30||($lease['owner']??null)!==$r['owner'])throw new RuntimeException('Current owner did not renew the exact cleanup lease.');
            unmLiveCleanupLocalGuard($j);unmLiveCleanupPlanCheck($j,$r['objects'],$r['items'],$r['done']);$r['deleting']=$item['id'];$r['deletingHash']=unmLiveCleanupHash($item);$r['deletingAt']=gmdate('c');$r['updatedAt']=gmdate('c');unmLiveCleanupStore($r);if(hrtime(true)-$started>15000000000)throw new RuntimeException('Cleanup evidence expired before deletion; retry with fresh proof.');
            unmLiveCleanupDeleteItem($item);$r['done'][$item['id']]=['deletedAt'=>gmdate('c')];unset($r['deleting'],$r['deletingHash'],$r['deletingAt']);$r['updatedAt']=gmdate('c');$r['message']='Removed '.count($r['done']).' of '.count($r['items']).' exact retained objects; fences/history kept';unmLiveCleanupStore($r);echo $item['kind'].' '.$item['path']." removed\n";
        }
        $r['state']='COMPLETE';$r['message']='Reviewed retained data removed; native start fences and job history remain';$r['updatedAt']=gmdate('c');unmLiveCleanupStore($r);unmInventoryCache()->invalidate();
    }catch(Throwable $e){$r['state']='BLOCKED';$r['message']=$e->getMessage().' Remaining data and start fences were retained.';$r['updatedAt']=gmdate('c');unmLiveCleanupStore($r);throw $e;}
    finally{if($peer!==null)try{unmLiveRpc($peer,'cleanup-release',['jobId'=>$id,'nonce'=>$r['nonce']],10);}catch(Throwable $e){error_log('Cleanup owner lease release unconfirmed; it expires within 30 seconds: '.$e->getMessage());}}
}
