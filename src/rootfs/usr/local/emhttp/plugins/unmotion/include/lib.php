<?php
declare(strict_types=1);

const UNM_VERSION = '0.4.0-beta1';
const UNM_PROTOCOL = 5;
const UNM_BOOT_DIR = '/boot/config/plugins/unmotion';
const UNM_CONFIG_JSON = UNM_BOOT_DIR . '/config.json';
const UNM_CONFIG_CFG = UNM_BOOT_DIR . '/settings.cfg';
const UNM_PEERS_DIR = UNM_BOOT_DIR . '/peers';
const UNM_JOBS_DIR = UNM_BOOT_DIR . '/jobs';
const UNM_OWNERSHIP_DIR = UNM_BOOT_DIR . '/ownership';
const UNM_SEEDS_DIR = UNM_BOOT_DIR . '/seeds';
const UNM_REPLICATIONS_DIR = UNM_BOOT_DIR . '/replications';
const UNM_REPLICAS_DIR = UNM_BOOT_DIR . '/replicas';
const UNM_HOST_ID_FILE = UNM_BOOT_DIR . '/host_id';
const UNM_REPLICATION_PROTOCOL = 1;

function unmEnsureDirs(): void {
    foreach ([UNM_BOOT_DIR, UNM_PEERS_DIR, UNM_JOBS_DIR, UNM_OWNERSHIP_DIR, UNM_SEEDS_DIR, UNM_REPLICATIONS_DIR, UNM_REPLICAS_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create $dir");
        }
    }
}

function unmBaseDefaults(): array {
    return [
        'enabled' => true,
        'image_dir' => '/mnt/cache/domains',
        'zvol_dataset' => 'cache/vm-zvols',
        'iso_dir' => '/mnt/user/isos',
        'dedup' => 'off',
        'compression' => 'inherit',
        'shutdown_timeout' => 300,
        'copy_isos_default' => true,
        'remove_cpu_pinning' => true,
        'discovery' => true,
        'source_cleanup_default' => 'unregister',
        'quiesce_snapshots' => true,
        'warm_bandwidth_mib' => 0,
        'health_poll_seconds' => 10,
        'health_memory_warning_percent' => 10,
        'health_memory_critical_percent' => 5,
        'debug_logging' => false,
        'config_schema' => 5,
    ];
}

function unmConsensusLocation(array $locations): ?string {
    $locations = array_values(array_filter(array_map(static fn($v): string => rtrim(trim((string)$v), '/'), $locations)));
    $total = count($locations);
    if ($total === 0) return null;
    $counts = array_count_values($locations);
    arsort($counts, SORT_NUMERIC);
    $top = array_key_first($counts);
    $topCount = $top !== null ? (int)$counts[$top] : 0;
    $secondCount = count($counts) > 1 ? (int)array_values($counts)[1] : 0;
    if ($top === null || $topCount === $secondCount || ($topCount * 3) < ($total * 2)) return null;
    return $top;
}

/**
 * Select the deepest common ancestor used by at least two thirds of paths.
 * This copes with ordinary Unraid layouts where every VM has its own directory,
 * for example /mnt/cache/domains/VM1 and /mnt/cache/domains/VM2.
 */
function unmConsensusAncestor(array $paths, string $minimumPrefix = '/mnt/'): ?string {
    $paths = array_values(array_filter(array_map(static fn($v): string => rtrim(trim((string)$v), '/'), $paths),
        static fn(string $v): bool => $v !== '' && str_starts_with($v . '/', rtrim($minimumPrefix, '/') . '/')));
    $total = count($paths);
    if ($total === 0) return null;
    $counts = [];
    foreach ($paths as $path) {
        $seen = [];
        $candidate = $path;
        while ($candidate !== '' && $candidate !== '/' && str_starts_with($candidate . '/', rtrim($minimumPrefix, '/') . '/')) {
            $seen[$candidate] = true;
            $parent = dirname($candidate);
            if ($parent === $candidate) break;
            $candidate = rtrim($parent, '/');
        }
        foreach (array_keys($seen) as $candidate) $counts[$candidate] = ($counts[$candidate] ?? 0) + 1;
    }
    $eligible = array_filter($counts, static fn(int $count): bool => ($count * 3) >= ($total * 2));
    if (!$eligible) return null;
    uksort($eligible, static function(string $a, string $b): int {
        $depthA = substr_count(trim($a, '/'), '/');
        $depthB = substr_count(trim($b, '/'), '/');
        return $depthB <=> $depthA ?: strlen($b) <=> strlen($a) ?: strcmp($a, $b);
    });
    return array_key_first($eligible);
}

function unmInferStorageLocations(): array {
    $images = [];
    $zvols = [];
    $isos = [];
    try {
        foreach (unmInventory() as $vm) {
            if (!empty($vm['error'])) continue;
            $vmName = (string)($vm['name'] ?? '');
            foreach (($vm['disks'] ?? []) as $disk) {
                $source = (string)($disk['source'] ?? '');
                if (($disk['type'] ?? '') === 'zvol' && str_starts_with($source, '/dev/zvol/')) {
                    $zvols[] = dirname(substr($source, strlen('/dev/zvol/')));
                } elseif (($disk['type'] ?? '') === 'file' && str_starts_with($source, '/mnt/')) {
                    $images[] = dirname($source);
                }
            }
            foreach (($vm['isos'] ?? []) as $iso) {
                $iso = (string)$iso;
                if (str_starts_with($iso, '/mnt/')) $isos[] = dirname($iso);
            }
        }
    } catch (Throwable $ignored) {
        // VM Manager/libvirt may be unavailable during boot. Fall back to normal Unraid defaults.
    }
    $isoDir = unmConsensusAncestor($isos, '/mnt/');
    // Never infer /mnt itself as an ISO library. If no meaningful ISO
    // location can be inferred, leave it unset here so the normal Unraid
    // default (/mnt/user/isos) survives.
    if ($isoDir !== null && rtrim($isoDir, '/') === '/mnt') $isoDir = null;
    return array_filter([
        'image_dir' => unmConsensusAncestor($images, '/mnt/'),
        'zvol_dataset' => unmConsensusLocation($zvols),
        'iso_dir' => $isoDir,
    ], static fn($v): bool => is_string($v) && $v !== '');
}

function unmDefaults(): array {
    $defaults = unmBaseDefaults();
    if (!is_file(UNM_CONFIG_JSON)) {
        $inferred = unmInferStorageLocations();
        $defaults = array_replace($defaults, $inferred);
        if (!array_key_exists('zvol_dataset', $inferred) && !unmZfsDatasetExists((string)$defaults['zvol_dataset'])) $defaults['zvol_dataset'] = '';
    }
    return $defaults;
}

function unmLoadJson(string $path, array $default = []): array {
    if (!is_file($path)) return $default;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function unmAtomicJson(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException("Unable to write $tmp");
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) throw new RuntimeException("Unable to replace $path");
}

function unmShellValue(mixed $value): string {
    if (is_bool($value)) $value = $value ? '1' : '0';
    return escapeshellarg((string)$value);
}

function unmWriteCfg(string $path, array $values): void {
    $lines = ["# Generated by unMotion. Do not edit while the plugin is running."];
    foreach ($values as $key => $value) {
        if (!preg_match('/^[A-Z0-9_]+$/', (string)$key)) continue;
        $lines[] = $key . '=' . unmShellValue($value);
    }
    $tmp = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function unmLoadConfig(): array {
    unmEnsureDirs();
    $stored = unmLoadJson(UNM_CONFIG_JSON);
    $schema = (int)($stored['config_schema'] ?? 1);
    $changed = false;
    // Preserve migrations from older schemas while adding newer settings
    // through defaults. Early 0.3 builds could infer /mnt as the ISO path
    // when no useful ISO evidence existed; that is never a sensible ISO
    // library, so repair it automatically.
    if ($schema < 2) { $stored['source_cleanup_default'] = 'unregister'; $changed = true; }
    if (rtrim((string)($stored['iso_dir'] ?? ''), '/') === '/mnt') {
        $stored['iso_dir'] = '/mnt/user/isos';
        $changed = true;
    }
    if ($schema < 5) { $stored['config_schema'] = 5; $changed = true; }
    if ($changed) unmAtomicJson(UNM_CONFIG_JSON, array_replace(unmDefaults(), $stored));
    return array_replace(unmDefaults(), $stored);
}

function unmWriteRuntimeConfig(array $cfg): void {
    unmWriteCfg(UNM_CONFIG_CFG, [
        'IMAGE_DIR' => $cfg['image_dir'],
        'ZVOL_DATASET' => $cfg['zvol_dataset'],
        'ISO_DIR' => $cfg['iso_dir'],
        'DEST_DEDUP' => $cfg['dedup'],
        'DEST_COMPRESSION' => $cfg['compression'],
        'SHUTDOWN_TIMEOUT' => $cfg['shutdown_timeout'],
        'COPY_ISOS_DEFAULT' => $cfg['copy_isos_default'],
        'REMOVE_CPU_PINNING' => $cfg['remove_cpu_pinning'],
        'DISCOVERY_ENABLED' => $cfg['discovery'],
        'SOURCE_CLEANUP_DEFAULT' => $cfg['source_cleanup_default'],
        'QUIESCE_SNAPSHOTS' => $cfg['quiesce_snapshots'],
        'WARM_BANDWIDTH_MIB' => $cfg['warm_bandwidth_mib'],
        'HEALTH_POLL_SECONDS' => $cfg['health_poll_seconds'],
        'DEBUG_LOGGING' => $cfg['debug_logging'],
    ]);
}

function unmSaveConfig(array $input): array {
    $cfg = unmDefaults();
    foreach ($cfg as $key => $default) {
        if (!array_key_exists($key, $input)) continue;
        if (is_bool($default)) $cfg[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        elseif (is_int($default)) $cfg[$key] = (int)$input[$key];
        else $cfg[$key] = trim((string)$input[$key]);
    }
    if (!str_starts_with($cfg['image_dir'], '/mnt/')) throw new InvalidArgumentException('Image directory must be under /mnt/.');
    if (!str_starts_with($cfg['iso_dir'], '/mnt/')) throw new InvalidArgumentException('ISO directory must be under /mnt/.');
    if ($cfg['zvol_dataset'] !== '' && !preg_match('/^[A-Za-z0-9_.:-]+\/[A-Za-z0-9_\.\/-]+$/', $cfg['zvol_dataset'])) throw new InvalidArgumentException('Invalid ZFS dataset name.');
    if (!in_array($cfg['dedup'], ['off', 'on', 'verify'], true)) throw new InvalidArgumentException('Invalid dedup setting.');
    if (!in_array($cfg['compression'], ['inherit', 'off', 'lz4', 'zstd', 'zstd-fast', 'gzip'], true)) throw new InvalidArgumentException('Invalid compression setting.');
    if (!in_array($cfg['source_cleanup_default'], ['unregister', 'retain', 'delete'], true)) throw new InvalidArgumentException('Invalid source-cleanup default.');
    $cfg['shutdown_timeout'] = max(30, min(3600, (int)$cfg['shutdown_timeout']));
    $cfg['warm_bandwidth_mib'] = max(0, min(10240, (int)$cfg['warm_bandwidth_mib']));
    $cfg['health_poll_seconds'] = max(5, min(120, (int)$cfg['health_poll_seconds']));
    $cfg['health_memory_warning_percent'] = max(1, min(50, (int)$cfg['health_memory_warning_percent']));
    $cfg['health_memory_critical_percent'] = max(1, min((int)$cfg['health_memory_warning_percent'], (int)$cfg['health_memory_critical_percent']));
    unmAtomicJson(UNM_CONFIG_JSON, $cfg);
    unmWriteRuntimeConfig($cfg);
    return $cfg;
}

function unmHostId(): string {
    unmEnsureDirs();
    if (!is_file(UNM_HOST_ID_FILE) || trim((string)file_get_contents(UNM_HOST_ID_FILE)) === '') {
        $id = trim((string)@file_get_contents('/proc/sys/kernel/random/uuid'));
        if ($id === '') $id = bin2hex(random_bytes(16));
        file_put_contents(UNM_HOST_ID_FILE, $id . "\n", LOCK_EX);
        chmod(UNM_HOST_ID_FILE, 0600);
    }
    return trim((string)file_get_contents(UNM_HOST_ID_FILE));
}

function unmRun(array|string $command, ?string $stdin = null, int $timeout = 60): array {
    $cmd = is_array($command) ? implode(' ', array_map('escapeshellarg', $command)) : $command;
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
    if (!is_resource($proc)) return ['code'=>127,'stdout'=>'','stderr'=>'Unable to start process'];
    fwrite($pipes[0], $stdin ?? ''); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $stdout=''; $stderr=''; $start=microtime(true); $exitCode=null; $timedOut=false;
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exitCode = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null;
            break;
        }
        if ((microtime(true)-$start) > $timeout) {
            $timedOut=true;
            proc_terminate($proc, 15); usleep(250000);
            $status=proc_get_status($proc);
            if ($status['running']) proc_terminate($proc, 9);
            $stderr .= "\nTimed out after {$timeout}s";
            break;
        }
        usleep(50000);
    }
    $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $closed = proc_close($proc);
    $code = $timedOut ? 124 : (($exitCode !== null && $exitCode >= 0) ? $exitCode : $closed);
    return ['code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr];
}

function unmTool(string $name): bool {
    return unmRun('command -v ' . escapeshellarg($name) . ' >/dev/null 2>&1', null, 5)['code'] === 0;
}

function unmSshStatus(): array {
    $var=@parse_ini_file('/var/local/emhttp/var.ini')?:[];
    $enabled=strtolower((string)($var['USE_SSH']??'no'))==='yes';
    $port=(int)($var['PORTSSH']??22);
    if($port<1 || $port>65535) $port=22;
    $running=unmRun('pidof sshd >/dev/null 2>&1',null,5)['code']===0;
    $listening=false;
    $r=unmRun(['ss','-lntH'],null,5);
    if($r['code']===0){
        $pattern='~(?:^|\s)(?:\[[^\]]+\]|[0-9A-Fa-f:.*]+):'.preg_quote((string)$port,'~').'\s~m';
        $listening=(bool)preg_match($pattern,$r['stdout']);
    }
    return [
        'enabled'=>$enabled,
        'running'=>$running,
        'listening'=>$listening,
        'port'=>$port,
        'managementUrl'=>'/Settings/ManagementAccess',
    ];
}

function unmZfsDatasetExists(string $dataset): bool {
    return $dataset !== '' && unmTool('zfs') && unmRun(['zfs','list','-H',$dataset], null, 10)['code'] === 0;
}

function unmZfsDatasetForPath(string $path, bool $exactMountpoint = false): ?string {
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    if (!unmTool('zfs')) return null;
    $r = unmRun(['zfs','list','-H','-o','name,mountpoint','-t','filesystem'], null, 15);
    if ($r['code'] !== 0) return null;
    $best = null; $bestLen = -1;
    foreach (preg_split('/\R/', trim($r['stdout'])) as $line) {
        if ($line === '') continue;
        $parts = preg_split('/\t+|\s+/', trim($line), 2);
        if (count($parts) !== 2) continue;
        [$dataset, $mount] = $parts;
        $mount = rtrim($mount, '/');
        if ($mount === '') $mount = '/';
        if ($mount === '-' || $mount === 'legacy' || $mount === 'none') continue;
        if ($exactMountpoint) {
            if ($path === $mount) return $dataset;
            continue;
        }
        if ($path === $mount || str_starts_with($path . '/', rtrim($mount, '/') . '/')) {
            $len = strlen($mount);
            if ($len > $bestLen) { $best = $dataset; $bestLen = $len; }
        }
    }
    return $best;
}


function unmZfsFilesystemTable(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!unmTool('zfs')) return $cache;
    $r = unmRun(['zfs','list','-H','-p','-o','name,mountpoint,used,available,encryption,readonly','-t','filesystem'], null, 20);
    if ($r['code'] !== 0) return $cache;
    foreach (preg_split('/\R/', trim($r['stdout'])) ?: [] as $line) {
        if ($line === '') continue;
        $parts = explode("\t", $line);
        if (count($parts) < 6) $parts = preg_split('/\s+/', trim($line), 6) ?: [];
        if (count($parts) < 6) continue;
        [$name,$mount,$used,$available,$encryption,$readonly] = $parts;
        if (in_array($mount, ['-','legacy','none'], true)) continue;
        $mount = rtrim($mount, '/') ?: '/';
        $cache[] = ['dataset'=>$name,'mountpoint'=>$mount,'usedBytes'=>(int)$used,'availableBytes'=>(int)$available,'encryption'=>$encryption,'readonly'=>$readonly];
    }
    usort($cache, static fn(array $a,array $b):int => strlen($b['mountpoint']) <=> strlen($a['mountpoint']));
    return $cache;
}

function unmZfsPathInfo(string $path): array {
    $real = realpath($path);
    $probe = $real !== false ? $real : $path;
    $probe = rtrim($probe, '/') ?: '/';
    foreach (unmZfsFilesystemTable() as $row) {
        $mount = rtrim((string)$row['mountpoint'], '/') ?: '/';
        if ($probe === $mount || str_starts_with($probe . '/', rtrim($mount, '/') . '/')) {
            $relative = ltrim(substr($probe, strlen($mount)), '/');
            return $row + ['relativePath'=>$relative,'exactMountpoint'=>$probe === $mount];
        }
    }
    return ['dataset'=>null,'mountpoint'=>null,'relativePath'=>null,'exactMountpoint'=>false,'usedBytes'=>0,'availableBytes'=>0,'encryption'=>null,'readonly'=>null];
}

function unmCommonDirectory(array $paths): string {
    $dirs=array_values(array_filter(array_map(static fn($p):string=>rtrim(dirname((string)$p),'/'),$paths)));
    if(!$dirs)return '';
    $parts=array_map(static fn(string $p):array=>explode('/',trim($p,'/')),$dirs);
    $common=[];
    for($i=0;;$i++){
        $value=$parts[0][$i]??null;
        if($value===null)break;
        foreach($parts as $set)if(($set[$i]??null)!==$value)return '/'.implode('/',$common);
        $common[]=$value;
    }
    return '/'.implode('/',$common);
}

function unmDatasetUsedByOtherVm(string $datasetMountpoint,string $excludeUuid): bool {
    $datasetMountpoint=rtrim($datasetMountpoint,'/');
    $r=unmRun(['virsh','list','--all','--uuid'],null,30);
    if($r['code']!==0)return true; // Conservative: do not call a dataset dedicated if inventory is unavailable.
    foreach(preg_split('/\R/',trim($r['stdout']))?:[] as $uuid){
        $uuid=trim($uuid); if($uuid===''||strcasecmp($uuid,$excludeUuid)===0)continue;
        try{$xml=unmDomainXml($uuid,true);}catch(Throwable $ignored){continue;}
        if(!preg_match_all('~<disk\b[^>]*\bdevice=(["\'])disk\1[^>]*>.*?</disk>~s',$xml,$blocks))continue;
        foreach($blocks[0] as $block){
            if(!preg_match('~<source\b[^>]*\bfile=(["\'])(.*?)\1~s',$block,$m))continue;
            $path=html_entity_decode($m[2],ENT_QUOTES|ENT_XML1);
            $resolution=unmResolveUserSharePath($path);
            $probe=(string)($resolution['physicalPath']??'');
            if($probe==='')$probe=$path;
            if($probe===$datasetMountpoint||str_starts_with($probe.'/', $datasetMountpoint.'/'))return true;
        }
    }
    return false;
}

function unmIsUserSharePath(string $path): bool {
    return $path === '/mnt/user' || str_starts_with($path, '/mnt/user/');
}

/**
 * Resolve an existing /mnt/user file to its backing pool/array path when there is
 * exactly one matching physical file. Duplicate files across devices are left
 * unresolved rather than guessing which copy shfs currently exposes.
 */
function unmResolveUserSharePath(string $path): array {
    if (!unmIsUserSharePath($path)) return ['isFuse'=>false,'physicalPath'=>$path,'candidates'=>[],'ambiguous'=>false];
    $relative=ltrim(substr($path,strlen('/mnt/user')),'/');
    if($relative==='')return ['isFuse'=>true,'physicalPath'=>'','candidates'=>[],'ambiguous'=>false];
    $roots=[];
    foreach(glob('/mnt/*',GLOB_ONLYDIR)?:[] as $root){
        $base=basename($root);
        if(in_array($base,['user','user0','remotes','disks','addons'],true))continue;
        $roots[rtrim($root,'/')]=true;
    }
    // Include top-level roots inferred from ZFS mountpoints, even if glob visibility is unusual.
    foreach(unmZfsFilesystemTable() as $row){
        $mount=(string)($row['mountpoint']??'');
        if(!preg_match('~^/mnt/([^/]+)(?:/|$)~',$mount,$m))continue;
        if(in_array($m[1],['user','user0','remotes','disks','addons'],true))continue;
        $roots['/mnt/'.$m[1]]=true;
    }
    $candidates=[];
    foreach(array_keys($roots) as $root){
        $candidate=$root.'/'.$relative;
        if(!is_file($candidate)||is_link($candidate))continue;
        $real=realpath($candidate)?:$candidate;
        $candidates[$real]=true;
    }
    $paths=array_keys($candidates);
    sort($paths,SORT_STRING);
    return [
        'isFuse'=>true,
        'physicalPath'=>count($paths)===1?$paths[0]:'',
        'candidates'=>$paths,
        'ambiguous'=>count($paths)>1,
    ];
}

function unmImageBackingInfo(string $path,string $format=''): array {
    $format=strtolower(trim($format));
    $looksQcow=in_array($format,['qcow','qcow2'],true)||preg_match('/\.qcow2?$/i',$path);
    if(!$looksQcow)return ['checked'=>false,'hasBackingChain'=>false,'chain'=>[],'error'=>'','errorDetail'=>''];
    if(!is_file($path)||is_link($path))return ['checked'=>true,'hasBackingChain'=>true,'chain'=>[],'error'=>'Image is missing or unsafe.','errorDetail'=>'Image file is missing or is a symbolic link.'];
    if(!unmTool('qemu-img'))return ['checked'=>true,'hasBackingChain'=>true,'chain'=>[],'error'=>'qcow2 backing chain cannot be verified.','errorDetail'=>'qemu-img is unavailable.'];
    // -U/--force-share permits read-only inspection while QEMU owns the normal write lock.
    $r=unmRun(['qemu-img','info','-U','--output=json','--backing-chain',$path],null,20);
    if($r['code']!==0){
        $detail=trim($r['stderr'].' '.$r['stdout']);
        // Very old qemu-img builds may not support -U. Retry normally only for an option error.
        if(preg_match('/(?:unknown|unrecognized|invalid) option|illegal option/i',$detail)){
            $r=unmRun(['qemu-img','info','--output=json','--backing-chain',$path],null,20);
            $detail=trim($r['stderr'].' '.$r['stdout']);
        }
        if($r['code']!==0)return ['checked'=>true,'hasBackingChain'=>true,'chain'=>[],'error'=>'qcow2 backing chain could not be inspected.','errorDetail'=>$detail?:'qemu-img returned an error.'];
    }
    $data=json_decode($r['stdout'],true);
    if(!is_array($data))return ['checked'=>true,'hasBackingChain'=>true,'chain'=>[],'error'=>'qcow2 backing chain could not be inspected.','errorDetail'=>'qemu-img returned invalid JSON.'];
    if(array_is_list($data))$entries=$data;else $entries=[$data];
    $chain=[];
    foreach($entries as $entry){
        if(!is_array($entry))continue;
        $filename=(string)($entry['filename']??'');
        $backing=(string)($entry['backing-filename']??'');
        $full=(string)($entry['full-backing-filename']??'');
        $chain[]=['filename'=>$filename,'format'=>(string)($entry['format']??''),'backingFilename'=>$backing,'fullBackingFilename'=>$full];
    }
    $top=$chain[0]??[];
    $has=count($chain)>1||((string)($top['backingFilename']??''))!==''||((string)($top['fullBackingFilename']??''))!=='';
    return ['checked'=>true,'hasBackingChain'=>$has,'chain'=>$chain,'error'=>'','errorDetail'=>''];
}

function unmClassifyVmStorage(array $vm,bool $checkOtherVms=true): array {
    $fileGroups=[];
    foreach(($vm['disks']??[]) as $i=>$disk){
        if(($disk['type']??'')!=='file')continue;
        $source=(string)$disk['source'];
        $resolution=unmResolveUserSharePath($source);
        $probe=(string)($resolution['physicalPath']??'');
        if($probe==='')$probe=$source;
        $vm['disks'][$i]['sourceViaFuse']=!empty($resolution['isFuse']);
        $vm['disks'][$i]['resolvedSource']=$probe;
        $vm['disks'][$i]['fuseResolution']=$resolution;
        if(!empty($resolution['isFuse'])){
            $vm['disks'][$i]['storageAdvisory']=!empty($resolution['ambiguous'])
                ? 'Uses /mnt/user (FUSE); multiple backing files found. Use a direct path.'
                : 'Uses /mnt/user (FUSE); direct path recommended.';
        }

        $backing=unmImageBackingInfo($source,(string)($disk['format']??''));
        $vm['disks'][$i]['backingInfo']=$backing;
        if(!empty($backing['hasBackingChain'])){
            $reason=trim((string)($backing['error']??''));
            if($reason==='')$reason='External qcow2 backing chain; flatten before Warm Move.';
            $vm['disks'][$i]+=['transferClass'=>'unsupported-backing-chain','warmMove'=>'blocked','readiness'=>'red','dedicatedDataset'=>false,'storageReason'=>$reason];
            continue;
        }
        $info=unmZfsPathInfo($probe);
        $dataset=(string)($info['dataset']??'');
        if($dataset!=='')$fileGroups[$dataset][]=$i;
    }
    foreach($fileGroups as $dataset=>$indexes){
        $paths=array_map(static fn(int $i):string=>(string)($vm['disks'][$i]['resolvedSource']??$vm['disks'][$i]['source']),$indexes);
        $info=unmZfsPathInfo($paths[0]);
        $mount=rtrim((string)($info['mountpoint']??''),'/');
        $common=rtrim(unmCommonDirectory($paths),'/');
        $dedicated=$mount!==''&&$common!==''&&$mount===$common;
        if($dedicated&&$checkOtherVms)$dedicated=!unmDatasetUsedByOtherVm($mount,(string)($vm['uuid']??''));
        $encryption=(string)($info['encryption']??'off');
        $nativeEligible=$dedicated&&$encryption==='off';
        foreach($indexes as $i){
            $probe=(string)($vm['disks'][$i]['resolvedSource']??$vm['disks'][$i]['source']);
            $vm['disks'][$i]['zfsDataset']=$dataset;
            $vm['disks'][$i]['zfsMountpoint']=$mount;
            $vm['disks'][$i]['zfsEncryption']=$encryption;
            $vm['disks'][$i]['relativePath']=ltrim(substr($probe,strlen($mount)),'/');
            $vm['disks'][$i]['dedicatedDataset']=$dedicated;
            $class=$nativeEligible?'zfs-dataset-image':($dedicated?'encrypted-zfs-image':'shared-zfs-image');
            $vm['disks'][$i]['transferClass']=$class;
            $vm['disks'][$i]['warmMove']=$nativeEligible?'supported':'supported-rsync';
            $vm['disks'][$i]['readiness']=$nativeEligible?'green':'amber';
            $vm['disks'][$i]['storageReason']=match($class){
                'zfs-dataset-image'=>'Dedicated ZFS dataset; native replication available.',
                'encrypted-zfs-image'=>'Encrypted ZFS dataset; Warm Move uses two-pass rsync.',
                default=>'Shared ZFS dataset; Warm Move uses two-pass rsync.',
            };
        }
    }
    foreach(($vm['disks']??[]) as $i=>$disk){
        if(($disk['type']??'')==='zvol'){
            $dataset=substr((string)$disk['source'],strlen('/dev/zvol/'));
            $vm['disks'][$i]+=['transferClass'=>'zvol','warmMove'=>'supported','readiness'=>'green','zfsDataset'=>$dataset,'dedicatedDataset'=>true,'storageReason'=>'ZFS volume; native replication available.'];
        }elseif(($disk['type']??'')==='file'&&empty($disk['zfsDataset'])&&empty($disk['transferClass'])){
            $fuseUnresolved=!empty($disk['sourceViaFuse'])&&empty($disk['fuseResolution']['physicalPath']);
            $reason=$fuseUnresolved
                ? 'Backing storage could not be resolved; Warm Move uses two-pass rsync.'
                : 'Not on ZFS; Warm Move uses two-pass rsync.';
            $vm['disks'][$i]+=['transferClass'=>'non-zfs-image','warmMove'=>'supported-rsync','readiness'=>'amber','dedicatedDataset'=>false,'storageReason'=>$reason];
        }elseif(!in_array(($disk['type']??''),['zvol','file'],true)){
            $vm['disks'][$i]+=['transferClass'=>'unsupported','warmMove'=>'blocked','readiness'=>'red','dedicatedDataset'=>false,'storageReason'=>'Unsupported storage type.'];
        }
    }
    $classes=array_column($vm['disks']??[],'transferClass');
    $hasUnsupported=(bool)array_filter($classes,static fn($c):bool=>is_string($c)&&str_starts_with($c,'unsupported'));
    $vm['storageReadiness']=$hasUnsupported?'red':((array_filter($classes,static fn($c):bool=>in_array($c,['shared-zfs-image','encrypted-zfs-image','non-zfs-image'],true)))?'amber':'green');
    $vm['nativeZfsWarmMove']=!empty($classes)&&!array_filter($classes,static fn($c):bool=>!in_array($c,['zvol','zfs-dataset-image'],true));
    $vm['warmMoveEligible']=$vm['storageReadiness']!=='red'&&empty($vm['pci']);
    $storageReasons=[];$blockingReasons=[];
    foreach(($vm['disks']??[]) as $disk){
        $advisory=trim((string)($disk['storageAdvisory']??''));
        if($advisory!=='')$storageReasons[$advisory]=true;
        $readiness=(string)($disk['readiness']??'');
        if(!in_array($readiness,['amber','red'],true))continue;
        $reason=trim((string)($disk['storageReason']??'Storage layout is not eligible.'));
        $storageReasons[$reason]=true;
        if($readiness==='red')$blockingReasons[$reason]=true;
    }
    if(!empty($vm['pci']))$blockingReasons['PCIe passthrough is unsupported for Warm Move.']=true;
    $vm['storageReasons']=array_keys($storageReasons);
    $vm['storageBlockingReasons']=array_keys($blockingReasons);
    return $vm;
}

