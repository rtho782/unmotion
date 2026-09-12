<?php
declare(strict_types=1);

// Post-recovery beta3 support: a pool-resident variables file belongs to a VM
// only when it is a regular sibling of a mapped disk in that VM's directory.
function unmNvramPoolPath(string $path): bool {
    return str_starts_with($path, '/mnt/') && !preg_match('~[\x00-\x1f\x7f]|//|/(?:\.|\.\.)(?:/|$)|^/mnt/(?:user|user0)(?:/|$)~', $path);
}

function unmNvramRegularFile(string $path, bool $pool): void {
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || !is_readable($path)) throw new RuntimeException('UEFI NVRAM is missing, unreadable, or a symbolic link: '.$path);
    if ($pool && (!unmNvramPoolPath($path) || realpath($path) !== $path || !str_ends_with(strtolower($path), '.fd') || (int)fileinode($path) === 0 || (int)(stat($path)['nlink'] ?? 0) !== 1)) throw new RuntimeException('Pool-resident UEFI NVRAM must be a direct, non-linked .fd file: '.$path);
    $size=filesize($path);
    if ($size === false || $size < 1 || $size > 67108864) throw new RuntimeException('UEFI NVRAM has an unsupported size (expected 1 byte to 64 MiB): '.$path);
}

function unmNvramOtherVmReferences(string $uuid, string $directory): void {
    $list=unmRun(['virsh','list','--all','--uuid'],null,20);
    if ($list['code'] !== 0) throw new RuntimeException('Unable to check other VM ownership of UEFI NVRAM.');
    foreach (preg_split('/\s+/',trim($list['stdout'])) ?: [] as $other) {
        if ($other === '' || strcasecmp($other,$uuid) === 0) continue;
        // Both live and next-boot definitions can own storage.
        foreach (array_unique([unmDomainXml($other,true),unmDomainXml($other,false)]) as $xml) {
        $doc=new DOMDocument();
        if (!@$doc->loadXML($xml,LIBXML_NONET) || $doc->doctype) throw new RuntimeException('Unable to parse VM ownership inventory.');
        $xp=new DOMXPath($doc);
        foreach ($xp->query('/domain/os/nvram | /domain/os/nvram/source/@file | /domain/os/loader | /domain/devices/disk/source/@file') as $node) {
            $path=trim($node->textContent);
            $resolved=realpath($path);
            if (unmPathWithin($path,$directory) || ($resolved !== false && unmPathWithin($resolved,$directory))) throw new RuntimeException('UEFI NVRAM directory is referenced by another VM: '.$directory);
        }
        }
    }
}

function unmMigrationNvramXml(string $xml): string {
    $doc=new DOMDocument();
    if (!@$doc->loadXML($xml,LIBXML_NONET) || $doc->doctype) throw new RuntimeException('Invalid source XML.');
    $nodes=(new DOMXPath($doc))->query('/domain/os/nvram');
    if ($nodes->length>1) throw new RuntimeException('Multiple NVRAM descriptions are unsupported.');
    foreach ($nodes as $node) {
        if ($node->getElementsByTagName('*')->length) throw new RuntimeException('Nested NVRAM storage descriptions are not supported; use a direct file path.');
        if (trim($node->textContent)==='') throw new RuntimeException('UEFI NVRAM file path is unavailable.');
        return trim($node->textContent);
    }
    return '';
}

