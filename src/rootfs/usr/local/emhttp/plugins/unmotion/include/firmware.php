<?php
declare(strict_types=1);

function unmFirmwareFile(string $path): array {
    if(!str_starts_with($path,'/')||preg_match('~[\x00-\x1f\x7f]|/(?:\.|\.\.)(?:/|$)~',$path)||!is_file($path)||!is_readable($path))throw new RuntimeException('Firmware file is missing or unsafe: '.$path);
    $size=filesize($path);if(!$size||$size>67108864)throw new RuntimeException('Firmware file size is unsupported.');
    return ['path'=>$path,'sha256'=>hash_file('sha256',$path),'bytes'=>$size];
}
function unmFirmwareRequest(string $xml): array {
    $doc=new DOMDocument();if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Invalid firmware XML.');
    $xp=new DOMXPath($doc);$request=[];
    foreach(['loader'=>'/domain/os/loader','template'=>'/domain/os/nvram/@template'] as $kind=>$query){
        $nodes=$xp->query($query);if($nodes->length>1)throw new RuntimeException('Multiple firmware descriptions are unsupported.');
        if($nodes->length&&trim($nodes->item(0)->textContent)!=='')$request[$kind]=unmFirmwareFile(trim($nodes->item(0)->textContent));
    }
    return $request;
}
function unmFirmwareCandidates(array $request): array {
    $paths=array_column($request,'path');
    foreach(['/usr/share/qemu/firmware','/etc/qemu/firmware'] as $directory)foreach(glob($directory.'/*.json')?:[] as $file){
        $descriptor=json_decode((string)file_get_contents($file),true);
        foreach(['executable','nvram-template'] as $part){$path=$descriptor['mapping'][$part]['filename']??null;if(is_string($path))$paths[]=$path;}
    }
    // Unraid's OVMF builds are not all registered in QEMU's JSON catalogue.
    if(is_dir('/usr/share/qemu'))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/usr/share/qemu',FilesystemIterator::SKIP_DOTS)) as $file){
        if($file->isFile()&&str_ends_with(strtolower($file->getFilename()),'.fd'))$paths[]=$file->getPathname();
    }
    return array_values(array_unique($paths));
}
function unmFirmwareResolve(array $request,?array $candidates=null): array {
    if(array_diff(array_keys($request),['loader','template']))throw new RuntimeException('Unsupported firmware request.');
    $candidates??=unmFirmwareCandidates($request);$result=[];$cache=[];
    foreach($request as $kind=>$source){
        if(!is_array($source)||!preg_match('/^[a-f0-9]{64}$/',(string)($source['sha256']??''))||($source['bytes']??0)<1||($source['bytes']??0)>67108864)throw new RuntimeException('Invalid firmware fingerprint.');
        $ordered=array_unique(array_merge([(string)$source['path']],$candidates));$selected='';
        foreach($ordered as $path){
            try{$entry=$cache[$path]??=unmFirmwareFile($path);}catch(Throwable $e){continue;}
            if($entry['bytes']===$source['bytes']&&hash_equals($source['sha256'],$entry['sha256'])){$selected=$path;break;}
        }
        if($selected==='')throw new RuntimeException('No byte-identical destination firmware '.$kind.' matches '.$source['path'].'. Automatic firmware upgrades are not supported.');
        $result[$kind]=['source'=>$source['path'],'destination'=>$selected,'sha256'=>$source['sha256'],'bytes'=>$source['bytes']];
    }
    return $result;
}
