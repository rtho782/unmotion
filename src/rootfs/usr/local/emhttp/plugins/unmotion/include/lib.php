<?php
declare(strict_types=1);

const UNM_VERSION = '0.4.1-beta1';
require_once __DIR__.'/diagnostics.php';
require_once __DIR__.'/migration-nvram.php';
require_once __DIR__.'/host-state.php';
require_once __DIR__.'/firmware.php';
require_once __DIR__.'/migration-resolution.php';
const UNM_PROTOCOL = 5;
// Legacy migration, cloning, pairing, and scheduled replication continue to
// use protocol 5.  Protocol 6 is negotiated only for the recovery control
// plane and must never change the shape of a protocol-5 operation.
const UNM_PROTOCOL_MIN = 5;
const UNM_PROTOCOL_MAX = 6;
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
const UNM_RECOVERY_PROTOCOL = 1;
const UNM_RECOVERY_EVIDENCE_TTL = 120;
const UNM_RECOVERY_RPC_TTL = 120;
const UNM_RECOVERY_SCHEMA = 1;
const UNM_RECOVERY_WORKER = '/usr/local/sbin/unmotion-recovery-worker';
const UNM_RECOVERY_RUNTIME_DIR = '/var/lib/unmotion/recovery';
const UNM_RECOVERY_SHUTDOWN_FENCE = '/var/run/unmotion/shutdown-fence';

function unmProtocolRange(array $capabilities): array {
    $legacy=(int)($capabilities['protocolVersion']??0);
    $minimum=array_key_exists('protocolMinVersion',$capabilities)?(int)$capabilities['protocolMinVersion']:$legacy;
    $maximum=array_key_exists('protocolMaxVersion',$capabilities)?(int)$capabilities['protocolMaxVersion']:$legacy;
    if($minimum<1||$maximum<1||$minimum>$maximum||$maximum>255)throw new InvalidArgumentException('Peer protocol range is invalid.');
    return ['min'=>$minimum,'max'=>$maximum];
}

function unmNegotiateProtocol(array $localCapabilities,array $remoteCapabilities): ?int {
    $local=unmProtocolRange($localCapabilities);$remote=unmProtocolRange($remoteCapabilities);
    $minimum=max($local['min'],$remote['min']);$maximum=min($local['max'],$remote['max']);
    return $maximum>=$minimum?$maximum:null;
}

function unmRecoveryProtocolAvailable(array $capabilities): bool {
    try{$range=unmProtocolRange($capabilities);}catch(Throwable $ignored){return false;}
    return UNM_PROTOCOL_MIN<=6&&UNM_PROTOCOL_MAX>=6&&$range['min']<=6&&$range['max']>=6
        &&(int)($capabilities['recoveryProtocolVersion']??0)===UNM_RECOVERY_PROTOCOL
        &&!empty($capabilities['features']['recoveryControlPlane']);
}

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
    return ['protocolVersion'=>UNM_PROTOCOL,'protocolMinVersion'=>UNM_PROTOCOL_MIN,'protocolMaxVersion'=>UNM_PROTOCOL_MAX,'supportedProtocolVersions'=>range(UNM_PROTOCOL_MIN,UNM_PROTOCOL_MAX),'replicationProtocolVersion'=>UNM_REPLICATION_PROTOCOL,'recoveryProtocolVersion'=>UNM_RECOVERY_PROTOCOL,'pluginVersion'=>UNM_VERSION,'hostId'=>unmHostId(),'hostname'=>gethostname()?:'unknown','ssh'=>unmSshStatus(),
        'storage'=>['imageDirectory'=>$cfg['image_dir'],'zvolDataset'=>$cfg['zvol_dataset'],'isoDirectory'=>$cfg['iso_dir'],'dedup'=>$cfg['dedup'],'compression'=>$cfg['compression'],'imageZfsDataset'=>$imageDataset,'imageZfsContainingDataset'=>$imageContaining,'isoZfsContainingDataset'=>$isoContaining,'zfsVersions'=>unmZfsVersions()],
        'resources'=>unmHostResources(),
        'features'=>['firmwareMapping'=>true,'customNvramReplication'=>true,'customNvramMigration'=>true,'zfs'=>$zfsAvailable,'zvol'=>$zvolReady,'zvolToImage'=>unmTool('qemu-img')&&unmTool('rsync'),'fileImages'=>unmTool('rsync'),'dedicatedDatasetImages'=>$zfsAvailable,'localClone'=>true,'ubuntuGuestCustomization'=>true,'warmMove'=>true,'warmZfsIncremental'=>$zfsAvailable,'warmRsyncSeed'=>unmTool('rsync'),'qemuGuestAgentQuiesce'=>true,'peerHealth'=>true,'tpm'=>unmTool('swtpm')||is_dir('/etc/libvirt/qemu/swtpm')||is_dir('/var/lib/libvirt/swtpm'),'isoCopy'=>unmTool('rsync'),'discovery'=>unmTool('avahi-browse'),'pciPassthroughMigration'=>false,'usbPassthroughPolicy'=>true,'liveUsbInventory'=>true,'jobCancellation'=>true,'resourceResize'=>true,'cpuPinningValidation'=>true,'streamingProgress'=>true,'delayedSourceCleanup'=>true,'destinationConflictHandling'=>true,'scheduledReplication'=>true,'replicationProtocolVersion'=>UNM_REPLICATION_PROTOCOL,'replicationRetention'=>true,'replicaInventory'=>true,'recoveryControlPlane'=>true,'managedAutostart'=>true,'gracefulHoldoff'=>true,'evidenceProbe'=>true,'recoveryActivation'=>true,'checkpointRetry'=>true,'activationRemoval'=>true,'coldFailbackPreflight'=>true,'witnessVote'=>false,'automaticFailover'=>false],
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
    try{unmRecoveryAssertLegacyVmAvailable((string)($vm['uuid']??''),'Clone');}catch(Throwable $e){$errors[]=$e->getMessage();}
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
        try{
            $owned=unmOwnedNvram($vm);
            $nvramDest=($owned['kind']==='custom'?'/etc/libvirt/qemu/nvram':dirname($nvram)).'/'.$cloneUuid.'_VARS.fd';
            if(file_exists($nvramDest)||is_link($nvramDest))throw new RuntimeException('Clone NVRAM destination already exists.');
            $plan[]=['kind'=>'nvram','source'=>$nvram,'destination'=>$nvramDest,'bytes'=>(int)filesize($nvram),'sha256'=>hash_file('sha256',$nvram)];$maps[$nvram]=$nvramDest;
        }catch(Throwable $e){$errors[]=$e->getMessage();}
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

function unmPublicPeerValue(mixed $value): mixed {
    if(!is_array($value))return $value;$blocked=['recoverySecret','pendingRecoveryBootstrap','bootstrapSignature','secret','keyPath','knownHosts','incomingPublicKey'];foreach($blocked as $key)unset($value[$key]);foreach($value as $key=>$item)$value[$key]=unmPublicPeerValue($item);return $value;
}

function unmPublicPeer(array $peer): array {
    $public=[];foreach(['id','name','host','port','username','hostId','pluginVersion','lastCapabilities','reciprocalPeerId','pairingState','pairedAt','fingerprint'] as $key)if(array_key_exists($key,$peer))$public[$key]=unmPublicPeerValue($peer[$key]);return $public;
}

function unmPublicPeers(array $peers): array {return array_map('unmPublicPeer',$peers);}

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

/**
 * Keep the configured retention set plus, while recovery is armed, one
 * recovery-capable safety point when the normal set contains none. The
 * destination annotates inventory entries after validating its local XML and
 * checkpoint material, so the source never guesses from inaccessible paths.
 */
function unmSelectReplicationRetentionPointsWithRecoverySafety(array $points,int $rpoSeconds,int $retentionCount,bool $recoveryArmed,?int $now=null): array {
    $selected=unmSelectReplicationRetentionPoints($points,$rpoSeconds,$retentionCount,$now);
    if(!$recoveryArmed)return $selected;
    foreach($selected as $point)if(is_array($point)&&!empty($point['recoveryEligible']))return $selected;
    $ordered=[];
    foreach($points as $index=>$point){
        if(!is_array($point))continue;$timestamp=unmReplicationPointTimestamp($point);if($timestamp<=0)continue;
        $ordered[]=['point'=>$point,'timestamp'=>$timestamp,'index'=>$index];
    }
    usort($ordered,static fn(array $a,array $b):int=>$b['timestamp']<=>$a['timestamp']?:$b['index']<=>$a['index']);
    $chosen=null;
    foreach($ordered as $entry)if(!empty($entry['point']['recoveryEligible'])){$chosen=$entry['point'];break;}
    // If exact validation is temporarily unavailable, preserve the newest
    // published non-replication-only candidate instead of deleting what may
    // be the only repairable recovery point.
    if($chosen===null)foreach($ordered as $entry)if(!empty($entry['point']['recoveryCandidate'])){$chosen=$entry['point'];break;}
    if($chosen===null)return $selected;
    $chosenId=(string)($chosen['id']??'');foreach($selected as $point)if($chosenId!==''&&hash_equals($chosenId,(string)($point['id']??'')))return $selected;
    $selected[]=$chosen;return $selected;
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
    $identity=['replicationId'=>$id,'vmUuid'=>(string)$policy['vmUuid'],'sourceHostId'=>unmHostId(),'destinationHostId'=>(string)$policy['peerHostId']];
    $record['recovery']=unmRecoveryPublicRecord(unmRecoveryLoad(unmRecoverySourcePath($id),unmRecoveryDefaultRecord($identity,true)));
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

function unmReplicationDatasetIsolationErrors(string $dataset,string $mountpoint,array $declaredFiles,?array $vm=null): array {
    if($vm!==null){
        try{
            $nvram=unmOwnedNvram($vm);
            if($nvram['kind']==='custom'&&unmPathWithin($nvram['source'],$mountpoint)){
                // Only the exact inventory-verified variables file is allowed;
                // it is host state, not another writable disk in the storage plan.
                if(!in_array($nvram['diskSource'],$declaredFiles,true))throw new RuntimeException('Custom NVRAM sibling disk is outside this dataset plan.');
                $declaredFiles[]=$nvram['source'];
            }
        }catch(Throwable $e){return ['Dataset '.$dataset.' NVRAM ownership cannot be proven: '.$e->getMessage()];}
    }
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
    try{
        $nvram=unmOwnedNvram($vm);
        if($nvram['kind']==='custom'&&empty($caps['features']['customNvramReplication']))throw new RuntimeException('Custom NVRAM replication/recovery requires a beta4 destination.');
        if(!empty($vm['tpm']))unmTpmStatePath((string)$vm['uuid'],unmDomainXml((string)$vm['uuid'],true),false);
    }catch(Throwable $e){$errors[]=$e->getMessage();}
    try{unmRecoveryAssertLegacyVmAvailable((string)($vm['uuid']??''),'Replication policy mutation');}catch(Throwable $e){$errors[]=$e->getMessage();}
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
        foreach(unmReplicationDatasetIsolationErrors($dataset,(string)$group['mountpoint'],$files,$vm) as $error)$errors[]=$error.' Use the ZFS Master plugin to prepare a dedicated dataset.';
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
    $policyLock=unmAcquireReplicationPolicyLock($id);$policy=unmReplicationPolicy($id);unmRecoveryAssertLegacyVmAvailable((string)$policy['vmUuid'],'Replication policy mutation');$policy['enabled']=$enabled;$policy['updatedAt']=date(DATE_ATOM);unmAtomicJson(unmReplicationPath($id).'/policy.json',$policy);
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
    $recovery=(array)($record['recovery']??[]);if(!empty($recovery['armed'])||(string)($recovery['state']??'REPLICATION_ONLY')!=='REPLICATION_ONLY')throw new RuntimeException('Disarm and reconcile recovery on both hosts before removing this replication policy.');
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
        $identity=['replicationId'=>$manifest['id'],'vmUuid'=>(string)($manifest['vmUuid']??''),'sourceHostId'=>(string)($manifest['sourceHostId']??''),'destinationHostId'=>unmHostId()];
        $manifest['recovery']=unmRecoveryPublicRecord(unmRecoveryLoad(dirname($path).'/recovery.json',unmRecoveryDefaultRecord($identity,false)));
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
    $identity=unmValidateReplicaRequestIdentity($request);$recoveryLock=unmRecoveryAcquireLock($identity['replicationId']);try{$dir=unmReplicaPath($identity['sourceHostId'],$identity['replicationId']);$manifest=unmLoadJson($dir.'/manifest.json');if(!$manifest)throw new RuntimeException('Replica must be reserved before publishing a recovery point.');
    unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);
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
    unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);$manifest['points']=$points;$manifest['currentPointId']=$pointId;$manifest['state']='READY';$manifest['lastSuccessAt']=$point['completedAt'];$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);
    return ['published'=>true,'accepted'=>true,'idempotent'=>false,'point'=>$point,'points'=>$points,'manifest'=>$manifest,'pointCount'=>count($points),'currentPointId'=>$pointId];
    }finally{unmRecoveryReleaseLock($recoveryLock);}
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
    $identity=unmValidateReplicaRequestIdentity($request,false);$recoveryLock=unmRecoveryAcquireLock($identity['replicationId']);try{$dir=unmReplicaPath($identity['sourceHostId'],$identity['replicationId']);$manifest=unmLoadJson($dir.'/manifest.json');if(!$manifest)throw new RuntimeException('Replica inventory not found.');
    unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);
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
                    unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);$release=unmRun(['zfs','release',$ownHold,$full],null,15);if($release['code']!==0)throw new RuntimeException('Unable to release the exact unMotion hold on '.$full.': '.trim($release['stderr']));
                }
                if(unmReplicaSnapshotHoldTags($full))throw new RuntimeException('Recovery snapshot gained another hold and was preserved: '.$full);
                $currentGuid=unmReplicaZfsProperty($full,'guid');if(!hash_equals((string)$stored['guid'],$currentGuid))throw new RuntimeException('Recovery snapshot GUID changed immediately before prune: '.$full);
                unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);$destroy=unmRun(['zfs','destroy',$full],null,60);if($destroy['code']!==0)throw new RuntimeException('Unable to prune recovery snapshot '.$full.': '.trim($destroy['stderr']));
            }
        }catch(Throwable $e){
            foreach($manifest['points'] as &$manifestPoint)if((string)($manifestPoint['id']??'')===$id){$manifestPoint['state']='CLEANUP_FAILED';$manifestPoint['cleanupError']=$e->getMessage();break;}unset($manifestPoint);
            $manifest['state']='CLEANUP_FAILED';$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);throw $e;
        }
        unset($byId[$id]);$manifest['points']=array_values($byId);usort($manifest['points'],static fn(array $a,array $b):int=>unmReplicationPointTimestamp($b)<=>unmReplicationPointTimestamp($a));$manifest['state']='READY';unset($manifest['checkpointCleanupError']);$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);$pruned[]=$id;
    }
    try{
        unmRecoveryAssertReplicaMutationAllowed($identity['sourceHostId'],$identity['replicationId']);
        unmGarbageCollectReplicaCheckpointArchives((array)($manifest['points']??[]),(string)($manifest['stateDirectory']??''));
        if(isset($manifest['checkpointCleanupError'])){unset($manifest['checkpointCleanupError']);$manifest['state']='READY';$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);}
    }catch(Throwable $e){
        $manifest['state']='CLEANUP_FAILED';$manifest['checkpointCleanupError']=$e->getMessage();$manifest['updatedAt']=date(DATE_ATOM);unmAtomicJson($dir.'/manifest.json',$manifest);throw $e;
    }
    return ['pruned'=>$pruned,'pointCount'=>count((array)($manifest['points']??[])),'currentPointId'=>$manifest['currentPointId']??null,'manifest'=>$manifest];
    }finally{unmRecoveryReleaseLock($recoveryLock);}
}

function unmReplicaInventory(array $request): array {
    $identity=unmValidateReplicaRequestIdentity($request,false);$manifest=unmReplicaManifest($identity['sourceHostId'],$identity['replicationId']);if(!$manifest)throw new RuntimeException('Replica inventory not found.');
    if(($manifest['vmUuid']??'')!==$identity['vmUuid'])throw new RuntimeException('Replica inventory VM identity does not match.');
    $points=[];$now=time();
    foreach((array)($manifest['points']??[]) as $point){
        if(!is_array($point))continue;$eligibility=unmRecoveryPointEligibility($manifest,$point,$now,false);
        $point['recoveryEligible']=!empty($eligibility['eligible']);
        $point['recoveryCandidate']=(string)($point['state']??'')==='AVAILABLE'&&empty($point['replicationOnly']);
        $points[]=$point;
    }
    return ['manifest'=>$manifest,'points'=>$points,'pointCount'=>count($points),'currentPointId'=>$manifest['currentPointId']??null];
}

function unmRecoveryStates(): array {
    return ['REPLICATION_ONLY','ARMING','STANDBY','HOLDOFF','SOURCE_START_FENCED','EVIDENCE_GATHERING','RECOVERY_READY','ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED','SPLIT_BRAIN_SUSPECTED','SPLIT_BRAIN_FENCING','SPLIT_BRAIN_UNRESOLVED','FAILBACK_PREPARING','FAILBACK_CUTOVER','SOURCE_RESTORING','DISARMING','FENCED'];
}

function unmRecoveryValidateState(string $state): string {
    $state=strtoupper(trim($state));
    if(!in_array($state,unmRecoveryStates(),true))throw new InvalidArgumentException('Invalid recovery state.');
    return $state;
}

function unmRecoveryTransitionAllowed(string $from,string $to): bool {
    $from=unmRecoveryValidateState($from);$to=unmRecoveryValidateState($to);
    if($from===$to)return true;
    if(in_array($to,['FENCED','SPLIT_BRAIN_SUSPECTED'],true)&&$from!=='REPLICATION_ONLY')return true;
    $allowed=[
        'REPLICATION_ONLY'=>['ARMING'],
        'ARMING'=>['STANDBY','REPLICATION_ONLY'],
        'STANDBY'=>['HOLDOFF','SOURCE_START_FENCED','EVIDENCE_GATHERING','DISARMING'],
        'HOLDOFF'=>['STANDBY','DISARMING'],
        'SOURCE_START_FENCED'=>['STANDBY','FENCED'],
        'EVIDENCE_GATHERING'=>['STANDBY','HOLDOFF','RECOVERY_READY'],
        'RECOVERY_READY'=>['EVIDENCE_GATHERING','ACTIVATING'],
        'ACTIVATING'=>['RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'],
        'RECOVERED_STOPPED'=>['RECOVERED_RUNNING','RECOVERY_BOOT_FAILED','FAILBACK_PREPARING','DISARMING'],
        'RECOVERED_RUNNING'=>['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED'],
        'RECOVERY_BOOT_FAILED'=>['RECOVERED_STOPPED','RECOVERED_RUNNING','DISARMING'],
        'SPLIT_BRAIN_SUSPECTED'=>['SPLIT_BRAIN_FENCING','SPLIT_BRAIN_UNRESOLVED'],
        'SPLIT_BRAIN_FENCING'=>['RECOVERED_STOPPED','SPLIT_BRAIN_UNRESOLVED'],
        'SPLIT_BRAIN_UNRESOLVED'=>[],
        'FAILBACK_PREPARING'=>['RECOVERED_STOPPED','FAILBACK_CUTOVER'],
        'FAILBACK_CUTOVER'=>['SOURCE_RESTORING','SPLIT_BRAIN_UNRESOLVED'],
        'SOURCE_RESTORING'=>['STANDBY','SPLIT_BRAIN_UNRESOLVED'],
        'DISARMING'=>['REPLICATION_ONLY'],
        'FENCED'=>['STANDBY','EVIDENCE_GATHERING','REPLICATION_ONLY','SPLIT_BRAIN_SUSPECTED'],
    ];
    return in_array($to,$allowed[$from]??[],true);
}

function unmRecoveryValidateRecord(array $record): array {
    $record['state']=unmRecoveryValidateState((string)($record['state']??'REPLICATION_ONLY'));
    $record['term']=(int)($record['term']??0);if($record['term']<0)throw new InvalidArgumentException('Recovery term cannot be negative.');
    $authority=strtoupper(trim((string)($record['authority']??'SOURCE')));if(!in_array($authority,['SOURCE','DESTINATION','UNKNOWN'],true))throw new InvalidArgumentException('Invalid recovery authority.');$record['authority']=$authority;
    $record['armed']=!empty($record['armed']);$record['managedAutostart']=!empty($record['managedAutostart']);$record['desiredAutostart']=!empty($record['desiredAutostart']);
    foreach(['activationId'=>'/^act-[a-f0-9]{24}$/','claimId'=>'/^claim-[a-f0-9]{24}$/'] as $key=>$pattern){$value=(string)($record[$key]??'');if($value!==''&&!preg_match($pattern,$value))throw new InvalidArgumentException('Invalid recovery '.$key.'.');}
    if(isset($record['hold'])&&!is_array($record['hold']))throw new InvalidArgumentException('Invalid recovery hold.');
    return $record;
}

function unmRecoveryRecordInvariantErrors(array $record): array {
    $errors=[];try{$record=unmRecoveryValidateRecord($record);}catch(Throwable $e){return [$e->getMessage()];}$state=(string)$record['state'];$armed=!empty($record['armed']);$term=(int)$record['term'];$authority=(string)$record['authority'];
    if($armed&&$term<1)$errors[]='An armed recovery policy requires a positive authority term.';
    if(!$armed&&!in_array($state,['REPLICATION_ONLY','FENCED'],true))$errors[]='An unarmed recovery policy cannot be in an active recovery state.';
    if($state==='REPLICATION_ONLY'&&($armed||!empty($record['managedAutostart'])))$errors[]='Replication-only state cannot retain managed recovery autostart.';
    if(isset($record['hold'])&&$state!=='HOLDOFF')$errors[]='A durable recovery hold requires HOLDOFF state.';
    if($state==='HOLDOFF'&&!is_array($record['hold']??null))$errors[]='HOLDOFF state requires a durable hold record.';
    if(in_array($state,['RECOVERY_READY','ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'],true)&&!preg_match('/^act-[a-f0-9]{24}$/',(string)($record['activationId']??$record['claim']['activationId']??'')))$errors[]='Recovery activation states require an activation id.';
    if(in_array($state,['RECOVERY_READY','ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'],true)&&$authority!=='DESTINATION')$errors[]='Recovery activation states require destination authority.';
    if($state==='STANDBY'&&$armed&&$authority!=='SOURCE')$errors[]='Armed STANDBY requires source authority.';
    if(isset($record['claim'])&&!is_array($record['claim']))$errors[]='Recovery claim must be an object.';
    if(isset($record['activation'])&&!is_array($record['activation']))$errors[]='Recovery activation must be an object.';
    return array_values(array_unique($errors));
}

function unmRecoveryAssertRecordInvariants(array $record): void {
    $errors=unmRecoveryRecordInvariantErrors($record);if($errors)throw new RuntimeException('Recovery record invariant failed: '.implode(' ',$errors));
}

function unmRecoverySourcePath(string $replicationId): string {
    return unmReplicationPath($replicationId).'/recovery.json';
}

function unmRecoveryReplicaContext(string $replicationId): array {
    unmReplicationPath($replicationId);$matches=[];
    foreach(glob(UNM_REPLICAS_DIR.'/*/'.$replicationId.'/manifest.json')?:[] as $path){$manifest=unmLoadJson($path);if($manifest)$matches[]=['dir'=>dirname($path),'manifest'=>$manifest];}
    if(count($matches)!==1)throw new RuntimeException(count($matches)?'Replica identity is ambiguous.':'Incoming replica not found.');
    return $matches[0];
}

function unmRecoveryReplicaPath(string $replicationId): string {
    return unmRecoveryReplicaContext($replicationId)['dir'].'/recovery.json';
}

function unmRecoveryLoad(string $path,array $defaults=[]): array {
    $record=unmLoadJson($path,$defaults);return unmRecoveryValidateRecord($record?:$defaults);
}

function unmRecoveryStore(string $path,array $record): array {
    $record=unmRecoveryValidateRecord($record);unmRecoveryAssertRecordInvariants($record);$record['updatedAt']=gmdate('c');unmAtomicJson($path,$record);return $record;
}

function unmRecoveryTransition(array $record,string $to,array $changes=[]): array {
    $from=(string)($record['state']??'REPLICATION_ONLY');$to=unmRecoveryValidateState($to);
    if(!unmRecoveryTransitionAllowed($from,$to))throw new RuntimeException("Recovery transition $from to $to is not permitted.");
    return unmRecoveryValidateRecord(array_replace($record,$changes,['state'=>$to]));
}

function unmRecoveryAcquireLock(string $replicationId) {
    unmReplicationPath($replicationId);$path='/var/lock/unmotion-recovery-'.$replicationId.'.lock';$handle=fopen($path,'c');
    if($handle===false)throw new RuntimeException('Unable to open recovery policy lock.');@chmod($path,0600);
    if(!flock($handle,LOCK_EX)){fclose($handle);throw new RuntimeException('Unable to lock recovery policy.');}return $handle;
}

function unmRecoveryReleaseLock($handle): void {if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}