function unmMigrationNvramPlan(array $vm, array $diskMaps, array $caps, bool $checkInventory=true): array {
    $source=trim((string)($vm['nvram'] ?? ''));
    if ($source === '') return ['kind'=>'none','source'=>'','destination'=>'','bundled'=>false,'bytes'=>0];
    $standard=str_starts_with($source,'/etc/libvirt/qemu/nvram/') || str_starts_with($source,'/var/lib/libvirt/qemu/nvram/');
    unmNvramRegularFile($source,!$standard);
    if ($standard) return ['kind'=>'legacy','source'=>$source,'destination'=>$source,'bundled'=>false,'bytes'=>(int)filesize($source)];
    if (empty($caps['features']['customNvramMigration'])) throw new RuntimeException('Pool-resident UEFI NVRAM requires unMotion 0.4.0-beta3 or later on the destination.');
    $uuid=(string)($vm['uuid'] ?? '');$name=(string)($vm['name'] ?? '');$directory=dirname($source);
    if (!preg_match('/^[a-f0-9-]{36}$/i',$uuid) || !in_array(basename($directory),[$name,$uuid],true)) throw new RuntimeException('Custom UEFI NVRAM must be beside a disk in a directory named for the VM or its UUID: '.$source);
    $matches=[];
    foreach ($diskMaps as $map) {
        $disk=(string)($map['source']??'');$destination=(string)($map['destination']??'');
        if ($disk === $source) throw new RuntimeException('UEFI NVRAM must not also be a writable VM disk.');
        if (dirname($disk) !== $directory || !is_file($disk) || is_link($disk) || realpath($disk) !== $disk) continue;
        if (!unmNvramPoolPath($destination)) throw new RuntimeException('Unsafe destination disk path for custom UEFI NVRAM.');
        $target=dirname($destination).'/'.basename($source);
        $matches[$target]=['diskSource'=>$disk,'diskDestination'=>$destination,'bundled'=>(bool)($map['bundled']??false)];
    }
    if (count($matches) !== 1) throw new RuntimeException('UEFI NVRAM has no unambiguous sibling disk mapping: '.$source);
    $destination=(string)array_key_first($matches);
    foreach ($diskMaps as $map) if (($map['destination']??'') === $destination) throw new RuntimeException('UEFI NVRAM destination collides with a VM disk.');
    $root=rtrim((string)($caps['storage']['imageDirectory']??''),'/');
    if (!unmNvramPoolPath($root) || !unmPathWithin($destination,$root) || dirname($destination) === $root) throw new RuntimeException('UEFI NVRAM destination is outside the VM image directory.');
    if ($checkInventory) unmNvramOtherVmReferences($uuid,$directory);
    return array_merge(['kind'=>'custom','source'=>$source,'destination'=>$destination,'vmUuid'=>$uuid,'vmName'=>$name,'bytes'=>(int)filesize($source)],$matches[$destination]);
}

function unmMigrationNvramDestination(array $plan, bool $verify=false): void {
    if (($plan['kind']??'') !== 'custom') throw new RuntimeException('Expected a custom NVRAM plan.');
    $destination=(string)($plan['destination']??'');$disk=(string)($plan['diskDestination']??'');
    $uuid=(string)($plan['vmUuid']??'');$name=(string)($plan['vmName']??'');
    $root=rtrim((string)unmLoadConfig()['image_dir'],'/');
    if (!preg_match('/^[a-f0-9-]{36}$/i',$uuid) || !unmNvramPoolPath($destination) || !unmPathWithin($destination,$root) || dirname($destination)===$root || dirname($destination)!==dirname($disk) || $destination===$disk || !in_array(basename(dirname($destination)),[$name,$uuid],true) || !str_ends_with(strtolower($destination),'.fd')) throw new RuntimeException('Unsafe destination UEFI NVRAM mapping.');
    // Reject symlink parents, including those that do not yet contain a file.
    for ($parent=dirname($destination); $parent !== '/'; $parent=dirname($parent)) if (is_link($parent)) throw new RuntimeException('UEFI NVRAM destination has a symbolic-link parent.');
    unmNvramOtherVmReferences($uuid,dirname($destination));
    if ($verify) {
        unmNvramRegularFile($destination,true);
        $sha=(string)($plan['sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/',$sha) || !hash_equals($sha,(string)hash_file('sha256',$destination))) throw new RuntimeException('Transferred UEFI NVRAM failed SHA-256 verification.');
    } elseif (is_link($destination) || (file_exists($destination) && empty($plan['bundled']))) {
        throw new RuntimeException('Custom UEFI NVRAM destination already exists; it will not be overwritten: '.$destination);
    }
}
