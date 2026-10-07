<?php
declare(strict_types=1);

/** Protocol 7 transport. No request string is ever handed to a shell. */
const UNM_TRANSPORT_PROTOCOL = 7;
const UNM_TRANSPORT_MAX_REQUEST = 1048576;

function unmTransportEnvelope(string $op,array $args=[]): string {
    return 'unmotion-rpc '.base64_encode(json_encode(['v'=>UNM_TRANSPORT_PROTOCOL,'op'=>$op,'args'=>$args],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

function unmTransportDecode(string $command): array {
    if(strlen($command)>UNM_TRANSPORT_MAX_REQUEST*2 || !preg_match('/\Aunmotion-rpc ([A-Za-z0-9+\/=]+)( --server --daemon \.)?\z/D',$command,$m))throw new RuntimeException('Only protocol-7 unMotion RPC is allowed by this key.');
    $raw=base64_decode($m[1],true);
    if($raw===false||strlen($raw)>UNM_TRANSPORT_MAX_REQUEST)throw new RuntimeException('Invalid transport payload.');
    $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($r)||($r['v']??null)!==UNM_TRANSPORT_PROTOCOL||!is_string($r['op']??null)||!is_array($r['args']??null)||array_diff(array_keys($r),['v','op','args']))throw new RuntimeException('Upgrade both hosts to unMotion 0.4.2 or later (protocol 7 required).');
    if(isset($m[2])&&$m[2]!==''&&$r['op']!=='rsync-receive')throw new RuntimeException('Unexpected rsync daemon suffix.');
    return $r;
}

/** A lexer for our OLD, locally generated call sites, not a shell interpreter. */
function unmTransportTokens(string $s): array {
    if(strlen($s)>UNM_TRANSPORT_MAX_REQUEST||preg_match('/[\x00\r\n]/',$s))throw new InvalidArgumentException('Invalid remote command.');
    $out=[];$word='';$started=false;$n=strlen($s);
    for($i=0;$i<$n;$i++){
        $c=$s[$i];
        if($c==="'"||$c==='"'){
            $started=true;$quote=$c;$closed=false;
            for($i++;$i<$n;$i++){
                $c=$s[$i];if($c===$quote){$closed=true;break;}
                if($quote==='"'&&($c==='$'||$c==='`'))throw new InvalidArgumentException('Shell expansion is not supported.');
                if($quote==='"'&&$c==='\\'&&$i+1<$n&&str_contains('"\\$`',$s[$i+1]))$c=$s[++$i];
                $word.=$c;
            }
            if(!$closed)throw new InvalidArgumentException('Unterminated remote argument.');
        }elseif($c==='\\'){
            if(++$i>=$n)throw new InvalidArgumentException('Invalid escape.');$word.=$s[$i];$started=true;
        }elseif($c===' '||$c==="\t"){
            if($started){$out[]=['w'=>$word];$word='';$started=false;}
        }elseif(str_contains(';|&<>',$c)){
            // Numeric redirections are tokens, never file descriptor evaluation.
            if($started){$out[]=['w'=>$word];$word='';$started=false;}
            $op=$c;if($i+1<$n&&(($c==='|'&&$s[$i+1]==='|')||($c==='&'&&$s[$i+1]==='&')||($c==='>'&&$s[$i+1]==='&')))$op.=$s[++$i];
            $out[]=['o'=>$op];
        }elseif(str_contains('$`(){}*?~',$c))throw new InvalidArgumentException('Shell expansions and wildcards are not supported.');
        else{$word.=$c;$started=true;}
    }
    if($started)$out[]=['w'=>$word];return $out;
}

function unmTransportParseLegacy(string $command): array {
    $tokens=unmTransportTokens($command);$at=0;
    $word=static fn($t)=>is_array($t)?($t['w']??null):null;
    $parseList=null;$parseAnd=null;$parseOne=null;
    $parseOne=function()use(&$parseList,&$parseOne,&$tokens,&$at,$word):array{
        if($word($tokens[$at]??null)==='if'){
            $at++;$condition=$parseList(['then']);if($word($tokens[$at]??null)!=='then')throw new InvalidArgumentException('Invalid remote condition.');$at++;
            $yes=$parseList(['else','elif','fi']);$no=['op'=>'constant','code'=>0];
            if($word($tokens[$at]??null)==='elif'){$tokens[$at]=['w'=>'if'];$no=$parseOne();return ['op'=>'if','condition'=>$condition,'yes'=>$yes,'no'=>$no];}
            if($word($tokens[$at]??null)==='else'){$at++;$no=$parseList(['fi']);}
            if($word($tokens[$at]??null)!=='fi')throw new InvalidArgumentException('Invalid remote conditional end.');$at++;
            return ['op'=>'if','condition'=>$condition,'yes'=>$yes,'no'=>$no];
        }
        $args=[];$redirect=null;$stdoutNull=false;$stderrNull=false;$stderrToOut=false;
        while(isset($tokens[$at])){
            $t=$tokens[$at];$op=$t['o']??'';
            if(in_array($op,[';','&&','||','|'],true)||in_array($word($t),['then','else','elif','fi'],true))break;
            if($op==='>'||$op==='>&'){
                $fd=null;if($args&&in_array(end($args),['1','2'],true))$fd=array_pop($args);
                $at++;$target=$word($tokens[$at]??null);if($target===null)throw new InvalidArgumentException('Invalid redirect.');$at++;
                if($op==='>'&&$target==='/dev/null'){if($fd==='2')$stderrNull=true;else $stdoutNull=true;continue;}
                if($op==='>&'&&$fd==='2'&&$target==='1'){$stderrToOut=true;continue;}
                if($op==='>'&&$fd===null&&$redirect===null){$redirect=$target;continue;}
                throw new InvalidArgumentException('Remote redirection is not supported.');
            }
            if(!isset($t['w']))throw new InvalidArgumentException('Unsupported remote operator.');$args[]=$t['w'];$at++;
        }
        if(($tokens[$at]['o']??'')==='|'){
            $at++;$tail=$parseOne();
            if(($args[0]??'')==='printf'&&($args[1]??'')==='%s'&&count($args)===3&&($tail['op']??'')==='decode-write')return ['op'=>'json-write','path'=>$tail['path'],'data'=>$args[2]];
            if(($args[0]??'')==='sha256sum'&&($tail['op']??'')==='sha-column')return ['op'=>'sha-first','child'=>['op'=>'call','argv'=>$args,'stdoutNull'=>$stdoutNull,'stderrNull'=>$stderrNull,'stderrToOut'=>$stderrToOut]];
            throw new InvalidArgumentException('Remote pipelines are not supported.');
        }
        if($args===['base64','-d']&&is_string($redirect))return ['op'=>'decode-write','path'=>$redirect];
        if($redirect!==null)throw new InvalidArgumentException('Remote file writes require the JSON operation.');
        if($args===['awk','{print $1}'])return ['op'=>'sha-column'];
        if(!$args)throw new InvalidArgumentException('Empty remote operation.');
        return ['op'=>'call','argv'=>$args,'stdoutNull'=>$stdoutNull,'stderrNull'=>$stderrNull,'stderrToOut'=>$stderrToOut];
    };
    $parseAnd=function()use(&$parseOne,&$tokens,&$at):array{
        $node=$parseOne();while(in_array($tokens[$at]['o']??'', ['&&','||'],true)){$op=$tokens[$at++]['o'];$node=['op'=>$op==='&&'?'and':'or','left'=>$node,'right'=>$parseOne()];}return $node;
    };
    $parseList=function(array $stop=[])use(&$parseAnd,&$tokens,&$at,$word):array{
        $nodes=[];while(isset($tokens[$at])&&!in_array($word($tokens[$at]),$stop,true)){
            if(($tokens[$at]['o']??'')===';'){$at++;continue;}$nodes[]=$parseAnd();
            if(($tokens[$at]['o']??'')===';'){$at++;continue;}
            if(isset($tokens[$at])&&!in_array($word($tokens[$at]),$stop,true))throw new InvalidArgumentException('Unexpected remote token.');
        }
        if(!$nodes)throw new InvalidArgumentException('Empty remote sequence.');return count($nodes)===1?$nodes[0]:['op'=>'sequence','items'=>$nodes];
    };
    $tree=$parseList();if($at!==count($tokens))throw new InvalidArgumentException('Unparsed remote command.');return $tree;
}

function unmTransportEncodeCommand(string $command): string {
    if(str_starts_with($command,'unmotion-rpc ')){unmTransportDecode($command);return $command;}
    return unmTransportEnvelope('operations',['tree'=>unmTransportParseLegacy($command)]);
}

function unmTransportKeyId(string $key): string {
    return unmManagedSshKeyId($key);
}

function unmTransportPeer(string $keyId): ?array {
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$keyId))throw new RuntimeException('Invalid authenticated key identity.');
    $found=null;
    foreach(unmPeers() as $peer){
        try{$candidate=unmTransportKeyId((string)($peer['incomingPublicKey']??''));}catch(Throwable $e){continue;}
        if(!hash_equals($candidate,$keyId))continue;
        if($found!==null)throw new RuntimeException('Ambiguous authenticated peer identity.');$found=$peer;
    }
    return $found;
}

function unmTransportId(string $id): string {
    if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',$id)||str_contains($id,'..'))throw new InvalidArgumentException('Invalid transfer identity.');return $id;
}

function unmTransportUuid(string $uuid): string {
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/Di',$uuid))throw new InvalidArgumentException('Invalid VM UUID.');return strtolower($uuid);
}

