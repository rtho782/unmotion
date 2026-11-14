<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmLiveCompletionContract(array $j): string {
    $binding=[$j['id'],$j['vmUuid'],$j['sourceHostId'],$j['destinationHostId'],unmLiveXmlHash($j['persistentXml']),unmLiveDisks($j)];
    if(unmLiveTrusted($j))$binding[]=['nativeHandoverVersion'=>1,'sshCipher'=>$j['sshCipher'],'sourceBootId'=>$j['sourceBootId']??null,'destinationBootId'=>$j['destinationBootId']??null];
    return hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));
}
function unmLiveCompletionCheck(array $receipt,array $j,string $boot,?array $qemu): bool {
    return ($receipt['schemaVersion']??null)===1&&(!unmLiveTrusted($j)||($receipt['nativeHandoverVersion']??null)===1)&&($receipt['jobId']??'')===$j['id']&&($receipt['vmUuid']??'')===$j['vmUuid']&&($receipt['bootId']??'')===$boot&&($receipt['contract']??'')===unmLiveCompletionContract($j)&&$qemu!==null&&($receipt['qemu']??null)===$qemu;
}
function unmLiveReceiveProven(array $j): bool {
    if($j['role']!=='destination')return false;$path=dirname(unmLiveJobPath($j['id'])).'/receive-complete.json';
    if(unmLiveTrusted($j)&&!file_exists($path)&&!is_link($path))return unmLiveTrustedReceiveComplete($j);
    try{if(!is_file($path)||is_link($path))return false;$r=unmNativeJson($path);if(($r['bootId']??'')!==unmNativeBoot())return false;return unmLiveCompletionCheck($r,$j,unmNativeBoot(),unmNativeQemu($j['vmUuid'],$j['vmName']));}catch(Throwable){return false;}
}
/** Called only after validated libvirt Finish3Params success, before forwarding. */
function unmLiveReceiveComplete(array $job): void {
    $j=unmLiveJob($job['id']);if($j['role']!=='destination'||$j['bootId']!==unmNativeBoot())throw new RuntimeException('Native completion belongs to another receiver boot.');unmLiveFenceProof();
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$s=unmLiveVmState($j['vmUuid']);$q=unmNativeQemu($j['vmUuid'],$j['vmName']);$a=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json');
    if(($r['jobId']??'')!==$j['id']||$r['state']!=='RECEIVING'||$s['state']!=='paused'||($a['jobId']??'')!==$j['id']||($a['phase']??'')!=='RUNNING'||($a['bootId']??'')!==unmNativeBoot()||($a['qemu']??null)!==$q)throw new RuntimeException('Native completion is not the exact admitted paused QEMU.');
    unmLiveNativeBinding(unmNativeDomain(unmDomainXml($j['vmUuid'],false)),$r);unmLiveNoJob($j['vmUuid'],$s);
    $receipt=['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'bootId'=>unmNativeBoot(),'contract'=>unmLiveCompletionContract($j),'qemu'=>$q,'observedAt'=>gmdate('c')];$path=dirname(unmLiveJobPath($j['id'])).'/receive-complete.json';unmNativeWrite($path,$receipt);unmLiveCommand(['sync','-f',$path]);
    $j['state']='RECEIVED_PAUSED';$j['message']='Native disk/RAM transfer finished; awaiting durable source retirement';unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($j['id'])]);
}
