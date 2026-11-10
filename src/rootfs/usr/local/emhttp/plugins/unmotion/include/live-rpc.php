<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/xml-canonical.php';
require_once __DIR__.'/live-storage.php';

/** A deliberately narrow migration-only adapter, NOT a general libvirt proxy.
 * ABI reviewed against libvirt v12.2.0 remote_protocol.x; unknown versions,
 * programs, methods, flags, parameters and trailing bytes fail closed.
 */
final class UnmLiveXdr {
    private int $at=0;
    public function __construct(private string $data){}
    public function raw(int $length): string {if($length<0||$length>strlen($this->data)-$this->at)throw new RuntimeException('Truncated migration RPC.');$v=substr($this->data,$this->at,$length);$this->at+=$length;return $v;}
    public function uint(): int {return unpack('N',$this->raw(4))[1];}
    public function ulong(): int {$v=unpack('Nhi/Nlo',$this->raw(8));if($v['hi']>0x7fffffff)throw new RuntimeException('Migration RPC integer overflow.');return $v['hi']*4294967296+$v['lo'];}
    public function bytes(int $limit=2097152): string {$n=$this->uint();if($n>$limit)throw new RuntimeException('Oversized migration RPC value.');$v=$this->raw($n);$pad=(4-$n%4)%4;if($this->raw($pad)!==str_repeat("\0",$pad))throw new RuntimeException('Invalid RPC padding.');return $v;}
    public function string(int $limit=2097152): string {$v=$this->bytes($limit);if(str_contains($v,"\0")||preg_match('//u',$v)!==1)throw new RuntimeException('Invalid migration RPC string.');return $v;}
    public function optional(): ?string {$n=$this->uint();if($n>1)throw new RuntimeException('Invalid RPC option.');return $n?$this->string():null;}
    public function params(): array {
        $count=$this->uint();if($count>32)throw new RuntimeException('Too many migration RPC parameters.');$out=[];
        for($i=0;$i<$count;$i++){
            $name=$this->string(96);$type=$this->uint();
            $value=match($type){1,2,6=>$this->uint(),3,4=>$this->ulong(),7=>$this->string(),default=>throw new RuntimeException('Unsupported RPC parameter type.')};
            $out[]=['name'=>$name,'type'=>$type,'value'=>$value];
        }return $out;
    }
    public function end(): void {if($this->at!==strlen($this->data))throw new RuntimeException('Trailing migration RPC data.');}
}

function unmLiveXmlHash(string $xml): string {
    if(strlen($xml)>2097152||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))throw new RuntimeException('Invalid migration XML.');
    $d=new DOMDocument();$old=libxml_use_internal_errors(true);try{$ok=$d->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    if(!$ok||$d->documentElement?->tagName!=='domain')throw new RuntimeException('Invalid migration domain.');
    $d->documentElement->removeAttribute('id');return hash('sha256',unmXmlCanonical($d));
}