function unmTransportPath(string $path): string {
    if($path===''||$path[0]!=='/'||strlen($path)>4096||preg_match('~[\x00-\x1f\x7f]|//|/(?:\.|\.\.)(?:/|$)~',$path)||str_ends_with($path,'/'))throw new InvalidArgumentException('Unsafe transport path.');
    for($p=$path;$p!=='/';$p=dirname($p)){
        clearstatcache(true,$p);if(is_link($p))throw new RuntimeException('Symbolic-link transport paths are prohibited: '.$p);
        if(file_exists($p)&&!is_file($p)&&!is_dir($p))throw new RuntimeException('Special-file transport paths are prohibited.');
    }
    if(is_file($path)&&(int)(lstat($path)['nlink']??0)!==1)throw new RuntimeException('Hard-linked transport files are prohibited.');return $path;
}

function unmTransportWithin(string $path,string $root,bool $equal=false): bool {
    return ($equal&&$path===$root)||str_starts_with($path,rtrim($root,'/').'/');
}

function unmTransportStoragePath(string $path,string $kind='image',bool $rootAllowed=false): string {
    unmTransportPath($path);$cfg=unmLoadConfig();$root=rtrim((string)$cfg[$kind==='iso'?'iso_dir':'image_dir'],'/');
    unmTransportPath($root);
    if(!preg_match('~\A/mnt/[^/]+/[^/]+~',$root)||!unmTransportWithin($path,$root,$rootAllowed))throw new RuntimeException('Path is outside configured unMotion storage.');return $path;
}

function unmTransportDataset(string $dataset): string {
    if(strlen($dataset)>1024||!preg_match('~\A[A-Za-z][A-Za-z0-9_.:-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.: -]*)+(?:@[A-Za-z0-9_][A-Za-z0-9_.:-]*)?\z~D',$dataset)||str_contains($dataset,'..'))throw new InvalidArgumentException('Invalid ZFS object.');return $dataset;
}

function unmTransportReservationDir(): string {return UNM_BOOT_DIR.'/transport';}
function unmTransportReservationPath(array $peer,string $id): string {
    return unmTransportReservationDir().'/'.unmTransportId((string)$peer['id']).'/'.unmTransportId($id).'.json';
}

function unmTransportReservations(array $peer): array {
    $all=[];foreach(glob(unmTransportReservationDir().'/'.unmTransportId((string)$peer['id']).'/*.json')?:[] as $path){$r=unmLoadJson($path);if(($r['peerId']??'')===($peer['id']??'')&&($r['sourceHostId']??'')===($peer['hostId']??''))$all[]=$r;}
    // Existing replica manifests already constitute an exact destination-owned plan.
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $path){$m=unmLoadJson($path);if(($m['sourceHostId']??'')!==($peer['hostId']??''))continue;$m['id']=$m['replicationId']??'';$m['replica']=true;$m['peerId']=$peer['id'];$all[]=$m;}
    return $all;
}

function unmTransportOwned(array $peer,string $path,string $kind='file'): array {
    if($kind==='dataset')unmTransportDataset($path);else unmTransportPath($path);
    foreach(unmTransportReservations($peer) as $r){
        foreach((array)($r['storage']??[]) as $s){
            if(($s['destination']??'')===$path&&($kind==='dataset')===in_array($s['kind']??'',['dataset','zvol'],true))return $r;
        }
        if($kind==='file'){
            foreach((array)($r['hostStatePaths']??[]) as $p)if($p===$path)return $r;
            if(in_array($path,(array)($r['files']??[]),true))return $r;
            $state=(string)($r['stateDirectory']??'');
            if(!empty($r['replica'])&&$state!==''&&dirname($path)===$state&&preg_match('/\A(?:state-[a-f0-9]{24}\.tar\.gz|(?:unmotion-[A-Za-z0-9_.:-]+|[A-Za-z0-9_.-]+)\.xml)\z/D',basename($path)))return $r;
        }
    }
    throw new RuntimeException('No matching peer-owned transfer reservation; preserve existing data and prepare a new transfer: '.$path);
}

function unmTransportVmOff(string $uuid): bool {
    $r=unmRun(['virsh','domstate',unmTransportUuid($uuid)],null,15);
    if($r['code']===0&&trim($r['stdout'])==='shut off')return true;
    if($r['code']===0)throw new RuntimeException('The destination VM must be shut off.');
    $list=unmRun(['virsh','list','--all','--uuid'],null,20);
    if($list['code']!==0||preg_match('/\b'.preg_quote($uuid,'/').'\b/i',$list['stdout']))throw new RuntimeException('The destination VM state is not provably absent or shut off.');
    return false;
}

function unmTransportReserve(array $peer,array $request): array {
    $admission=unmTransportAdmissionLock();try{
    $id=unmTransportId((string)($request['id']??''));$uuid=unmTransportUuid((string)($request['vmUuid']??''));$vmLock=unmTransportVmLock($uuid);$existsVm=unmTransportVmOff($uuid);
    if($existsVm&&!unmTransportHasVmOwnership($peer,$uuid))throw new RuntimeException('An existing destination VM is not owned by this authenticated peer.');
    $old=unmLoadJson(unmTransportReservationPath($peer,$id));
    if($old&&(($old['vmUuid']??'')!==$uuid||($old['sourceHostId']??'')!==$peer['hostId']))throw new RuntimeException('Transfer identity is already reserved.');
    $storage=[];
    foreach((array)($request['storage']??[]) as $s){
        if(!is_array($s))throw new InvalidArgumentException('Invalid transfer storage.');$kind=(string)($s['kind']??'');$dst=(string)($s['destination']??'');
        if(in_array($kind,['dataset','zvol'],true)){
            unmTransportDataset($dst);if(str_contains($dst,'@'))throw new InvalidArgumentException('Reserve datasets, not snapshots.');
            $cfg=unmLoadConfig();$root=$kind==='zvol'?(string)$cfg['zvol_dataset']:unmZfsDatasetForPath((string)$cfg['image_dir']);
            if($root===''||!unmTransportWithin($dst,$root))throw new RuntimeException('ZFS receive is outside configured VM storage.');
            $exists=unmRun(['zfs','list','-H','-o','name',$dst],null,15)['code']===0;
            if($exists){try{$owner=unmTransportOwned($peer,$dst,'dataset');if(($owner['vmUuid']??'')!==$uuid)throw new RuntimeException('ZFS object belongs to another VM.');}catch(Throwable $e){unmTransportAdoptStoppedDisk($peer,$uuid,$dst,$kind);}}
            $mount=(string)($s['mountpoint']??'');if($kind==='dataset'){unmTransportStoragePath($mount);}
            $storage[]=['kind'=>$kind,'destination'=>$dst,'mountpoint'=>$mount];
        }elseif(in_array($kind,['image','iso','nvram'],true)){
            unmTransportStoragePath($dst,$kind);if(file_exists($dst)){
                // Existing ISO libraries can be read/reused but are never overwritten.
                if($kind==='iso')continue;
                try{$owner=unmTransportOwned($peer,$dst);if(($owner['vmUuid']??'')!==$uuid)throw new RuntimeException('File belongs to another VM.');}catch(Throwable $e){unmTransportAdoptStoppedDisk($peer,$uuid,$dst,$kind);}
            }
            $storage[]=['kind'=>$kind,'destination'=>$dst];
        }else throw new InvalidArgumentException('Unsupported reserved storage kind.');
    }
    $hostState=[];foreach((array)($request['hostStatePaths']??[]) as $path){$path=(string)$path;unmTransportPath($path);if(!unmTransportHostStatePath($path,$uuid))throw new RuntimeException('Host-state transfer is not UUID-scoped.');if(file_exists($path)&&!unmTransportHasVmOwnership($peer,$uuid))throw new RuntimeException('Existing destination host state has no authenticated VM ownership.');$hostState[]=$path;}
    $stage=UNM_BOOT_DIR.'/incoming/'.$id;$r=['id'=>$id,'vmUuid'=>$uuid,'vmName'=>(string)($request['vmName']??''),'peerId'=>$peer['id'],'sourceHostId'=>$peer['hostId'],'storage'=>$storage,'hostStatePaths'=>$hostState,'files'=>[$stage.'/destination.xml',$stage.'/host-state.tar.gz'],'createdAt'=>$old['createdAt']??gmdate('c')];
    foreach(unmTransportReservations($peer) as $existing)if(($existing['id']??'')!==$id&&($existing['vmUuid']??'')!==$uuid)unmTransportNoOverlap($r,$existing);
    // Reserve across ALL peers, not merely the authenticated peer.
    foreach(glob(unmTransportReservationDir().'/*/*.json')?:[] as $p){$other=unmLoadJson($p);if(($other['peerId']??'')===$peer['id'])continue;unmTransportNoOverlap($r,$other);}
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $p){$other=unmLoadJson($p);if(($other['sourceHostId']??'')===$peer['hostId']&&($other['vmUuid']??'')===$uuid)continue;unmTransportNoOverlap($r,$other);}
    unmAtomicJson(unmTransportReservationPath($peer,$id),$r);return ['success'=>true,'id'=>$id];
    }finally{if(isset($vmLock)&&is_resource($vmLock)){flock($vmLock,LOCK_UN);fclose($vmLock);}flock($admission,LOCK_UN);fclose($admission);}
}

function unmTransportRuntimeDirectory(): string {
    // Unraid's /var/run is a symlink to /run. Internally generated temporary
    // paths must use the canonical root; peer-controlled paths stay no-follow.
    return '/run/unmotion/transport';
}

function unmTransportAdmissionLock(){
    $dir=unmTransportRuntimeDirectory();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create transport admission directory.');$f=fopen($dir.'/admission.lock','c');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Unable to lock transport admission.');return $f;
}

function unmTransportNoOverlap(array $a,array $b): void {
    if(($a['vmUuid']??'')!==''&&($a['vmUuid']??'')===($b['vmUuid']??'')&&($a['sourceHostId']??'')!==($b['sourceHostId']??''))throw new RuntimeException('VM identity is reserved by another authenticated peer.');
    $spans=static function(array $r):array{$v=[];foreach((array)($r['storage']??[]) as $s){$dst=(string)($s['destination']??'');if($dst!=='')$v[]=$dst;$mount=(string)($s['mountpoint']??$s['destinationMountpoint']??'');if($mount!=='')$v[]=$mount;}return array_merge($v,(array)($r['files']??[]),(array)($r['hostStatePaths']??[]));};
    foreach($spans($a) as $left)foreach($spans($b) as $right)if($left===$right||unmTransportWithin($left,$right)||unmTransportWithin($right,$left))throw new RuntimeException('Transfer resources overlap another peer or VM reservation.');
}