function unmRecoveryAcquireVmLock(string $vmUuid) {
    $vmUuid=strtolower(trim($vmUuid));if(!preg_match('/^[a-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid recovery VM lock identity.');$path='/var/lock/unmotion-'.$vmUuid.'.lock';$handle=fopen($path,'c');if($handle===false)throw new RuntimeException('Unable to open the global VM operation lock.');@chmod($path,0600);if(!flock($handle,LOCK_EX|LOCK_NB)){fclose($handle);throw new RuntimeException('Another unMotion migration, replication, clone, or recovery operation is using this VM.');}return $handle;
}

function unmRecoveryBootId(): string {
    $id=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
    return preg_match('/^[a-f0-9-]{32,40}$/i',$id)?strtolower($id):'unknown-boot';
}

function unmRecoveryRandomId(string $prefix): string {
    if(!preg_match('/^[a-z]{2,12}$/',$prefix))throw new InvalidArgumentException('Invalid recovery id prefix.');
    return $prefix.'-'.bin2hex(random_bytes(12));
}

function unmRecoveryCanonicalValue(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('unmRecoveryCanonicalValue',$value);
    ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=unmRecoveryCanonicalValue($item);return $value;
}

function unmRecoveryCanonicalJson(array $value): string {
    return json_encode(unmRecoveryCanonicalValue($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function unmRecoveryPeerSupportsV6(array $peer): bool {
    return (string)($peer['pairingState']??'paired')==='paired'&&unmRecoveryProtocolAvailable((array)($peer['lastCapabilities']??[]));
}

function unmRecoveryPeerSecret(array $peer): string {
    $secret=strtolower(trim((string)($peer['recoverySecret']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$secret))throw new RuntimeException('The paired host has no recovery RPC signing key. Re-test or re-pair it before arming recovery.');
    return $secret;
}

function unmRecoveryBootstrapPayload(array $request): array {
    unset($request['bootstrapSignature']);return $request;
}

function unmRecoveryBootstrapIdentityMatches(array $request,array $peer,string $localHostId): bool {
    return (string)($request['destinationHostId']??'')===$localHostId
        &&(string)($request['senderHostId']??'')===(string)($request['sourceHostId']??'')
        &&(string)($request['sourceHostId']??'')===(string)($peer['hostId']??'')
        &&(string)($request['recipientPeerId']??'')===(string)($peer['id']??'')
        &&(string)($request['senderPeerId']??'')===(string)($peer['reciprocalPeerId']??'')
        &&(string)($request['sourceHostId']??'')!==$localHostId;
}

function unmRecoveryBootstrapReplayDecision(array $seen,string $nonce,string $requestHash): string {
    if(!preg_match('/^[a-f0-9]{32}$/',$nonce)||!preg_match('/^[a-f0-9]{64}$/',$requestHash))return 'invalid';
    if(!array_key_exists($nonce,$seen))return 'new';return hash_equals((string)$seen[$nonce],$requestHash)?'idempotent':'conflict';
}

function unmRecoveryPeerCredentialLock(string $peerId) {
    unmPeerPath($peerId);$path='/var/lock/unmotion-peer-recovery-'.$peerId.'.lock';$lock=fopen($path,'c');if($lock===false)throw new RuntimeException('Unable to open recovery peer-credential lock.');@chmod($path,0600);if(!flock($lock,LOCK_EX)){fclose($lock);throw new RuntimeException('Unable to lock recovery peer credentials.');}return $lock;
}

function unmRecoverySshSign(string $message,string $privateKey,string $identity): string {
    if(!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/',$identity))throw new InvalidArgumentException('Recovery bootstrap signing identity is invalid.');$message="unmotion-recovery-identity:$identity\n".$message;
    $realKey=realpath($privateKey);if($realKey===false||!is_file($realKey)||is_link($privateKey))throw new RuntimeException('Recovery bootstrap signing key is unavailable.');
    $data=tempnam('/tmp','unm-rpc-data-');if($data===false)throw new RuntimeException('Unable to create recovery bootstrap signing input.');$signature=$data.'.sig';
    try{if(file_put_contents($data,$message,LOCK_EX)!==strlen($message)||!chmod($data,0600))throw new RuntimeException('Unable to write recovery bootstrap signing input.');$result=unmRun(['ssh-keygen','-Y','sign','-f',$realKey,'-n','unmotion-recovery',$data],null,15);if($result['code']!==0||!is_file($signature))throw new RuntimeException('Unable to sign recovery bootstrap request: '.trim($result['stderr']));$contents=(string)file_get_contents($signature);if(!str_contains($contents,'BEGIN SSH SIGNATURE')||strlen($contents)>8192)throw new RuntimeException('Recovery bootstrap signature is invalid.');return $contents;}
    finally{@unlink($data);@unlink($signature);}
}

function unmRecoverySshVerify(string $message,string $signature,string $publicKey,string $identity): bool {
    try{$publicKey=unmValidatePublicKey($publicKey);}catch(Throwable $ignored){return false;}if(!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/',$identity)||!str_contains($signature,'BEGIN SSH SIGNATURE')||strlen($signature)>8192)return false;
    $message="unmotion-recovery-identity:$identity\n".$message;
    $allowed=tempnam('/tmp','unm-rpc-allowed-');$sig=tempnam('/tmp','unm-rpc-sig-');if($allowed===false||$sig===false){if(is_string($allowed))@unlink($allowed);if(is_string($sig))@unlink($sig);return false;}
    try{$keyParts=preg_split('/\s+/',trim($publicKey),3)?:[];if(count($keyParts)<2)return false;$line=$identity.' namespaces="unmotion-recovery" '.$keyParts[0].' '.$keyParts[1]."\n";if(file_put_contents($allowed,$line,LOCK_EX)!==strlen($line)||file_put_contents($sig,$signature,LOCK_EX)!==strlen($signature))return false;@chmod($allowed,0600);@chmod($sig,0600);$result=unmRun(['ssh-keygen','-Y','verify','-f',$allowed,'-I',$identity,'-n','unmotion-recovery','-s',$sig],$message,15);return $result['code']===0;}
    finally{@unlink($allowed);@unlink($sig);}
}

function unmRecoveryInstallPeerSecret(array $request): array {
    $sourceHostId=trim((string)($request['sourceHostId']??''));$destinationHostId=trim((string)($request['destinationHostId']??''));
    $senderHostId=trim((string)($request['senderHostId']??''));$secret=strtolower(trim((string)($request['secret']??'')));
    $issuedAt=(int)($request['issuedAt']??0);$expiresAt=(int)($request['expiresAt']??0);$nonce=(string)($request['nonce']??'');$senderPeerId=(string)($request['senderPeerId']??'');$recipientPeerId=(string)($request['recipientPeerId']??'');$signature=(string)($request['bootstrapSignature']??'');
    if($destinationHostId!==unmHostId()||$senderHostId!==$sourceHostId||$sourceHostId===''||$sourceHostId===$destinationHostId)throw new InvalidArgumentException('Recovery signing-key identities are invalid.');
    if(!preg_match('/^[a-f0-9]{64}$/',$secret)||!preg_match('/^[a-f0-9]{32}$/',$nonce))throw new InvalidArgumentException('Recovery signing-key material is invalid.');
    $now=time();if($issuedAt>$now+30||$issuedAt<$now-UNM_RECOVERY_RPC_TTL||$expiresAt<$now||$expiresAt>$issuedAt+UNM_RECOVERY_RPC_TTL)throw new RuntimeException('Recovery signing-key request is expired or has an invalid clock.');
    $peer=unmFindPeerByHostId($sourceHostId);if((string)($peer['pairingState']??'')!=='paired')throw new RuntimeException('Recovery signing keys require a completed reciprocal pairing.');
    if(!unmRecoveryBootstrapIdentityMatches($request,$peer,unmHostId()))throw new RuntimeException('Recovery signing-key request does not match the reciprocal peer identities.');
    if(!unmRecoveryProtocolAvailable((array)($peer['lastCapabilities']??[])))throw new RuntimeException('The paired source has not negotiated recovery protocol 1 over protocol 6.');
    if(!unmRecoverySshVerify(unmRecoveryCanonicalJson(unmRecoveryBootstrapPayload($request)),$signature,(string)($peer['incomingPublicKey']??''),$sourceHostId))throw new RuntimeException('Recovery signing-key bootstrap signature validation failed.');
    $requestHash=hash('sha256',unmRecoveryCanonicalJson(unmRecoveryBootstrapPayload($request)));$credentialLock=unmRecoveryPeerCredentialLock((string)$peer['id']);try{$peer=unmPeer((string)$peer['id']);$nonces=(array)($peer['recoveryBootstrapNonces']??[]);$replay=unmRecoveryBootstrapReplayDecision($nonces,$nonce,$requestHash);if($replay==='conflict'||$replay==='invalid')throw new RuntimeException('Recovery signing-key bootstrap nonce was replayed with different content.');$existing=strtolower(trim((string)($peer['recoverySecret']??'')));if($existing!==''&&!hash_equals($existing,$secret))throw new RuntimeException('The paired host presented a different recovery signing key. Remove and re-pair it to rotate recovery credentials.');$pending=(array)($peer['pendingRecoveryBootstrap']??[]);$pendingSecret=strtolower((string)($pending['secret']??''));if($existing===''&&preg_match('/^[a-f0-9]{64}$/',$pendingSecret)&&!hash_equals($pendingSecret,$secret)&&strcmp(unmHostId(),$sourceHostId)<0)throw new RuntimeException('The deterministic local-host recovery bootstrap takes precedence; retry after reciprocal key installation.');$nonces[$nonce]=$requestHash;if(count($nonces)>32)$nonces=array_slice($nonces,-32,null,true);$peer['recoveryBootstrapNonces']=$nonces;$peer['recoverySecret']=$secret;$peer['recoverySecretInstalledAt']=$peer['recoverySecretInstalledAt']??gmdate('c');unset($peer['pendingRecoveryBootstrap']);unmSavePeerRecord($peer);}finally{unmRecoveryReleaseLock($credentialLock);}
    $ack=['installed'=>true,'sourceHostId'=>$sourceHostId,'destinationHostId'=>$destinationHostId,'nonce'=>$nonce,'hostId'=>unmHostId(),'recoveryProtocolVersion'=>UNM_RECOVERY_PROTOCOL];
    $ack['signature']=hash_hmac('sha256',unmRecoveryCanonicalJson($ack),$secret);return $ack;
}

function unmRecoveryEnsurePeerSecret(array $peer): array {
    $peerId=(string)($peer['id']??'');$lock=unmRecoveryPeerCredentialLock($peerId);
    try{
        $peer=unmPeer($peerId);if(!unmRecoveryPeerSupportsV6($peer))throw new RuntimeException('Recovery requires a reciprocal protocol-6 peer with recovery protocol 1.');if(preg_match('/^[a-f0-9]{64}$/i',(string)($peer['recoverySecret']??'')))return $peer;
        $pending=is_array($peer['pendingRecoveryBootstrap']??null)?$peer['pendingRecoveryBootstrap']:[];$secret=strtolower((string)($pending['secret']??''));if($secret!==''&&!preg_match('/^[a-f0-9]{64}$/',$secret))throw new RuntimeException('Pending recovery signing-key state is invalid; re-pair before recovery.');if($secret==='')$secret=bin2hex(random_bytes(32));
        $request=is_array($pending['request']??null)?$pending['request']:[];$sameIdentity=hash_equals((string)($request['sourceHostId']??''),unmHostId())&&hash_equals((string)($request['destinationHostId']??''),(string)$peer['hostId'])&&hash_equals((string)($request['senderPeerId']??''),$peerId)&&hash_equals((string)($request['recipientPeerId']??''),(string)($peer['reciprocalPeerId']??''))&&hash_equals((string)($request['secret']??''),$secret);$expires=(int)($request['expiresAt']??0);
        if(!$sameIdentity||$expires<time()){$now=time();$request=['sourceHostId'=>unmHostId(),'destinationHostId'=>(string)$peer['hostId'],'senderHostId'=>unmHostId(),'senderPeerId'=>$peerId,'recipientPeerId'=>(string)($peer['reciprocalPeerId']??''),'senderBootId'=>unmRecoveryBootId(),'issuedAt'=>$now,'expiresAt'=>$now+UNM_RECOVERY_RPC_TTL,'nonce'=>bin2hex(random_bytes(16)),'secret'=>$secret];$request['bootstrapSignature']=unmRecoverySshSign(unmRecoveryCanonicalJson($request),(string)($peer['keyPath']??''),unmHostId());}
        $peer['pendingRecoveryBootstrap']=['secret'=>$secret,'request'=>$request,'persistedAt'=>(string)($pending['persistedAt']??gmdate('c')),'lastAttemptAt'=>gmdate('c')];unmSavePeerRecord($peer);
    }finally{unmRecoveryReleaseLock($lock);}
    $encoded=base64_encode(unmRecoveryCanonicalJson($request));$remote=unmRemote($peer,'/usr/local/sbin/unmotion-agent recovery-key-install '.escapeshellarg($encoded),30);
    if($remote['code']!==0){$lock=unmRecoveryPeerCredentialLock($peerId);try{$current=unmPeer($peerId);if(!unmRecoveryPeerSupportsV6($current))throw new RuntimeException('Recovery peer capability or reciprocal pairing changed during signing-key installation.');if(preg_match('/^[a-f0-9]{64}$/i',(string)($current['recoverySecret']??'')))return $current;}finally{unmRecoveryReleaseLock($lock);}throw new RuntimeException('Unable to install the paired recovery signing key: '.(trim($remote['stderr'])?:'unknown error'));}
    $reply=json_decode($remote['stdout'],true);if(!is_array($reply)||empty($reply['installed'])||!hash_equals((string)$peer['hostId'],(string)($reply['hostId']??''))||!hash_equals((string)$request['nonce'],(string)($reply['nonce']??'')))throw new RuntimeException('The paired host returned an invalid recovery signing-key acknowledgement.');$signature=(string)($reply['signature']??'');unset($reply['signature']);if(!preg_match('/^[a-f0-9]{64}$/',$signature)||!hash_equals($signature,hash_hmac('sha256',unmRecoveryCanonicalJson($reply),$secret)))throw new RuntimeException('The paired host recovery signing-key acknowledgement was not authentic.');
    $lock=unmRecoveryPeerCredentialLock($peerId);try{$current=unmPeer($peerId);if(!unmRecoveryPeerSupportsV6($current))throw new RuntimeException('Recovery peer capability or reciprocal pairing changed before signing-key acknowledgement.');$established=strtolower((string)($current['recoverySecret']??''));if($established!==''&&!hash_equals($established,$secret))return $current;$pending=(array)($current['pendingRecoveryBootstrap']??[]);if(!hash_equals($secret,strtolower((string)($pending['secret']??'')))||!hash_equals((string)$request['nonce'],(string)($pending['request']['nonce']??'')))throw new RuntimeException('Pending recovery bootstrap changed before authenticated acknowledgement.');$current['recoverySecret']=$secret;$current['recoverySecretInstalledAt']=gmdate('c');unset($current['pendingRecoveryBootstrap']);unmSavePeerRecord($current);return $current;}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryIdentityFromSource(string $replicationId): array {
    $policy=unmReplicationPolicy($replicationId);$peer=unmPeer((string)$policy['peerId']);
    $identity=['replicationId'=>$replicationId,'vmUuid'=>(string)$policy['vmUuid'],'vmName'=>(string)$policy['vmName'],'sourceHostId'=>unmHostId(),'destinationHostId'=>(string)$policy['peerHostId']];
    if(!hash_equals((string)($peer['hostId']??''),$identity['destinationHostId']))throw new RuntimeException('Replication peer identity no longer matches the recovery policy.');
    return ['identity'=>$identity,'policy'=>$policy,'peer'=>$peer,'path'=>unmRecoverySourcePath($replicationId),'sourceSide'=>true];
}

function unmRecoveryIdentityFromReplica(string $replicationId): array {
    $context=unmRecoveryReplicaContext($replicationId);$manifest=(array)$context['manifest'];$identity=['replicationId'=>$replicationId,'vmUuid'=>(string)$manifest['vmUuid'],'vmName'=>(string)$manifest['vmName'],'sourceHostId'=>(string)$manifest['sourceHostId'],'destinationHostId'=>unmHostId()];
    return ['identity'=>$identity,'manifest'=>$manifest,'peer'=>unmFindPeerByHostId($identity['sourceHostId']),'path'=>$context['dir'].'/recovery.json','dir'=>$context['dir'],'sourceSide'=>false];
}

function unmRecoveryContext(string $replicationId): array {
    unmReplicationPath($replicationId);return is_file(unmReplicationPath($replicationId).'/policy.json')?unmRecoveryIdentityFromSource($replicationId):unmRecoveryIdentityFromReplica($replicationId);
}

function unmRecoverySignEnvelope(array $envelope,string $secret): array {
    unset($envelope['signature']);$envelope['signature']=hash_hmac('sha256',unmRecoveryCanonicalJson($envelope),$secret);return $envelope;
}

function unmRecoveryEnvelope(array $context,array $peer,string $command,int $term,array $payload=[]): array {
    if(!preg_match('/^recovery-[a-z-]+$/',$command)||$command==='recovery-key-install')throw new InvalidArgumentException('Invalid recovery RPC command.');
    $identity=(array)$context['identity'];$now=time();$envelope=['requestId'=>unmRecoveryRandomId('req'),'nonce'=>bin2hex(random_bytes(16)),'issuedAt'=>$now,'expiresAt'=>$now+UNM_RECOVERY_RPC_TTL,'protocolVersion'=>6,'recoveryProtocolVersion'=>UNM_RECOVERY_PROTOCOL,'command'=>$command,'replicationId'=>$identity['replicationId'],'vmUuid'=>$identity['vmUuid'],'sourceHostId'=>$identity['sourceHostId'],'destinationHostId'=>$identity['destinationHostId'],'senderHostId'=>unmHostId(),'senderBootId'=>unmRecoveryBootId(),'term'=>$term,'payload'=>$payload];
    return unmRecoverySignEnvelope($envelope,unmRecoveryPeerSecret($peer));
}

function unmRecoveryVerifyResponse(array $response,array $request,string $secret,string $expectedHostId): array {
    $signature=(string)($response['responseSignature']??'');unset($response['responseSignature']);
    if($expectedHostId===''||!hash_equals($expectedHostId,(string)($response['hostId']??''))||(int)($response['recoveryProtocolVersion']??0)!==UNM_RECOVERY_PROTOCOL||!hash_equals((string)$request['requestId'],(string)($response['requestId']??''))||!preg_match('/^[a-f0-9]{64}$/',$signature)||!hash_equals($signature,hash_hmac('sha256',unmRecoveryCanonicalJson($response),$secret)))throw new RuntimeException('The peer returned an unauthenticated recovery response.');
    if(empty($response['success']))throw new RuntimeException((string)($response['error']??'The peer rejected the recovery request.'));
    return $response;
}

function unmRecoveryRemoteCall(array $context,string $command,int $term,array $payload=[],int $timeout=45): array {
    if(!preg_match('/^recovery-[a-z-]+$/',$command)||$command==='recovery-key-install')throw new InvalidArgumentException('Invalid recovery RPC command.');
    $peer=unmRecoveryEnsurePeerSecret((array)$context['peer']);$request=unmRecoveryEnvelope($context,$peer,$command,$term,$payload);$encoded=base64_encode(unmRecoveryCanonicalJson($request));
    $remote=unmRemote($peer,'/usr/local/sbin/unmotion-agent '.$command.' '.escapeshellarg($encoded),$timeout);
    if($remote['code']!==0)throw new RuntimeException('Recovery RPC '.$command.' failed: '.(trim($remote['stderr'])?:'peer unavailable'));
    $reply=json_decode($remote['stdout'],true);if(!is_array($reply))throw new RuntimeException('Recovery RPC '.$command.' returned invalid JSON.');
    return unmRecoveryVerifyResponse($reply,$request,unmRecoveryPeerSecret($peer),(string)$peer['hostId']);
}

function unmRecoveryRequestContext(array $request,string $command): array {
    if(strlen(unmRecoveryCanonicalJson($request))>65536)throw new InvalidArgumentException('Recovery RPC request exceeds the 64 KiB limit.');
    if((int)($request['protocolVersion']??0)!==6||(int)($request['recoveryProtocolVersion']??0)!==UNM_RECOVERY_PROTOCOL)throw new RuntimeException('Recovery RPC requires protocol 6 and recovery protocol 1.');
    if(!hash_equals($command,(string)($request['command']??'')))throw new RuntimeException('Recovery RPC command is not bound to the signed envelope.');
    foreach(['requestId'=>'/^req-[a-f0-9]{24}$/','nonce'=>'/^[a-f0-9]{32}$/','replicationId'=>'/^repl-[a-f0-9]{24}$/','vmUuid'=>'/^[A-Fa-f0-9-]{32,36}$/'] as $key=>$pattern)if(!preg_match($pattern,(string)($request[$key]??'')))throw new InvalidArgumentException('Invalid recovery RPC '.$key.'.');
    if(!preg_match('/^(?:[a-f0-9-]{32,40}|unknown-boot)$/i',(string)($request['senderBootId']??''))||(int)($request['term']??-1)<0)throw new InvalidArgumentException('Recovery RPC boot identity or term is invalid.');
    $sourceHostId=(string)($request['sourceHostId']??'');$destinationHostId=(string)($request['destinationHostId']??'');$senderHostId=(string)($request['senderHostId']??'');
    if($sourceHostId===''||$destinationHostId===''||$sourceHostId===$destinationHostId||$senderHostId===unmHostId()||!in_array($senderHostId,[$sourceHostId,$destinationHostId],true))throw new InvalidArgumentException('Recovery RPC host identities are invalid.');
    $now=time();$issuedAt=(int)($request['issuedAt']??0);$expiresAt=(int)($request['expiresAt']??0);
    if($issuedAt>$now+30||$issuedAt<$now-UNM_RECOVERY_RPC_TTL||$expiresAt<$now||$expiresAt>$issuedAt+UNM_RECOVERY_RPC_TTL)throw new RuntimeException('Recovery RPC is expired or the peer clock is outside the allowed window.');
    $peer=unmFindPeerByHostId($senderHostId);if(!unmRecoveryPeerSupportsV6($peer))throw new RuntimeException('Recovery RPC sender has not negotiated recovery protocol 1 over protocol 6.');$secret=unmRecoveryPeerSecret($peer);
    $signature=(string)($request['signature']??'');$unsigned=$request;unset($unsigned['signature']);if(!preg_match('/^[a-f0-9]{64}$/',$signature)||!hash_equals($signature,hash_hmac('sha256',unmRecoveryCanonicalJson($unsigned),$secret)))throw new RuntimeException('Recovery RPC signature validation failed.');
    $replicationId=(string)$request['replicationId'];
    if(unmHostId()===$destinationHostId){$context=unmRecoveryIdentityFromReplica($replicationId);if($senderHostId!==$sourceHostId)throw new RuntimeException('Only the source may send this recovery RPC to the destination.');}
    elseif(unmHostId()===$sourceHostId){$context=unmRecoveryIdentityFromSource($replicationId);if($senderHostId!==$destinationHostId)throw new RuntimeException('Only the destination may send this recovery RPC to the source.');}
    else throw new RuntimeException('Recovery RPC targets another host.');
    $identity=(array)$context['identity'];foreach(['replicationId','vmUuid','sourceHostId','destinationHostId'] as $key)if(!hash_equals(strtolower((string)$identity[$key]),strtolower((string)$request[$key])))throw new RuntimeException('Recovery RPC '.$key.' does not match the durable policy identity.');
    $context['peer']=$peer;$context['secret']=$secret;$context['command']=$command;return $context;
}

function unmRecoverySignedResponse(array $request,string $secret,array $payload): array {
    $response=['success'=>true,'requestId'=>(string)$request['requestId'],'hostId'=>unmHostId(),'bootId'=>unmRecoveryBootId(),'recoveryProtocolVersion'=>UNM_RECOVERY_PROTOCOL]+$payload;
    $response['responseSignature']=hash_hmac('sha256',unmRecoveryCanonicalJson($response),$secret);return $response;
}

function unmRecoveryApplyRemoteMutation(array $request,string $command,callable $mutator): array {
    $context=unmRecoveryRequestContext($request,$command);$lock=unmRecoveryAcquireLock((string)$request['replicationId']);
    try{
        $record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],(bool)$context['sourceSide']));$requests=(array)($record['requests']??[]);$hash=hash('sha256',unmRecoveryCanonicalJson($request));$requestId=(string)$request['requestId'];$nonce=(string)$request['nonce'];
        if(isset($requests[$requestId])){if(!hash_equals((string)($requests[$requestId]['requestSha256']??''),$hash))throw new RuntimeException('Recovery request ID was replayed with different content.');$stored=(array)($requests[$requestId]['response']??[]);if(!$stored)throw new RuntimeException('Recovery request replay record is incomplete.');return $stored;}
        foreach($requests as $seen)if(hash_equals((string)($seen['nonce']??''),$nonce))throw new RuntimeException('Recovery request nonce was already used.');
        [$record,$payload]=$mutator($record,$context,(array)($request['payload']??[]),$request);$response=unmRecoverySignedResponse($request,(string)$context['secret'],(array)$payload);
        $requests[$requestId]=['nonce'=>$nonce,'requestSha256'=>$hash,'command'=>$command,'acceptedAt'=>gmdate('c'),'response'=>$response];
        if(count($requests)>128)$requests=array_slice($requests,-128,null,true);$record['requests']=$requests;unmRecoveryStore((string)$context['path'],$record);return $response;
    }finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryDefaultRecord(array $identity,bool $sourceSide): array {
    return unmRecoveryValidateRecord([
        'schemaVersion'=>UNM_RECOVERY_SCHEMA,'protocolVersion'=>6,'recoveryProtocolVersion'=>UNM_RECOVERY_PROTOCOL,'replicationId'=>(string)$identity['replicationId'],'vmUuid'=>(string)$identity['vmUuid'],
        'sourceHostId'=>(string)$identity['sourceHostId'],'destinationHostId'=>(string)$identity['destinationHostId'],'term'=>0,'authority'=>'SOURCE','state'=>'REPLICATION_ONLY',
        'armed'=>false,'managedAutostart'=>false,'desiredAutostart'=>false,'side'=>$sourceSide?'source':'destination','sourceBootId'=>$sourceSide?unmRecoveryBootId():'','destinationBootId'=>$sourceSide?'':unmRecoveryBootId(),'requests'=>[],
    ]);
}

function unmRecoveryNativeAutostart(string $vmUuid): bool {
    if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid VM UUID.');
    $info=unmRun(['virsh','dominfo',$vmUuid],null,15);if($info['code']!==0)throw new RuntimeException('Unable to inspect native VM autostart: '.trim($info['stderr']));
    if(!preg_match('/^Autostart:\s+(enable|disable)/mi',$info['stdout'],$match))throw new RuntimeException('Unable to determine native VM autostart.');
    return strtolower($match[1])==='enable';
}

function unmRecoverySetNativeAutostart(string $vmUuid,bool $enabled): void {
    $result=unmRun($enabled?['virsh','autostart',$vmUuid]:['virsh','autostart','--disable',$vmUuid],null,15);
    if($result['code']!==0)throw new RuntimeException('Unable to update native VM autostart: '.trim($result['stderr']));
    if(unmRecoveryNativeAutostart($vmUuid)!==$enabled)throw new RuntimeException('Native VM autostart did not remain in the required state.');
}

function unmRecoveryVmState(string $vmUuid): string {
    if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid VM UUID.');
    $state=unmRun(['virsh','domstate',$vmUuid],null,15);return $state['code']===0?strtolower(trim($state['stdout'])):'undefined';
}

function unmRecoveryAssertNoDomainConflict(string $vmUuid,string $vmName,bool $allowOwned=false,?array $record=null): void {
    $uuids=unmRun(['virsh','list','--all','--uuid'],null,30);$names=unmRun(['virsh','list','--all','--name'],null,30);
    if($uuids['code']!==0||$names['code']!==0)throw new RuntimeException('Libvirt inventory is unavailable; recovery conflict isolation cannot be verified.');
    $uuidConflict=!unmReplicaVmUuidUndefinedInInventory((string)$uuids['stdout'],$vmUuid);$nameConflict=false;
    foreach(preg_split('/\R/',trim((string)$names['stdout']))?:[] as $name)if($name!==''&&strcasecmp(trim($name),$vmName)===0){$nameConflict=true;break;}
    if($allowOwned&&is_array($record)&&hash_equals((string)($record['activation']['activationId']??''),(string)($record['activationId']??'')))return;
    if($uuidConflict)throw new RuntimeException('Destination already defines a VM with the recovery UUID.');
    if($nameConflict)throw new RuntimeException('Destination already defines a VM named '.$vmName.'.');
}

function unmRecoveryInterlockStatus(): array {
    $startGate='/usr/local/sbin/unmotion-replication-start-gate';$lifecycle='/usr/local/sbin/unmotion-replication-lifecycle';$pidPath='/var/run/unmotion/replication-lifecycle.pid';$pid=(int)trim((string)@file_get_contents($pidPath));$alive=false;
    if($pid>1&&is_readable('/proc/'.$pid.'/cmdline')){$cmd=(string)@file_get_contents('/proc/'.$pid.'/cmdline');$alive=str_contains($cmd,$lifecycle);}
    $reasons=[];if(!is_executable($startGate))$reasons[]='The recovery start gate is not installed.';if(!is_executable($lifecycle))$reasons[]='The libvirt recovery lifecycle monitor is not installed.';if(!$alive)$reasons[]='The libvirt recovery lifecycle monitor is not running.';
    return ['ready'=>!$reasons,'reasons'=>$reasons,'startGate'=>$startGate,'lifecycleMonitor'=>$lifecycle,'lifecyclePid'=>$alive?$pid:0];
}

function unmRecoveryHoldActive(array $record,int $now=0): bool {
    $hold=is_array($record['hold']??null)?$record['hold']:[];if(!$hold)return false;$until=(string)($hold['holdUntil']??'');if($until==='forever')return true;$epoch=strtotime($until);return $epoch!==false&&$epoch>($now?:time());
}

function unmRecoveryPublicRecord(array $record): array {
    unset($record['requests'],$record['nativeAutostartBeforeArm']);
    if(is_array($record['activation']??null)){
        unset($record['activation']['xmlPath'],$record['activation']['activationRoot'],$record['activation']['hostStateBinding'],$record['activation']['intendedState'],$record['activation']['checkpointArchivePath'],$record['activation']['checkpointRetry']);
        foreach((array)($record['activation']['objects']??[]) as $index=>$object)if(is_array($object)){unset($object['mountpoint'],$object['sourceSnapshot']);$record['activation']['objects'][$index]=$object;}
        foreach((array)($record['activation']['installedState']??[]) as $index=>$state)if(is_array($state)){$record['activation']['installedState'][$index]=['kind'=>$state['kind']??'state','present'=>true];}
    }
    return $record;
}

function unmRecoverySourcePolicyActive(array $record): bool {
    $state=strtoupper(trim((string)($record['state']??'UNKNOWN')));return !in_array($state,['REPLICATION_ONLY','DISARMED','REMOVED'],true)||!empty($record['armed'])||isset($record['claim'])||isset($record['activation']);
}

function unmRecoveryPolicySetIsSole(array $active,string $replicationId): bool {
    $active=array_values(array_unique(array_map('strval',$active)));return count($active)===0||(count($active)===1&&hash_equals($replicationId,$active[0]));
}

function unmRecoveryAssertSoleSourcePolicy(string $vmUuid,string $replicationId): void {
    $vmUuid=strtolower(trim($vmUuid));unmReplicationPath($replicationId);if(!preg_match('/^[a-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid recovery VM identity for policy uniqueness.');$active=[];
    foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $policyPath){$raw=@file_get_contents($policyPath);$policy=$raw===false?null:json_decode($raw,true);if(!is_array($policy))throw new RuntimeException('A corrupt source replication policy prevents global recovery authority verification.');if(strcasecmp((string)($policy['vmUuid']??''),$vmUuid)!==0)continue;$id=(string)($policy['id']??basename(dirname($policyPath)));if(!preg_match('/^repl-[a-f0-9]{24}$/',$id)||!hash_equals(basename(dirname($policyPath)),$id))throw new RuntimeException('An ambiguous source replication policy prevents recovery authority verification.');$recoveryPath=dirname($policyPath).'/recovery.json';if(!is_file($recoveryPath))continue;$recoveryRaw=@file_get_contents($recoveryPath);$recovery=$recoveryRaw===false?null:json_decode($recoveryRaw,true);if(!is_array($recovery))throw new RuntimeException('A corrupt source recovery record prevents global VM authority verification.');if(strcasecmp((string)($recovery['vmUuid']??$vmUuid),$vmUuid)!==0)throw new RuntimeException('A source recovery record has a conflicting VM identity.');if(unmRecoverySourcePolicyActive($recovery))$active[]=$id;}
    $active=array_values(array_unique($active));if(!unmRecoveryPolicySetIsSole($active,$replicationId))throw new RuntimeException('Exactly one recovery policy may hold or reconcile authority for a source VM. Conflicting policy: '.implode(', ',$active));
}

function unmRecoveryAssertSoleDestinationPolicy(string $vmUuid,string $replicationId): void {
    $vmUuid=strtolower(trim($vmUuid));unmReplicationPath($replicationId);if(!preg_match('/^[a-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid destination recovery VM identity for policy uniqueness.');$active=[];
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $manifestPath){$manifest=unmLoadJson($manifestPath);if(!$manifest)throw new RuntimeException('A corrupt incoming replica prevents global destination authority verification.');if(strcasecmp((string)($manifest['vmUuid']??''),$vmUuid)!==0)continue;$id=(string)($manifest['replicationId']??basename(dirname($manifestPath)));if(!preg_match('/^repl-[a-f0-9]{24}$/',$id)||!hash_equals(basename(dirname($manifestPath)),$id))throw new RuntimeException('An ambiguous incoming replica prevents destination recovery authority verification.');$recoveryPath=dirname($manifestPath).'/recovery.json';if(!is_file($recoveryPath))continue;$record=unmLoadJson($recoveryPath);if(!$record)throw new RuntimeException('A corrupt destination recovery record prevents global VM authority verification.');if(unmRecoverySourcePolicyActive($record))$active[]=$id;}
    $active=array_values(array_unique($active));if(!unmRecoveryPolicySetIsSole($active,$replicationId))throw new RuntimeException('Exactly one incoming recovery policy may hold or reconcile authority for a destination VM. Conflicting policy: '.implode(', ',$active));
}

function unmRecoveryAssertLegacyVmAvailable(string $vmUuid,string $operation): void {
    $vmUuid=strtolower(trim($vmUuid));if(!preg_match('/^[a-f0-9-]{32,36}$/',$vmUuid))throw new InvalidArgumentException('Invalid VM identity for recovery interlock.');foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $policyPath){$policy=unmLoadJson($policyPath);if(!$policy)throw new RuntimeException('A corrupt replication policy blocks '.$operation.'.');if(strcasecmp((string)($policy['vmUuid']??''),$vmUuid)!==0)continue;$recoveryPath=dirname($policyPath).'/recovery.json';if(!is_file($recoveryPath))continue;$record=unmLoadJson($recoveryPath);if(!$record||unmRecoverySourcePolicyActive($record))throw new RuntimeException($operation.' is blocked while this VM has armed or unreconciled recovery authority.');}
    $dump=unmRun(['virsh','dumpxml',$vmUuid,'--inactive'],null,15);if($dump['code']===0&&str_contains((string)$dump['stdout'],'urn:unmotion:recovery:1'))throw new RuntimeException($operation.' is blocked for an activation-owned recovered VM.');
}

function unmRecoverySourceWorkerAuthority(string $replicationId,string $vmUuid): array {
    try{$context=unmRecoveryIdentityFromSource($replicationId);if(strcasecmp((string)$context['identity']['vmUuid'],$vmUuid)!==0)throw new RuntimeException('Recovery policy VM identity does not match.');$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],true));unmRecoveryAssertSoleSourcePolicy($vmUuid,$replicationId);}
    catch(Throwable $e){return ['allowed'=>false,'reason'=>$e->getMessage(),'term'=>null,'authority'=>'UNKNOWN','state'=>'UNKNOWN','record'=>[]];}
    $summary=['term'=>(int)$record['term'],'authority'=>(string)$record['authority'],'state'=>(string)$record['state'],'record'=>unmRecoveryPublicRecord($record)];
    if(empty($record['armed']))return ['allowed'=>true,'reason'=>'Recovery is not armed.']+$summary;
    if((string)$record['authority']!=='SOURCE')return ['allowed'=>false,'reason'=>'The source host does not hold recovery authority.']+$summary;
    if(!in_array((string)$record['state'],['STANDBY','HOLDOFF'],true))return ['allowed'=>false,'reason'=>'Recovery state '.$record['state'].' blocks source replication.']+$summary;
    return ['allowed'=>true,'reason'=>'Source authority is current.']+$summary;
}

function unmRecoveryAssertReplicaMutationAllowed(string $sourceHostId,string $replicationId): void {
    $path=unmReplicaPath($sourceHostId,$replicationId).'/recovery.json';if(!is_file($path))return;$record=unmRecoveryLoad($path);
    if(empty($record['armed']))return;if((string)$record['authority']!=='SOURCE'||!in_array((string)$record['state'],['STANDBY','HOLDOFF'],true))throw new RuntimeException('Replica publish/prune is fenced while recovery authority or activation state is unresolved.');
}

function unmRecoveryLifecycleFence(string $replicationId,string $vmUuid,string $state,string $reason): array {
    $state=unmRecoveryValidateState($state);if(!in_array($state,['SPLIT_BRAIN_SUSPECTED','SPLIT_BRAIN_FENCING','SPLIT_BRAIN_UNRESOLVED'],true))throw new InvalidArgumentException('Lifecycle fencing may only enter split-brain safety states.');
    $context=unmRecoveryIdentityFromSource($replicationId);if(strcasecmp((string)$context['identity']['vmUuid'],$vmUuid)!==0)throw new RuntimeException('Lifecycle fence VM identity does not match the recovery policy.');
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],true));if(empty($record['armed']))throw new RuntimeException('Recovery is not armed for this VM.');
        $current=(string)$record['state'];if($state==='SPLIT_BRAIN_FENCING'&&!in_array($current,['SPLIT_BRAIN_SUSPECTED','SPLIT_BRAIN_FENCING'],true))throw new RuntimeException('Split-brain fencing must follow a durable suspicion record.');
        if($state==='SPLIT_BRAIN_UNRESOLVED'&&!in_array($current,['SPLIT_BRAIN_FENCING','SPLIT_BRAIN_UNRESOLVED'],true))throw new RuntimeException('Unresolved split brain must follow a durable fencing attempt.');
        $record=unmRecoveryTransition($record,$state,['authority'=>'UNKNOWN','lastFenceReason'=>substr(trim($reason),0,512),'lastFenceAt'=>gmdate('c')]);return unmRecoveryStore((string)$context['path'],$record);
    }finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryCheckpointChain(array $checkpoint): array {
    $out=[];$depth=0;
    while($checkpoint&&$depth++<2){$out[]=$checkpoint;$checkpoint=is_array($checkpoint['safeFallback']??null)?$checkpoint['safeFallback']:[];}
    return $out;
}