function unmGuestAgentStatus(string $vmUuid,string $state): array {
    if(!in_array(strtolower(trim($state)),['running','idle','paused'],true))return ['connected'=>false,'quiesceAvailable'=>false,'reason'=>'VM is not running'];
    $r=unmRun(['virsh','qemu-agent-command',$vmUuid,'{"execute":"guest-ping"}'],null,5);
    return ['connected'=>$r['code']===0,'quiesceAvailable'=>$r['code']===0,'reason'=>$r['code']===0?'':'Guest agent did not answer'];
}


function unmReadRangeFile(string $path, string $fallback=''): string {
    $value=trim((string)@file_get_contents($path));
    return $value!==''?$value:$fallback;
}

function unmExpandRangeSet(string $value): array {
    $out=[];
    foreach(preg_split('/\s*,\s*/',trim($value),-1,PREG_SPLIT_NO_EMPTY)?:[] as $part){
        if(preg_match('/^(\d+)-(\d+)$/',$part,$m)){
            $a=(int)$m[1];$b=(int)$m[2];
            if($b<$a||($b-$a)>65535)continue;
            for($i=$a;$i<=$b;$i++)$out[$i]=true;
        }elseif(preg_match('/^\d+$/',$part))$out[(int)$part]=true;
    }
    ksort($out,SORT_NUMERIC);
    return array_map('intval',array_keys($out));
}

function unmMemoryInfo(): array {
    $values=[];
    foreach(file('/proc/meminfo',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        if(preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/',$line,$m))$values[$m[1]]=(int)$m[2]*1024;
    }
    return ['totalBytes'=>(int)($values['MemTotal']??0),'availableBytes'=>(int)($values['MemAvailable']??($values['MemFree']??0)),'freeBytes'=>(int)($values['MemFree']??0)];
}

function unmHostResources(): array {
    $nproc=max(1,(int)trim((string)@shell_exec('nproc 2>/dev/null')));
    $cpuSet=unmReadRangeFile('/sys/devices/system/cpu/online','0-'.($nproc-1));
    $numaSet=unmReadRangeFile('/sys/devices/system/node/online','0');
    $cpuList=unmExpandRangeSet($cpuSet);$numaList=unmExpandRangeSet($numaSet);
    return ['cpuOnlineSet'=>$cpuSet,'cpuOnline'=>$cpuList,'cpuOnlineCount'=>count($cpuList),'numaNodeSet'=>$numaSet,'numaNodes'=>$numaList]+unmMemoryInfo();
}

function unmMemoryToKiB(string $value,string $unit): int {
    $n=(float)trim($value);$unit=strtolower(trim($unit));
    $bytes=match($unit){'b','bytes'=>$n,'kb'=>$n*1000,'kib','k'=>$n*1024,'mb'=>$n*1000*1000,'mib','m'=>$n*1024*1024,'gb'=>$n*1000*1000*1000,'gib','g'=>$n*1024*1024*1024,'tb'=>$n*1000*1000*1000*1000,'tib','t'=>$n*1024*1024*1024*1024,default=>$n*1024};
    return max(0,(int)round($bytes/1024));
}

function unmXmlMemoryKiB(string $xml): int {
    foreach(['currentMemory','memory'] as $tag){
        if(preg_match('~<'.$tag.'\\b([^>]*)>\\s*([0-9.]+)\\s*</'.$tag.'>~s',$xml,$m)){
            $unit=preg_match('~\\bunit=(["\\\'])(.*?)\\1~s',$m[1],$u)?$u[2]:'KiB';
            return unmMemoryToKiB($m[2],$unit);
        }
    }
    return 0;
}

function unmCpuPinningInfo(string $xml): array {
    $sets=[];$vcpuPins=[];$numaSets=[];
    if(preg_match('~<vcpu\\b([^>]*)>~s',$xml,$m)&&preg_match('~\\bcpuset=(["\\\'])(.*?)\\1~s',$m[1],$c))$sets[]=['kind'=>'vcpu','value'=>$c[2]];
    if(preg_match_all('~<(vcpupin|emulatorpin|iothreadpin)\\b([^>]*)/?>~s',$xml,$matches,PREG_SET_ORDER)){
        foreach($matches as $entry){
            if(!preg_match('~\\bcpuset=(["\\\'])(.*?)\\1~s',$entry[2],$c))continue;
            $item=['kind'=>$entry[1],'value'=>$c[2]];
            if($entry[1]==='vcpupin'&&preg_match('~\\bvcpu=(["\\\'])(\\d+)\\1~s',$entry[2],$v)){$item['vcpu']=(int)$v[2];$vcpuPins[]=(int)$v[2];}
            $sets[]=$item;
        }
    }
    if(preg_match('~<numatune\\b[^>]*>.*?</numatune>~s',$xml,$m)){
        if(preg_match_all('~\\b(?:nodeset|nodes)=(["\\\'])(.*?)\\1~s',$m[0],$n,PREG_SET_ORDER))foreach($n as $entry)$numaSets[]=$entry[2];
    }
    return ['present'=>!empty($sets)||!empty($numaSets),'cpuSets'=>$sets,'numaSets'=>$numaSets,'vcpuPins'=>array_values(array_unique($vcpuPins))];
}

function unmPinningCompatibility(array $pinning,array $resources,int $sourceVcpus,int $requestedVcpus): array {
    $reasons=[];$missingCpus=[];$missingNodes=[];
    if(empty($pinning['present']))return ['present'=>false,'valid'=>true,'reasons'=>[],'missingCpus'=>[],'missingNodes'=>[]];
    if($requestedVcpus!==$sourceVcpus)$reasons[]='The vCPU count is changing, so existing vCPU pin mappings cannot be retained.';
    $online=array_fill_keys(array_map('intval',$resources['cpuOnline']??[]),true);
    foreach($pinning['cpuSets']??[] as $entry)foreach(unmExpandRangeSet((string)($entry['value']??'')) as $cpu)if(!isset($online[$cpu]))$missingCpus[$cpu]=true;
    if($missingCpus){ksort($missingCpus,SORT_NUMERIC);$reasons[]='Pinning references destination-unavailable host CPUs: '.implode(', ',array_keys($missingCpus)).'.';}
    foreach($pinning['vcpuPins']??[] as $vcpu)if((int)$vcpu>=$requestedVcpus)$reasons[]='A vCPU pin targets virtual CPU '.$vcpu.', outside the requested vCPU count.';
    $nodes=array_fill_keys(array_map('intval',$resources['numaNodes']??[]),true);
    foreach($pinning['numaSets']??[] as $set)foreach(unmExpandRangeSet((string)$set) as $node)if(!isset($nodes[$node]))$missingNodes[$node]=true;
    if($missingNodes){ksort($missingNodes,SORT_NUMERIC);$reasons[]='NUMA tuning references destination-unavailable nodes: '.implode(', ',array_keys($missingNodes)).'.';}
    return ['present'=>true,'valid'=>empty($reasons),'reasons'=>array_values(array_unique($reasons)),'missingCpus'=>array_map('intval',array_keys($missingCpus)),'missingNodes'=>array_map('intval',array_keys($missingNodes))];
}


function unmHealthAdd(array &$issues,string $code,string $severity,string $message,bool $blocksMigration=false,array $context=[]): void {
    $issues[]=['code'=>$code,'severity'=>$severity,'message'=>$message,'blocksMigration'=>$blocksMigration,'context'=>$context];
}

/**
 * DISK_NP is Unraid's normal placeholder for an unassigned array slot.  Only
 * statuses that indicate a formerly assigned, disabled, invalid or wrong disk
 * are faults.  Keeping this separate makes the policy easy to regression-test.
 */
function unmArrayDiskStatusIsFault(string $status): bool {
    $status=strtoupper(trim($status));
    if($status===''||in_array($status,['DISK_OK','DISK_NP','DISK_NEW'],true))return false;
    return preg_match('/(?:DSBL|INVALID|MISSING|WRONG)/',$status)===1;
}

/** Return the text after zpool's errors: label, or null when it explicitly says
 * that no data errors are known.  Parse first and compare second: a negative
 * look-ahead after \s* can backtrack and falsely accept the success message.
 */
function unmZfsDataErrorText(string $statusOutput): ?string {
    if(!preg_match('/^\s*errors:\s*([^\r\n]*)/mi',$statusOutput,$m))return null;
    $text=trim((string)$m[1]);
    if($text===''||strcasecmp($text,'No known data errors')===0)return null;
    return $text;
}

function unmZfsVersions(): array {
    if(!unmTool('zfs'))return ['userland'=>'','kernel'=>'','raw'=>''];
    $r=unmRun(['zfs','version'],null,10);$raw=trim((string)($r['stdout'].' '.$r['stderr']));
    $userland='';$kernel='';
    if(preg_match('/^zfs-([^\s]+)/mi',$raw,$m))$userland=$m[1];
    if(preg_match('/^zfs-kmod-([^\s]+)/mi',$raw,$m))$kernel=$m[1];
    return ['userland'=>$userland,'kernel'=>$kernel,'raw'=>$raw];
}

function unmZfsCoreVersion(string $version): string {
    return preg_match('/(\d+\.\d+(?:\.\d+)?)/',$version,$m)?$m[1]:$version;
}

function unmHealth(): array {
    $cfg=unmLoadConfig(); $issues=[]; $resources=unmHostResources();
    $total=(int)($resources['totalBytes']??0); $available=(int)($resources['availableBytes']??0);
    $pct=$total>0?($available*100/$total):0;
    if($total<=0)unmHealthAdd($issues,'MEMORY_UNKNOWN','warning','Unable to determine available memory.');
    elseif($pct<=(int)$cfg['health_memory_critical_percent'])unmHealthAdd($issues,'MEMORY_CRITICAL','critical',sprintf('Only %.1f%% of RAM is currently available.',$pct),true,['availableBytes'=>$available,'totalBytes'=>$total]);
    elseif($pct<=(int)$cfg['health_memory_warning_percent'])unmHealthAdd($issues,'MEMORY_LOW','warning',sprintf('Only %.1f%% of RAM is currently available.',$pct),false,['availableBytes'=>$available,'totalBytes'=>$total]);

    $virsh=unmRun(['virsh','list','--all','--uuid'],null,15);
    if($virsh['code']!==0)unmHealthAdd($issues,'LIBVIRT_UNAVAILABLE','critical','libvirt/VM Manager is unavailable.',true);
    else foreach(preg_split('/\R/',trim($virsh['stdout']))?:[] as $uuid){
        $uuid=trim($uuid);if($uuid==='')continue;
        $state=unmRun(['virsh','domstate',$uuid,'--reason'],null,5);
        $text=strtolower(trim($state['stdout'].' '.$state['stderr']));
        if(str_contains($text,'crash')){
            $name=trim(unmRun(['virsh','domname',$uuid],null,5)['stdout'])?:$uuid;
            unmHealthAdd($issues,'VM_CRASHED','warning',"VM $name is reported as crashed.",false,['vmUuid'=>$uuid,'vmName'=>$name]);
        }
    }

    $var=@parse_ini_file('/var/local/emhttp/var.ini')?:[];
    $mdState=strtoupper((string)($var['mdState']??$var['MD_STATE']??''));
    if($mdState!==''&&!in_array($mdState,['STARTED','STARTED,READY'],true))unmHealthAdd($issues,'ARRAY_NOT_STARTED','warning',"Unraid array state is $mdState.");
    $disks=@parse_ini_file('/var/local/emhttp/disks.ini',true)?:[];
    foreach($disks as $name=>$disk){
        $status=strtoupper((string)($disk['status']??''));
        if(unmArrayDiskStatusIsFault($status)){
            unmHealthAdd($issues,'ARRAY_DISK_FAULT','critical',"Array device $name reports status $status.",true,['disk'=>$name,'status'=>$status]);
        }
    }

    $zfsVersions=unmZfsVersions();
    if($zfsVersions['userland']!==''&&$zfsVersions['kernel']!==''&&unmZfsCoreVersion($zfsVersions['userland'])!==unmZfsCoreVersion($zfsVersions['kernel'])){
        unmHealthAdd($issues,'ZFS_VERSION_MISMATCH','critical','ZFS userland '.$zfsVersions['userland'].' does not match loaded kernel module '.$zfsVersions['kernel'].'. Reboot or repair the Unraid upgrade before ZFS migration.',true,$zfsVersions);
    }

    $pools=[];
    if(unmTool('zpool')){
        $r=unmRun(['zpool','list','-H','-o','name,health'],null,15);
        if($r['code']===0)foreach(preg_split('/\R/',trim($r['stdout']))?:[] as $line){
            if(!preg_match('/^(\S+)\s+(\S+)/',$line,$m))continue;
            $name=$m[1];$health=strtoupper($m[2]);$scan='';
            $status=unmRun(['zpool','status',$name],null,15);
            if(preg_match('/^\s*scan:\s*(.+)$/mi',$status['stdout'],$sm))$scan=trim($sm[1]);
            $pools[]=['name'=>$name,'health'=>$health,'scan'=>$scan];
            if(!in_array($health,['ONLINE'],true)){
                $critical=in_array($health,['FAULTED','UNAVAIL','SUSPENDED','REMOVED'],true);
                unmHealthAdd($issues,'ZFS_POOL_'.$health,$critical?'critical':'warning',"ZFS pool $name is $health.",$critical,['pool'=>$name]);
            }
            if($scan!==''&&(stripos($scan,'resilver in progress')!==false||stripos($scan,'scrub in progress')!==false))unmHealthAdd($issues,'ZFS_SCAN_ACTIVE','warning',"ZFS pool $name: $scan",false,['pool'=>$name]);
            $dataErrors=unmZfsDataErrorText((string)$status['stdout']);
            if($dataErrors!==null)unmHealthAdd($issues,'ZFS_DATA_ERRORS','critical',"ZFS pool $name reports data errors: $dataErrors",true,['pool'=>$name]);
        }
    }

    $imagePath=(string)$cfg['image_dir'];
    if($imagePath===''||!is_dir($imagePath))unmHealthAdd($issues,'IMAGE_PATH_MISSING','critical',"Configured image path is unavailable: $imagePath",true);
    elseif(!is_writable($imagePath))unmHealthAdd($issues,'IMAGE_PATH_READONLY','critical',"Configured image path is not writable: $imagePath",true);
    $isoPath=(string)$cfg['iso_dir'];
    // ISO storage is optional for migrations which are not copying optical media.
    // Report a bad configured path, but do not make the whole host migration-blocking.
    if($isoPath===''||!is_dir($isoPath))unmHealthAdd($issues,'ISO_PATH_MISSING','warning',"Configured ISO path is unavailable: $isoPath",false);
    elseif(!is_writable($isoPath))unmHealthAdd($issues,'ISO_PATH_READONLY','warning',"Configured ISO path is not writable: $isoPath",false);
    if((string)$cfg['zvol_dataset']!==''&&!unmZfsDatasetExists((string)$cfg['zvol_dataset']))unmHealthAdd($issues,'ZVOL_DATASET_MISSING','warning','Configured destination zvol dataset is unavailable: '.$cfg['zvol_dataset']);

    $syslog='/var/log/syslog';
    if(is_file($syslog)){
        $r=unmRun('tail -n 2500 '.escapeshellarg($syslog).' | grep -Eai "out of memory|oom-killer|killed process" | tail -n 1',null,5);
        if($r['code']===0&&trim($r['stdout'])!=='')unmHealthAdd($issues,'RECENT_OOM','warning','A recent out-of-memory event appears in syslog.',false,['event'=>trim($r['stdout'])]);
    }
    $rank=['healthy'=>0,'warning'=>1,'unhealthy'=>2];$status='healthy';
    foreach($issues as $issue){$candidate=($issue['severity']??'warning')==='critical'?'unhealthy':'warning';if($rank[$candidate]>$rank[$status])$status=$candidate;}
    return ['status'=>$status,'hostname'=>gethostname()?:'unknown','hostId'=>unmHostId(),'version'=>UNM_VERSION,'protocolVersion'=>UNM_PROTOCOL,'checkedAt'=>date(DATE_ATOM),'issues'=>$issues,'resources'=>$resources,'storage'=>['zfsPools'=>$pools,'arrayState'=>$mdState?:'unknown','zfsVersions'=>$zfsVersions],'migrationBlocked'=>(bool)array_filter($issues,static fn(array $i):bool=>!empty($i['blocksMigration']))];
}

function unmCapabilities(): array {
    $cfg=unmLoadConfig();$zfsAvailable=unmTool('zfs');$zvolReady=unmZfsDatasetExists((string)$cfg['zvol_dataset']);
    $imageDataset=is_dir($cfg['image_dir'])?unmZfsDatasetForPath($cfg['image_dir'],true):null;
    $imageContaining=is_dir($cfg['image_dir'])?unmZfsDatasetForPath($cfg['image_dir'],false):null;
    $isoContaining=is_dir($cfg['iso_dir'])?unmZfsDatasetForPath($cfg['iso_dir'],false):null;
    $checks=['image_dir_exists'=>is_dir($cfg['image_dir']),'image_dir_writable'=>is_dir($cfg['image_dir'])&&is_writable($cfg['image_dir']),'iso_dir_exists'=>is_dir($cfg['iso_dir']),'iso_dir_writable'=>is_dir($cfg['iso_dir'])&&is_writable($cfg['iso_dir']),'zvol_dataset_exists'=>$zvolReady];
    $toolNames=['virsh','zfs','zpool','qemu-img','rsync','tar','ssh','pv'];
    return ['protocolVersion'=>UNM_PROTOCOL,'replicationProtocolVersion'=>UNM_REPLICATION_PROTOCOL,'pluginVersion'=>UNM_VERSION,'hostId'=>unmHostId(),'hostname'=>gethostname()?:'unknown','ssh'=>unmSshStatus(),
        'storage'=>['imageDirectory'=>$cfg['image_dir'],'zvolDataset'=>$cfg['zvol_dataset'],'isoDirectory'=>$cfg['iso_dir'],'dedup'=>$cfg['dedup'],'compression'=>$cfg['compression'],'imageZfsDataset'=>$imageDataset,'imageZfsContainingDataset'=>$imageContaining,'isoZfsContainingDataset'=>$isoContaining,'zfsVersions'=>unmZfsVersions()],
        'resources'=>unmHostResources(),
        'features'=>['zfs'=>$zfsAvailable,'zvol'=>$zvolReady,'zvolToImage'=>unmTool('qemu-img')&&unmTool('rsync'),'fileImages'=>unmTool('rsync'),'dedicatedDatasetImages'=>$zfsAvailable,'localClone'=>true,'ubuntuGuestCustomization'=>true,'warmMove'=>true,'warmZfsIncremental'=>$zfsAvailable,'warmRsyncSeed'=>unmTool('rsync'),'qemuGuestAgentQuiesce'=>true,'peerHealth'=>true,'tpm'=>unmTool('swtpm')||is_dir('/etc/libvirt/qemu/swtpm')||is_dir('/var/lib/libvirt/swtpm'),'isoCopy'=>unmTool('rsync'),'discovery'=>unmTool('avahi-browse'),'pciPassthroughMigration'=>false,'usbPassthroughPolicy'=>true,'liveUsbInventory'=>true,'jobCancellation'=>true,'resourceResize'=>true,'cpuPinningValidation'=>true,'streamingProgress'=>true,'delayedSourceCleanup'=>true,'destinationConflictHandling'=>true,'scheduledReplication'=>true,'replicationProtocolVersion'=>UNM_REPLICATION_PROTOCOL,'replicationRetention'=>true,'replicaInventory'=>true],
        'checks'=>$checks,'tools'=>array_combine($toolNames,array_map('unmTool',$toolNames))];
}

function unmDomainXml(string $vmIdentifier, bool $inactive = true): string {
    $command = ['virsh','dumpxml',$vmIdentifier];
    if ($inactive) $command[] = '--inactive';
    $r = unmRun($command, null, 30);
    if ($r['code'] !== 0) throw new RuntimeException(trim($r['stderr']) ?: "Unable to read VM $vmIdentifier");
    return $r['stdout'];
}

function unmXmlValue(string $xml, string $tag): string {
    return preg_match('~<' . preg_quote($tag, '~') . '\b[^>]*>(.*?)</' . preg_quote($tag, '~') . '>~s', $xml, $m)
        ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1)) : '';
}

function unmNormaliseUsbId(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/^0x/', '', $value) ?? $value;
    return preg_match('/^[0-9a-f]{1,4}$/', $value) ? '0x' . str_pad($value, 4, '0', STR_PAD_LEFT) : '';
}

function unmUsbSystemIndex(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;
    $byAddress = []; $byVidPid = [];
    foreach (glob('/sys/bus/usb/devices/*') ?: [] as $dir) {
        $busFile = $dir . '/busnum'; $devFile = $dir . '/devnum';
        $vendorFile = $dir . '/idVendor'; $productFile = $dir . '/idProduct';
        if (!is_file($busFile) || !is_file($devFile) || !is_file($vendorFile) || !is_file($productFile)) continue;
        $bus = (int)trim((string)@file_get_contents($busFile));
        $device = (int)trim((string)@file_get_contents($devFile));
        $vendor = unmNormaliseUsbId((string)@file_get_contents($vendorFile));
        $product = unmNormaliseUsbId((string)@file_get_contents($productFile));
        if ($bus < 1 || $device < 1 || $vendor === '' || $product === '') continue;
        $manufacturer = trim((string)@file_get_contents($dir . '/manufacturer'));
        $productName = trim((string)@file_get_contents($dir . '/product'));
        $serial = trim((string)@file_get_contents($dir . '/serial'));
        $description = trim(implode(' ', array_filter([$manufacturer, $productName])));
        $entry = ['bus'=>$bus,'device'=>$device,'vendor'=>$vendor,'product'=>$product,'description'=>$description,'serial'=>$serial];
        $byAddress[$bus . ':' . $device] = $entry;
        $byVidPid[strtolower($vendor . ':' . $product)][] = $entry;
    }
    return $cached = ['byAddress'=>$byAddress,'byVidPid'=>$byVidPid];
}

function unmParseHostDevices(string $xmlText, string $origin): array {
    $usb = []; $pci = [];
    $systemUsb = unmUsbSystemIndex();
    if (!preg_match_all('~<hostdev\b[^>]*>.*?</hostdev>~s', $xmlText, $blocks)) return ['usb'=>[],'pci'=>[]];
    foreach ($blocks[0] as $block) {
        $type = preg_match('~<hostdev\b[^>]*\btype=(["\'])(.*?)\1~s', $block, $m) ? strtolower($m[2]) : '';
        if ($type === 'pci') {
            $pci[] = ['type'=>'pci','xml'=>$block,'origin'=>$origin];
            continue;
        }
        if ($type !== 'usb') continue;
        $sourceBlock = preg_match('~<source\b[^>]*>(.*?)</source>~s', $block, $m) ? $m[1] : '';
        $vendor = preg_match('~<vendor\b[^>]*\bid=(["\'])(.*?)\1~s', $sourceBlock, $m) ? unmNormaliseUsbId($m[2]) : '';
        $product = preg_match('~<product\b[^>]*\bid=(["\'])(.*?)\1~s', $sourceBlock, $m) ? unmNormaliseUsbId($m[2]) : '';
        $bus=0; $device=0;
        if(preg_match('~<address\b([^>]*)/>~s',$sourceBlock,$addressMatch)){
            $attrs=$addressMatch[1];
            if(preg_match('~\bbus=(["\'])(\d+)\1~',$attrs,$m)) $bus=(int)$m[2];
            if(preg_match('~\bdevice=(["\'])(\d+)\1~',$attrs,$m)) $device=(int)$m[2];
        }
        $description = ''; $serial = '';
        if ($bus > 0 && $device > 0 && isset($systemUsb['byAddress'][$bus . ':' . $device])) {
            $physical = $systemUsb['byAddress'][$bus . ':' . $device];
            $vendor = $vendor !== '' ? $vendor : $physical['vendor'];
            $product = $product !== '' ? $product : $physical['product'];
            $description = $physical['description'];
            $serial = $physical['serial'];
        }
        $usb[] = [
            'type'=>'usb','vendor'=>$vendor,'product'=>$product,'bus'=>$bus,'device'=>$device,
            'description'=>$description,'serial'=>$serial,'xml'=>$block,'origin'=>$origin,
            'portable'=>($vendor !== '' && $product !== ''),
        ];
    }
    return ['usb'=>$usb,'pci'=>$pci];
}

function unmMergeUsbDevices(array $persistent, array $live): array {
    $out = [];
    foreach ($persistent as $i => $device) {
        $device['persistent'] = true; $device['presentLive'] = false; $device['liveOnly'] = false;
        $key = $device['bus'] && $device['device'] ? 'addr:' . $device['bus'] . ':' . $device['device'] : 'persistent:' . $i . ':' . hash('sha256', (string)$device['xml']);
        $out[$key] = $device;
    }
    foreach ($live as $i => $device) {
        $matchKey = null;
        if ($device['bus'] && $device['device']) {
            $candidate = 'addr:' . $device['bus'] . ':' . $device['device'];
            if (isset($out[$candidate])) $matchKey = $candidate;
        }
        if ($matchKey === null && $device['vendor'] !== '' && $device['product'] !== '') {
            foreach ($out as $key => $existing) {
                if (($existing['vendor'] ?? '') === $device['vendor'] && ($existing['product'] ?? '') === $device['product'] && empty($existing['presentLive'])) { $matchKey = $key; break; }
            }
        }
        if ($matchKey !== null) {
            $out[$matchKey] = array_replace($out[$matchKey], array_filter($device, static fn($v): bool => $v !== '' && $v !== 0));
            $out[$matchKey]['persistent'] = true; $out[$matchKey]['presentLive'] = true; $out[$matchKey]['liveOnly'] = false;
        } else {
            $device['persistent'] = false; $device['presentLive'] = true; $device['liveOnly'] = true;
            $key = $device['bus'] && $device['device'] ? 'live:' . $device['bus'] . ':' . $device['device'] : 'live:' . $i . ':' . hash('sha256', (string)$device['xml']);
            $out[$key] = $device;
        }
    }
    $devices=array_values($out);
    $vidpidCounts=[];
    foreach($devices as $device){
        $vendor=(string)($device['vendor']??''); $product=(string)($device['product']??'');
        if($vendor!==''&&$product!=='') $vidpidCounts[strtolower($vendor.':'.$product)]=($vidpidCounts[strtolower($vendor.':'.$product)]??0)+1;
    }
    foreach($devices as &$device){
        $vendor=(string)($device['vendor']??''); $product=(string)($device['product']??'');
        $key=strtolower($vendor.':'.$product);
        // A VID:PID selector cannot distinguish two identical devices on the destination.
        $device['ambiguous']=$vendor!=='' && $product!=='' && ($vidpidCounts[$key]??0)>1;
        if($device['ambiguous']) $device['portable']=false;
    }
    unset($device);
    return $devices;
}

