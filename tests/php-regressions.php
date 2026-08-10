<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

function expectSame(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$txt = unmParseAvahiTxtRecordList('"host_id=peer-123" "version=0.3.0-RC1" "protocol=5" "label=Squid\\032Proxy"');
expectSame('peer-123', $txt['host_id'] ?? null, 'host_id');
expectSame('0.3.0-RC1', $txt['version'] ?? null, 'version');
expectSame('5', $txt['protocol'] ?? null, 'protocol');
expectSame('Squid Proxy', $txt['label'] ?? null, 'escaped TXT value');
expectSame('Squid Proxy', unmAvahiUnescape('Squid\\032Proxy'), 'Avahi display unescape');
expectSame('Squid Proxy - Clone', unmCloneName(' Squid Proxy - Clone '), 'clone name trim');
expectSame(true, str_starts_with(unmCloneObjectStem('Squid Proxy'),'unmotion-clone-squid-proxy-'), 'clone ZFS stem');
$newUuid=unmNewUuid();
expectSame(36, strlen($newUuid), 'clone UUID length');
expectSame('4', $newUuid[14], 'clone UUID version');
expectSame(true, in_array($newUuid[19],['8','9','a','b'],true), 'clone UUID variant');
try { unmCloneName('../unsafe'); fwrite(STDERR,"Unsafe clone name was accepted.\n"); exit(1); } catch(InvalidArgumentException $expected) {}
expectSame(true, unmZfsObjectNameSafe('cache/domains/Multi Disk Mixed'), 'ZFS dataset spaces');
expectSame(true, unmZfsObjectNameSafe('cache/vm-zvols/Data Disk 2'), 'ZFS zvol spaces');
foreach (['cache','/cache/domains/vm','cache/domains/','cache/domains/../system','cache/domains/vm@snapshot',"cache/domains/vm\nother"] as $unsafeZfs) {
    expectSame(false, unmZfsObjectNameSafe($unsafeZfs), 'unsafe ZFS object ' . json_encode($unsafeZfs));
}

expectSame([300,900,1800,3600,7200,14400,21600,28800,43200,86400],unmReplicationRpoOptions(),'replication RPO notches');
$retentionMaximums=[300=>24,900=>24,1800=>24,3600=>24,7200=>12,14400=>6,21600=>4,28800=>3,43200=>2,86400=>1];
foreach($retentionMaximums as $rpo=>$maximum){
    expectSame($rpo,unmValidateReplicationRpo($rpo),'valid replication RPO '.$rpo);
    expectSame($maximum,unmReplicationMaxRetention($rpo),'retention maximum for RPO '.$rpo);
    expectSame($maximum,unmValidateReplicationRetention($rpo,$maximum),'valid retention maximum for RPO '.$rpo);
}
foreach([0,299,301,7201,172800] as $invalidRpo){
    try { unmValidateReplicationRpo($invalidRpo); fwrite(STDERR,"Invalid replication RPO was accepted: $invalidRpo\n"); exit(1); } catch(InvalidArgumentException $expected) {}
}
foreach([[43200,3],[86400,2],[3600,0],[3600,25]] as [$rpo,$retention]){
    try { unmValidateReplicationRetention($rpo,$retention); fwrite(STDERR,"Invalid retention was accepted: RPO $rpo count $retention\n"); exit(1); } catch(InvalidArgumentException $expected) {}
}

$retentionNow=strtotime('2026-08-10T17:00:00Z');
$points=[
    ['id'=>'current-early','completedAt'=>'2026-08-10T16:05:00Z'],
    ['id'=>'latest','completedAt'=>'2026-08-10T17:00:00Z'],
    ['id'=>'previous-early','completedAt'=>'2026-08-10T08:00:00Z'],
    ['id'=>'previous-newest','completedAt'=>'2026-08-10T15:59:59Z'],
    ['id'=>'oldest-early','completedAt'=>'2026-08-10T00:00:00Z'],
    ['id'=>'oldest-newest','completedAt'=>'2026-08-10T07:59:59Z'],
    ['id'=>'expired','completedAt'=>'2026-08-09T23:59:59Z'],
];
$selected=unmSelectReplicationRetentionPoints($points,3600,3,$retentionNow);
expectSame(['latest','previous-newest','oldest-newest'],array_column($selected,'id'),'UTC aligned three-point retention');

$boundaryNow=strtotime('2026-08-10T16:00:00Z');
$boundaryPoints=[
    ['id'=>'new-boundary','timestamp'=>$boundaryNow],
    ['id'=>'previous','timestamp'=>strtotime('2026-08-10T15:59:59Z')],
    ['id'=>'oldest','timestamp'=>strtotime('2026-08-10T07:59:59Z')],
    ['id'=>'exactly-24h-old','timestamp'=>strtotime('2026-08-09T16:00:00Z')],
];
expectSame(['new-boundary','previous','oldest'],array_column(unmSelectReplicationRetentionPoints($boundaryPoints,3600,3,$boundaryNow),'id'),'UTC retention boundary ownership');

$stalePoints=[
    ['id'=>'stale-latest','createdAt'=>'2026-08-08T12:00:00Z'],
    ['id'=>'stale-older','createdAt'=>'2026-08-07T12:00:00Z'],
];
expectSame(['stale-latest'],array_column(unmSelectReplicationRetentionPoints($stalePoints,3600,3,$retentionNow),'id'),'latest point survives outside 24-hour window');

$futurePoints=[
    ['id'=>'older','capturedAt'=>'2026-08-10T16:00:00Z'],
    ['id'=>'clock-skew','capturedAt'=>'2026-08-10T17:00:01Z'],
    ['id'=>'latest-valid','capturedAt'=>'2026-08-10T17:00:00Z'],
];
expectSame(['clock-skew','latest-valid','older'],array_column(unmSelectReplicationRetentionPoints($futurePoints,3600,3,$retentionNow),'id'),'future timestamp suppresses uncertain pruning');

$capturedAtWins=[
    ['id'=>'captured-newest','capturedAt'=>'2026-08-10T17:00:00Z','createdAt'=>'2026-08-10T16:00:00Z'],
    ['id'=>'created-newest','capturedAt'=>'2026-08-10T16:59:59Z','createdAt'=>'2026-08-10T17:00:01Z'],
];
expectSame('captured-newest',unmSelectReplicationRetentionPoints($capturedAtWins,3600,1,$retentionNow)[0]['id'],'retention orders by capture time rather than manifest write time');

expectSame(5,UNM_PROTOCOL,'migration protocol remains compatible');
expectSame(1,UNM_REPLICATION_PROTOCOL,'replication protocol is independently capability-gated');
$replicaUuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
expectSame(false,unmReplicaVmUuidUndefinedInInventory("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb\n".strtoupper($replicaUuid)."\n",$replicaUuid),'replica UUID collision is case insensitive');
expectSame(true,unmReplicaVmUuidUndefinedInInventory("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb\n",$replicaUuid),'unrelated destination UUID is allowed');

$holdOutput="cache/domains/Squid Proxy@unmotion-rpl-test-g1\tunmotion:replication:repl-aaaaaaaaaaaaaaaaaaaaaaaa\tSun Aug  9 16:43 2026\n";
expectSame(['unmotion:replication:repl-aaaaaaaaaaaaaaaaaaaaaaaa'],unmParseReplicaSnapshotHoldTags($holdOutput),'ZFS hold tags with spaced dataset names');

$incompatibleFallback=[
    'quality'=>'best-effort','tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>true,'nvramCaptured'=>true,'transferred'=>false,
    'safeFallback'=>['quality'=>'safe','tpmPresent'=>false,'tpmCaptured'=>false,'nvramPresent'=>true,'nvramCaptured'=>true,'transferred'=>false],
];
try { unmValidateReplicaCheckpoint($incompatibleFallback,sys_get_temp_dir()); fwrite(STDERR,"Incompatible safe TPM fallback was accepted.\n"); exit(1); } catch(InvalidArgumentException $expected) {}

$checkpointDir=sys_get_temp_dir().'/unmotion-checkpoints-'.bin2hex(random_bytes(6));
if(!mkdir($checkpointDir,0700,true))throw new RuntimeException('Unable to create checkpoint regression directory.');
$makeCheckpointArchive=static function(string $contents)use($checkpointDir):array{
    $temporary=$checkpointDir.'/pending-'.bin2hex(random_bytes(4));file_put_contents($temporary,$contents);$hash=hash_file('sha256',$temporary);$id='state-'.substr($hash,0,24);$path=$checkpointDir.'/'.$id.'.tar.gz';rename($temporary,$path);return compact('hash','id','path');
};
$safeArchive=$makeCheckpointArchive('safe checkpoint');
$fallbackArchive=$makeCheckpointArchive('fallback checkpoint');
$orphanArchive=$makeCheckpointArchive('orphan checkpoint');
try{
    $safeWithoutArchive=['quality'=>'safe','tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>false,'nvramCaptured'=>false,'transferred'=>false];
    try{unmValidateReplicaCheckpoint($safeWithoutArchive,$checkpointDir);fwrite(STDERR,"Safe checkpoint without a transferred archive was accepted.\n");exit(1);}catch(InvalidArgumentException $expected){}
    $safeWithoutCoverage=['checkpointId'=>$safeArchive['id'],'quality'=>'safe','contentSha256'=>str_repeat('a',64),'archiveSha256'=>$safeArchive['hash'],'archivePath'=>$safeArchive['path'],'tpmPresent'=>true,'tpmCaptured'=>false,'nvramPresent'=>false,'nvramCaptured'=>false,'transferred'=>true];
    try{unmValidateReplicaCheckpoint($safeWithoutCoverage,$checkpointDir);fwrite(STDERR,"Safe checkpoint without exact TPM coverage was accepted.\n");exit(1);}catch(InvalidArgumentException $expected){}
    $safeCheckpoint=['checkpointId'=>$safeArchive['id'],'quality'=>'safe','contentSha256'=>str_repeat('b',64),'archiveSha256'=>$safeArchive['hash'],'archivePath'=>$safeArchive['path'],'tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>false,'nvramCaptured'=>false,'transferred'=>true];
    expectSame('safe',unmValidateReplicaCheckpoint($safeCheckpoint,$checkpointDir)['quality'],'fully transferred safe checkpoint');
    $safeFallback=['checkpointId'=>$fallbackArchive['id'],'quality'=>'safe','contentSha256'=>str_repeat('c',64),'archiveSha256'=>$fallbackArchive['hash'],'archivePath'=>$fallbackArchive['path'],'tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>false,'nvramCaptured'=>false,'transferred'=>true];
    $retainedCheckpoint=$safeCheckpoint;$retainedCheckpoint['quality']='best-effort';$retainedCheckpoint['safeFallback']=$safeFallback;
    expectSame([$orphanArchive['id'].'.tar.gz'],unmGarbageCollectReplicaCheckpointArchives([['hostState'=>$retainedCheckpoint]],$checkpointDir),'only unreferenced owned checkpoint archive is removed');
    expectSame(true,is_file($safeArchive['path']),'retained primary checkpoint archive survives cleanup');
    expectSame(true,is_file($fallbackArchive['path']),'retained safe fallback archive survives cleanup');
    expectSame(false,file_exists($orphanArchive['path']),'unreferenced checkpoint archive is removed');
    $unsafeName=$checkpointDir.'/state-'.str_repeat('0',24).'.tar.gz';file_put_contents($unsafeName,'does not match ownership hash');
    try{unmGarbageCollectReplicaCheckpointArchives([],$checkpointDir);fwrite(STDERR,"Checkpoint archive with an invalid ownership hash was deleted.\n");exit(1);}catch(RuntimeException $expected){}
    expectSame(true,is_file($unsafeName),'unowned checkpoint-like archive is preserved');
}finally{
    foreach([$safeArchive['path'],$fallbackArchive['path'],$orphanArchive['path'],$checkpointDir.'/state-'.str_repeat('0',24).'.tar.gz'] as $path)if(is_file($path)||is_link($path))unlink($path);
    rmdir($checkpointDir);
}

echo "PHP migration and scheduled-replication regressions passed.\n";
