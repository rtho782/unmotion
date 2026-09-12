<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner

/** Report-local aliases. The mapping is never exported or persisted. */
final class UnmReportRedactor {
    private array $map = [];
    private array $counts = [];
    private array $originals = [];
    public function __construct(private bool $originalPaths = false) {}
    public function alias(string $value, string $kind): string {
        if ($value === '') return '';
        if (!isset($this->map[$value])) {
            $n = ($this->counts[$kind] ?? 0) + 1;
            $this->counts[$kind] = $n;
            $this->map[$value] = $kind.'-'.$n;
        }
        return $this->map[$value];
    }
    public function path(string $path): string {
        if ($this->originalPaths) { if(!isset($this->originals[$path]))$this->originals[$path]='REPORTORIGINALPATH'.count($this->originals).'END'; return $path; }
        if (isset($this->map[$path])) return $this->map[$path];
        $parts = explode('/', $path);
        foreach ($parts as &$part) {
            if ($part === '' || in_array($part, ['mnt','dev','zvol','user','domains','isos','etc','libvirt','qemu','nvram','usr','share','local','boot','config','plugins','unmotion','tmp','var','lib'], true)) continue;
            if (preg_match('/^vdisk[0-9]+\.(?:img|raw|qcow2)$/D', $part)) continue;
            if (in_array($part, $this->map, true)) continue;
            if (isset($this->map[$part])) { $part = $this->map[$part]; continue; }
            $ext = pathinfo($part, PATHINFO_EXTENSION);
            $suffix = preg_match('/^(?:img|raw|qcow2|fd|iso|xml|log|json|cfg|sock)$/D', $ext) ? '.'.$ext : '';
            $aliased = $this->alias($part, str_contains($part, ' ') ? 'name with spaces' : 'name');
            $this->map[$part] = $aliased.$suffix;
            $part = $this->map[$part];
        }
        unset($part);
        return $this->map[$path] = implode('/', $parts);
    }
    public function text(string $text): string {
        // Drop whole suspicious lines: do not try to retain values around secrets.
        $text = preg_replace('/-----BEGIN [^-\r\n]*(?:PRIVATE KEY|CERTIFICATE)-----.*?(?:-----END [^-\r\n]+-----|\z)/s', '[key/certificate omitted]', $text) ?? '';
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as &$line) {
            if (strlen($line) > 8192 || preg_match('/(?:password|passwd|passphrase|secret|authorization|credential|csrf|cookie|token|serial|\bwwn\b|machine.id|id_rsa|id_ed25519|private.key|sshpass|\b-pw\b|\bBearer\b|gh[pousr]_[A-Za-z0-9]|github_pat_|ssh-(?:rsa|ed25519)\s|[A-Za-z0-9+\/_=-]{80,})/i', $line)) {
                $line = '[sensitive or oversized log line omitted]';
                continue;
            }
            $line = preg_replace('~[a-z][a-z0-9+.-]*://[^\s<>"\x27]+~i', '[URL omitted]', $line) ?? '';
            if($this->originalPaths && $this->originals)$line=strtr($line,$this->originals);
            $line = preg_replace_callback('/\b[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\b/i', fn($m) => $this->alias($m[0], 'uuid'), $line) ?? '';
            $line = preg_replace_callback('/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i', fn($m) => $this->alias($m[0], 'mac'), $line) ?? '';
            $line = preg_replace_callback('/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/', fn($m) => filter_var($m[0], FILTER_VALIDATE_IP) ? $this->alias($m[0], 'ip') : $m[0], $line) ?? '';
            $line = preg_replace_callback('/(?<![\w:])(?:[0-9a-f]{0,4}:){2,}[0-9a-f:.]*(?:%[\w.-]+)?/i', function($m) { $ip = explode('%', $m[0])[0]; return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $this->alias($m[0], 'ip') : $m[0]; }, $line) ?? '';
            // Register quoted paths before unquoted paths, to retain spaces coherently.
            $line = preg_replace_callback('~(["\x27])(/[^\r\n"\x27]+)\1~', fn($m) => $m[1].$this->path($m[2]).$m[1], $line) ?? '';
            if ($this->map) $line = strtr($line, $this->map);
            if (!$this->originalPaths) $line = preg_replace_callback('~(?<![\w])/(?:[^\s<>"\x27,;|]+)~', fn($m) => $this->path($m[0]), $line) ?? '';
            $line = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email omitted]', $line) ?? '';
            $line = preg_replace('/\b(?:[a-z0-9-]+\.)+(?:local|lan|home|internal|com|net|org)\b/i', '[hostname omitted]', $line) ?? '';
            if($this->originalPaths && $this->originals)$line=strtr($line,array_flip($this->originals));
        }
        unset($line);
        // Strip terminal escapes/control characters; preserve tabs and newlines.
        return preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', implode("\n", $lines)) ?? '';
    }
    /** Preserve projected field labels; only exact identity keys (e.g. pool names) are aliased. */
    public function structured(mixed $value): mixed {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key=>$item) $out[is_string($key) ? ($this->map[$key] ?? $key) : $key] = $this->structured($item);
            return $out;
        }
        // These are device types, not serial identifiers. No arbitrary serial values are projected.
        if ($value === 'virtio-serial' || $value === 'serial') return $value;
        return is_string($value) ? $this->text($value) : $value;
    }
}

