<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

function expectSame(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function expectContains(string $needle, string $haystack, string $label): void {
    expectSame(true, str_contains($haystack, $needle), $label);
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

$recoveryRetentionPoints=[
    ['id'=>'new-replication-only','capturedAt'=>'2026-08-10T17:00:00Z','recoveryEligible'=>false,'recoveryCandidate'=>false],
    ['id'=>'older-safe','capturedAt'=>'2026-08-10T16:59:00Z','recoveryEligible'=>true,'recoveryCandidate'=>true],
];
expectSame(['new-replication-only'],array_column(unmSelectReplicationRetentionPointsWithRecoverySafety($recoveryRetentionPoints,3600,1,false,$retentionNow),'id'),'unarmed retention remains exact');
expectSame(['new-replication-only','older-safe'],array_column(unmSelectReplicationRetentionPointsWithRecoverySafety($recoveryRetentionPoints,3600,1,true,$retentionNow),'id'),'armed retention preserves one eligible safety point');
$selectedAlreadySafe=$recoveryRetentionPoints;$selectedAlreadySafe[0]['recoveryEligible']=true;$selectedAlreadySafe[0]['recoveryCandidate']=true;
expectSame(['new-replication-only'],array_column(unmSelectReplicationRetentionPointsWithRecoverySafety($selectedAlreadySafe,3600,1,true,$retentionNow),'id'),'armed retention adds no point when the retained set is eligible');
$repairableRetentionPoints=[
    ['id'=>'new-replication-only','capturedAt'=>'2026-08-10T17:00:00Z','recoveryEligible'=>false,'recoveryCandidate'=>false],
    ['id'=>'older-repairable','capturedAt'=>'2026-08-10T16:58:00Z','recoveryEligible'=>false,'recoveryCandidate'=>true],
];
expectSame(['new-replication-only','older-repairable'],array_column(unmSelectReplicationRetentionPointsWithRecoverySafety($repairableRetentionPoints,3600,1,true,$retentionNow),'id'),'armed retention fails safe by preserving a published repairable candidate');

expectSame(5,UNM_PROTOCOL,'migration protocol remains compatible');
expectSame(5,UNM_PROTOCOL_MIN,'beta2 protocol range minimum remains v5');
expectSame(6,UNM_PROTOCOL_MAX,'beta2 advertises protocol 6 only for recovery negotiation');
expectSame(1,UNM_REPLICATION_PROTOCOL,'replication protocol is independently capability-gated');
expectSame(['min'=>5,'max'=>5],unmProtocolRange(['protocolVersion'=>5]),'legacy v5 capability range');
expectSame(5,unmNegotiateProtocol(['protocolMinVersion'=>5,'protocolMaxVersion'=>5],['protocolMinVersion'=>5,'protocolMaxVersion'=>6]),'future range negotiates down to v5');
expectSame(null,unmNegotiateProtocol(['protocolMinVersion'=>5,'protocolMaxVersion'=>5],['protocolMinVersion'=>6,'protocolMaxVersion'=>6]),'v6-only peer is incompatible until local v6 support exists');
expectSame(6,unmNegotiateProtocol(['protocolMinVersion'=>5,'protocolMaxVersion'=>6],['protocolMinVersion'=>6,'protocolMaxVersion'=>6]),'future peers select the highest common protocol');
try{unmProtocolRange(['protocolMinVersion'=>6,'protocolMaxVersion'=>5]);fwrite(STDERR,"Invalid peer protocol range was accepted.\n");exit(1);}catch(InvalidArgumentException $expected){}
expectSame(1,UNM_RECOVERY_PROTOCOL,'recovery protocol is independently capability-gated');
expectSame(false,unmRecoveryProtocolAvailable(['protocolVersion'=>5,'protocolMinVersion'=>5,'protocolMaxVersion'=>5,'recoveryProtocolVersion'=>1,'features'=>['recoveryControlPlane'=>true]]),'legacy v5 peer remains replication-only');
expectSame(true,unmRecoveryProtocolAvailable(['protocolVersion'=>5,'protocolMinVersion'=>5,'protocolMaxVersion'=>6,'recoveryProtocolVersion'=>1,'features'=>['recoveryControlPlane'=>true]]),'v6 range plus recovery feature enables recovery only');
expectSame(false,unmRecoveryProtocolAvailable(['protocolVersion'=>5,'protocolMinVersion'=>5,'protocolMaxVersion'=>6,'recoveryProtocolVersion'=>1,'features'=>['recoveryControlPlane'=>false]]),'v6 range without the recovery feature remains fail closed');
expectSame(true,unmRecoveryTransitionAllowed('REPLICATION_ONLY','ARMING'),'recovery arming transition');
expectSame(false,unmRecoveryTransitionAllowed('REPLICATION_ONLY','RECOVERED_RUNNING'),'recovery cannot skip authority transitions');
expectSame(true,unmRecoveryTransitionAllowed('STANDBY','SPLIT_BRAIN_SUSPECTED'),'split-brain suspicion is reachable from every armed state');
expectSame(['10.0.0.20','2001:db8::20'],unmRecoveryGuestIps(['interfaces'=>[['addresses'=>[['address'=>'10.0.0.20/24'],['address'=>'0.0.0.0'],['address'=>'127.0.0.1'],['address'=>'169.254.2.3'],['address'=>'224.0.0.1'],['address'=>'2001:db8::20/64'],['address'=>'::'],['address'=>'::1'],['address'=>'fe80::1'],['address'=>'ff02::1']]]]]),'QGA network metadata excludes unspecified, loopback, link-local, and multicast probe addresses');
$evidenceNow=2000000000;
$goodEvidence=static fn(int $at):array=>['at'=>$at,'sourceSilent'=>true,'guestSilent'=>true,'gatewayReachable'=>true,'dnsResolvable'=>true];
expectSame(true,unmRecoveryEvidenceReady([$goodEvidence($evidenceNow-35),$goodEvidence($evidenceNow-20),$goodEvidence($evidenceNow-5)],$evidenceNow),'three good evidence samples spanning thirty seconds');
expectSame(false,unmRecoveryEvidenceReady([$goodEvidence($evidenceNow-25),$goodEvidence($evidenceNow-15),$goodEvidence($evidenceNow-5)],$evidenceNow),'evidence window cannot be shortened');
$failedEvidence=$goodEvidence($evidenceNow-18);$failedEvidence['guestSilent']=false;
expectSame(false,unmRecoveryEvidenceReady([$goodEvidence($evidenceNow-50),$goodEvidence($evidenceNow-35),$failedEvidence,$goodEvidence($evidenceNow-10),$goodEvidence($evidenceNow-5)],$evidenceNow),'a failed probe resets the evidence window rather than being filtered out');
$recoveryPoints=[
    ['id'=>'point-old','capturedAt'=>'2026-08-10T08:00:00Z','hostState'=>['checkpointId'=>'state-'.str_repeat('a',24),'quality'=>'safe','capturedAt'=>'2026-08-10T08:00:00Z','tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>true,'nvramCaptured'=>true,'transferred'=>true]],
    ['id'=>'point-selected','capturedAt'=>'2026-08-10T16:00:00Z','hostState'=>['checkpointId'=>'state-'.str_repeat('b',24),'quality'=>'best-effort','capturedAt'=>'2026-08-10T16:00:00Z','tpmPresent'=>true,'tpmCaptured'=>true,'nvramPresent'=>true,'nvramCaptured'=>true,'transferred'=>true]],
];
$recommendation=unmRecoveryRecommendCheckpoint($recoveryPoints,'point-selected');
expectSame('safe',$recommendation['recommended']['quality']??null,'safe TPM checkpoint outranks exact best-effort checkpoint');
expectSame('point-old',$recommendation['recommended']['pointId']??null,'closest earlier safe TPM checkpoint is recommended');
$canonicalA=unmRecoveryCanonicalJson(['z'=>1,'nested'=>['b'=>2,'a'=>1],'list'=>[['b'=>2,'a'=>1]]]);
$canonicalB=unmRecoveryCanonicalJson(['list'=>[['a'=>1,'b'=>2]],'nested'=>['a'=>1,'b'=>2],'z'=>1]);
expectSame($canonicalA,$canonicalB,'recovery RPC canonical JSON is stable across associative key order');
$rpcSecret=str_repeat('ab',32);$rpcEnvelope=['requestId'=>'req-'.str_repeat('1',24),'nonce'=>str_repeat('2',32),'payload'=>['term'=>7,'state'=>'STANDBY']];$signedRpc=unmRecoverySignEnvelope($rpcEnvelope,$rpcSecret);$rpcSignature=$signedRpc['signature'];unset($signedRpc['signature']);
expectSame(hash_hmac('sha256',unmRecoveryCanonicalJson($signedRpc),$rpcSecret),$rpcSignature,'recovery RPC envelope HMAC covers canonical content');
$tamperedRpc=$signedRpc;$tamperedRpc['payload']['term']=8;expectSame(false,hash_equals($rpcSignature,hash_hmac('sha256',unmRecoveryCanonicalJson($tamperedRpc),$rpcSecret)),'tampered recovery RPC envelope is rejected');
$responseRequest=['requestId'=>'req-'.str_repeat('6',24)];$response=['success'=>true,'requestId'=>$responseRequest['requestId'],'hostId'=>'expected-host','bootId'=>'boot','recoveryProtocolVersion'=>1];$response['responseSignature']=hash_hmac('sha256',unmRecoveryCanonicalJson($response),$rpcSecret);
expectSame('expected-host',unmRecoveryVerifyResponse($response,$responseRequest,$rpcSecret,'expected-host')['hostId'],'signed RPC response binds expected host and recovery protocol');
try{unmRecoveryVerifyResponse($response,$responseRequest,$rpcSecret,'wrong-host');fwrite(STDERR,"Signed response from wrong host was accepted.\n");exit(1);}catch(RuntimeException $expected){}
$bootstrapPeer=['id'=>'peer-destination','reciprocalPeerId'=>'peer-source','hostId'=>'source-host'];$bootstrap=['sourceHostId'=>'source-host','destinationHostId'=>'destination-host','senderHostId'=>'source-host','senderPeerId'=>'peer-source','recipientPeerId'=>'peer-destination'];
expectSame(true,unmRecoveryBootstrapIdentityMatches($bootstrap,$bootstrapPeer,'destination-host'),'recovery bootstrap binds both host and reciprocal peer identities');
$wrongBootstrap=$bootstrap;$wrongBootstrap['senderPeerId']='another-peer';expectSame(false,unmRecoveryBootstrapIdentityMatches($wrongBootstrap,$bootstrapPeer,'destination-host'),'wrong reciprocal peer cannot bootstrap recovery signing');
$wrongBootstrap=$bootstrap;$wrongBootstrap['sourceHostId']='other-source';expectSame(false,unmRecoveryBootstrapIdentityMatches($wrongBootstrap,$bootstrapPeer,'destination-host'),'wrong source host cannot bootstrap recovery signing');
$bootstrapNonce=str_repeat('3',32);$bootstrapHash=str_repeat('4',64);expectSame('new',unmRecoveryBootstrapReplayDecision([],$bootstrapNonce,$bootstrapHash),'new recovery bootstrap nonce');expectSame('idempotent',unmRecoveryBootstrapReplayDecision([$bootstrapNonce=>$bootstrapHash],$bootstrapNonce,$bootstrapHash),'exact recovery bootstrap replay is idempotent');expectSame('conflict',unmRecoveryBootstrapReplayDecision([$bootstrapNonce=>$bootstrapHash],$bootstrapNonce,str_repeat('5',64)),'changed recovery bootstrap replay is rejected');
$signatureDir=sys_get_temp_dir().'/unmotion-signature-'.bin2hex(random_bytes(6));if(!mkdir($signatureDir,0700,true))throw new RuntimeException('Unable to create recovery signature test directory.');
try{
    $signatureKey=$signatureDir.'/id_ed25519';$generated=unmRun(['ssh-keygen','-q','-t','ed25519','-N','','-f',$signatureKey],null,15);if($generated['code']!==0)throw new RuntimeException('ssh-keygen is required for recovery bootstrap regressions: '.$generated['stderr']);$signaturePublic=trim((string)file_get_contents($signatureKey.'.pub'));$signatureMessage=unmRecoveryCanonicalJson(['sourceHostId'=>'source-host','destinationHostId'=>'destination-host','nonce'=>$bootstrapNonce]);$detached=unmRecoverySshSign($signatureMessage,$signatureKey,'source-host');
    expectSame(true,unmRecoverySshVerify($signatureMessage,$detached,$signaturePublic,'source-host'),'recovery bootstrap detached SSH signature');
    expectSame(false,unmRecoverySshVerify($signatureMessage.'tampered',$detached,$signaturePublic,'source-host'),'tampered recovery bootstrap detached signature is rejected');
    expectSame(false,unmRecoverySshVerify($signatureMessage,$detached,$signaturePublic,'other-source'),'recovery bootstrap signature is bound to the expected host identity');
}finally{foreach([$signatureDir.'/id_ed25519',$signatureDir.'/id_ed25519.pub'] as $path)if(is_file($path))unlink($path);rmdir($signatureDir);}
$invariantIdentity=['schemaVersion'=>1,'protocolVersion'=>6,'recoveryProtocolVersion'=>1,'replicationId'=>'repl-'.str_repeat('a',24),'vmUuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','sourceHostId'=>'source-host','destinationHostId'=>'destination-host','term'=>1,'authority'=>'SOURCE','state'=>'STANDBY','armed'=>true,'managedAutostart'=>true,'desiredAutostart'=>true,'side'=>'source','requests'=>[]];
expectSame([],unmRecoveryRecordInvariantErrors($invariantIdentity),'valid armed source standby recovery record');
$badInvariant=$invariantIdentity;$badInvariant['term']=0;expectSame(true,in_array('An armed recovery policy requires a positive authority term.',unmRecoveryRecordInvariantErrors($badInvariant),true),'armed recovery record cannot use term zero');
$badInvariant=$invariantIdentity;$badInvariant['authority']='DESTINATION';expectSame(true,in_array('Armed STANDBY requires source authority.',unmRecoveryRecordInvariantErrors($badInvariant),true),'standby cannot name destination authority');
$replicaUuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
expectSame(false,unmReplicaVmUuidUndefinedInInventory("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb\n".strtoupper($replicaUuid)."\n",$replicaUuid),'replica UUID collision is case insensitive');
expectSame(true,unmReplicaVmUuidUndefinedInInventory("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb\n",$replicaUuid),'unrelated destination UUID is allowed');

expectSame(['evidence','activate','start-activation','retry-checkpoint','stop-activation','remove-activation'],unmRecoveryOperationTypes(),'recovery worker operation whitelist is exact');
expectSame(['coordinated'],unmRecoveryEvidenceModes(),'only coordinated recovery evidence is advertised');
expectSame('coordinated',unmRecoveryValidateEvidenceMode('coordinated'),'coordinated evidence mode is accepted');
try{unmRecoveryValidateEvidenceMode('two-host');fwrite(STDERR,"Two-host recovery evidence was not fail closed.\n");exit(1);}catch(RuntimeException $expected){}
try{unmRecoveryValidateEvidenceMode('invented');fwrite(STDERR,"Unknown recovery evidence mode was accepted.\n");exit(1);}catch(InvalidArgumentException $expected){}
expectSame('arm-'.str_repeat('a',24),unmRecoveryTransactionId('arm','arm-'.str_repeat('a',24)),'arm transaction id validation');
try{unmRecoveryTransactionId('arm','disarm-'.str_repeat('a',24));fwrite(STDERR,"Mismatched recovery transaction prefix was accepted.\n");exit(1);}catch(InvalidArgumentException $expected){}
$retryFlags=unmRecoveryActionFlags(['state'=>'FENCED','armed'=>true,'authority'=>'UNKNOWN','armTransactionId'=>'arm-'.str_repeat('a',24),'desiredAutostart'=>true],'source');
expectSame(true,$retryFlags['canArm']??false,'reply-loss arm transaction remains resumable');
expectSame(true,$retryFlags['canResumeArm']??false,'reply-loss arm transaction is explicit in status');
$retryFlags=unmRecoveryActionFlags(['state'=>'FENCED','armed'=>true,'authority'=>'UNKNOWN','disarmTransactionId'=>'disarm-'.str_repeat('b',24)],'source');
expectSame(true,$retryFlags['canDisarm']??false,'reply-loss disarm transaction remains resumable');
$retryFlags=unmRecoveryActionFlags(['state'=>'HOLDOFF','armed'=>true,'authority'=>'SOURCE','hold'=>['holdId'=>'hold-'.str_repeat('c',24),'holdUntil'=>'forever','acknowledged'=>false]],'source');
expectSame(true,$retryFlags['canHold']??false,'unacknowledged durable hold remains resumable');
$expiredClaimFlags=unmRecoveryActionFlags(['state'=>'RECOVERY_READY','armed'=>true,'authority'=>'DESTINATION','claim'=>['evidenceReady'=>true,'expiresAt'=>gmdate('c',time()-60)]],'destination');
expectSame(true,$expiredClaimFlags['canRenewClaim']??false,'expired exact coordinated claim exposes authenticated renewal');
expectSame(false,$expiredClaimFlags['canActivate']??true,'expired claim cannot activate before renewal');
$stoppedActivationFlags=unmRecoveryActionFlags(['state'=>'RECOVERED_STOPPED','armed'=>true,'authority'=>'DESTINATION','activation'=>['vmState'=>'shut off']],'destination');
expectSame(true,$stoppedActivationFlags['canStartActivation']??false,'stopped destination activation exposes guarded restart');
$runningActivationFlags=unmRecoveryActionFlags(['state'=>'RECOVERED_STOPPED','armed'=>true,'authority'=>'DESTINATION','activation'=>['vmState'=>'running']],'destination');
expectSame(false,$runningActivationFlags['canStartActivation']??true,'running destination activation cannot launch a second start');
expectSame(false,unmRecoverySourcePolicyActive(['state'=>'REPLICATION_ONLY','armed'=>false]),'replication-only source policy is globally inactive');
expectSame(true,unmRecoverySourcePolicyActive(['state'=>'DISARMING','armed'=>true]),'transitional source policy holds global VM authority');
$solePolicy='repl-'.str_repeat('d',24);$otherPolicy='repl-'.str_repeat('e',24);expectSame(true,unmRecoveryPolicySetIsSole([$solePolicy],$solePolicy),'one active destination policy is sole VM authority');expectSame(false,unmRecoveryPolicySetIsSole([$solePolicy,$otherPolicy],$solePolicy),'two destination policies for one VM fail closed');expectSame(false,unmRecoveryPolicySetIsSole([$otherPolicy],$solePolicy),'another active policy blocks the requested VM authority');
$grantActivation='act-'.str_repeat('7',24);$grantSelection=str_repeat('8',64);$grantTransaction='grant-'.str_repeat('9',24);$grantPayload=['proposedTerm'=>4,'activationId'=>$grantActivation,'pointId'=>'point-exact','checkpointId'=>'checkpoint-exact','selectionHash'=>$grantSelection,'grantTransactionId'=>$grantTransaction];$grantRecord=['term'=>4,'authority'=>'DESTINATION','state'=>'SOURCE_START_FENCED','activationId'=>$grantActivation,'claim'=>['activationId'=>$grantActivation,'pointId'=>'point-exact','checkpointId'=>'checkpoint-exact','selectionHash'=>$grantSelection,'grantTransactionId'=>$grantTransaction]];
expectSame(true,unmRecoveryGrantMatches($grantRecord,$grantPayload,3),'coordinated grant reply-loss retry matches exact committed authority');$grantPayload['selectionHash']=str_repeat('a',64);expectSame(false,unmRecoveryGrantMatches($grantRecord,$grantPayload,3),'coordinated grant reconciliation rejects changed selection');

$selectionPoint=['id'=>'point-1','generation'=>1,'snapshot'=>'snap-1','capturedAt'=>'2026-08-10T17:00:00Z','storage'=>[['destination'=>'tank/Squid Proxy','snapshot'=>'snap-1','guid'=>'123']],'sourceXmlSha256'=>str_repeat('d',64)];
$selectionCheckpoint=['checkpointId'=>'state-'.str_repeat('e',24),'archiveSha256'=>str_repeat('f',64)];
$selectionA=unmRecoverySelectionHash('repl-'.str_repeat('1',24),$selectionPoint,$selectionCheckpoint,'act-'.str_repeat('2',24));
$tamperedCheckpoint=$selectionCheckpoint;$tamperedCheckpoint['archiveSha256']=str_repeat('0',64);
expectSame(false,hash_equals($selectionA,unmRecoverySelectionHash('repl-'.str_repeat('1',24),$selectionPoint,$tamperedCheckpoint,'act-'.str_repeat('2',24))),'selection hash binds exact checkpoint archive');
$tamperedPoint=$selectionPoint;$tamperedPoint['storage'][0]['guid']='124';
expectSame(false,hash_equals($selectionA,unmRecoverySelectionHash('repl-'.str_repeat('1',24),$tamperedPoint,$selectionCheckpoint,'act-'.str_repeat('2',24))),'selection hash binds immutable point material');
$bindingUuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$bindingNvram='/etc/libvirt/qemu/nvram/'.$bindingUuid.'_VARS.fd';$binding=unmRecoveryHostStateBinding('<domain><uuid>'.$bindingUuid.'</uuid><os><nvram>'.$bindingNvram.'</nvram></os></domain>',$bindingUuid);unmRecoveryAssertCheckpointDestinations([$bindingNvram=>'/stage/nvram'],[],$binding,true,false);
try{unmRecoveryAssertCheckpointDestinations(['/etc/libvirt/qemu/nvram/'.$bindingUuid.'_OTHER.fd'=>'/stage/nvram'],[],$binding,true,false);fwrite(STDERR,"Checkpoint NVRAM path mismatch was accepted.\n");exit(1);}catch(RuntimeException $expected){}

$publicPeer=unmPublicPeer(['id'=>'peer-1','name'=>'DEV02','host'=>'dev02','port'=>22,'hostId'=>'host-2','lastCapabilities'=>['protocolMaxVersion'=>6,'nested'=>['recoverySecret'=>str_repeat('c',64),'bootstrapSignature'=>'nested-secret']],'recoverySecret'=>str_repeat('a',64),'pendingRecoveryBootstrap'=>['secret'=>str_repeat('b',64),'request'=>['bootstrapSignature'=>'secret-signature']],'keyPath'=>'/secret/key','knownHosts'=>'/secret/known','incomingPublicKey'=>'ssh-ed25519 secret']);
expectSame(['id','name','host','port','hostId','lastCapabilities'],array_keys($publicPeer),'public peer projection is an explicit non-secret whitelist');
expectSame(false,str_contains(json_encode($publicPeer,JSON_THROW_ON_ERROR),'recoverySecret'),'public peer output excludes recovery secret');
expectSame(false,str_contains(json_encode($publicPeer,JSON_THROW_ON_ERROR),'pendingRecoveryBootstrap'),'public peer output excludes pending bootstrap material');
expectSame(false,str_contains(json_encode($publicPeer,JSON_THROW_ON_ERROR),'bootstrapSignature'),'public peer output recursively excludes bootstrap signatures');

$apiSource=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
foreach(['recoveryArm'=>'source','recoveryDisarm'=>'source','recoveryHold'=>'source','recoveryHoldRelease'=>'source','recoveryEvidence'=>'destination','recoveryRenewClaim'=>'destination','recoveryActivate'=>'destination','recoveryStartActivation'=>'destination','recoveryRetryCheckpoint'=>'destination','recoveryStopActivation'=>'destination','recoveryRemoveActivation'=>'destination','recoveryFailback'=>'destination'] as $route=>$direction){
    if(!preg_match("~case '".preg_quote($route,'~')."':(.*?)(?=\\n\\s*case ')~s",$apiSource,$routeMatch)){fwrite(STDERR,"Recovery mutation route is missing: $route\n");exit(1);}expectContains('requirePostMutation();',$routeMatch[1],$route.' retains CSRF-protected POST enforcement');expectContains("requireRecoveryDirection('$direction');",$routeMatch[1],$route.' enforces host direction');
}
expectContains("unmPublicPeers(unmPeers())",$apiSource,'peer list API uses secret-redacted projection');
expectSame(false,(bool)preg_match("~case 'peers':[^\\n]*unmPeers\\(\\)\\]~",$apiSource),'peer list API never returns raw peer records');
$agentSource=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-agent');
foreach(['recovery-key-install','recovery-legacy-interlock','recovery-arm','recovery-disarm','recovery-hold','recovery-hold-release','recovery-status','recovery-fence-status','recovery-grant','recovery-claim-renew','recovery-activation-commit','recovery-failback-preflight'] as $command)expectContains("case '$command':",$agentSource,'agent dispatch exposes '.$command);
$libSource=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
expectContains('unmRecoveryAcquireLock($replicationId)',$libSource,'recovery operation launch is serialized under the policy lock');
expectContains("LOCK_EX|LOCK_NB",$libSource,'recovery worker takes a non-overlapping process lock');
expectContains('hash_equals($expectedOperationId,(string)($request[\'operationId\']??\'\'))',$libSource,'worker request is bound to exact operation id');
expectContains('hash_equals($expectedSelection,(string)($claim[\'selectionHash\']??\'\'))',$libSource,'activation revalidates selection hash');
expectContains("pendingRecoveryBootstrap",$libSource,'bootstrap request is durable across reply loss');
expectContains('unset($peer[\'pendingRecoveryBootstrap\'])',$libSource,'authenticated bootstrap acknowledgement promotes and clears pending state');
expectContains("lastDisarm",$libSource,'disarm remote commit records an idempotent transaction tombstone');
expectContains('bool $validateOnly=false',$libSource,'checkpoint replacement is fully validated before live state is removed');
expectContains("unmRecoveryCheckpointRetryBackup",$libSource,'checkpoint retry keeps an exact rollback backup');
expectContains("unmRecoveryCheckpointRetryRestore",$libSource,'checkpoint retry has crash-recovery restoration');
expectContains("['PREPARED','SWAPPING']",$libSource,'checkpoint retry reconciles every pre-commit crash phase');
expectContains('unmRecoveryAssertReplicaMutationAllowed($identity[\'sourceHostId\'],$identity[\'replicationId\'])',$libSource,'publish and prune recheck recovery authority under lock');
expectContains("'grantReconciliationRequired'=>true",$libSource,'ambiguous coordinated grant leaves destination fenced unknown');
expectContains("'phase'=>'PREPARED'",$libSource,'activation journal exists before side effects');
expectContains("'INSTALLING_STATE'",$libSource,'activation journals intended host state before installation');
expectContains("['activation']['phase']='STARTING'",$libSource,'activation journals start before virsh side effect');
expectContains('unmRecoveryAcquireVmLock',$libSource,'activation mutations share the global VM UUID lock');
expectContains('unmRecoveryAssertSoleSourcePolicy',$libSource,'source authority rejects duplicate recovery policies for one VM');
expectContains('unmRecoveryAssertSoleDestinationPolicy',$libSource,'destination arming rejects duplicate incoming recovery policies for one VM');
expectContains('unmRecoveryAssertLegacyVmAvailable',$libSource,'Move, Warm Move, Clone, and replication mutations share the recovery identity interlock');
expectContains('function unmRecoveryLifecycleReconcileDestination',$libSource,'destination lifecycle uses one locked reconciliation helper');
expectContains("(string)(\$operation['state']??'')!=='RUNNING'",$libSource,'STARTING lifecycle skip requires a live RUNNING recovery worker');
expectContains("\$phase==='RUNNING'&&\$state==='RECOVERED_RUNNING'",$libSource,'status reconciliation never promotes STARTING before QGA health journal');
expectContains('every other claim kind is fail-closed',$libSource,'final activation start gate rejects dormant two-host claims');
expectContains('$grantMayHaveCommitted=true',$libSource,'only an attempted coordinated authority grant enters ambiguous fencing');
expectContains("unmRecoveryTransition(\$record,'STANDBY',['authority'=>'SOURCE','lastEvidenceError'",$libSource,'pre-grant guest evidence failure returns to source-authoritative standby');
expectContains('reachable or could not be probed safely',$libSource,'guest liveness errors distinguish unsafe probe ambiguity from proven reachability');
expectContains("if(in_array(\$state,['QUEUED','RUNNING'],true))unset(\$operation['failedAt'],\$operation['error'],\$operation['completedAt'],\$operation['result'])",$libSource,'new recovery operations clear terminal fields from a previous attempt');
expectContains("elseif(\$state==='COMPLETE')unset(\$operation['failedAt'],\$operation['error'])",$libSource,'successful recovery retries cannot retain stale failure fields');
expectSame(false,str_contains(substr($libSource,strpos($libSource,'function unmRecoveryStatus'),strpos($libSource,'function unmRecoveryStartAuthorization')-strpos($libSource,'function unmRecoveryStatus')),'unmRecoveryReconcileActivationState('),'read-only recovery status never performs durable activation reconciliation');
$remoteHoldBody=substr($libSource,strpos($libSource,'function unmRecoveryRemoteHold'),strpos($libSource,'function unmRecoveryRemoteHoldRelease')-strpos($libSource,'function unmRecoveryRemoteHold'));
expectSame(true,strpos($remoteHoldBody,'$current=(array)')<strpos($remoteHoldBody,'$epoch=strtotime'),'expired exact hold retry is reconciled before new-hold expiry validation');

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
