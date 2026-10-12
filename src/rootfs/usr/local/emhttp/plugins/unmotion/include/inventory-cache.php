<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Display observations only. Never use this cache to authorize a VM/storage operation. */
final class UnmInventoryCache {
    public function __construct(public readonly string $directory, private readonly string $bootId, private readonly string $version) {
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory))) throw new RuntimeException('Inventory cache directory is unavailable.');
        $stat=lstat($directory);
        if (!$stat || $stat['uid']!==posix_geteuid() || ($stat['mode']&0022)!==0) throw new RuntimeException('Unsafe inventory cache directory.');
    }
    private function path(string $name): string {
        $path=$this->directory.'/'.$name;
        clearstatcache(true,$path);
        if (is_link($path) || (file_exists($path) && (!is_file($path) || fileowner($path)!==posix_geteuid()))) throw new RuntimeException('Unsafe inventory cache file.');
        return $path;
    }
    public function read(string $name): array {
        $path=$this->path($name);
        if (!is_file($path) || filesize($path)>8388608) return [];
        $data=json_decode((string)file_get_contents($path),true);
        return is_array($data)?$data:[];
    }
    public function write(string $name,array $data): void {
        $path=$this->path($name);$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json)>8388608) throw new RuntimeException('Inventory observation exceeds its size limit.');
        $temporary=tempnam($this->directory,'.write-');
        if ($temporary===false) throw new RuntimeException('Cannot stage inventory observation.');
        try {
            if (!chmod($temporary,0600) || file_put_contents($temporary,$json)!==strlen($json) || !rename($temporary,$path)) throw new RuntimeException('Cannot publish inventory observation.');
        } finally {if (is_file($temporary)) unlink($temporary);}
    }
    public function lock(string $name) {
        $handle=fopen($this->path($name),'c');
        if (!$handle) throw new RuntimeException('Cannot open inventory lock.');
        chmod($this->path($name),0600);
        if (!flock($handle,LOCK_EX|LOCK_NB)) {fclose($handle);return null;}
        return $handle;
    }
    public function token(): string {return (string)($this->read('dirty.json')['token']??'');}
    public function invalidate(): void {$this->write('dirty.json',['token'=>bin2hex(random_bytes(16))]);}
    public function view(string $fingerprint,int $now): array {
        $data=$this->read('inventory.json');
        if (($data['schema']??0)!==1 || ($data['bootId']??'')!==$this->bootId || ($data['version']??'')!==$this->version || !is_array($data['vms']??null)) $data=[];
        $time=(int)($data['observedAt']??0);
        $stale=!$data || $time>$now || $now-$time>=30 || ($data['fingerprint']??'')!==$fingerprint || ($data['token']??'')!==$this->token();
        $lock=$this->lock('worker.lock');$running=$lock===null;if ($lock) fclose($lock);
        $status=$this->read('status.json');
        $requested=(int)($status['requestedAt']??0);
        return ['vms'=>array_values($data['vms']??[]),'inventory'=>[
            'available'=>(bool)$data,'complete'=>(bool)($data['complete']??false),
            'stale'=>$stale,'refreshing'=>$running || ($requested>0 && $now-$requested>=0 && $now-$requested<5),
            'observedAt'=>$time>0?gmdate(DATE_ATOM,$time):null,'ageSeconds'=>$time>0?max(0,$now-$time):null,
            'error'=>(string)($status['error']??''),'displayOnly'=>true,
        ]];
    }
    public function publish(array $vms,bool $complete,string $fingerprint,string $token,int $observedAt): void {
        $this->write('inventory.json',['schema'=>1,'bootId'=>$this->bootId,'version'=>$this->version,'observedAt'=>$observedAt,'fingerprint'=>$fingerprint,'token'=>$token,'complete'=>$complete,'vms'=>$vms]);
    }
    public function request(string $fingerprint,bool $force,callable $launch): void {
        $lock=$this->lock('request.lock');if (!$lock) return;
        try {
            if ($force) $this->invalidate();
            $view=$this->view($fingerprint,time());$meta=$view['inventory'];
            if ($meta['refreshing'] || (!$meta['stale'] && $meta['complete'])) return;
            $status=$this->read('status.json');
            // Failed/unavailable libvirt must not cause a fork storm across tabs/peers.
            if (!$force && time()-(int)($status['attemptAt']??0)<10) return;
            $this->write('status.json',['requestedAt'=>time(),'attemptAt'=>time(),'error'=>$status['error']??'']);
            try {$launch();} catch (Throwable $e) {$this->write('status.json',['attemptAt'=>time(),'error'=>$e->getMessage()]);}
        } finally {fclose($lock);}
    }
}

function unmInventoryCache(): UnmInventoryCache {
    // /run is canonical RAM storage; refuse redirected/writable ancestors.
    $parent='/run/unmotion';
    if(is_link($parent)||(!is_dir($parent)&&!mkdir($parent,0700)&&!is_dir($parent)))throw new RuntimeException('Unsafe inventory runtime root.');
    $stat=lstat($parent);
    if(!$stat||$stat['uid']!==posix_geteuid()||($stat['mode']&0022)!==0)throw new RuntimeException('Unsafe inventory runtime permissions.');
    return new UnmInventoryCache($parent.'/inventory',trim((string)file_get_contents('/proc/sys/kernel/random/boot_id')),UNM_VERSION);
}