/** Exact, regular, non-linked job files only. Reads never follow arbitrary paths. */
function unmReportRead(string $dir, string $name, int $limit, bool $tail = false): array {
    if (!in_array($name, ['job.json','source.xml','destination.xml','disks.json','remote-capabilities.json','migration.log'], true)) throw new InvalidArgumentException('Diagnostic file not allowed.');
    $path = $dir.'/'.$name;
    $st = @lstat($path);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1 || realpath($path) !== $path) return ['text'=>'','note'=>$name.': missing or unsafe file; omitted'];
    $f = @fopen($path, 'rb');
    if (!$f) return ['text'=>'','note'=>$name.': unreadable; omitted'];
    try {
        $opened = fstat($f);
        if (!$opened || $opened['ino'] !== $st['ino'] || $opened['dev'] !== $st['dev'] || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) return ['text'=>'','note'=>$name.': changed during collection; omitted'];
        $truncated = $opened['size'] > $limit;
        if ($truncated && !$tail) return ['text'=>'','note'=>$name.': size limit exceeded; omitted'];
        if ($truncated) fseek($f, -$limit, SEEK_END);
        $text = stream_get_contents($f, $limit);
        if ($text === false) $text = '';
        if ($truncated) { $newline = strpos($text, "\n"); $text = $newline === false ? '' : substr($text, $newline + 1); }
        return ['text'=>$text,'note'=>$truncated ? $name.': truncated to the last complete lines within '.$limit.' bytes' : ''];
    } finally { fclose($f); }
}

function unmReportFields(array $value, array $fields): array {
    $out = [];
    foreach ($fields as $field) if (isset($value[$field]) && is_scalar($value[$field])) $out[$field] = is_string($value[$field]) ? substr($value[$field], 0, 2048) : $value[$field];
    return $out;
}