function unmTransportHasVmOwnership(array $peer,string $uuid): bool {
    foreach(unmTransportReservations($peer) as $r)if(($r['vmUuid']??'')===$uuid)return true;
    $marker=unmLoadJson(UNM_OWNERSHIP_DIR.'/'.$uuid.'.json');if(($marker['vmUuid']??'')!==$uuid)return false;
    if(($marker['sourceHostId']??'')===$peer['hostId']&&in_array($marker['state']??'',['owned','staged'],true))return true;
    return ($marker['ownerHostId']??'')===$peer['hostId']&&in_array($marker['state']??'',['migrated_out_retained','migrated_out_unregistered','source_deleted','pending_cleanup'],true);
}

function unmTransportAdoptStoppedDisk(array $peer,string $uuid,string $path,string $kind): void {
    $marker=unmLoadJson(UNM_OWNERSHIP_DIR.'/'.$uuid.'.json');
    if(!unmTransportHasVmOwnership($peer,$uuid))throw new RuntimeException('Existing storage has no authenticated source ownership; retained for manual review.');
    unmTransportVmOff($uuid);$vm=unmParseVm($uuid);
    foreach((array)($vm['disks']??[]) as $disk){
        $source=(string)($disk['resolvedSource']??$disk['source']??'');
        if($kind==='zvol'&&$source==='/dev/zvol/'.$path)return;
        if($kind==='dataset'&&($disk['zfsDataset']??'')===$path&&($disk['transferClass']??'')==='zfs-dataset-image')return;
        if($kind==='image'&&$source===$path)return;
    }
    if($kind==='nvram'&&($vm['nvram']??'')===$path)return;
    throw new RuntimeException('Existing storage is not an exact disk of the owned stopped VM.');
}

function unmTransportHostStatePath(string $path,string $uuid): bool {
    if(function_exists('unmTpmPathAllowed')&&unmTpmPathAllowed($path,$uuid))return true;
    foreach(['/etc/libvirt/qemu/nvram','/var/lib/libvirt/qemu/nvram'] as $root)if(dirname($path)===$root&&preg_match('/\A'.preg_quote($uuid,'/').'(?:_VARS(?:-[A-Za-z0-9_-]{1,80})?)?\.fd\z/Di',basename($path)))return true;
    return false;
}

function unmTransportRun(array $argv,bool $stream=false): array {
    if($stream){$p=proc_open($argv,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,null,['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C']);if(!is_resource($p))throw new RuntimeException('Cannot launch restricted transport operation.');return ['code'=>proc_close($p),'stdout'=>'','stderr'=>''];}
    // proc_open(array) bypasses the shell; this includes ordinary read queries.
    $p=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C','UNM_SSH_KEY_ID'=>(string)getenv('UNM_SSH_KEY_ID')]);
    if(!is_resource($p))throw new RuntimeException('Cannot launch restricted transport operation.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$code=null;$start=microtime(true);
    while(true){$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($p);if(!$status['running']){$code=$status['exitcode'];break;}if(microtime(true)-$start>300){proc_terminate($p);$code=124;break;}usleep(20000);}
    $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($p);return ['code'=>$code===null||$code<0?$closed:$code,'stdout'=>$out,'stderr'=>$err];
}

/** Validate only the fixed DRR_BEGIN prefix before allowing ZFS to consume stdin.
 * OpenZFS sys/zfs_ioctl.h: uint32 record type/payload length, followed by
 * uint64 magic/version/creation time and uint32 objset type/flags. Header type
 * is versioninfo & 3; sys/fs/zfs.h defines ZFS=2 and ZVOL=3. Reading 40 bytes
 * does not depend on the platform's full replay-record union/padding size.
 */
function unmTransportZfsStreamPrefix(string $prefix,string $kind): void {
    if(strlen($prefix)!==40||!in_array($kind,['dataset','zvol'],true))throw new RuntimeException('Invalid or truncated ZFS stream header.');
    $magic=substr($prefix,8,8);
    if($magic===pack('V2',0xf5bacbac,2))$format='V';
    elseif($magic===pack('N2',2,0xf5bacbac))$format='N';
    else throw new RuntimeException('Invalid ZFS stream magic.');
    $u32=static fn(int $offset):int=>unpack($format,substr($prefix,$offset,4))[1];
    $versionLow=$u32($format==='V'?16:20);
    if($u32(0)!==0||($versionLow&3)!==1)throw new RuntimeException('Only a single ZFS substream is accepted; recursive/property package streams are forbidden.');
    if($u32(4)>1048576)throw new RuntimeException('ZFS stream begin payload exceeds the permitted bound.');
    if($u32(32)!==($kind==='dataset'?2:3))throw new RuntimeException('ZFS stream storage type does not match its reservation.');
}

function unmTransportReceiveZfs(array $argv,string $kind): array {
    $prefix='';while(strlen($prefix)<40&&!feof(STDIN)){$part=fread(STDIN,40-strlen($prefix));if($part===false)throw new RuntimeException('Cannot read ZFS stream header.');if($part==='')break;$prefix.=$part;}
    unmTransportZfsStreamPrefix($prefix,$kind);
    // Start no receiver until the typed, non-compound boundary is validated.
    // Forward the inspected bytes unchanged; progress/errors remain on stderr.
    $p=proc_open($argv,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,null,['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LANG'=>'C']);
    if(!is_resource($p))throw new RuntimeException('Cannot launch restricted ZFS receiver.');
    $sent=0;while($sent<strlen($prefix)){$n=@fwrite($pipes[0],substr($prefix,$sent));if($n===false||$n===0)break;$sent+=$n;}
    $copied=$sent===strlen($prefix)?@stream_copy_to_stream(STDIN,$pipes[0]):false;
    fclose($pipes[0]);$code=proc_close($p);if($copied===false&&$code===0)$code=74;
    return unmTransportResult($code);
}

function unmTransportResult(int $code=0,string $out='',string $err=''): array {return ['code'=>$code,'stdout'=>$out,'stderr'=>$err];}

function unmTransportJsonArgument(array $a,int $index=2): array {
    $raw=base64_decode((string)($a[$index]??''),true);if($raw===false||strlen($raw)>UNM_TRANSPORT_MAX_REQUEST)throw new InvalidArgumentException('Invalid agent payload.');
    $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r))throw new InvalidArgumentException('Agent payload must be an object.');return $r;
}