function unmRecoveryPointRequiresState(array $point): array {
    $checkpoint=is_array($point['hostState']??null)?$point['hostState']:[];
    $xmlPath=(string)($point['sourceXmlPath']??'');$xmlHash=strtolower((string)($point['sourceXmlSha256']??''));
    if($xmlPath!==''&&!is_link($xmlPath)&&is_file($xmlPath)&&preg_match('/^[a-f0-9]{64}$/',$xmlHash)&&hash_equals($xmlHash,(string)hash_file('sha256',$xmlPath))){
        $xml=(string)file_get_contents($xmlPath);
        return ['tpm'=>(bool)preg_match('~<tpm\b~i',$xml),'nvram'=>(bool)preg_match('~<nvram\b~i',$xml)];
    }
    return ['tpm'=>!empty($checkpoint['tpmPresent']),'nvram'=>!empty($checkpoint['nvramPresent'])];
}

function unmRecoveryCheckpoints(array $points): array {
    $out=[];
    foreach($points as $point){if(!is_array($point))continue;$pointId=(string)($point['id']??'');$pointTime=unmReplicationPointTimestamp($point);
        foreach(['hostState','tpm','nvram'] as $key){if(!is_array($point[$key]??null))continue;foreach(unmRecoveryCheckpointChain($point[$key]) as $checkpoint){
            $id=(string)($checkpoint['checkpointId']??'');if($id===''||isset($out[$id]))continue;
            $out[$id]=$checkpoint+['pointId'=>$pointId,'pointTimestamp'=>$pointTime,'checkpointKind'=>$key];
        }}
    }
    return array_values($out);
}

function unmRecoveryRecommendCheckpoint(array $points,string $pointId): array {
    $chosen=null;foreach($points as $point)if(is_array($point)&&hash_equals((string)($point['id']??''),$pointId)){$chosen=$point;break;}
    if(!is_array($chosen))throw new RuntimeException('Recovery point not found.');$required=unmRecoveryPointRequiresState($chosen);$chosenTime=unmReplicationPointTimestamp($chosen);
    if(!$required['tpm']&&!$required['nvram'])return ['required'=>false,'recommended'=>null,'candidates'=>[],'reason'=>'No TPM or NVRAM checkpoint is required.'];
    $candidates=[];
    foreach(unmRecoveryCheckpoints($points) as $checkpoint){
        $quality=(string)($checkpoint['quality']??'missing');$compatible=(bool)($checkpoint['tpmPresent']??false)===$required['tpm']&&(bool)($checkpoint['nvramPresent']??false)===$required['nvram'];
        $covered=(!$required['tpm']||!empty($checkpoint['tpmCaptured']))&&(!$required['nvram']||!empty($checkpoint['nvramCaptured']))&&!empty($checkpoint['transferred']);
        if(!$compatible||!$covered||!in_array($quality,['safe','best-effort'],true))continue;
        $captured=strtotime((string)($checkpoint['capturedAt']??''));if($captured===false)$captured=(int)($checkpoint['pointTimestamp']??0);
        $exact=hash_equals((string)($checkpoint['pointId']??''),$pointId);$delta=$captured-$chosenTime;
        $rank=$quality==='safe'?($exact?0:($delta<=0?1:2)):($exact?3:4);
        $checkpoint['quality']=$quality;$checkpoint['compatible']=true;$checkpoint['timeDeltaSeconds']=$delta;$checkpoint['rank']=$rank;$candidates[]=$checkpoint;
    }
    usort($candidates,static fn(array $a,array $b):int=>($a['rank']<=>$b['rank'])?:((int)abs($a['timeDeltaSeconds'])<=>(int)abs($b['timeDeltaSeconds']))?:((int)$a['timeDeltaSeconds']<=>(int)$b['timeDeltaSeconds'])?:strcmp((string)$a['checkpointId'],(string)$b['checkpointId']));
    return ['required'=>true,'recommended'=>$candidates[0]??null,'candidates'=>$candidates,'reason'=>$candidates?'The safest compatible checkpoint closest to the disk point is recommended.':'No usable compatible TPM/NVRAM checkpoint exists.'];
}

function unmRecoveryPointEligibility(array $manifest,array $point,int $now=0,bool $verifyStorage=false): array {
    $now=$now?:time();$reasons=[];$pointId=(string)($point['id']??'');
    if($pointId===''||(string)($point['state']??'')!=='AVAILABLE')$reasons[]='The recovery point is not available.';
    if(!empty($point['replicationOnly']))$reasons[]='The point is marked replication-only.';
    if(!in_array((string)($point['consistency']??''),['powered-off','filesystem-quiesced'],true))$reasons[]='The point is only crash-consistent.';
    $guest=is_array($point['guestAgent']??null)?$point['guestAgent']:[];$observed=strtotime((string)($guest['observedAt']??''));
    if(empty($guest['verified'])||$observed===false)$reasons[]='The point has no verified QEMU Guest Agent observation.';
    elseif(abs(unmReplicationPointTimestamp($point)-$observed)>86400)$reasons[]='The Guest Agent observation is too far from the capture time.';
    if(!is_array($guest['network']??null)||!unmRecoveryGuestIps((array)($guest['network']??[])))$reasons[]='The Guest Agent did not report a usable VM address.';
    $xmlPath=(string)($point['sourceXmlPath']??'');$xmlHash=strtolower((string)($point['sourceXmlSha256']??''));$stateDirectory=(string)($manifest['stateDirectory']??'');
    $stateReal=realpath($stateDirectory);$xmlReal=realpath($xmlPath);
    if(!preg_match('/^[a-f0-9]{64}$/',$xmlHash)||$stateReal===false||$xmlReal===false||dirname($xmlReal)!==$stateReal||!is_file($xmlReal)||is_link($xmlPath)||!hash_equals($xmlHash,(string)hash_file('sha256',$xmlReal)))$reasons[]='The source XML recovery material failed exact path or hash validation.';
    try{$recommendation=unmRecoveryRecommendCheckpoint((array)($manifest['points']??[]),$pointId);if($recommendation['required']&&!$recommendation['recommended'])$reasons[]='No compatible TPM/NVRAM checkpoint is available.';}catch(Throwable $e){$recommendation=['required'=>true,'recommended'=>null,'candidates'=>[],'reason'=>$e->getMessage()];$reasons[]=$e->getMessage();}
    if($verifyStorage)foreach((array)($point['storage']??[]) as $item){
        if(!is_array($item)){$reasons[]='Recovery storage metadata is invalid.';continue;}$full=(string)($item['destination']??'').'@'.(string)($item['snapshot']??'');
        $guid=unmRun(['zfs','get','-H','-o','value','guid',$full],null,15);if($guid['code']!==0||!hash_equals(trim((string)($item['guid']??'')),trim($guid['stdout'])))$reasons[]='A recovery snapshot is missing or has a different GUID: '.$full;
        elseif(!in_array('unmotion:replication:'.(string)($manifest['replicationId']??''),unmReplicaSnapshotHoldTags($full),true))$reasons[]='A recovery snapshot lost its exact unMotion hold: '.$full;
    }
    return ['eligible'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'point'=>$point,'recommendation'=>$recommendation];
}

function unmRecoveryGuestIps(array $network): array {
    $ips=[];$walk=function(mixed $value)use(&$walk,&$ips):void{
        if(is_array($value)){foreach($value as $item)$walk($item);return;}if(!is_string($value))return;
        $candidate=trim(explode('/',trim($value),2)[0]);if(!filter_var($candidate,FILTER_VALIDATE_IP))return;$packed=@inet_pton($candidate);if($packed===false)return;
        if(strlen($packed)===4){$first=ord($packed[0]);$second=ord($packed[1]);if($candidate==='0.0.0.0'||$first===127||($first===169&&$second===254)||($first>=224&&$first<=239))return;}
        elseif(strlen($packed)===16){if($packed===str_repeat("\0",16)||$packed===str_repeat("\0",15)."\1"||ord($packed[0])===255||(ord($packed[0])===254&&(ord($packed[1])&192)===128))return;}
        $ips[]=$candidate;
    };
    $walk($network);return array_values(array_unique($ips));
}

function unmRecoveryEvidenceReady(array $samples,int $now=0): bool {
    $now=$now?:time();$ordered=[];
    foreach($samples as $sample){if(!is_array($sample))continue;$at=(int)($sample['at']??0);if($at<=0||$at>$now||$at<$now-UNM_RECOVERY_EVIDENCE_TTL)continue;$ordered[]=$sample+['at'=>$at];}
    usort($ordered,static fn(array $a,array $b):int=>$a['at']<=>$b['at']);$valid=[];
    foreach($ordered as $sample){
        $passed=!empty($sample['sourceSilent'])&&!empty($sample['guestSilent'])&&!empty($sample['gatewayReachable'])&&!empty($sample['dnsResolvable'])&&(!array_key_exists('externalReachable',$sample)||$sample['externalReachable']!==false);
        if(!$passed){$valid=[];continue;}$valid[(int)$sample['at']]=true;
    }
    $times=array_keys($valid);sort($times,SORT_NUMERIC);return count($times)>=3&&($times[array_key_last($times)]-$times[0])>=30&&$now-$times[array_key_last($times)]<=15;
}

function unmRecoveryPublicCheckpoint(?array $checkpoint): ?array {
    if(!$checkpoint)return null;unset($checkpoint['archivePath'],$checkpoint['safeFallback']);return $checkpoint;
}

function unmRecoveryOperation(string $replicationId): array {
    unmReplicationPath($replicationId);return unmLoadJson(UNM_RECOVERY_RUNTIME_DIR.'/'.$replicationId.'.json');
}

function unmRecoveryActionFlags(array $record,string $direction,array $points=[]): array {
    $state=(string)($record['state']??'REPLICATION_ONLY');$armed=!empty($record['armed']);$authority=(string)($record['authority']??'SOURCE');$hold=unmRecoveryHoldActive($record);
    if($direction==='source'){$armRetry=$armed&&in_array($state,['ARMING','FENCED'],true)&&preg_match('/^arm-[a-f0-9]{24}$/',(string)($record['armTransactionId']??''));$disarmRetry=$armed&&in_array($state,['DISARMING','FENCED'],true)&&preg_match('/^disarm-[a-f0-9]{24}$/',(string)($record['disarmTransactionId']??''));$holdRetry=$armed&&$state==='HOLDOFF'&&isset($record['hold'])&&empty($record['hold']['acknowledged']);return ['canArm'=>(!$armed&&$state==='REPLICATION_ONLY')||$armRetry,'canResumeArm'=>$armRetry,'canDisarm'=>($armed&&$state==='STANDBY'&&$authority==='SOURCE'&&!$hold&&empty($record['claim'])&&empty($record['activation']))||$disarmRetry,'canResumeDisarm'=>$disarmRetry,'canHold'=>($armed&&$state==='STANDBY'&&$authority==='SOURCE')||$holdRetry,'canResumeHold'=>$holdRetry,'canReleaseHold'=>$armed&&$state==='HOLDOFF'&&isset($record['hold']),'canGatherEvidence'=>false,'canActivate'=>false,'canStartActivation'=>false,'canRetryCheckpoint'=>false,'canStopActivation'=>false,'canRemoveActivation'=>false,'canFailbackPreflight'=>false];}
    $eligible=(bool)array_filter($points,static fn(array $point):bool=>!empty($point['eligible']));$activation=is_array($record['activation']??null)?$record['activation']:[];$vmState=(string)($activation['vmState']??'');
    $grantRetry=$armed&&in_array($state,['EVIDENCE_GATHERING','FENCED'],true)&&empty($record['claim']['evidenceReady'])&&preg_match('/^grant-[a-f0-9]{24}$/',(string)($record['claim']['grantTransactionId']??''));$claimExpires=strtotime((string)($record['claim']['expiresAt']??''));$claimFresh=$claimExpires!==false&&$claimExpires>time();$canRenew=$armed&&$state==='RECOVERY_READY'&&$authority==='DESTINATION'&&!empty($record['claim']['evidenceReady'])&&empty($record['activation'])&&($claimExpires===false||$claimExpires<=time()+60);return ['canArm'=>false,'canDisarm'=>false,'canHold'=>false,'canReleaseHold'=>false,'canGatherEvidence'=>($armed&&$state==='STANDBY'&&$authority==='SOURCE'&&!$hold&&$eligible)||$grantRetry,'canResumeGrant'=>$grantRetry,'canRenewClaim'=>$canRenew,'canActivate'=>$armed&&$state==='RECOVERY_READY'&&$authority==='DESTINATION'&&!empty($record['claim']['evidenceReady'])&&$claimFresh,'canStartActivation'=>$authority==='DESTINATION'&&$state==='RECOVERED_STOPPED'&&$vmState!=='running','canRetryCheckpoint'=>$authority==='DESTINATION'&&in_array($state,['RECOVERY_BOOT_FAILED','RECOVERED_STOPPED'],true)&&$vmState!=='running','canStopActivation'=>$authority==='DESTINATION'&&in_array($state,['ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'],true)&&$vmState==='running','canRemoveActivation'=>$authority==='DESTINATION'&&in_array($state,['RECOVERY_BOOT_FAILED','RECOVERED_STOPPED'],true)&&$vmState!=='running','canFailbackPreflight'=>$authority==='DESTINATION'&&$state==='RECOVERED_STOPPED'&&$vmState!=='running'];
}

function unmRecoveryReconcileActivationState(string $replicationId,array $context): array {
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],false));if((string)($record['authority']??'')!=='DESTINATION'||!isset($record['activation'])||!preg_match('/^act-[a-f0-9]{24}$/',(string)($record['activationId']??'')))return $record;$activationId=(string)$record['activationId'];$uuid=(string)$context['identity']['vmUuid'];$actual=unmRecoveryVmState($uuid);$owned=$actual==='undefined'?false:unmRecoveryDomainOwned($uuid,$replicationId,$activationId);$state=(string)$record['state'];$phase=(string)($record['activation']['phase']??'');
        if($actual!=='undefined'&&!$owned){$record=unmRecoveryTransition($record,'SPLIT_BRAIN_UNRESOLVED',['authority'=>'UNKNOWN','lastError'=>'A domain with this recovery UUID is not owned by the durable activation journal.']);return unmRecoveryStore((string)$context['path'],$record);}
        if(in_array($actual,['running','idle','paused'],true)){if($phase==='RUNNING'&&$state==='RECOVERED_RUNNING')return $record;try{unmRecoveryStopDomain($uuid,10,true);$actual='shut off';$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['lastError'=>'An interrupted or unauthorized activation start was fenced before QEMU Guest Agent health could be certified.']);}catch(Throwable $e){$record=unmRecoveryTransition($record,'SPLIT_BRAIN_UNRESOLVED',['authority'=>'UNKNOWN','lastError'=>'Unexpected exact-owned activation could not be fenced during reconciliation: '.$e->getMessage()]);return unmRecoveryStore((string)$context['path'],$record);}}
        if($actual==='shut off'){$installed=[];foreach((array)($record['activation']['intendedState']??[]) as $item){if(!is_array($item)||!hash_equals($activationId,(string)($item['activationId']??'')))continue;$path=(string)($item['path']??'');$kind=(string)($item['kind']??'');if(unmRecoveryStateDestinationAllowed($path,$kind,$uuid)&&(is_file($path)||is_dir($path))&&!is_link($path)){$item['contentSha256']=unmRecoveryPathContentHash($path);unset($item['pending']);$installed[]=$item;}}if($installed)$record['activation']['installedState']=$installed;if(in_array($state,['ACTIVATING','RECOVERED_RUNNING'],true))$record=unmRecoveryTransition($record,'RECOVERED_STOPPED');$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['vmState']='shut off';return unmRecoveryStore((string)$context['path'],$record);}
        if($actual==='undefined'&&$state==='ACTIVATING'){$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['lastError'=>'Activation transaction was interrupted before an exact owned domain was defined.']);$record['activation']['vmState']='undefined';$record['activation']['partial']=true;return unmRecoveryStore((string)$context['path'],$record);}return $record;
    }finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryExactStartingWorkerLive(string $replicationId): bool {
    $operation=unmRecoveryOperation($replicationId);$operationId=(string)($operation['operationId']??'');$pid=(int)($operation['pid']??0);if((string)($operation['state']??'')!=='RUNNING'||!in_array((string)($operation['type']??''),['activate','retry-checkpoint'],true)||!preg_match('/^op-[a-f0-9]{24}$/',$operationId)||$pid<2||!is_readable('/proc/'.$pid.'/cmdline'))return false;$parts=explode("\0",(string)@file_get_contents('/proc/'.$pid.'/cmdline'));for($index=0;$index+2<count($parts);$index++)if($parts[$index]===UNM_RECOVERY_WORKER&&$parts[$index+1]===$replicationId&&$parts[$index+2]===$operationId)return true;return false;
}

