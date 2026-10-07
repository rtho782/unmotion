<?php
declare(strict_types=1);

// PIDs, including process-group IDs, are reusable. Never use a saved PID as
// authority to signal a process: bind it to this boot, start tick and command.
function unmProcessSnapshot(int $pid, string $procRoot='/proc'): ?array {
    if($pid<2)return null;
    $base=rtrim($procRoot,'/').'/'.$pid;
    $boot=trim((string)@file_get_contents(rtrim($procRoot,'/').'/sys/kernel/random/boot_id'));
    $stat=(string)@file_get_contents($base.'/stat');
    $end=strrpos($stat,')');
    if(!preg_match('/^[a-f0-9-]{36}$/i',$boot)||$end===false||!str_starts_with($stat,$pid.' ('))return null;
    // comm may itself contain spaces and parentheses; fields begin after its LAST ')'.
    $fields=preg_split('/\s+/',trim(substr($stat,$end+1)));
    if(count($fields)<20||in_array($fields[0],['Z','X','x'],true))return null;
    foreach([1,2,3,19] as $index)if(!ctype_digit($fields[$index]))return null;
    $command=(string)@file_get_contents($base.'/cmdline');
    clearstatcache(true,$base.'/exe');
    $executable=@readlink($base.'/exe');$exeStat=@stat($base.'/exe');
    if($command===''||$executable===false||$exeStat===false)return null;
    $arguments=explode("\0",rtrim($command,"\0"));
    $result=['schema'=>1,'pid'=>$pid,'bootId'=>$boot,'startTicks'=>$fields[19],
        'parentPid'=>(int)$fields[1],'processGroup'=>(int)$fields[2],'session'=>(int)$fields[3],
        'executable'=>$executable,'executableDevice'=>(string)$exeStat['dev'],
        'executableInode'=>(string)$exeStat['ino'],'argv'=>$arguments];
    // Detect exit/exec/reuse while collecting the /proc fields.
    $again=(string)@file_get_contents($base.'/stat');$againEnd=strrpos($again,')');
    $againFields=$againEnd===false?[]:preg_split('/\s+/',trim(substr($again,$againEnd+1)));
    if(($againFields[19]??null)!==$fields[19]||in_array($againFields[0]??'',['Z','X','x'],true)
        ||(string)@file_get_contents($base.'/cmdline')!==$command||@readlink($base.'/exe')!==$executable)return null;
    return $result;
}

function unmProcessCommandMatches(array $snapshot,string $script,array $arguments=[]): bool {
    $argv=$snapshot['argv']??[];
    if(!is_array($argv)||!$argv||$script===''||$script[0]!=='/')return false;
    $offset=0;
    if(($argv[0]??'')!==$script){
        // Only recognise an actual interpreter executable, never a script name
        // occurring later in arbitrary argv (e.g. echo/sleep/php -r bait).
        if(!preg_match('/^(bash|php(?:[0-9]+(?:\.[0-9]+)?)?)$/',basename((string)($snapshot['executable']??'')))
            ||($argv[1]??'')!==$script)return false;
        $offset=1;
    }elseif((string)($snapshot['executable']??'')!==$script)return false;
    return array_slice($argv,$offset+1,count($arguments))===$arguments;
}

function unmProcessIdentityMatches(array $identity,string $procRoot='/proc'): bool {
    if(($identity['schema']??null)!==1||!is_int($identity['pid']??null))return false;
    $current=unmProcessSnapshot($identity['pid'],$procRoot);
    if($current===null)return false;
    // Parent PID may legitimately change after the short-lived launcher exits.
    foreach(['pid','bootId','startTicks','processGroup','session','executable','executableDevice','executableInode','argv'] as $key){
        if(!array_key_exists($key,$identity)||$identity[$key]!==$current[$key])return false;
    }
    return true;
}

function unmProcessReadIdentity(string $path,int $pid,string $script,array $arguments=[]): ?array {
    $identity=json_decode((string)@file_get_contents($path),true);
    if(!is_array($identity)||($identity['pid']??null)!==$pid
        ||!unmProcessCommandMatches($identity,$script,$arguments)||!unmProcessIdentityMatches($identity))return null;
    return $identity;
}

function unmProcessRecordedWorker(string $path,string $script,array $arguments=[]): ?array {
    $record=json_decode((string)@file_get_contents($path),true);
    if(!is_array($record))return null;
    return unmProcessReadIdentity($path,(int)($record['pid']??0),$script,$arguments);
}

function unmProcessCleanupCandidate(string $path,int $legacyPid,string $script,array $arguments=[]): ?array {
    $identity=unmProcessRecordedWorker($path,$script,$arguments);
    if($identity!==null)return $identity;
    $record=json_decode((string)@file_get_contents($path),true);
    foreach(array_unique([$legacyPid,(int)($record['pid']??0)]) as $pid){
        $current=unmProcessSnapshot($pid);
        if($current!==null&&unmProcessCommandMatches($current,$script,$arguments)){
            throw new RuntimeException('An active worker has no verifiable lifetime record. Let it finish before removing unMotion: '.$script);
        }
    }
    return null;
}