function unmTransportAgent(array $a,?array $peer,string $keyId): array {
    $cmd=$a[1]??'';$count=count($a);
    if($cmd==='capabilities'&&$count===2)return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd]);
    if($cmd==='reciprocal-prepare'&&$count===3){
        $r=unmTransportJsonArgument($a);if(!hash_equals($keyId,unmTransportKeyId((string)($r['incomingPublicKey']??''))))throw new RuntimeException('Pairing may install only its authenticated key.');
        if($peer!==null)throw new RuntimeException('This key is already bound to a peer; finish or remove the existing pairing first.');
        if((int)($r['capabilities']['protocolVersion']??0)!==7)throw new RuntimeException('Both peers must use protocol 7.');
        return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd,$a[2]]);
    }
    if($cmd==='transport-forget-self'&&$count===2){
        if($peer!==null)unmForgetPeerLocal((string)$peer['id']);
        else{
            $contents=@file_get_contents('/root/.ssh/authorized_keys');if($contents===false)throw new RuntimeException('Cannot inspect the pairing key.');
            foreach(preg_split('/\R/',$contents) as $line){$blob=unmAuthorizedKeyBlob($line);if($blob!==''&&hash_equals($keyId,unmTransportKeyId('ssh-ed25519 '.$blob)))unmRemoveAuthorizedKey('ssh-ed25519 '.$blob);}
        }
        return unmTransportResult(0,"{\"success\":true}\n");
    }
    if($peer===null)throw new RuntimeException('Complete reciprocal pairing before using this key.');
    if(in_array($cmd,['reciprocal-finalize','reciprocal-forget'],true)&&$count===3){
        if(($a[2]??'')!==($peer['id']??''))throw new RuntimeException('A pairing key may change only its own peer record.');return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd,$a[2]]);
    }
    if(($peer['pairingState']??'')!=='paired')throw new RuntimeException('Reciprocal pairing is incomplete.');
    if(in_array($cmd,['health','inventory','usb-list','diagnostic-host'],true)&&$count===2)return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd]);
    if($cmd==='transport-reserve'&&$count===3)return unmTransportResult(0,json_encode(unmTransportReserve($peer,unmTransportJsonArgument($a)),JSON_THROW_ON_ERROR)."\n");
    if($cmd==='zfs-dataset-for-path'&&in_array($count,[3,4],true)){
        $path=base64_decode($a[2],true);if($path===false)throw new RuntimeException('Invalid storage path.');unmTransportReadable($peer,$path);
        if($count===4&&!in_array($a[3],['0','1'],true))throw new RuntimeException('Invalid exact-match option.');return unmTransportRun(array_merge(['/usr/local/sbin/unmotion-agent',$cmd],array_slice($a,2)));
    }
    if($cmd==='finalize-source-cleanup'&&$count===4){
        unmTransportId($a[2]);if($a[3]!==$peer['hostId'])throw new RuntimeException('Cleanup is not bound to the authenticated destination.');
        return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd,$a[2],$a[3]]);
    }
    if($cmd==='recovery-legacy-interlock'&&$count===4){unmTransportUuid($a[2]);if(!in_array($a[3],['Move','Warm Move','Clone','Replication policy mutation'],true))throw new RuntimeException('Invalid interlock operation.');return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd,$a[2],$a[3]]);}
    $jsonOps=['firmware-plan','migration-nvram-check','migration-nvram-verify','replication-preflight','replication-prepare','replication-publish','replication-commit','replication-prune','replication-inventory','recovery-key-install','migration-resolve','schedule-source-cleanup','prepare-overwrite','recovery-arm','recovery-disarm','recovery-hold','recovery-hold-release','recovery-status','recovery-fence-status','recovery-grant','recovery-claim-renew','recovery-activation-commit','recovery-failback-preflight'];
    if(!in_array($cmd,$jsonOps,true)||$count!==3)throw new RuntimeException('Agent operation is not available through restricted SSH.');
    $r=unmTransportJsonArgument($a);
    if(str_starts_with($cmd,'recovery-')){
        if(($r['senderHostId']??'')!==$peer['hostId'])throw new RuntimeException('Recovery sender is not the authenticated peer.');
    }elseif(str_starts_with($cmd,'replication-')||in_array($cmd,['migration-resolve','schedule-source-cleanup'],true)){
        if(($r['sourceHostId']??'')!==$peer['hostId']||($r['destinationHostId']??'')!==unmHostId())throw new RuntimeException('Request host identity is not the authenticated peer.');
    }
    if(in_array($cmd,['replication-preflight','replication-prepare'],true)&&($cmd==='replication-prepare'||($r['reserve']??true))){
        $admission=unmTransportAdmissionLock();$candidate=['vmUuid'=>$r['vmUuid']??'','sourceHostId'=>$peer['hostId'],'storage'=>$r['storage']??[]];
        foreach(glob(unmTransportReservationDir().'/*/*.json')?:[] as $path){$other=unmLoadJson($path);if(($other['sourceHostId']??'')===$peer['hostId']&&($other['vmUuid']??'')===($candidate['vmUuid']??''))continue;unmTransportNoOverlap($candidate,$other);}
    }
    if(in_array($cmd,['schedule-source-cleanup','migration-resolve'],true)){
        $id=unmTransportId((string)($r['jobId']??''));$owned=unmLoadJson(unmTransportReservationPath($peer,$id));if(!$owned||($owned['vmUuid']??'')!==($r['vmUuid']??''))throw new RuntimeException('Cleanup watcher requires the exact peer-owned transfer reservation.');
    }
    if(in_array($cmd,['replication-publish','replication-commit'],true)){
        $point=(array)($r['point']??[]);$xml=(string)($point['sourceXml']??$r['sourceXml']??'');$encoded=$point['sourceXmlBase64']??$r['sourceXmlBase64']??null;
        if($encoded!==null){$decoded=base64_decode((string)$encoded,true);if($decoded===false)throw new RuntimeException('Invalid replica XML.');$xml=$decoded;}
        unmTransportValidateDomainXml($xml,(string)($r['vmUuid']??''),null);
    }
    if($cmd==='prepare-overwrite'){
        $uuid=unmTransportUuid((string)($r['vmUuid']??''));$vmLock=unmTransportVmLock($uuid);
        $r=unmTransportOverwritePlan($peer,$r);$a[2]=base64_encode(json_encode($r,JSON_THROW_ON_ERROR));
    }
    return unmTransportRun(['/usr/local/sbin/unmotion-agent',$cmd,$a[2]]);
}

/** Validate the helper's COMPLETE effective deletion plan before it undefines. */
function unmTransportOverwritePlan(array $peer,array $request): array {
    $uuid=unmTransportUuid((string)($request['vmUuid']??''));$exists=unmTransportVmOff($uuid);
    if(!unmTransportHasVmOwnership($peer,$uuid))throw new RuntimeException('Destination replacement requires authenticated VM ownership.');
    $items=[];foreach((array)($request['storage']??[]) as $s){if(!is_array($s))throw new RuntimeException('Invalid replacement item.');$items[]=['kind'=>(string)($s['kind']??''),'path'=>(string)($s['path']??'')];}
    $vm=$exists?unmParseVm($uuid):null;
    if($vm!==null)foreach((array)($vm['disks']??[]) as $disk){
        $src=(string)($disk['source']??'');
        if(($disk['type']??'')==='zvol'&&str_starts_with($src,'/dev/zvol/'))$items[]=['kind'=>'zvol','path'=>substr($src,10)];
        elseif(($disk['type']??'')==='file')$items[]=['kind'=>($disk['transferClass']??'')==='zfs-dataset-image'?'dataset':'image','path'=>($disk['transferClass']??'')==='zfs-dataset-image'?(string)$disk['zfsDataset']:$src];
    }
    $unique=[];foreach($items as $s)$unique[$s['kind']."\0".$s['path']]=$s;
    foreach($unique as $s){
        $kind=$s['kind'];$path=$s['path'];if(!in_array($kind,['image','dataset','zvol'],true))throw new RuntimeException('Unsupported replacement storage kind.');
        $owner=unmTransportOwned($peer,$path,$kind==='image'?'file':'dataset');if(($owner['vmUuid']??'')!==$uuid)throw new RuntimeException('Replacement resource belongs to a different VM.');
        if($kind==='image'){unmTransportPath($path);continue;}
        $children=unmTransportRun(['zfs','list','-H','-r','-o','name',$path]);
        if($children['code']!==0)continue;
        if(array_values(array_filter(explode("\n",trim($children['stdout']))))!==[$path])throw new RuntimeException('Child datasets block destination replacement.');
        $snapshots=unmTransportRun(['zfs','list','-H','-d','1','-t','snapshot','-o','name',$path]);if($snapshots['code']!==0)throw new RuntimeException('Cannot prove destination snapshot inventory.');
        foreach(array_filter(explode("\n",trim($snapshots['stdout']))) as $snapshot){
            if(!str_starts_with($snapshot,$path.'@unmotion-')&&!str_starts_with($snapshot,$path.'@pre-first-boot-'))throw new RuntimeException('Unrelated snapshots block destination replacement.');
            $holds=unmTransportRun(['zfs','holds','-H',$snapshot]);if($holds['code']!==0||trim($holds['stdout'])!=='')throw new RuntimeException('Held snapshots block destination replacement.');
        }
        if($kind==='zvol'&&unmReplicationZvolUsedByOtherVm($path,$uuid))throw new RuntimeException('Another VM references this ZFS volume.');
        if($kind==='dataset'){
            if($vm===null)throw new RuntimeException('Dataset replacement needs its exact stopped VM image inventory.');
            $mount=unmTransportRun(['zfs','get','-H','-o','value','mountpoint',$path]);if($mount['code']!==0)throw new RuntimeException('Dataset mountpoint unavailable.');$files=[];
            foreach((array)$vm['disks'] as $disk)if(($disk['zfsDataset']??'')===$path)$files[]=(string)($disk['resolvedSource']??$disk['source']);
            $errors=unmReplicationDatasetIsolationErrors($path,trim($mount['stdout']),$files,$vm);if($errors)throw new RuntimeException(implode(' ',$errors));
        }
    }
    $request['storage']=array_values($unique);return $request;
}

function unmTransportReadable(array $peer,string $path): void {
    unmTransportPath($path);foreach(['image','iso'] as $kind){try{unmTransportStoragePath($path,$kind,true);return;}catch(Throwable $e){}}
    if(dirname($path)===UNM_OWNERSHIP_DIR&&preg_match('/\A[a-f0-9-]{36}\.json\z/Di',basename($path)))return;
    unmTransportOwned($peer,$path);
}