function unmRecoveryLifecycleReconcileDestination(string $replicationId): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$uuid=strtolower((string)$context['identity']['vmUuid']);$vmLock=unmRecoveryAcquireVmLock($uuid);$policyLock=null;
    try{$policyLock=unmRecoveryAcquireLock($replicationId);$context=unmRecoveryIdentityFromReplica($replicationId);if(!hash_equals($uuid,strtolower((string)$context['identity']['vmUuid'])))throw new RuntimeException('Destination recovery identity changed during lifecycle reconciliation.');$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],false));$activation=(array)($record['activation']??[]);$activationId=(string)($record['activationId']??'');$base=['replicationId'=>$replicationId,'vmUuid'=>$uuid,'activationId'=>$activationId,'state'=>(string)$record['state']];if(!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!hash_equals($activationId,(string)($activation['activationId']??'')))return ['action'=>'noop']+$base;
        $actual=unmRecoveryVmState($uuid);$owned=$actual==='undefined'?false:unmRecoveryDomainOwned($uuid,$replicationId,$activationId);if($actual!=='undefined'&&!$owned)return ['action'=>'foreign-domain','actualState'=>$actual]+$base;
        if(in_array($actual,['running','idle','paused'],true)){
            if((string)$record['authority']==='DESTINATION'&&(string)$record['state']==='RECOVERED_RUNNING'&&(string)($activation['phase']??'')==='RUNNING')return ['action'=>'authorized-running','actualState'=>$actual]+$base;
            if((string)$record['authority']==='DESTINATION'&&(string)($activation['phase']??'')==='STARTING'&&unmRecoveryExactStartingWorkerLive($replicationId))return ['action'=>'worker-starting','actualState'=>$actual]+$base;
            $record=unmRecoveryTransition($record,'SPLIT_BRAIN_SUSPECTED',['authority'=>'UNKNOWN','lastError'=>'Lifecycle observed an exact-owned recovery VM running without a completed RUNNING/QGA journal.']);unmRecoveryStore((string)$context['path'],$record);$record=unmRecoveryTransition($record,'SPLIT_BRAIN_FENCING',['authority'=>'UNKNOWN']);unmRecoveryStore((string)$context['path'],$record);
            try{unmRecoveryStopDomain($uuid,20,true);$record=unmRecoveryTransition($record,'RECOVERED_STOPPED',['authority'=>'DESTINATION']);$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['vmState']='shut off';$record['activation']['stoppedAt']=gmdate('c');unmRecoveryStore((string)$context['path'],$record);return ['action'=>'fenced-stopped','actualState'=>'shut off']+$base;}catch(Throwable $e){$record=unmRecoveryTransition($record,'SPLIT_BRAIN_UNRESOLVED',['authority'=>'UNKNOWN','lastError'=>'Lifecycle could not fence an unauthorized exact-owned recovery VM: '.$e->getMessage()]);unmRecoveryStore((string)$context['path'],$record);throw $e;}
        }
        if($actual==='shut off'&&$owned&&in_array((string)$record['state'],['ACTIVATING','RECOVERED_RUNNING','SPLIT_BRAIN_SUSPECTED','SPLIT_BRAIN_FENCING'],true)){if((string)$record['state']==='SPLIT_BRAIN_SUSPECTED'){$record=unmRecoveryTransition($record,'SPLIT_BRAIN_FENCING',['authority'=>'UNKNOWN']);unmRecoveryStore((string)$context['path'],$record);}$record=unmRecoveryTransition($record,'RECOVERED_STOPPED',['authority'=>'DESTINATION']);$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['vmState']='shut off';unmRecoveryStore((string)$context['path'],$record);return ['action'=>'reconciled-stopped','actualState'=>'shut off']+$base;}
        if($actual==='undefined'&&in_array((string)$record['state'],['ACTIVATING','RECOVERED_RUNNING'],true)){$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['authority'=>'DESTINATION','lastError'=>'Lifecycle found no exact owned domain for an incomplete activation.']);$record['activation']['vmState']='undefined';$record['activation']['partial']=true;unmRecoveryStore((string)$context['path'],$record);return ['action'=>'reconciled-stopped','actualState'=>'undefined']+$base;}
        return ['action'=>'noop','actualState'=>$actual]+$base;
    }finally{if(is_resource($policyLock))unmRecoveryReleaseLock($policyLock);unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryStatus(string $replicationId): array {
    unmReplicationPath($replicationId);$sourcePolicyPath=unmReplicationPath($replicationId).'/policy.json';
    if(is_file($sourcePolicyPath)){
        $policy=unmReplicationPolicy($replicationId);$identity=['replicationId'=>$replicationId,'vmUuid'=>(string)$policy['vmUuid'],'sourceHostId'=>unmHostId(),'destinationHostId'=>(string)$policy['peerHostId']];
        $record=unmRecoveryLoad(unmRecoverySourcePath($replicationId),unmRecoveryDefaultRecord($identity,true));$public=unmRecoveryPublicRecord($record);$peer=unmPeer((string)$policy['peerId']);$interlocks=unmRecoveryInterlockStatus();$armReasons=[];$armWarnings=[];
        $armRetry=!empty($record['armed'])&&in_array((string)$record['state'],['ARMING','FENCED'],true)&&preg_match('/^arm-[a-f0-9]{24}$/',(string)($record['armTransactionId']??''));if(!unmRecoveryPeerSupportsV6($peer))$armReasons[]='The paired destination has not negotiated protocol 6 and recovery protocol 1.';if(empty($interlocks['ready']))$armReasons=array_merge($armReasons,(array)$interlocks['reasons']);if(((string)$record['state']!=='REPLICATION_ONLY'||!empty($record['armed']))&&!$armRetry)$armReasons[]='Recovery is already armed or requires reconciliation.';
        try{$vm=unmParseVm((string)$policy['vmUuid']);if(!empty($vm['pci'])||!empty($vm['usb']))$armReasons[]='Recovery beta2 does not support PCI or USB host-device passthrough.';if(strtolower(trim((string)($vm['state']??'')))!=='shut off')$armReasons[]='Power off the source VM before arming recovery; beta2 does not grandfather a running VM through the startup fence.';if(!empty($vm['tpm']))$armWarnings[]='Virtual TPM recovery is best effort unless a safe powered-off checkpoint is available.';}catch(Throwable $e){$armReasons[]=$e->getMessage();}
        $nativeAutostart=null;try{$nativeAutostart=unmRecoveryNativeAutostart((string)$policy['vmUuid']);}catch(Throwable $e){$armReasons[]='Native VM autostart could not be inspected: '.$e->getMessage();}
        $arm=['ready'=>!$armReasons,'resumeTransaction'=>$armRetry,'reasons'=>array_values(array_unique($armReasons)),'warnings'=>array_values(array_unique($armWarnings)),'nativeAutostart'=>$nativeAutostart,'suggestedDesiredAutostart'=>$armRetry?!empty($record['desiredAutostart']):$nativeAutostart===true,'witnessSupported'=>false,'evidenceModes'=>unmRecoveryEvidenceModes(),'automaticFailover'=>false,'interlocks'=>$interlocks];
        return ['direction'=>'source','recovery'=>$public,'policy'=>['id'=>$replicationId,'vmUuid'=>$policy['vmUuid'],'vmName'=>$policy['vmName'],'peerId'=>$policy['peerId'],'peerHostId'=>$policy['peerHostId']],'points'=>[],'arm'=>$arm,'actions'=>unmRecoveryActionFlags($record,'source'),'operation'=>unmRecoveryOperation($replicationId)?:null];
    }
    $context=unmRecoveryReplicaContext($replicationId);$manifest=(array)$context['manifest'];$identity=['replicationId'=>$replicationId,'vmUuid'=>(string)$manifest['vmUuid'],'sourceHostId'=>(string)$manifest['sourceHostId'],'destinationHostId'=>unmHostId()];
    $record=unmRecoveryLoad($context['dir'].'/recovery.json',unmRecoveryDefaultRecord($identity,false));$points=[];
    foreach((array)($manifest['points']??[]) as $point){if(!is_array($point))continue;$eligibility=unmRecoveryPointEligibility($manifest,$point,time(),false);$recommendation=(array)$eligibility['recommendation'];
        $points[]=['id'=>$point['id']??'','capturedAt'=>$point['capturedAt']??null,'consistency'=>$point['consistency']??null,'state'=>$point['state']??null,'eligible'=>$eligibility['eligible'],'reasons'=>$eligibility['reasons'],'recommendedCheckpoint'=>unmRecoveryPublicCheckpoint(is_array($recommendation['recommended']??null)?$recommendation['recommended']:null),'checkpoints'=>array_map('unmRecoveryPublicCheckpoint',(array)($recommendation['candidates']??[]))];
    }
    return ['direction'=>'destination','recovery'=>unmRecoveryPublicRecord($record),'replica'=>['replicationId'=>$replicationId,'vmUuid'=>$manifest['vmUuid'],'vmName'=>$manifest['vmName'],'sourceHostId'=>$manifest['sourceHostId']],'points'=>$points,'arm'=>['ready'=>false,'reasons'=>['Recovery is armed from the source host.'],'warnings'=>[],'witnessSupported'=>false,'evidenceModes'=>unmRecoveryEvidenceModes(),'automaticFailover'=>false],'actions'=>unmRecoveryActionFlags($record,'destination',$points),'operation'=>unmRecoveryOperation($replicationId)?:null];
}

function unmRecoveryStartAuthorization(array $record,string $sourceBootId,string $destinationHostId,string $destinationBootId): array {
    $term=(int)($record['term']??0);if($term<1)throw new RuntimeException('A positive recovery term is required for source-start authorization.');
    if(!preg_match('/^(?:[a-f0-9-]{32,40}|unknown-boot)$/i',$sourceBootId)||!preg_match('/^(?:[a-f0-9-]{16,80}|unknown-boot)$/i',$destinationBootId))throw new RuntimeException('Recovery start authorization boot identity is invalid.');
    $now=time();return ['requestId'=>unmRecoveryRandomId('start'),'term'=>$term,'sourceBootId'=>strtolower($sourceBootId),'destinationHostId'=>$destinationHostId,'destinationBootId'=>strtolower($destinationBootId),'issuedAt'=>gmdate('c',$now),'expiresAt'=>gmdate('c',$now+240)];
}

function unmRecoveryAssertRemoteTerm(array $record,array $request,bool $allowNext=false): int {
    $current=(int)($record['term']??0);$received=(int)($request['term']??-1);
    if($received===$current||($allowNext&&$received===$current+1))return $received;
    throw new RuntimeException('Recovery RPC term is stale or skips the durable authority generation.');
}

function unmRecoveryGrantMatches(array $record,array $payload,int $requestTerm): bool {
    $claim=(array)($record['claim']??[]);$proposed=(int)($payload['proposedTerm']??0);return $requestTerm===$proposed-1&&(int)($record['term']??0)===$proposed&&(string)($record['authority']??'')==='DESTINATION'&&in_array((string)($record['state']??''),['SOURCE_START_FENCED','FENCED'],true)&&hash_equals((string)($record['activationId']??''),(string)($payload['activationId']??''))&&hash_equals((string)($claim['activationId']??''),(string)($payload['activationId']??''))&&hash_equals((string)($claim['pointId']??''),(string)($payload['pointId']??''))&&hash_equals((string)($claim['checkpointId']??''),(string)($payload['checkpointId']??''))&&hash_equals((string)($claim['selectionHash']??''),(string)($payload['selectionHash']??''))&&hash_equals((string)($claim['grantTransactionId']??''),(string)($payload['grantTransactionId']??''));
}

function unmRecoveryAssertSourceReplicationIdle(string $replicationId): void {
    $durable=unmReplicationDurableState($replicationId);$runtime=unmLoadJson(unmReplicationRuntimePath($replicationId));if(unmReplicationStateIsActive((string)($durable['state']??''))||unmReplicationStateIsActive((string)($runtime['state']??''))||unmReplicationRuntimeWorkerAlive($replicationId,$runtime))throw new RuntimeException('A source replication transaction is active; recovery authority cannot be granted.');
}

function unmRecoveryTransactionId(string $prefix,string $value): string {
    if(!preg_match('/^[a-z]{2,12}$/',$prefix)||!preg_match('/^'.preg_quote($prefix,'/').'-[a-f0-9]{24}$/',$value))throw new InvalidArgumentException('Invalid recovery '.$prefix.' transaction id.');return $value;
}

function unmRecoveryRemoteArm(array $request): array {
    $vmLock=unmRecoveryAcquireVmLock((string)($request['vmUuid']??''));try{return unmRecoveryApplyRemoteMutation($request,'recovery-arm',static function(array $record,array $context,array $payload,array $request):array{
        if(!empty($context['sourceSide']))throw new RuntimeException('Recovery arming must target the replica destination.');
        unmRecoveryAssertSoleDestinationPolicy((string)$context['identity']['vmUuid'],(string)$context['identity']['replicationId']);
        $transactionId=unmRecoveryTransactionId('arm',(string)($payload['armTransactionId']??''));$desiredAutostart=!empty($payload['desiredAutostart']);
        if((string)$record['state']==='STANDBY'&&!empty($record['armed'])&&hash_equals((string)($record['armTransactionId']??''),$transactionId)){
            $term=unmRecoveryAssertRemoteTerm($record,$request);if($desiredAutostart!==!empty($record['desiredAutostart']))throw new RuntimeException('Recovery arm retry changed managed autostart intent.');$sourceBootId=strtolower((string)($payload['sourceBootId']??''));if($sourceBootId!==strtolower((string)$request['senderBootId']))throw new RuntimeException('Recovery arm retry source boot identity is inconsistent.');$record['sourceBootId']=$sourceBootId;$record['destinationBootId']=unmRecoveryBootId();$authorization=unmRecoveryStartAuthorization($record,$sourceBootId,(string)$context['identity']['destinationHostId'],unmRecoveryBootId());return [$record,['armed'=>true,'idempotent'=>true,'term'=>$term,'state'=>'STANDBY','authority'=>'SOURCE','armTransactionId'=>$transactionId,'startAuthorization'=>$authorization]];
        }
        if((string)$record['state']!=='REPLICATION_ONLY'||!empty($record['armed']))throw new RuntimeException('The destination recovery policy is not replication-only.');
        $term=unmRecoveryAssertRemoteTerm($record,$request,true);if($term<1)throw new RuntimeException('Recovery arming requires a positive authority term.');
        $sourceBootId=strtolower((string)($payload['sourceBootId']??$request['senderBootId']??''));if($sourceBootId!==strtolower((string)$request['senderBootId']))throw new RuntimeException('Recovery arming source boot identity is inconsistent.');
        unmRecoveryAssertNoDomainConflict((string)$context['identity']['vmUuid'],(string)$context['identity']['vmName']);
        $record=unmRecoveryTransition($record,'ARMING',['term'=>$term,'armed'=>true,'managedAutostart'=>false,'desiredAutostart'=>$desiredAutostart,'authority'=>'SOURCE','sourceBootId'=>$sourceBootId,'destinationBootId'=>unmRecoveryBootId(),'armTransactionId'=>$transactionId,'armedAt'=>gmdate('c')]);
        $record=unmRecoveryTransition($record,'STANDBY');
        $authorization=unmRecoveryStartAuthorization($record,$sourceBootId,(string)$context['identity']['destinationHostId'],unmRecoveryBootId());
        return [$record,['armed'=>true,'term'=>$term,'state'=>'STANDBY','authority'=>'SOURCE','armTransactionId'=>$transactionId,'startAuthorization'=>$authorization]];
    });}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryRemoteDisarm(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-disarm',static function(array $record,array $context,array $payload,array $request):array{
        if(!empty($context['sourceSide']))throw new RuntimeException('Recovery disarming must target the replica destination.');$phase=(string)($payload['phase']??'prepare');if(!in_array($phase,['prepare','commit'],true))throw new InvalidArgumentException('Invalid recovery disarm phase.');$transactionId=unmRecoveryTransactionId('disarm',(string)($payload['disarmTransactionId']??''));$requestTerm=(int)($request['term']??-1);$last=(array)($record['lastDisarm']??[]);
        if((string)$record['state']==='REPLICATION_ONLY'&&hash_equals((string)($last['transactionId']??''),$transactionId)&&(int)($last['term']??-2)===$requestTerm)return [$record,['prepared'=>true,'disarmed'=>true,'idempotent'=>true,'term'=>$requestTerm,'state'=>'REPLICATION_ONLY','disarmTransactionId'=>$transactionId]];
        unmRecoveryAssertRemoteTerm($record,$request);
        if($phase==='prepare'){
            if((string)$record['state']==='DISARMING'){if(!hash_equals((string)($record['disarmTransactionId']??''),$transactionId))throw new RuntimeException('Another recovery disarm transaction is already prepared.');return [$record,['prepared'=>true,'idempotent'=>true,'term'=>(int)$record['term'],'disarmTransactionId'=>$transactionId]];}
            if((string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||empty($record['armed'])||unmRecoveryHoldActive($record)||isset($record['claim'])||isset($record['activation']))throw new RuntimeException('Destination recovery state cannot be safely disarmed.');
            return [unmRecoveryTransition($record,'DISARMING',['disarmTransactionId'=>$transactionId]),['prepared'=>true,'term'=>(int)$record['term'],'disarmTransactionId'=>$transactionId]];
        }
        if((string)$record['state']!=='DISARMING'||!hash_equals((string)($record['disarmTransactionId']??''),$transactionId))throw new RuntimeException('Destination disarm commit has no matching transaction prepare.');
        $requests=(array)($record['requests']??[]);$reset=unmRecoveryDefaultRecord((array)$context['identity'],false);$reset['requests']=$requests;$reset['disarmedAt']=gmdate('c');$reset['lastDisarm']=['transactionId'=>$transactionId,'term'=>$requestTerm,'committedAt'=>gmdate('c')];
        return [$reset,['disarmed'=>true,'term'=>$requestTerm,'state'=>'REPLICATION_ONLY','disarmTransactionId'=>$transactionId]];
    });
}

function unmRecoveryRemoteHold(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-hold',static function(array $record,array $context,array $payload,array $request):array{
        if(!empty($context['sourceSide']))throw new RuntimeException('Recovery holds must target the replica destination.');unmRecoveryAssertRemoteTerm($record,$request);
        $hold=(array)($payload['hold']??[]);$id=(string)($hold['holdId']??'');$until=(string)($hold['holdUntil']??'');$reason=(string)($hold['reason']??'');
        if(!preg_match('/^hold-[a-f0-9]{24}$/',$id)||!in_array($reason,['vm-poweroff','vm-restart','host-shutdown','host-reboot'],true))throw new InvalidArgumentException('Recovery hold identity or reason is invalid.');
        $current=(array)($record['hold']??[]);if((string)$record['state']==='HOLDOFF'&&hash_equals((string)($current['holdId']??''),$id)){if(!hash_equals((string)($current['reason']??''),$reason)||!hash_equals((string)($current['holdUntil']??''),$until))throw new RuntimeException('Recovery hold retry changed the durable hold.');return [$record,['held'=>true,'idempotent'=>true,'hold'=>$current]];}
        if($until!=='forever'){$epoch=strtotime($until);if($epoch===false||$epoch<=time()||$epoch>time()+604800)throw new InvalidArgumentException('Recovery hold expiry must be in the next seven days.');}
        if((string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||empty($record['armed']))throw new RuntimeException('Destination is not in source-authoritative standby.');
        $hold=['holdId'=>$id,'reason'=>$reason,'holdUntil'=>$until,'sourceBootId'=>(string)$request['senderBootId'],'acknowledged'=>true,'acknowledgedAt'=>gmdate('c')];
        return [unmRecoveryTransition($record,'HOLDOFF',['hold'=>$hold]),['held'=>true,'hold'=>$hold]];
    });
}

function unmRecoveryRemoteHoldRelease(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-hold-release',static function(array $record,array $context,array $payload,array $request):array{
        if(!empty($context['sourceSide']))throw new RuntimeException('Recovery hold release must target the replica destination.');unmRecoveryAssertRemoteTerm($record,$request);
        $hold=is_array($record['hold']??null)?$record['hold']:[];$holdId=(string)($payload['holdId']??'');$last=(array)($record['lastHoldRelease']??[]);if((string)$record['state']==='STANDBY'&&preg_match('/^hold-[a-f0-9]{24}$/',$holdId)&&hash_equals((string)($last['holdId']??''),$holdId))return [$record,['released'=>true,'idempotent'=>true,'holdId'=>$holdId,'state'=>'STANDBY']];
        if((string)$record['state']!=='HOLDOFF'||!preg_match('/^hold-[a-f0-9]{24}$/',$holdId)||!hash_equals((string)($hold['holdId']??''),$holdId))throw new RuntimeException('Recovery hold release does not match the durable hold.');
        $record=unmRecoveryTransition($record,'STANDBY');unset($record['hold']);$record['lastHoldRelease']=['holdId'=>$holdId,'releasedAt'=>gmdate('c')];
        return [$record,['released'=>true,'holdId'=>$holdId,'state'=>'STANDBY']];
    });
}

function unmRecoveryRemoteStatus(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-status',static function(array $record,array $context,array $payload,array $request):array{
        unmRecoveryAssertRemoteTerm($record,$request);$vmState=unmRecoveryVmState((string)$context['identity']['vmUuid']);$autostart=null;
        if(!empty($context['sourceSide']))try{$autostart=unmRecoveryNativeAutostart((string)$context['identity']['vmUuid']);}catch(Throwable $ignored){}
        return [$record,['term'=>(int)$record['term'],'state'=>(string)$record['state'],'authority'=>(string)$record['authority'],'armed'=>!empty($record['armed']),'vmState'=>$vmState,'nativeAutostart'=>$autostart,'holdActive'=>unmRecoveryHoldActive($record),'activationId'=>(string)($record['activationId']??'')]];
    });
}

function unmRecoveryRemoteFenceStatus(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-fence-status',static function(array $record,array $context,array $payload,array $request):array{
        if(!empty($context['sourceSide']))throw new RuntimeException('Source-start authorization must be issued by the replica destination.');unmRecoveryAssertRemoteTerm($record,$request);
        if(empty($record['armed'])||(string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||unmRecoveryHoldActive($record)||isset($record['claim'])||isset($record['activation']))throw new RuntimeException('Destination recovery state does not authorize a source start.');
        unmRecoveryAssertNoDomainConflict((string)$context['identity']['vmUuid'],(string)$context['identity']['vmName']);
        $sourceBootId=strtolower((string)($payload['sourceBootId']??''));if($sourceBootId!==strtolower((string)$request['senderBootId']))throw new RuntimeException('Source-start authorization boot identity does not match the authenticated request.');
        $record['sourceBootId']=$sourceBootId;$record['destinationBootId']=unmRecoveryBootId();$authorization=unmRecoveryStartAuthorization($record,$sourceBootId,(string)$context['identity']['destinationHostId'],unmRecoveryBootId());
        return [$record,['authorized'=>true,'term'=>(int)$record['term'],'state'=>'STANDBY','authority'=>'SOURCE','startAuthorization'=>$authorization]];
    });
}