/** Project XML into a technical allowlist; never export raw XML or qemu args. */
function unmReportVm(string $xml, UnmReportRedactor $redactor): array {
    if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) return ['available'=>false];
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement?->tagName !== 'domain') return ['available'=>false];
        $x = new DOMXPath($doc);
        $out = ['available'=>true];
        foreach (['name','uuid'] as $tag) $redactor->alias($x->evaluate('string(/domain/'.$tag.')'), $tag === 'name' ? 'vm' : 'uuid');
        foreach (['memory','currentMemory','vcpu','os/type','cpu/model'] as $tag) $out[$tag] = substr($x->evaluate('string(/domain/'.$tag.')'), 0, 128);
        foreach (['hypervisor'=>'/domain/@type','memoryUnit'=>'/domain/memory/@unit','architecture'=>'/domain/os/type/@arch','machineType'=>'/domain/os/type/@machine','cpuMode'=>'/domain/cpu/@mode'] as $key=>$query) $out[$key] = substr($x->evaluate('string('.$query.')'), 0, 128);
        foreach (['sockets','dies','clusters','cores','threads'] as $attr) $out['cpuTopology'][$attr] = $x->evaluate('string(/domain/cpu/topology/@'.$attr.')');
        $out['cpuFeatures'] = [];
        foreach ($x->query('/domain/cpu/feature') as $feature) { if(count($out['cpuFeatures'])>=256)break; $out['cpuFeatures'][]=['name'=>$feature->getAttribute('name'),'policy'=>$feature->getAttribute('policy')]; }
        $out['cpuPinning'] = [];
        foreach ($x->query('/domain/cputune/vcpupin') as $pin) { if(count($out['cpuPinning'])>=256)break; $out['cpuPinning'][]=['vcpu'=>$pin->getAttribute('vcpu'),'cpuset'=>$pin->getAttribute('cpuset')]; }
        $out['controllers'] = [];
        foreach ($x->query('/domain/devices/controller') as $controller) { if(count($out['controllers'])>=64)break; $out['controllers'][]=['type'=>$controller->getAttribute('type'),'model'=>$controller->getAttribute('model')]; }
        foreach (['loader','nvram'] as $tag) { $path = $x->evaluate('string(/domain/os/'.$tag.')'); if ($path !== '') $out[$tag] = $redactor->path($path); }
        $out['disks'] = [];
        foreach ($x->query('/domain/devices/disk') as $disk) {
            if (count($out['disks']) >= 32) break;
            $item = ['type'=>$disk->getAttribute('type'),'device'=>$disk->getAttribute('device')];
            foreach (['driver'=>['type','cache','io'],'target'=>['dev','bus'],'source'=>['file','dev']] as $tag=>$attrs) foreach ($attrs as $attr) {
                $value = $x->evaluate('string('.$tag.'/@'.$attr.')', $disk);
                if ($value !== '') $item[$tag.'/'.$attr] = $tag === 'source' ? $redactor->path($value) : substr($value,0,128);
            }
            $out['disks'][] = $item;
        }
        $out['nics'] = [];
        foreach ($x->query('/domain/devices/interface') as $nic) {
            if (count($out['nics']) >= 32) break;
            $mac = $x->evaluate('string(mac/@address)', $nic); $redactor->alias($mac,'mac');
            $bridge = $x->evaluate('string(source/@bridge)', $nic);
            $out['nics'][] = ['type'=>$nic->getAttribute('type'),'model'=>$x->evaluate('string(model/@type)', $nic),'bridge'=>$redactor->alias($bridge,'bridge'),'mac'=>$redactor->alias($mac,'mac')];
        }
        $out['tpmPresent'] = $x->query('/domain/devices/tpm')->length > 0;
        $out['passthroughCount'] = $x->query('/domain/devices/hostdev')->length;
        $out['passthrough'] = [];
        foreach ($x->query('/domain/devices/hostdev') as $device) { if(count($out['passthrough'])>=32)break; $out['passthrough'][]=['type'=>$device->getAttribute('type'),'mode'=>$device->getAttribute('mode'),'managed'=>$device->getAttribute('managed'),'usbVendor'=>$x->evaluate('string(source/vendor/@id)',$device),'usbProduct'=>$x->evaluate('string(source/product/@id)',$device)]; }
        $out['omitted'] = 'Raw XML, custom QEMU arguments, metadata, serial numbers, graphics credentials, guest data and firmware/TPM contents';
        return $out;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}

