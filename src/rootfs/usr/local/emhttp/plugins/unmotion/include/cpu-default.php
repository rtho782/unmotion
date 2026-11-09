<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** A default is a frozen, locally validated definition, never a floating alias. */
function unmCpuDefaultResolve(array $selection,array $view): array {
    $id=$selection['mode']??'';$hash=$selection['definition_hash']??'';$identity=$selection['cache_identity']??'';
    if(!is_string($id)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,191}\z/D',$id)||!is_string($hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$hash)||!is_string($identity)||!preg_match('/\A[a-f0-9]{64}\z/D',$identity))throw new InvalidArgumentException('Invalid default CPU selection.');
    if(empty($view['available'])||!hash_equals($identity,(string)($view['cache']['identity']??'')))throw new RuntimeException('The CPU cache changed or is unavailable. Read CPU compatibility again.');
    $modes=array_values(array_filter($view['modes']??[],static fn($m)=>($m['id']??'')===$id&&($m['status']??'')==='supported'));
    if(count($modes)!==1||!hash_equals($hash,(string)($modes[0]['definitionHash']??'')))throw new RuntimeException('The selected CPU mode is not validated in the current cache.');
    $cpu=unmCpuCompatibilityDefinition((string)$modes[0]['cpuXml']);
    if(!hash_equals($hash,hash('sha256',$cpu)))throw new RuntimeException('Invalid cached CPU definition.');
    $d=unmCpuCompatibilityXml($cpu);$xp=new DOMXPath($d);
    if($xp->query('/cpu/feature[@name="lm" and @policy="require"]')->length!==1)throw new RuntimeException('The default must support x86_64 long mode.');
    $xp->query('/cpu/model')->item(0)->nodeValue=$id;
    return ['schemaVersion'=>1,'mode'=>$id,'definition_hash'=>$hash,'cache_identity'=>$identity,'cpuXml'=>unmCpuCompatibilityDefinition($d->saveXML())];
}
function unmCpuDefaultRead(): ?array {
    $path=UNM_BOOT_DIR.'/cpu-default.json';if(!file_exists($path))return null;
    if(is_link($path))throw new RuntimeException('Redirected CPU default.');
    $r=unmLoadJson($path);if(($r['schemaVersion']??0)!==1)throw new RuntimeException('Invalid CPU default.');
    $expected=unmCpuDefaultResolve($r,unmCpuCompatibilityLocalOverview(false));
    if(!hash_equals($expected['cpuXml'],(string)($r['cpuXml']??'')))throw new RuntimeException('The saved CPU default needs review.');
    return $expected;
}
function unmCpuDefaultStatus(): array {
    try{$r=unmCpuDefaultRead();return ['configured'=>$r!==null,'mode'=>$r['mode']??null,'error'=>'','editor'=>unmCpuEditorStatus()];}
    catch(Throwable $e){return ['configured'=>true,'mode'=>null,'error'=>$e->getMessage(),'editor'=>unmCpuEditorStatus()];}
}
function unmCpuDefaultSave(?array $selection): array {
    $lock=unmNativeLock('cpu-default','/var/lock');
    try{
        $path=UNM_BOOT_DIR.'/cpu-default.json';if(is_link($path))throw new RuntimeException('Redirected CPU default.');
        if($selection===null){if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot clear the CPU default.');}
        else{
            if(!unmCpuEditorStatus()['available'])throw new RuntimeException('The native VM editor adapter is unavailable on this Unraid build.');
            $r=unmCpuDefaultResolve($selection,unmCpuCompatibilityLocalOverview(false));
            unmCpuEditorCompare($r['cpuXml'],'q35');unmCpuEditorCompare($r['cpuXml'],'pc');
            unmAtomicJson($path,$r+['updatedAt'=>gmdate('c')]);unmLiveCommand(['sync','-f',$path]);
        }
        return unmCpuDefaultStatus();
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

/** Native editor integration is deliberately limited to reviewed core sources. */
function unmCpuEditorPatches(): array {
    return [
        'templates/Custom.form.php'=>['hash'=>'f27e0301e42fddc4d4a26dfd3d056463a1aee84b9200108391f05d2236efd79c','edits'=>[
            ["// create new VM\nif (isset(\$_POST['createvm'])) {","require_once '/usr/local/emhttp/plugins/unmotion/include/cpu-editor.php';\nunmCpuEditorRequest(\$arrConfigDefaults,\$arrAllTemplates[\$strSelectedTemplate]['overrides']??[],\$lv);\n// create new VM\nif (isset(\$_POST['createvm'])) {"],
            ["\$xml = custom::createXML('domain',\$arrConfig)->saveXML();","\$xml = custom::createXML('domain',\$arrConfig)->saveXML();\n\t\t\t\$xml = unmCpuEditorFinalXml(\$xml,\$_POST);"],
            ["mk_dropdown_options(['host-passthrough' => _('Host Passthrough').' ('.\$strCPUModel.')', 'custom' => _('Emulated').' ('._('QEMU64').')'], \$arrConfig['domain']['cpumode']);","mk_dropdown_options(unmCpuEditorOptions(\$arrConfig,\$boolNew,\$strXML,['host-passthrough' => _('Host Passthrough').' ('.\$strCPUModel.')', 'custom' => _('Emulated').' ('._('QEMU64').')']), \$arrConfig['domain']['cpumode']);"]
        ]],
        'include/libvirt.php'=>['hash'=>'0148936c74ffffbcc80d73dc1be7f16ea26a37416e52c69c970abb4aa3308c2f','edits'=>[
            ["\$usbmode = 'usb3';","if (is_file('/usr/local/emhttp/plugins/unmotion/include/cpu-editor.php')) {\n\t\t\trequire_once '/usr/local/emhttp/plugins/unmotion/include/cpu-editor.php';\n\t\t\t\$cpustr = unmCpuEditorFragment(\$cpustr,\$config);\n\t\t}\n\t\t\$usbmode = 'usb3';"]
        ]]
    ];
}
function unmCpuEditorPatch(string $source,array $spec): string {
    if(!hash_equals($spec['hash'],hash('sha256',$source)))throw new RuntimeException('Unreviewed native VM editor source; no files were changed.');
    foreach($spec['edits'] as [$from,$to]){if(substr_count($source,$from)!==1)throw new RuntimeException('Native VM editor anchor changed.');$source=str_replace($from,$to,$source);}
    return $source;
}
function unmCpuEditorStatus(string $core='/usr/local/emhttp/plugins/dynamix.vm.manager'): array {
    foreach(unmCpuEditorPatches() as $file=>$spec){
        $path=$core.'/'.$file;if(!is_file($path)||is_link($path))return ['available'=>false,'error'=>'The native VM editor is unavailable.'];
        // Derive the installed adapter hash from the private original, not a mutable manifest.
        $backup=UNM_BOOT_DIR.'/cpu-editor-backups/'.basename($file).'.original';
        try{$original=(string)@file_get_contents($backup);$expected=hash('sha256',unmCpuEditorPatch($original,$spec));}
        catch(Throwable $e){return ['available'=>false,'error'=>'Native VM editor integration is not installed for this Unraid build.'];}
        if(!hash_equals($expected,(string)hash_file('sha256',$path)))return ['available'=>false,'error'=>'Native VM editor integration changed; default CPU selection is disabled.'];
    }
    return ['available'=>true,'error'=>''];
}
function unmCpuEditorInstall(bool $restore=false,string $core='/usr/local/emhttp/plugins/dynamix.vm.manager'): void {
    $lock=unmNativeLock('cpu-editor','/var/lock');$dir=UNM_BOOT_DIR.'/cpu-editor-backups';$staged=[];
    try{
        unmLivePrivateDir($dir);$pending=[];
        foreach(unmCpuEditorPatches() as $file=>$spec){
            $path=$core.'/'.$file;$backup=$dir.'/'.basename($file).'.original';
            if(is_link($path)||!is_file($path)||is_link($backup))throw new RuntimeException('Unsafe native VM editor path.');
            $current=(string)file_get_contents($path);$original=is_file($backup)?(string)file_get_contents($backup):$current;
            if($restore&&!str_contains($current,'unmCpuEditor')&&!hash_equals($spec['hash'],hash('sha256',$current)))continue;
            $patched=unmCpuEditorPatch($original,$spec);
            if(!hash_equals($spec['hash'],hash('sha256',$current))&&!hash_equals(hash('sha256',$patched),hash('sha256',$current)))throw new RuntimeException('Native VM editor changed outside unMotion; refusing to overwrite it.');
            $pending[$path]=['target'=>$restore?$original:$patched,'backup'=>$backup,'original'=>$original,'currentHash'=>hash('sha256',$current)];
        }
        // Validate both sources before changing either file. Backups survive upgrades/reboots.
        foreach($pending as $path=>$r){
            if(!is_file($r['backup'])){if(file_put_contents($r['backup'],$r['original'],LOCK_EX)!==strlen($r['original']))throw new RuntimeException('Cannot back up the native VM editor.');chmod($r['backup'],0600);unmLiveCommand(['sync','-f',$r['backup']]);}
            $tmp=$path.'.unmotion-'.bin2hex(random_bytes(6));
            $staged[$path]=$tmp;
            if(file_put_contents($tmp,$r['target'],LOCK_EX)!==strlen($r['target']))throw new RuntimeException('Cannot stage native VM editor integration.');
            chmod($tmp,fileperms($path)&0777);
            $check=unmRun(['php','-d','short_open_tag=1','-l',$tmp],null,15);if($check['code']!==0)throw new RuntimeException('Native VM editor syntax validation failed.');
        }
        foreach($pending as $path=>$r)if(!hash_equals($r['currentHash'],(string)hash_file('sha256',$path)))throw new RuntimeException('The native VM editor changed while staging; no integration files were replaced.');
        foreach($staged as $path=>$tmp)if(!rename($tmp,$path))throw new RuntimeException('Cannot install native VM editor integration.');
    }finally{foreach($staged as $tmp)if(is_file($tmp))unlink($tmp);flock($lock,LOCK_UN);fclose($lock);}
}
function unmCpuEditorCompare(string $cpu,string $machine): void {
    if(unmCpuCompatibilityMachineFamily($machine)===''&&!in_array($machine,['q35','pc'],true))throw new RuntimeException('The default CPU supports only x86_64 Q35/i440fx VMs.');
    $r=unmRun(['env','LC_ALL=C','virsh','hypervisor-cpu-compare','/dev/stdin','--error','--virttype','kvm','--arch','x86_64','--machine',$machine],$cpu,15);
    if($r['code']!==0)throw new RuntimeException('The selected CPU cannot be used with this machine: '.trim($r['stderr']));
}
