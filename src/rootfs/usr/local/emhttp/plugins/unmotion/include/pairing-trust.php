<?php
declare(strict_types=1);

// Host keys are separate from the restricted client keys installed by pairing.
// Only the explicitly accepted Ed25519 host key enters known_hosts.
function unmSshHostPublicKey(string $key): string {
    unmManagedSshKeyId($key); // Validate the complete Ed25519 wire representation.
    $parts=preg_split('/\s+/',trim($key),3);
    return 'ssh-ed25519 '.$parts[1];
}

function unmSshHostFingerprint(string $key): string {
    $parts=explode(' ',unmSshHostPublicKey($key));
    return 'SHA256:'.rtrim(base64_encode(hash('sha256',base64_decode($parts[1],true),true)),'=');
}

function unmAssertSshHostFingerprint(string $key,string $expected): void {
    $expected=trim($expected);
    if(!preg_match('~^SHA256:[A-Za-z0-9+/]{43}$~D',$expected))throw new InvalidArgumentException('Accept the peer Ed25519 fingerprint before pairing.');
    if(!hash_equals($expected,unmSshHostFingerprint($key)))throw new RuntimeException('The peer SSH host key changed or does not match the accepted fingerprint. No password was sent. Check the address and review the fingerprint again.');
}

function unmScannedSshHostKey(string $output): string {
    $keys=[];
    foreach(preg_split('/\R/',$output)?:[] as $line){
        $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
        $parts=preg_split('/\s+/',$line);
        if(count($parts)!==3)throw new RuntimeException('Invalid SSH host-key scan response.');
        $key=unmSshHostPublicKey($parts[1].' '.$parts[2]);$keys[$key]=true;
    }
    if(count($keys)!==1)throw new RuntimeException('The peer must offer one unambiguous Ed25519 SSH host key.');
    return (string)array_key_first($keys);
}

function unmScanPeerHostKey(string $host,int $port): string {
    $scan=unmRun(['ssh-keyscan','-T','10','-t','ed25519','-p',(string)$port,$host],null,20);
    if($scan['code']!==0||trim($scan['stdout'])==='')throw new RuntimeException('Unable to read the peer Ed25519 SSH host key. Check SSH under Settings → Management Access and the SSH port.');
    return unmScannedSshHostKey($scan['stdout']);
}

function unmPairingEndpoint(array $input): array {
    $host=trim((string)($input['host']??''));
    $port=(string)($input['port']??22);
    if($host===''||!preg_match('/^[A-Za-z0-9_.-]+$/D',$host))throw new InvalidArgumentException('Enter a hostname or IPv4 address. IPv6 peers are not supported.');
    if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535)throw new InvalidArgumentException('Invalid SSH port.');
    unmAssertRemotePeerHost($host);
    return ['host'=>$host,'port'=>(int)$port];
}

/** Read-only first-contact preview. Never creates keys or sends a password. */
function unmPairingHostKey(array $input): array {
    $endpoint=unmPairingEndpoint($input);
    $key=unmScanPeerHostKey($endpoint['host'],$endpoint['port']);
    return $endpoint+['algorithm'=>'Ed25519','fingerprint'=>unmSshHostFingerprint($key)];
}

function unmLocalSshHostPublicKey(): string {
    $key=@file_get_contents('/etc/ssh/ssh_host_ed25519_key.pub');
    if($key===false)throw new RuntimeException('The local Ed25519 SSH host public key is unavailable. Enable SSH before pairing.');
    return unmSshHostPublicKey($key);
}