function unmParseVm(string $vmIdentifier): array {
    $inactiveXml = unmDomainXml($vmIdentifier, true);
    $uuid = unmXmlValue($inactiveXml, 'uuid');
    $name = unmXmlValue($inactiveXml, 'name');
    $ref = $uuid !== '' ? $uuid : $vmIdentifier;
    $state = trim(unmRun(['virsh','domstate',$ref], null, 10)['stdout']);
    $info = trim(unmRun(['virsh','dominfo',$ref], null, 10)['stdout']);
    $auto = preg_match('/^Autostart:\s+(\S+)/mi', $info, $m) ? $m[1] : 'unknown';
    $liveXml = '';
    if (!in_array($state, ['shut off','no state',''], true)) {
        try { $liveXml = unmDomainXml($ref, false); } catch (Throwable $ignored) {}
    }
    $disks=[]; $isos=[];
    if (preg_match_all('~<disk\b[^>]*>.*?</disk>~s', $inactiveXml, $blocks)) {
        foreach ($blocks[0] as $block) {
            $device = preg_match('~<disk\b[^>]*\bdevice=(["\'])(.*?)\1~s', $block, $m) ? $m[2] : '';
            $source=''; $kind='';
            if (preg_match('~<source\b[^>]*\bdev=(["\'])(.*?)\1~s', $block, $m)) { $source=html_entity_decode($m[2], ENT_QUOTES|ENT_XML1); $kind='block'; }
            elseif (preg_match('~<source\b[^>]*\bfile=(["\'])(.*?)\1~s', $block, $m)) { $source=html_entity_decode($m[2], ENT_QUOTES|ENT_XML1); $kind='file'; }
            if ($device==='cdrom') { if($source!=='') $isos[]=$source; continue; }
            if ($device!=='disk' || $source==='') continue;
            $storage=str_starts_with($source,'/dev/zvol/')?'zvol':($kind==='file'?'file':'other');
            $format=preg_match('~<driver\b[^>]*\btype=(["\'])(.*?)\1~s',$block,$fm)?strtolower($fm[2]):'';
            $disks[]=['type'=>$storage,'source'=>$source,'format'=>$format];
        }
    }
    $persistentDevices = unmParseHostDevices($inactiveXml, 'persistent');
    $liveDevices = $liveXml !== '' ? unmParseHostDevices($liveXml, 'live') : ['usb'=>[],'pci'=>[]];
    $usb = unmMergeUsbDevices($persistentDevices['usb'], $liveDevices['usb']);
    $pciMap=[];
    foreach (array_merge($persistentDevices['pci'], $liveDevices['pci']) as $entry) $pciMap[hash('sha256', (string)$entry['xml'])]=$entry;
    $vcpus=preg_match('~<vcpu\b[^>]*>\s*(\d+)\s*</vcpu>~s',$inactiveXml,$m)?(int)$m[1]:0;
    $memoryKiB=unmXmlMemoryKiB($inactiveXml);$cpuPinning=unmCpuPinningInfo($inactiveXml);
    $vm=[
        'name'=>$name !== '' ? $name : $vmIdentifier,'uuid'=>$uuid,'state'=>$state,'autostart'=>$auto,'vcpus'=>$vcpus,'memoryKiB'=>$memoryKiB,'memoryMiB'=>(int)ceil($memoryKiB/1024),'cpuPinning'=>$cpuPinning,
        'tpm'=>(bool)preg_match('~<tpm(?:\s|>)~',$inactiveXml),
        'nvram'=>unmXmlValue($inactiveXml,'nvram'),'loader'=>unmXmlValue($inactiveXml,'loader'),
        'disks'=>$disks,'isos'=>$isos,'usb'=>$usb,'pci'=>array_values($pciMap),
        'hasCpuPinning'=>(bool)$cpuPinning['present'],
        'liveXmlAvailable'=>$liveXml !== '',
        'guestAgent'=>unmGuestAgentStatus($ref,$state),
    ];
    return unmClassifyVmStorage($vm,true);
}

function unmInventory(): array {
    $r=unmRun(['virsh','list','--all','--uuid'], null, 30);
    if ($r['code']!==0) throw new RuntimeException(trim($r['stderr']));
    $out=[];
    foreach (preg_split('/\R/', trim($r['stdout'])) as $uuid) {
        $uuid=trim($uuid); if ($uuid==='') continue;
        try { $out[]=unmParseVm($uuid); }
        catch (Throwable $e) { $out[]=['name'=>$uuid,'uuid'=>$uuid,'error'=>$e->getMessage()]; }
    }
    return $out;
}

function unmNewUuid(): string {
    $bytes=random_bytes(16);
    $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
    $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);
    return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
}

function unmCloneName(string $name): string {
    $name=trim($name);
    if($name===''||strlen($name)>128||$name[0]==='-'||in_array($name,['.','..'],true)||preg_match('~[\\/\x00-\x1f\x7f]~',$name))
        throw new InvalidArgumentException('Clone name must be 1-128 characters and cannot contain slashes or control characters.');
    return $name;
}

function unmCloneObjectStem(string $name): string {
    $slug=strtolower((string)preg_replace('/[^A-Za-z0-9_.-]+/','-',trim($name)));
    $slug=trim($slug,'.-');
    if($slug==='')$slug='vm';
    $slug=substr($slug,0,40);
    return 'unmotion-clone-'.$slug.'-'.substr(hash('sha256',$name),0,8);
}

function unmLocalDomainMacs(): array {
    $seen=[];$r=unmRun(['virsh','list','--all','--uuid'],null,30);
    if($r['code']!==0)return [];
    foreach(preg_split('/\R/',trim($r['stdout']))?:[] as $uuid){
        $uuid=trim($uuid);if($uuid==='')continue;
        try{$xml=unmDomainXml($uuid,true);}catch(Throwable $ignored){continue;}
        if(preg_match_all('~<mac\b[^>]*\baddress=(["\'])([0-9a-f:]{17})\1~i',$xml,$m))
            foreach($m[2] as $mac)$seen[strtolower($mac)]=true;
    }
    return $seen;
}

function unmNewDomainMacs(int $count): array {
    $seen=unmLocalDomainMacs();$out=[];
    while(count($out)<$count){
        $tail=random_bytes(3);
        $mac=sprintf('52:54:00:%02x:%02x:%02x',ord($tail[0]),ord($tail[1]),ord($tail[2]));
        if(isset($seen[$mac]))continue;
        $seen[$mac]=true;$out[]=$mac;
    }
    return $out;
}

function unmCloneDatasetIsolated(string $dataset,string $mountpoint,array $diskPaths): bool {
    if($dataset===''||$mountpoint===''||!str_starts_with($mountpoint,'/mnt/'))return false;
    $children=unmRun(['zfs','list','-H','-r','-d','1','-o','name',$dataset],null,20);
    if($children['code']!==0)return false;
    $names=array_values(array_filter(preg_split('/\R/',trim($children['stdout']))?:[]));
    if(count($names)!==1||$names[0]!==$dataset)return false;
    $allowed=[];$parents=[];
    foreach($diskPaths as $path){
        $real=realpath((string)$path);if($real===false||!is_file($real)||is_link($real)||!str_starts_with($real.'/',rtrim($mountpoint,'/').'/'))return false;
        $allowed[$real]=true;$parent=dirname($real);
        while($parent!==$mountpoint&&str_starts_with($parent.'/',rtrim($mountpoint,'/').'/')){$parents[$parent]=true;$next=dirname($parent);if($next===$parent)break;$parent=$next;}
    }
    $scan=unmRun(['find',$mountpoint,'-xdev','-mindepth','1','-printf','%y\0%p\0'],null,30);
    if($scan['code']!==0)return false;
    $parts=explode("\0",$scan['stdout']);
    for($i=0;$i+1<count($parts);$i+=2){$type=$parts[$i];$path=$parts[$i+1];if($path==='')continue;if($type==='d'&&isset($parents[$path]))continue;if($type==='f'&&isset($allowed[$path]))continue;return false;}
    return true;
}

/**
 * Build a same-host, full-copy clone plan.  This is a post-recovery 0.3.1
 * feature and deliberately does not use dependent `zfs clone` datasets.
 */
function unmClonePreflight(string $vmIdentifier,string $cloneName,array $options=[]): array {
    $cloneName=unmCloneName($cloneName);
    $vm=unmParseVm($vmIdentifier);$cfg=unmLoadConfig();$errors=[];$warnings=[];$plan=[];$maps=[];
    $state=strtolower(trim((string)($vm['state']??'')));
    if($state!=='shut off')$errors[]='Power off the source VM before cloning.';
    if(!empty($vm['pci']))$errors[]='PCIe passthrough must be removed before cloning.';
    if(!empty($vm['tpm']))$errors[]='Virtual TPM cloning is blocked in RC2 because copying or resetting TPM identity can invalidate guest secrets.';
    foreach(($vm['disks']??[]) as $disk)if(str_starts_with((string)($disk['transferClass']??''),'unsupported'))$errors[]=(string)($disk['storageReason']??'Unsupported disk storage.');
    $existing=unmRun(['virsh','dominfo',$cloneName],null,10);
    if($existing['code']===0)$errors[]='A local VM already uses the requested clone name.';
    if(!empty($vm['usb']))$warnings[]='USB passthrough definitions will be removed from the clone.';
    if(!empty($vm['isos']))$warnings[]='Attached ISOs will retain their current read-only host paths.';

    $customization=(string)($options['guest_customization']??'ubuntu-dhcp');
    if(!in_array($customization,['ubuntu-dhcp','none-disconnected'],true))$errors[]='Invalid guest customization mode.';
    if($customization==='ubuntu-dhcp')$warnings[]='The clone will boot once with every NIC link down. If QEMU Guest Agent confirms Ubuntu, unMotion will reset its machine ID, hostname and SSH host keys, replace Netplan with DHCP, then shut it down.';
    else $warnings[]='Guest identity and network files will not be changed; every cloned NIC will remain link-down until manually reviewed.';

    $cloneUuid=(string)($options['clone_uuid']??'');
    if(!preg_match('/^[a-f0-9-]{36}$/i',$cloneUuid))$cloneUuid=unmNewUuid();
    $cloneGenid=unmNewUuid();$cloneDir=rtrim((string)$cfg['image_dir'],'/').'/'.$cloneName;
    if(file_exists($cloneDir)||is_link($cloneDir))$errors[]='The clone image directory already exists: '.$cloneDir;
    $stem=unmCloneObjectStem($cloneName);$usedDest=[];$datasetGroups=[];$datasetNo=0;$required=['zfs'=>0,'image'=>0];$poolRequired=[];$datasetCandidates=[];$datasetIsolation=[];
    foreach(($vm['disks']??[]) as $disk)if(($disk['transferClass']??'')==='zfs-dataset-image')$datasetCandidates[(string)($disk['zfsDataset']??'')][]=(string)($disk['resolvedSource']??$disk['source']??'');
    foreach($datasetCandidates as $dataset=>$paths){$first=null;foreach(($vm['disks']??[]) as $disk)if(($disk['zfsDataset']??'')===$dataset){$first=$disk;break;}$datasetIsolation[$dataset]=unmCloneDatasetIsolated($dataset,(string)($first['zfsMountpoint']??''),$paths);if(!$datasetIsolation[$dataset])$warnings[]='Dataset '.$dataset.' contains unrelated content or child datasets; cloning will copy only the VM disk files.';}

    foreach(($vm['disks']??[]) as $i=>$disk){
        $source=(string)($disk['source']??'');$kind=(string)($disk['type']??'');$class=(string)($disk['transferClass']??'');
        if($source===''||preg_match('/[\x00-\x1f\x7f]/',$source)){$errors[]='A disk has an unsafe or empty source path.';continue;}
        if($kind==='zvol'){
            $bytes=0;
            $src=(string)($disk['zfsDataset']??substr($source,strlen('/dev/zvol/')));
            $base=rtrim((string)$cfg['zvol_dataset'],'/');if($base==='')$base=dirname($src);
            if(unmRun(['zfs','list','-H','-o','name',$base],null,10)['code']!==0)$errors[]='Clone zvol parent dataset is unavailable: '.$base;
            $dst=$base.'/'.$stem.'-disk'.($i+1);$dstPath='/dev/zvol/'.$dst;
            if(isset($usedDest[$dst])||unmRun(['zfs','list','-H','-o','name',$dst],null,10)['code']===0)$errors[]='Clone zvol destination already exists: '.$dst;
            $usedDest[$dst]=true;$referenced=unmRun(['zfs','get','-pH','-o','value','referenced',$src],null,10);
            if($referenced['code']!==0)$errors[]='Unable to inspect source zvol: '.$src;else{$bytes=(int)trim($referenced['stdout']);$required['zfs']+=$bytes;$pool=explode('/',$dst,2)[0];$poolRequired[$pool]=($poolRequired[$pool]??0)+$bytes;}
            $plan[]=['kind'=>'zvol','source'=>$src,'destination'=>$dst,'bytes'=>$bytes];$maps[$source]=$dstPath;continue;
        }
        if($kind!=='file')continue;
        $copySource=(string)($disk['resolvedSource']??$source);
        if(!is_file($copySource)||is_link($copySource)){$errors[]='Disk image is missing or is a symbolic link: '.$copySource;continue;}
        if($class==='zfs-dataset-image'&&!empty($datasetIsolation[(string)($disk['zfsDataset']??'')])){
            $srcDs=(string)($disk['zfsDataset']??'');
            if(!isset($datasetGroups[$srcDs])){
                $datasetNo++;$dstDs=dirname($srcDs).'/'.$stem.($datasetNo>1?'-'.$datasetNo:'');
                $mount=$datasetNo===1?$cloneDir:$cloneDir.'/dataset-'.$datasetNo;
                $r=unmRun(['zfs','get','-pH','-o','value','referenced',$srcDs],null,10);$bytes=$r['code']===0?(int)trim($r['stdout']):0;
                if($r['code']!==0)$errors[]='Unable to inspect source dataset: '.$srcDs;
                if(unmRun(['zfs','list','-H','-o','name',$dstDs],null,10)['code']===0)$errors[]='Clone dataset destination already exists: '.$dstDs;
                $datasetGroups[$srcDs]=['destination'=>$dstDs,'mountpoint'=>$mount];$required['zfs']+=$bytes;$pool=explode('/',$dstDs,2)[0];$poolRequired[$pool]=($poolRequired[$pool]??0)+$bytes;
                $plan[]=['kind'=>'dataset','source'=>$srcDs,'destination'=>$dstDs,'mountpoint'=>$mount,'bytes'=>$bytes];
            }
            $group=$datasetGroups[$srcDs];$relative=(string)($disk['relativePath']??basename($copySource));
            $maps[$source]=rtrim($group['mountpoint'],'/').'/'.ltrim($relative,'/');continue;
        }
        $base=basename($copySource);$dst=$cloneDir.'/'.$base;
        if(isset($usedDest[$dst]))$dst=$cloneDir.'/disk'.($i+1).'-'.$base;
        $usedDest[$dst]=true;$bytes=(int)filesize($copySource);$required['image']+=$bytes;
        $plan[]=['kind'=>'file','source'=>$copySource,'destination'=>$dst,'bytes'=>$bytes];$maps[$source]=$dst;
    }

    $nvram=(string)($vm['nvram']??'');
    if($nvram!==''){
        if((!str_starts_with($nvram,'/etc/libvirt/qemu/nvram/')&&!str_starts_with($nvram,'/var/lib/libvirt/qemu/nvram/'))||!is_file($nvram)||is_link($nvram))$errors[]='UEFI NVRAM is missing or outside the verified libvirt NVRAM directories.';
        else{$nvramDest=dirname($nvram).'/'.$cloneUuid.'_VARS.fd';if(file_exists($nvramDest)||is_link($nvramDest))$errors[]='Clone NVRAM destination already exists.';else{$plan[]=['kind'=>'nvram','source'=>$nvram,'destination'=>$nvramDest,'bytes'=>(int)filesize($nvram)];$maps[$nvram]=$nvramDest;}}
    }
    foreach($poolRequired as $pool=>$need){$r=unmRun(['zfs','get','-pH','-o','value','available',$pool],null,10);if($r['code']!==0)$errors[]='Unable to inspect available ZFS space in '.$pool.'.';elseif((int)trim($r['stdout'])<$need)$errors[]='Insufficient ZFS space in '.$pool.' for the clone.';}
    $fs=unmLocalFsCapacity(rtrim((string)$cfg['image_dir'],'/'));if($required['image']>0&&(int)($fs['available']??0)<$required['image'])$errors[]='Insufficient image-directory space for the clone.';
    $xml=unmDomainXml((string)$vm['uuid'],true);$interfaceCount=preg_match_all('~<interface\b[^>]*>.*?</interface>~s',$xml);
    $identity=['uuid'=>$cloneUuid,'genid'=>$cloneGenid,'macs'=>unmNewDomainMacs((int)$interfaceCount),'sourceXmlSha256'=>hash('sha256',$xml)];
    return ['ready'=>empty($errors),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'vm'=>$vm,'clone'=>['name'=>$cloneName,'directory'=>$cloneDir,'customization'=>$customization]+$identity,'plan'=>$plan,'pathMap'=>$maps,'capacity'=>['required'=>$required,'imageAvailable'=>(int)($fs['available']??0),'zfsPools'=>$poolRequired]];
}

function unmPeerPath(string $id, string $ext='json'): string {
    if (!preg_match('/^[a-f0-9-]{8,64}$/i',$id)) throw new InvalidArgumentException('Invalid peer id.');
    return UNM_PEERS_DIR . '/' . $id . '.' . $ext;
}

function unmPeers(): array {
    unmEnsureDirs(); $peers=[];
    foreach (glob(UNM_PEERS_DIR.'/*.json') ?: [] as $file) {
        $p=unmLoadJson($file); if ($p) $peers[]=$p;
    }
    usort($peers, fn($a,$b)=>strcasecmp($a['name']??'', $b['name']??''));
    return $peers;
}

function unmPeer(string $id): array {
    $p=unmLoadJson(unmPeerPath($id));
    if (!$p) throw new RuntimeException('Peer not found.');
    return $p;
}

function unmSshBase(array $peer): string {
    $host=(string)($peer['host']??'');
    unmAssertRemotePeerHost($host);
    $parts=['ssh','-T','-p',(string)($peer['port']??22),'-i',$peer['keyPath'],
        '-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=30','-o','ServerAliveCountMax=6',
        '-o','UserKnownHostsFile='.$peer['knownHosts'],'-o','StrictHostKeyChecking=yes',
        ($peer['username']??'root').'@'.$peer['host']];
    return implode(' ',array_map('escapeshellarg',$parts));
}

function unmRemote(array $peer, string $command, int $timeout=60): array {
    return unmRun(unmSshBase($peer).' '.escapeshellarg($command), null, $timeout);
}


function unmPeerHealth(string $id): array {
    $peer=unmPeer($id);$host=(string)$peer['host'];$port=(int)$peer['port'];
    $errno=0;$errstr='';$sock=@stream_socket_client("tcp://$host:$port",$errno,$errstr,3,STREAM_CLIENT_CONNECT);
    if(!is_resource($sock))return ['status'=>'unreachable','hostname'=>$peer['name']??$host,'hostId'=>$peer['hostId']??'','checkedAt'=>date(DATE_ATOM),'issues'=>[['code'=>'PEER_UNREACHABLE','severity'=>'critical','message'=>'SSH port is unreachable: '.($errstr?:"error $errno"),'blocksMigration'=>true]],'migrationBlocked'=>true,'transport'=>'tcp'];
    fclose($sock);
    $r=unmRemote($peer,'/usr/local/sbin/unmotion-agent health',12);
    if($r['code']!==0){
        $stderr=trim($r['stderr']);$auth=(bool)preg_match('/permission denied|authentication failed/i',$stderr);
        return ['status'=>$auth?'pairing-broken':'agent-unavailable','hostname'=>$peer['name']??$host,'hostId'=>$peer['hostId']??'','checkedAt'=>date(DATE_ATOM),'issues'=>[['code'=>$auth?'PAIRING_BROKEN':'AGENT_UNAVAILABLE','severity'=>'critical','message'=>$auth?'SSH key authentication failed.':'Host is reachable but the unMotion agent did not answer: '.($stderr?:'unknown error'),'blocksMigration'=>true]],'migrationBlocked'=>true,'transport'=>'ssh'];
    }
    $data=json_decode($r['stdout'],true);
    if(!is_array($data))return ['status'=>'agent-unavailable','hostname'=>$peer['name']??$host,'hostId'=>$peer['hostId']??'','checkedAt'=>date(DATE_ATOM),'issues'=>[['code'=>'INVALID_AGENT_RESPONSE','severity'=>'critical','message'=>'The peer agent returned invalid health data.','blocksMigration'=>true]],'migrationBlocked'=>true,'transport'=>'agent'];
    $data['transport']='agent';return $data;
}

function unmAvahiUnescape(string $value): string {
    // avahi-browse --parsable escapes bytes as a backslash followed by three decimal digits.
    $value = preg_replace_callback('/\\\\([0-9]{3})/', static function(array $m): string {
        $code = (int)$m[1];
        return ($code >= 0 && $code <= 255) ? chr($code) : $m[0];
    }, $value) ?? $value;
    return str_replace('\\\\', '\\', $value);
}

function unmDiscoveryName(string $instance, string $target, string $address): string {
    $name = trim(unmAvahiUnescape($instance));
    // The advertised instance is "<hostname> unMotion". Only display the server name.
    $name = preg_replace('/\s+unMotion\s*$/iu', '', $name) ?? $name;
    if ($name !== '') return $name;
    $target = trim(unmAvahiUnescape($target));
    $target = preg_replace('/\.local\.?$/i', '', $target) ?? $target;
    return $target !== '' ? $target : $address;
}

function unmParseAvahiTxtRecordList(string $value): array {
    $txt = [];
    if (!preg_match_all('/"((?:\\\\.|[^"])*)"|([^\\s]+)/', trim($value), $matches, PREG_SET_ORDER)) return $txt;
    foreach ($matches as $match) {
        $entry = ($match[1] ?? '') !== '' ? (string)$match[1] : (string)($match[2] ?? '');
        $entry = unmAvahiUnescape($entry);
        if (!str_contains($entry, '=')) continue;
        [$key, $entryValue] = explode('=', $entry, 2);
        $key = trim($key);
        if ($key !== '') $txt[$key] = trim($entryValue);
    }
    return $txt;
}

function unmLocalIpv4Addresses(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;
    $addresses = ['127.0.0.1'];
    $r = unmRun(['ip','-4','-o','addr','show'], null, 10);
    if ($r['code'] === 0 && preg_match_all('/\binet\s+([0-9.]+)\//', $r['stdout'], $matches)) {
        foreach ($matches[1] as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $addresses[] = $address;
        }
    }
    $hostname = gethostname();
    if (is_string($hostname) && $hostname !== '') {
        foreach (gethostbynamel($hostname) ?: [] as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $addresses[] = $address;
        }
    }
    return $cached = array_values(array_unique($addresses));
}

function unmLocalHostnames(): array {
    static $cached = null;
    if (is_array($cached)) return $cached;
    $names = ['localhost', 'localhost.localdomain'];
    foreach ([gethostname() ?: '', php_uname('n')] as $name) {
        $name = strtolower(rtrim(trim((string)$name), '.'));
        if ($name === '') continue;
        $names[] = $name;
        $names[] = preg_replace('/\.local$/i', '', $name) ?? $name;
        $short = explode('.', $name, 2)[0] ?? '';
        if ($short !== '') $names[] = $short;
    }
    return $cached = array_values(array_unique(array_filter($names)));
}

function unmResolveIpv4Addresses(string $host): array {
    static $cache = [];
    $host = rtrim(trim($host), '.');
    $cacheKey = strtolower($host);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $cache[$cacheKey] = [$host];
    $addresses = gethostbynamel($host) ?: [];
    return $cache[$cacheKey] = array_values(array_unique(array_filter($addresses, static fn($address): bool =>
        filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    )));
}

function unmIsLocalPeerHost(string $host): bool {
    $normalised = strtolower(rtrim(trim($host), '.'));
    if ($normalised === '') return false;
    $withoutLocal = preg_replace('/\.local$/i', '', $normalised) ?? $normalised;
    $localHostnames = unmLocalHostnames();
    if (in_array($normalised, $localHostnames, true) || in_array($withoutLocal, $localHostnames, true)) return true;

    $localAddresses = array_flip(unmLocalIpv4Addresses());
    foreach (unmResolveIpv4Addresses($host) as $address) {
        $octets = explode('.', $address);
        if (($octets[0] ?? '') === '127' || $address === '0.0.0.0' || isset($localAddresses[$address])) return true;
    }
    return false;
}

function unmAssertRemotePeerHost(string $host): void {
    if (unmIsLocalPeerHost($host)) {
        throw new InvalidArgumentException('Refusing a peer connection from this server back to itself. Use the hostname or IP address of a different Unraid host.');
    }
}

function unmParseAvahiDiscoveryOutput(string $output): array {
    $peers = [];
    $localHostId = unmHostId();
    foreach (preg_split('/\R/', $output) as $line) {
        if (!str_starts_with($line, '=')) continue;
        // Avahi emits the TXT records as space-separated quoted strings in the
        // final semicolon-delimited field. CSV parsing combines those strings,
        // so split the fixed header first and parse the TXT list separately.
        $fields = explode(';', $line, 10);
        if (count($fields) < 9) continue;

        $family = $fields[2] ?? '';
        $instance = $fields[3] ?? '';
        $target = $fields[6] ?? '';
        $address = $fields[7] ?? '';
        $port = (int)($fields[8] ?? 22);
        if ($family !== 'IPv4' || $address === '' || $port < 1 || $port > 65535) continue;

        $txt = unmParseAvahiTxtRecordList((string)($fields[9] ?? ''));

        // Filter our own announcement by stable plugin identity first, then by all local IPv4 addresses.
        if (($txt['host_id'] ?? '') === $localHostId || unmIsLocalPeerHost($address)) continue;

        $key = $address . ':' . $port;
        $peers[$key] = [
            'name' => unmDiscoveryName($instance, $target, $address),
            'host' => $address,
            'port' => $port,
            'service' => unmAvahiUnescape((string)($fields[4] ?? '')),
            'hostId' => $txt['host_id'] ?? '',
            'pluginVersion' => $txt['version'] ?? '',
            'protocolVersion' => isset($txt['protocol']) ? (int)$txt['protocol'] : null,
        ];
    }
    return array_values($peers);
}

function unmPeerIdForAddress(string $host, int $port): string {
    return substr(hash('sha256', strtolower(trim($host) . ':' . $port . ':root')), 0, 24);
}

function unmCreatePeerMaterial(string $host, int $port): array {
    unmAssertRemotePeerHost($host);
    $id = unmPeerIdForAddress($host, $port);
    $dir = UNM_PEERS_DIR . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException("Unable to create peer directory $dir");
    $key = $dir . '/id_ed25519';
    $known = $dir . '/known_hosts';
    if (!is_file($key)) {
        $r = unmRun(['ssh-keygen','-q','-t','ed25519','-N','','-C','unmotion@'.(gethostname() ?: 'unraid'),'-f',$key], null, 30);
        if ($r['code'] !== 0) throw new RuntimeException('ssh-keygen failed: ' . trim($r['stderr']));
    }
    chmod($key, 0600);
    chmod($key . '.pub', 0644);
    $scan = unmRun('ssh-keyscan -T 10 -p ' . $port . ' ' . escapeshellarg($host), null, 20);
    if ($scan['code'] !== 0 || trim($scan['stdout']) === '') {
        throw new RuntimeException('Unable to connect to SSH on the peer. Confirm SSH is enabled under Settings → Management Access and that the SSH port is correct.');
    }
    file_put_contents($known, $scan['stdout'], LOCK_EX);
    chmod($known, 0600);
    return [
        'id' => $id,
        'name' => $host,
        'host' => $host,
        'port' => $port,
        'username' => 'root',
        'keyPath' => $key,
        'knownHosts' => $known,
        'fingerprint' => trim(unmRun(['ssh-keygen','-lf',$known], null, 10)['stdout']),
    ];
}

function unmSavePeerRecord(array $peer): void {
    $id = (string)($peer['id'] ?? '');
    unmAtomicJson(unmPeerPath($id), $peer);
    unmWriteCfg(unmPeerPath($id, 'cfg'), [
        'PEER_ID' => $id,
        'PEER_NAME' => $peer['name'] ?? $peer['host'] ?? '',
        'PEER_HOST' => $peer['host'] ?? '',
        'PEER_PORT' => $peer['port'] ?? 22,
        'PEER_USER' => 'root',
        'PEER_KEY' => $peer['keyPath'] ?? '',
        'PEER_KNOWN_HOSTS' => $peer['knownHosts'] ?? '',
        'PEER_HOST_ID' => $peer['hostId'] ?? '',
    ]);
}

function unmValidatePublicKey(string $publicKey): string {
    $publicKey = trim($publicKey);
    if (!preg_match('/^ssh-ed25519\s+[A-Za-z0-9+\/=]+(?:\s+[^\r\n]+)?$/', $publicKey)) {
        throw new InvalidArgumentException('The peer returned an invalid Ed25519 public key.');
    }
    return $publicKey;
}

function unmAppendAuthorizedKey(string $publicKey): void {
    $publicKey = unmValidatePublicKey($publicKey);
    $dir = '/root/.ssh';
    $path = $dir . '/authorized_keys';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create /root/.ssh.');
    chmod($dir, 0700);
    $lines = is_file($path) ? preg_split('/\R/', (string)file_get_contents($path)) : [];
    $lines = array_values(array_filter(array_map('trim', $lines ?: []), static fn(string $line): bool => $line !== ''));
    if (!in_array($publicKey, $lines, true)) $lines[] = $publicKey;
    $tmp = $path . '.unmotion.' . getmypid();
    if (file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX) === false) throw new RuntimeException('Unable to update authorized_keys.');
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) throw new RuntimeException('Unable to replace authorized_keys.');
}

