<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/xml-canonical.php';

/** Pure structural validation, also used by the local-only native start fence. */
function unmLiveFirmwareXml(DOMXPath $xp,string $uuid): ?array {
    $loaders=$xp->query('/domain/os/loader');$vars=$xp->query('/domain/os/nvram');
    if(!$loaders->length&&!$vars->length)return null;
    if($loaders->length!==1||$vars->length!==1||$xp->evaluate('string(/domain/os/@firmware)')!=='')throw new RuntimeException('Live UEFI requires explicit pflash firmware and one raw NVRAM file.');
    $loader=$loaders->item(0);$nvram=$vars->item(0);$path=trim($loader->textContent);$nv=trim($nvram->textContent);
    if(!preg_match('~\A/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi(?:-tpm)?\.fd\z~D',$path)||$loader->getAttribute('readonly')!=='yes'||$loader->getAttribute('type')!=='pflash'||!in_array($loader->getAttribute('format'),['','raw'],true)||$loader->getAttribute('stateless')==='yes'||$loader->getElementsByTagName('*')->length)throw new RuntimeException('Live UEFI supports reviewed, read-only raw Unraid OVMF firmware only.');
    if(!preg_match('~\A/etc/libvirt/qemu/nvram/'.preg_quote($uuid,'~').'_[A-Za-z0-9_.-]{1,160}\.fd\z~D',$nv)||!in_array($nvram->getAttribute('format'),['','raw'],true)||!in_array($nvram->getAttribute('type'),['','file'],true)||$nvram->getElementsByTagName('*')->length)throw new RuntimeException('Live UEFI requires a UUID-scoped raw NVRAM file in libvirt storage.');
    foreach($loader->attributes as $a)if(!in_array($a->name,['readonly','type','format','secure','stateless'],true))throw new RuntimeException('Unsupported live firmware option.');
    foreach($nvram->attributes as $a)if(!in_array($a->name,['format','type','template','templateFormat'],true))throw new RuntimeException('Unsupported live NVRAM option.');
    if($nvram->hasAttribute('template')&&!preg_match('~\A/usr/share/qemu/ovmf-x64/OVMF_VARS-pure-efi(?:-tpm)?\.fd\z~D',$nvram->getAttribute('template')))throw new RuntimeException('Unsupported NVRAM template.');
    if(!in_array($nvram->getAttribute('templateFormat'),['','raw'],true))throw new RuntimeException('Unsupported NVRAM template format.');
    return ['loaderPath'=>$path,'nvramPath'=>$nv,'xmlHash'=>hash('sha256',unmXmlCanonical($loader).unmXmlCanonical($nvram))];
}
