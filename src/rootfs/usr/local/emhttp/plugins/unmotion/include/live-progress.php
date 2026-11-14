<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Display-only raw libvirt statistics. Not an overall completion percentage. */
function unmLiveProgressParse(string $text): ?array {
    if(strlen($text)>65536)return null;$v=[];
    foreach(explode("\n",$text) as $line){if(!preg_match('/\A([a-z_]+): ([0-9]+)\r?\z/D',$line,$m))continue;if(strlen($m[2])>strlen((string)PHP_INT_MAX)||strlen($m[2])===strlen((string)PHP_INT_MAX)&&strcmp($m[2],(string)PHP_INT_MAX)>0)return null;if(isset($v[$m[1]]))return null;$v[$m[1]]=(int)$m[2];}
    $out=['phase'=>'COPYING','observedAt'=>gmdate('c')];
    foreach(['disk','memory'] as $kind){$part=[];foreach(['total','processed','remaining'] as $field)if(isset($v[$kind.'_'.$field]))$part[$field.'Bytes']=$v[$kind.'_'.$field];if($part)$out[$kind]=$part;}
    if(!isset($out['disk'])&&!isset($out['memory']))return null;
    if(($v['disk_remaining']??0)>0)$out['phase']='DISK_COPY';
    elseif(($v['memory_remaining']??0)>0)$out['phase']=($v['memory_iteration']??0)>1?'RAM_CONVERGENCE':'RAM_PRECOPY';
    else $out['phase']='FINISHING_COPY';
    foreach(['time_elapsed'=>'elapsedMs','memory_iteration'=>'ramIteration','memory_dirty_rate'=>'dirtyPagesPerSecond','memory_bps'=>'memoryBytesPerSecond','disk_bps'=>'diskBytesPerSecond'] as $key=>$field)if(isset($v[$key]))$out[$field]=$v[$key];
    return $out;
}
function unmLiveProgressObserve(array $j): void {
    try{$r=unmLiveRunCommand(['env','LC_ALL=C','LANG=C','virsh','domjobinfo',$j['vmUuid'],'--rawstats'],null,2);if($r['code']!==0)return;$p=unmLiveProgressParse($r['stdout']);if($p!==null)unmNativeWrite($j['runtime'].'/progress.json',$p);}catch(Throwable){/* Retain last display sample; never fail or authorize a migration. */}
}

/** Optional display sampler. It holds no admission/VM locks or execution permits. */
function unmLiveProgressStart(array $j): ?array {
    $p=null;$identity=null;
    try{
        $script='/usr/local/sbin/unmotion-live-progress';
        $p=proc_open([$script,$j['id']],[0=>['file','/dev/null','r'],1=>['file',dirname(unmLiveJobPath($j['id'])).'/progress.log','a'],2=>['file',dirname(unmLiveJobPath($j['id'])).'/progress.log','a']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Cannot launch progress sampler.');$pid=proc_get_status($p)['pid'];
        for($i=0;$i<100;$i++){$identity=unmProcessSnapshot($pid);if($identity&&unmProcessCommandMatches($identity,$script,[$j['id']]))break;$identity=null;usleep(1000);}
        if($identity===null)throw new RuntimeException('Cannot prove progress sampler lifetime.');
        unmAtomicJson(dirname(unmLiveJobPath($j['id'])).'/progress-process.json',$identity);
        return ['handle'=>$p,'identity'=>$identity];
    }catch(Throwable){if(is_resource($p)){if($identity!==null)unmProcessStop($identity,250,true);else proc_terminate($p,15);proc_close($p);}return null;}
}
function unmLiveProgressStop(?array $child): void {
    if($child===null)return;unmProcessStop($child['identity'],250,true);proc_close($child['handle']);
}
function unmLiveProgressRun(string $id): void {
    $j=unmLiveJob($id);if($j['role']!=='source'||$j['bootId']!==unmNativeBoot())throw new RuntimeException('Invalid progress sampler binding.');
    $parent=unmProcessRecordedWorker(dirname(unmLiveJobPath($id)).'/process.json','/usr/local/sbin/unmotion-live-worker',[$id]);
    if($parent===null)throw new RuntimeException('The exact migration worker is not active.');
    $end=microtime(true)+UNM_LIVE_TRANSFER_SECONDS+UNM_LIVE_TRANSPORT_GRACE_SECONDS;
    while(microtime(true)<$end&&unmProcessIdentityMatches($parent)&&unmLiveAlive($j)){
        unmLiveProgressObserve($j);
        for($i=0;$i<12;$i++){usleep(250000);if(!unmProcessIdentityMatches($parent)||!unmLiveAlive($j))return;}
    }
}

/** These monotonic durations are display evidence, never handover authority. */
function unmLiveTimingMark(array &$trace,string $phase,int &$previous): void {
    $now=hrtime(true);$trace[$phase]=round(($now-$previous)/1e6,3);$previous=$now;
}
function unmLiveTimingStore(array $j,array $trace): void {
    try{unmNativeWrite($j['runtime'].'/handover-timing.json',['schemaVersion'=>1,'jobId'=>$j['id'],'role'=>$j['role'],'nonAuthoritative'=>true,'unit'=>'milliseconds','phases'=>$trace]);}catch(Throwable){/* Diagnostics must not change a successful handover. */}
}