function unmProcessLegacyServiceAllowed(string $script,array $arguments): bool {
    return ($script==='/usr/local/sbin/unmotion-replication-scheduler'&&$arguments===[])
        ||($script==='/usr/local/sbin/unmotion-replication-lifecycle'&&$arguments===['--daemon']);
}

function unmProcessAdoptLegacyService(string $path,int $pid,string $script,array $arguments): ?array {
    // One-time 0.4.1 upgrade path for the TWO known singleton daemons only.
    // Never replace an existing lifetime record or adopt jobs/seed workers.
    if(file_exists($path)||is_link($path)||!unmProcessLegacyServiceAllowed($script,$arguments))return null;
    $current=unmProcessSnapshot($pid);
    if($current===null||!unmProcessCommandMatches($current,$script,$arguments))return null;
    $offset=($current['argv'][0]??'')===$script?0:1;
    if(array_slice($current['argv'],$offset+1)!==$arguments)return null;
    // Persist exactly the first observed lifetime; if it changed, the caller's
    // mandatory second identity check refuses the stop. No fresh PID fallback.
    $handle=@fopen($path,'x');if($handle===false)return null;
    try{
        if(!chmod($path,0600)||fwrite($handle,json_encode($current,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")===false)throw new RuntimeException('Unable to persist adopted service identity.');
    }finally{fclose($handle);}
    return unmProcessReadIdentity($path,$pid,$script,$arguments);
}

function unmProcessRegister(string $path,int $pid,string $script,array $arguments=[]): array {
    $identity=unmProcessSnapshot($pid);
    if($identity===null||!unmProcessCommandMatches($identity,$script,$arguments))throw new RuntimeException('Unable to prove worker process identity.');
    $temporary=$path.'.tmp.'.getmypid();
    $json=json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    if(file_put_contents($temporary,$json,LOCK_EX)===false||!chmod($temporary,0600)||!rename($temporary,$path)){
        @unlink($temporary);throw new RuntimeException('Unable to persist worker process identity.');
    }
    return $identity;
}

function unmProcessTree(array $identity): array {
    if(!unmProcessIdentityMatches($identity))return [];
    $candidates=[];
    foreach(glob('/proc/[0-9]*/stat')?:[] as $path){
        $candidate=unmProcessSnapshot((int)basename(dirname($path)));
        if($candidate!==null&&$candidate['session']===$identity['session']&&$candidate['processGroup']===$identity['processGroup'])$candidates[$candidate['pid']]=$candidate;
    }
    if(!unmProcessIdentityMatches($identity))return [];
    $owned=[$identity['pid']=>$identity];
    do{
        $changed=false;
        foreach($candidates as $pid=>$candidate){
            if(!isset($owned[$pid])&&isset($owned[$candidate['parentPid']])){$owned[$pid]=$candidate;$changed=true;}
        }
    }while($changed);
    // Stop children before the shell so its traps can reap and restore state.
    return array_reverse(array_values($owned));
}

function unmProcessSignal(array $identity,int $signal): bool {
    if(!in_array($signal,[15,9],true))throw new InvalidArgumentException('Unsupported process signal.');
    if(!function_exists('posix_kill'))throw new RuntimeException('PHP POSIX support is required for verified process signalling.');
    // Revalidate immediately before EVERY signal, including escalation. Never
    // use kill(-pgid) or pkill: those can hit new/unverified group members.
    if(!unmProcessIdentityMatches($identity))return false;
    return posix_kill($identity['pid'],$signal);
}

function unmProcessSignalTree(array $identity,int $signal): array {
    $members=unmProcessTree($identity);
    foreach($members as $member)unmProcessSignal($member,$signal);
    return $members;
}

function unmProcessStop(array $identity,int $graceMilliseconds=10000,bool $escalate=false): bool {
    $members=unmProcessSignalTree($identity,15);
    $deadline=microtime(true)+$graceMilliseconds/1000;
    do{
        $alive=array_values(array_filter($members,'unmProcessIdentityMatches'));
        if(!$alive)return true;
        usleep(100000);
    }while(microtime(true)<$deadline);
    if($escalate){
        // Keep the ORIGINAL identities, not freshly captured identities for the
        // old PID numbers. A replacement process must never inherit authority.
        foreach($alive as $member)unmProcessSignal($member,9);
        for($attempt=0;$attempt<20;$attempt++){
            if(!array_filter($members,'unmProcessIdentityMatches'))return true;
            usleep(100000);
        }
    }
    return !array_filter($members,'unmProcessIdentityMatches');
}
