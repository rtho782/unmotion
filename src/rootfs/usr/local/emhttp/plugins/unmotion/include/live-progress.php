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
    try{$r=unmRun(['env','LC_ALL=C','LANG=C','virsh','domjobinfo',$j['vmUuid'],'--rawstats'],null,2);if($r['code']!==0)return;$p=unmLiveProgressParse($r['stdout']);if($p!==null)unmNativeWrite($j['runtime'].'/progress.json',$p);}catch(Throwable){/* Retain last display sample; never fail or authorize a migration. */}
}