function unmRecoveryRemoteGrant(array $request): array {
    $vmLock=unmRecoveryAcquireVmLock((string)($request['vmUuid']??''));try{return unmRecoveryApplyRemoteMutation($request,'recovery-grant',static function(array $record,array $context,array $payload,array $request):array{
        if(empty($context['sourceSide']))throw new RuntimeException('Coordinated recovery grant must target the source.');unmRecoveryAssertSoleSourcePolicy((string)$context['identity']['vmUuid'],(string)$context['identity']['replicationId']);$proposed=(int)($payload['proposedTerm']??0);$activationId=(string)($payload['activationId']??'');$pointId=(string)($payload['pointId']??'');$checkpointId=(string)($payload['checkpointId']??'');$selectionHash=(string)($payload['selectionHash']??'');$grantTransactionId=unmRecoveryTransactionId('grant',(string)($payload['grantTransactionId']??''));
        if($proposed<1||!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||$pointId===''||!preg_match('/^[a-f0-9]{64}$/',$selectionHash))throw new InvalidArgumentException('Coordinated recovery grant identity is invalid.');unmRecoveryAssertSourceReplicationIdle((string)$context['identity']['replicationId']);
        if(unmRecoveryGrantMatches($record,$payload,(int)($request['term']??-1)))return [$record,['granted'=>true,'idempotent'=>true,'term'=>$proposed,'activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'selectionHash'=>$selectionHash,'grantTransactionId'=>$grantTransactionId,'sourceVmState'=>'shut off','sourceFenced'=>true]];
        unmRecoveryAssertRemoteTerm($record,$request);if($proposed!==(int)$record['term']+1)throw new RuntimeException('Coordinated recovery grant term is not the next authority generation.');
        if(empty($record['armed'])||(string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||unmRecoveryHoldActive($record))throw new RuntimeException('Source is not in grantable recovery standby.');
        if(unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off')throw new RuntimeException('The source VM is not verifiably powered off.');
        if(unmRecoveryNativeAutostart((string)$context['identity']['vmUuid']))throw new RuntimeException('The source VM native autostart interlock is not disabled.');
        $record=unmRecoveryTransition($record,'SOURCE_START_FENCED',['term'=>$proposed,'authority'=>'DESTINATION','activationId'=>$activationId,'claim'=>['activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'selectionHash'=>$selectionHash,'grantTransactionId'=>$grantTransactionId,'kind'=>'coordinated','grantedAt'=>gmdate('c')]]);unset($record['startAuthorization']);
        return [$record,['granted'=>true,'term'=>$proposed,'activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'selectionHash'=>$selectionHash,'grantTransactionId'=>$grantTransactionId,'sourceVmState'=>'shut off','sourceFenced'=>true]];
    });}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryRemoteClaimRenew(array $request): array {
    $vmLock=unmRecoveryAcquireVmLock((string)($request['vmUuid']??''));try{return unmRecoveryApplyRemoteMutation($request,'recovery-claim-renew',static function(array $record,array $context,array $payload,array $request):array{
        if(empty($context['sourceSide']))throw new RuntimeException('Recovery claim renewal must target the fenced source.');unmRecoveryAssertSoleSourcePolicy((string)$context['identity']['vmUuid'],(string)$context['identity']['replicationId']);unmRecoveryAssertRemoteTerm($record,$request);unmRecoveryAssertSourceReplicationIdle((string)$context['identity']['replicationId']);
        $claimId=(string)($payload['claimId']??'');$activationId=(string)($payload['activationId']??'');$pointId=(string)($payload['pointId']??'');$checkpointId=(string)($payload['checkpointId']??'');$selectionHash=(string)($payload['selectionHash']??'');$claim=(array)($record['claim']??[]);
        if(!preg_match('/^claim-[a-f0-9]{24}$/',$claimId)||!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!preg_match('/^[a-f0-9]{64}$/',$selectionHash)||(string)$record['authority']!=='DESTINATION'||!in_array((string)$record['state'],['SOURCE_START_FENCED','FENCED'],true)||isset($record['remoteActivation'])||!hash_equals((string)($record['activationId']??''),$activationId)||!hash_equals((string)($claim['activationId']??''),$activationId)||!hash_equals((string)($claim['pointId']??''),$pointId)||!hash_equals((string)($claim['checkpointId']??''),$checkpointId)||!hash_equals((string)($claim['selectionHash']??''),$selectionHash))throw new RuntimeException('Claim renewal does not match the exact fenced source selection or an activation was already committed.');
        if(unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off'||unmRecoveryNativeAutostart((string)$context['identity']['vmUuid']))throw new RuntimeException('The source VM is not verifiably fenced for exact claim renewal.');
        $now=time();$renewal=['claimId'=>$claimId,'activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'selectionHash'=>$selectionHash,'issuedAt'=>gmdate('c',$now),'expiresAt'=>gmdate('c',$now+300)];$record['claimRenewal']=$renewal;
        return [$record,['renewed'=>true,'sourceFenced'=>true,'term'=>(int)$record['term']]+$renewal];
    });}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryRemoteActivationCommit(array $request): array {
    $vmLock=unmRecoveryAcquireVmLock((string)($request['vmUuid']??''));try{return unmRecoveryApplyRemoteMutation($request,'recovery-activation-commit',static function(array $record,array $context,array $payload,array $request):array{
        if(empty($context['sourceSide']))throw new RuntimeException('Activation commit must target the source.');unmRecoveryAssertSoleSourcePolicy((string)$context['identity']['vmUuid'],(string)$context['identity']['replicationId']);unmRecoveryAssertRemoteTerm($record,$request);
        $activationId=(string)($payload['activationId']??'');$selectionHash=(string)($payload['selectionHash']??'');if(!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!preg_match('/^[a-f0-9]{64}$/',$selectionHash)||!hash_equals((string)($record['activationId']??''),$activationId)||!hash_equals((string)($record['claim']['selectionHash']??''),$selectionHash)||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Activation commit does not match destination authority or the granted selection.');
        if(!in_array((string)$record['state'],['SOURCE_START_FENCED','FENCED'],true))throw new RuntimeException('Source is not durably start-fenced for activation commit.');
        if(unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off')throw new RuntimeException('The source VM became active before activation commit.');
        $record=unmRecoveryTransition($record,'FENCED',['remoteActivation'=>['activationId'=>$activationId,'pointId'=>(string)($payload['pointId']??''),'destinationBootId'=>(string)$request['senderBootId'],'committedAt'=>gmdate('c')]]);
        $now=time();return [$record,['committed'=>true,'sourceFenced'=>true,'term'=>(int)$record['term'],'activationId'=>$activationId,'selectionHash'=>$selectionHash,'grantIssuedAt'=>gmdate('c',$now),'grantExpiresAt'=>gmdate('c',$now+120)]];
    });}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryRemoteFailbackPreflight(array $request): array {
    return unmRecoveryApplyRemoteMutation($request,'recovery-failback-preflight',static function(array $record,array $context,array $payload,array $request):array{
        if(empty($context['sourceSide']))throw new RuntimeException('Cold-failback preflight must inspect the source.');unmRecoveryAssertRemoteTerm($record,$request);
        $activationId=(string)($payload['activationId']??'');$reasons=[];
        if((string)$record['authority']!=='DESTINATION'||!hash_equals((string)($record['activationId']??''),$activationId))$reasons[]='Source authority does not match the stopped destination activation.';
        if(unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off')$reasons[]='The source VM is not verifiably stopped.';
        try{if(unmRecoveryNativeAutostart((string)$context['identity']['vmUuid']))$reasons[]='Source native autostart is enabled.';}catch(Throwable $e){$reasons[]='Source native autostart cannot be verified.';}
        $policy=(array)($context['policy']??[]);$runtime=unmLoadJson(unmReplicationPath((string)$context['identity']['replicationId']).'/runtime.json');if(in_array((string)($runtime['state']??''),['STARTING','RUNNING','PREFLIGHT','SNAPSHOTTING','SENDING','PUBLISHING','COMMITTING','PRUNING'],true))$reasons[]='A source replication transaction is active.';
        $resources=unmHostResources();return [$record,['ready'=>!$reasons,'reasons'=>$reasons,'sourceVmState'=>'shut off','resources'=>$resources,'storage'=>(array)($policy['storage']??[])]];
    });
}

function unmRecoveryDispatchRpc(string $command,array $request): array {
    return match($command){
        'recovery-arm'=>unmRecoveryRemoteArm($request),'recovery-disarm'=>unmRecoveryRemoteDisarm($request),'recovery-hold'=>unmRecoveryRemoteHold($request),'recovery-hold-release'=>unmRecoveryRemoteHoldRelease($request),
        'recovery-status'=>unmRecoveryRemoteStatus($request),'recovery-fence-status'=>unmRecoveryRemoteFenceStatus($request),'recovery-grant'=>unmRecoveryRemoteGrant($request),'recovery-claim-renew'=>unmRecoveryRemoteClaimRenew($request),'recovery-activation-commit'=>unmRecoveryRemoteActivationCommit($request),'recovery-failback-preflight'=>unmRecoveryRemoteFailbackPreflight($request),
        default=>throw new InvalidArgumentException('Unknown or unsupported recovery RPC command.'),
    };
}

function unmRecoveryArmUnlocked(string $replicationId,bool $desiredAutostart,array $witnessPeerIds=[]): array {
    if($witnessPeerIds)throw new RuntimeException('Witness voting is not implemented in beta2; no witness may be selected implicitly.');$context=unmRecoveryIdentityFromSource($replicationId);$identity=(array)$context['identity'];$interlocks=unmRecoveryInterlockStatus();if(empty($interlocks['ready']))throw new RuntimeException(implode(' ',(array)$interlocks['reasons']));
    $vm=unmParseVm((string)$identity['vmUuid']);if(!empty($vm['pci'])||!empty($vm['usb']))throw new RuntimeException('Recovery beta2 does not support PCI or USB host-device passthrough.');
    $lock=unmRecoveryAcquireLock($replicationId);$record=[];$native=false;
    try{
        $record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord($identity,true));unmRecoveryAssertSoleSourcePolicy((string)$identity['vmUuid'],$replicationId);$state=(string)$record['state'];$retry=in_array($state,['ARMING','FENCED'],true)&&!empty($record['armed'])&&preg_match('/^arm-[a-f0-9]{24}$/',(string)($record['armTransactionId']??''));if(!$retry&&($state!=='REPLICATION_ONLY'||!empty($record['armed'])))throw new RuntimeException('Recovery is already armed or requires reconciliation.');if($retry&&$desiredAutostart!==!empty($record['desiredAutostart']))throw new RuntimeException('Recovery arm retry must retain the original managed autostart intent.');
        if(unmRecoveryVmState((string)$identity['vmUuid'])!=='shut off')throw new RuntimeException('Power off the source VM before arming recovery. Beta2 does not grandfather a running VM through the startup fence.');
        if(!$retry){$native=unmRecoveryNativeAutostart((string)$identity['vmUuid']);$term=max(1,(int)$record['term']+1);$record=unmRecoveryTransition($record,'ARMING',['term'=>$term,'armed'=>true,'managedAutostart'=>true,'desiredAutostart'=>$desiredAutostart,'nativeAutostartBeforeArm'=>$native,'authority'=>'SOURCE','sourceBootId'=>unmRecoveryBootId(),'destinationBootId'=>'','armTransactionId'=>unmRecoveryRandomId('arm'),'armedAt'=>gmdate('c')]);unmRecoveryStore((string)$context['path'],$record);unmRecoverySetNativeAutostart((string)$identity['vmUuid'],false);}else{$record['sourceBootId']=unmRecoveryBootId();unmRecoveryStore((string)$context['path'],$record);unmRecoverySetNativeAutostart((string)$identity['vmUuid'],false);}
    }catch(Throwable $e){
        if($record&& !empty($record['armed'])){try{$record=unmRecoveryTransition($record,'FENCED',['authority'=>'UNKNOWN','lastError'=>$e->getMessage()]);unmRecoveryStore((string)$context['path'],$record);}catch(Throwable $ignored){}}
        throw $e;
    }finally{unmRecoveryReleaseLock($lock);}
    try{$reply=unmRecoveryRemoteCall($context,'recovery-arm',(int)$record['term'],['sourceBootId'=>(string)$record['sourceBootId'],'desiredAutostart'=>$desiredAutostart,'armTransactionId'=>(string)$record['armTransactionId']],45);}
    catch(Throwable $e){$lock=unmRecoveryAcquireLock($replicationId);try{$current=unmRecoveryLoad((string)$context['path']);$current=unmRecoveryTransition($current,'FENCED',['authority'=>'UNKNOWN','lastError'=>'Destination arming acknowledgement failed: '.$e->getMessage()]);unmRecoveryStore((string)$context['path'],$current);}finally{unmRecoveryReleaseLock($lock);}throw $e;}
    $lock=unmRecoveryAcquireLock($replicationId);try{$current=unmRecoveryLoad((string)$context['path']);if(!in_array((string)$current['state'],['ARMING','FENCED'],true)||(int)$current['term']!==(int)$reply['term']||!hash_equals((string)$current['armTransactionId'],(string)($reply['armTransactionId']??'')))throw new RuntimeException('Local recovery arming state changed before destination acknowledgement.');$current=unmRecoveryTransition($current,'STANDBY',['authority'=>'SOURCE','destinationBootId'=>(string)($reply['bootId']??''),'startAuthorization'=>(array)($reply['startAuthorization']??[])]);unset($current['lastError']);$current=unmRecoveryStore((string)$context['path'],$current);}finally{unmRecoveryReleaseLock($lock);}
    if($desiredAutostart)try{unmRecoveryQueueManagedStart($replicationId);}catch(Throwable $e){$lock=unmRecoveryAcquireLock($replicationId);try{$current=unmRecoveryLoad((string)$context['path']);$current=unmRecoveryTransition($current,'FENCED',['authority'=>'UNKNOWN','lastError'=>'Recovery armed on both hosts, but managed startup could not be queued: '.$e->getMessage()]);unmRecoveryStore((string)$context['path'],$current);}finally{unmRecoveryReleaseLock($lock);}throw $e;}return $current;
}

function unmRecoveryArm(string $replicationId,bool $desiredAutostart,array $witnessPeerIds=[]): array {
    $context=unmRecoveryIdentityFromSource($replicationId);$vmLock=unmRecoveryAcquireVmLock((string)$context['identity']['vmUuid']);try{return unmRecoveryArmUnlocked($replicationId,$desiredAutostart,$witnessPeerIds);}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryQueueManagedStart(string $replicationId): void {
    $context=unmRecoveryIdentityFromSource($replicationId);unmRecoveryAssertSoleSourcePolicy((string)$context['identity']['vmUuid'],$replicationId);$directory='/var/run/unmotion/lifecycle/state';if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Unable to create lifecycle state for managed startup.');$target=$directory.'/'.$replicationId.'.restart-pending';$temporary=tempnam($directory,'.restart-pending-');if($temporary===false)throw new RuntimeException('Unable to create a managed-start marker.');$value=unmRecoveryBootId()."\n";
    try{if(file_put_contents($temporary,$value,LOCK_EX)!==strlen($value)||!chmod($temporary,0600)||!rename($temporary,$target))throw new RuntimeException('Unable to commit the managed-start marker.');$temporary='';}finally{if($temporary!==''&&is_file($temporary))@unlink($temporary);}
}

function unmRecoveryReconcileSourceStartAuthorization(string $replicationId): array {
    $context=unmRecoveryIdentityFromSource($replicationId);unmRecoveryAssertSoleSourcePolicy((string)$context['identity']['vmUuid'],$replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    $hostHold=null;
    try{$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],true));if(!empty($record['armed'])&&(string)$record['state']==='HOLDOFF'&&(string)$record['authority']==='SOURCE'&&is_array($record['hold']??null)&&!empty($record['hold']['automaticHostShutdown'])&&!hash_equals((string)($record['hold']['sourceBootId']??''),unmRecoveryBootId())&&in_array((string)($record['hold']['reason']??''),['host-shutdown','host-reboot'],true))$hostHold=(array)$record['hold'];}finally{unmRecoveryReleaseLock($lock);}
    if($hostHold!==null){
        if(is_file(UNM_RECOVERY_SHUTDOWN_FENCE))throw new RuntimeException('The same-boot host-shutdown fence is still active; recovery hold reconciliation was blocked.');
        if(empty($hostHold['acknowledged']))unmRecoverySetHold($replicationId,(string)$hostHold['reason'],(string)$hostHold['holdUntil']);
        unmRecoveryReleaseHold($replicationId,(string)$hostHold['holdId']);
    }
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],true));if(empty($record['armed'])||(string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE')throw new RuntimeException('Source recovery authority is not in startable STANDBY state.');if(unmRecoveryHoldActive($record))throw new RuntimeException('A graceful recovery hold is active.');$term=(int)$record['term'];}finally{unmRecoveryReleaseLock($lock);}
    $bootId=unmRecoveryBootId();$reply=unmRecoveryRemoteCall($context,'recovery-fence-status',$term,['sourceBootId'=>$bootId],30);$authorization=(array)($reply['startAuthorization']??[]);
    if((int)($authorization['term']??0)!==$term||!hash_equals(strtolower($bootId),strtolower((string)($authorization['sourceBootId']??'')))||!hash_equals((string)$context['identity']['destinationHostId'],(string)($authorization['destinationHostId']??'')))throw new RuntimeException('Destination returned a mismatched source-start authorization.');
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((int)$record['term']!==$term||(string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE')throw new RuntimeException('Recovery authority changed while source-start authorization was in flight.');$record['sourceBootId']=$bootId;$record['destinationBootId']=(string)($reply['bootId']??'');$record['startAuthorization']=$authorization;return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryDisarm(string $replicationId): array {
    $context=unmRecoveryIdentityFromSource($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{$record=unmRecoveryLoad((string)$context['path']);$retry=in_array((string)$record['state'],['DISARMING','FENCED'],true)&&preg_match('/^disarm-[a-f0-9]{24}$/',(string)($record['disarmTransactionId']??''));if(!$retry&&((string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||empty($record['armed'])||unmRecoveryHoldActive($record)||isset($record['claim'])||isset($record['activation'])))throw new RuntimeException('Recovery cannot be safely disarmed in its current state.');if(!$retry){$record=unmRecoveryTransition($record,'DISARMING',['disarmTransactionId'=>unmRecoveryRandomId('disarm')]);unmRecoveryStore((string)$context['path'],$record);}$term=(int)$record['term'];$transactionId=(string)$record['disarmTransactionId'];$restore=!empty($record['nativeAutostartBeforeArm']);}finally{unmRecoveryReleaseLock($lock);}
    unmRecoveryRemoteCall($context,'recovery-disarm',$term,['phase'=>'prepare','disarmTransactionId'=>$transactionId],30);unmRecoveryRemoteCall($context,'recovery-disarm',$term,['phase'=>'commit','disarmTransactionId'=>$transactionId],30);
    try{unmRecoverySetNativeAutostart((string)$context['identity']['vmUuid'],$restore);}catch(Throwable $e){$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'FENCED',['authority'=>'UNKNOWN','lastError'=>'Destination disarmed, but native autostart restoration failed: '.$e->getMessage()]);unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}throw $e;}
    $lock=unmRecoveryAcquireLock($replicationId);try{$reset=unmRecoveryDefaultRecord((array)$context['identity'],true);$reset['disarmedAt']=gmdate('c');return unmRecoveryStore((string)$context['path'],$reset);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoverySetHold(string $replicationId,string $reason,string $holdUntil,bool $automaticHostShutdown=false): array {
    if(!in_array($reason,['vm-poweroff','vm-restart','host-shutdown','host-reboot'],true))throw new InvalidArgumentException('Invalid recovery hold reason.');if($automaticHostShutdown&&!in_array($reason,['host-shutdown','host-reboot'],true))throw new InvalidArgumentException('Automatic host hold metadata requires a host shutdown or reboot reason.');
    $context=unmRecoveryIdentityFromSource($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']==='HOLDOFF'){$hold=(array)($record['hold']??[]);if(!hash_equals((string)($hold['reason']??''),$reason)||!hash_equals((string)($hold['holdUntil']??''),$holdUntil))throw new RuntimeException('A different durable recovery hold is already active.');if($automaticHostShutdown&&!empty($hold)&&empty($hold['automaticHostShutdown'])){$record['hold']['automaticHostShutdown']=true;$record['hold']['preparedBootId']=unmRecoveryBootId();$hold=(array)$record['hold'];unmRecoveryStore((string)$context['path'],$record);}if(!empty($hold['acknowledged']))return $record;}else{if($holdUntil!=='forever'){$epoch=strtotime($holdUntil);if($epoch===false||$epoch<=time()||$epoch>time()+604800)throw new InvalidArgumentException('Recovery hold expiry must be in the next seven days.');}if((string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||empty($record['armed']))throw new RuntimeException('Recovery is not in source-authoritative standby.');$hold=['holdId'=>unmRecoveryRandomId('hold'),'reason'=>$reason,'holdUntil'=>$holdUntil,'sourceBootId'=>unmRecoveryBootId(),'acknowledged'=>false,'requestedAt'=>gmdate('c')];if($automaticHostShutdown){$hold['automaticHostShutdown']=true;$hold['preparedBootId']=unmRecoveryBootId();}$record=unmRecoveryTransition($record,'HOLDOFF',['hold'=>$hold]);unmRecoveryStore((string)$context['path'],$record);}$term=(int)$record['term'];}finally{unmRecoveryReleaseLock($lock);}
    $reply=unmRecoveryRemoteCall($context,'recovery-hold',$term,['hold'=>$hold],30);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']!=='HOLDOFF'||!hash_equals($hold['holdId'],(string)($record['hold']['holdId']??'')))throw new RuntimeException('Recovery hold changed before destination acknowledgement.');$record['hold']['acknowledged']=true;$record['hold']['acknowledgedAt']=gmdate('c');return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryPrepareHostShutdownHolds(string $reason='host-shutdown'): array {
    if(!in_array($reason,['host-shutdown','host-reboot'],true))throw new InvalidArgumentException('Invalid host shutdown hold reason.');$results=[];
    foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $policyPath){
        $policy=unmLoadJson($policyPath);$replicationId=(string)($policy['id']??basename(dirname($policyPath)));if(!preg_match('/^repl-[a-f0-9]{24}$/',$replicationId))continue;$recoveryPath=unmRecoverySourcePath($replicationId);if(!is_file($recoveryPath))continue;
        try{$record=unmRecoveryLoad($recoveryPath);if(empty($record['armed']))continue;$state=(string)$record['state'];$authority=(string)$record['authority'];if($state==='HOLDOFF'&&is_array($record['hold']??null)){$results[]=['replicationId'=>$replicationId,'status'=>'preserved','holdId'=>(string)($record['hold']['holdId']??''),'reason'=>(string)($record['hold']['reason']??'')];continue;}if($state!=='STANDBY'||$authority!=='SOURCE'){$results[]=['replicationId'=>$replicationId,'status'=>'skipped','state'=>$state,'authority'=>$authority];continue;}$held=unmRecoverySetHold($replicationId,$reason,'forever',true);$holdId=(string)($held['hold']['holdId']??'');if(empty($held['hold']['automaticHostShutdown'])||!hash_equals((string)($held['hold']['preparedBootId']??''),unmRecoveryBootId()))throw new RuntimeException('Automatic host-shutdown hold was not durably prepared before remote acknowledgement.');$results[]=['replicationId'=>$replicationId,'status'=>'acknowledged','holdId'=>$holdId,'reason'=>$reason];}
        catch(Throwable $e){$results[]=['replicationId'=>$replicationId,'status'=>'failed','error'=>$e->getMessage()];}
    }
    return $results;
}

function unmRecoveryReleaseHold(string $replicationId,string $holdId): array {
    if(!preg_match('/^hold-[a-f0-9]{24}$/',$holdId))throw new InvalidArgumentException('Invalid recovery hold id.');$context=unmRecoveryIdentityFromSource($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']!=='HOLDOFF'||!hash_equals((string)($record['hold']['holdId']??''),$holdId))throw new RuntimeException('Recovery hold id does not match the active hold.');$term=(int)$record['term'];}finally{unmRecoveryReleaseLock($lock);}
    unmRecoveryRemoteCall($context,'recovery-hold-release',$term,['holdId'=>$holdId],30);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'STANDBY');unset($record['hold']);$record['lastHoldRelease']=['holdId'=>$holdId,'releasedAt'=>gmdate('c')];return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryEnsureRuntimeDir(): void {
    if(!is_dir(UNM_RECOVERY_RUNTIME_DIR)&&!mkdir(UNM_RECOVERY_RUNTIME_DIR,0700,true)&&!is_dir(UNM_RECOVERY_RUNTIME_DIR))throw new RuntimeException('Unable to create the recovery runtime directory.');
    if(!chmod(UNM_RECOVERY_RUNTIME_DIR,0700))throw new RuntimeException('Unable to secure the recovery runtime directory.');
}

function unmRecoveryOperationUpdate(string $replicationId,string $type,string $state,int $progress,string $message,array $extra=[]): array {
    unmReplicationPath($replicationId);unmRecoveryEnsureRuntimeDir();$current=unmLoadJson(UNM_RECOVERY_RUNTIME_DIR.'/'.$replicationId.'.json');$operation=array_replace($current,['schemaVersion'=>1,'replicationId'=>$replicationId,'type'=>$type,'state'=>$state,'progress'=>max(0,min(100,$progress)),'message'=>substr($message,0,512),'updatedAt'=>gmdate('c')],$extra);$operation['createdAt']=$current['createdAt']??gmdate('c');
    if(in_array($state,['QUEUED','RUNNING'],true))unset($operation['failedAt'],$operation['error'],$operation['completedAt'],$operation['result']);
    elseif($state==='COMPLETE')unset($operation['failedAt'],$operation['error']);
    elseif($state==='FAILED')unset($operation['completedAt'],$operation['result']);
    unmAtomicJson(UNM_RECOVERY_RUNTIME_DIR.'/'.$replicationId.'.json',$operation);return $operation;
}

function unmRecoveryOperationTypes(): array {return ['evidence','activate','start-activation','retry-checkpoint','stop-activation','remove-activation'];}

function unmRecoveryOperationLockPath(string $replicationId): string {unmReplicationPath($replicationId);return '/var/lock/unmotion-recovery-operation-'.$replicationId.'.lock';}

function unmRecoveryWorkerRunning(string $replicationId,int $pid): bool {
    if($pid<2||!is_readable('/proc/'.$pid.'/cmdline'))return false;$parts=explode("\0",(string)@file_get_contents('/proc/'.$pid.'/cmdline'));
    for($index=0;$index+1<count($parts);$index++)if($parts[$index]===UNM_RECOVERY_WORKER&&$parts[$index+1]===$replicationId)return true;return false;
}

function unmRecoveryLaunchOperation(string $replicationId,string $type,array $parameters=[]): array {
    unmReplicationPath($replicationId);if(!in_array($type,unmRecoveryOperationTypes(),true))throw new InvalidArgumentException('Invalid recovery operation.');if(!is_executable(UNM_RECOVERY_WORKER))throw new RuntimeException('The recovery worker is not installed or executable.');
    $lock=unmRecoveryAcquireLock($replicationId);$processLock=null;try{unmRecoveryEnsureRuntimeDir();$processLock=fopen(unmRecoveryOperationLockPath($replicationId),'c');if($processLock===false)throw new RuntimeException('Unable to open recovery operation process lock.');@chmod(unmRecoveryOperationLockPath($replicationId),0600);if(!flock($processLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another recovery worker already owns this policy operation.');$existing=unmRecoveryOperation($replicationId);$updated=strtotime((string)($existing['updatedAt']??''));$recent=$updated!==false&&$updated>=time()-30;if(in_array((string)($existing['state']??''),['QUEUED','RUNNING'],true)&&($recent||unmRecoveryWorkerRunning($replicationId,(int)($existing['pid']??0))))throw new RuntimeException('Another recovery operation is already running for this policy.');
        $request=['schemaVersion'=>1,'operationId'=>unmRecoveryRandomId('op'),'replicationId'=>$replicationId,'type'=>$type,'parameters'=>$parameters,'requestedAt'=>gmdate('c')];if(strlen(unmRecoveryCanonicalJson($request))>65536)throw new InvalidArgumentException('Recovery operation request is too large.');unmAtomicJson(UNM_RECOVERY_RUNTIME_DIR.'/'.$replicationId.'.request.json',$request);$operation=unmRecoveryOperationUpdate($replicationId,$type,'QUEUED',0,'Recovery operation queued.',['operationId'=>$request['operationId']]);$logDirectory='/var/log/unmotion';if(!is_dir($logDirectory)&&!mkdir($logDirectory,0700,true)&&!is_dir($logDirectory))throw new RuntimeException('Unable to create recovery log directory.');flock($processLock,LOCK_UN);fclose($processLock);$processLock=null;
        $command='nohup setsid '.escapeshellarg(UNM_RECOVERY_WORKER).' '.escapeshellarg($replicationId).' '.escapeshellarg((string)$request['operationId']).' >>'.escapeshellarg($logDirectory.'/recovery-'.$replicationId.'.log').' 2>&1 & echo $!';$launched=unmRun($command,null,10);$pid=(int)trim($launched['stdout']);if($launched['code']!==0||$pid<2){unmRecoveryOperationUpdate($replicationId,$type,'FAILED',100,'Unable to launch recovery worker.',['operationId'=>$request['operationId'],'error'=>trim($launched['stderr'])]);throw new RuntimeException('Unable to launch recovery worker: '.trim($launched['stderr']));}return array_replace($operation,['pid'=>$pid]);
    }finally{if(is_resource($processLock)){flock($processLock,LOCK_UN);fclose($processLock);}unmRecoveryReleaseLock($lock);}
}

function unmRecoveryFindPoint(array $manifest,string $pointId): array {
    foreach((array)($manifest['points']??[]) as $point)if(is_array($point)&&hash_equals((string)($point['id']??''),$pointId))return $point;throw new RuntimeException('Recovery point not found.');
}

function unmRecoveryFindCheckpoint(array $manifest,array $point,string $checkpointId): ?array {
    $recommendation=unmRecoveryRecommendCheckpoint((array)($manifest['points']??[]),(string)$point['id']);if(empty($recommendation['required'])){if($checkpointId!=='')throw new RuntimeException('This recovery point does not require a TPM/NVRAM checkpoint.');return null;}
    foreach((array)$recommendation['candidates'] as $checkpoint)if(is_array($checkpoint)&&hash_equals((string)($checkpoint['checkpointId']??''),$checkpointId))return $checkpoint;throw new RuntimeException('The selected TPM/NVRAM checkpoint is unavailable or incompatible.');
}

function unmRecoverySelectionHash(string $replicationId,array $point,?array $checkpoint,string $activationId): string {
    return hash('sha256',unmRecoveryCanonicalJson(['replicationId'=>$replicationId,'pointId'=>(string)($point['id']??''),'checkpointId'=>(string)($checkpoint['checkpointId']??''),'checkpointArchiveSha256'=>(string)($checkpoint['archiveSha256']??''),'activationId'=>$activationId,'pointFingerprint'=>unmReplicaPointImmutableFingerprint($point)]));
}

function unmRecoveryProbePing(string $address,int $timeout=2): bool {
    if(!filter_var($address,FILTER_VALIDATE_IP))return false;$args=['ping'];if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6))$args[]='-6';$args=array_merge($args,['-n','-c','1','-W',(string)max(1,min(5,$timeout)),$address]);return unmRun($args,null,$timeout+3)['code']===0;
}

function unmRecoveryNetworkSnapshot(): array {
    $addresses=unmRun(['ip','-j','address','show'],null,10);$routes=unmRun(['ip','-j','route','show'],null,10);if($addresses['code']!==0||$routes['code']!==0)throw new RuntimeException('Local network state cannot be inspected for recovery evidence.');
    $routeData=json_decode($routes['stdout'],true);$gateway='';$device='';if(is_array($routeData))foreach($routeData as $route)if(is_array($route)&&(string)($route['dst']??'')==='default'){$gateway=(string)($route['gateway']??'');$device=(string)($route['dev']??'');break;}
    if(!filter_var($gateway,FILTER_VALIDATE_IP)||$device==='')throw new RuntimeException('A usable default gateway is required for recovery evidence.');return ['fingerprint'=>hash('sha256',$addresses['stdout']."\n".$routes['stdout']),'gateway'=>$gateway,'device'=>$device];
}

function unmRecoveryGuestAddressSilent(string $address): bool {
    if(unmRecoveryProbePing($address))return false;$route=unmRun(['ip','-j','route','get',$address],null,8);if($route['code']!==0)return false;$data=json_decode($route['stdout'],true);$entry=is_array($data)&&is_array($data[0]??null)?$data[0]:[];$direct=!isset($entry['gateway'])&&(string)($entry['dev']??'')!=='';if(!$direct)return true;
    $dev=(string)$entry['dev'];if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
        if(!unmTool('arping'))return false;return unmRun(['arping','-c','2','-w','3','-I',$dev,$address],null,6)['code']!==0;
    }
    if(!unmTool('ndisc6'))return false;return unmRun(['ndisc6','-q','-r','2','-w','1000',$address,$dev],null,6)['code']!==0;
}

function unmRecoveryAssertGuestAddressesSilent(array $point): array {
    $network=(array)($point['guestAgent']['network']??[]);$addresses=unmRecoveryGuestIps($network);if(!$addresses)throw new RuntimeException('The selected point has no usable QEMU Guest Agent address for coordinated liveness fencing.');
    foreach($addresses as $address)if(!unmRecoveryGuestAddressSilent($address))throw new RuntimeException('A QEMU Guest Agent address from the selected point is reachable or could not be probed safely: '.$address);
    sort($addresses,SORT_STRING);return ['silent'=>true,'addresses'=>$addresses,'networkSha256'=>hash('sha256',unmRecoveryCanonicalJson($network)),'observedAt'=>gmdate('c')];
}

function unmRecoveryEvidenceSample(array $context,array $point,string $dnsProbe,string $externalProbe,int $term): array {
    $network=unmRecoveryNetworkSnapshot();$sourceProbe=unmRemote((array)$context['peer'],'/usr/local/sbin/unmotion-agent capabilities',8);$sourceSilent=false;$sourceError=$sourceProbe['code']===0?'Source answered authenticated SSH.':'Source contact failed ambiguously and cannot prove source silence: '.substr(trim((string)$sourceProbe['stderr'])?:'unknown transport error',0,220);
    $guestIps=unmRecoveryGuestIps((array)($point['guestAgent']['network']??[]));$guestSilent=!empty($guestIps);foreach($guestIps as $ip)if(!unmRecoveryGuestAddressSilent($ip)){$guestSilent=false;break;}
    $gatewayReachable=unmRecoveryProbePing((string)$network['gateway']);$dns=unmRun(['getent','ahosts',$dnsProbe],null,8);$dnsResolvable=$dns['code']===0&&trim($dns['stdout'])!=='';$externalReachable=$externalProbe===''?null:unmRecoveryProbePing($externalProbe);
    return ['at'=>time(),'observedAt'=>gmdate('c'),'sourceSilent'=>$sourceSilent,'sourceError'=>$sourceError,'guestSilent'=>$guestSilent,'guestAddresses'=>$guestIps,'gatewayReachable'=>$gatewayReachable,'gateway'=>$network['gateway'],'dnsResolvable'=>$dnsResolvable,'dnsProbe'=>$dnsProbe,'externalReachable'=>$externalReachable,'externalProbe'=>$externalProbe,'networkFingerprint'=>$network['fingerprint']];
}

function unmRecoveryEvidenceModes(): array {return ['coordinated'];}

function unmRecoveryValidateEvidenceMode(string $mode): string {
    if($mode==='two-host')throw new RuntimeException('Two-host recovery is fail-closed in beta2 because transport silence cannot prove that the source VM is powered off. Use coordinated recovery with the source durably fenced.');if(!in_array($mode,unmRecoveryEvidenceModes(),true))throw new InvalidArgumentException('Recovery evidence mode is not enabled.');return $mode;
}

function unmRecoveryCollectEvidence(string $replicationId,array $parameters): array {
    $mode=unmRecoveryValidateEvidenceMode((string)($parameters['mode']??''));$pointId=(string)($parameters['pointId']??'');$checkpointId=(string)($parameters['checkpointId']??'');$dnsProbe=strtolower(trim((string)($parameters['dnsProbe']??'one.one.one.one')));$externalProbe=trim((string)($parameters['externalProbe']??''));if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$dnsProbe))throw new InvalidArgumentException('DNS probe name is invalid.');if($externalProbe!==''&&!filter_var($externalProbe,FILTER_VALIDATE_IP))throw new InvalidArgumentException('External recovery probe must be an IP address.');$context=unmRecoveryIdentityFromReplica($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{
        $context=unmRecoveryIdentityFromReplica($replicationId);$manifest=(array)$context['manifest'];$record=unmRecoveryLoad((string)$context['path'],unmRecoveryDefaultRecord((array)$context['identity'],false));$pending=(array)($record['claim']??[]);$resume=!empty($record['armed'])&&in_array((string)$record['state'],['EVIDENCE_GATHERING','FENCED'],true)&&empty($pending['evidenceReady'])&&hash_equals($pointId,(string)($pending['pointId']??''))&&hash_equals($checkpointId,(string)($pending['checkpointId']??''))&&hash_equals($mode,(string)($pending['kind']??''))&&preg_match('/^grant-[a-f0-9]{24}$/',(string)($pending['grantTransactionId']??''));
        if($resume){$activationId=(string)($pending['activationId']??'');$claimId=(string)($pending['claimId']??'');$grantTransactionId=(string)$pending['grantTransactionId'];$term=(int)($pending['term']??-1);$selectionHash=(string)($pending['selectionHash']??'');$point=unmRecoveryFindPoint($manifest,$pointId);$checkpoint=unmRecoveryFindCheckpoint($manifest,$point,$checkpointId);if(!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!preg_match('/^claim-[a-f0-9]{24}$/',$claimId)||$term<1||!hash_equals($selectionHash,unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId)))throw new RuntimeException('Pending coordinated grant no longer matches exact retained recovery material.');if((string)$record['state']==='FENCED')$record=unmRecoveryTransition($record,'EVIDENCE_GATHERING',['authority'=>'UNKNOWN','lastError'=>null]);unmRecoveryStore((string)$context['path'],$record);}
        else{$point=unmRecoveryFindPoint($manifest,$pointId);$eligibility=unmRecoveryPointEligibility($manifest,$point,time(),true);if(empty($eligibility['eligible']))throw new RuntimeException(implode(' ',(array)$eligibility['reasons']));$checkpoint=unmRecoveryFindCheckpoint($manifest,$point,$checkpointId);if(empty($record['armed'])||(string)$record['state']!=='STANDBY'||(string)$record['authority']!=='SOURCE'||unmRecoveryHoldActive($record))throw new RuntimeException('Replica is not in source-authoritative recovery standby.');$term=(int)$record['term'];$activationId=unmRecoveryRandomId('act');$claimId=unmRecoveryRandomId('claim');$grantTransactionId=unmRecoveryRandomId('grant');$selectionHash=unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId);$pending=['claimId'=>$claimId,'activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'kind'=>$mode,'term'=>$term,'evidenceReady'=>false,'selectionHash'=>$selectionHash,'grantTransactionId'=>$grantTransactionId,'startedAt'=>gmdate('c')];$record=unmRecoveryTransition($record,'EVIDENCE_GATHERING',['activationId'=>$activationId,'claim'=>$pending]);unmRecoveryStore((string)$context['path'],$record);}
    }finally{unmRecoveryReleaseLock($lock);}
    $grantMayHaveCommitted=$resume&&!empty($record['grantReconciliationRequired']);
    $grantPayload=['proposedTerm'=>$term+1,'activationId'=>$activationId,'pointId'=>$pointId,'checkpointId'=>$checkpointId,'selectionHash'=>$selectionHash,'grantTransactionId'=>$grantTransactionId];
    try{
        $guestSilence=unmRecoveryAssertGuestAddressesSilent($point);$lock=unmRecoveryAcquireLock($replicationId);try{$freshContext=unmRecoveryIdentityFromReplica($replicationId);$freshPoint=unmRecoveryFindPoint((array)$freshContext['manifest'],$pointId);$freshCheckpoint=unmRecoveryFindCheckpoint((array)$freshContext['manifest'],$freshPoint,$checkpointId);$record=unmRecoveryLoad((string)$freshContext['path']);if((string)$record['state']!=='EVIDENCE_GATHERING'||!hash_equals($activationId,(string)($record['activationId']??''))||!hash_equals($selectionHash,unmRecoverySelectionHash($replicationId,$freshPoint,$freshCheckpoint,$activationId)))throw new RuntimeException('Selected point changed during coordinated guest-liveness evidence.');$record['claim']['guestSilence']=$guestSilence;unmRecoveryStore((string)$freshContext['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
        $reply=null;$lastGrantError=null;$grantMayHaveCommitted=true;for($attempt=0;$attempt<2;$attempt++)try{$reply=unmRecoveryRemoteCall($context,'recovery-grant',$term,$grantPayload,30);break;}catch(Throwable $grantError){$lastGrantError=$grantError;}if(!is_array($reply))throw new RuntimeException('Coordinated recovery grant could not be reconciled exactly after reply loss.',0,$lastGrantError);if(empty($reply['granted'])||empty($reply['sourceFenced'])||(int)($reply['term']??0)!==$term+1||!hash_equals($activationId,(string)($reply['activationId']??''))||!hash_equals($selectionHash,(string)($reply['selectionHash']??''))||!hash_equals($grantTransactionId,(string)($reply['grantTransactionId']??'')))throw new RuntimeException('Source returned a mismatched coordinated recovery grant.');$newTerm=$term+1;$evidence=['kind'=>'coordinated','grantHostId'=>(string)($reply['hostId']??''),'grantTransactionId'=>$grantTransactionId,'sourceVmState'=>(string)($reply['sourceVmState']??''),'sourceFenced'=>true,'grantedAt'=>gmdate('c')];$now=time();$claim=$pending;$claim['guestSilence']=$guestSilence;$claim['term']=$newTerm;$claim['evidenceReady']=true;$claim['issuedAt']=gmdate('c',$now);$claim['expiresAt']=gmdate('c',$now+300);$claim['evidence']=$evidence;
        $lock=unmRecoveryAcquireLock($replicationId);try{$freshContext=unmRecoveryIdentityFromReplica($replicationId);$freshPoint=unmRecoveryFindPoint((array)$freshContext['manifest'],$pointId);$freshCheckpoint=unmRecoveryFindCheckpoint((array)$freshContext['manifest'],$freshPoint,$checkpointId);$record=unmRecoveryLoad((string)$freshContext['path']);if((string)$record['state']!=='EVIDENCE_GATHERING'||!hash_equals($activationId,(string)($record['activationId']??''))||!hash_equals($grantTransactionId,(string)($record['claim']['grantTransactionId']??''))||!hash_equals($selectionHash,unmRecoverySelectionHash($replicationId,$freshPoint,$freshCheckpoint,$activationId)))throw new RuntimeException('Recovery point, grant transaction, or evidence state changed before authority commit.');$record=unmRecoveryTransition($record,'RECOVERY_READY',['term'=>$newTerm,'authority'=>'DESTINATION','claim'=>$claim,'activationId'=>$activationId,'destinationBootId'=>unmRecoveryBootId(),'grantReconciliationRequired'=>false]);unset($record['lastError']);return unmRecoveryStore((string)$freshContext['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
    }catch(Throwable $e){$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']==='EVIDENCE_GATHERING'&&hash_equals($activationId,(string)($record['activationId']??''))){if($grantMayHaveCommitted){$record=unmRecoveryTransition($record,'FENCED',['authority'=>'UNKNOWN','grantReconciliationRequired'=>true,'lastError'=>$e->getMessage()]);}else{$record=unmRecoveryTransition($record,'STANDBY',['authority'=>'SOURCE','lastEvidenceError'=>$e->getMessage()]);unset($record['activationId'],$record['claim'],$record['grantReconciliationRequired'],$record['lastError']);}unmRecoveryStore((string)$context['path'],$record);}}finally{unmRecoveryReleaseLock($lock);}throw $e;}
}

function unmRecoveryRenewClaim(string $replicationId): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{$context=unmRecoveryIdentityFromReplica($replicationId);$record=unmRecoveryLoad((string)$context['path']);$claim=(array)($record['claim']??[]);if((string)$record['state']!=='RECOVERY_READY'||(string)$record['authority']!=='DESTINATION'||empty($claim['evidenceReady'])||isset($record['activation']))throw new RuntimeException('Only an unactivated exact destination claim may be renewed.');$claimId=(string)($claim['claimId']??'');$activationId=(string)($claim['activationId']??'');$point=unmRecoveryFindPoint((array)$context['manifest'],(string)($claim['pointId']??''));$checkpoint=unmRecoveryFindCheckpoint((array)$context['manifest'],$point,(string)($claim['checkpointId']??''));$selectionHash=unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId);if(!preg_match('/^claim-[a-f0-9]{24}$/',$claimId)||!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!hash_equals($selectionHash,(string)($claim['selectionHash']??'')))throw new RuntimeException('The retained claim no longer matches its exact point and checkpoint.');$term=(int)$record['term'];}finally{unmRecoveryReleaseLock($lock);}
    $guestSilence=unmRecoveryAssertGuestAddressesSilent($point);$payload=['claimId'=>$claimId,'activationId'=>$activationId,'pointId'=>(string)$claim['pointId'],'checkpointId'=>(string)($claim['checkpointId']??''),'selectionHash'=>$selectionHash];$reply=unmRecoveryRemoteCall($context,'recovery-claim-renew',$term,$payload,30);$issued=strtotime((string)($reply['issuedAt']??''));$expires=strtotime((string)($reply['expiresAt']??''));if(empty($reply['renewed'])||empty($reply['sourceFenced'])||(int)($reply['term']??-1)!==$term||!hash_equals($claimId,(string)($reply['claimId']??''))||!hash_equals($activationId,(string)($reply['activationId']??''))||!hash_equals($selectionHash,(string)($reply['selectionHash']??''))||$issued===false||$expires===false||$issued>time()+30||$expires<=time()||$expires>$issued+300)throw new RuntimeException('Source returned a mismatched or stale exact claim renewal.');
    $lock=unmRecoveryAcquireLock($replicationId);try{$freshContext=unmRecoveryIdentityFromReplica($replicationId);$fresh=unmRecoveryLoad((string)$freshContext['path']);$freshPoint=unmRecoveryFindPoint((array)$freshContext['manifest'],(string)$claim['pointId']);$freshCheckpoint=unmRecoveryFindCheckpoint((array)$freshContext['manifest'],$freshPoint,(string)($claim['checkpointId']??''));if((string)$fresh['state']!=='RECOVERY_READY'||isset($fresh['activation'])||(int)$fresh['term']!==$term||!hash_equals($claimId,(string)($fresh['claim']['claimId']??''))||!hash_equals($selectionHash,unmRecoverySelectionHash($replicationId,$freshPoint,$freshCheckpoint,$activationId)))throw new RuntimeException('Claim or exact retained recovery material changed before renewal commit.');$fresh['claim']['issuedAt']=(string)$reply['issuedAt'];$fresh['claim']['expiresAt']=(string)$reply['expiresAt'];$fresh['claim']['renewedAt']=gmdate('c');$fresh['claim']['guestSilence']=$guestSilence;unset($fresh['lastError']);return unmRecoveryStore((string)$freshContext['path'],$fresh);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryActivationObjectName(string $replicaDataset,string $activationId,int $index): string {
    if(!unmZfsObjectNameSafe($replicaDataset)||!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||$index<0||$index>255)throw new InvalidArgumentException('Invalid recovery activation storage identity.');
    return $replicaDataset.'-unmotion-'.substr($activationId,4,12).'-'.($index+1);
}

function unmRecoveryExpectedActivationRoot(string $uuid,string $activationId): string {
    $uuid=strtolower(trim($uuid));if(!preg_match('/^[a-f0-9-]{32,36}$/',$uuid)||!preg_match('/^act-[a-f0-9]{24}$/',$activationId))throw new InvalidArgumentException('Invalid activation root identity.');$imageRoot=rtrim((string)unmLoadConfig()['image_dir'],'/');if($imageRoot===''||!str_starts_with($imageRoot,'/mnt/')||str_contains($imageRoot,"\0")||in_array('..',explode('/',$imageRoot),true))throw new RuntimeException('Configured VM image root is unsafe for recovery activation.');return $imageRoot.'/.unmotion-activations/'.$uuid.'/'.$activationId;
}

function unmRecoveryAssertActivationRoot(string $root,string $uuid,string $activationId): string {
    $expected=unmRecoveryExpectedActivationRoot($uuid,$activationId);if(!hash_equals($expected,$root)||str_contains($root,"\0")||in_array('..',explode('/',$root),true))throw new RuntimeException('Activation journal root does not match the exact configured UUID and activation identity.');return $expected;
}

function unmRecoveryActivationPlan(array $manifest,array $point,string $activationId): array {
    $uuid=strtolower((string)($manifest['vmUuid']??''));if(!preg_match('/^[a-f0-9-]{32,36}$/',$uuid)||!preg_match('/^act-[a-f0-9]{24}$/',$activationId))throw new InvalidArgumentException('Invalid activation identity.');$byDestination=[];foreach((array)($manifest['storage']??[]) as $item)if(is_array($item))$byDestination[(string)($item['destination']??'')]=$item;
    $root=unmRecoveryExpectedActivationRoot($uuid,$activationId);$objects=[];$maps=[];$seen=[];
    foreach((array)($point['storage']??[]) as $index=>$pointItem){if(!is_array($pointItem))throw new RuntimeException('Recovery point storage metadata is invalid.');$destination=(string)($pointItem['destination']??'');$sourceSnapshot=$destination.'@'.(string)($pointItem['snapshot']??'');$kind=(string)($pointItem['kind']??'');$reservation=$byDestination[$destination]??null;
        if(!is_array($reservation)||!in_array($kind,['zvol','dataset'],true)||$kind!==(string)($reservation['kind']??'')||isset($seen[$destination])||!unmZfsObjectNameSafe($destination))throw new RuntimeException('Recovery point storage does not match its exact replica reservation.');$seen[$destination]=true;$target=unmRecoveryActivationObjectName($destination,$activationId,(int)$index);$mountpoint='';$objectMaps=[];
        if($kind==='zvol'){$sourcePath='/dev/zvol/'.(string)$reservation['source'];$targetPath='/dev/zvol/'.$target;$maps[$sourcePath]=$targetPath;$objectMaps[$sourcePath]=$targetPath;}
        else{$mountpoint=$root.'/disk'.($index+1);foreach((array)($reservation['files']??[]) as $file){if(!is_array($file))continue;$source=(string)($file['source']??'');$relative=ltrim((string)($file['relativePath']??''),'/');if($source===''||$relative===''||str_contains($relative,'..')||str_contains($relative,"\0"))throw new RuntimeException('Recovery dataset file mapping is unsafe.');$targetPath=$mountpoint.'/'.$relative;if(isset($maps[$source]))throw new RuntimeException('Recovery XML source path is mapped more than once.');$maps[$source]=$targetPath;$objectMaps[$source]=$targetPath;}}
        $objects[]=['kind'=>$kind,'replicaDataset'=>$destination,'sourceSnapshot'=>$sourceSnapshot,'sourceGuid'=>(string)($pointItem['guid']??''),'dataset'=>$target,'mountpoint'=>$mountpoint,'mappings'=>$objectMaps];
    }
    if(count($objects)!==count($byDestination)||(!$maps&&$objects))throw new RuntimeException('Activation plan does not cover every reserved VM disk.');return ['activationRoot'=>$root,'objects'=>$objects,'diskMaps'=>$maps];
}

function unmRecoveryTransformDomainXml(string $xml,array $diskMaps,bool $stripPinning=false): string {
    if($xml===''||strlen($xml)>2097152||!str_contains($xml,'<domain'))throw new InvalidArgumentException('Recovery domain XML is invalid.');if(preg_match('~<hostdev\b~i',$xml))throw new RuntimeException('Recovery activation does not support PCI or USB host devices.');$used=[];
    $nvram=unmMigrationNvramXml($xml);
    if($nvram!==''&&unmNvramPoolPath($nvram)){
        $uuid=unmXmlValue($xml,'uuid');$name=unmXmlValue($xml,'name');$sibling=false;
        foreach(array_keys($diskMaps) as $source)if(dirname($source)===dirname($nvram))$sibling=true;
        if(!$sibling||!in_array(basename(dirname($nvram)),[$uuid,$name],true)||!str_ends_with(strtolower($nvram),'.fd')||!preg_match('/^[a-f0-9-]{36}$/i',$uuid))throw new RuntimeException('Custom recovery NVRAM is not bound to the original VM disk layout.');
        $doc=new DOMDocument();$doc->loadXML($xml,LIBXML_NONET);$node=(new DOMXPath($doc))->query('/domain/os/nvram')->item(0);
        while($node->firstChild)$node->removeChild($node->firstChild);
        $node->appendChild($doc->createTextNode('/etc/libvirt/qemu/nvram/'.$uuid.'_VARS.fd'));$xml=$doc->saveXML($doc->documentElement);
    }
    $xml=preg_replace_callback('~<disk\b([^>]*)>.*?</disk>~si',static function(array $match)use($diskMaps,&$used):string{
        $block=$match[0];$device=preg_match('~\bdevice=(["\'])([^"\']+)\1~i',$match[1],$dm)?strtolower($dm[2]):'';
        if($device==='cdrom')return (string)preg_replace('~\s*<source\b[^>]*/>~si','',$block);
        if($device!=='disk')return $block;if(!preg_match('~<source\b([^>]*)/>~si',$block,$sourceMatch))throw new RuntimeException('A recovery disk has no local source path.');
        if(!preg_match('~\b(file|dev)=(["\'])([^"\']+)\2~i',$sourceMatch[1],$pathMatch))throw new RuntimeException('Network and unsupported disk sources cannot be activated by recovery.');$original=html_entity_decode($pathMatch[3],ENT_QUOTES|ENT_XML1);if(!array_key_exists($original,$diskMaps))throw new RuntimeException('Recovery XML disk source is not covered by the activation plan: '.$original);$replacement=(string)$diskMaps[$original];$used[$original]=true;$escaped=htmlspecialchars($replacement,ENT_QUOTES|ENT_XML1);
        $newSource=preg_replace('~\b'.preg_quote($pathMatch[1],'~').'=(["\'])[^"\']+\1~i',$pathMatch[1].'='.$pathMatch[2].$escaped.$pathMatch[2],$sourceMatch[0],1);return str_replace($sourceMatch[0],(string)$newSource,$block);
    },$xml);
    if(!is_string($xml)||count($used)!==count($diskMaps))throw new RuntimeException('Recovery XML does not reference every activation disk mapping exactly once.');
    $xml=(string)preg_replace('~<on_poweroff\b[^>]*>.*?</on_poweroff>~si','<on_poweroff>destroy</on_poweroff>',$xml);$xml=(string)preg_replace('~<on_reboot\b[^>]*>.*?</on_reboot>~si','<on_reboot>restart</on_reboot>',$xml);$xml=(string)preg_replace('~<on_crash\b[^>]*>.*?</on_crash>~si','<on_crash>destroy</on_crash>',$xml);
    if($stripPinning){$xml=(string)preg_replace('~\s*<cputune\b[^>]*>.*?</cputune>~si','',$xml);$xml=(string)preg_replace('~\s*<numatune\b[^>]*>.*?</numatune>~si','',$xml);$xml=(string)preg_replace_callback('~<vcpu\b([^>]*)>~si',static fn(array $m):string=>'<vcpu'.preg_replace('~\s+cpuset=(["\'])[^"\']*\1~i','',$m[1]).'>',$xml);}
    return $xml;
}

function unmRecoveryDomainRuntimePlan(string $xml,array $diskMaps): array {
    if(preg_match('~<hostdev\b~i',$xml))throw new RuntimeException('Recovery activation blocks all host-device passthrough.');$resources=unmHostResources();$vcpus=(int)trim(unmXmlValue($xml,'vcpu'));$memoryKiB=unmXmlMemoryKiB($xml);$reasons=[];$warnings=[];
    if($vcpus<1||$vcpus>(int)($resources['cpuOnlineCount']??0))$reasons[]='VM vCPU count exceeds destination online CPUs.';if($memoryKiB<131072)$reasons[]='VM memory declaration is invalid.';elseif($memoryKiB*1024>(int)($resources['availableBytes']??0))$reasons[]='Destination does not currently have enough available memory for this VM.';
    $pinning=unmCpuPinningInfo($xml);$compatibility=unmPinningCompatibility($pinning,$resources,$vcpus,$vcpus);$stripPinning=!empty($pinning['present'])&&empty($compatibility['valid']);if($stripPinning)$warnings=array_merge($warnings,(array)$compatibility['reasons'],['Destination-incompatible CPU and NUMA pinning will be removed from the recovered definition.']);
    $loader=unmXmlValue($xml,'loader');if($loader!==''&&(!str_starts_with($loader,'/')||!is_file($loader)||is_link($loader)))$reasons[]='Destination firmware loader is missing or unsafe: '.$loader;
    if(preg_match_all('~<interface\b([^>]*)>.*?</interface>~si',$xml,$interfaces,PREG_SET_ORDER))foreach($interfaces as $interface){$type=preg_match('~\btype=(["\'])([^"\']+)\1~i',$interface[1],$typeMatch)?strtolower($typeMatch[2]):'';
        if($type==='bridge'&&preg_match('~<source\b[^>]*\bbridge=(["\'])([^"\']+)\1~i',$interface[0],$source)){$check=unmRun(['ip','link','show',$source[2]],null,8);if($check['code']!==0)$reasons[]='Destination bridge is unavailable: '.$source[2];}
        elseif($type==='network'&&preg_match('~<source\b[^>]*\bnetwork=(["\'])([^"\']+)\1~i',$interface[0],$source)){$check=unmRun(['virsh','net-info',$source[2]],null,8);if($check['code']!==0||!preg_match('/^Active:\s+yes/mi',$check['stdout']))$reasons[]='Destination libvirt network is unavailable: '.$source[2];}
        else $reasons[]='Recovery does not support this destination network interface type or source.';
    }
    $transformed=unmRecoveryTransformDomainXml($xml,$diskMaps,$stripPinning);return ['ready'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'warnings'=>array_values(array_unique($warnings)),'xml'=>$transformed,'stripPinning'=>$stripPinning,'resources'=>$resources,'vcpus'=>$vcpus,'memoryKiB'=>$memoryKiB];
}

function unmRecoveryZfsProperty(string $dataset,string $property): string {
    $result=unmRun(['zfs','get','-H','-o','value',$property,$dataset],null,15);if($result['code']!==0)throw new RuntimeException('Unable to inspect ZFS property '.$property.' on '.$dataset.': '.trim($result['stderr']));return trim($result['stdout']);
}

function unmRecoveryAssertActivationObject(array $object,string $activationId): void {
    $dataset=(string)($object['dataset']??'');$origin=(string)($object['sourceSnapshot']??'');if(!unmZfsObjectNameSafe($dataset)||!str_contains($origin,'@')||!preg_match('/^act-[a-f0-9]{24}$/',$activationId))throw new RuntimeException('Activation object journal is invalid.');
    if(!hash_equals($origin,unmRecoveryZfsProperty($dataset,'origin'))||!hash_equals($activationId,unmRecoveryZfsProperty($dataset,'unmotion:activation')))throw new RuntimeException('Activation object ownership or origin does not match its durable journal: '.$dataset);
}

function unmRecoveryRemoveExactTree(string $path,string $base): void {
    if(!unmPathWithin($path,$base)||$path===$base||is_link($path))throw new RuntimeException('Recovery cleanup path is outside its exact owned root: '.$path);if(!file_exists($path))return;
    if(is_file($path)){if(!unlink($path))throw new RuntimeException('Unable to remove activation-owned file: '.$path);return;}if(!is_dir($path))throw new RuntimeException('Activation-owned path has an unsupported type: '.$path);
    $items=scandir($path);if($items===false)throw new RuntimeException('Unable to inspect activation-owned directory: '.$path);foreach($items as $name){if($name==='.'||$name==='..')continue;$child=$path.'/'.$name;if(is_link($child))throw new RuntimeException('Activation-owned directory contains a symbolic link: '.$child);unmRecoveryRemoveExactTree($child,$base);}if(!rmdir($path))throw new RuntimeException('Unable to remove activation-owned directory: '.$path);
}

function unmRecoveryCopyExactTree(string $source,string $destination): void {
    if(is_link($source))throw new RuntimeException('Recovery checkpoint contains a symbolic link.');if(is_file($source)){if(file_exists($destination)||is_link($destination))throw new RuntimeException('Activation host-state destination already exists: '.$destination);$parent=dirname($destination);if(!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent))throw new RuntimeException('Unable to create activation host-state parent.');if(!copy($source,$destination)||!chmod($destination,0600))throw new RuntimeException('Unable to install activation host-state file.');return;}
    if(!is_dir($source)||file_exists($destination)||is_link($destination))throw new RuntimeException('Activation host-state directory is invalid or already exists.');if(!mkdir($destination,0700,true)&&!is_dir($destination))throw new RuntimeException('Unable to create activation host-state directory.');$items=scandir($source);if($items===false)throw new RuntimeException('Unable to inspect staged host state.');foreach($items as $name){if($name==='.'||$name==='..')continue;unmRecoveryCopyExactTree($source.'/'.$name,$destination.'/'.$name);}
}

function unmRecoveryPathContentHash(string $path): string {
    if(is_link($path)||(!is_file($path)&&!is_dir($path)))throw new RuntimeException('Activation-owned host-state path is unavailable.');$entries=[];$base=is_dir($path)?$path:dirname($path);$walk=function(string $current)use(&$walk,&$entries,$base):void{if(is_link($current))throw new RuntimeException('Activation-owned host state contains a symbolic link.');if(is_file($current)){$relative=ltrim(substr($current,strlen($base)),'/');$entries[$relative]=hash_file('sha256',$current).':'.filesize($current);return;}foreach(scandir($current)?:[] as $name)if($name!=='.'&&$name!=='..')$walk($current.'/'.$name);};$walk($path);ksort($entries,SORT_STRING);return hash('sha256',unmRecoveryCanonicalJson($entries));
}

function unmRecoveryStateDestinationAllowed(string $path,string $kind,string $uuid): bool {
    $uuid=strtolower($uuid);if($kind==='nvram')return (unmPathWithin($path,'/etc/libvirt/qemu/nvram')||unmPathWithin($path,'/var/lib/libvirt/qemu/nvram'))&&str_contains(strtolower(basename($path)),$uuid)&&str_ends_with(strtolower($path),'.fd');
    if($kind!=='tpm'||strtolower(basename($path))!==$uuid)return false;foreach(['/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm'] as $base)if(unmPathWithin($path,$base)&&$path!==$base)return true;return false;
}

function unmRecoveryHostStateBinding(string $xml,string $uuid): array {
    $uuid=strtolower($uuid);if(!preg_match('/^[a-f0-9-]{32,36}$/',$uuid)||$xml===''||!str_contains($xml,'<domain'))throw new InvalidArgumentException('Invalid recovery host-state XML binding input.');$nvram=trim(unmXmlValue($xml,'nvram'));if($nvram!==''&&!unmRecoveryStateDestinationAllowed($nvram,'nvram',$uuid))throw new RuntimeException('Transformed recovery XML contains an unsafe or non-UUID-scoped NVRAM path.');
    $tpmPresent=preg_match('~<tpm\b.*?</tpm>~si',$xml,$tpmBlock)===1;$tpmPath='';if($tpmPresent&&preg_match('~<(?:source|backend)\b[^>]*\bpath=(["\'])([^"\']+)\1~i',$tpmBlock[0],$pathMatch)){$tpmPath=trim($pathMatch[2]);if(!unmRecoveryStateDestinationAllowed($tpmPath,'tpm',$uuid))throw new RuntimeException('Transformed recovery XML contains an unrecognized software TPM state path.');}
    $tpmRoots=array_map(static fn(string $base):string=>$base.'/'.$uuid,['/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm']);$binding=['nvramPath'=>$nvram,'tpmPresent'=>$tpmPresent,'tpmPath'=>$tpmPath,'tpmAllowedRoots'=>$tpmRoots];$binding['bindingHash']=hash('sha256',unmRecoveryCanonicalJson($binding));return $binding;
}

function unmRecoveryAssertCheckpointDestinations(array $nvram,array $tpmRoots,array $binding,bool $needNvram,bool $needTpm): void {
    $nvramPath=(string)($binding['nvramPath']??'');$tpmPath=(string)($binding['tpmPath']??'');$allowed=array_values(array_map('strval',(array)($binding['tpmAllowedRoots']??[])));$boundTpm=!empty($binding['tpmPresent']);
    if($needNvram){if($nvramPath===''||count($nvram)!==1||!hash_equals($nvramPath,(string)array_key_first($nvram)))throw new RuntimeException('Checkpoint NVRAM destination does not equal the exact transformed XML nvram path.');}elseif($nvram||$nvramPath!=='')throw new RuntimeException('Checkpoint and transformed XML disagree about NVRAM state.');
    if($needTpm){if(!$boundTpm||count($tpmRoots)!==1)throw new RuntimeException('Checkpoint and transformed XML disagree about software TPM state.');$destination=(string)array_key_first($tpmRoots);if($tpmPath!==''?!hash_equals($tpmPath,$destination):!in_array($destination,$allowed,true))throw new RuntimeException('Checkpoint TPM destination is not the exact recognized transformed XML state root.');}elseif($tpmRoots||$boundTpm)throw new RuntimeException('Checkpoint and transformed XML disagree about software TPM state.');
}

function unmRecoveryInstallCheckpoint(array $checkpoint,string $uuid,string $activationId,string $stagingRoot,bool $validateOnly=false,array $binding=[]): array {
    if(!preg_match('/^act-[a-f0-9]{24}$/',$activationId)||!preg_match('/^[a-f0-9-]{32,36}$/i',$uuid))throw new InvalidArgumentException('Invalid checkpoint activation identity.');$archive=(string)($checkpoint['archivePath']??'');$archiveHash=strtolower((string)($checkpoint['archiveSha256']??''));
    if(!is_file($archive)||is_link($archive)||!preg_match('/^[a-f0-9]{64}$/',$archiveHash)||!hash_equals($archiveHash,(string)hash_file('sha256',$archive)))throw new RuntimeException('Selected checkpoint archive failed exact SHA-256 validation.');if(!is_dir($stagingRoot)&&!mkdir($stagingRoot,0700,true)&&!is_dir($stagingRoot))throw new RuntimeException('Unable to create checkpoint staging root.');$stage=$stagingRoot.'/checkpoint-'.$activationId;
    if(file_exists($stage)||is_link($stage))throw new RuntimeException('Checkpoint staging path already exists.');if(!mkdir($stage,0700,true))throw new RuntimeException('Unable to create checkpoint staging directory.');
    $installed=[];$installedDestinations=[];try{$listed=unmRun(['tar','-tzf',$archive],null,30);if($listed['code']!==0)throw new RuntimeException('Unable to inspect checkpoint archive: '.trim($listed['stderr']));foreach(preg_split('/\R/',trim($listed['stdout']))?:[] as $entry){$entry=rtrim($entry,'/');if($entry===''||str_starts_with($entry,'/')||str_contains($entry,"\0")||in_array('..',explode('/',$entry),true))throw new RuntimeException('Checkpoint archive contains an unsafe path.');}
        $extract=unmRun(['tar','--no-same-owner','--no-same-permissions','-xzf',$archive,'-C',$stage],null,60);if($extract['code']!==0)throw new RuntimeException('Unable to extract checkpoint archive: '.trim($extract['stderr']));$nvram=[];$tpmRoots=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $item){$path=$item->getPathname();if($item->isLink()||(!$item->isDir()&&!$item->isFile()))throw new RuntimeException('Checkpoint archive contains an unsupported filesystem object.');$relative=ltrim(substr($path,strlen($stage)),'/');$absolute='/'.$relative;$matched=false;
            if($item->isFile()&&unmRecoveryStateDestinationAllowed($absolute,'nvram',$uuid)){$nvram[$absolute]=$path;$matched=true;}
            foreach(['/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm'] as $base)if(unmPathWithin($absolute,$base.'/'.$uuid)){$tpmRoots[$base.'/'.$uuid]=$stage.'/'.ltrim($base.'/'.$uuid,'/');$matched=true;break;}
            if(!$matched){$allowedParent=false;foreach(['/etc/libvirt/qemu/nvram','/var/lib/libvirt/qemu/nvram','/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm'] as $base)if(unmPathWithin($base,$absolute)){$allowedParent=true;break;}if(!$allowedParent)throw new RuntimeException('Checkpoint archive contains data outside UUID-scoped libvirt state.');}
        }
        $needNvram=!empty($checkpoint['nvramPresent']);$needTpm=!empty($checkpoint['tpmPresent']);if($needNvram&&count($nvram)!==1)throw new RuntimeException('Checkpoint does not contain one exact UUID-scoped NVRAM file.');if(!$needNvram&&$nvram)throw new RuntimeException('Checkpoint contains unexpected NVRAM state.');if($needTpm&&count($tpmRoots)!==1)throw new RuntimeException('Checkpoint does not contain one exact UUID-scoped TPM directory.');if(!$needTpm&&$tpmRoots)throw new RuntimeException('Checkpoint contains unexpected TPM state.');if(!$binding)throw new RuntimeException('Checkpoint installation requires an exact transformed XML host-state binding.');unmRecoveryAssertCheckpointDestinations($nvram,$tpmRoots,$binding,$needNvram,$needTpm);if($validateOnly){$intended=[];foreach(array_keys($nvram) as $destination)$intended[]=['kind'=>'nvram','path'=>$destination,'activationId'=>$activationId,'pending'=>true];foreach(array_keys($tpmRoots) as $destination)$intended[]=['kind'=>'tpm','path'=>$destination,'activationId'=>$activationId,'pending'=>true];return $intended;}
        foreach($nvram as $destination=>$source){$installedDestinations[]=['kind'=>'nvram','path'=>$destination];unmRecoveryCopyExactTree($source,$destination);$installed[]=['kind'=>'nvram','path'=>$destination,'contentSha256'=>unmRecoveryPathContentHash($destination),'activationId'=>$activationId];}
        foreach($tpmRoots as $destination=>$source){$installedDestinations[]=['kind'=>'tpm','path'=>$destination];unmRecoveryCopyExactTree($source,$destination);$installed[]=['kind'=>'tpm','path'=>$destination,'contentSha256'=>unmRecoveryPathContentHash($destination),'activationId'=>$activationId];}
        return $installed;
    }catch(Throwable $e){foreach(array_reverse($installedDestinations) as $owned){$destination=(string)$owned['path'];if(file_exists($destination)&&unmRecoveryStateDestinationAllowed($destination,(string)$owned['kind'],$uuid))try{unmRecoveryRemoveExactTree($destination,dirname($destination));}catch(Throwable $ignored){}}throw $e;}finally{if(file_exists($stage)&&!is_link($stage))unmRecoveryRemoveExactTree($stage,$stagingRoot);}
}

function unmRecoveryRemoveInstalledState(array $installed,string $uuid,string $activationId): array {
    $removed=[];foreach($installed as $item){if(!is_array($item)||!hash_equals($activationId,(string)($item['activationId']??'')))throw new RuntimeException('Activation host-state ownership journal is invalid.');$kind=(string)($item['kind']??'');$path=(string)($item['path']??'');if(!unmRecoveryStateDestinationAllowed($path,$kind,$uuid))throw new RuntimeException('Activation host-state path is not exact and UUID-scoped: '.$path);if(file_exists($path)||is_link($path)){$base=$kind==='nvram'?dirname($path):dirname($path);unmRecoveryRemoveExactTree($path,$base);}$removed[]=$path;}return $removed;
}

function unmRecoveryCheckpointRetryBackup(array $installed,string $uuid,string $activationId,string $activationRoot,string $retryId): array {
    unmRecoveryAssertActivationRoot($activationRoot,$uuid,$activationId);unmRecoveryTransactionId('retry',$retryId);$backupRoot=$activationRoot.'/retry-backup-'.$retryId;if(file_exists($backupRoot)||is_link($backupRoot)||!mkdir($backupRoot,0700,true))throw new RuntimeException('Unable to create exact checkpoint retry backup root.');$backups=[];
    try{foreach(array_values($installed) as $index=>$item){if(!is_array($item)||!hash_equals($activationId,(string)($item['activationId']??'')))throw new RuntimeException('Checkpoint retry host-state ownership journal is invalid.');$kind=(string)($item['kind']??'');$path=(string)($item['path']??'');if(!unmRecoveryStateDestinationAllowed($path,$kind,$uuid)||(!is_file($path)&&!is_dir($path))||is_link($path))throw new RuntimeException('Checkpoint retry source state is missing or unsafe: '.$path);$current=$item;$current['contentSha256']=unmRecoveryPathContentHash($path);$backup=$backupRoot.'/state-'.$index;unmRecoveryCopyExactTree($path,$backup);if(!hash_equals((string)$current['contentSha256'],unmRecoveryPathContentHash($backup)))throw new RuntimeException('Checkpoint retry backup verification failed.');$backups[]=['kind'=>$kind,'path'=>$path,'backupPath'=>$backup,'contentSha256'=>$current['contentSha256'],'activationId'=>$activationId,'installed'=>$current];}return ['backupRoot'=>$backupRoot,'backups'=>$backups];}
    catch(Throwable $e){if(file_exists($backupRoot)&&!is_link($backupRoot))try{unmRecoveryRemoveExactTree($backupRoot,$activationRoot);}catch(Throwable $ignored){}throw $e;}
}

function unmRecoveryCheckpointRetryRestore(array $journal,string $uuid,string $activationId,string $activationRoot): array {
    unmRecoveryAssertActivationRoot($activationRoot,$uuid,$activationId);$retryId=unmRecoveryTransactionId('retry',(string)($journal['retryId']??''));$backupRoot=(string)($journal['backupRoot']??'');if(!hash_equals($activationRoot.'/retry-backup-'.$retryId,$backupRoot)||!is_dir($backupRoot)||is_link($backupRoot))throw new RuntimeException('Checkpoint retry backup root is missing or mismatched.');$restored=[];
    foreach((array)($journal['backups']??[]) as $backup){if(!is_array($backup)||!hash_equals($activationId,(string)($backup['activationId']??'')))throw new RuntimeException('Checkpoint retry backup journal is invalid.');$kind=(string)($backup['kind']??'');$path=(string)($backup['path']??'');$source=(string)($backup['backupPath']??'');$hash=(string)($backup['contentSha256']??'');if(!unmRecoveryStateDestinationAllowed($path,$kind,$uuid)||!unmPathWithin($source,$backupRoot)||(!is_file($source)&&!is_dir($source))||is_link($source)||!preg_match('/^[a-f0-9]{64}$/',$hash)||!hash_equals($hash,unmRecoveryPathContentHash($source)))throw new RuntimeException('Checkpoint retry backup entry failed exact verification.');if(file_exists($path)||is_link($path))unmRecoveryRemoveExactTree($path,dirname($path));unmRecoveryCopyExactTree($source,$path);if(!hash_equals($hash,unmRecoveryPathContentHash($path)))throw new RuntimeException('Checkpoint retry rollback restoration failed exact verification.');$restored[]=(array)($backup['installed']??[]);}
    return $restored;
}

function unmRecoveryCreateActivationObjects(array $objects,string $activationId): array {
    $created=[];try{foreach($objects as $object){$dataset=(string)$object['dataset'];$snapshot=(string)$object['sourceSnapshot'];$guid=unmRun(['zfs','get','-H','-o','value','guid',$snapshot],null,15);if($guid['code']!==0||!hash_equals(trim((string)$object['sourceGuid']),trim($guid['stdout'])))throw new RuntimeException('Recovery snapshot GUID changed before activation: '.$snapshot);if(unmRun(['zfs','list','-H','-o','name',$dataset],null,10)['code']===0)throw new RuntimeException('Activation ZFS object already exists: '.$dataset);
            $args=['zfs','clone','-o','readonly=off','-o','unmotion:activation='.$activationId];if((string)$object['kind']==='zvol')$args=array_merge($args,['-o','volmode=none','-o','snapdev=hidden']);else{$mountpoint=(string)$object['mountpoint'];if(!str_starts_with($mountpoint,'/mnt/')||str_contains($mountpoint,'/../'))throw new RuntimeException('Activation dataset mountpoint is unsafe.');if(!is_dir($mountpoint)&&!mkdir($mountpoint,0700,true)&&!is_dir($mountpoint))throw new RuntimeException('Unable to create activation dataset mountpoint.');$args=array_merge($args,['-o','canmount=noauto','-o','mountpoint='.$mountpoint]);}$args=array_merge($args,[$snapshot,$dataset]);$clone=unmRun($args,null,120);if($clone['code']!==0)throw new RuntimeException('Unable to create activation clone '.$dataset.': '.trim($clone['stderr']));$created[]=$object;unmRecoveryAssertActivationObject($object,$activationId);}
        return unmRecoveryExposeActivationObjects($created,$activationId);
    }catch(Throwable $e){try{unmRecoveryDestroyActivationObjects(array_reverse($created),$activationId);}catch(Throwable $cleanup){throw new RuntimeException($e->getMessage().' Exact partial activation cleanup also failed: '.$cleanup->getMessage(),0,$e);}throw $e;}
}

function unmRecoveryExposeActivationObjects(array $objects,string $activationId): array {
    foreach($objects as $object){if(!is_array($object))throw new RuntimeException('Activation storage journal is invalid.');unmRecoveryAssertActivationObject($object,$activationId);$dataset=(string)$object['dataset'];$kind=(string)($object['kind']??'');
        if($kind==='zvol'){$enable=unmRecoveryZfsProperty($dataset,'volmode')==='dev'?['code'=>0,'stderr'=>'']:unmRun(['zfs','set','volmode=dev',$dataset],null,30);}
        elseif($kind==='dataset'){$enable=unmRecoveryZfsProperty($dataset,'mounted')==='yes'?['code'=>0,'stderr'=>'']:unmRun(['zfs','mount',$dataset],null,30);}else throw new RuntimeException('Activation object kind is invalid.');
        if($enable['code']!==0)throw new RuntimeException('Unable to expose activation object '.$dataset.': '.trim((string)$enable['stderr']));foreach((array)($object['mappings']??[]) as $target)if(!file_exists((string)$target)||is_link((string)$target))throw new RuntimeException('Activation disk path did not appear safely after clone exposure: '.$target);
    }return $objects;
}

function unmRecoveryDestroyActivationObjects(array $objects,string $activationId): array {
    $removed=[];foreach($objects as $object){if(!is_array($object))throw new RuntimeException('Activation storage journal is invalid.');$dataset=(string)($object['dataset']??'');$exists=unmRun(['zfs','list','-H','-o','name',$dataset],null,10)['code']===0;if(!$exists)continue;unmRecoveryAssertActivationObject($object,$activationId);if((string)($object['kind']??'')==='dataset')unmRun(['zfs','unmount',$dataset],null,30);else unmRun(['zfs','set','volmode=none',$dataset],null,30);$destroy=unmRun(['zfs','destroy',$dataset],null,120);if($destroy['code']!==0)throw new RuntimeException('Unable to remove exact activation ZFS object '.$dataset.': '.trim($destroy['stderr']));$removed[]=$dataset;}return $removed;
}

function unmRecoveryEmbedActivationMetadata(string $xml,string $replicationId,string $activationId): string {
    $metadata='<metadata><unmotion:activation xmlns:unmotion="urn:unmotion:recovery:1" replicationId="'.htmlspecialchars($replicationId,ENT_QUOTES|ENT_XML1).'">'.htmlspecialchars($activationId,ENT_QUOTES|ENT_XML1).'</unmotion:activation></metadata>';
    if(preg_match('~<metadata\b[^>]*>.*?</metadata>~si',$xml))$xml=(string)preg_replace('~<metadata\b([^>]*)>~si','$0<unmotion:activation xmlns:unmotion="urn:unmotion:recovery:1" replicationId="'.htmlspecialchars($replicationId,ENT_QUOTES|ENT_XML1).'">'.$activationId.'</unmotion:activation>',$xml,1);else $xml=(string)preg_replace('~</domain>\s*$~',$metadata.'</domain>',$xml,1,$count);
    if(!str_contains($xml,$activationId))throw new RuntimeException('Unable to bind activation ownership metadata into the domain XML.');return $xml;
}

function unmRecoveryDomainOwned(string $vmUuid,string $replicationId,string $activationId): bool {
    $dump=unmRun(['virsh','dumpxml',$vmUuid,'--inactive'],null,15);if($dump['code']!==0)return false;$xml=$dump['stdout'];return str_contains($xml,'urn:unmotion:recovery:1')&&str_contains($xml,$activationId)&&str_contains($xml,$replicationId)&&strcasecmp(unmXmlValue($xml,'uuid'),$vmUuid)===0;
}

function unmRecoveryStopDomain(string $vmUuid,int $graceSeconds=45,bool $force=true): string {
    $state=unmRecoveryVmState($vmUuid);if($state==='undefined')return $state;if($state==='shut off')return $state;unmRun(['virsh','shutdown',$vmUuid],null,15);$deadline=time()+max(5,min(120,$graceSeconds));do{sleep(2);$state=unmRecoveryVmState($vmUuid);if($state==='shut off'||$state==='undefined')return $state;}while(time()<$deadline);
    if(!$force)throw new RuntimeException('Recovered VM did not stop within the graceful timeout.');$destroy=unmRun(['virsh','destroy',$vmUuid],null,30);if($destroy['code']!==0||unmRecoveryVmState($vmUuid)!=='shut off')throw new RuntimeException('Unable to force-stop the exact recovered VM: '.trim($destroy['stderr']));return 'shut off';
}

function unmRecoveryWaitGuestHealthy(string $vmUuid,int $timeoutSeconds=90): array {
    $deadline=time()+max(15,min(300,$timeoutSeconds));$last='';do{$state=unmRecoveryVmState($vmUuid);if(!in_array($state,['running','idle'],true))throw new RuntimeException('Recovered VM left the running state before Guest Agent health succeeded.');$probe=unmRun(['virsh','qemu-agent-command',$vmUuid,'{"execute":"guest-ping"}'],null,8);$last=trim($probe['stderr']);if($probe['code']===0){$decoded=json_decode($probe['stdout'],true);if(is_array($decoded)&&array_key_exists('return',$decoded))return ['healthy'=>true,'at'=>gmdate('c'),'response'=>$decoded];}sleep(5);}while(time()<$deadline);throw new RuntimeException('QEMU Guest Agent did not become healthy within the recovery boot timeout'.($last!==''?': '.$last:'.'));
}

function unmRecoveryRevalidateAuthorityForStart(array $context,array $record,array $point): array {
    $claim=(array)($record['claim']??[]);$kind=(string)($claim['kind']??'');$activationId=(string)($record['activationId']??'');$term=(int)$record['term'];
    if($kind==='coordinated'){$reply=unmRecoveryRemoteCall($context,'recovery-activation-commit',$term,['activationId'=>$activationId,'pointId'=>(string)($claim['pointId']??''),'selectionHash'=>(string)($claim['selectionHash']??'')],30);$expires=strtotime((string)($reply['grantExpiresAt']??''));if(empty($reply['committed'])||empty($reply['sourceFenced'])||!hash_equals((string)($claim['selectionHash']??''),(string)($reply['selectionHash']??''))||$expires===false||$expires<=time()||$expires>time()+150)throw new RuntimeException('Source did not issue a fresh exact activation start grant.');return $reply;}
    throw new RuntimeException('Beta2 starts require an authenticated coordinated source-fence claim; every other claim kind is fail-closed.');
}

function unmRecoveryActivationPhase(array $context,string $activationId,string $phase,array $changes=[]): array {
    if(!preg_match('/^[A-Z][A-Z0-9_]{2,40}$/',$phase))throw new InvalidArgumentException('Invalid activation journal phase.');$replicationId=(string)$context['identity']['replicationId'];$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)($record['activationId']??''))||!isset($record['activation']))throw new RuntimeException('Activation ownership changed before journal phase '.$phase.'.');$record['activation']['phase']=$phase;$record['activation']['phaseAt']=gmdate('c');foreach($changes as $key=>$value)$record['activation'][$key]=$value;return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryStartActivationUnlocked(string $replicationId): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$lock=unmRecoveryAcquireLock($replicationId);
    try{$record=unmRecoveryLoad((string)$context['path']);$state=(string)$record['state'];if((!in_array($state,['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED'],true)&&!($state==='ACTIVATING'&&(string)($record['activation']['phase']??'')==='STARTING'))||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Recovered activation is not in a startable stopped state.');$activation=(array)($record['activation']??[]);$activationId=(string)($record['activationId']??'');$claim=(array)($record['claim']??[]);if(!hash_equals($activationId,(string)($activation['activationId']??'')))throw new RuntimeException('Activation ownership or its start claim is incomplete.');$point=unmRecoveryFindPoint((array)$context['manifest'],(string)($activation['pointId']??''));$checkpoint=unmRecoveryFindCheckpoint((array)$context['manifest'],$point,(string)($claim['checkpointId']??''));$expectedSelection=unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId);if(!hash_equals($expectedSelection,(string)($claim['selectionHash']??'')))throw new RuntimeException('Activation selection no longer matches the durable claim.');}finally{unmRecoveryReleaseLock($lock);}
    $actualState=unmRecoveryVmState((string)$context['identity']['vmUuid']);if(in_array($actualState,['running','idle'],true)){if(!unmRecoveryDomainOwned((string)$context['identity']['vmUuid'],$replicationId,$activationId)||!in_array((string)($activation['phase']??''),['STARTING','RUNNING'],true))throw new RuntimeException('An already-running VM cannot be reconciled to this exact activation start journal.');try{$health=unmRecoveryWaitGuestHealthy((string)$context['identity']['vmUuid']);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'RECOVERED_RUNNING');$record['activation']['phase']='RUNNING';$record['activation']['vmState']='running';$record['activation']['healthAt']=$health['at'];return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}}catch(Throwable $e){try{unmRecoveryStopDomain((string)$context['identity']['vmUuid'],20,true);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['lastError'=>$e->getMessage()]);$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['vmState']='shut off';unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}}catch(Throwable $stop){$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'SPLIT_BRAIN_UNRESOLVED',['authority'=>'UNKNOWN','lastError'=>$e->getMessage().' Local fencing failed: '.$stop->getMessage()]);unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}throw $stop;}throw $e;}}if($actualState!=='shut off')throw new RuntimeException('Recovered VM is not verifiably stopped before start.');unmRecoveryExposeActivationObjects((array)($activation['objects']??[]),$activationId);if(!unmRecoveryDomainOwned((string)$context['identity']['vmUuid'],$replicationId,$activationId))throw new RuntimeException('Defined recovered VM is not owned by this exact activation.');$startGrant=unmRecoveryRevalidateAuthorityForStart($context,$record,$point);$grantExpires=strtotime((string)($startGrant['grantExpiresAt']??''));if($grantExpires===false||$grantExpires<=time())throw new RuntimeException('Fresh source start grant expired before VM start.');$guestSilence=unmRecoveryAssertGuestAddressesSilent($point);$lock=unmRecoveryAcquireLock($replicationId);try{$fresh=unmRecoveryLoad((string)$context['path']);if(!hash_equals($expectedSelection,(string)($fresh['claim']['selectionHash']??''))||(int)$fresh['term']!==(int)$record['term'])throw new RuntimeException('Recovery selection or term changed after the fresh source grant.');$fresh['startGrant']=['selectionHash'=>$expectedSelection,'issuedAt'=>(string)$startGrant['grantIssuedAt'],'expiresAt'=>(string)$startGrant['grantExpiresAt'],'sourceHostId'=>(string)$context['identity']['sourceHostId'],'guestSilence'=>$guestSilence];$fresh['activation']['phase']='STARTING';$fresh['activation']['phaseAt']=gmdate('c');$fresh['activation']['vmState']='starting';unmRecoveryStore((string)$context['path'],$fresh);}finally{unmRecoveryReleaseLock($lock);}
    if($grantExpires<=time())throw new RuntimeException('Fresh source start grant expired before the start command.');if(unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off'||!unmRecoveryDomainOwned((string)$context['identity']['vmUuid'],$replicationId,$activationId))throw new RuntimeException('Recovered VM ownership or stopped state changed immediately before start.');$started=unmRun(['virsh','start',(string)$context['identity']['vmUuid']],null,45);if($started['code']!==0)throw new RuntimeException('Unable to start recovered VM: '.trim($started['stderr']));
    try{$health=unmRecoveryWaitGuestHealthy((string)$context['identity']['vmUuid']);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)($record['activationId']??'')))throw new RuntimeException('Activation authority changed while starting the VM.');$record=unmRecoveryTransition($record,'RECOVERED_RUNNING');$record['activation']['phase']='RUNNING';$record['activation']['vmState']='running';$record['activation']['startedAt']=$record['activation']['startedAt']??gmdate('c');$record['activation']['healthAt']=$health['at'];unset($record['lastError']);return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}}
    catch(Throwable $e){try{unmRecoveryStopDomain((string)$context['identity']['vmUuid'],20,true);}catch(Throwable $stop){$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'SPLIT_BRAIN_UNRESOLVED',['authority'=>'UNKNOWN','lastError'=>$e->getMessage().' Local fencing failed: '.$stop->getMessage()]);unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}throw $stop;}$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['lastError'=>$e->getMessage()]);$record['activation']['vmState']='shut off';unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}throw $e;}
}

function unmRecoveryActivateUnlocked(string $replicationId,string $claimId,bool $startVm=true): array {
    if(!preg_match('/^claim-[a-f0-9]{24}$/',$claimId))throw new InvalidArgumentException('Invalid recovery claim id.');$context=unmRecoveryIdentityFromReplica($replicationId);$manifest=(array)$context['manifest'];$lock=unmRecoveryAcquireLock($replicationId);
    try{$record=unmRecoveryLoad((string)$context['path']);$claim=(array)($record['claim']??[]);$activationId=(string)($record['activationId']??'');$expires=strtotime((string)($claim['expiresAt']??''));if((string)$record['state']!=='RECOVERY_READY'||(string)$record['authority']!=='DESTINATION'||empty($claim['evidenceReady'])||!hash_equals($claimId,(string)($claim['claimId']??''))||$expires===false||$expires<=time()||!preg_match('/^act-[a-f0-9]{24}$/',$activationId))throw new RuntimeException('Recovery claim is stale, mismatched, or no longer ready.');$point=unmRecoveryFindPoint($manifest,(string)$claim['pointId']);}finally{unmRecoveryReleaseLock($lock);}
    $eligibility=unmRecoveryPointEligibility($manifest,$point,time(),true);if(empty($eligibility['eligible']))throw new RuntimeException(implode(' ',(array)$eligibility['reasons']));$checkpoint=unmRecoveryFindCheckpoint($manifest,$point,(string)($claim['checkpointId']??''));$expectedSelection=unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId);if(!hash_equals($expectedSelection,(string)($claim['selectionHash']??'')))throw new RuntimeException('Recovery claim selection hash does not match the exact point and checkpoint.');$plan=unmRecoveryActivationPlan($manifest,$point,$activationId);$sourceXml=(string)file_get_contents((string)$point['sourceXmlPath']);if(!hash_equals((string)$point['sourceXmlSha256'],hash('sha256',$sourceXml)))throw new RuntimeException('Recovery source XML changed after claim.');$runtime=unmRecoveryDomainRuntimePlan($sourceXml,(array)$plan['diskMaps']);if(empty($runtime['ready']))throw new RuntimeException(implode(' ',(array)$runtime['reasons']));$xml=unmRecoveryEmbedActivationMetadata((string)$runtime['xml'],$replicationId,$activationId);$hostStateBinding=unmRecoveryHostStateBinding($xml,(string)$context['identity']['vmUuid']);$root=(string)$plan['activationRoot'];$xmlPath=$root.'/domain.xml';$activation=['activationId'=>$activationId,'pointId'=>(string)$point['id'],'checkpointId'=>(string)($checkpoint['checkpointId']??''),'selectionHash'=>$expectedSelection,'objects'=>$plan['objects'],'activationRoot'=>$root,'xmlPath'=>$xmlPath,'xmlSha256'=>hash('sha256',$xml),'hostStateBinding'=>$hostStateBinding,'installedState'=>[],'vmState'=>'undefined','phase'=>'PREPARED','createdAt'=>gmdate('c'),'warnings'=>$runtime['warnings']];
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']!=='RECOVERY_READY'||!hash_equals($claimId,(string)($record['claim']['claimId']??'')))throw new RuntimeException('Recovery claim changed before activation transaction.');$record=unmRecoveryTransition($record,'ACTIVATING',['activation'=>$activation]);unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
    $defined=false;$created=[];$installed=[];try{unmRecoveryActivationPhase($context,$activationId,'CREATING_ROOT');if(file_exists($root)||is_link($root))throw new RuntimeException('Activation root already exists before its durable transaction.');if(!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Unable to create exact activation root.');unmRecoveryActivationPhase($context,$activationId,'WRITING_XML');if(file_exists($xmlPath)||is_link($xmlPath)||file_put_contents($xmlPath,$xml,LOCK_EX)!==strlen($xml)||!chmod($xmlPath,0600))throw new RuntimeException('Unable to write activation domain XML.');unmRecoveryAssertNoDomainConflict((string)$context['identity']['vmUuid'],(string)$context['identity']['vmName']);unmRecoveryActivationPhase($context,$activationId,'CREATING_STORAGE');$created=unmRecoveryCreateActivationObjects((array)$plan['objects'],$activationId);if($checkpoint){$intended=unmRecoveryInstallCheckpoint($checkpoint,(string)$context['identity']['vmUuid'],$activationId,$root,true,$hostStateBinding);unmRecoveryActivationPhase($context,$activationId,'INSTALLING_STATE',['objects'=>$created,'intendedState'=>$intended]);$installed=unmRecoveryInstallCheckpoint($checkpoint,(string)$context['identity']['vmUuid'],$activationId,$root,false,$hostStateBinding);}else unmRecoveryActivationPhase($context,$activationId,'STORAGE_READY',['objects'=>$created]);$freshContext=unmRecoveryIdentityFromReplica($replicationId);$freshPoint=unmRecoveryFindPoint((array)$freshContext['manifest'],(string)$claim['pointId']);$freshCheckpoint=unmRecoveryFindCheckpoint((array)$freshContext['manifest'],$freshPoint,(string)($claim['checkpointId']??''));$lock=unmRecoveryAcquireLock($replicationId);try{$freshRecord=unmRecoveryLoad((string)$freshContext['path']);$freshExpiry=strtotime((string)($freshRecord['claim']['expiresAt']??''));if((string)$freshRecord['state']!=='ACTIVATING'||$freshExpiry===false||$freshExpiry<=time()||!hash_equals($expectedSelection,(string)($freshRecord['claim']['selectionHash']??''))||!hash_equals($expectedSelection,unmRecoverySelectionHash($replicationId,$freshPoint,$freshCheckpoint,$activationId)))throw new RuntimeException('Recovery claim expired or its exact selection changed before VM definition.');}finally{unmRecoveryReleaseLock($lock);}unmRecoveryAssertNoDomainConflict((string)$context['identity']['vmUuid'],(string)$context['identity']['vmName']);unmRecoveryActivationPhase($context,$activationId,'DEFINING',['objects'=>$created,'installedState'=>$installed]);$define=unmRun(['virsh','define',$xmlPath],null,45);if($define['code']!==0)throw new RuntimeException('Unable to define recovered VM: '.trim($define['stderr']));$defined=true;if(!unmRecoveryDomainOwned((string)$context['identity']['vmUuid'],$replicationId,$activationId)||unmRecoveryVmState((string)$context['identity']['vmUuid'])!=='shut off')throw new RuntimeException('Recovered VM definition did not remain exact, owned, and stopped.');unmRecoverySetNativeAutostart((string)$context['identity']['vmUuid'],false);
        $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']!=='ACTIVATING'||!hash_equals($activationId,(string)$record['activationId']))throw new RuntimeException('Recovery authority changed during activation.');$record=unmRecoveryTransition($record,'RECOVERED_STOPPED');$record['activation']['objects']=$created;$record['activation']['installedState']=$installed;$record['activation']['vmState']='shut off';$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['definedAt']=gmdate('c');unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
    }catch(Throwable $e){if(!$defined){try{if($installed)unmRecoveryRemoveInstalledState($installed,(string)$context['identity']['vmUuid'],$activationId);if($created)unmRecoveryDestroyActivationObjects(array_reverse($created),$activationId);}catch(Throwable $cleanup){$e=new RuntimeException($e->getMessage().' Exact activation rollback failed: '.$cleanup->getMessage(),0,$e);}}else try{unmRecoveryStopDomain((string)$context['identity']['vmUuid'],10,true);}catch(Throwable $ignored){}$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']==='ACTIVATING'){$record=unmRecoveryTransition($record,'RECOVERY_BOOT_FAILED',['lastError'=>$e->getMessage()]);$record['activation']['objects']=$created?:$plan['objects'];$record['activation']['installedState']=$installed;$record['activation']['vmState']=$defined?'shut off':'undefined';$record['activation']['partial']=!$defined;unmRecoveryStore((string)$context['path'],$record);}}finally{unmRecoveryReleaseLock($lock);}throw $e;}
    return $startVm?unmRecoveryStartActivationUnlocked($replicationId):unmRecoveryLoad((string)$context['path']);
}

function unmRecoveryStopActivationUnlocked(string $replicationId): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!in_array((string)$record['state'],['ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'],true)||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Recovered activation is not stoppable under destination authority.');$activationId=(string)$record['activationId'];}finally{unmRecoveryReleaseLock($lock);}
    $actual=unmRecoveryVmState((string)$context['identity']['vmUuid']);if(!in_array($actual,['running','idle','paused','shut off'],true)||!unmRecoveryDomainOwned((string)$context['identity']['vmUuid'],$replicationId,$activationId))throw new RuntimeException('VM is not exactly owned by this activation or has an unsafe runtime state.');if($actual!=='shut off')unmRecoveryStopDomain((string)$context['identity']['vmUuid'],45,true);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId']))throw new RuntimeException('Activation authority changed while stopping the VM.');$record=unmRecoveryTransition($record,'RECOVERED_STOPPED');$record['activation']['phase']='DEFINED_STOPPED';$record['activation']['vmState']='shut off';$record['activation']['stoppedAt']=gmdate('c');return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryRetryCheckpointUnlocked(string $replicationId,string $checkpointId,bool $startVm=true): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$manifest=(array)$context['manifest'];$uuid=(string)$context['identity']['vmUuid'];$lock=unmRecoveryAcquireLock($replicationId);
    try{
        $record=unmRecoveryLoad((string)$context['path']);if(!in_array((string)$record['state'],['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED'],true)||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Checkpoint retry requires a stopped destination-authoritative activation.');$activation=(array)($record['activation']??[]);$activationId=(string)$record['activationId'];if(!hash_equals($activationId,(string)($activation['activationId']??'')))throw new RuntimeException('Activation ownership journal is incomplete.');$activationRoot=unmRecoveryAssertActivationRoot((string)($activation['activationRoot']??''),$uuid,$activationId);$xmlPath=(string)($activation['xmlPath']??'');if(!hash_equals($activationRoot.'/domain.xml',$xmlPath)||!is_file($xmlPath)||is_link($xmlPath)||!hash_equals((string)($activation['xmlSha256']??''),(string)hash_file('sha256',$xmlPath)))throw new RuntimeException('Activation XML journal is missing, moved, or changed before checkpoint retry.');$hostStateBinding=unmRecoveryHostStateBinding((string)file_get_contents($xmlPath),$uuid);if(!hash_equals((string)($activation['hostStateBinding']['bindingHash']??''),(string)$hostStateBinding['bindingHash']))throw new RuntimeException('Activation host-state binding changed before checkpoint retry.');$point=unmRecoveryFindPoint($manifest,(string)$activation['pointId']);$currentCheckpoint=(string)($activation['checkpointId']??'');if(!hash_equals($currentCheckpoint,$checkpointId))throw new RuntimeException('Beta2 checkpoint retry is bound to the already authorized checkpoint; selecting a different checkpoint requires a new coordinated recovery claim.');$checkpoint=unmRecoveryFindCheckpoint($manifest,$point,$checkpointId);$expectedSelection=unmRecoverySelectionHash($replicationId,$point,$checkpoint,$activationId);if(!hash_equals($expectedSelection,(string)($record['claim']['selectionHash']??''))||!hash_equals($expectedSelection,(string)($activation['selectionHash']??'')))throw new RuntimeException('Checkpoint retry selection no longer matches the exact authorized activation.');
    }finally{unmRecoveryReleaseLock($lock);}
    if(!$checkpoint)throw new RuntimeException('This activation has no TPM/NVRAM checkpoint to retry.');if(unmRecoveryVmState($uuid)!=='shut off'||!unmRecoveryDomainOwned($uuid,$replicationId,$activationId))throw new RuntimeException('Recovered VM is not exactly owned and stopped before checkpoint retry.');

    $priorJournal=(array)($activation['checkpointRetry']??[]);if(in_array((string)($priorJournal['phase']??''),['PREPARED','SWAPPING'],true)){
        $restored=unmRecoveryCheckpointRetryRestore($priorJournal,$uuid,$activationId,$activationRoot);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId']))throw new RuntimeException('Activation authority changed during checkpoint retry crash recovery.');$record['activation']['installedState']=$restored;$record['activation']['checkpointRetry']['phase']='ROLLED_BACK';$record['activation']['checkpointRetry']['rolledBackAt']=gmdate('c');$record['activation']['vmState']='shut off';unmRecoveryStore((string)$context['path'],$record);$activation=(array)$record['activation'];}finally{unmRecoveryReleaseLock($lock);}unmRecoveryRemoveExactTree((string)$priorJournal['backupRoot'],$activationRoot);
    }elseif(in_array((string)($priorJournal['phase']??''),['COMPLETE','ROLLED_BACK'],true)&&is_dir((string)($priorJournal['backupRoot']??''))&&!is_link((string)$priorJournal['backupRoot']))unmRecoveryRemoveExactTree((string)$priorJournal['backupRoot'],$activationRoot);

    unmRecoveryInstallCheckpoint($checkpoint,$uuid,$activationId,$activationRoot,true,$hostStateBinding);$retryId=unmRecoveryRandomId('retry');$backup=unmRecoveryCheckpointRetryBackup((array)($activation['installedState']??[]),$uuid,$activationId,$activationRoot,$retryId);$journal=['retryId'=>$retryId,'phase'=>'PREPARED','checkpointId'=>$checkpointId,'selectionHash'=>(string)($record['claim']['selectionHash']??''),'hostStateBindingHash'=>(string)$hostStateBinding['bindingHash'],'backupRoot'=>$backup['backupRoot'],'backups'=>$backup['backups'],'preparedAt'=>gmdate('c')];
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId'])||!in_array((string)$record['state'],['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED'],true))throw new RuntimeException('Activation authority changed before checkpoint retry prepare.');$record['activation']['checkpointRetry']=$journal;unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
    $sourceFence=unmRecoveryRevalidateAuthorityForStart($context,$record,$point);$sourceFenceExpiry=strtotime((string)($sourceFence['grantExpiresAt']??''));if($sourceFenceExpiry===false||$sourceFenceExpiry<=time()||!hash_equals($expectedSelection,(string)($sourceFence['selectionHash']??'')))throw new RuntimeException('Source did not return a fresh exact fence grant for checkpoint retry.');
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($retryId,(string)($record['activation']['checkpointRetry']['retryId']??''))||!hash_equals($expectedSelection,(string)($record['claim']['selectionHash']??'')))throw new RuntimeException('Checkpoint retry journal or selection changed before swap.');$record['activation']['checkpointRetry']['phase']='SWAPPING';$record['activation']['checkpointRetry']['sourceFenceGrant']=['selectionHash'=>$expectedSelection,'issuedAt'=>(string)($sourceFence['grantIssuedAt']??''),'expiresAt'=>(string)$sourceFence['grantExpiresAt']];$record['activation']['checkpointRetry']['swapStartedAt']=gmdate('c');unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}if($sourceFenceExpiry<=time())throw new RuntimeException('Fresh source fence grant expired before checkpoint retry swap.');
    try{
        $old=array_map(static fn(array $item):array=>(array)$item['installed'],(array)$backup['backups']);if($old)unmRecoveryRemoveInstalledState($old,$uuid,$activationId);$installed=unmRecoveryInstallCheckpoint($checkpoint,$uuid,$activationId,$activationRoot,false,$hostStateBinding);
        $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId'])||!hash_equals($retryId,(string)($record['activation']['checkpointRetry']['retryId']??'')))throw new RuntimeException('Activation authority changed during checkpoint retry.');$record['activation']['installedState']=$installed;$record['activation']['checkpointRetry']['phase']='COMPLETE';$record['activation']['checkpointRetry']['completedAt']=gmdate('c');$record['activation']['checkpointRetryAt']=gmdate('c');$record['activation']['vmState']='shut off';$record=unmRecoveryTransition($record,'RECOVERED_STOPPED');unset($record['lastError']);unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}unmRecoveryRemoveExactTree((string)$backup['backupRoot'],$activationRoot);
    }catch(Throwable $e){
        try{$restored=unmRecoveryCheckpointRetryRestore($journal,$uuid,$activationId,$activationRoot);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId']))throw new RuntimeException('Activation authority changed during checkpoint retry rollback.');$record['activation']['installedState']=$restored;$record['activation']['checkpointRetry']['phase']='ROLLED_BACK';$record['activation']['checkpointRetry']['rolledBackAt']=gmdate('c');$record['activation']['checkpointRetry']['error']=$e->getMessage();$record['activation']['vmState']='shut off';$record['lastError']=$e->getMessage();unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}unmRecoveryRemoveExactTree((string)$backup['backupRoot'],$activationRoot);}catch(Throwable $rollback){throw new RuntimeException($e->getMessage().' Exact checkpoint retry rollback failed: '.$rollback->getMessage(),0,$e);}throw $e;
    }
    return $startVm?unmRecoveryStartActivationUnlocked($replicationId):$record;
}

function unmRecoveryRemoveActivationUnlocked(string $replicationId,string $confirmation): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);if(!hash_equals('REMOVE '.(string)$context['identity']['vmName'],$confirmation))throw new RuntimeException('Activation removal confirmation phrase does not match the VM name.');$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!in_array((string)$record['state'],['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED'],true)||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Only a stopped destination-authoritative activation may be removed.');$activation=(array)($record['activation']??[]);$activationId=(string)$record['activationId'];if(!hash_equals($activationId,(string)($activation['activationId']??'')))throw new RuntimeException('Activation removal ownership journal is incomplete.');$root=unmRecoveryAssertActivationRoot((string)($activation['activationRoot']??''),(string)$context['identity']['vmUuid'],$activationId);}finally{unmRecoveryReleaseLock($lock);}
    $uuid=(string)$context['identity']['vmUuid'];$vmState=unmRecoveryVmState($uuid);if(!in_array($vmState,['shut off','undefined'],true))throw new RuntimeException('Recovered VM must be stopped before activation removal.');if($vmState!=='undefined'){if(!unmRecoveryDomainOwned($uuid,$replicationId,$activationId))throw new RuntimeException('Defined VM is not owned by this exact activation.');$args=['virsh','undefine',$uuid];$kinds=array_column((array)($activation['installedState']??[]),'kind');if(in_array('nvram',$kinds,true))$args[]='--keep-nvram';if(in_array('tpm',$kinds,true))$args[]='--keep-tpm';$undefine=unmRun($args,null,45);if($undefine['code']!==0)throw new RuntimeException('Unable to undefine exact recovered VM while retaining owned state for verified cleanup: '.trim($undefine['stderr']));if(unmRecoveryVmState($uuid)!=='undefined')throw new RuntimeException('Recovered VM definition remained after undefine.');}
    $stateJournal=[];foreach(array_merge((array)($activation['installedState']??[]),(array)($activation['intendedState']??[])) as $item)if(is_array($item))$stateJournal[(string)($item['kind']??'').'|'.(string)($item['path']??'')]=$item;$removedState=unmRecoveryRemoveInstalledState(array_values($stateJournal),$uuid,$activationId);$removedObjects=unmRecoveryDestroyActivationObjects(array_reverse((array)($activation['objects']??[])),$activationId);$base=dirname(dirname($root));if(file_exists($root)||is_link($root))unmRecoveryRemoveExactTree($root,$base);
    $lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if(!hash_equals($activationId,(string)$record['activationId']))throw new RuntimeException('Activation authority changed before removal commit.');$record=unmRecoveryTransition($record,'FENCED',['lastRemovedActivation'=>['activationId'=>$activationId,'removedAt'=>gmdate('c'),'removedObjects'=>$removedObjects,'removedState'=>$removedState],'lastError'=>'Recovered activation was removed. Source authority was not restored automatically.']);unset($record['activation'],$record['activationId'],$record['claim']);return unmRecoveryStore((string)$context['path'],$record);}finally{unmRecoveryReleaseLock($lock);}
}

function unmRecoveryWithVmLock(string $replicationId,callable $operation): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$vmLock=unmRecoveryAcquireVmLock((string)$context['identity']['vmUuid']);try{return $operation();}finally{unmRecoveryReleaseLock($vmLock);}
}

function unmRecoveryStartActivation(string $replicationId): array {return unmRecoveryWithVmLock($replicationId,static fn():array=>unmRecoveryStartActivationUnlocked($replicationId));}
function unmRecoveryActivate(string $replicationId,string $claimId,bool $startVm=true): array {return unmRecoveryWithVmLock($replicationId,static fn():array=>unmRecoveryActivateUnlocked($replicationId,$claimId,$startVm));}
function unmRecoveryStopActivation(string $replicationId): array {return unmRecoveryWithVmLock($replicationId,static fn():array=>unmRecoveryStopActivationUnlocked($replicationId));}
function unmRecoveryRetryCheckpoint(string $replicationId,string $checkpointId,bool $startVm=true): array {return unmRecoveryWithVmLock($replicationId,static fn():array=>unmRecoveryRetryCheckpointUnlocked($replicationId,$checkpointId,$startVm));}
function unmRecoveryRemoveActivation(string $replicationId,string $confirmation): array {return unmRecoveryWithVmLock($replicationId,static fn():array=>unmRecoveryRemoveActivationUnlocked($replicationId,$confirmation));}

function unmRecoveryColdFailbackPreflight(string $replicationId): array {
    $context=unmRecoveryIdentityFromReplica($replicationId);$lock=unmRecoveryAcquireLock($replicationId);try{$record=unmRecoveryLoad((string)$context['path']);if((string)$record['state']!=='RECOVERED_STOPPED'||(string)$record['authority']!=='DESTINATION')throw new RuntimeException('Cold failback preflight requires a stopped recovered activation.');$activation=(array)($record['activation']??[]);$activationId=(string)$record['activationId'];$term=(int)$record['term'];}finally{unmRecoveryReleaseLock($lock);}$reasons=[];$uuid=(string)$context['identity']['vmUuid'];if(unmRecoveryVmState($uuid)!=='shut off')$reasons[]='Recovered destination VM is not verifiably stopped.';if(!unmRecoveryDomainOwned($uuid,$replicationId,$activationId))$reasons[]='Recovered destination definition is not owned by the exact activation.';
    foreach((array)($activation['objects']??[]) as $object)try{unmRecoveryAssertActivationObject((array)$object,$activationId);}catch(Throwable $e){$reasons[]=$e->getMessage();}foreach((array)($activation['installedState']??[]) as $state){$path=(string)($state['path']??'');if(!unmRecoveryStateDestinationAllowed($path,(string)($state['kind']??''),$uuid)||!file_exists($path)||is_link($path))$reasons[]='Activation host state is missing or unsafe: '.$path;}
    try{$source=unmRecoveryRemoteCall($context,'recovery-failback-preflight',$term,['activationId'=>$activationId],45);if(empty($source['ready']))$reasons=array_merge($reasons,(array)($source['reasons']??['Source rejected cold failback preflight.']));}catch(Throwable $e){$source=[];$reasons[]='Source cold-failback check failed: '.$e->getMessage();}
    return ['ready'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'mode'=>'cold-preflight-only','transferStarted'=>false,'authorityChanged'=>false,'activationId'=>$activationId,'term'=>$term,'source'=>$source];
}

function unmRecoveryRunWorker(string $replicationId,string $expectedOperationId): array {
    unmReplicationPath($replicationId);if(!preg_match('/^op-[a-f0-9]{24}$/',$expectedOperationId))throw new InvalidArgumentException('Invalid recovery worker operation id.');unmRecoveryEnsureRuntimeDir();$operationLock=fopen(unmRecoveryOperationLockPath($replicationId),'c');if($operationLock===false)throw new RuntimeException('Unable to open recovery operation lock.');@chmod(unmRecoveryOperationLockPath($replicationId),0600);if(!flock($operationLock,LOCK_EX|LOCK_NB)){fclose($operationLock);throw new RuntimeException('Another recovery worker already owns this policy operation.');}
    try{$request=unmLoadJson(UNM_RECOVERY_RUNTIME_DIR.'/'.$replicationId.'.request.json');$operation=unmRecoveryOperation($replicationId);if(!$request||($request['schemaVersion']??null)!==1||!hash_equals($replicationId,(string)($request['replicationId']??''))||!hash_equals($expectedOperationId,(string)($request['operationId']??''))||!hash_equals($expectedOperationId,(string)($operation['operationId']??''))||(string)($operation['state']??'')!=='QUEUED')throw new RuntimeException('Recovery worker request was replaced, consumed, or is invalid.');$type=(string)($request['type']??'');$parameters=(array)($request['parameters']??[]);unmRecoveryOperationUpdate($replicationId,$type,'RUNNING',2,'Recovery worker started.',['pid'=>getmypid(),'operationId'=>$expectedOperationId]);
        try{$result=match($type){'evidence'=>unmRecoveryCollectEvidence($replicationId,$parameters),'activate'=>unmRecoveryActivate($replicationId,(string)($parameters['claimId']??''),!empty($parameters['startVm'])),'start-activation'=>unmRecoveryStartActivation($replicationId),'retry-checkpoint'=>unmRecoveryRetryCheckpoint($replicationId,(string)($parameters['checkpointId']??''),!empty($parameters['startVm'])),'stop-activation'=>unmRecoveryStopActivation($replicationId),'remove-activation'=>unmRecoveryRemoveActivation($replicationId,(string)($parameters['confirmation']??'')),default=>throw new InvalidArgumentException('Unsupported recovery worker operation.')};return unmRecoveryOperationUpdate($replicationId,$type,'COMPLETE',100,'Recovery operation completed.',['completedAt'=>gmdate('c'),'result'=>is_array($result)?unmRecoveryPublicRecord($result):$result]);}
        catch(Throwable $e){unmRecoveryOperationUpdate($replicationId,$type,'FAILED',100,$e->getMessage(),['failedAt'=>gmdate('c'),'error'=>$e->getMessage()]);throw $e;}
    }finally{flock($operationLock,LOCK_UN);fclose($operationLock);}
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
    $matches=[];foreach(unmPeers() as $peer)if(($peer['hostId']??'')===$hostId)$matches[]=$peer;
    if(count($matches)>1)throw new RuntimeException('Multiple peer records match host ID '.$hostId.'; remove stale pairing records before continuing.');
    if(!$matches)throw new RuntimeException('No paired peer matches host ID '.$hostId.'.');
    return $matches[0];
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
        $staleState=[];$staleVm['name']=(string)($request['vmName']??$staleVm['name']);
        if($nvram!==''){$owned=unmOwnedNvram($staleVm);$staleState[]=unmHostStateEvidence($owned['source'],'nvram',$uuid);}
        $staleTpm=unmTpmStatePath($uuid,$xml);
        if($staleTpm!=='')$staleState[]=unmHostStateEvidence($staleTpm,'tpm',$uuid);
        unmValidateHostStateManifest($staleState,$uuid,$nvram,$staleTpm);
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
        foreach($staleState as $evidence){unmRemoveHostStateEvidence($evidence);$removed[]=$evidence['path'];}
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
    $manifest=unmLoadJson(UNM_JOBS_DIR.'/'.$jobId.'/source-cleanup.json');
    $uuid=strtolower((string)($manifest['vmUuid']??''));
    if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$uuid))throw new RuntimeException('Cleanup lock identity is unavailable.');
    $global=unmResolutionLock('/var/run/unmotion.lock');$vmLock=null;
    try{
        $vmLock=unmResolutionLock('/var/lock/unmotion-'.$uuid.'.lock');
        return unmFinalizeSourceCleanupLocked($jobId,$destinationHostId,$uuid);
    }finally{if(is_resource($vmLock)){flock($vmLock,LOCK_UN);fclose($vmLock);}flock($global,LOCK_UN);fclose($global);}
}

function unmFinalizeSourceCleanupLocked(string $jobId, string $destinationHostId,string $lockedUuid): array {
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $jobId)) throw new InvalidArgumentException('Invalid cleanup job ID.');
    $jobDir = UNM_JOBS_DIR . '/' . $jobId;
    $manifestPath = $jobDir . '/source-cleanup.json';
    $manifest = unmLoadJson($manifestPath);
    if (!$manifest) throw new RuntimeException('Source cleanup manifest was not found.');
    $uuid = trim((string)($manifest['vmUuid'] ?? ''));
    if (!preg_match('/^[A-Fa-f0-9-]{32,36}$/', $uuid)) throw new RuntimeException('Source cleanup manifest has an invalid VM UUID.');
    if(strtolower($uuid)!==$lockedUuid||($manifest['jobId']??'')!==$jobId||($manifest['sourceHostId']??'')!==unmHostId())throw new RuntimeException('Cleanup manifest identity changed or belongs to another source.');
    if (($manifest['policy'] ?? '') !== 'delete') throw new RuntimeException('This migration was not authorised to delete its source copy.');
    if (($manifest['destinationHostId'] ?? '') !== $destinationHostId) throw new RuntimeException('Cleanup destination identity does not match the migration manifest.');
    $marker = unmLoadJson(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json');
    if(($marker['jobId']??'')!==$jobId||($marker['vmUuid']??'')!==$uuid||($marker['ownerHostId']??'')!==$destinationHostId)throw new RuntimeException('Cleanup ownership does not match the exact job and VM.');
    $completedJob=unmLoadJson($jobDir.'/job.json');
    if(($marker['state']??'')==='source_deleted'&&($completedJob['state']??'')==='COMPLETE_CLEANED'&&($completedJob['sourceCleanup']['state']??'')==='complete'&&($completedJob['request']['VM_UUID']??'')===$uuid){
        return ['success'=>true,'removed'=>(array)($completedJob['sourceCleanup']['removed']??[]),'alreadyCompleted'=>true];
    }
    if(is_file($jobDir.'/manual-resolution.json')){
        $resolutionJob=unmLoadJson($jobDir.'/job.json');
        unmResolutionDeleteManifest($jobDir,$resolutionJob,(string)file_get_contents($jobDir.'/source.xml'),$destinationHostId);
    }
    $marker = unmLoadJson(UNM_OWNERSHIP_DIR . '/' . $uuid . '.json');
    if (($marker['ownerHostId'] ?? '') !== $destinationHostId || !in_array((string)($marker['state'] ?? ''), ['pending_cleanup','migrated_out'], true)) {
        throw new RuntimeException('Ownership marker does not authorise source deletion.');
    }

    $removed = [];
    $nvram = trim((string)($manifest['nvramPath'] ?? ''));
    $tpm = trim((string)($manifest['tpmPath'] ?? ''));
    $hostState=(array)($manifest['hostState']??[]);
    $sourceState=unmResolutionVmState($uuid);
    if(!in_array($sourceState,['shut off','undefined'],true))throw new RuntimeException('Source state cannot be proved safe for cleanup.');
    unmValidateHostStateManifest($hostState,$uuid,$nvram,$tpm);
    if ($sourceState==='shut off') {
        if (unmResolutionVmState($uuid)!=='shut off') throw new RuntimeException('Source VM is no longer shut off; refusing cleanup.');
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
    foreach($hostState as $evidence){
        // A variables file inside an already-removed dataset is already gone.
        if(!file_exists($evidence['path'])&&!is_link($evidence['path']))continue;
        unmRemoveHostStateEvidence($evidence);$removed[]=$evidence['path'];
    }

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
    $errors=$seedCompatibilityErrors;try{unmRecoveryAssertLegacyVmAvailable((string)$uuid,$migrationMode==='cold'?'Move':'Warm Move');}catch(Throwable $e){$errors[]=$e->getMessage();}$warnings=[]; $plan=[]; $required=['zvol'=>0,'dataset'=>0,'image'=>0,'iso'=>0,'sourceStaging'=>0];
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
    try {
        $firmware=unmFirmwareRequest(unmDomainXml($uuid,true));
        if($firmware){
            if(empty($features['firmwareMapping']))throw new RuntimeException('Firmware verification requires unMotion beta4 or later on the destination.');
            $r=unmRemote($peer,'/usr/local/sbin/unmotion-agent firmware-plan '.escapeshellarg(base64_encode(json_encode($firmware,JSON_THROW_ON_ERROR))),45);
            if($r['code']!==0)throw new RuntimeException('Destination firmware compatibility: '.trim($r['stderr'].' '.$r['stdout']));
        }
        if(!empty($local['tpm']))unmTpmStatePath($uuid,unmDomainXml($uuid,true));
    }catch(Throwable $e){$errors[]=$e->getMessage();}

    $remoteVmName=''; $remoteVmState=''; $sameUuidVm=false;
    if($uuid!=='') {
        $r=unmRemote($peer,'virsh domname '.escapeshellarg($uuid).' 2>/dev/null',15);
        if($r['code']===0 && trim($r['stdout'])!=='') {
            $sameUuidVm=true; $remoteVmName=trim($r['stdout']);
            $stateResult=unmRemote($peer,'virsh domstate '.escapeshellarg($uuid).' 2>/dev/null',15);
            $remoteVmState=trim($stateResult['stdout']);
            $conflicts[]=['kind'=>'vm','path'=>$remoteVmName,'state'=>$remoteVmState,'sameUuid'=>true];
            if(!in_array($remoteVmState,['shut off','no state',''],true)) $errors[]='A VM with this UUID is running on the destination and cannot be overwritten.';
            if($conflictAction==='overwrite'){
                try{
                    $xmlResult=unmRemote($peer,'virsh dumpxml '.escapeshellarg($uuid).' --inactive',20);
                    if($xmlResult['code']!==0)throw new RuntimeException('Unable to inspect destination NVRAM before overwrite.');
                    $oldNvram=unmMigrationNvramXml($xmlResult['stdout']);
                    if($oldNvram!==''&&!unmNvramPoolPath($oldNvram)&&!unmRecoveryStateDestinationAllowed($oldNvram,'nvram',$uuid))throw new RuntimeException('Destination NVRAM filename is not UUID-scoped; automatic overwrite is refused. Retain it for manual inspection.');
                }catch(Throwable $e){$errors[]=$e->getMessage();}
            }
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
        elseif($isoAction==='copy'){
            $check=unmRemote($peer,'if test -e '.escapeshellarg($dst).' || test -L '.escapeshellarg($dst).'; then test -f '.escapeshellarg($dst).' && test ! -L '.escapeshellarg($dst).' && sha256sum -- '.escapeshellarg($dst).'; else printf MISSING; fi',300);
            if($check['code']!==0)$errors[]='Unable to verify destination ISO: '.$dst;
            elseif(trim($check['stdout'])!=='MISSING'&&!hash_equals((string)hash_file('sha256',$iso),substr(trim($check['stdout']),0,64)))$errors[]='Destination ISO filename already exists with different contents: '.$dst.'. Rename the ISO or choose omit/retain explicitly.';
        }
    }

    try {
        $nvramMaps=[];
        $local['nvram']=unmMigrationNvramXml(unmDomainXml((string)$local['uuid'],true));
        foreach($plan as $item){
            if(($item['kind']??'')==='zfs-filesystem')foreach($item['files']??[] as $file)$nvramMaps[]=array_merge($file,['bundled'=>true]);
            elseif(($item['kind']??'')==='image')$nvramMaps[]=$item+['bundled'=>false];
        }
        $nvramPlan=unmMigrationNvramPlan($local,$nvramMaps,$caps);
        if($sourceCleanupAction==='delete'&&$nvramPlan['kind']==='legacy'&&!unmRecoveryStateDestinationAllowed($nvramPlan['source'],'nvram',(string)$local['uuid']))throw new RuntimeException('Source NVRAM filename is not UUID-scoped. Select retain or unregister; automatic source deletion is blocked.');
        if($nvramPlan['kind']==='custom'){
            $result=unmRemote($peer,'/usr/local/sbin/unmotion-agent migration-nvram-check '.escapeshellarg(base64_encode(json_encode($nvramPlan,JSON_THROW_ON_ERROR))),30);
            if($result['code']!==0)throw new RuntimeException('Destination UEFI NVRAM check failed: '.trim($result['stderr'].' '.$result['stdout']));
            if(!$nvramPlan['bundled'])$required['image']+=$nvramPlan['bytes'];
            $plan[]=$nvramPlan+['transferClass'=>$nvramPlan['bundled']?'dataset-contained-nvram':'file-nvram'];
        }
    }catch(Throwable $e){$errors[]=$e->getMessage();}

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