final class UnmLiveRpcGuard {
    private bool $opened=false;private bool $prepared=false;private bool $finished=false;
    private array $pending=[];
    public function __construct(private array $job,private $audit=null,private $prepare=null){}
    private function heartbeat(array $h,string $frame): bool {
        if($h['program']!==0x6b656570)return false;
        if(strlen($frame)!==28||$h['length']!==28||$h['version']!==1||$h['type']!==2||$h['serial']!==0||$h['status']!==0||!in_array($h['procedure'],[1,2],true))throw new RuntimeException('Invalid migration keepalive.');return true;
    }
    public function request(string $frame): array {
        if(strlen($frame)<28||strlen($frame)>4194304)throw new RuntimeException('Invalid migration RPC frame.');
        $h=unpack('Nlength/Nprogram/Nversion/Nprocedure/Ntype/Nserial/Nstatus',substr($frame,0,28));
        if($this->heartbeat($h,$frame))return $h;
        if($h['length']!==strlen($frame)||$h['program']!==0x20008086||$h['version']!==1||$h['type']!==0||$h['status']!==0||isset($this->pending[$h['serial']])||count($this->pending)>=8)throw new RuntimeException('Unsupported migration RPC header.');
        $x=new UnmLiveXdr(substr($frame,28));$method=$h['procedure'];if($this->audit)($this->audit)('inspect',['method'=>$method,'serial'=>$h['serial']]);
        if($method===66){if($this->opened)throw new RuntimeException('Repeated RPC authentication.');}
        elseif($method===60){if($x->uint()>64)throw new RuntimeException('Unknown libvirt feature query.');}
        elseif($method===1){if($this->opened)throw new RuntimeException('Repeated RPC open.');$name=$x->optional();if(!in_array($name,[null,'qemu:///system'],true)||$x->uint()!==0)throw new RuntimeException('Only the system QEMU driver is permitted.');$this->opened=true;}
        elseif(!$this->opened)throw new RuntimeException('Migration RPC is not open.');
        elseif(in_array($method,[2,3,4,59,110,149,157],true)){}
        elseif(in_array($method,[303,306],true)){
            $params=$x->params();$x->bytes(262144);$flags=$x->uint();
            // The client strips source-only CHANGE_PROTECTION/ABORT_ON_ERROR
            // before calling the destination. Exactly LIVE, PERSIST_DEST,
            // PAUSED and NON_SHARED_DISK may cross this boundary.
            if($flags!==105)throw new RuntimeException('Unsafe migration flags: '.$flags.'.');
            $seen=[];$migrated=[];$expectedTargets=isset($this->job['disks'])?array_column(unmLiveDisks($this->job),'target'):[$this->job['diskTarget']];
            foreach($params as $param){
                $key=$param['name'];$value=$param['value'];$type=$param['type'];
                if(isset($seen[$key])&&$key!=='migrate_disks')throw new RuntimeException('Duplicate migration RPC option.');$seen[$key]=true;
                if(in_array($key,['destination_xml','persistent_xml'],true)){
                    $expected=$key==='destination_xml'?$this->job['destinationXml']:$this->job['persistentXml'];
                    if($type!==7||!hash_equals(unmLiveXmlHash($expected),unmLiveXmlHash($value)))throw new RuntimeException('Migration RPC XML changed after reservation.');
                }elseif($key==='destination_name'){if($type!==7||$value!==$this->job['vmName'])throw new RuntimeException('Wrong migration VM name.');}
                elseif($key==='migrate_uri'){if($type!==7||$value!=='unix://'.$this->job['runtime'].'/memory.sock')throw new RuntimeException('Wrong RAM channel.');}
                elseif($key==='disks_uri'){if($type!==7||$value!=='unix://'.$this->job['runtime'].'/disk.sock')throw new RuntimeException('Wrong disk channel.');}
                elseif($key==='migrate_disks'){if($type!==7||!in_array($value,$expectedTargets,true)||isset($migrated[$value]))throw new RuntimeException('Wrong or duplicated migration disk.');$migrated[$value]=true;}
                elseif(in_array($key,['bandwidth','available_switchover_bandwidth'],true)){if($type!==4||$value<0||$value>1048576)throw new RuntimeException('Invalid migration bandwidth.');}
                else throw new RuntimeException('Unsupported migration RPC option: '.$key);
            }
            if(isset($seen['migrate_disks'])&&count($migrated)!==count($expectedTargets))throw new RuntimeException('Every admitted disk must be migrated exactly once.');
            if($method===303){if($this->prepared||$this->finished||!isset($seen['destination_xml'],$seen['migrate_uri'],$seen['disks_uri'],$seen['migrate_disks']))throw new RuntimeException('Invalid migration prepare sequence.');$this->prepared=true;}
            else{if(!$this->prepared||$this->finished||!isset($seen['destination_xml']))throw new RuntimeException('Invalid migration finish sequence.');$cancel=$x->uint();if($cancel>1)throw new RuntimeException('Invalid migration cancellation.');$this->finished=true;}
        }else throw new RuntimeException('Migration RPC method denied: '.$method);
        $x->end();if($method===303&&$this->prepare)($this->prepare)();$this->pending[$h['serial']]=$method;
        if($this->audit)($this->audit)('request',['method'=>$method,'serial'=>$h['serial']]);
        return $h;
    }
    public function response(string $frame): void {
        if(strlen($frame)<28||strlen($frame)>8388608)throw new RuntimeException('Invalid RPC response.');
        $h=unpack('Nlength/Nprogram/Nversion/Nprocedure/Ntype/Nserial/Nstatus',substr($frame,0,28));
        if($this->heartbeat($h,$frame))return;
        if($h['length']!==strlen($frame)||$h['program']!==0x20008086||$h['version']!==1||$h['type']!==1||!in_array($h['status'],[0,1],true)||($this->pending[$h['serial']]??null)!==$h['procedure'])throw new RuntimeException('Unexpected migration RPC response.');
        unset($this->pending[$h['serial']]);if($this->audit)($this->audit)('response',['method'=>$h['procedure'],'status'=>$h['status']]);
    }
}

/** Framed control relay. Binary stdout is never mixed with logging. */
function unmLiveRpcProxy($input,$output,$backend,UnmLiveRpcGuard $guard,callable $alive): void {
    foreach([$input,$output,$backend] as $s)stream_set_blocking($s,false);
    $client='';$server='';$toServer='';$toClient='';$inputOpen=true;$serverOpen=true;$last=time();
    while($inputOpen&&$serverOpen||$toServer!==''||$toClient!==''){
        if(!$alive()||time()-$last>300)throw new RuntimeException('Migration control lease expired.');
        $r=[];if($inputOpen&&strlen($client)+strlen($toServer)<8388608)$r[]=$input;if($serverOpen&&strlen($server)+strlen($toClient)<16777216)$r[]=$backend;
        $w=[];if($toServer!=='')$w[]=$backend;if($toClient!=='')$w[]=$output;$e=[];
        if(!$r&&!$w)break;if(@stream_select($r,$w,$e,1)===false)throw new RuntimeException('Migration RPC stream failed.');
        foreach($r as $s){$part=fread($s,65536);if($part===false)throw new RuntimeException('Migration RPC read failed.');if($part===''){if(feof($s)){if($s===$input)$inputOpen=false;else $serverOpen=false;}continue;}$last=time();if($s===$input)$client.=$part;else $server.=$part;}
        foreach([0,1] as $side){if($side===0)$buffer=&$client;else $buffer=&$server;while(strlen($buffer)>=4){$n=unpack('N',substr($buffer,0,4))[1];if($n<28||$n>($side===0?4194304:8388608))throw new RuntimeException('Oversized migration RPC frame.');if(strlen($buffer)<$n)break;$frame=substr($buffer,0,$n);$buffer=substr($buffer,$n);if($side===0){$guard->request($frame);$toServer.=$frame;}else{$guard->response($frame);$toClient.=$frame;}}unset($buffer);}
        foreach($w as $s){if($s===$backend){$n=fwrite($s,$toServer);if($n===false)throw new RuntimeException('Migration RPC write failed.');$toServer=substr($toServer,$n);}else{$n=fwrite($s,$toClient);if($n===false)throw new RuntimeException('Migration RPC write failed.');$toClient=substr($toClient,$n);}}
    }
    if($client!==''||$server!=='')throw new RuntimeException('Truncated migration RPC stream.');
}