function unmInventoryDefinitionFingerprint(string $directory='/etc/libvirt/qemu'): string {
    $context=hash_init('sha256');
    foreach (glob($directory.'/*.xml')?:[] as $path) {
        hash_update($context,basename($path));
        if (!is_link($path) && is_file($path)) hash_update($context,(string)hash_file('sha256',$path));
    }
    foreach (glob($directory.'/autostart/*.xml')?:[] as $path) hash_update($context,'autostart:'.basename($path));
    return hash_final($context);
}

function unmInventoryView(bool $refresh=false): array {
    $cache=unmInventoryCache();$fingerprint=unmInventoryDefinitionFingerprint();
    $cache->request($fingerprint,$refresh,static function(): void {
        $r=unmRun('nohup setsid /usr/local/sbin/unmotion-inventory refresh </dev/null >/dev/null 2>&1 &',null,2);
        if ($r['code']!==0) throw new RuntimeException('Unable to start inventory refresh.');
    });
    return $cache->view($fingerprint,time());
}

function unmInventoryBasicVm(string $uuid,string $xml,string $info): array {
    $name=unmXmlValue($xml,'name');
    if (strtolower(unmXmlValue($xml,'uuid'))!==strtolower($uuid)) throw new RuntimeException('VM identity changed during inventory.');
    $state=preg_match('/^State:\s+(.+)$/mi',$info,$m)?strtolower(trim($m[1])):'unknown';
    $auto=preg_match('/^Autostart:\s+(\S+)/mi',$info,$m)?$m[1]:'unknown';
    $vcpus=preg_match('~<vcpu\b[^>]*>\s*(\d+)\s*</vcpu>~s',$xml,$m)?(int)$m[1]:0;
    $memory=unmXmlMemoryKiB($xml);
    return ['uuid'=>$uuid,'name'=>$name,'state'=>$state,'autostart'=>$auto,'vcpus'=>$vcpus,'memoryKiB'=>$memory,'memoryMiB'=>(int)ceil($memory/1024),'detailsPending'=>true];
}

/** One collector for all tabs and peers; publish basic rows before slow detail checks. */
function unmInventoryCollect(): void {
    putenv('LC_ALL=C');
    $cache=unmInventoryCache();$lock=$cache->lock('worker.lock');if (!$lock) return;
    try {
        $fingerprint=unmInventoryDefinitionFingerprint();$token=$cache->token();$started=time();
        $cache->write('status.json',['attemptAt'=>$started]);
        $r=unmRun(['virsh','list','--all','--uuid'],null,15);
        if ($r['code']!==0) throw new RuntimeException('VM Manager inventory is unavailable: '.trim($r['stderr']));
        $uuids=array_values(array_filter(array_map('trim',preg_split('/\R/',trim($r['stdout']))?:[])));
        $rows=[];$observations=[];$xmlIndex=[];
        foreach ($uuids as $uuid) {
            if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$uuid)) throw new RuntimeException('Invalid libvirt inventory identity.');
            if (time()-$started>180) throw new RuntimeException('Inventory refresh exceeded its time budget.');
            try {
                $xml=unmDomainXml($uuid,true);$xmlIndex[$uuid]=$xml;
                $info=unmRun(['virsh','dominfo',$uuid],null,10);
                if ($info['code']!==0) throw new RuntimeException('VM state unavailable.');
                $rows[$uuid]=unmInventoryBasicVm($uuid,$xml,$info['stdout']);
                $observations[$uuid]=['xml'=>$xml,'info'=>$info['stdout'],'state'=>$rows[$uuid]['state']];
            } catch (Throwable $e) {$xmlIndex[$uuid]=null;$rows[$uuid]=['uuid'=>$uuid,'name'=>$uuid,'error'=>$e->getMessage()];}
        }
        $cache->publish(array_values($rows),false,$fingerprint,$token,$started);
        foreach ($observations as $uuid=>$observation) {
            if (time()-$started>180) throw new RuntimeException('Inventory details exceeded their time budget.');
            try {$rows[$uuid]=unmParseVm($uuid,$observation+['xmlIndex'=>$xmlIndex])+['detailsPending'=>false];}
            catch (Throwable $e) {$rows[$uuid]['error']=$e->getMessage();$rows[$uuid]['detailsPending']=false;}
            $cache->publish(array_values($rows),false,$fingerprint,$token,$started);
        }
        // Timestamp is when the state was observed, not when a slow guest agent finally answered.
        $cache->publish(array_values($rows),true,$fingerprint,$token,$started);
        $cache->write('status.json',['attemptAt'=>$started]);
    } catch (Throwable $e) {
        // Retain the last available rows; an unavailable host is never an empty VM list.
        $cache->write('status.json',['attemptAt'=>time(),'error'=>$e->getMessage()]);
    } finally {fclose($lock);}
}
