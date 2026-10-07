<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/native-fence.php';
// Extract only controller helpers; never execute its host-mutating entrypoint.
$source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-native-fence');
$begin=strpos($source,'function nativeCommand(');$end=strpos($source,"\ntry {",$begin);
if($begin===false||$end===false)throw new RuntimeException('Controller helper boundary changed.');
eval(substr($source,$begin,$end-$begin));
$passed=0;
function assertController(bool $ok,string $message):void{global $passed;if(!$ok)throw new RuntimeException($message);$passed++;}
function denyController(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){assertController(true,$message);return;}throw new RuntimeException('Accepted: '.$message);}
$nonce=str_repeat('a',32);$uuid='f0420000-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$name='unmotion-fence-probe-'.$nonce;$probe=['uuid'=>$uuid,'name'=>$name,'nonce'=>$nonce];
assertController(nativeProbeIdentity($probe)===['uuid'=>$uuid,'name'=>$name],'Exact nonce-derived probe identity accepted');
denyController(fn()=>nativeProbeIdentity(array_replace($probe,['name'=>'Some other VM'])),'Different VM name rejected');
denyController(fn()=>nativeProbeIdentity(array_replace($probe,['uuid'=>'11111111-1111-4111-8111-111111111111'])),'Different VM UUID rejected');
$other='11111111-1111-4111-8111-111111111111';$calls=[];
nativeProbeReconcile($probe,static function(array $args)use(&$calls,$other):array{$calls[]=$args;return ['code'=>0,'stdout'=>$other."\n",'stderr'=>''];});
assertController(count($calls)===1,'Absent probe performs no mutation');
foreach(['running','timeout','name-mismatch','stop-failed','still-defined'] as $scenario){
    $calls=[];$destroyed=false;
    $command=static function(array $args)use(&$calls,&$destroyed,$scenario,$uuid,$name,$other):array{
        $calls[]=$args;$i=array_search('virsh',$args,true);$verb=$args[$i+1];$out='';$code=0;
        if($verb==='list')$out=$other."\n".(!$destroyed||$scenario==='still-defined'?$uuid."\n":'');
        elseif($verb==='dumpxml')$out='<domain><name>'.($scenario==='name-mismatch'?'Other VM':$name).'</name><uuid>'.$uuid.'</uuid></domain>';
        elseif($verb==='domstate'){if($scenario==='timeout')$code=124;else $out='running';}
        elseif($verb==='destroy'){assertController(end($args)===$uuid,'Destroy targets only exact randomized probe UUID');$destroyed=true;if($scenario==='stop-failed')$code=1;}
        else throw new RuntimeException('Unexpected cleanup operation.');return ['code'=>$code,'stdout'=>$out,'stderr'=>''];
    };
    if($scenario==='running'){nativeProbeReconcile($probe,$command);assertController($destroyed,'Active exact probe stopped and absence verified');}
    else {denyController(fn()=>nativeProbeReconcile($probe,$command),'Unknown/failed cleanup remains unresolved: '.$scenario);if(in_array($scenario,['timeout','name-mismatch'],true))assertController(!$destroyed,'Unknown/different VM never destroyed');}
}
$r=nativeCommand([PHP_BINARY,'-r','fwrite(STDERR,str_repeat("e",131072));fwrite(STDOUT,"done");'],5);
assertController($r['code']===0&&$r['stdout']==='done'&&strlen($r['stderr'])===131072,'Both child output streams drained without deadlock');
$start=microtime(true);$r=nativeCommand([PHP_BINARY,'-r','sleep(10);'],1);
assertController($r['code']===124&&microtime(true)-$start<4,'Hung command is bounded and terminated');
$r=nativeCommand([PHP_BINARY,'-r','fwrite(STDOUT,str_repeat("x",2097152));'],5);
assertController($r['code']===74&&strlen($r['stdout'])<=1048576,'Oversized command output is bounded');
echo "native fence controller: $passed assertions passed\n";