/** Safe numeric capacity/resource evidence; excludes unknown string-valued metadata. */
function unmReportNumbers(array $input, int $depth = 0): array {
    if ($depth > 5) return [];
    $out = [];
    foreach (array_slice($input,0,64,true) as $key=>$value) {
        if (!is_int($key) && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',(string)$key)) continue;
        if (is_array($value)) { $nested=unmReportNumbers($value,$depth+1); if($nested)$out[$key]=$nested; }
        elseif ((is_int($value)||is_float($value)||is_bool($value)) && !preg_match('/secret|token|serial|password|address|uuid/i',(string)$key)) $out[$key]=$value;
    }
    return $out;
}

/** Read-only host specs: deliberately excludes DMI serials, addresses and credentials. */
function unmDiagnosticHost(): array {
    $host=['schema'=>1,'capturedAt'=>gmdate(DATE_ATOM),'hostname'=>gethostname(),'versions'=>['unmotion'=>UNM_VERSION,'php'=>PHP_VERSION,'kernel'=>php_uname('r')],'resources'=>unmHostResources()];
    $unraid=@parse_ini_file('/etc/unraid-version')?:[];
    $host['versions']['unraid']=substr((string)($unraid['version']??'unavailable'),0,128);
    $cpu=explode("\n\n",(string)@file_get_contents('/proc/cpuinfo',false,null,0,65536))[0];
    foreach(explode("\n",$cpu) as $line) { $parts=explode(':',$line,2); if(count($parts)===2&&in_array(trim($parts[0]),['vendor_id','model name','cpu family','model','stepping','cpu cores','siblings','flags','cache size'],true))$host['cpu'][trim($parts[0])]=substr(trim($parts[1]),0,4096); }
    foreach(['libvirt'=>['virsh','--version'],'qemu'=>['qemu-system-x86_64','--version'],'zfs'=>['zfs','version']] as $key=>$command) {
        $result=unmRun($command,null,2); $host['versions'][$key]=$result['code']===0?substr(trim($result['stdout']),0,512):'unavailable';
    }
    return $host;
}

function unmReportHostProjection(array $host): array {
    return ['capturedAt'=>substr((string)($host['capturedAt']??''),0,128),
        'versions'=>unmReportFields(is_array($host['versions']??null)?$host['versions']:[],['unmotion','unraid','php','kernel','libvirt','qemu','zfs']),
        'cpu'=>unmReportFields(is_array($host['cpu']??null)?$host['cpu']:[],['vendor_id','model name','cpu family','model','stepping','cpu cores','siblings','flags','cache size']),
        'resources'=>unmReportNumbers(is_array($host['resources']??null)?$host['resources']:[])];
}

function unmDiagnosticReport(string $id, bool $originalPaths = false, ?string $jobRoot = null, ?array $versions = null, bool $collectPeer = false): array {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $id) || str_contains($id,'..')) throw new InvalidArgumentException('Invalid diagnostic job id.');
    $root = realpath($jobRoot ?? UNM_JOBS_DIR);
    $dir = $root ? realpath($root.'/'.$id) : false;
    if (!$root || !$dir || $dir !== $root.'/'.$id || is_link($root.'/'.$id)) throw new RuntimeException('Diagnostic job is unavailable.');
    $files = []; $notes = [];
    foreach (['job.json'=>524288,'source.xml'=>131072,'destination.xml'=>131072,'disks.json'=>262144,'remote-capabilities.json'=>131072,'migration.log'=>131072] as $name=>$limit) {
        $read = unmReportRead($dir, $name, $limit, $name === 'migration.log');
        $files[$name] = $read['text'];
        if ($read['note'] !== '') $notes[] = $read['note'];
    }
    $job = json_decode($files['job.json'],true,32);
    if (!is_array($job) || ($job['id']??'') !== $id) throw new RuntimeException('Diagnostic job metadata is missing, oversized or invalid.');
    $request = is_array($job['request']??null) ? $job['request'] : [];
    $r = new UnmReportRedactor($originalPaths);
    foreach ([$id,$job['peerId']??'', $request['VM_UUID']??'', $request['CLONE_UUID']??'', $request['WARM_SEED_ID']??''] as $value) if (is_string($value)) $r->alias($value,'id');
    foreach ([$job['vm']??'', $job['cloneName']??'', $request['VM_NAME']??'', $request['CLONE_NAME']??''] as $value) if (is_string($value)) $r->alias($value,'vm');
    foreach ([gethostname(), $job['peerName']??''] as $value) if (is_string($value)) $r->alias($value,'host');
    $remote = json_decode($files['remote-capabilities.json'],true,32);
    $remote = is_array($remote) ? $remote : [];
    foreach (['hostname','hostId'] as $key) if (is_string($remote[$key]??null)) $r->alias($remote[$key], 'host');
    $data = ['schema'=>1,'capturedAt'=>gmdate(DATE_ATOM),'pathMode'=>$originalPaths?'ORIGINAL PATHS: potentially identifying':'report-local aliases; best-effort redaction',
        'job'=>unmReportFields($job,['jobType','state','progress','message','createdAt','updatedAt']),
        'options'=>unmReportFields($request,['ISO_ACTION','USB_ACTION','TARGET_VCPUS','TARGET_MEMORY_KIB','CPU_PINNING_ACTION','START_DESTINATION','SOURCE_CLEANUP_ACTION','DESTINATION_CONFLICT_ACTION','SOAK_SECONDS','GUEST_CUSTOMIZATION','DEST_DEDUP','DEST_COMPRESSION']),
        'sourceVmRecorded'=>unmReportVm($files['source.xml'],$r),'destinationVmRecorded'=>unmReportVm($files['destination.xml'],$r),
        'peerRecorded'=>unmReportFields($remote,['pluginVersion','protocolVersion','protocolMinVersion','protocolMaxVersion','replicationProtocolVersion','recoveryProtocolVersion']),
        'peerObservation'=>'Recorded when the job collected capabilities. No peer contacted; this is not a current liveness check.',
        'collectionNotes'=>$notes];
    $pre=is_array($job['preflight']??null)?$job['preflight']:[];
    foreach(['capacity','resourcePlan','plan'] as $key) $data[$key.'Recorded']=unmReportNumbers(is_array($pre[$key]??null)?$pre[$key]:[]);
    $data['destinationResourcesRecorded']=unmReportNumbers(is_array($remote['resources']??null)?$remote['resources']:[]);
    $disks = json_decode($files['disks.json'],true,32);
    $data['storageRecorded'] = [];
    foreach (array_slice(is_array($disks['fileDetails']??null)?$disks['fileDetails']:[],0,32) as $disk) {
        if (!is_array($disk)) continue;
        $item = unmReportFields($disk,['type','format','sourceViaFuse','dedicatedDataset','transferClass','zfsEncryption','warmMove','readiness']);
        $item['sizesRecorded']=unmReportNumbers($disk);
        foreach (['source','resolvedSource','zfsMountpoint','zfsDataset','relativePath'] as $key) if (is_string($disk[$key]??null)) $item[$key] = $r->path(substr($disk[$key],0,2048));
        $data['storageRecorded'][] = $item;
    }
    if ($versions === null) {
        $local=unmDiagnosticHost(); $data['sourceHostCurrent']=unmReportHostProjection($local); $versions=$local['versions'];
    }
    $data['destinationHostCurrent']=['available'=>false,'reason'=>'Live destination specs were not requested. Recorded resources remain above.'];
    if($collectPeer && ($job['jobType']??'migration')!=='clone') {
        try {
            $peer=unmPeer((string)($job['peerId']??''));
            foreach(['host','hostname','name','hostId'] as $key)if(is_string($peer[$key]??null))$r->alias($peer[$key],'host');
            $result=unmRemote($peer,'/usr/local/sbin/unmotion-agent diagnostic-host',8);
            $host=strlen($result['stdout'])<=65536?json_decode($result['stdout'],true,16):null;
            if($result['code']!==0||!is_array($host)||($host['schema']??0)!==1)throw new RuntimeException('Unavailable');
            if(is_string($host['hostname']??null))$r->alias($host['hostname'],'host');
            $data['destinationHostCurrent']=['available'=>true]+unmReportHostProjection($host);
            $data['peerObservation']='Read-only peer specs collected now over the existing SSH pairing. Job-era configuration remains separately labelled.';
        } catch(Throwable $e) { $data['destinationHostCurrent']=['available'=>false,'reason'=>'Peer unreachable, unsupported, unpaired or timed out; recorded metadata only.']; }
    }
    $data['versions'] = unmReportFields($versions,['unmotion','unraid','php','libvirt','qemu','zfs']);
    $data['workerProcessPresent'] = isset($job['pid']) && is_numeric($job['pid']) && (int)$job['pid']>0 ? is_dir('/proc/'.(int)$job['pid']) : null;
    $data['consistency'] = 'Read-only, bounded capture; running jobs may change during collection. Process presence does not prove VM state or progress.';
    $json = json_encode($r->structured($data),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    $log = $r->text($files['migration.log']);
    $indent = fn(string $s):string => implode("\n",array_map(fn($line)=>'    '.$line,explode("\n",$s)));
    $text = "# unMotion diagnostic report\n\n## What happened?\nDescribe the problem, what you expected, and what you already tried.\n\n## Privacy\nGitHub issues and attachments are public. Review and edit this report before sharing. Redaction is best-effort, not a guarantee of anonymity. ".($originalPaths?'ORIGINAL FILENAMES/PATHS INCLUDED. ':'')."No disk, guest, NVRAM or TPM contents are collected.\n\n## Diagnostics\n".$indent($json)."\n\n## Recent job log\n".$indent($log !== ''?$log:'No log available.')."\n";
    return ['text'=>$text,'filename'=>'unmotion-report-'.gmdate('Ymd-His').'.txt','version'=>(string)($versions['unmotion']??UNM_VERSION)];
}