function unmTransportMkdir(array $peer,string $path): array {
    unmTransportPath($path);
    if(in_array($path,[UNM_BOOT_DIR.'/incoming/seeds',UNM_OWNERSHIP_DIR],true)){if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Unable to create metadata directory.');return unmTransportResult();}
    $allowed=false;
    foreach(unmTransportReservations($peer) as $r){
        foreach(array_merge(array_column((array)($r['storage']??[]),'destination'),(array)($r['files']??[])) as $dst)if(str_starts_with($dst,$path.'/'))$allowed=true;
        if(($r['stateDirectory']??'')===$path)$allowed=true;
    }
    if(!$allowed||!preg_match('~\A/(?:mnt/[^/]+/[^/]+|boot/config/plugins/unmotion/incoming/[^/]+)~',$path))throw new RuntimeException('Directory is not needed by a reserved transfer.');
    if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Unable to create reserved transfer directory.');return unmTransportResult();
}

function unmTransportDatasetReadable(array $peer,string $path): void {
    if(!preg_match('~\A[A-Za-z][A-Za-z0-9_.:-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.: -]*)*(?:@[A-Za-z0-9_][A-Za-z0-9_.:-]*)?\z~D',$path)||str_contains($path,'..'))throw new RuntimeException('Invalid ZFS query.');
    $cfg=unmLoadConfig();$base=explode('@',$path,2)[0];$roots=[(string)$cfg['zvol_dataset']];
    $image=unmZfsDatasetForPath((string)$cfg['image_dir']);if(is_string($image))$roots[]=$image;
    foreach($roots as $root)if($root!==''&&($base===$root||str_starts_with($base,$root.'/')||$base===explode('/',$root)[0]))return;
    unmTransportOwned($peer,$base,'dataset');
}

function unmTransportZfs(array $peer,array $a): array {
    $verb=$a[1]??'';$target=(string)end($a);
    if($verb==='get'&&count($a)===7&&in_array($a[2],['-H','-pH'],true)&&$a[3]==='-o'&&$a[4]==='value'&&in_array($a[5],['guid','readonly','receive_resume_token','used','available','mountpoint','canmount','volmode','dedup','compression'],true)){unmTransportDatasetReadable($peer,$target);return unmTransportRun($a);}
    if($verb==='list'){
        $flags=array_slice($a,2,-1);if(!in_array($flags,[[],['-H','-o','name'],['-H','-t','snapshot']],true))throw new RuntimeException('Unsupported ZFS list options.');unmTransportDatasetReadable($peer,$target);return unmTransportRun($a);
    }
    $base=explode('@',$target,2)[0];$r=unmTransportOwned($peer,$base,'dataset');$vmLock=unmTransportVmLock((string)$r['vmUuid']);unmTransportVmOff((string)$r['vmUuid']);
    $object=null;foreach((array)$r['storage'] as $s)if(($s['destination']??'')===$base)$object=$s;
    if($object===null)throw new RuntimeException('Missing ZFS ownership plan.');
    if(str_contains($target,'@')){[$ds,$snap]=explode('@',$target,2);if(!preg_match('/\A(?:unmotion-[A-Za-z0-9_.:-]+|pre-first-boot-[A-Za-z0-9_.-]+)\z/D',$snap))throw new RuntimeException('Only unMotion snapshots are remotely mutable.');}
    if(in_array($verb,['hold','release'],true)&&count($a)===4){
        $holds=[];foreach(unmTransportReservations($peer) as $owner)if(($owner['vmUuid']??'')===($r['vmUuid']??''))foreach((array)($owner['storage']??[]) as $s)if(($s['destination']??'')===$base)$holds[]=!empty($owner['replica'])?'unmotion:replication:'.$owner['id']:'unmotion:'.$owner['id'];
        if(!in_array($a[2],$holds,true)||!str_contains($target,'@'))throw new RuntimeException('Snapshot hold does not belong to this transfer.');return unmTransportRun($a);
    }
    if($verb==='holds'&&count($a)===4&&$a[2]==='-H'&&str_contains($target,'@'))return unmTransportRun($a);
    if($verb==='snapshot'&&count($a)===3&&str_contains($target,'@'))return unmTransportRun($a);
    if($verb==='receive'){
        if(count($a)===4&&$a[2]==='-A'&&!str_contains($target,'@'))return unmTransportRun($a);
        if(str_contains($target,'@')||($a[2]??'')!=='-su')throw new RuntimeException('Only nonrecursive resumable receives are allowed.');
        $props=[];for($i=3;$i<count($a)-1;$i+=2){if($a[$i]!=='-o'||!isset($a[$i+1]))throw new RuntimeException('Unsafe ZFS receive options.');$p=explode('=',$a[$i+1],2);if(count($p)!==2||isset($props[$p[0]]))throw new RuntimeException('Invalid duplicate ZFS property.');$props[$p[0]]=$p[1];}
        foreach($props as $key=>$value)unmTransportZfsProperty($key,$value,$object,!empty($r['replica']));
        // Never inherit share/export/mount/execution properties from a remote stream.
        $safe=['readonly'=>'on'];
        $safe+=($object['kind']==='dataset'?['setuid'=>'off','devices'=>'off','exec'=>'off','sharenfs'=>'off','sharesmb'=>'off','canmount'=>'off','mountpoint'=>'none']:['volmode'=>'none','snapdev'=>'hidden']);
        $safe=array_replace($safe,$props);$cmd=['zfs','receive','-su'];foreach($safe as $key=>$value){$cmd[]='-o';$cmd[]=$key.'='.$value;}$cmd[]=$target;
        return unmTransportReceiveZfs($cmd,$object['kind']);
    }
    if($verb==='set'&&count($a)===4&&!str_contains($target,'@')){
        $p=explode('=',$a[2],2);if(count($p)!==2)throw new RuntimeException('Invalid ZFS property.');unmTransportZfsProperty($p[0],$p[1],$object,!empty($r['replica']));return unmTransportRun($a);
    }
    if($verb==='mount'&&count($a)===3&&$object['kind']==='dataset'&&empty($r['replica'])){
        $mp=(string)($object['mountpoint']??'');unmTransportStoragePath($mp);$actual=unmTransportRun(['zfs','get','-H','-o','value','mountpoint',$base]);if($actual['code']!==0||trim($actual['stdout'])!==$mp)throw new RuntimeException('ZFS mountpoint is not the reserved VM directory.');return unmTransportRun($a);
    }
    if($verb==='destroy'&&in_array(count($a),[3,4],true)){
        if(count($a)===4&&$a[2]!=='-r')throw new RuntimeException('Unsafe ZFS destroy option.');
        if(str_contains($target,'@')){if(count($a)!==3)throw new RuntimeException('Recursive snapshot destruction is prohibited.');return unmTransportRun(['zfs','destroy',$target]);}
        // Legacy -r intent is implemented as exact snapshots + exact dataset only.
        $children=unmTransportRun(['zfs','list','-H','-r','-o','name',$base]);if($children['code']!==0)return $children;
        if(array_values(array_filter(explode("\n",trim($children['stdout']))))!==[$base])throw new RuntimeException('Child datasets block transfer removal.');
        $snaps=unmTransportRun(['zfs','list','-H','-d','1','-t','snapshot','-o','name',$base]);if($snaps['code']!==0)return $snaps;
        foreach(array_filter(explode("\n",trim($snaps['stdout']))) as $snapshot){if(!str_starts_with($snapshot,$base.'@unmotion-')&&!str_starts_with($snapshot,$base.'@pre-first-boot-'))throw new RuntimeException('Unrelated snapshots block removal.');$x=unmTransportRun(['zfs','destroy',$snapshot]);if($x['code']!==0)return $x;}
        return unmTransportRun(['zfs','destroy',$base]);
    }
    throw new RuntimeException('Unsupported ZFS transport operation.');
}

function unmTransportZfsProperty(string $key,string $value,array $object,bool $replica): void {
    $allowed=['readonly'=>['on','off'],'dedup'=>['off','on','verify'],'compression'=>['off','on','lz4','zle','lzjb','gzip','gzip-1','gzip-2','gzip-3','gzip-4','gzip-5','gzip-6','gzip-7','gzip-8','gzip-9','zstd','zstd-fast'],'canmount'=>['on','off','noauto'],'volmode'=>['none','dev','default'],'snapdev'=>['hidden']];
    if($key==='compression'&&preg_match('/\Azstd-(?:[1-9]|1[0-9])\z/D',$value))return;
    if($key==='mountpoint'){
        if($value==='none')return;if($replica||($object['kind']??'')!=='dataset'||$value!==($object['mountpoint']??''))throw new RuntimeException('Mountpoint is outside the reserved VM dataset.');unmTransportStoragePath($value);return;
    }
    if(!isset($allowed[$key])||!in_array($value,$allowed[$key],true))throw new RuntimeException('ZFS property is not permitted.');
    if($replica&&(($key==='readonly'&&$value!=='on')||($key==='canmount'&&$value!=='off')||($key==='volmode'&&$value!=='none')))throw new RuntimeException('Replicas must remain inert.');
}

function unmTransportFindVm(array $peer,string $uuid): array {
    unmTransportUuid($uuid);foreach(unmTransportReservations($peer) as $r)if(($r['vmUuid']??'')===$uuid)return $r;throw new RuntimeException('The VM is not owned by this peer transfer.');
}

function unmTransportMetadataOwner(array $peer,string $path,?string $expectedId=null): array {
    unmTransportPath($path);
    foreach(unmTransportReservations($peer) as $r){
        if($expectedId!==null&&($r['id']??'')!==$expectedId)continue;
        if($path===UNM_BOOT_DIR.'/incoming/seeds/'.$r['id'].'.json'||$path===UNM_OWNERSHIP_DIR.'/'.$r['vmUuid'].'.json')return $r;
    }
    throw new RuntimeException('Metadata does not belong to this peer.');
}

function unmTransportJsonWrite(array $peer,string $path,string $encoded): array {
    $raw=base64_decode($encoded,true);if($raw===false||strlen($raw)>524288)throw new RuntimeException('Invalid metadata payload.');$d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Invalid metadata.');$r=unmTransportMetadataOwner($peer,$path,(string)($d['jobId']??$d['id']??''));if(($d['vmUuid']??'')!==$r['vmUuid'])throw new RuntimeException('Metadata VM identity mismatch.');
    if(dirname($path)===UNM_BOOT_DIR.'/incoming/seeds'){
        if(($d['id']??'')!==$r['id']||($d['peerHostId']??'')!==unmHostId())throw new RuntimeException('Seed metadata identity mismatch.');
        foreach((array)($d['storage']??[]) as $s){$owned=unmTransportOwned($peer,(string)($s['destination']??''),in_array($s['kind']??'',['dataset','zvol'],true)?'dataset':'file');if(($owned['vmUuid']??'')!==$r['vmUuid'])throw new RuntimeException('Seed storage identity mismatch.');}
    }else{
        if(($d['jobId']??'')!==$r['id']||!in_array($d['ownerHostId']??'',[unmHostId(),$peer['hostId']],true)||!in_array($d['state']??'',['owned','staged'],true))throw new RuntimeException('Ownership metadata identity mismatch.');
    }
    $d['sourceHostId']=$peer['hostId'];$d['transportPeerId']=$peer['id'];unmAtomicJson($path,$d);return unmTransportResult();
}

function unmTransportCall(array $a,?array $peer,string $keyId): array {
    foreach($a as $v)if(!is_string($v)||strlen($v)>UNM_TRANSPORT_MAX_REQUEST||str_contains($v,"\0"))throw new RuntimeException('Invalid operation argument.');
    $cmd=$a[0]??'';$n=count($a);
    if($cmd==='/usr/local/sbin/unmotion-agent')return unmTransportAgent($a,$peer,$keyId);
    if($peer===null||($peer['pairingState']??'')!=='paired')throw new RuntimeException('Complete reciprocal pairing before using transport operations.');
    if($a===['true'])return unmTransportResult();
    if($a===['printf','MISSING'])return unmTransportResult(0,'MISSING');
    if($a===['printf','%s\\n','-'])return unmTransportResult(0,"-\n");
    if($a===['exit','75'])return unmTransportResult(75);
    if($cmd==='['){if(end($a)!==']')throw new RuntimeException('Invalid test.');array_pop($a);$a[0]='test';$cmd='test';$n=count($a);}
    if($cmd==='test'){
        $neg=($a[1]??'')==='!';$i=$neg?2:1;$type=$a[$i]??'';$p=$a[$i+1]??'';
        if(count($a)!==$i+2||!in_array($type,['-e','-f','-L','-d'],true))throw new RuntimeException('Unsupported remote test.');unmTransportReadable($peer,$p);
        $ok=match($type){'-e'=>file_exists($p),'-f'=>is_file($p),'-d'=>is_dir($p),'-L'=>is_link($p)};return unmTransportResult(($neg?!$ok:$ok)?0:1);
    }
    if($cmd==='zfs')return unmTransportZfs($peer,$a);
    if($cmd==='zpool'&&$n===6&&array_slice($a,1,4)===['list','-pH','-o','free']){unmTransportDatasetReadable($peer,$a[5]);return unmTransportRun($a);}
    if($cmd==='stat'){
        $clean=array_values(array_filter($a,static fn($v)=>$v!=='--'));$target=(string)end($clean);unmTransportReadable($peer,$target);
        if((count($clean)===4&&$clean[1]==='-c'&&in_array($clean[2],['%s','%A','%b %B'],true))||(count($clean)===5&&$clean[1]==='-f'&&$clean[2]==='-c'&&$clean[3]==='%d %a %S'))return unmTransportRun($a);
        throw new RuntimeException('Unsupported file statistic.');
    }
    if($cmd==='sha256sum'&&in_array($n,[2,3],true)&&($n===2||$a[1]==='--')){unmTransportReadable($peer,(string)end($a));if(!is_file((string)end($a)))return unmTransportResult(1);return unmTransportRun(['sha256sum','--',(string)end($a)]);}
    if($cmd==='cat'&&$n===2){unmTransportReadable($peer,$a[1]);if(dirname($a[1])!==UNM_OWNERSHIP_DIR)throw new RuntimeException('Only VM ownership metadata is remotely readable as text.');if(!is_file($a[1]))return unmTransportResult(1);return unmTransportResult(0,(string)file_get_contents($a[1]));}
    if($cmd==='mkdir'&&$n===3&&$a[1]==='-p')return unmTransportMkdir($peer,$a[2]);
    if($cmd==='chmod'&&$n===3&&in_array($a[1],['400','0400','600','0600'],true)){
        try{$r=unmTransportOwned($peer,$a[2]);$vmLock=unmTransportVmLock((string)$r['vmUuid']);unmTransportVmOff((string)$r['vmUuid']);}catch(Throwable $e){unmTransportMetadataOwner($peer,$a[2]);}
        return unmTransportResult(@chmod($a[2],octdec($a[1]))?0:1);
    }
    if($cmd==='rm'&&$n===4&&$a[2]==='--'&&in_array($a[1],['-f','-rf'],true)){
        $p=unmTransportPath($a[3]);
        if($a[1]==='-rf'){
            $id=basename($p);if(dirname($p)!==UNM_BOOT_DIR.'/incoming')throw new RuntimeException('Only an exact incoming stage can be removed.');$r=unmLoadJson(unmTransportReservationPath($peer,$id));if(!$r)throw new RuntimeException('Incoming stage has no peer ownership.');
            if(!file_exists($p))return unmTransportResult();foreach(scandir($p)?:[] as $name){if($name==='.'||$name==='..')continue;$child=unmTransportPath($p.'/'.$name);if(!is_file($child))throw new RuntimeException('Incoming stage contains an unexpected directory; preserve it for inspection.');}
            foreach(scandir($p)?:[] as $name)if($name!=='.'&&$name!=='..'&&!unlink($p.'/'.$name))throw new RuntimeException('Unable to remove stage file.');return unmTransportResult(rmdir($p)?0:1);
        }
        try{$r=unmTransportOwned($peer,$p);$vmLock=unmTransportVmLock((string)$r['vmUuid']);unmTransportVmOff((string)$r['vmUuid']);}catch(Throwable $e){unmTransportMetadataOwner($peer,$p);}
        return unmTransportResult(!file_exists($p)||@unlink($p)?0:1);
    }
    if($cmd==='virsh'){
        $verb=$a[1]??'';
        if(in_array($verb,['domname','domstate','domuuid','dumpxml'],true)&&($n===3||($n===4&&$verb==='dumpxml'&&$a[3]==='--inactive'))){if($a[2]===''||str_starts_with($a[2],'-')||preg_match('/[\x00-\x1f\x7f]/',$a[2]))throw new RuntimeException('Invalid VM query.');return unmTransportRun($a);}
        if($verb==='define'&&$n===3){$r=unmTransportOwned($peer,$a[2]);$vmLock=unmTransportVmLock((string)$r['vmUuid']);if(basename($a[2])!=='destination.xml')throw new RuntimeException('Only the reserved VM definition can be defined.');unmTransportVmOff((string)$r['vmUuid']);unmTransportValidateDomainXml((string)file_get_contents($a[2]),(string)$r['vmUuid'],$peer);return unmTransportRun($a);}
        if(in_array($verb,['start','autostart'],true)&&$n===3){$r=unmTransportFindVm($peer,$a[2]);$vmLock=unmTransportVmLock((string)$r['vmUuid']);if(!empty($r['replica']))throw new RuntimeException('Replicas may only be activated through coordinated recovery.');$xml=unmDomainXml($a[2],true);unmTransportValidateDomainXml($xml,$a[2],$peer);return unmTransportRun($a);}
    }
    if($cmd==='/usr/local/sbin/unmotion-start-destination'&&$n===3){$r=unmTransportFindVm($peer,$a[1]);$vmLock=unmTransportVmLock((string)$r['vmUuid']);if(!empty($r['replica'])||!preg_match('/\A[1-9][0-9]{0,12}\z/D',$a[2]))throw new RuntimeException('Invalid destination start.');unmTransportValidateDomainXml(unmDomainXml($a[1],true),$a[1],$peer);return unmTransportRun($a);}
    if($cmd==='tar'&&$n===8&&array_slice($a,1,6)===['--xattrs','--acls','--numeric-owner','-C','/','-xzf']){
        $r=unmTransportOwned($peer,$a[7]);$vmLock=unmTransportVmLock((string)$r['vmUuid']);unmTransportVmOff((string)$r['vmUuid']);unmTransportArchive($a[7],$r,true);return unmTransportResult();
    }
    throw new RuntimeException('Remote command is not an allowed unMotion operation: '.$cmd);
}

function unmTransportTree(array $tree,?array $peer,string $keyId,int $depth=0): array {
    if($depth>20)throw new RuntimeException('Transport nesting limit exceeded.');$op=$tree['op']??'';
    if($op==='call'){$r=unmTransportCall((array)($tree['argv']??[]),$peer,$keyId);if(!empty($tree['stderrToOut'])){$r['stdout'].=$r['stderr'];$r['stderr']='';}if(!empty($tree['stdoutNull']))$r['stdout']='';if(!empty($tree['stderrNull']))$r['stderr']='';return $r;}
    if($op==='constant'&&($tree['code']??null)===0)return unmTransportResult();
    if($op==='json-write'){if($peer===null||($peer['pairingState']??'')!=='paired')throw new RuntimeException('Pairing required.');return unmTransportJsonWrite($peer,(string)($tree['path']??''),(string)($tree['data']??''));}
    if($op==='sha-first'){$r=unmTransportTree((array)$tree['child'],$peer,$keyId,$depth+1);if($r['code']===0)$r['stdout']=explode(' ',trim($r['stdout']),2)[0]."\n";return $r;}
    if($op==='sequence'){$out='';$err='';$last=unmTransportResult();if(count((array)($tree['items']??[]))>32)throw new RuntimeException('Too many transport operations.');foreach((array)$tree['items'] as $item){$last=unmTransportTree($item,$peer,$keyId,$depth+1);$out.=$last['stdout'];$err.=$last['stderr'];}return unmTransportResult($last['code'],$out,$err);}
    if(in_array($op,['and','or'],true)){$left=unmTransportTree((array)$tree['left'],$peer,$keyId,$depth+1);if(($op==='and'&&$left['code']===0)||($op==='or'&&$left['code']!==0)){$right=unmTransportTree((array)$tree['right'],$peer,$keyId,$depth+1);return unmTransportResult($right['code'],$left['stdout'].$right['stdout'],$left['stderr'].$right['stderr']);}return $left;}
    if($op==='if'){$condition=unmTransportTree((array)$tree['condition'],$peer,$keyId,$depth+1);$r=unmTransportTree((array)$tree[$condition['code']===0?'yes':'no'],$peer,$keyId,$depth+1);return unmTransportResult($r['code'],$condition['stdout'].$r['stdout'],$condition['stderr'].$r['stderr']);}
    throw new RuntimeException('Unknown structured transport operation.');
}

function unmTransportDispatch(array $request,string $keyId): array {
    if(($request['v']??null)!==7)throw new RuntimeException('Protocol 7 is required.');$peer=unmTransportPeer($keyId);
    if($request['op']==='operations')return unmTransportTree((array)($request['args']['tree']??[]),$peer,$keyId);
    if($request['op']==='rsync-receive'&&$peer!==null&&($peer['pairingState']??'')==='paired')return unmTransportRsync($peer,(string)($request['args']['path']??''));
    throw new RuntimeException('Unsupported unMotion RPC.');
}

function unmTransportVmLock(string $uuid) {
    $uuid=unmTransportUuid($uuid);$dir=unmTransportRuntimeDirectory();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create transport lock directory.');
    $h=fopen($dir.'/'.$uuid.'.lock','c');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Unable to lock transfer VM.');return $h;
}

function unmTransportRsyncConfiguration(string $parent,string $filterFile): string {
    unmTransportPath($parent);unmTransportPath($filterFile);
    $quote=static fn(string $v):string=>str_replace('%','%%',$v);
    return "[transfer]\npath = ".$quote($parent)."\nuse chroot = yes\nread only = no\nwrite only = yes\nlist = no\nuid = 0\ngid = 0\nnumeric ids = yes\nmunge symlinks = yes\nincoming chmod = F600,D700\ntimeout = 600\nfilter = merge ".$quote($filterFile)."\nrefuse options = * !recursive !times !sparse !numeric-ids !partial !inplace !whole-file !no-whole-file !W !no-W !bwlimit !info !human-readable !stats !verbose !checksum !size-only !ignore-times write-devices copy-devices links hard-links devices specials delete remove-source-files perms owner group acls xattrs files-from backup backup-dir temp-dir partial-dir link-dest copy-dest compare-dest\n";
}

function unmTransportRsync(array $peer,string $path): array {
    $r=unmTransportOwned($peer,$path);$lock=unmTransportVmLock((string)$r['vmUuid']);unmTransportVmOff((string)$r['vmUuid']);
    // Excluding symlinks/hardlinks before starting is necessary even with chroot.
    unmTransportPath($path);$parent=dirname($path);unmTransportMkdir($peer,$parent);
    foreach((array)$r['storage'] as $s)if(($s['destination']??'')===$path&&($s['kind']??'')==='iso'&&file_exists($path))throw new RuntimeException('Existing ISOs must be checksum-reused, never overwritten.');
    $tmp=unmTransportRuntimeDirectory().'/session-'.bin2hex(random_bytes(12));if(!mkdir($tmp,0700))throw new RuntimeException('Unable to create isolated transfer configuration.');
    $filter=$tmp.'/filter';$config=$tmp.'/rsyncd.conf';
    // Literal anchored filename; no client-controlled filter syntax or wildcard.
    $name=strtr(basename($path),['\\'=>'\\\\','*'=>'\\*','?'=>'\\?','['=>'\\[',']'=>'\\]']);
    try{
        if(file_put_contents($filter,'+ /'.$name."\n- /***\n")===false||file_put_contents($config,unmTransportRsyncConfiguration($parent,$filter))===false)throw new RuntimeException('Unable to write transfer configuration.');chmod($filter,0600);chmod($config,0600);
        $result=unmTransportRun(['rsync','--server','--daemon','--config='.$config,'.'],true);
        if($result['code']===0){
            unmTransportPath($path);if(!is_file($path))throw new RuntimeException('The peer did not transfer the reserved regular file.');chmod($path,0600);
            if(str_ends_with($path,'.tar.gz'))unmTransportArchive($path,$r,false);
            if(basename($path)==='destination.xml')unmTransportValidateDomainXml((string)file_get_contents($path),(string)$r['vmUuid'],$peer);
        }
        return $result;
    }finally{@unlink($filter);@unlink($config);@rmdir($tmp);if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}

/** Reject libvirt features that can turn a VM definition into host code/file access. */
function unmTransportValidateDomainXml(string $xml,string $uuid,?array $peer): void {
    unmTransportUuid($uuid);if(strlen($xml)>2097152)throw new RuntimeException('VM XML is too large.');
    $doc=new DOMDocument();if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype||$doc->documentElement->nodeName!=='domain')throw new RuntimeException('Invalid VM XML.');
    $xp=new DOMXPath($doc);if(strtolower(trim($xp->evaluate('string(/domain/uuid)')))!==$uuid||$xp->query('/domain/uuid')->length!==1)throw new RuntimeException('VM XML identity does not match its reservation.');
    if(!in_array($doc->documentElement->getAttribute('type'),['kvm','qemu'],true))throw new RuntimeException('Only QEMU/KVM domains are allowed.');
    $forbidden='/domain/os/kernel|/domain/os/initrd|/domain/os/dtb|/domain/os/init|/domain/os/initarg|/domain/devices/filesystem|/domain/devices/shmem|/domain/devices/smartcard|/domain/devices/crypto|/domain/devices/pstore|/domain/devices/interface/script|/domain/devices/interface/backend|/domain/devices/disk/backingStore[* or @*]|/domain/devices/disk/dataStore|/domain/devices/disk/auth|/domain/devices/disk/encryption|/domain/devices/*/rom[@file]|/domain/sysinfo/entry[@file]|/domain/devices/hostdev[@type!="usb"]';
    if($xp->query($forbidden)->length)throw new RuntimeException('VM XML includes host-execution, external backing, or unsupported host-device features.');
    foreach($xp->query('//*') as $node){
        if($node->namespaceURI!==null&&$node->namespaceURI!==''){
            $metadata=false;for($p=$node;$p instanceof DOMElement;$p=$p->parentNode)if($p->nodeName==='metadata')$metadata=true;
            if(!$metadata)throw new RuntimeException('Executable libvirt extension namespaces are not permitted.');
        }
    }
    $emulator=trim($xp->evaluate('string(/domain/devices/emulator)'));
    if($emulator!==''&&!in_array($emulator,['/usr/bin/qemu-system-x86_64','/usr/local/bin/qemu-system-x86_64','/usr/local/sbin/qemu'],true))throw new RuntimeException('Custom emulators are not permitted.');
    foreach($xp->query('/domain/devices/disk') as $disk){
        if(!in_array($disk->getAttribute('device'),['disk','cdrom'],true))throw new RuntimeException('Only ordinary disks and CD-ROMs are supported by restricted transfers.');
        $type=$disk->getAttribute('type');if(!in_array($type,['file','block'],true))throw new RuntimeException('Only file/zvol disks are remotely definable.');
        $source=$disk->getElementsByTagName('source');if($source->length===0&&$disk->getAttribute('device')==='cdrom')continue;if($source->length!==1)throw new RuntimeException('Invalid disk source.');
        $node=$source->item(0);$path=$node->getAttribute($type==='file'?'file':'dev');
        $driver=$disk->getElementsByTagName('driver')->item(0);$format=$driver instanceof DOMElement?$driver->getAttribute('type'):'';
        if($disk->getAttribute('device')==='cdrom'){if(!in_array($format,['','raw'],true))throw new RuntimeException('CD-ROM images must use raw format.');}
        elseif(!in_array($format,['raw','qcow2'],true))throw new RuntimeException('Only explicit raw or qcow2 disk formats are accepted.');
        if($type==='block'){
            if(!str_starts_with($path,'/dev/zvol/'))throw new RuntimeException('Only reserved ZFS volumes may be attached as block devices.');unmTransportDataset(substr($path,10));if($peer!==null){$r=unmTransportOwned($peer,substr($path,10),'dataset');if($r['vmUuid']!==$uuid)throw new RuntimeException('Disk reservation belongs to another VM.');}
        }else{
            if($path===''||!str_starts_with($path,'/mnt/')||preg_match('~[\x00-\x1f\x7f]|//|/(?:\.|\.\.)(?:/|$)~',$path))throw new RuntimeException('Invalid VM disk path.');
            if($peer!==null){
                unmTransportPath($path);$allowed=false;
                foreach(unmTransportReservations($peer) as $r){if(($r['vmUuid']??'')!==$uuid)continue;foreach((array)$r['storage'] as $s)if(($s['destination']??'')===$path||(($s['kind']??'')==='dataset'&&($s['mountpoint']??'')!==''&&unmTransportWithin($path,$s['mountpoint'])))$allowed=true;}
                if(!$allowed&&$disk->getAttribute('device')==='cdrom'){unmTransportStoragePath($path,'iso');$allowed=is_file($path);}
                if(!$allowed)throw new RuntimeException('VM disk is outside this VM transfer reservation.');
            }
        }
    }
    $name=trim($xp->evaluate('string(/domain/name)'));
    // Any path-bearing attribute not explicitly handled below is rejected.
    foreach($xp->query('//@*') as $attr){
        $element=$attr->ownerElement;$parent=$element->parentNode;$value=$attr->value;$tag=$element->nodeName;$key=$attr->nodeName;
        if(!in_array($key,['path','file','dev','dir','socket','template','logFile'],true))continue;
        if($tag==='source'&&$parent instanceof DOMElement&&$parent->nodeName==='disk'&&in_array($key,['file','dev'],true))continue;
        if($tag==='target'&&$key==='dev'&&!str_contains($value,'/'))continue;
        if($tag==='boot'&&$key==='dev'&&in_array($value,['hd','cdrom','network','fd'],true))continue;
        if($tag==='nvram'&&$key==='template'&&preg_match('~\A/usr/share/(?:qemu|OVMF|edk2)/[^\x00-\x1f]*\z~D',$value)&&!str_contains($value,'..'))continue;
        if($tag==='source'&&$parent instanceof DOMElement&&in_array($parent->nodeName,['serial','console'],true)&&$parent->getAttribute('type')==='pty'&&preg_match('~\A/dev/pts/[0-9]+\z~D',$value))continue;
        if($tag==='source'&&$parent instanceof DOMElement&&$parent->nodeName==='channel'&&$parent->getAttribute('type')==='unix'&&$key==='path'&&preg_match('~\A/var/lib/libvirt/qemu/channel/target/(?:domain-[0-9]+-'.preg_quote($name,'~').'|'.preg_quote($uuid,'~').')/org\.qemu\.guest_agent\.0\z~D',$value))continue;
        if(in_array($tag,['backend','source'],true)&&$key==='path'&&unmTransportHostStatePath($value,$uuid))continue;
        throw new RuntimeException('VM XML contains an unsupported host path: '.$tag.'/@'.$key);
    }
    foreach($xp->query('/domain/os/loader') as $loader){$path=trim($loader->textContent);if(!preg_match('~\A/usr/share/(?:qemu|OVMF|edk2)/[^\x00-\x1f]*\z~D',$path)||str_contains($path,'..')||($loader->getAttribute('readonly')!==''&&$loader->getAttribute('readonly')!=='yes'))throw new RuntimeException('Firmware loader must be read-only system firmware.');}
    foreach($xp->query('/domain/os/nvram') as $nv){$path=trim($nv->textContent);if($peer!==null){if(unmTransportHostStatePath($path,$uuid)){$owner=unmTransportOwned($peer,$path);if(($owner['vmUuid']??'')!==$uuid)throw new RuntimeException('NVRAM is not reserved by this VM.');}else unmTransportVmFile($peer,$uuid,$path);}elseif(!unmTransportHostStatePath($path,$uuid)&&!str_starts_with($path,'/mnt/'))throw new RuntimeException('NVRAM is not UUID-scoped or pool-resident.');}
    foreach($xp->query('/domain/devices/rng/backend') as $rng)if($rng->getAttribute('model')!=='random'||!in_array(trim($rng->textContent),['/dev/random','/dev/urandom'],true))throw new RuntimeException('Unsupported host entropy backend.');
    foreach($xp->query('/domain/devices/tpm/backend') as $tpm)if($tpm->getAttribute('type')!=='emulator')throw new RuntimeException('Only software TPM state is supported.');
    if($peer!==null&&$xp->query('/domain/devices/tpm')->length){$found=false;foreach(unmTransportReservations($peer) as $r)if(($r['vmUuid']??'')===$uuid)foreach((array)($r['hostStatePaths']??[]) as $path)if(unmTpmPathAllowed($path,$uuid))$found=true;if(!$found)throw new RuntimeException('Software TPM is not reserved by this VM transfer.');}
    foreach($xp->query('/domain/devices/serial|/domain/devices/console|/domain/devices/parallel') as $device)if(!in_array($device->getAttribute('type'),['pty','null'],true))throw new RuntimeException('File/network host character devices are unsupported.');
    foreach($xp->query('/domain/devices/channel') as $channel)if(!in_array($channel->getAttribute('type'),['unix','spicevmc'],true))throw new RuntimeException('Unsupported host channel.');
    if($peer!==null)unmTransportValidateDiskImages($xml);
}

function unmTransportValidateDiskImages(string $xml): void {
    $doc=new DOMDocument();if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Invalid disk XML.');$xp=new DOMXPath($doc);
    foreach($xp->query('/domain/devices/disk') as $disk){
        if(!in_array($disk->getAttribute('device'),['disk','cdrom'],true))throw new RuntimeException('Unsupported VM disk device.');
        $source=$disk->getElementsByTagName('source')->item(0);if(!$source&&$disk->getAttribute('device')==='cdrom')continue;if(!$source)throw new RuntimeException('Disk source is unavailable.');
        $driver=$disk->getElementsByTagName('driver')->item(0);$format=$driver instanceof DOMElement?$driver->getAttribute('type'):'';
        $path=$source->getAttribute($disk->getAttribute('type')==='block'?'dev':'file');if(!str_starts_with($path,'/mnt/')&&!str_starts_with($path,'/dev/zvol/'))throw new RuntimeException('Disk image is outside VM storage.');
        if($disk->getAttribute('type')==='file')unmTransportPath($path);
        if($disk->getAttribute('device')==='cdrom'){if(!in_array($format,['','raw'],true))throw new RuntimeException('Unsupported CD-ROM format.');unmTransportStoragePath($path,'iso');continue;}
        if(!in_array($format,['raw','qcow2'],true))throw new RuntimeException('Only explicit raw/qcow2 images are allowed.');
        if($format==='qcow2'){$info=unmTransportRun(['qemu-img','info','--output=json','-f','qcow2',$path]);$d=json_decode($info['stdout'],true);if($info['code']!==0||!is_array($d)||isset($d['backing-filename'])||!empty($d['format-specific']['data']['data-file']))throw new RuntimeException('QCOW2 backing or external data files are unsupported by restricted transfers.');}
    }
}

function unmTransportVmFile(array $peer,string $uuid,string $path): void {
    unmTransportPath($path);foreach(unmTransportReservations($peer) as $r){if(($r['vmUuid']??'')!==$uuid)continue;foreach((array)$r['storage'] as $s)if(($s['destination']??'')===$path||(($s['kind']??'')==='dataset'&&($s['mountpoint']??'')!==''&&unmTransportWithin($path,$s['mountpoint'])))return;}
    throw new RuntimeException('File is not part of this VM transfer reservation.');
}

/** Parse the archive ourselves: no tar extraction and no links, devices or PAX side effects. */
function unmTransportArchive(string $archive,array $reservation,bool $install=false): void {
    unmTransportPath($archive);$uuid=unmTransportUuid((string)$reservation['vmUuid']);$roots=(array)($reservation['hostStatePaths']??[]);
    if(!empty($reservation['replica'])){$roots=array_map(static fn($r)=>$r.'/'.$uuid,unmTpmRoots());$roots[]='/etc/libvirt/qemu/nvram/'.$uuid.'_VARS.fd';$roots[]='/var/lib/libvirt/qemu/nvram/'.$uuid.'_VARS.fd';}
    $scan=function(bool $write)use($archive,$roots,$uuid,$reservation):void{
        $fh=gzopen($archive,'rb');if(!$fh)throw new RuntimeException('Unable to inspect host-state archive.');$total=0;$count=0;$pendingPath=null;$entries=[];
        $read=static function($f,int $size):string{$s='';while(strlen($s)<$size){$b=gzread($f,$size-strlen($s));if($b===false||$b==='')throw new RuntimeException('Truncated host-state archive.');$s.=$b;}return $s;};
        try{while(!gzeof($fh)){
            $header=$read($fh,512);if($header===str_repeat("\0",512))break;if(++$count>10000)throw new RuntimeException('Too many archive entries.');
            $number=static function(string $raw):int{$s=trim($raw," \0");if($s===''||!preg_match('/\A[0-7]{1,11}\z/D',$s))throw new RuntimeException('Invalid archive numeric field.');return intval($s,8);};
            $checksum=$number(substr($header,148,8));$sum=array_sum(unpack('C*',substr_replace($header,str_repeat(' ',8),148,8)));if($checksum!==$sum)throw new RuntimeException('Archive header checksum mismatch.');
            $size=$number(substr($header,124,12));$total+=$size;if($size>67108864||$total>536870912)throw new RuntimeException('Host-state archive exceeds safety bounds.');$type=$header[156];$body=$size?$read($fh,$size):'';if($size%512)$read($fh,512-$size%512);
            if($type==='x'){
                $offset=0;while($offset<strlen($body)){if(!preg_match('/\G([1-9][0-9]*) /A',$body,$m,0,$offset))throw new RuntimeException('Invalid archive PAX record.');$len=(int)$m[1];if($len<strlen($m[0])+2||$offset+$len>strlen($body))throw new RuntimeException('Invalid PAX length.');$record=substr($body,$offset+strlen($m[0]),$len-strlen($m[0]));$offset+=$len;$parts=explode('=',rtrim($record,"\n"),2);if(count($parts)!==2)throw new RuntimeException('Invalid PAX key.');if($parts[0]==='path')$pendingPath=$parts[1];elseif(in_array($parts[0],['linkpath','size','GNU.sparse.map','GNU.sparse.name'],true)||str_starts_with($parts[0],'GNU.sparse'))throw new RuntimeException('Unsupported PAX entry.');}
                continue;
            }
            if($type==='L'){$pendingPath=rtrim($body,"\0");continue;}
            if(!in_array($type,["\0",'0','5'],true)||trim(substr($header,157,100),"\0")!=='')throw new RuntimeException('Archive links and special objects are prohibited.');
            $name=rtrim(substr($header,0,100),"\0");$prefix=rtrim(substr($header,345,155),"\0");if($prefix!=='')$name=$prefix.'/'.$name;if($pendingPath!==null){$name=$pendingPath;$pendingPath=null;}$name=rtrim($name,'/');
            if($name===''||$name[0]==='/'||preg_match('~[\x00-\x1f\x7f]|//|(?:^|/)(?:\.|\.\.)(?:/|$)~',$name))throw new RuntimeException('Unsafe archive entry name.');$path='/'.$name;if(isset($entries[$path]))throw new RuntimeException('Duplicate archive target.');$entries[$path]=true;
            $allowed=false;foreach($roots as $root)if($path===$root||(unmTpmPathAllowed($root,(string)basename($root))&&unmTransportWithin($path,$root)))$allowed=true;
            if(!empty($reservation['replica'])&&unmTransportHostStatePath($path,$uuid))$allowed=true;
            // Custom pool-resident NVRAM may be retained as opaque replication
            // evidence. It is never installed by this transport operation.
            if(!$write&&!empty($reservation['replica'])&&str_ends_with(strtolower($path),'.fd'))foreach((array)($reservation['storage']??[]) as $s)foreach((array)($s['files']??[]) as $file){$source=(string)($file['source']??'');if(str_starts_with($path,'/mnt/')&&dirname($path)===dirname($source)&&in_array(basename(dirname($path)),[$uuid,(string)($reservation['vmName']??'')],true)&&$path!==$source)$allowed=true;}
            // Ancestor directory headers are harmless; they are never written.
            if(!$allowed&&$type==='5'){foreach($roots as $root)if(unmTransportWithin($root,$path))$allowed=true;if($allowed)continue;}
            if(!$allowed)throw new RuntimeException('Archive entry is outside reserved UUID-scoped host state.');
            if($write){unmTransportPath($path);if($type==='5'){if(!is_dir($path)&&!mkdir($path,0700,true))throw new RuntimeException('Unable to create TPM directory.');}else{if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true))throw new RuntimeException('Unable to create state parent.');unmTransportPath($path);if(file_put_contents($path,$body,LOCK_EX)!==strlen($body)||!chmod($path,0600))throw new RuntimeException('Unable to install exact host-state file.');}}
        }}finally{gzclose($fh);}
    };
    $scan(false);if($install)$scan(true);
}