function unmRemoveAuthorizedKey(string $publicKey): void {
    $publicKey = trim($publicKey);
    $path = '/root/.ssh/authorized_keys';
    if ($publicKey === '' || !is_file($path)) return;
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $lines = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== $publicKey && trim($line) !== ''));
    $tmp = $path . '.unmotion.' . getmypid();
    file_put_contents($tmp, $lines ? implode("\n", $lines) . "\n" : '', LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function unmLocalAddressForPeer(string $peerHost): string {
    $addresses = unmResolveIpv4Addresses($peerHost);
    $target = $addresses[0] ?? $peerHost;
    $r = unmRun(['ip','-4','route','get',$target], null, 10);
    if ($r['code'] === 0 && preg_match('/\bsrc\s+([0-9.]+)/', $r['stdout'], $m) && !str_starts_with($m[1], '127.')) return $m[1];
    foreach (unmLocalIpv4Addresses() as $address) if (!str_starts_with($address, '127.')) return $address;
    throw new RuntimeException('Unable to determine an IPv4 address the peer can use to connect back to this host.');
}

function unmPrepareReciprocalPeer(array $request): array {
    unmEnsureDirs();
    $host = trim((string)($request['host'] ?? ''));
    $port = (int)($request['port'] ?? 22);
    $hostId = trim((string)($request['hostId'] ?? ''));
    $name = trim((string)($request['hostname'] ?? $host));
    $incomingPublicKey = unmValidatePublicKey((string)($request['incomingPublicKey'] ?? ''));
    $reciprocalPeerId = trim((string)($request['reciprocalPeerId'] ?? ''));
    $caps = is_array($request['capabilities'] ?? null) ? $request['capabilities'] : [];
    if ($host === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $host)) throw new InvalidArgumentException('Invalid reciprocal peer address.');
    if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Invalid reciprocal SSH port.');
    if ($hostId === '' || $hostId === unmHostId()) throw new InvalidArgumentException('Invalid reciprocal host identity.');
    if (($caps['protocolVersion'] ?? UNM_PROTOCOL) !== UNM_PROTOCOL) throw new RuntimeException('Reciprocal peer protocol is incompatible.');
    unmAppendAuthorizedKey($incomingPublicKey);
    $peer = unmCreatePeerMaterial($host, $port);
    $peer['name'] = $name !== '' ? $name : $host;
    $peer['hostId'] = $hostId;
    $peer['pluginVersion'] = (string)($request['pluginVersion'] ?? '');
    $peer['lastCapabilities'] = $caps;
    $peer['reciprocalPeerId'] = $reciprocalPeerId;
    $peer['incomingPublicKey'] = $incomingPublicKey;
    $peer['pairingState'] = 'pending';
    $peer['pairedAt'] = null;
    unmSavePeerRecord($peer);
    return ['peerId'=>$peer['id'],'publicKey'=>trim((string)file_get_contents($peer['keyPath'].'.pub')),'host'=>$host,'port'=>$port];
}

function unmFinalizeReciprocalPeer(string $id): array {
    $peer = unmTestPeer($id);
    $peer['pairingState'] = 'paired';
    $peer['pairedAt'] = $peer['pairedAt'] ?? date(DATE_ATOM);
    unmSavePeerRecord($peer);
    return $peer;
}

function unmForgetPeerLocal(string $id): void {
    $peer = unmLoadJson(unmPeerPath($id));
    if ($peer) unmRemoveAuthorizedKey((string)($peer['incomingPublicKey'] ?? ''));
    @unlink(unmPeerPath($id));
    @unlink(unmPeerPath($id, 'cfg'));
    $dir = $peer ? dirname((string)($peer['keyPath'] ?? '')) : UNM_PEERS_DIR . '/' . $id;
    if ($dir !== UNM_PEERS_DIR && str_starts_with($dir, UNM_PEERS_DIR . '/')) unmRun(['rm','-rf','--',$dir], null, 15);
}

function unmPair(array $input): array {
    unmEnsureDirs();
    $host = trim((string)($input['host'] ?? ''));
    $port = (int)($input['port'] ?? 22);
    $password = (string)($input['password'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $host)) throw new InvalidArgumentException('Invalid hostname or IPv4 address. IPv6 peers are not supported in 0.2.');
    unmAssertRemotePeerHost($host);
    if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Invalid SSH port.');
    if ($password === '') throw new InvalidArgumentException('Password is required for initial pairing.');
    $localSsh = unmSshStatus();
    if (!$localSsh['enabled'] || !$localSsh['running'] || !$localSsh['listening']) {
        throw new RuntimeException('Local SSH must be enabled and listening before two-way pairing. Open Settings → Management Access and enable SSH.');
    }

    $peer = unmCreatePeerMaterial($host, $port);
    $pub = trim((string)file_get_contents($peer['keyPath'] . '.pub'));
    $passFile = tempnam('/tmp', 'unm-pass-');
    $askFile = tempnam('/tmp', 'unm-ask-');
    if ($passFile === false || $askFile === false) throw new RuntimeException('Unable to create temporary pairing files.');
    try {
        file_put_contents($passFile, $password);
        chmod($passFile, 0600);
        file_put_contents($askFile, "#!/bin/sh\ncat " . escapeshellarg($passFile) . "\n");
        chmod($askFile, 0700);
        $remoteCmd = 'umask 077; mkdir -p ~/.ssh; touch ~/.ssh/authorized_keys; IFS= read -r key; grep -qxF "$key" ~/.ssh/authorized_keys || printf "%s\\n" "$key" >> ~/.ssh/authorized_keys';
        $ssh = 'setsid -w env DISPLAY=:0 SSH_ASKPASS_REQUIRE=force SSH_ASKPASS=' . escapeshellarg($askFile) . ' ssh -T -p ' . $port .
            ' -o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive -o NumberOfPasswordPrompts=1' .
            ' -o UserKnownHostsFile=' . escapeshellarg($peer['knownHosts']) . ' -o StrictHostKeyChecking=yes ' . escapeshellarg('root@' . $host) . ' ' . escapeshellarg($remoteCmd);
        $r = unmRun($ssh, $pub, 40);
    } finally {
        @unlink($passFile);
        @unlink($askFile);
        $password = '';
    }
    if ($r['code'] !== 0) throw new RuntimeException('Password pairing failed: ' . trim($r['stderr']));

    $remotePreparedId = '';
    $remotePublicKey = '';
    try {
        $test = unmRemote($peer, '/usr/local/sbin/unmotion-agent capabilities', 30);
        if ($test['code'] !== 0) throw new RuntimeException('Key installed, but the remote unMotion agent could not be reached. Install a protocol-compatible unMotion release on the peer first. ' . trim($test['stderr']));
        $caps = json_decode($test['stdout'], true);
        if (!is_array($caps) || ($caps['protocolVersion'] ?? null) !== UNM_PROTOCOL) throw new RuntimeException('Remote agent returned incompatible capabilities.');
        if (($caps['hostId'] ?? '') === unmHostId()) throw new RuntimeException('The discovered host is this server, not a peer.');

        $peer['name'] = $caps['hostname'] ?? $host;
        $peer['hostId'] = $caps['hostId'] ?? '';
        $peer['pluginVersion'] = $caps['pluginVersion'] ?? '';
        $peer['lastCapabilities'] = $caps;
        $peer['pairedAt'] = date(DATE_ATOM);
        $peer['pairingState'] = 'pending-reciprocal';

        $localCaps = unmCapabilities();
        $prepareRequest = [
            'host' => unmLocalAddressForPeer($host),
            'port' => (int)$localSsh['port'],
            'hostId' => unmHostId(),
            'hostname' => gethostname() ?: 'unraid',
            'pluginVersion' => UNM_VERSION,
            'capabilities' => $localCaps,
            'reciprocalPeerId' => $peer['id'],
            'incomingPublicKey' => $pub,
        ];
        $encoded = base64_encode(json_encode($prepareRequest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $prepared = unmRemote($peer, '/usr/local/sbin/unmotion-agent reciprocal-prepare ' . escapeshellarg($encoded), 45);
        if ($prepared['code'] !== 0) throw new RuntimeException('Remote host could not prepare reciprocal pairing: ' . trim($prepared['stderr']));
        $preparedData = json_decode($prepared['stdout'], true);
        if (!is_array($preparedData) || empty($preparedData['peerId']) || empty($preparedData['publicKey'])) throw new RuntimeException('Remote host returned an invalid reciprocal pairing response.');
        $remotePreparedId = (string)$preparedData['peerId'];
        $remotePublicKey = unmValidatePublicKey((string)$preparedData['publicKey']);
        unmAppendAuthorizedKey($remotePublicKey);

        $finalized = unmRemote($peer, '/usr/local/sbin/unmotion-agent reciprocal-finalize ' . escapeshellarg($remotePreparedId), 45);
        if ($finalized['code'] !== 0) throw new RuntimeException('Remote host could not verify the reciprocal connection: ' . trim($finalized['stderr']));

        $peer['reciprocalPeerId'] = $remotePreparedId;
        $peer['incomingPublicKey'] = $remotePublicKey;
        $peer['pairingState'] = 'paired';
        unmSavePeerRecord($peer);
        return $peer;
    } catch (Throwable $e) {
        if ($remotePublicKey !== '') unmRemoveAuthorizedKey($remotePublicKey);
        if ($remotePreparedId !== '') @unmRemote($peer, '/usr/local/sbin/unmotion-agent reciprocal-forget ' . escapeshellarg($remotePreparedId), 20);
        $encodedPub = base64_encode($pub);
        $cleanup = 'key=$(printf %s ' . escapeshellarg($encodedPub) . ' | base64 -d); if [ -f ~/.ssh/authorized_keys ]; then tmp=$(mktemp ~/.ssh/authorized_keys.unmotion.XXXXXX) || exit 1; grep -vxF "$key" ~/.ssh/authorized_keys > "$tmp" || true; chmod 600 "$tmp"; mv "$tmp" ~/.ssh/authorized_keys; fi';
        @unmRemote($peer, $cleanup, 20);
        @unmForgetPeerLocal($peer['id']);
        throw $e;
    }
}

function unmRemovePeer(string $id): void {
    $peer = unmPeer($id);
    foreach (glob(UNM_REPLICATIONS_DIR.'/*/policy.json') ?: [] as $policyPath) {
        $replication=unmLoadJson($policyPath);
        if ((string)($replication['peerId'] ?? '') === $id) {
            throw new RuntimeException('This pairing is used by replication policy '.(string)($replication['id'] ?? basename(dirname($policyPath))).'. Remove the pristine policy first; policies with recovery state require a future explicit replica-cleanup workflow.');
        }
    }
    $reciprocalId = (string)($peer['reciprocalPeerId'] ?? '');
    if ($reciprocalId !== '') {
        try { unmRemote($peer, '/usr/local/sbin/unmotion-agent reciprocal-forget ' . escapeshellarg($reciprocalId), 25); }
        catch (Throwable $ignored) {}
    } else {
        // Fallback for an incomplete pairing: remove our outgoing key from the remote authorised-key list.
        $pubPath = (string)($peer['keyPath'] ?? '') . '.pub';
        if (is_file($pubPath)) {
            $encoded = base64_encode(trim((string)file_get_contents($pubPath)));
            $cmd = 'key=$(printf %s ' . escapeshellarg($encoded) . ' | base64 -d); if [ -f ~/.ssh/authorized_keys ]; then tmp=$(mktemp ~/.ssh/authorized_keys.unmotion.XXXXXX) || exit 1; grep -vxF "$key" ~/.ssh/authorized_keys > "$tmp" || true; chmod 600 "$tmp"; mv "$tmp" ~/.ssh/authorized_keys; fi';
            try { unmRemote($peer, $cmd, 20); } catch (Throwable $ignored) {}
        }
    }
    unmForgetPeerLocal($id);
}

function unmTestPeer(string $id): array {
    $peer=unmPeer($id); $r=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',30);
    if ($r['code']!==0) throw new RuntimeException(trim($r['stderr'])?:'Peer connection failed.');
    $caps=json_decode($r['stdout'],true); if (!is_array($caps)) throw new RuntimeException('Invalid capabilities response.');
    if (($caps['protocolVersion']??null)!==UNM_PROTOCOL) throw new RuntimeException('Peer protocol version is incompatible.');
    if (($peer['hostId']??'')!=='' && ($caps['hostId']??'')!==$peer['hostId']) throw new RuntimeException('Peer host identity changed. Remove and re-pair it before migration.');
    $peer['lastCapabilities']=$caps; $peer['lastContact']=date(DATE_ATOM); $peer['name']=$caps['hostname']??$peer['name']; $peer['pluginVersion']=$caps['pluginVersion']??($peer['pluginVersion']??'');
    unmAtomicJson(unmPeerPath($id),$peer); return $peer;
}

function unmDiscover(): array {
    if (!unmTool('avahi-browse')) return ['supported'=>false,'peers'=>[],'message'=>'avahi-browse is not installed on this Unraid release.'];
    $r=unmRun("timeout 5 avahi-browse -rtp _unmotion._tcp 2>/dev/null",null,8);
    return ['supported'=>true,'peers'=>unmParseAvahiDiscoveryOutput($r['stdout'])];
}

function unmReplicationRpoOptions(): array {
    return [300,900,1800,3600,7200,14400,21600,28800,43200,86400];
}

function unmValidateReplicationRpo(int $rpoSeconds): int {
    if(!in_array($rpoSeconds,unmReplicationRpoOptions(),true))throw new InvalidArgumentException('Invalid replication RPO.');
    return $rpoSeconds;
}

function unmReplicationMaxRetention(int $rpoSeconds): int {
    $rpoSeconds=unmValidateReplicationRpo($rpoSeconds);
    return max(1,min(24,(int)floor(86400/$rpoSeconds)));
}

function unmValidateReplicationRetention(int $rpoSeconds,int $retentionCount): int {
    $maximum=unmReplicationMaxRetention($rpoSeconds);
    if($retentionCount<1||$retentionCount>$maximum)throw new InvalidArgumentException("Retention must be between 1 and $maximum recovery points for this RPO.");
    return $retentionCount;
}

function unmReplicationPointTimestamp(array $point): int {
    foreach(['capturedAt','completedAt','createdAt','timestamp','lastSyncAt'] as $key){
        $value=$point[$key]??null;
        if(is_int($value)||is_float($value))return (int)$value;
        if(is_string($value)&&trim($value)!==''){$parsed=strtotime($value);if($parsed!==false)return $parsed;}
    }
    return 0;
}

/** Select the newest point in each UTC epoch-aligned retention bucket. */
function unmSelectReplicationRetentionPoints(array $points,int $rpoSeconds,int $retentionCount,?int $now=null): array {
    unmValidateReplicationRetention($rpoSeconds,$retentionCount);
    $now=$now??time();
    $normalised=[];
    foreach($points as $index=>$point){
        if(!is_array($point))continue;
        $timestamp=unmReplicationPointTimestamp($point);
        if($timestamp<=0)continue;
        $normalised[]=['point'=>$point,'timestamp'=>$timestamp,'index'=>$index];
    }
    usort($normalised,static fn(array $a,array $b):int=>$b['timestamp']<=>$a['timestamp']?:$b['index']<=>$a['index']);
    if(!$normalised)return [];
    // A future-dated point indicates clock skew. Preserve everything rather
    // than allowing uncertain wall-clock ordering to delete recovery data.
    foreach($normalised as $entry)if($entry['timestamp']>$now)return array_column($normalised,'point');
    $selected=[];$seenBuckets=[];$latest=$normalised[0];
    $selected[]=$latest['point'];$seenBuckets[intdiv($latest['timestamp']*$retentionCount,86400)]=true;
    foreach(array_slice($normalised,1) as $entry){
        if(count($selected)>=$retentionCount)break;
        if($entry['timestamp']<$now-86400||$entry['timestamp']>$now)continue;
        $bucket=intdiv($entry['timestamp']*$retentionCount,86400);
        if(isset($seenBuckets[$bucket]))continue;
        $seenBuckets[$bucket]=true;$selected[]=$entry['point'];
    }
    return $selected;
}

function unmReplicationId(string $vmUuid,string $destinationHostId): string {
    if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid VM UUID.');
    $destinationHostId=unmReplicaIdentityPart($destinationHostId,'destination host identity');
    return 'repl-'.substr(hash('sha256',strtolower($vmUuid).'|'.$destinationHostId),0,24);
}

function unmReplicationPath(string $id): string {
    if(!preg_match('/^repl-[a-f0-9]{24}$/',$id))throw new InvalidArgumentException('Invalid replication id.');
    return UNM_REPLICATIONS_DIR.'/'.$id;
}

function unmReplicaIdentityPart(string $value,string $label='identity'): string {
    $value=trim($value);
    if(!preg_match('/^[A-Za-z0-9_.-]{8,80}$/',$value))throw new InvalidArgumentException('Invalid replica '.$label.'.');
    return $value;
}

function unmReplicaPath(string $sourceHostId,string $replicationId): string {
    $sourceHostId=unmReplicaIdentityPart($sourceHostId,'source host identity');
    unmReplicationPath($replicationId);
    return UNM_REPLICAS_DIR.'/'.$sourceHostId.'/'.$replicationId;
}

function unmReplicationRuntimePath(string $id): string {
    unmReplicationPath($id);
    return '/var/run/unmotion/replications/'.$id.'.json';
}

function unmAcquireReplicationPolicyLock(string $id,bool $wait=false) {
    unmReplicationPath($id);$path='/var/lock/unmotion-replication-'.$id.'.lock';$handle=fopen($path,'c');
    if($handle===false)throw new RuntimeException('Unable to open the replication policy lock.');
    @chmod($path,0600);
    if(!flock($handle,LOCK_EX|($wait?0:LOCK_NB))){fclose($handle);throw new RuntimeException('The replication policy is busy with another run or mutation.');}
    return $handle;
}

function unmReleaseReplicationPolicyLock($handle): void {
    if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}
}

function unmReplicationPolicy(string $id): array {
    $policy=unmLoadJson(unmReplicationPath($id).'/policy.json');
    if(!$policy)throw new RuntimeException('Replication policy not found.');
    $expectedId=unmReplicationId((string)($policy['vmUuid']??''),(string)($policy['peerHostId']??''));if(!hash_equals($id,$expectedId))throw new RuntimeException('Replication policy identity does not match its VM and destination host.');
    return $policy;
}

function unmReplicationDurableState(string $id): array {
    return unmLoadJson(unmReplicationPath($id).'/state.json');
}

function unmReplicationActiveStates(): array {
    return ['QUEUED','STARTING','LOCKING','PREFLIGHT','PROBING_GUEST','QUIESCING','SNAPSHOTTING','THAWING','CAPTURING_HOST_STATE','TRANSFERRING','VERIFYING','PUBLISHING','COMMITTING','PRUNING'];
}

function unmReplicationStateIsActive(string $state): bool {
    return in_array(strtoupper(trim($state)),unmReplicationActiveStates(),true);
}

function unmReplicationRuntimeWorkerAlive(string $id,array $runtime): bool {
    unmReplicationPath($id);$pid=(int)($runtime['pid']??0);if($pid<=1)return false;$path='/proc/'.$pid.'/cmdline';if(!is_readable($path))return false;
    $command=(string)@file_get_contents($path);if($command==='')return false;$arguments=array_values(array_filter(explode("\0",trim($command,"\0")),static fn(string $value):bool=>$value!==''));
    return in_array('/usr/local/sbin/unmotion-replication-worker',$arguments,true)&&in_array($id,$arguments,true);
}

function unmReplication(string $id): array {
    $policy=unmReplicationPolicy($id);$durable=unmReplicationDurableState($id);$runtime=unmLoadJson(unmReplicationRuntimePath($id));
    $record=array_replace($policy,$durable,$runtime);
    $record['state']=(string)($record['state']??($policy['enabled']?'IDLE':'PAUSED'));
    if(!$runtime&&$record['state']==='QUEUED'){
        $queuedAt=strtotime((string)($durable['updatedAt']??''));
        if($queuedAt===false||$queuedAt<time()-120)$record['state']=is_array($durable['pending']??null)?'INTERRUPTED':'IDLE';
    }
    if($runtime&&unmReplicationStateIsActive($record['state'])&&!unmReplicationRuntimeWorkerAlive($id,$runtime))$record['state']=is_array($durable['pending']??null)?'INTERRUPTED':'FAILED';
    if(empty($policy['enabled'])&&!unmReplicationStateIsActive($record['state']))$record['state']='PAUSED';
    $record['status']=$record['state'];
    $record['pointCount']=(int)($record['pointCount']??count((array)($record['recoveryPoints']??[])));
    $pending=is_array($durable['pending']??null);
    $generation=(int)($durable['generation']??0);
    $lastScheduledSlot=(int)($durable['lastScheduledSlot']??$durable['lastSuccessfulSlot']??0);
    $nextDueEpoch=null;
    if(!empty($policy['enabled'])&&!$pending){
        $nextDueEpoch=($generation===0||$lastScheduledSlot<=0)?time():$lastScheduledSlot+(int)$policy['rpoSeconds'];
    }
    $record['nextDueEpoch']=$nextDueEpoch;
    $record['nextDueAt']=$nextDueEpoch===null?null:gmdate('c',$nextDueEpoch);
    $record['policy']=$policy;$record['durable']=$durable;$record['runtime']=$runtime;
    return $record;
}

function unmReplications(): array {
    unmEnsureDirs();$out=[];
    foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $path){
        $id=basename(dirname($path));
        try{$out[]=unmReplication($id);}catch(Throwable $ignored){}
    }
    usort($out,static fn(array $a,array $b):int=>strcasecmp((string)($a['vmName']??''),(string)($b['vmName']??''))?:strcmp((string)($a['id']??''),(string)($b['id']??'')));
    return $out;
}

function unmReplicationTpmInitialMode(string $mode): string {
    $mode=strtolower(trim($mode));
    if($mode==='best-effort-stun')$mode='best-effort';
    if(!in_array($mode,['power-cycle','best-effort','none'],true))throw new InvalidArgumentException('Invalid initial TPM capture mode.');
    return $mode;
}

function unmReplicationDatasetIsolationErrors(string $dataset,string $mountpoint,array $declaredFiles): array {
    return unmCloneDatasetIsolated($dataset,$mountpoint,$declaredFiles)
        ? []
        : ['Dataset '.$dataset.' contains a child dataset, unrelated entry, symbolic link, mount escape, or missing VM image and is treated as shared storage.'];
}

function unmReplicationZvolUsedByOtherVm(string $dataset,string $excludeUuid): bool {
    $r=unmRun(['virsh','list','--all','--uuid'],null,30);if($r['code']!==0)return true;
    foreach(preg_split('/\R/',trim($r['stdout']))?:[] as $uuid){
        $uuid=trim($uuid);if($uuid===''||strcasecmp($uuid,$excludeUuid)===0)continue;
        try{$xml=unmDomainXml($uuid,true);}catch(Throwable $ignored){return true;}
        if(preg_match('~<source\b[^>]*\bdev=(["\'])'.preg_quote('/dev/zvol/'.$dataset,'~').'\1~s',$xml))return true;
    }
    return false;
}

