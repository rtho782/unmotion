<?php
declare(strict_types=1);
const UNM_LIVE_TRANSFER_SECONDS=1800;const UNM_LIVE_TRANSPORT_GRACE_SECONDS=120;
$job=['id'=>'live-'.str_repeat('a',24),'vmUuid'=>'aaaaaaaa-1111-4111-8111-000000000001','role'=>'source','bootId'=>'boot-a','runtime'=>'/run/unmotion/live/live-'.str_repeat('a',24)];$alive=true;$fail=false;$writes=[];$queries=0;$parentAlive=true;$parentPresent=true;
function unmLiveJob(string $id):array{global $job;if($id!==$job['id'])throw new RuntimeException('Foreign sampler');return $job;}
function unmNativeBoot():string{return 'boot-a';}
function unmLiveAlive(array $j):bool{global $alive;return $alive;}
function unmLiveJobPath(string $id):string{return '/private/jobs/'.$id.'/job.json';}
function unmProcessRecordedWorker(string $path,string $script,array $args=[]):?array{global $parentPresent;return $parentPresent?['pid'=>123,'bootId'=>'boot-a','startTicks'=>'100']:null;}
function unmProcessIdentityMatches(array $record):bool{global $parentAlive;return $parentAlive;}
function unmLiveRunCommand(array $args,?string $input=null,int $seconds=20):array{global $alive,$fail,$queries;$queries++;$alive=false;if($fail)throw new RuntimeException('Blocked display query');return ['code'=>0,'stdout'=>"disk_total: 100\ndisk_processed: 80\ndisk_remaining: 20\nmemory_total: 40\nmemory_processed: 0\n",'stderr'=>''];}
function unmNativeWrite(string $path,array $value):void{global $writes;$writes[$path]=$value;}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-progress.php';
$checks=0;function progressCheck(bool $v,string $message):void{global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
unmLiveProgressRun($job['id']);progressCheck($queries===1&&isset($writes[$job['runtime'].'/progress.json']),'Display sampler writes one isolated progress record and stops after authority withdrawal');
progressCheck(count($writes)===1&&$writes[$job['runtime'].'/progress.json']['phase']==='DISK_COPY','Sampler never writes authority, permits or VM definitions');
$saved=$writes;$alive=true;$fail=true;unmLiveProgressRun($job['id']);progressCheck($queries===2&&$writes===$saved,'Display query failure retains the prior sample without changing ownership');
$alive=true;$parentAlive=false;unmLiveProgressRun($job['id']);progressCheck($queries===2&&$writes===$saved,'An orphaned or reused worker lifetime cannot keep a sampler active');$parentAlive=true;$parentPresent=false;$denied=false;try{unmLiveProgressRun($job['id']);}catch(RuntimeException){$denied=true;}progressCheck($denied,'Missing exact worker identity rejects sampler start');$parentPresent=true;
foreach(['role'=>'destination','bootId'=>'old-boot'] as $field=>$value){$old=$job[$field];$job[$field]=$value;$denied=false;try{unmLiveProgressRun($job['id']);}catch(RuntimeException){$denied=true;}$job[$field]=$old;progressCheck($denied,'Wrong role/boot cannot run this source sampler');}
$clock=hrtime(true);$trace=[];unmLiveTimingMark($trace,'callback',$clock);unmLiveTimingStore($job,$trace);$timing=$writes[$job['runtime'].'/handover-timing.json'];progressCheck($timing['nonAuthoritative']===true&&$timing['unit']==='milliseconds'&&isset($timing['phases']['callback']),'Timing is explicitly non-authoritative and separate from progress');
echo "Live display sampler regressions passed: $checks checks.\n";