function unmReplicationPreflight(string $vmIdentifier,string $peerId,array $options=[]): array {
    if($vmIdentifier==='')throw new InvalidArgumentException('Select a VM.');
    $rpo=unmValidateReplicationRpo((int)($options['rpoSeconds']??$options['rpo_seconds']??3600));
    $retention=unmValidateReplicationRetention($rpo,(int)($options['retentionCount']??$options['retention_count']??1));
    $tpmMode=unmReplicationTpmInitialMode((string)($options['tpmInitialMode']??$options['tpm_initial_mode']??'none'));
    $vm=unmParseVm($vmIdentifier);$peer=unmTestPeer($peerId);$caps=(array)($peer['lastCapabilities']??[]);
    $errors=[];$warnings=[];$storage=[];$sourceDatasets=[];$short=substr(hash('sha256',unmHostId().'|'.(string)$vm['uuid'].'|'.(string)($peer['hostId']??'')),0,16);
    if((string)($peer['pairingState']??'paired')!=='paired')$errors[]='Scheduled replication requires a completed reciprocal pairing.';
    if((int)($caps['replicationProtocolVersion']??$caps['features']['replicationProtocolVersion']??0)!==UNM_REPLICATION_PROTOCOL||empty($caps['features']['scheduledReplication']))$errors[]='Destination does not support scheduled replication protocol 1. Upgrade it to unMotion 0.4 or later.';
    if(empty($vm['disks']))$errors[]='The VM has no writable disks to replicate.';
    if(!empty($vm['tpm']))$warnings[]='This VM uses a virtual TPM. Replication is more reliable without TPM; recovery points will identify safe and best-effort TPM captures.';
    if($tpmMode!=='none'&&empty($vm['tpm']))$warnings[]='An initial TPM capture mode was selected, but this VM does not report a virtual TPM.';
    $destStorage=(array)($caps['storage']??[]);$destFeatures=(array)($caps['features']??[]);$destChecks=(array)($caps['checks']??[]);
    $destZvolRoot=rtrim((string)($destStorage['zvolDataset']??''),'/');$destImageRoot=rtrim((string)($destStorage['imageZfsDataset']??''),'/');$destImageDir=rtrim((string)($destStorage['imageDirectory']??''),'/');
    $datasetGroups=[];$diskNumber=0;
    foreach((array)($vm['disks']??[]) as $disk){
        $diskNumber++;
        $class=(string)($disk['transferClass']??'');
        if($class==='zvol'){
            $source=substr((string)$disk['source'],strlen('/dev/zvol/'));
            if(isset($sourceDatasets[$source])){$errors[]='The same source zvol is attached more than once and cannot form an unambiguous replication plan: '.$source.'.';continue;}
            if(!unmZfsObjectNameSafe($source)||unmRun(['zfs','list','-H','-t','volume',$source],null,15)['code']!==0){$errors[]='Source zvol is missing or unsafe: '.$source;continue;}
            if(unmReplicationZvolUsedByOtherVm($source,(string)($vm['uuid']??''))){$errors[]='Source zvol is referenced by another VM or its ownership could not be proven: '.$source.'.';continue;}
            $encryption=unmRun(['zfs','get','-H','-o','value','encryption',$source],null,15);
            if($encryption['code']!==0||trim($encryption['stdout'])!=='off')$errors[]='Encrypted zvols are not supported for scheduled replication: '.$source.'.';
            if($destZvolRoot===''||empty($destFeatures['zvol'])||empty($destChecks['zvol_dataset_exists'])){$errors[]='Destination zvol storage is unavailable; scheduled replication does not convert zvols to image files.';continue;}
            $storage[]=['kind'=>'zvol','source'=>$source,'destination'=>$destZvolRoot.'/unmotion-replica-'.$short.'-disk'.$diskNumber,'sourceMountpoint'=>'','destinationMountpoint'=>'','files'=>[]];$sourceDatasets[$source]=true;
            continue;
        }
        if($class!=='zfs-dataset-image'){
            $reason=(string)($disk['storageReason']??'Storage is shared, non-ZFS, encrypted, or otherwise unsupported.');
            $errors[]=$reason.' Scheduled replication requires an isolated, unencrypted ZFS dataset or zvol. Use the ZFS Master plugin to prepare suitable storage.';
            continue;
        }
        $format=strtolower((string)($disk['format']??''));
        if(!in_array($format,['','raw','qcow','qcow2'],true)){$errors[]='Unsupported image format for scheduled replication: '.($format?:'unknown').'.';continue;}
        if(!empty($disk['sourceViaFuse'])){$errors[]='Scheduled replication requires direct pool paths and does not support /mnt/user VM images.';continue;}
        $dataset=(string)($disk['zfsDataset']??'');$mount=rtrim((string)($disk['zfsMountpoint']??''),'/');$source=(string)($disk['resolvedSource']??$disk['source']??'');
        if($dataset===''||$mount===''||empty($disk['dedicatedDataset'])){$errors[]='VM image dataset could not be proven isolated. Use the ZFS Master plugin to place this VM in a dedicated dataset.';continue;}
        $datasetGroups[$dataset]['mountpoint']=$mount;$datasetGroups[$dataset]['files'][]=['source'=>$source,'relativePath'=>ltrim((string)($disk['relativePath']??basename($source)),'/')];
    }
    foreach($datasetGroups as $dataset=>$group){
        $encryption=unmRun(['zfs','get','-H','-o','value','encryption',$dataset],null,15);
        if($encryption['code']!==0||trim($encryption['stdout'])!=='off')$errors[]='Encrypted datasets are not supported for scheduled replication: '.$dataset.'.';
        $files=array_column($group['files'],'source');
        foreach(unmReplicationDatasetIsolationErrors($dataset,(string)$group['mountpoint'],$files) as $error)$errors[]=$error.' Use the ZFS Master plugin to prepare a dedicated dataset.';
        if($destImageRoot===''||$destImageDir===''||empty($destFeatures['dedicatedDatasetImages'])){$errors[]='Destination image directory is not an exact writable ZFS dataset, so it cannot receive an isolated VM dataset.';continue;}
        $diskNumber++;$destination=$destImageRoot.'/unmotion-replica-'.$short.'-disk'.$diskNumber;$destinationMount=$destImageDir.'/.unmotion-replicas/'.$short.'/disk'.$diskNumber;$mapped=[];
        foreach($group['files'] as $file)$mapped[]=$file+['destination'=>$destinationMount.'/'.ltrim((string)$file['relativePath'],'/')];
        $storage[]=['kind'=>'dataset','source'=>$dataset,'destination'=>$destination,'sourceMountpoint'=>$group['mountpoint'],'destinationMountpoint'=>$destinationMount,'files'=>$mapped];
        $sourceDatasets[$dataset]=true;
    }
    if(count($storage)===0)$errors[]='No scheduled-replication-compatible storage was found.';
    $replicationId=empty($vm['uuid'])?'':unmReplicationId((string)$vm['uuid'],(string)($peer['hostId']??''));
    $proposal=['sourceHostId'=>unmHostId(),'destinationHostId'=>(string)($peer['hostId']??''),'replicationId'=>$replicationId,'vmUuid'=>(string)($vm['uuid']??''),'vmName'=>(string)($vm['name']??''),'storage'=>$storage,'reserve'=>false];
    $destination=[];
    if(!$errors){
        $encoded=base64_encode(json_encode($proposal,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$remote=unmRemote($peer,'/usr/local/sbin/unmotion-agent replication-preflight '.escapeshellarg($encoded),45);
        if($remote['code']!==0)$errors[]='Destination replication preflight failed: '.(trim($remote['stderr'])?:'unknown error');
        else{$destination=json_decode($remote['stdout'],true);if(!is_array($destination)||empty($destination['accepted']))$errors[]='Destination returned an invalid replication preflight response.';}
    }
    return ['ready'=>empty($errors),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'id'=>$replicationId,'vm'=>$vm,'peer'=>$peer,'rpoSeconds'=>$rpo,'retentionCount'=>$retention,'maxRetention'=>unmReplicationMaxRetention($rpo),'tpmInitialMode'=>$tpmMode,'storage'=>$storage,'destination'=>$destination];
}

function unmCreateReplication(array $input): array {
    $vmId=(string)($input['vm_id']??$input['vmUuid']??'');$peerId=(string)($input['peer_id']??$input['peerId']??'');
    $pre=unmReplicationPreflight($vmId,$peerId,$input);if(empty($pre['ready']))throw new RuntimeException(implode('; ',$pre['errors']));
    $id=(string)$pre['id'];$policyLock=unmAcquireReplicationPolicyLock($id,true);$dir=unmReplicationPath($id);if(is_file($dir.'/policy.json'))throw new RuntimeException('A replication policy already exists for this VM and destination.');
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create replication policy directory.');
    $enabled=filter_var($input['enabled']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($enabled===null)throw new InvalidArgumentException('Invalid replication enabled state.');
    $now=date(DATE_ATOM);$policy=['id'=>$id,'enabled'=>$enabled,'vmUuid'=>(string)$pre['vm']['uuid'],'vmName'=>(string)$pre['vm']['name'],'peerId'=>$peerId,'peerName'=>(string)($pre['peer']['name']??$pre['peer']['host']??''),'peerHostId'=>(string)($pre['peer']['hostId']??''),'rpoSeconds'=>(int)$pre['rpoSeconds'],'retentionCount'=>(int)$pre['retentionCount'],'tpmInitialMode'=>(string)$pre['tpmInitialMode'],'storage'=>$pre['storage'],'createdAt'=>$now,'updatedAt'=>$now];
    unmAtomicJson($dir.'/policy.json',$policy);unmAtomicJson($dir.'/state.json',['id'=>$id,'state'=>$policy['enabled']?'IDLE':'PAUSED','generation'=>0,'baseSnapshot'=>'','basePointId'=>'','pending'=>null,'lastSuccessAt'=>null,'nextDueAt'=>$policy['enabled']?$now:null,'updatedAt'=>$now]);
    unmWriteCfg($dir.'/request.cfg',['REPLICATION_ID'=>$id,'VM_UUID'=>$policy['vmUuid'],'VM_NAME'=>$policy['vmName'],'PEER_ID'=>$peerId]);
    return unmReplication($id);
}

function unmReplicationStorageFingerprint(array $storage): string {
    usort($storage,static fn(array $a,array $b):int=>strcmp((string)($a['destination']??''),(string)($b['destination']??'')));
    return hash('sha256',json_encode($storage,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function unmUpdateReplication(string $id,array $input): array {
    $policyLock=unmAcquireReplicationPolicyLock($id);$record=unmReplication($id);$existing=(array)$record['policy'];$durable=(array)$record['durable'];$vmId=(string)$existing['vmUuid'];$peerId=(string)$existing['peerId'];
    if(unmReplicationStateIsActive((string)($record['state']??''))||is_array($durable['pending']??null))throw new RuntimeException('Wait for the active or pending replication generation to finish before changing this policy.');
    $merged=['rpoSeconds'=>$input['rpo_seconds']??$input['rpoSeconds']??$existing['rpoSeconds'],'retentionCount'=>$input['retention_count']??$input['retentionCount']??$existing['retentionCount'],'tpmInitialMode'=>$input['tpm_initial_mode']??$input['tpmInitialMode']??$existing['tpmInitialMode']];
    $pre=unmReplicationPreflight($vmId,$peerId,$merged);if(empty($pre['ready']))throw new RuntimeException(implode('; ',$pre['errors']));
    $hasBase=(int)($durable['generation']??0)>0||(string)($durable['baseSnapshot']??'')!==''||(string)($durable['basePointId']??'')!=='';
    if($hasBase&&unmReplicationStorageFingerprint((array)($existing['storage']??[]))!==unmReplicationStorageFingerprint((array)$pre['storage']))throw new RuntimeException('The VM storage mapping changed after the incremental replication chain was established. This policy cannot be updated in place.');
    $existing['rpoSeconds']=(int)$pre['rpoSeconds'];$existing['retentionCount']=(int)$pre['retentionCount'];$existing['tpmInitialMode']=(string)$pre['tpmInitialMode'];$existing['storage']=$pre['storage'];$existing['vmName']=(string)$pre['vm']['name'];$existing['peerName']=(string)($pre['peer']['name']??$pre['peer']['host']??'');$existing['peerHostId']=(string)($pre['peer']['hostId']??'');$existing['updatedAt']=date(DATE_ATOM);
    unmAtomicJson(unmReplicationPath($id).'/policy.json',$existing);return unmReplication($id);
}

function unmSetReplicationEnabled(string $id,bool $enabled): array {
    $policyLock=unmAcquireReplicationPolicyLock($id);$policy=unmReplicationPolicy($id);$policy['enabled']=$enabled;$policy['updatedAt']=date(DATE_ATOM);unmAtomicJson(unmReplicationPath($id).'/policy.json',$policy);
    $state=unmReplicationDurableState($id);$state['state']=$enabled?'IDLE':'PAUSED';$state['nextDueAt']=$enabled?date(DATE_ATOM):null;$state['updatedAt']=date(DATE_ATOM);unmAtomicJson(unmReplicationPath($id).'/state.json',$state);
    return unmReplication($id);
}

function unmLaunchReplicationWorker(string $id,string $reason='manual'): int {
    $policyLock=unmAcquireReplicationPolicyLock($id);$policy=unmReplicationPolicy($id);if(empty($policy['enabled']))throw new RuntimeException('Replication policy is paused.');
    $runtime=unmLoadJson(unmReplicationRuntimePath($id));if(unmReplicationStateIsActive((string)($runtime['state']??''))&&unmReplicationRuntimeWorkerAlive($id,$runtime))throw new RuntimeException('A replication run is already active.');
    $durable=unmReplicationDurableState($id);$durableState=(string)($durable['state']??'');$durableUpdated=strtotime((string)($durable['updatedAt']??''));$staleQueued=$durableState==='QUEUED'&&($durableUpdated===false||$durableUpdated<time()-120)&&!unmReplicationRuntimeWorkerAlive($id,$runtime);
    if(unmReplicationStateIsActive($durableState)&&!$staleQueued)throw new RuntimeException('A replication run is already queued or active.');
    @unlink(unmReplicationRuntimePath($id));
    $dir=unmReplicationPath($id);unmWriteCfg($dir.'/request.cfg',['REPLICATION_ID'=>$id,'VM_UUID'=>$policy['vmUuid'],'VM_NAME'=>$policy['vmName'],'PEER_ID'=>$policy['peerId'],'RUN_REASON'=>$reason]);
    $state=$durable;$state['state']='QUEUED';$state['message']='Replication run queued';$state['runReason']=$reason;$state['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/state.json',$state);
    if(!is_dir('/var/log/unmotion')&&!mkdir('/var/log/unmotion',0755,true)&&!is_dir('/var/log/unmotion'))throw new RuntimeException('Unable to create the runtime replication log directory.');
    unmReleaseReplicationPolicyLock($policyLock);$policyLock=null;
    $launcherLog='/var/log/unmotion/replication-'.$id.'-launcher.log';$cmd='nohup setsid /usr/local/sbin/unmotion-replication-worker '.escapeshellarg($id).' >>'.escapeshellarg($launcherLog).' 2>&1 & echo $!';$result=unmRun($cmd,null,10);$pid=(int)trim($result['stdout']);
    if($result['code']!==0||$pid<=0){
        try{$failureLock=unmAcquireReplicationPolicyLock($id);$failed=unmReplicationDurableState($id);if((string)($failed['state']??'')==='QUEUED'){$failed['state']='FAILED';$failed['message']='Unable to launch replication worker';$failed['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/state.json',$failed);}}catch(Throwable $ignored){}
        throw new RuntimeException('Unable to launch replication worker: '.trim($result['stderr']));
    }
    return $pid;
}

function unmRunReplicationNow(string $id): array {
    $pid=unmLaunchReplicationWorker($id,'manual');$record=unmReplication($id);$record['pid']=$pid;return $record;
}

function unmRemoveReplication(string $id): void {
    $policyLock=unmAcquireReplicationPolicyLock($id);$record=unmReplication($id);$durable=(array)($record['durable']??[]);$state=(string)($record['state']??'');
    if(unmReplicationStateIsActive($state))throw new RuntimeException('Pause and wait for the active replication run before removing this policy.');
    $unsafe=(int)($durable['generation']??0)!==0
        ||is_array($durable['pending']??null)
        ||(string)($durable['baseSnapshot']??'')!==''
        ||(string)($durable['basePointId']??'')!==''
        ||!empty($durable['baseStorage'])
        ||!empty($durable['sourceCleanupPending'])
        ||!empty($durable['destinationPrunePending'])
        ||!empty($durable['receiveResumeToken'])
        ||!empty($durable['resumeToken']);
    if($unsafe)throw new RuntimeException('This policy has replication or cleanup state and cannot be removed without an explicit destination replica-cleanup workflow. No replica data was removed.');
    $dir=unmReplicationPath($id);$real=realpath($dir);$base=realpath(UNM_REPLICATIONS_DIR);
    if($real===false||$base===false||dirname($real)!==$base)throw new RuntimeException('Unsafe replication policy directory.');
    $result=unmRun(['rm','-rf','--',$real],null,30);if($result['code']!==0)throw new RuntimeException('Unable to remove replication policy: '.trim($result['stderr']));
    @unlink(unmReplicationRuntimePath($id));
}

function unmReplicationLog(string $id): string {
    unmReplicationPath($id);$path='/var/log/unmotion/replication-'.$id.'.log';return is_file($path)?(string)file_get_contents($path):'';
}

function unmReplicaManifest(string $sourceHostId,string $replicationId): array {
    return unmLoadJson(unmReplicaPath($sourceHostId,$replicationId).'/manifest.json');
}

function unmIncomingReplicas(bool $includeRecoveryMaterial=false): array {
    unmEnsureDirs();$out=[];
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $path){
        $manifest=unmLoadJson($path);if(!$manifest)continue;
        $manifest['id']=(string)($manifest['replicationId']??$manifest['id']??'');$manifest['pointCount']=count((array)($manifest['points']??[]));$manifest['status']=(string)($manifest['state']??'UNKNOWN');
        if(!$includeRecoveryMaterial){
            foreach($manifest['points']??[] as &$point){
                if(array_key_exists('sourceXml',$point)){$point['sourceXmlAvailable']=(string)$point['sourceXml']!=='';unset($point['sourceXml']);}
                if(array_key_exists('sourceXmlPath',$point)){$point['sourceXmlAvailable']=(string)$point['sourceXmlPath']!=='';unset($point['sourceXmlPath']);}
            }
            unset($point);
        }
        $out[]=$manifest;
    }
    usort($out,static fn(array $a,array $b):int=>strcmp((string)($b['updatedAt']??''),(string)($a['updatedAt']??'')));
    return $out;
}

function unmValidateReplicaRequestIdentity(array $request,bool $requireVmName=true): array {
    $sourceHostId=unmReplicaIdentityPart((string)($request['sourceHostId']??''),'source host identity');
    $destinationHostId=unmReplicaIdentityPart((string)($request['destinationHostId']??''),'destination host identity');
    if($sourceHostId===unmHostId())throw new InvalidArgumentException('Replica source host cannot be this destination host.');
    if($destinationHostId!==unmHostId())throw new InvalidArgumentException('Replica request targets a different destination host.');
    $sourcePeer=unmFindPeerByHostId($sourceHostId);if((string)($sourcePeer['pairingState']??'paired')!=='paired')throw new RuntimeException('Replica requests require a completed reciprocal pairing.');
    $replicationId=(string)($request['replicationId']??$request['id']??'');unmReplicationPath($replicationId);
    $vmUuid=trim((string)($request['vmUuid']??''));if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid replica VM UUID.');
    if(!hash_equals(unmReplicationId($vmUuid,$destinationHostId),$replicationId))throw new InvalidArgumentException('Replica id does not match the VM UUID and destination host identity.');
    $vmName=trim((string)($request['vmName']??''));if(($requireVmName&&$vmName==='')||preg_match('~[/\r\n\t]~',$vmName))throw new InvalidArgumentException('Invalid replica VM name.');
    return compact('sourceHostId','destinationHostId','replicationId','vmUuid','vmName');
}

function unmReplicaVmUuidUndefinedInInventory(string $inventory,string $vmUuid): bool {
    foreach(preg_split('/\R/',trim($inventory))?:[] as $definedUuid){
        if($definedUuid!==''&&strcasecmp(trim($definedUuid),$vmUuid)===0)return false;
    }
    return true;
}

function unmAssertReplicaVmUuidUndefined(string $vmUuid): void {
    $domains=unmRun(['virsh','list','--all','--uuid'],null,30);
    if($domains['code']!==0)throw new RuntimeException('Destination libvirt inventory is unavailable; replica UUID isolation cannot be verified.');
    if(!unmReplicaVmUuidUndefinedInInventory((string)$domains['stdout'],$vmUuid))
        throw new RuntimeException('Destination already defines a VM with the replica UUID. Remove or migrate the stopped conflicting definition before replicating.');
}

function unmNormaliseReplicaStoragePlan(array $items): array {
    if(!$items)throw new InvalidArgumentException('Replica storage plan is empty.');
    $cfg=unmLoadConfig();$zvolRoot=rtrim((string)$cfg['zvol_dataset'],'/');$imageRoot=(string)unmZfsDatasetForPath((string)$cfg['image_dir'],true);$imageDir=rtrim((string)$cfg['image_dir'],'/');$out=[];$destinations=[];$sources=[];
    foreach($items as $item){
        if(!is_array($item))throw new InvalidArgumentException('Invalid replica storage item.');
        $kind=(string)($item['kind']??'');$source=trim((string)($item['source']??''));$destination=trim((string)($item['destination']??''));
        if(!in_array($kind,['zvol','dataset'],true)||!unmZfsObjectNameSafe($source)||!unmZfsObjectNameSafe($destination))throw new InvalidArgumentException('Invalid replica ZFS storage mapping.');
        $sourceKey=$kind.'|'.$source;if(isset($sources[$sourceKey]))throw new InvalidArgumentException('Duplicate replica source mapping: '.$source);$sources[$sourceKey]=true;
        $root=$kind==='zvol'?$zvolRoot:$imageRoot;
        if($root===''||$destination===$root||!str_starts_with($destination,$root.'/')||str_contains(substr($destination,strlen($root)+1),'/'))throw new InvalidArgumentException('Replica destination is outside the configured exact ZFS receive root: '.$destination);
        if(isset($destinations[$destination]))throw new InvalidArgumentException('Duplicate replica destination: '.$destination);$destinations[$destination]=true;
        $sourceMount=(string)($item['sourceMountpoint']??'');$destinationMount=(string)($item['destinationMountpoint']??'');$files=[];
        if($kind==='dataset'){
            if($destinationMount===''||!str_starts_with(rtrim($destinationMount,'/').'/', $imageDir.'/.unmotion-replicas/'))throw new InvalidArgumentException('Invalid replica dataset mountpoint.');
            foreach((array)($item['files']??[]) as $file){
                if(!is_array($file))throw new InvalidArgumentException('Invalid replica dataset file mapping.');
                $relative=ltrim((string)($file['relativePath']??''),'/');$destFile=(string)($file['destination']??'');
                if($relative===''||str_contains($relative,'..')||str_contains($relative,"\0")||$destFile!==rtrim($destinationMount,'/').'/'.$relative)throw new InvalidArgumentException('Invalid replica dataset file path.');
                $files[]=['source'=>(string)($file['source']??''),'relativePath'=>$relative,'destination'=>$destFile];
            }
            if(!$files)throw new InvalidArgumentException('Replica dataset storage must declare at least one VM image file.');
        }
        $out[]=['kind'=>$kind,'source'=>$source,'destination'=>$destination,'sourceMountpoint'=>$sourceMount,'destinationMountpoint'=>$destinationMount,'files'=>$files];
    }
    return $out;
}

function unmReplicaPreflight(array $request): array {
    $identity=unmValidateReplicaRequestIdentity($request);$storage=unmNormaliseReplicaStoragePlan((array)($request['storage']??[]));$dir=unmReplicaPath($identity['sourceHostId'],$identity['replicationId']);$existing=unmLoadJson($dir.'/manifest.json');
    unmAssertReplicaVmUuidUndefined($identity['vmUuid']);
    if($existing&&(($existing['sourceHostId']??'')!==$identity['sourceHostId']||($existing['destinationHostId']??'')!==$identity['destinationHostId']||($existing['vmUuid']??'')!==$identity['vmUuid']))throw new RuntimeException('Existing replica reservation has a different identity.');
    if($existing&&!empty($existing['storage'])&&unmReplicationStorageFingerprint((array)$existing['storage'])!==unmReplicationStorageFingerprint($storage))throw new RuntimeException('Existing replica reservation has a different storage mapping. Explicit cleanup is required before remapping it.');
    $owned=[];$ownedByOther=[];foreach((array)($existing['storage']??[]) as $item)$owned[(string)($item['destination']??'')]=true;
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $manifestPath){
        if($manifestPath===$dir.'/manifest.json')continue;$other=unmLoadJson($manifestPath);
        foreach((array)($other['storage']??[]) as $item)$ownedByOther[(string)($item['destination']??'')]=true;
    }
    foreach($storage as $item){
        $destination=(string)$item['destination'];
        if(isset($ownedByOther[$destination]))throw new RuntimeException('Replica destination is owned by another policy: '.$destination);
        $root=dirname($destination);if(unmRun(['zfs','list','-H','-o','name',$root],null,15)['code']!==0)throw new RuntimeException('Destination ZFS receive root is unavailable: '.$root);
        $destinationExists=unmRun(['zfs','list','-H','-o','name',$destination],null,15)['code']===0;
        if($destinationExists&&!isset($owned[$destination]))throw new RuntimeException('Destination ZFS object already exists without matching replica ownership: '.$destination);
        if($destinationExists&&isset($owned[$destination]))unmVerifyReplicaDestinationInert($item);
    }
    $cfg=unmLoadConfig();$stateDirectory=rtrim((string)$cfg['image_dir'],'/').'/.unmotion-replicas/'.substr(hash('sha256',$identity['sourceHostId']),0,16).'/'.$identity['vmUuid'];
    $reserve=true;if(array_key_exists('reserve',$request)){$reserve=filter_var($request['reserve'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($reserve===null)throw new InvalidArgumentException('Invalid replica reservation mode.');}
    if($reserve){
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create replica reservation directory.');
        if(!is_dir($stateDirectory)&&!mkdir($stateDirectory,0700,true)&&!is_dir($stateDirectory))throw new RuntimeException('Unable to create replica state directory.');
        $now=date(DATE_ATOM);$manifest=array_replace($existing,['replicationId'=>$identity['replicationId'],'sourceHostId'=>$identity['sourceHostId'],'destinationHostId'=>$identity['destinationHostId'],'vmUuid'=>$identity['vmUuid'],'vmName'=>$identity['vmName'],'state'=>(string)($existing['state']??'RESERVED'),'storage'=>$storage,'stateDirectory'=>$stateDirectory,'points'=>(array)($existing['points']??[]),'currentPointId'=>$existing['currentPointId']??null,'createdAt'=>$existing['createdAt']??$now,'updatedAt'=>$now]);
        unmAtomicJson($dir.'/manifest.json',$manifest);$existing=$manifest;
    }
    return ['accepted'=>true,'hostId'=>unmHostId(),'replicationProtocolVersion'=>UNM_REPLICATION_PROTOCOL,'stateDirectory'=>$stateDirectory,'manifest'=>$existing?:null];
}

function unmReplicaSnapshotName(string $value): string {
    $value=trim($value);if(!preg_match('/^unmotion-(?:repl|rpl)-[A-Za-z0-9_.:-]+$/',$value))throw new InvalidArgumentException('Invalid replica snapshot name.');return $value;
}

function unmReplicaExpectedSnapshotName(string $replicationId,int $generation): string {
    unmReplicationPath($replicationId);if($generation<1)throw new InvalidArgumentException('Invalid recovery point generation.');
    return 'unmotion-rpl-'.substr(hash('sha256',$replicationId),0,12).'-g'.$generation;
}

function unmReplicaZfsProperty(string $object,string $property): string {
    $result=unmRun(['zfs','get','-H','-o','value',$property,$object],null,15);
    if($result['code']!==0)throw new RuntimeException('Unable to verify ZFS property '.$property.' on '.$object.': '.trim($result['stderr']));
    return trim($result['stdout']);
}

function unmVerifyReplicaDestinationInert(array $storage): void {
    $destination=(string)($storage['destination']??'');$kind=(string)($storage['kind']??'');
    $type=unmReplicaZfsProperty($destination,'type');
    if($kind==='dataset'&&$type!=='filesystem')throw new RuntimeException('Replica destination is not a filesystem: '.$destination);
    if($kind==='zvol'&&$type!=='volume')throw new RuntimeException('Replica destination is not a volume: '.$destination);
    if(unmReplicaZfsProperty($destination,'readonly')!=='on')throw new RuntimeException('Replica destination is not readonly: '.$destination);
    if($kind==='dataset'){
        if(unmReplicaZfsProperty($destination,'canmount')!=='off'||unmReplicaZfsProperty($destination,'mountpoint')!=='none')throw new RuntimeException('Replica dataset is not inert (canmount=off, mountpoint=none): '.$destination);
    }elseif($kind==='zvol'){
        if(unmReplicaZfsProperty($destination,'volmode')!=='none'||unmReplicaZfsProperty($destination,'snapdev')!=='hidden')throw new RuntimeException('Replica zvol is not inert (volmode=none, snapdev=hidden): '.$destination);
    }else throw new InvalidArgumentException('Invalid replica destination kind.');
}

function unmValidateReplicaCheckpoint(array $checkpoint,string $stateDirectory,int $depth=0): array {
    if($depth>1)throw new InvalidArgumentException('Replica checkpoint fallback nesting is too deep.');
    foreach(['contentSha256','archiveSha256'] as $hashKey){
        $hash=strtolower(trim((string)($checkpoint[$hashKey]??'')));
        if($hash!==''&&!preg_match('/^[a-f0-9]{64}$/',$hash))throw new InvalidArgumentException('Replica checkpoint contains an invalid '.$hashKey.'.');
        if($hash!=='')$checkpoint[$hashKey]=$hash;
    }
    $checkpointId=trim((string)($checkpoint['checkpointId']??''));
    if($checkpointId!==''&&!preg_match('/^state-[a-f0-9]{24}$/',$checkpointId))throw new InvalidArgumentException('Replica checkpoint id is invalid.');
    $archivePath=(string)($checkpoint['archivePath']??'');$transferred=!empty($checkpoint['transferred']);
    if($transferred||$archivePath!==''){
        if(!$transferred||$archivePath===''||str_contains($archivePath,"\0")||str_contains($archivePath,"\n"))throw new InvalidArgumentException('Transferred replica checkpoint archive metadata is incomplete.');
        $stateReal=realpath($stateDirectory);$archiveReal=realpath($archivePath);
        if($stateReal===false||$archiveReal===false||!is_file($archiveReal)||is_link($archivePath)||dirname($archiveReal)!==$stateReal)throw new RuntimeException('Replica checkpoint archive is outside its exact reserved state directory or is not a regular file.');
        $archiveHash=(string)($checkpoint['archiveSha256']??'');
        if($archiveHash===''||!hash_equals($archiveHash,(string)hash_file('sha256',$archiveReal)))throw new RuntimeException('Replica checkpoint archive failed SHA-256 verification.');
        if($checkpointId===''||!hash_equals($checkpointId,'state-'.substr($archiveHash,0,24))||basename($archiveReal)!==$checkpointId.'.tar.gz')throw new RuntimeException('Replica checkpoint id or filename does not match its archive SHA-256.');
        $checkpoint['archivePath']=$archiveReal;$checkpoint['transferred']=true;
    }else{
        $checkpoint['archivePath']='';$checkpoint['transferred']=false;
    }
    $quality=(string)($checkpoint['quality']??'');if(!in_array($quality,['safe','best-effort','unstable','missing','not-required'],true))throw new InvalidArgumentException('Replica checkpoint quality is invalid.');
    $tpmPresent=!empty($checkpoint['tpmPresent']);$nvramPresent=!empty($checkpoint['nvramPresent']);
    if($quality==='safe'&&(!$transferred||$archivePath===''||($tpmPresent&&empty($checkpoint['tpmCaptured']))||($nvramPresent&&empty($checkpoint['nvramCaptured']))))
        throw new InvalidArgumentException('A safe replica checkpoint requires a transferred archive covering every declared TPM and NVRAM device.');
    foreach(['capturedAt','safeObservedAt'] as $timeKey)if(isset($checkpoint[$timeKey])&&((string)$checkpoint[$timeKey]===''||strlen((string)$checkpoint[$timeKey])>64||strtotime((string)$checkpoint[$timeKey])===false))throw new InvalidArgumentException('Replica checkpoint contains an invalid '.$timeKey.'.');
    if(isset($checkpoint['safeFallback'])){
        if(!is_array($checkpoint['safeFallback']))throw new InvalidArgumentException('Replica safe checkpoint fallback is invalid.');
        $checkpoint['safeFallback']=unmValidateReplicaCheckpoint($checkpoint['safeFallback'],$stateDirectory,$depth+1);
        $fallback=$checkpoint['safeFallback'];
        if($quality==='safe')throw new InvalidArgumentException('A safe replica checkpoint must not contain a fallback checkpoint.');
        $compatible=($fallback['quality']??'')==='safe'&&!empty($fallback['transferred'])&&!empty($fallback['archivePath'])&&
            !empty($fallback['tpmPresent'])===$tpmPresent&&!empty($fallback['nvramPresent'])===$nvramPresent&&
            (!$tpmPresent||!empty($fallback['tpmCaptured']))&&(!$nvramPresent||!empty($fallback['nvramCaptured']));
        if(!$compatible)throw new InvalidArgumentException('Replica safe checkpoint fallback does not cover the same TPM and NVRAM devices.');
    }
    $normalised=['checkpointId'=>$checkpointId,'quality'=>$quality,'contentSha256'=>(string)($checkpoint['contentSha256']??''),'archiveSha256'=>(string)($checkpoint['archiveSha256']??''),'archivePath'=>(string)$checkpoint['archivePath'],'capturedAt'=>(string)($checkpoint['capturedAt']??''),'tpmPresent'=>!empty($checkpoint['tpmPresent']),'tpmCaptured'=>!empty($checkpoint['tpmCaptured']),'nvramPresent'=>!empty($checkpoint['nvramPresent']),'nvramCaptured'=>!empty($checkpoint['nvramCaptured']),'transferred'=>!empty($checkpoint['transferred'])];
    if(isset($checkpoint['safeObservedAt']))$normalised['safeObservedAt']=(string)$checkpoint['safeObservedAt'];
    if(isset($checkpoint['safeFallback']))$normalised['safeFallback']=$checkpoint['safeFallback'];
    return $normalised;
}

function unmCanonicalReplicationValue(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('unmCanonicalReplicationValue',$value);
    ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=unmCanonicalReplicationValue($item);return $value;
}

function unmReplicaPointImmutableFingerprint(array $point): string {
    $keys=['id','generation','snapshot','slotEpoch','scheduledAt','createdAt','capturedAt','capturedAtEpoch','consistency','replicationOnly','storage','guestAgent','sourceXmlPath','sourceXmlSha256','hostState','tpm','nvram'];$immutable=[];
    foreach($keys as $key)$immutable[$key]=$point[$key]??null;
    return hash('sha256',json_encode(unmCanonicalReplicationValue($immutable),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function unmStoreReplicaSourceXml(string $sourceXml,string $sourceXmlSha256,string $stateDirectory): string {
    $stateReal=realpath($stateDirectory);if($stateReal===false||!is_dir($stateReal)||is_link($stateDirectory))throw new RuntimeException('Replica state directory is unavailable or unsafe.');
    $target=$stateReal.'/xml-'.$sourceXmlSha256.'.xml';
    if(file_exists($target)||is_link($target)){
        $targetReal=realpath($target);
        if($targetReal===false||dirname($targetReal)!==$stateReal||!is_file($targetReal)||is_link($target)||!hash_equals($sourceXmlSha256,(string)hash_file('sha256',$targetReal)))throw new RuntimeException('Existing replica source XML recovery material failed path or SHA-256 verification.');
        if(!chmod($targetReal,0600))throw new RuntimeException('Unable to secure existing replica source XML recovery material.');
        return $targetReal;
    }
    $temporary=tempnam($stateReal,'.unmotion-xml-');if($temporary===false)throw new RuntimeException('Unable to create a temporary source XML recovery file.');
    try{
        if(file_put_contents($temporary,$sourceXml,LOCK_EX)!==strlen($sourceXml)||!chmod($temporary,0600)||!hash_equals($sourceXmlSha256,(string)hash_file('sha256',$temporary)))throw new RuntimeException('Unable to write verified source XML recovery material.');
        if(!rename($temporary,$target))throw new RuntimeException('Unable to commit source XML recovery material.');
        $temporary='';
        if(!chmod($target,0600)||is_link($target)||!is_file($target)||!hash_equals($sourceXmlSha256,(string)hash_file('sha256',$target)))throw new RuntimeException('Committed source XML recovery material failed verification.');
        return $target;
    }finally{if($temporary!==''&&is_file($temporary))@unlink($temporary);}
}

function unmParseReplicaSnapshotHoldTags(string $output): array {
    $tags=[];foreach(preg_split('/\R/',trim($output))?:[] as $line){
        if(trim($line)==='')continue;$columns=explode("\t",$line,3);if(isset($columns[1])&&$columns[1]!=='')$tags[]=(string)$columns[1];
    }
    return array_values(array_unique($tags));
}

function unmReplicaSnapshotHoldTags(string $snapshot): array {
    $result=unmRun(['zfs','holds','-H',$snapshot],null,15);
    if($result['code']!==0)throw new RuntimeException('Unable to inspect holds for '.$snapshot.': '.trim($result['stderr']));
    return unmParseReplicaSnapshotHoldTags($result['stdout']);
}

function unmReplicaPublish(array $request): array {
    $identity=unmValidateReplicaRequestIdentity($request);$dir=unmReplicaPath($identity['sourceHostId'],$identity['replicationId']);$manifest=unmLoadJson($dir.'/manifest.json');if(!$manifest)throw new RuntimeException('Replica must be reserved before publishing a recovery point.');
    unmAssertReplicaVmUuidUndefined($identity['vmUuid']);
    if(($manifest['sourceHostId']??'')!==$identity['sourceHostId']||($manifest['destinationHostId']??'')!==$identity['destinationHostId']||($manifest['vmUuid']??'')!==$identity['vmUuid'])throw new RuntimeException('Replica reservation identity does not match the publish request.');
    $point=is_array($request['point']??null)?$request['point']:[];$pointId=trim((string)($point['id']??''));if(!preg_match('/^[A-Za-z0-9_.-]{8,100}$/',$pointId))throw new InvalidArgumentException('Invalid recovery point id.');
    $generation=(int)($point['generation']??0);if($generation<1)throw new InvalidArgumentException('Invalid recovery point generation.');
    $sourceXml=(string)($point['sourceXml']??$request['sourceXml']??'');$encodedXml=$point['sourceXmlBase64']??$request['sourceXmlBase64']??null;
    if($encodedXml!==null){$decoded=base64_decode((string)$encodedXml,true);if($decoded===false)throw new InvalidArgumentException('Source XML recovery material is not valid base64.');if($sourceXml!==''&&!hash_equals($sourceXml,$decoded))throw new InvalidArgumentException('Source XML string and base64 recovery material differ.');$sourceXml=$decoded;}
    unset($point['sourceXmlBase64']);
    $sourceXmlSha256=strtolower(trim((string)($point['sourceXmlSha256']??$request['sourceXmlSha256']??'')));
    if($sourceXml===''||strlen($sourceXml)>2097152||!preg_match('/^[a-f0-9]{64}$/',$sourceXmlSha256)||!hash_equals($sourceXmlSha256,hash('sha256',$sourceXml)))throw new InvalidArgumentException('Every recovery point requires source XML recovery material with a valid size and SHA-256.');
    if(!str_contains($sourceXml,'<domain')||strcasecmp(unmXmlValue($sourceXml,'uuid'),$identity['vmUuid'])!==0)throw new InvalidArgumentException('Source XML recovery material does not match the replica VM UUID.');
    $point['sourceXmlSha256']=$sourceXmlSha256;
    $expected=[];foreach((array)$manifest['storage'] as $item)$expected[(string)$item['destination']]=$item;
    $pointSnapshot=unmReplicaSnapshotName((string)($point['snapshot']??''));if(!hash_equals(unmReplicaExpectedSnapshotName($identity['replicationId'],$generation),$pointSnapshot))throw new InvalidArgumentException('Recovery point snapshot name does not match its policy and generation.');$verified=[];$ownHold='unmotion:replication:'.$identity['replicationId'];
    foreach((array)($point['storage']??[]) as $item){
        if(!is_array($item))throw new InvalidArgumentException('Invalid recovery point storage item.');
        $destination=(string)($item['destination']??$item['dataset']??'');if(!isset($expected[$destination]))throw new InvalidArgumentException('Recovery point references an unowned destination: '.$destination);
        $snapshot=unmReplicaSnapshotName((string)($item['snapshot']??''));if($snapshot!==$pointSnapshot)throw new RuntimeException('Recovery point storage snapshots do not form one exact consistency group.');$full=$destination.'@'.$snapshot;
        unmVerifyReplicaDestinationInert($expected[$destination]);
        $guidResult=unmRun(['zfs','get','-H','-o','value','guid',$full],null,15);if($guidResult['code']!==0)throw new RuntimeException('Recovery point snapshot is unavailable: '.$full);
        $guid=trim($guidResult['stdout']);$claimed=trim((string)($item['guid']??''));if(!preg_match('/^\d+$/',$claimed)||!hash_equals($claimed,$guid))throw new RuntimeException('Recovery point snapshot GUID does not match: '.$full);
        if(!in_array($ownHold,unmReplicaSnapshotHoldTags($full),true))throw new RuntimeException('Recovery point snapshot does not have its exact unMotion replication hold: '.$full);
        $verified[$destination]=['kind'=>$expected[$destination]['kind'],'source'=>$expected[$destination]['source'],'destination'=>$destination,'snapshot'=>$snapshot,'guid'=>$guid];
    }
    if(count($verified)!==count($expected))throw new RuntimeException('Recovery point does not include every replica storage object.');
    $stateDirectory=(string)($manifest['stateDirectory']??'');if($stateDirectory===''||realpath($stateDirectory)===false)throw new RuntimeException('Replica state directory is unavailable.');
    foreach(['hostState','tpm','nvram'] as $checkpointKey)if(isset($point[$checkpointKey])){
        if(!is_array($point[$checkpointKey]))throw new InvalidArgumentException('Invalid '.$checkpointKey.' checkpoint metadata.');
        $point[$checkpointKey]=unmValidateReplicaCheckpoint($point[$checkpointKey],$stateDirectory);
    }
    $consistency=(string)($point['consistency']??'');if(!in_array($consistency,['powered-off','filesystem-quiesced','crash-consistent'],true))throw new InvalidArgumentException('Invalid recovery point consistency classification.');
    $capturedAt=(string)($point['capturedAt']??'');$capturedAtEpoch=(int)($point['capturedAtEpoch']??0);$parsedCapture=strtotime($capturedAt);
    if($capturedAt===''||strlen($capturedAt)>64||$parsedCapture===false||$capturedAtEpoch<=0||$capturedAtEpoch!==$parsedCapture)throw new InvalidArgumentException('Recovery point capture timestamp is invalid or inconsistent.');
    $slotEpoch=(int)($point['slotEpoch']??0);if($slotEpoch<=0)throw new InvalidArgumentException('Recovery point UTC slot is invalid.');
    if(!hash_equals('point-'.$slotEpoch.'-g'.$generation,$pointId))throw new InvalidArgumentException('Recovery point id does not match its UTC slot and generation.');
    $scheduledAt=(string)($point['scheduledAt']??'');if($scheduledAt!==''&&(strlen($scheduledAt)>64||strtotime($scheduledAt)===false))throw new InvalidArgumentException('Recovery point scheduled timestamp is invalid.');
    $createdAt=(string)($point['createdAt']??$capturedAt);if($createdAt===''||strlen($createdAt)>64||strtotime($createdAt)===false)throw new InvalidArgumentException('Recovery point created timestamp is invalid.');
    $guestAgent=$point['guestAgent']??[];if(!is_array($guestAgent))throw new InvalidArgumentException('Recovery point guest-agent metadata is invalid.');
    $guestObservedAt=(string)($guestAgent['observedAt']??'');if($guestObservedAt!==''&&(strlen($guestObservedAt)>64||strtotime($guestObservedAt)===false))throw new InvalidArgumentException('Recovery point guest-agent observation timestamp is invalid.');
    $guestNetwork=$guestAgent['network']??[];if(!is_array($guestNetwork)||strlen(json_encode($guestNetwork,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>131072)throw new InvalidArgumentException('Recovery point guest-agent network metadata is invalid or too large.');
    $guestAgent=['verified'=>!empty($guestAgent['verified']),'observedAt'=>$guestObservedAt,'network'=>$guestNetwork];
    $normalisedPoint=['id'=>$pointId,'generation'=>$generation,'snapshot'=>$pointSnapshot,'slotEpoch'=>$slotEpoch,'scheduledAt'=>$scheduledAt,'createdAt'=>$createdAt,'capturedAt'=>$capturedAt,'capturedAtEpoch'=>$capturedAtEpoch,'consistency'=>$consistency,'replicationOnly'=>!empty($point['replicationOnly']),'storage'=>array_values($verified),'guestAgent'=>$guestAgent,'sourceXmlPath'=>unmStoreReplicaSourceXml($sourceXml,$sourceXmlSha256,$stateDirectory),'sourceXmlSha256'=>$sourceXmlSha256,'completedAt'=>date(DATE_ATOM),'state'=>'AVAILABLE'];
    foreach(['hostState','tpm','nvram'] as $checkpointKey)if(isset($point[$checkpointKey]))$normalisedPoint[$checkpointKey]=$point[$checkpointKey];
    $point=$normalisedPoint;
    $points=(array)($manifest['points']??[]);$replaced=false;$maximumGeneration=0;
    foreach($points as $index=>$existing){
        $maximumGeneration=max($maximumGeneration,(int)($existing['generation']??0));
        if(($existing['id']??'')!==$pointId)continue;
        if(unmReplicaPointImmutableFingerprint($existing)!==unmReplicaPointImmutableFingerprint($point))throw new RuntimeException('Recovery point id already exists with different recovery material or consistency metadata.');
        $point=$existing;$replaced=true;break;
    }
    if($replaced)return ['published'=>true,'accepted'=>true,'idempotent'=>true,'point'=>$point,'points'=>$points,'manifest'=>$manifest,'pointCount'=>count($points),'currentPointId'=>$manifest['currentPointId']??null];
    if($generation!==$maximumGeneration+1)throw new RuntimeException('Recovery point generation is not the next destination inventory generation.');
    $points[]=$point;
    usort($points,static fn(array $a,array $b):int=>unmReplicationPointTimestamp($b)<=>unmReplicationPointTimestamp($a));
    unmAssertReplicaVmUuidUndefined($identity['vmUuid']);
    $manifest['points']=$points;$manifest['currentPointId']=$pointId;$manifest['state']='READY';$manifest['lastSuccessAt']=$point['completedAt'];$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);
    return ['published'=>true,'accepted'=>true,'idempotent'=>false,'point'=>$point,'points'=>$points,'manifest'=>$manifest,'pointCount'=>count($points),'currentPointId'=>$pointId];
}

function unmGarbageCollectReplicaCheckpointArchives(array $points,string $stateDirectory): array {
    $stateReal=realpath($stateDirectory);
    if($stateReal===false||!is_dir($stateReal)||is_link($stateDirectory))throw new RuntimeException('Replica state directory is unavailable for exact checkpoint cleanup.');
    $referenced=[];
    $collect=static function(array $checkpoint)use(&$referenced,$stateReal):void{
        $validated=unmValidateReplicaCheckpoint($checkpoint,$stateReal);
        while(true){
            if(!empty($validated['transferred']))$referenced[(string)$validated['archivePath']]=true;
            if(!isset($validated['safeFallback'])||!is_array($validated['safeFallback']))break;
            // The outer validation already validated and normalised the exact
            // fallback chain. Do not revalidate its optional empty timestamps.
            $validated=$validated['safeFallback'];
        }
    };
    foreach($points as $point){
        if(!is_array($point))throw new RuntimeException('Replica inventory contains invalid recovery point metadata; checkpoint cleanup was refused.');
        foreach(['hostState','tpm','nvram'] as $key)if(isset($point[$key])){
            if(!is_array($point[$key]))throw new RuntimeException('Replica inventory contains invalid checkpoint metadata; checkpoint cleanup was refused.');
            $collect($point[$key]);
        }
    }
    try{$entries=new DirectoryIterator($stateReal);}catch(Throwable $e){throw new RuntimeException('Unable to enumerate the exact replica checkpoint directory.',0,$e);}
    $candidates=[];
    foreach($entries as $entry){
        if($entry->isDot())continue;$name=$entry->getFilename();
        if(!preg_match('/^state-([a-f0-9]{24})\.tar\.gz$/',$name,$match))continue;
        $path=$stateReal.DIRECTORY_SEPARATOR.$name;
        if($entry->isLink()||!$entry->isFile())throw new RuntimeException('Unsafe checkpoint archive entry was preserved during cleanup: '.$name);
        $real=realpath($path);if($real===false||dirname($real)!==$stateReal)throw new RuntimeException('Checkpoint archive escaped its exact replica state directory; cleanup was refused.');
        if(isset($referenced[$real]))continue;
        $hash=hash_file('sha256',$real);if($hash===false||!hash_equals($match[1],substr($hash,0,24)))throw new RuntimeException('Unreferenced checkpoint archive failed its ownership hash check and was preserved: '.$name);
        $candidates[$real]=['name'=>$name,'sha256'=>$hash];
    }
    $removed=[];
    foreach($candidates as $real=>$candidate){
        if(is_link($real)||!is_file($real)||dirname((string)realpath($real))!==$stateReal||!hash_equals($candidate['sha256'],(string)hash_file('sha256',$real)))
            throw new RuntimeException('Unreferenced checkpoint archive changed during exact cleanup and was preserved: '.$candidate['name']);
        if(!unlink($real))throw new RuntimeException('Unable to remove exact unreferenced checkpoint archive: '.$candidate['name']);
        $removed[]=$candidate['name'];
    }
    sort($removed,SORT_STRING);return $removed;
}

function unmReplicaPrune(array $request): array {
    $identity=unmValidateReplicaRequestIdentity($request,false);$dir=unmReplicaPath($identity['sourceHostId'],$identity['replicationId']);$manifest=unmLoadJson($dir.'/manifest.json');if(!$manifest)throw new RuntimeException('Replica inventory not found.');
    if(($manifest['vmUuid']??'')!==$identity['vmUuid'])throw new RuntimeException('Replica inventory VM identity does not match.');
    $requested=(array)($request['points']??[]);if(!$requested&&isset($request['pointIds']))foreach((array)$request['pointIds'] as $id)$requested[]=['id'=>$id];
    $points=(array)($manifest['points']??[]);$byId=[];foreach($points as $point)$byId[(string)($point['id']??'')]=$point;$pruned=[];$seen=[];$ownHold='unmotion:replication:'.$identity['replicationId'];
    foreach($requested as $entry){
        if(!is_array($entry))throw new InvalidArgumentException('Invalid prune entry.');$id=(string)($entry['id']??'');
        if($id===''||isset($seen[$id]))throw new InvalidArgumentException('Invalid or duplicate recovery point prune id.');$seen[$id]=true;
        if(!isset($byId[$id]))continue;if($id===(string)($manifest['currentPointId']??''))throw new RuntimeException('The current recovery point cannot be pruned.');
        $point=$byId[$id];$allowMissing=in_array((string)($point['state']??'AVAILABLE'),['DELETING','CLEANUP_FAILED'],true);$allowed=[];foreach((array)($point['storage']??[]) as $item){$full=(string)$item['destination'].'@'.(string)$item['snapshot'];$allowed[$full]=$item;}
        $snapshots=(array)($entry['snapshots']??[]);if(!$snapshots)$snapshots=(array)($point['storage']??[]);
        $requestedSnapshots=[];
        foreach($snapshots as $snapshot){
            if(!is_array($snapshot))throw new InvalidArgumentException('Invalid prune snapshot.');$dataset=(string)($snapshot['dataset']??$snapshot['destination']??'');$snap=unmReplicaSnapshotName((string)($snapshot['snapshot']??''));$full=$dataset.'@'.$snap;
            if(!isset($allowed[$full])||isset($requestedSnapshots[$full]))throw new RuntimeException('Prune request references an unowned or duplicate recovery snapshot: '.$full);$requestedSnapshots[$full]=true;
        }
        if(count($requestedSnapshots)!==count($allowed))throw new RuntimeException('Prune request must name every exact storage snapshot in the recovery point.');
        foreach($manifest['points'] as &$manifestPoint)if((string)($manifestPoint['id']??'')===$id){$manifestPoint['state']='DELETING';$manifestPoint['cleanupError']=null;break;}unset($manifestPoint);
        $manifest['state']='CLEANING';$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);
        try{
            $existingSnapshots=[];
            foreach($allowed as $full=>$stored){
                $exists=unmRun(['zfs','list','-H','-t','snapshot','-o','name',$full],null,15);
                if($exists['code']!==0){if($allowMissing)continue;throw new RuntimeException('Available recovery snapshot is missing before prune: '.$full);}
                $existingSnapshots[$full]=$stored;
                $guidResult=unmRun(['zfs','get','-H','-o','value','guid',$full],null,15);if($guidResult['code']!==0)throw new RuntimeException('Unable to verify recovery snapshot GUID before prune: '.$full);
                $currentGuid=trim($guidResult['stdout']);$manifestGuid=trim((string)($stored['guid']??''));if($manifestGuid===''||!hash_equals($manifestGuid,$currentGuid))throw new RuntimeException('Recovery snapshot GUID changed; refusing prune: '.$full);
                $tags=unmReplicaSnapshotHoldTags($full);if(!$allowMissing&&!in_array($ownHold,$tags,true))throw new RuntimeException('Available recovery snapshot lost its exact unMotion hold and was preserved: '.$full);
                $foreign=array_values(array_diff($tags,[$ownHold]));if($foreign)throw new RuntimeException('Recovery snapshot has a foreign hold and was preserved: '.$full);
            }
            foreach($existingSnapshots as $full=>$stored){
                $tags=unmReplicaSnapshotHoldTags($full);
                if(in_array($ownHold,$tags,true)){
                    $release=unmRun(['zfs','release',$ownHold,$full],null,15);if($release['code']!==0)throw new RuntimeException('Unable to release the exact unMotion hold on '.$full.': '.trim($release['stderr']));
                }
                if(unmReplicaSnapshotHoldTags($full))throw new RuntimeException('Recovery snapshot gained another hold and was preserved: '.$full);
                $currentGuid=unmReplicaZfsProperty($full,'guid');if(!hash_equals((string)$stored['guid'],$currentGuid))throw new RuntimeException('Recovery snapshot GUID changed immediately before prune: '.$full);
                $destroy=unmRun(['zfs','destroy',$full],null,60);if($destroy['code']!==0)throw new RuntimeException('Unable to prune recovery snapshot '.$full.': '.trim($destroy['stderr']));
            }
        }catch(Throwable $e){
            foreach($manifest['points'] as &$manifestPoint)if((string)($manifestPoint['id']??'')===$id){$manifestPoint['state']='CLEANUP_FAILED';$manifestPoint['cleanupError']=$e->getMessage();break;}unset($manifestPoint);
            $manifest['state']='CLEANUP_FAILED';$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);throw $e;
        }
        unset($byId[$id]);$manifest['points']=array_values($byId);usort($manifest['points'],static fn(array $a,array $b):int=>unmReplicationPointTimestamp($b)<=>unmReplicationPointTimestamp($a));$manifest['state']='READY';unset($manifest['checkpointCleanupError']);$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);$pruned[]=$id;
    }
    try{
        unmGarbageCollectReplicaCheckpointArchives((array)($manifest['points']??[]),(string)($manifest['stateDirectory']??''));
        if(isset($manifest['checkpointCleanupError'])){unset($manifest['checkpointCleanupError']);$manifest['state']='READY';$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);}
    }catch(Throwable $e){
        $manifest['state']='CLEANUP_FAILED';$manifest['checkpointCleanupError']=$e->getMessage();$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);throw $e;
    }
    return ['pruned'=>$pruned,'pointCount'=>count((array)($manifest['points']??[])),'currentPointId'=>$manifest['currentPointId']??null,'manifest'=>$manifest];
}

function unmReplicaInventory(array $request): array {
    $identity=unmValidateReplicaRequestIdentity($request,false);$manifest=unmReplicaManifest($identity['sourceHostId'],$identity['replicationId']);if(!$manifest)throw new RuntimeException('Replica inventory not found.');
    if(($manifest['vmUuid']??'')!==$identity['vmUuid'])throw new RuntimeException('Replica inventory VM identity does not match.');
    return ['manifest'=>$manifest,'points'=>(array)($manifest['points']??[]),'pointCount'=>count((array)($manifest['points']??[])),'currentPointId'=>$manifest['currentPointId']??null];
}


function unmVmReplicationSources(array $vm): array {
    $sources=[];
    foreach(($vm['disks']??[]) as $disk){
        $type=(string)($disk['type']??'');
        $class=(string)($disk['transferClass']??'');
        if($type==='zvol') $source=substr((string)($disk['source']??''),strlen('/dev/zvol/'));
        elseif($class==='zfs-dataset-image') $source=(string)($disk['zfsDataset']??'');
        elseif($type==='file') $source=(string)($disk['source']??'');
        else $source='';
        if($source!=='')$sources[$source]=true;
    }
    $out=array_keys($sources);sort($out,SORT_STRING);return $out;
}

function unmSeedPath(string $id): string {
    if(!preg_match('/^[A-Za-z0-9_.-]{8,80}$/',$id))throw new InvalidArgumentException('Invalid prepared-copy id.');
    return UNM_SEEDS_DIR.'/'.$id;
}

function unmSeedId(string $vmUuid,string $peerId): string {
    return 'seed-'.substr(hash('sha256',strtolower($vmUuid).'|'.$peerId),0,24);
}

function unmSeed(string $id): array {
    $dir=unmSeedPath($id);$seed=unmLoadJson($dir.'/seed.json');if(!$seed)throw new RuntimeException('Prepared copy not found.');return $seed;
}

function unmSeeds(): array {
    unmEnsureDirs();$out=[];
    foreach(glob(UNM_SEEDS_DIR.'/*/seed.json')?:[] as $file){
        $seed=unmLoadJson($file);if(!$seed)continue;$changed=0;$unknown=false;
        if(($seed['state']??'')==='READY')foreach($seed['storage']??[] as $item){
            $kind=(string)($item['kind']??'');$src=(string)($item['source']??'');$snap=(string)($item['snapshot']??'');
            if(in_array($kind,['zvol','dataset'],true)&&$src!==''&&$snap!==''){
                $r=unmRun(['zfs','get','-pH','-o','value','written@'.$snap,$src],null,8);
                if($r['code']===0)$changed+=(int)trim($r['stdout']);else $unknown=true;
            }elseif($kind==='image')$unknown=true;
        }
        $seed['estimatedChangedBytes']=$changed;$seed['estimateIncomplete']=$unknown;$out[]=$seed;
    }
    usort($out,static fn(array $a,array $b):int=>strcmp((string)($b['updatedAt']??''),(string)($a['updatedAt']??'')));
    return $out;
}

function unmLaunchSeedWorker(string $id): int {
    $dir=unmSeedPath($id);$cmd='nohup setsid /usr/local/sbin/unmotion-seed-worker '.escapeshellarg($id).' >>'.escapeshellarg($dir.'/launcher.log').' 2>&1 & echo $!';
    $r=unmRun($cmd,null,10);$pid=(int)trim($r['stdout']);if($r['code']!==0||$pid<=0)throw new RuntimeException('Unable to launch Warm Move worker: '.trim($r['stderr']));return $pid;
}

function unmStartSeed(string $vmIdentifier,string $peerId,string $action='prepare'): array {
    if(!in_array($action,['prepare','update'],true))throw new InvalidArgumentException('Invalid Warm Move operation.');
    $vm=unmParseVm($vmIdentifier);if(empty($vm['uuid']))throw new RuntimeException('VM UUID is unavailable.');
    if(!empty($vm['pci']))throw new RuntimeException('PCIe passthrough is unsupported for Warm Move.');
    if(empty($vm['warmMoveEligible']))throw new RuntimeException('The VM storage layout is not eligible for Warm Move.');
    $id=unmSeedId((string)$vm['uuid'],$peerId);
    if($action==='prepare') {
        $pre=unmPreflight($vmIdentifier,$peerId,['migration_mode'=>'warm-prepare','iso_action'=>'remove','source_cleanup_action'=>'unregister']);
        if(empty($pre['ready'])) throw new RuntimeException(implode('; ',$pre['errors']??['Warm Move preparation preflight failed.']));
    } else {
        $pre=unmPreflight($vmIdentifier,$peerId,['migration_mode'=>'warm-cutover','warm_seed_id'=>$id,'iso_action'=>'remove','source_cleanup_action'=>'unregister']);
        if(empty($pre['ready'])) throw new RuntimeException(implode('; ',$pre['errors']??['Prepared-copy update preflight failed.']));
    }
    $peer=unmTestPeer($peerId);$dir=unmSeedPath($id);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Unable to create prepared-copy directory.');
    if($action==='prepare'&&is_file($dir.'/seed.json'))throw new RuntimeException('A prepared-copy record already exists for this VM and peer. Update or remove it first.');
    if($action==='update'&&!is_file($dir.'/seed.json'))throw new RuntimeException('Prepare this VM before requesting an update.');
    unmWriteCfg($dir.'/request.cfg',['SEED_ID'=>$id,'VM_UUID'=>$vm['uuid'],'VM_NAME'=>$vm['name'],'PEER_ID'=>$peerId,'ACTION'=>$action]);
    $existing=unmLoadJson($dir.'/seed.json');$seed=array_replace($existing,['id'=>$id,'vmUuid'=>$vm['uuid'],'vmName'=>$vm['name'],'peerId'=>$peerId,'peerName'=>$peer['name']??$peer['host'],'state'=>'STARTING','progress'=>0,'message'=>$action==='prepare'?'Preparing copy':'Updating prepared copy','createdAt'=>$existing['createdAt']??date(DATE_ATOM),'updatedAt'=>date(DATE_ATOM)]);
    unmAtomicJson($dir.'/seed.json',$seed);file_put_contents($dir.'/seed.log','',FILE_APPEND);chmod($dir.'/seed.log',0600);$seed['pid']=unmLaunchSeedWorker($id);unmAtomicJson($dir.'/seed.json',$seed);return $seed;
}

function unmResumeSeed(string $id): array {
    $seed=unmSeed($id);
    if(($seed['state']??'')!=='INTERRUPTED') throw new RuntimeException('Only an interrupted prepared-copy transfer can be resumed.');
    if(empty($seed['pendingSnapshot']) || empty($seed['pendingGeneration'])) throw new RuntimeException('The interrupted seed has no recorded ZFS resume generation.');
    $dir=unmSeedPath($id);
    unmWriteCfg($dir.'/request.cfg',['SEED_ID'=>$id,'VM_UUID'=>$seed['vmUuid']??'','VM_NAME'=>$seed['vmName']??'','PEER_ID'=>$seed['peerId']??'','ACTION'=>'resume']);
    $seed['state']='STARTING';$seed['message']='Resume requested';$seed['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/seed.json',$seed);
    $seed['pid']=unmLaunchSeedWorker($id);unmAtomicJson($dir.'/seed.json',$seed);return $seed;
}

function unmRemoveSeed(string $id): array {
    $seed=unmSeed($id);$state=(string)($seed['state']??'');
    if(!in_array($state,['READY','FAILED','INTERRUPTED'],true)) throw new RuntimeException('This prepared copy cannot be removed while another seed operation is active.');
    $dir=unmSeedPath($id);unmWriteCfg($dir.'/request.cfg',['SEED_ID'=>$id,'VM_UUID'=>$seed['vmUuid']??'','VM_NAME'=>$seed['vmName']??'','PEER_ID'=>$seed['peerId']??'','ACTION'=>'remove']);
    $seed['state']='REMOVING';$seed['message']='Removing prepared copy';$seed['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/seed.json',$seed);$seed['pid']=unmLaunchSeedWorker($id);return $seed;
}

function unmJobs(): array {
    unmEnsureDirs(); $jobs=[];
    foreach (glob(UNM_JOBS_DIR.'/*/job.json')?:[] as $file) {
        $j=unmLoadJson($file); if ($j) $jobs[]=$j;
    }
    usort($jobs,fn($a,$b)=>strcmp($b['createdAt']??'', $a['createdAt']??''));
    return $jobs;
}

function unmActiveJobStates(): array {
    return ['STARTING','PREFLIGHT','PREPARING_DESTINATION','SHUTTING_DOWN','SNAPSHOTTING','CONVERTING','TRANSFERRING','COPYING_STORAGE','HOST_STATE','DEFINING_DESTINATION','DEFINING_CLONE','GUEST_CUSTOMIZING','CANCELLING'];
}

function unmCleanupCloneJob(array $job,string $dir): void {
    if(($job['jobType']??'')!=='clone')throw new InvalidArgumentException('Not a clone job.');
    $pre=(array)($job['preflight']??[]);$clone=(array)($pre['clone']??[]);$cloneUuid=(string)($clone['uuid']??'');$cloneDir=rtrim((string)($clone['directory']??''),'/');$jobId=(string)($job['id']??'');
    if(!preg_match('/^[a-f0-9-]{36}$/i',$cloneUuid)||!preg_match('/^[A-Za-z0-9_.-]+$/',$jobId))throw new RuntimeException('Clone cleanup identity is invalid.');
    $cloneName=(string)($clone['name']??'');$xmlPath='/etc/libvirt/qemu/'.$cloneName.'.xml';$absentChecks=0;
    for($attempt=0;$attempt<80;$attempt++){
        $info=unmRun(['virsh','dominfo',$cloneUuid],null,10);
        if($info['code']!==0&&!file_exists($xmlPath)){if(++$absentChecks>=4)break;usleep(250000);continue;}
        $absentChecks=0;if($info['code']!==0){usleep(250000);continue;}
        $state=trim(unmRun(['virsh','domstate',$cloneUuid],null,10)['stdout']);
        if(!in_array($state,['shut off','no state',''],true))unmRun(['virsh','destroy',$cloneUuid],null,30);
        else{$undef=unmRun(['virsh','undefine',$cloneUuid,'--nvram'],null,30);if($undef['code']!==0)$undef=unmRun(['virsh','undefine',$cloneUuid],null,30);}
        usleep(250000);
    }
    $info=unmRun(['virsh','dominfo',$cloneUuid],null,10);if($info['code']===0||file_exists($xmlPath))throw new RuntimeException('Unable to unregister the incomplete clone or remove its persistent XML after repeated stop/undefine attempts.');
    $zfs=[];$files=[];$dirs=[];$snapshot='unmotion-clone-'.$jobId;
    foreach((array)($pre['plan']??[]) as $item){
        $kind=(string)($item['kind']??'');$source=(string)($item['source']??'');$destination=(string)($item['destination']??'');
        if(in_array($kind,['zvol','dataset'],true)){
            if($destination===''||str_contains($destination,'@')||str_contains($destination,'..')||!str_starts_with(basename($destination),'unmotion-clone-'))throw new RuntimeException('Unsafe clone ZFS cleanup destination.');
            $zfs[]=$destination;
            if($source!==''&&!str_contains($source,'@')&&!str_contains($source,'..'))foreach([$source.'@'.$snapshot,$destination.'@'.$snapshot] as $snap)if(unmRun(['zfs','list','-H','-t','snapshot',$snap],null,10)['code']===0){$r=unmRun(['zfs','destroy',$snap],null,30);if($r['code']!==0)throw new RuntimeException('Unable to remove clone snapshot '.$snap.': '.trim($r['stderr']));}
        }elseif(in_array($kind,['file','nvram'],true)){
            $safe=$kind==='file'&&$cloneDir!==''&&unmPathWithin($destination,$cloneDir);
            if($kind==='nvram')$safe=(str_starts_with($destination,'/etc/libvirt/qemu/nvram/')||str_starts_with($destination,'/var/lib/libvirt/qemu/nvram/'))&&str_starts_with(basename($destination),$cloneUuid.'_');
            if(!$safe||str_contains($destination,'/../'))throw new RuntimeException('Unsafe clone file cleanup destination.');
            $files[]=$destination;if($kind==='file'){$parent=dirname($destination);while($parent!=='.'&&$parent!=='/'&&unmPathWithin($parent,$cloneDir)){$dirs[$parent]=strlen($parent);if($parent===$cloneDir)break;$next=dirname($parent);if($next===$parent)break;$parent=$next;}}
        }
    }
    foreach($files as $file){$r=unmRun(['rm','-f','--',$file],null,30);if($r['code']!==0)throw new RuntimeException('Unable to remove incomplete clone file: '.$file);}
    foreach(array_reverse($zfs) as $dataset){
        $last=[];for($attempt=0;$attempt<40;$attempt++){if(unmRun(['zfs','list','-H','-o','name',$dataset],null,10)['code']!==0)break;$last=unmRun(['zfs','destroy',$dataset],null,120);if($last['code']===0)break;usleep(250000);}
        if(unmRun(['zfs','list','-H','-o','name',$dataset],null,10)['code']===0)throw new RuntimeException('Unable to remove incomplete clone ZFS object '.$dataset.': '.trim((string)($last['stderr']??'')));
    }
    arsort($dirs,SORT_NUMERIC);foreach(array_keys($dirs) as $directory)unmRun(['rmdir','--',$directory],null,10);
    $cfg=unmLoadConfig();$imageDir=rtrim((string)$cfg['image_dir'],'/');
    if($cloneDir!==''&&$cloneDir!==$imageDir&&unmPathWithin($cloneDir,$imageDir)&&!is_link($cloneDir)&&is_dir($cloneDir)){unmRun(['rmdir','--',$cloneDir],null,10);if(is_dir($cloneDir))throw new RuntimeException('Unable to remove the exact incomplete clone directory: '.$cloneDir);}
}

function unmCancelJob(string $id): array {
    if(!preg_match('/^[A-Za-z0-9_.-]+$/',$id)) throw new InvalidArgumentException('Invalid job id.');
    $dir=UNM_JOBS_DIR.'/'.$id; $path=$dir.'/job.json'; $job=unmLoadJson($path);
    if(!$job) throw new RuntimeException('Job not found.');
    $state=(string)($job['state']??'');
    if(in_array($state,['FAILED','INTERRUPTED'],true)) {
        if(($job['jobType']??'migration')==='clone')unmCleanupCloneJob($job,$dir);
        $job['state']='CANCELLED';
        $job['message']=(($job['jobType']??'migration')==='clone')?'Failed/interrupted clone was cancelled and its exact destinations were cleaned':'Failed/interrupted job was abandoned; partial destination data, if any, was retained';
        $job['updatedAt']=date(DATE_ATOM);
        unmAtomicJson($path,$job);
        return $job;
    }
    if(in_array($state,['STARTING_DESTINATION','OWNERSHIP_HANDOFF','ATTENTION_REQUIRED','COMPLETE','COMPLETE_WITH_WARNINGS','COMPLETE_CLEANED'],true)) {
        throw new RuntimeException('This job has reached destination startup or ownership handoff and cannot be safely cancelled automatically.');
    }
    if(!in_array($state,unmActiveJobStates(),true)) throw new RuntimeException('This job is not currently cancellable.');
    if(file_put_contents($dir.'/cancel.requested',date(DATE_ATOM)."\n",LOCK_EX)===false) throw new RuntimeException('Unable to create cancellation request.');
    chmod($dir.'/cancel.requested',0600);
    $job['state']='CANCELLING'; $job['message']='Cancellation requested'; $job['updatedAt']=date(DATE_ATOM);
    unmAtomicJson($path,$job);
    $pid=(int)($job['pid']??0);
    if($pid>1 && is_dir('/proc/'.$pid)) {
        $pg=trim(unmRun(['ps','-o','pgid=','-p',(string)$pid],null,5)['stdout']);
        if($pg!=='' && (int)$pg===$pid) unmRun('kill -TERM -- -'.(int)$pid.' 2>/dev/null || true',null,5);
        else {
            unmRun('pkill -TERM -P '.(int)$pid.' 2>/dev/null || true; kill -TERM '.(int)$pid.' 2>/dev/null || true',null,5);
        }
    } else {
        $job['state']='CANCELLED'; $job['message']='Cancelled before the worker was running'; $job['updatedAt']=date(DATE_ATOM);
        unmAtomicJson($path,$job);
    }
    return unmLoadJson($path,$job);
}

function unmRemoveJob(string $id): void {
    if(!preg_match('/^[A-Za-z0-9_.-]+$/',$id)) throw new InvalidArgumentException('Invalid job id.');
    $dir=UNM_JOBS_DIR.'/'.$id;
    $job=unmLoadJson($dir.'/job.json');
    if(!$job) throw new RuntimeException('Job not found.');
    $state=(string)($job['state']??'');
    $cleanupAction=(string)(($job['request']['SOURCE_CLEANUP_ACTION']??'unregister'));
    $allowed=in_array($state,['CANCELLED','COMPLETE_CLEANED'],true)||(
        in_array($state,['COMPLETE','COMPLETE_WITH_WARNINGS'],true)&&$cleanupAction!=='delete'
    );
    if(!$allowed) throw new RuntimeException('Only completed retained-source jobs, fully cleaned jobs, or cancelled jobs can be removed from history.');
    $real=realpath($dir);
    $jobsReal=realpath(UNM_JOBS_DIR);
    if($real===false||$jobsReal===false||dirname($real)!==$jobsReal) throw new RuntimeException('Unsafe job directory.');
    $r=unmRun(['rm','-rf','--',$real],null,30);
    if($r['code']!==0) throw new RuntimeException('Unable to remove job history: '.trim($r['stderr']));
}

function unmClearJobHistory(): int {
    $removed=0;
    foreach(unmJobs() as $job) {
        $id=(string)($job['id']??'');
        if($id==='') continue;
        try { unmRemoveJob($id); $removed++; } catch(Throwable $ignored) {}
    }
    return $removed;
}

function unmCleanupAll(): void {
    unmEnsureDirs();
    $pids=[];
    foreach(unmJobs() as $job) {
        $id=(string)($job['id']??'');
        $pid=(int)($job['pid']??0);
        if($pid>1) $pids[]=$pid;
        if($id!=='' && in_array((string)($job['state']??''),unmActiveJobStates(),true)) {
            try { unmCancelJob($id); } catch(Throwable $ignored) {}
        }
    }
    $seedRecords=unmSeeds();
    foreach($seedRecords as $seed) {
        $pid=(int)($seed['pid']??0);
        if($pid>1) $pids[]=$pid;
    }
    foreach(array_values(array_unique($pids)) as $pid) {
        if($pid>1&&is_dir('/proc/'.$pid)) unmRun('kill -TERM -- -'.(int)$pid.' 2>/dev/null || kill -TERM '.(int)$pid.' 2>/dev/null || true',null,5);
    }
    for($attempt=0;$attempt<20;$attempt++) {
        $alive=array_values(array_filter($pids,static fn(int $pid):bool=>$pid>1&&is_dir('/proc/'.$pid)));
        if(!$alive) break;
        usleep(250000);
    }
    foreach(array_values(array_unique($pids)) as $pid) if($pid>1&&is_dir('/proc/'.$pid)) unmRun('kill -KILL -- -'.(int)$pid.' 2>/dev/null || kill -KILL '.(int)$pid.' 2>/dev/null || true',null,5);
    foreach(glob(UNM_BOOT_DIR.'/incoming/*/source-cleanup-request.json')?:[] as $requestPath) {
        $request=unmLoadJson($requestPath); $pid=(int)($request['pid']??0);
        if($pid>1&&is_dir('/proc/'.$pid)) unmRun('kill -TERM -- -'.(int)$pid.' 2>/dev/null || kill -TERM '.(int)$pid.' 2>/dev/null || true',null,5);
    }
    foreach(unmPeers() as $peer) {
        $id=(string)($peer['id']??'');
        if($id==='') continue;
        foreach(unmJobs() as $job) {
            if(($job['peerId']??'')!==$id) continue;
            $jobId=(string)($job['id']??'');
            if($jobId!=='') try { unmRemote($peer,'rm -rf -- '.escapeshellarg('/boot/config/plugins/unmotion/incoming/'.$jobId),15); } catch(Throwable $ignored) {}
        }
        foreach($seedRecords as $seed) {
            if(($seed['peerId']??'')!==$id) continue;
            $seedId=(string)($seed['id']??'');
            foreach(($seed['storage']??[]) as $item) {
                $kind=(string)($item['kind']??'');
                $src=(string)($item['source']??'');
                $dst=(string)($item['destination']??'');
                $snap=(string)($item['snapshot']??'');
                if(!in_array($kind,['zvol','dataset'],true)||$snap==='') continue;
                if($src!=='') {
                    unmRun(['zfs','release','unmotion:'.$seedId,$src.'@'.$snap],null,10);
                }
                if($dst!=='') {
                    try { unmRemote($peer,'zfs release '.escapeshellarg('unmotion:'.$seedId).' '.escapeshellarg($dst.'@'.$snap).' 2>/dev/null || true',15); } catch(Throwable $ignored) {}
                }
            }
            if($seedId!=='') try { unmRemote($peer,'rm -f -- '.escapeshellarg('/boot/config/plugins/unmotion/incoming/seeds/'.$seedId.'.json'),15); } catch(Throwable $ignored) {}
        }
        try { unmRemovePeer($id); } catch(Throwable $ignored) { try { unmForgetPeerLocal($id); } catch(Throwable $ignored2) {} }
    }
    unmRun(['rm','-rf','--',UNM_PEERS_DIR,UNM_JOBS_DIR,UNM_OWNERSHIP_DIR,UNM_SEEDS_DIR,UNM_BOOT_DIR.'/incoming'],null,30);
    foreach([UNM_CONFIG_JSON,UNM_CONFIG_CFG,UNM_HOST_ID_FILE] as $path) @unlink($path);
}

function unmFindPeerByHostId(string $hostId): array {
    foreach (unmPeers() as $peer) {
        if (($peer['hostId'] ?? '') === $hostId) return $peer;
    }
    throw new RuntimeException('No paired peer matches host ID ' . $hostId . '.');
}

function unmPathWithin(string $path, string $base): bool {
    $path = rtrim(trim($path), '/');
    $base = rtrim(trim($base), '/');
    if ($path === '' || $base === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) return false;
    foreach (explode('/', $path) as $part) if ($part === '..') return false;
    return $path === $base || str_starts_with($path . '/', $base . '/');
}

function unmZfsObjectNameSafe(string $name): bool {
    if ($name === '' || $name !== trim($name) || str_starts_with($name, '/') || str_ends_with($name, '/')) return false;
    if (str_contains($name, "\0") || str_contains($name, '@') || preg_match('/[\x00-\x1F\x7F]/', $name)) return false;
    $parts = explode('/', $name);
    if (count($parts) < 2) return false;
    foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..') return false;
    return true;
}

function unmRemoteOwnershipMarker(array $peer, string $uuid): array {
    if (!preg_match('/^[A-Fa-f0-9-]{32,36}$/', $uuid)) return [];
    $path = UNM_OWNERSHIP_DIR . '/' . $uuid . '.json';
    $r = unmRemote($peer, 'cat ' . escapeshellarg($path) . ' 2>/dev/null', 15);
    if ($r['code'] !== 0) return [];
    $marker = json_decode($r['stdout'], true);
    return is_array($marker) ? $marker : [];
}

function unmPrepareDestinationOverwrite(array $request): array {
    unmEnsureDirs();
    $uuid = trim((string)($request['vmUuid'] ?? ''));
    $destinationHostId = trim((string)($request['destinationHostId'] ?? ''));
    $items = (array)($request['storage'] ?? []);
    if (!preg_match('/^[A-Fa-f0-9-]{32,36}$/', $uuid)) throw new InvalidArgumentException('Invalid VM UUID in overwrite request.');
    if ($destinationHostId !== unmHostId()) throw new RuntimeException('Overwrite request was addressed to a different host.');
    $cfg = unmLoadConfig();
    $removed = [];
    $marker = unmLoadJson(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json');
    $ownershipMatches = ($marker['vmUuid'] ?? '') === $uuid;

    $dom = unmRun(['virsh','dominfo',$uuid], null, 15);
    $hadMatchingVm = $dom['code'] === 0;
    if ($items && !$hadMatchingVm && !$ownershipMatches) throw new RuntimeException('No matching retained VM or unMotion ownership marker authorises deletion of the destination storage.');
    if ($hadMatchingVm) {
        $state = trim(unmRun(['virsh','domstate',$uuid], null, 15)['stdout']);
        if (!in_array($state, ['shut off','no state',''], true)) throw new RuntimeException('The conflicting destination VM is not shut off.');
        $xml = unmDomainXml($uuid, true);
        $nvram = unmXmlValue($xml, 'nvram');
        $staleVm = unmParseVm($uuid);
        foreach ((array)($staleVm['disks'] ?? []) as $disk) {
            $source = (string)($disk['source'] ?? '');
            $kind = (string)($disk['type'] ?? '');
            if ($kind === 'zvol' && str_starts_with($source, '/dev/zvol/')) $items[] = ['kind'=>'zvol','path'=>substr($source, strlen('/dev/zvol/')),'authorisedByVm'=>true];
            elseif ($kind === 'file' && str_starts_with($source, '/mnt/')) {
                if (($disk['transferClass']??'')==='zfs-dataset-image' && !empty($disk['zfsDataset'])) $items[]=['kind'=>'dataset','path'=>$disk['zfsDataset'],'authorisedByVm'=>true];
                else $items[] = ['kind'=>'image','path'=>$source,'authorisedByVm'=>true];
            }
        }
        unmRun(['virsh','autostart','--disable',$uuid], null, 15);
        $undef = unmRun(['virsh','undefine',$uuid,'--keep-nvram','--keep-tpm'], null, 30);
        if ($undef['code'] !== 0) throw new RuntimeException('Unable to undefine the stale destination VM: ' . trim($undef['stderr']));
        $removed[] = 'VM definition';
        if ($nvram !== '' && unmPathWithin($nvram, '/etc/libvirt/qemu/nvram') && str_contains(basename($nvram), $uuid)) {
            @unlink($nvram); $removed[] = $nvram;
        }
        foreach ([
            '/etc/libvirt/qemu/swtpm/tpm-states/' . $uuid,
            '/var/lib/libvirt/swtpm/' . $uuid,
            '/var/lib/libvirt/qemu/swtpm/' . $uuid,
        ] as $tpm) {
            if (is_dir($tpm)) { unmRun(['rm','-rf','--',$tpm], null, 30); $removed[] = $tpm; }
        }
    }

    $uniqueItems=[];
    foreach($items as $item) if(is_array($item)) $uniqueItems[(string)($item['kind']??'')."\0".(string)($item['path']??'')]=$item;
    foreach (array_values($uniqueItems) as $item) {
        if (!is_array($item)) continue;
        $kind = (string)($item['kind'] ?? '');
        $path = trim((string)($item['path'] ?? ''));
        $authorisedByVm = !empty($item['authorisedByVm']);
        if ($kind === 'zvol') {
            $prefix = rtrim((string)$cfg['zvol_dataset'], '/');
            if (!unmZfsObjectNameSafe($path) || (!$authorisedByVm && ($prefix === '' || $path === $prefix || !str_starts_with($path . '/', $prefix . '/')))) {
                throw new RuntimeException('Refusing to destroy an unauthorised destination zvol: ' . $path);
            }
            $exists = unmRun(['zfs','list','-H','-o','name','-t','volume',$path], null, 15);
            if ($exists['code'] === 0) {
                $destroy = unmRun(['zfs','destroy','-r',$path], null, 120);
                if ($destroy['code'] !== 0) throw new RuntimeException('Unable to destroy stale destination zvol ' . $path . ': ' . trim($destroy['stderr']));
                $removed[] = $path;
            }
        } elseif ($kind === 'dataset') {
            $prefix=(string)unmZfsDatasetForPath((string)$cfg['image_dir'],true);
            if(!unmZfsObjectNameSafe($path)||(!$authorisedByVm&&($prefix===''||$path===$prefix||!str_starts_with($path.'/',$prefix.'/'))))throw new RuntimeException('Refusing to destroy an unauthorised destination dataset: '.$path);
            if(unmRun(['zfs','list','-H','-o','name','-t','filesystem',$path],null,15)['code']===0){$destroy=unmRun(['zfs','destroy','-r',$path],null,180);if($destroy['code']!==0)throw new RuntimeException('Unable to destroy stale destination dataset '.$path.': '.trim($destroy['stderr']));$removed[]=$path;}
        } elseif ($kind === 'image') {
            if (!$authorisedByVm && !unmPathWithin($path, (string)$cfg['image_dir'])) throw new RuntimeException('Refusing to delete an image outside the configured destination directory: ' . $path);
            if ($authorisedByVm && (!str_starts_with($path, '/mnt/') || str_contains($path, '/../'))) throw new RuntimeException('Refusing an unsafe image path from the retained VM definition: ' . $path);
            if (is_link($path)) throw new RuntimeException('Refusing to delete a symlinked destination image: ' . $path);
            if (file_exists($path)) {
                if (!is_file($path)) throw new RuntimeException('Destination image path is not a regular file: ' . $path);
                if (!unlink($path)) throw new RuntimeException('Unable to delete stale destination image: ' . $path);
                $removed[] = $path;
                @rmdir(dirname($path));
            }
        }
    }
    @unlink(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json');
    return ['success'=>true,'removed'=>$removed];
}

function unmScheduleSourceCleanup(array $request): array {
    unmEnsureDirs();
    $jobId = trim((string)($request['jobId'] ?? ''));
    $uuid = trim((string)($request['vmUuid'] ?? ''));
    $sourceHostId = trim((string)($request['sourceHostId'] ?? ''));
    $destinationHostId = trim((string)($request['destinationHostId'] ?? ''));
    $soakSeconds = max(60, min(3600, (int)($request['soakSeconds'] ?? 300)));
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $jobId)) throw new InvalidArgumentException('Invalid cleanup job ID.');
    if (!preg_match('/^[A-Fa-f0-9-]{32,36}$/', $uuid)) throw new InvalidArgumentException('Invalid cleanup VM UUID.');
    if ($destinationHostId !== unmHostId()) throw new RuntimeException('Cleanup request was addressed to a different destination host.');
    $peer = unmFindPeerByHostId($sourceHostId);
    $dir = UNM_BOOT_DIR . '/incoming/' . $jobId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Unable to create cleanup state directory.');
    $payload = [
        'jobId'=>$jobId,'vmUuid'=>$uuid,'sourceHostId'=>$sourceHostId,'destinationHostId'=>$destinationHostId,
        'sourcePeerId'=>$peer['id'],'soakSeconds'=>$soakSeconds,'state'=>'scheduled','scheduledAt'=>date(DATE_ATOM),
    ];
    unmAtomicJson($dir . '/source-cleanup-request.json', $payload);
    $cmd = 'nohup setsid /usr/local/sbin/unmotion-soak-cleanup ' . escapeshellarg($jobId) . ' >>' . escapeshellarg($dir . '/source-cleanup-launcher.log') . ' 2>&1 & echo $!';
    $r = unmRun($cmd, null, 10);
    $pid = (int)trim($r['stdout']);
    if ($r['code'] !== 0 || $pid <= 0) throw new RuntimeException('Unable to launch delayed source cleanup: ' . trim($r['stderr']));
    $payload['pid'] = $pid; $payload['state'] = 'watching';
    unmAtomicJson($dir . '/source-cleanup-request.json', $payload);
    return ['success'=>true,'pid'=>$pid,'soakSeconds'=>$soakSeconds];
}

function unmFinalizeSourceCleanup(string $jobId, string $destinationHostId): array {
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $jobId)) throw new InvalidArgumentException('Invalid cleanup job ID.');
    $jobDir = UNM_JOBS_DIR . '/' . $jobId;
    $manifestPath = $jobDir . '/source-cleanup.json';
    $manifest = unmLoadJson($manifestPath);
    if (!$manifest) throw new RuntimeException('Source cleanup manifest was not found.');
    $uuid = trim((string)($manifest['vmUuid'] ?? ''));
    if (!preg_match('/^[A-Fa-f0-9-]{32,36}$/', $uuid)) throw new RuntimeException('Source cleanup manifest has an invalid VM UUID.');
    if (($manifest['policy'] ?? '') !== 'delete') throw new RuntimeException('This migration was not authorised to delete its source copy.');
    if (($manifest['destinationHostId'] ?? '') !== $destinationHostId) throw new RuntimeException('Cleanup destination identity does not match the migration manifest.');
    $marker = unmLoadJson(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json');
    if (($marker['ownerHostId'] ?? '') !== $destinationHostId || !in_array((string)($marker['state'] ?? ''), ['pending_cleanup','migrated_out'], true)) {
        throw new RuntimeException('Ownership marker does not authorise source deletion.');
    }

    $removed = [];
    $nvram = trim((string)($manifest['nvramPath'] ?? ''));
    $tpm = trim((string)($manifest['tpmPath'] ?? ''));
    if (unmRun(['virsh','dominfo',$uuid], null, 15)['code'] === 0) {
        $state = trim(unmRun(['virsh','domstate',$uuid], null, 15)['stdout']);
        if (!in_array($state, ['shut off','no state',''], true)) throw new RuntimeException('Source VM is no longer shut off; refusing cleanup.');
        unmRun(['virsh','autostart','--disable',$uuid], null, 15);
        $undefArgs=['virsh','undefine',$uuid];
        if($nvram!=='')$undefArgs[]='--keep-nvram';
        if($tpm!=='')$undefArgs[]='--keep-tpm';
        $undef = unmRun($undefArgs, null, 30);
        if ($undef['code'] !== 0) throw new RuntimeException('Unable to undefine source VM: ' . trim($undef['stderr']));
        $removed[] = 'VM definition';
    }
    foreach ((array)($manifest['storage'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $kind = (string)($item['kind'] ?? '');
        $path = trim((string)($item['path'] ?? ''));
        if ($kind === 'zvol') {
            if ($path === '' || str_contains($path, '@') || str_contains($path, '..')) throw new RuntimeException('Unsafe zvol in cleanup manifest.');
            if (unmRun(['zfs','list','-H','-o','name','-t','volume',$path], null, 15)['code'] === 0) {
                $r = unmRun(['zfs','destroy','-r',$path], null, 180);
                if ($r['code'] !== 0) throw new RuntimeException('Unable to destroy source zvol ' . $path . ': ' . trim($r['stderr']));
                $removed[] = $path;
            }
        } elseif ($kind === 'dataset') {
            if ($path === '' || str_contains($path,'@') || str_contains($path,'..')) throw new RuntimeException('Unsafe dataset in cleanup manifest.');
            if (unmRun(['zfs','list','-H','-o','name','-t','filesystem',$path],null,15)['code']===0) { $r=unmRun(['zfs','destroy','-r',$path],null,180); if($r['code']!==0)throw new RuntimeException('Unable to destroy source dataset '.$path.': '.trim($r['stderr'])); $removed[]=$path; }
        } elseif ($kind === 'image') {
            if (!str_starts_with($path, '/mnt/') || str_contains($path, '/../') || is_link($path)) throw new RuntimeException('Unsafe image path in cleanup manifest: ' . $path);
            if (file_exists($path)) {
                if (!is_file($path) || !unlink($path)) throw new RuntimeException('Unable to remove source image: ' . $path);
                $removed[] = $path; @rmdir(dirname($path));
            }
        }
    }
    if ($nvram !== '' && unmPathWithin($nvram, '/etc/libvirt/qemu/nvram') && str_contains(basename($nvram), $uuid) && file_exists($nvram)) {
        @unlink($nvram); $removed[] = $nvram;
    }
    $tpmAllowed = $tpm !== '' && str_contains($tpm, $uuid) && (
        unmPathWithin($tpm, '/etc/libvirt/qemu/swtpm') || unmPathWithin($tpm, '/var/lib/libvirt/swtpm') || unmPathWithin($tpm, '/var/lib/libvirt/qemu/swtpm')
    );
    if ($tpmAllowed && is_dir($tpm)) { unmRun(['rm','-rf','--',$tpm], null, 60); $removed[] = $tpm; }

    $marker['state'] = 'source_deleted'; $marker['sourceDeletedAt'] = date(DATE_ATOM); $marker['updatedAt'] = date(DATE_ATOM);
    unmAtomicJson(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json', $marker);
    $job = unmLoadJson($jobDir . '/job.json');
    if ($job) {
        $job['state'] = 'COMPLETE_CLEANED'; $job['progress'] = 100;
        $job['message'] = 'Destination remained running for the validation period; source VM and disks were removed';
        $job['sourceCleanup'] = ['state'=>'complete','completedAt'=>date(DATE_ATOM),'removed'=>$removed];
        $job['updatedAt'] = date(DATE_ATOM); unmAtomicJson($jobDir . '/job.json', $job);
    }
    file_put_contents($jobDir . '/migration.log', date('Y-m-d H:i:s') . " Source cleanup authorised by destination after validation; removed: " . implode(', ', $removed) . "\n", FILE_APPEND | LOCK_EX);
    return ['success'=>true,'removed'=>$removed];
}


function unmRemoteFsCapacity(array $peer,string $path): array {
    if($path==='')return ['device'=>'','available'=>0];
    $r=unmRemote($peer,'stat -f -c '.escapeshellarg('%d %a %S').' '.escapeshellarg($path),15);
    if($r['code']!==0||!preg_match('/^(\S+)\s+(\d+)\s+(\d+)$/',trim($r['stdout']),$m))return ['device'=>'','available'=>0];
    return ['device'=>$m[1],'available'=>(int)$m[2]*(int)$m[3]];
}
function unmLocalFsCapacity(string $path): array {
    if($path===''||!is_dir($path))return ['device'=>'','available'=>0];
    $r=unmRun(['stat','-f','-c','%d %a %S',$path],null,15);
    if($r['code']!==0||!preg_match('/^(\S+)\s+(\d+)\s+(\d+)$/',trim($r['stdout']),$m))return ['device'=>'','available'=>0];
    return ['device'=>$m[1],'available'=>(int)$m[2]*(int)$m[3]];
}

function unmPreflight(string $vmIdentifier,string $peerId,array $options=[]): array {
    if($vmIdentifier==='') throw new InvalidArgumentException('Select a VM.');
    $isoAction=(string)($options['iso_action']??'copy');
    if(!in_array($isoAction,['copy','remove','retain'],true)) throw new InvalidArgumentException('Invalid ISO action.');
    $cfg=unmLoadConfig();
    $sourceCleanupAction=(string)($options['source_cleanup_action']??$cfg['source_cleanup_default']??'unregister');
    $conflictAction=(string)($options['destination_conflict_action']??'cancel');
    $migrationMode=(string)($options['migration_mode']??'cold');
    $warmSeedId=trim((string)($options['warm_seed_id']??''));
    $warmSeed=[];$warmSeedDestinations=[];$warmBaseSnapshot='';$seedCompatibilityErrors=[];
    if(!in_array($sourceCleanupAction,['unregister','retain','delete'],true)) throw new InvalidArgumentException('Invalid source cleanup action.');
    if(!in_array($conflictAction,['cancel','overwrite'],true)) throw new InvalidArgumentException('Invalid destination conflict action.');
    if(!in_array($migrationMode,['cold','warm-prepare','warm-cutover'],true)) throw new InvalidArgumentException('Invalid migration mode.');

    $local=unmParseVm($vmIdentifier); $vm=$local['name']; $uuid=$local['uuid'];
    if($migrationMode==='warm-cutover'){
        if($warmSeedId==='')throw new InvalidArgumentException('Select a prepared copy for Warm Move cutover.');
        $warmSeed=unmSeed($warmSeedId);
        if(($warmSeed['vmUuid']??'')!==$uuid||($warmSeed['peerId']??'')!==$peerId||($warmSeed['state']??'')!=='READY')throw new RuntimeException('The prepared copy is missing, stale, or belongs to a different VM/destination.');
        $warmBaseSnapshot=(string)($warmSeed['baseSnapshot']??'');
        $expectedSources=[];
        foreach($warmSeed['storage']??[] as $item){
            $d=(string)($item['destination']??'');if($d!=='')$warmSeedDestinations[$d]=true;
            $src=(string)($item['source']??'');if($src!=='')$expectedSources[$src]=true;
            $kind=(string)($item['kind']??'');$snap=(string)($item['snapshot']??$warmBaseSnapshot);
            if(in_array($kind,['zvol','dataset'],true)&&$src!==''&&$snap!==''&&unmRun(['zfs','list','-H','-t','snapshot',$src.'@'.$snap],null,10)['code']!==0)$seedCompatibilityErrors[]='Required source base snapshot is missing: '.$src.'@'.$snap;
        }
        $expected=array_keys($expectedSources);sort($expected,SORT_STRING);$current=unmVmReplicationSources($local);
        if($expected!==$current)$seedCompatibilityErrors[]='The VM disk layout changed after the prepared copy was created. Remove it and prepare a new full copy.';
    }
    $peer=unmTestPeer($peerId); $caps=$peer['lastCapabilities'];
    $peerHealth=unmPeerHealth($peerId);
    if($vm===''||preg_match('~[/\r\n\t]~',$vm)) throw new RuntimeException('VM name contains characters unsafe for a destination directory. Spaces are supported.');
    $errors=$seedCompatibilityErrors; $warnings=[]; $plan=[]; $required=['zvol'=>0,'dataset'=>0,'image'=>0,'iso'=>0,'sourceStaging'=>0];
    $destinations=[]; $conflicts=[];
    if($migrationMode==='warm-cutover'&&!$seedCompatibilityErrors){
        foreach($warmSeed['storage']??[] as $item){
            $kind=(string)($item['kind']??'');$src=(string)($item['source']??'');$dst=(string)($item['destination']??'');$snap=(string)($item['snapshot']??$warmBaseSnapshot);
            if(in_array($kind,['zvol','dataset'],true)&&$src!==''&&$dst!==''&&$snap!==''){
                $sg=unmRun(['zfs','get','-H','-o','value','guid',$src.'@'.$snap],null,10);
                $dg=unmRemote($peer,'zfs get -H -o value guid '.escapeshellarg($dst.'@'.$snap),15);
                if($sg['code']!==0||$dg['code']!==0||trim($sg['stdout'])!==trim($dg['stdout']))$errors[]='The prepared ZFS base is missing or no longer matches for '.$src.'. A new full preparation is required.';
                $ro=unmRemote($peer,'zfs get -H -o value readonly '.escapeshellarg($dst),15);
                if($ro['code']!==0||trim($ro['stdout'])!=='on')$errors[]='Prepared destination dataset is not read-only as expected: '.$dst;
            }elseif($kind==='image'&&$dst!==''){
                if(unmRemote($peer,'test -f '.escapeshellarg($dst),15)['code']!==0)$errors[]='Prepared destination image is missing: '.$dst;
                else{
                    $mode=unmRemote($peer,'stat -c '.escapeshellarg('%A').' '.escapeshellarg($dst),15);
                    if($mode['code']!==0||str_contains(trim($mode['stdout']),'w'))$errors[]='Prepared destination image is unexpectedly writable: '.$dst;
                }
            }
        }
    }
    if($migrationMode==='cold'&&strtolower(trim((string)($local['state']??'')))!=='shut off') $errors[]='Power off the VM before a cold migration.';
    if(!empty($local['pci'])) $errors[]='PCIe passthrough is present and is unsupported in 0.3.';
    foreach(($local['disks']??[]) as $disk)if(($disk['transferClass']??'')==='unsupported-backing-chain'){
        $detail=(string)($disk['backingInfo']['error']??'');
        $errors[]=$detail!==''?$detail:'External qcow2 backing chain; flatten before migration.';
    }
    if(!empty($local['tpm'])&&empty($caps['features']['tpm'])) $errors[]='The destination does not report software TPM support.';
    if(($caps['protocolVersion']??null)!==UNM_PROTOCOL) $errors[]='Destination protocol version is incompatible.';
    foreach(['virsh','tar','ssh'] as $tool) if(empty($caps['tools'][$tool])) $errors[]="Destination command is unavailable: $tool";
    foreach(($peerHealth['issues']??[]) as $issue){
        $message='Destination health: '.(string)($issue['message']??$issue['code']??'Unknown issue');
        if(!empty($issue['blocksMigration']))$errors[]=$message;else $warnings[]=$message;
    }

    $destResources=(array)($caps['resources']??[]);
    $sourceVcpus=max(1,(int)($local['vcpus']??1));
    $sourceMemoryKiB=max(131072,(int)($local['memoryKiB']??0));
    $requestedVcpus=(int)($options['vcpu_count']??$sourceVcpus);
    $requestedMemoryMiB=(int)($options['memory_mib']??(int)ceil($sourceMemoryKiB/1024));
    if($requestedVcpus<1||$requestedVcpus>4096) $errors[]='Requested vCPU count is outside the supported range.';
    if($requestedMemoryMiB<128||$requestedMemoryMiB>1048576) $errors[]='Requested RAM is outside the supported range.';
    $requestedMemoryKiB=max(131072,$requestedMemoryMiB*1024);
    $destCpuCount=(int)($destResources['cpuOnlineCount']??0);
    $destMemAvail=(int)($destResources['availableBytes']??0);
    $destMemTotal=(int)($destResources['totalBytes']??0);
    if($destCpuCount<=0) $errors[]='Unable to determine the destination online CPU count.';
    elseif($requestedVcpus>$destCpuCount) $errors[]="Requested vCPU count ($requestedVcpus) exceeds the destination online CPU count ($destCpuCount).";
    if($destMemAvail<=0) $errors[]='Unable to determine destination available memory.';
    elseif($requestedMemoryKiB*1024>$destMemAvail) $errors[]='Insufficient currently available RAM on the destination for the requested VM memory.';
    elseif(($destMemAvail-$requestedMemoryKiB*1024)<max(1073741824,(int)($destMemTotal*.05))) $warnings[]='The requested VM memory leaves little immediately available RAM on the destination.';

    $pinning=unmPinningCompatibility((array)($local['cpuPinning']??[]),$destResources,$sourceVcpus,$requestedVcpus);
    $requestedPin=(string)($options['cpu_pinning_action']??'');
    if(!in_array($requestedPin,['','retain','remove','none'],true)) $errors[]='Invalid CPU pinning action.';
    if(!empty($pinning['present'])&&$requestedPin==='none') $errors[]='CPU pinning action cannot be none when source pinning is present.';
    if(empty($pinning['present'])) $effectivePin='none';
    elseif(empty($pinning['valid'])) {
        $effectivePin='remove';
        foreach($pinning['reasons'] as $reason) $warnings[]=$reason;
        $warnings[]='Existing CPU/NUMA pinning is not valid on the destination and will be removed.';
    } else $effectivePin=$requestedPin!==''?$requestedPin:(!empty($cfg['remove_cpu_pinning'])?'remove':'retain');
    if($requestedPin==='retain'&&empty($pinning['valid'])) $warnings[]='Retain was requested, but invalid pinning is being forcibly stripped.';

    $storage=$caps['storage']??[]; $checks=$caps['checks']??[]; $features=$caps['features']??[];
    $imageDir=(string)($storage['imageDirectory']??''); $zvolDataset=(string)($storage['zvolDataset']??''); $isoDir=(string)($storage['isoDirectory']??'');
    $imageZfsDataset=(string)($storage['imageZfsDataset']??'');
    $destZvolReady=!empty($features['zvol'])&&!empty($checks['zvol_dataset_exists'])&&$zvolDataset!=='';
    if($imageDir===''||empty($checks['image_dir_exists'])||empty($checks['image_dir_writable'])) $errors[]='Destination image directory is unavailable or not writable.';
    $hasAttachedIsos=!empty($local['isos']);
    if($hasAttachedIsos&&$isoAction==='copy'&&($isoDir===''||empty($checks['iso_dir_exists'])||empty($checks['iso_dir_writable']))) $errors[]='Destination ISO directory is unavailable or not writable.';
    if(($local['loader']??'')!==''&&unmRemote($peer,'test -f '.escapeshellarg($local['loader']),15)['code']!==0) $errors[]='Destination firmware is missing: '.$local['loader'];

    $remoteVmName=''; $remoteVmState=''; $sameUuidVm=false;
    if($uuid!=='') {
        $r=unmRemote($peer,'virsh domname '.escapeshellarg($uuid).' 2>/dev/null',15);
        if($r['code']===0 && trim($r['stdout'])!=='') {
            $sameUuidVm=true; $remoteVmName=trim($r['stdout']);
            $stateResult=unmRemote($peer,'virsh domstate '.escapeshellarg($uuid).' 2>/dev/null',15);
            $remoteVmState=trim($stateResult['stdout']);
            $conflicts[]=['kind'=>'vm','path'=>$remoteVmName,'state'=>$remoteVmState,'sameUuid'=>true];
            if(!in_array($remoteVmState,['shut off','no state',''],true)) $errors[]='A VM with this UUID is running on the destination and cannot be overwritten.';
        }
    }
    $nameLookup=unmRemote($peer,'virsh domuuid '.escapeshellarg($vm).' 2>/dev/null',15);
    if($nameLookup['code']===0 && trim($nameLookup['stdout'])!=='' && trim($nameLookup['stdout'])!==$uuid) {
        $errors[]='A different VM already uses this name on the destination.';
        $conflicts[]=['kind'=>'vm-name','path'=>$vm,'uuid'=>trim($nameLookup['stdout']),'sameUuid'=>false];
    }
    $ownership=unmRemoteOwnershipMarker($peer,$uuid);
    $ownershipMatches=($ownership['vmUuid']??'')===$uuid;

    if(!empty($local['usb'])) {
        $remoteUsb=[]; $u=unmRemote($peer,'/usr/local/sbin/unmotion-agent usb-list',20);
        if($u['code']===0) $remoteUsb=json_decode($u['stdout'],true)['usb']??[];
        $matched=0; $nonPortable=0;
        foreach($local['usb'] as &$device) {
            $device['foundOnDestination']=false;
            if(empty($device['portable'])) { $nonPortable++; continue; }
            foreach($remoteUsb as $candidate) {
                if(strtolower((string)($candidate['vendor']??''))===strtolower((string)$device['vendor'])&&strtolower((string)($candidate['product']??''))===strtolower((string)$device['product'])) {
                    $device['foundOnDestination']=true; $device['destinationDescription']=$candidate['description']??''; $matched++; break;
                }
            }
        }
        unset($device);
        $warnings[]='USB passthrough is present: '.$matched.' of '.count($local['usb']).' devices have a VID:PID match on the destination. Choose retain, remove, or cancel.';
        if($nonPortable>0) $warnings[]="$nonPortable live USB attachment(s) are unresolved or VID:PID-ambiguous and cannot be portably retained.";
    }

    foreach($local['disks'] as $disk) {
        if($disk['type']==='zvol') {
            $src=substr($disk['source'],strlen('/dev/zvol/'));
            $enc=unmRun(['zfs','get','-H','-o','value','encryption',$src],null,15);
            if($enc['code']===0&&trim($enc['stdout'])!=='off') $errors[]='Native ZFS encryption is not supported for zvol replication in 0.3 beta1: '.$src;
            $rr=unmRun(['zfs','get','-pH','-o','value','referenced',$src],null,15);
            $rs=unmRun(['zfs','get','-pH','-o','value','volsize',$src],null,15);
            if($rr['code']!==0||$rs['code']!==0) { $errors[]='Unable to inspect source zvol: '.$src; $referenced=$volsize=0; }
            else { $referenced=(int)trim($rr['stdout']); $volsize=(int)trim($rs['stdout']); }
            if($destZvolReady) {
                $dst=rtrim($zvolDataset,'/').'/'.basename($src);
                $transferBytes=$referenced;
                if($migrationMode==='warm-cutover'&&$warmBaseSnapshot!==''){
                    $delta=unmRun(['zfs','get','-pH','-o','value','written@'.$warmBaseSnapshot,$src],null,15);
                    if($delta['code']===0)$transferBytes=max(0,(int)trim($delta['stdout']));
                }
                $required['zvol']+=$transferBytes;
                $plan[]=['kind'=>'zvol','source'=>$src,'destination'=>$dst,'bytes'=>$transferBytes,'seeded'=>$migrationMode==='warm-cutover'];
                if(empty($caps['tools']['zfs'])) $errors[]='Destination zfs command is unavailable for zvol receive.';
                if(!isset($warmSeedDestinations[$dst])&&unmRemote($peer,'zfs list '.escapeshellarg($dst).' >/dev/null 2>&1',15)['code']===0) { $usedResult=unmRemote($peer,'zfs get -pH -o value used '.escapeshellarg($dst),15); $conflicts[]=['kind'=>'zvol','path'=>$dst,'reclaimBytes'=>$usedResult['code']===0?(int)trim($usedResult['stdout']):0]; }
            } else {
                $dst=rtrim($imageDir,'/').'/'.$vm.'/'.basename($src).'.img'; $required['image']+=$volsize; $required['sourceStaging']+=$volsize;
                $plan[]=['kind'=>'zvol-to-image','source'=>$src,'destination'=>$dst,'bytes'=>$volsize,'format'=>'raw'];
                if(!unmTool('qemu-img')) $errors[]='Source qemu-img is unavailable for zvol-to-image conversion.';
                if(empty($caps['tools']['rsync'])) $errors[]='Destination rsync is unavailable for image transfer.';
                $warnings[]='Destination zvol storage is unavailable; '.$src.' will be converted to a sparse raw image.';
                if(!isset($warmSeedDestinations[$dst])&&unmRemote($peer,'test -e '.escapeshellarg($dst),15)['code']===0) { $spaceResult=unmRemote($peer,'stat -c '.escapeshellarg('%b %B').' '.escapeshellarg($dst),15); $reclaim=0; if($spaceResult['code']===0&&preg_match('/^(\d+)\s+(\d+)$/',trim($spaceResult['stdout']),$sm))$reclaim=(int)$sm[1]*(int)$sm[2]; $conflicts[]=['kind'=>'image','path'=>$dst,'reclaimBytes'=>$reclaim]; }
            }
            if(isset($destinations[$dst])) $errors[]="Multiple VM disks map to the same destination: $dst"; else $destinations[$dst]=true;
        } elseif($disk['type']==='file') {
            $source=(string)$disk['source'];
            if(!str_starts_with($source,'/mnt/')||!is_file($source)||is_link($source)) $errors[]='Source image is missing or unsafe: '.$source;
            $bytes=is_file($source)?(int)filesize($source):0;
            $nativeDataset=($disk['transferClass']??'')==='zfs-dataset-image'&&$imageZfsDataset!==''&&!empty($caps['tools']['zfs']);
            if($nativeDataset){
                if(!empty($disk['sourceViaFuse']))$warnings[]='A VM disk uses /mnt/user (FUSE); a direct pool/disk path is recommended.';
                $srcDataset=(string)($disk['zfsDataset']??'');$srcMount=(string)($disk['zfsMountpoint']??'');
                $destDataset=rtrim($imageZfsDataset,'/').'/'.basename($srcDataset);
                $destMount=rtrim($imageDir,'/').'/'.basename($srcMount);
                $groupKey='dataset:'.$srcDataset;
                if(!isset($destinations[$groupKey])){
                    $usedResult=unmRun(['zfs','get','-pH','-o','value','referenced',$srcDataset],null,15);
                    $datasetBytes=$usedResult['code']===0?(int)trim($usedResult['stdout']):$bytes;
                    if($migrationMode==='warm-cutover'&&$warmBaseSnapshot!==''){
                        $delta=unmRun(['zfs','get','-pH','-o','value','written@'.$warmBaseSnapshot,$srcDataset],null,15);
                        if($delta['code']===0)$datasetBytes=max(0,(int)trim($delta['stdout']));
                    }
                    $required['dataset']+=$datasetBytes;
                    $plan[]=['kind'=>'zfs-filesystem','source'=>$srcDataset,'destination'=>$destDataset,'sourceMountpoint'=>$srcMount,'destinationMountpoint'=>$destMount,'bytes'=>$datasetBytes,'seeded'=>$migrationMode==='warm-cutover','files'=>[]];
                    $destinations[$groupKey]=count($plan)-1;
                    if(!isset($warmSeedDestinations[$destDataset])&&unmRemote($peer,'zfs list '.escapeshellarg($destDataset).' >/dev/null 2>&1',15)['code']===0){$used=unmRemote($peer,'zfs get -pH -o value used '.escapeshellarg($destDataset),15);$conflicts[]=['kind'=>'dataset','path'=>$destDataset,'reclaimBytes'=>$used['code']===0?(int)trim($used['stdout']):0];}
                }
                $idx=(int)$destinations[$groupKey];
                $dst=$destMount.'/'.ltrim((string)($disk['relativePath']??basename($source)),'/');
                $plan[$idx]['files'][]=['source'=>$source,'destination'=>$dst,'relativePath'=>$disk['relativePath']??basename($source)];
                $warnings=array_values(array_filter($warnings,static fn(string $w):bool=>$w!==''));
            }else{
                $dst=rtrim($imageDir,'/').'/'.$vm.'/'.basename($source);
                $transferBytes=$bytes;
                if($migrationMode==='warm-cutover'&&isset($warmSeedDestinations[$dst])){
                    $srcStat=unmRun(['stat','-c','%b %B',$source],null,10);$srcAllocated=$bytes;
                    if($srcStat['code']===0&&preg_match('/^(\d+)\s+(\d+)$/',trim($srcStat['stdout']),$sm))$srcAllocated=(int)$sm[1]*(int)$sm[2];
                    $dstStat=unmRemote($peer,'stat -c '.escapeshellarg('%b %B').' '.escapeshellarg($dst),15);$dstAllocated=0;
                    if($dstStat['code']===0&&preg_match('/^(\d+)\s+(\d+)$/',trim($dstStat['stdout']),$dm))$dstAllocated=(int)$dm[1]*(int)$dm[2];
                    $transferBytes=max(67108864,$srcAllocated-$dstAllocated);
                }
                $required['image']+=$transferBytes;
                $plan[]=['kind'=>'image','source'=>$source,'destination'=>$dst,'bytes'=>$transferBytes,'seeded'=>$migrationMode==='warm-cutover','storageClass'=>$disk['transferClass']??'non-zfs-image'];
                if(empty($caps['tools']['rsync'])) $errors[]='Destination rsync is unavailable for image transfer.';
                if(isset($destinations[$dst])) $errors[]="Multiple VM disks map to the same destination: $dst"; else $destinations[$dst]=true;
                if(!isset($warmSeedDestinations[$dst])&&unmRemote($peer,'test -e '.escapeshellarg($dst),15)['code']===0) { $spaceResult=unmRemote($peer,'stat -c '.escapeshellarg('%b %B').' '.escapeshellarg($dst),15); $reclaim=0; if($spaceResult['code']===0&&preg_match('/^(\d+)\s+(\d+)$/',trim($spaceResult['stdout']),$sm))$reclaim=(int)$sm[1]*(int)$sm[2]; $conflicts[]=['kind'=>'image','path'=>$dst,'reclaimBytes'=>$reclaim]; }
                if(!empty($disk['sourceViaFuse']))$warnings[]='A VM disk uses /mnt/user (FUSE); a direct pool/disk path is recommended.';
                if(($disk['transferClass']??'')==='shared-zfs-image')$warnings[]='A VM image is in a shared ZFS dataset; Warm Move uses two-pass rsync.';
                elseif(($disk['transferClass']??'')==='encrypted-zfs-image')$warnings[]='A VM image is in an encrypted ZFS dataset; Warm Move uses two-pass rsync.';
                elseif(($disk['transferClass']??'')==='non-zfs-image')$warnings[]='A VM image is not on ZFS; Warm Move uses two-pass rsync.';
            }
        } else $errors[]='Unsupported disk source: '.$disk['source'];
    }
    foreach($local['isos'] as $iso) {
        $dst=rtrim($isoDir,'/').'/'.basename($iso); $bytes=is_file($iso)?(int)filesize($iso):0;
        if($isoAction==='copy') { $required['iso']+=$bytes; if(empty($caps['tools']['rsync'])) $errors[]='Destination rsync is unavailable for ISO copying.'; }
        $plan[]=['kind'=>'iso','source'=>$iso,'destination'=>$dst,'action'=>$isoAction,'bytes'=>$bytes];
        if($isoAction==='copy'&&(!str_starts_with($iso,'/mnt/')||!is_file($iso)||is_link($iso))) $errors[]='Attached ISO is missing or unsafe: '.$iso;
    }

    $storageConflicts=array_values(array_filter($conflicts,static fn(array $c):bool=>in_array($c['kind']??'', ['zvol','dataset','image'], true)));
    if($conflicts) {
        if($conflictAction==='cancel') {
            $errors[]='The destination contains an existing VM definition or disk data for this migration. Choose overwrite to replace the retained copy, or cancel.';
        } else {
            if(!$sameUuidVm && !$ownershipMatches && $storageConflicts) $errors[]='Existing destination storage has no matching VM UUID or unMotion ownership marker, so automatic overwrite is refused.';
            if(!array_filter($errors,static fn(string $e):bool=>str_contains($e,'running on the destination')||str_contains($e,'different VM already uses'))) {
                $warnings[]='Overwrite selected: the stopped destination VM definition and conflicting disk data will be deleted immediately before transfer.';
            }
        }
    }

    $reclaimable=['zvol'=>0,'dataset'=>0,'image'=>0];
    if($conflictAction==='overwrite') foreach($conflicts as $conflict) {
        $kind=(string)($conflict['kind']??'');
        if(isset($reclaimable[$kind])) $reclaimable[$kind]+=(int)($conflict['reclaimBytes']??0);
    }
    $zfree=0;
    if($required['zvol']>0&&$zvolDataset!=='') {
        $r=unmRemote($peer,'zfs get -pH -o value available '.escapeshellarg($zvolDataset),15);
        $zfree=$r['code']===0?(int)trim($r['stdout']):0;
        if($zfree<=0) $errors[]='Unable to determine destination ZFS free space.';
        elseif($required['zvol']>($zfree+$reclaimable['zvol'])) $errors[]='Insufficient destination ZFS space for the referenced zvol data, even after reclaiming the retained copy.';
    }
    $imageFs=unmRemoteFsCapacity($peer,$imageDir);
    $isoFs=$required['iso']>0?unmRemoteFsCapacity($peer,$isoDir):['device'=>'','available'=>0];
    $ifree=(int)$imageFs['available']; $isofree=(int)$isoFs['available'];
    if($required['image']>0&&$ifree<=0) $errors[]='Unable to determine destination image-directory free space.';
    if($required['iso']>0&&$isofree<=0) $errors[]='Unable to determine destination ISO-directory free space.';
    if($required['image']>($ifree+$reclaimable['image'])&&$ifree>0) $errors[]='Insufficient destination image-directory space, even after reclaiming the retained copy.';
    if($required['iso']>$isofree&&$isofree>0) $errors[]='Insufficient destination ISO-directory space.';
    if($required['image']>0&&$required['iso']>0&&$imageFs['device']!==''&&$imageFs['device']===$isoFs['device']&&($required['image']+$required['iso'])>($ifree+$reclaimable['image'])) $errors[]='Image and ISO transfers share the same destination filesystem and together exceed its free space after reclaiming the retained copy.';
    $poolReq=[];
    if($required['zvol']>0&&$zvolDataset!=='') { $pool=explode('/',$zvolDataset,2)[0]; $poolReq[$pool]=($poolReq[$pool]??0)+$required['zvol']; }
    if($required['dataset']>0&&$imageZfsDataset!=='') { $pool=explode('/',$imageZfsDataset,2)[0]; $poolReq[$pool]=($poolReq[$pool]??0)+$required['dataset']; }
    foreach([[$required['image'],(string)($storage['imageZfsContainingDataset']??'')],[$required['iso'],(string)($storage['isoZfsContainingDataset']??'')]] as [$bytes,$dataset]) {
        if($bytes>0&&$dataset!=='') { $pool=explode('/',$dataset,2)[0]; $poolReq[$pool]=($poolReq[$pool]??0)+(int)$bytes; }
    }
    $poolReclaim=[];
    if($conflictAction==='overwrite') foreach($conflicts as $conflict) {
        $kind=(string)($conflict['kind']??''); $path=(string)($conflict['path']??''); $bytes=(int)($conflict['reclaimBytes']??0);
        if($bytes<=0) continue;
        if($kind==='zvol'&&str_contains($path,'/')) { $pool=explode('/',$path,2)[0]; $poolReclaim[$pool]=($poolReclaim[$pool]??0)+$bytes; }
        elseif($kind==='image'&&(string)($storage['imageZfsContainingDataset']??'')!=='') { $pool=explode('/',(string)$storage['imageZfsContainingDataset'],2)[0]; $poolReclaim[$pool]=($poolReclaim[$pool]??0)+$bytes; }
    }
    $poolAvail=[];
    foreach($poolReq as $pool=>$bytes) {
        $r=unmRemote($peer,'zpool list -pH -o free '.escapeshellarg($pool),15); $free=$r['code']===0?(int)trim($r['stdout']):0; $poolAvail[$pool]=$free;
        if($free<=0) $errors[]="Unable to determine free space for destination ZFS pool $pool.";
        elseif($bytes>($free+($poolReclaim[$pool]??0))) $errors[]="Combined migration data exceeds free space in destination ZFS pool $pool after reclaiming the retained copy.";
    }
    $stageDir=rtrim((string)$cfg['image_dir'],'/'); $stageFs=unmLocalFsCapacity($stageDir); $stageFree=(int)$stageFs['available'];
    if($required['sourceStaging']>0&&($stageDir===''||!is_dir($stageDir)||!is_writable($stageDir))) $errors[]='The local image directory is required as conversion staging space but is unavailable.';
    if($required['sourceStaging']>0&&$stageFree<=0) $errors[]='Unable to determine local conversion-staging free space.';
    elseif($required['sourceStaging']>$stageFree&&$stageFree>0) $errors[]='Insufficient local image-directory space to stage zvol-to-image conversion.';

    $resourcePlan=[
        'source'=>['vcpus'=>$sourceVcpus,'memoryKiB'=>$sourceMemoryKiB,'memoryMiB'=>(int)ceil($sourceMemoryKiB/1024)],
        'requested'=>['vcpus'=>$requestedVcpus,'memoryKiB'=>$requestedMemoryKiB,'memoryMiB'=>$requestedMemoryMiB],
        'destination'=>['cpuOnlineCount'=>$destCpuCount,'cpuOnlineSet'=>(string)($destResources['cpuOnlineSet']??''),'numaNodeSet'=>(string)($destResources['numaNodeSet']??''),'memoryTotalBytes'=>$destMemTotal,'memoryAvailableBytes'=>$destMemAvail],
        'pinning'=>['present'=>(bool)($pinning['present']??false),'valid'=>(bool)($pinning['valid']??true),'requestedAction'=>$requestedPin,'effectiveAction'=>$effectivePin,'forcedRemoval'=>(bool)(!empty($pinning['present'])&&empty($pinning['valid'])),'reasons'=>$pinning['reasons']??[],'cpuSets'=>$local['cpuPinning']['cpuSets']??[],'numaSets'=>$local['cpuPinning']['numaSets']??[]],
    ];
    return [
        'ready'=>empty($errors),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),
        'vm'=>$local,'peer'=>$peer,'peerHealth'=>$peerHealth,'destination'=>$caps,'plan'=>$plan,'resourcePlan'=>$resourcePlan,'migrationMode'=>$migrationMode,'warmSeed'=>$warmSeed,
        'capacity'=>['required'=>$required,'available'=>['zvol'=>$zfree+$reclaimable['zvol'],'image'=>$ifree+$reclaimable['image'],'iso'=>$isofree,'sourceStaging'=>$stageFree],'currentlyFree'=>['zvol'=>$zfree,'image'=>$ifree,'iso'=>$isofree],'reclaimable'=>$reclaimable,'zfsPools'=>$poolAvail],
        'sourceCleanup'=>['action'=>$sourceCleanupAction,'soakSeconds'=>300],
        'conflicts'=>['action'=>$conflictAction,'items'=>$conflicts,'overwriteEligible'=>empty(array_filter($errors,static fn(string $e):bool=>str_contains($e,'overwrite is refused')||str_contains($e,'running on the destination')||str_contains($e,'different VM already uses'))),'ownershipMarker'=>$ownership],
    ];
}
