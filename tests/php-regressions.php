<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

function expectSame(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$txt = unmParseAvahiTxtRecordList('"host_id=peer-123" "version=0.3.0-RC1" "protocol=5" "label=Squid\\032Proxy"');
expectSame('peer-123', $txt['host_id'] ?? null, 'host_id');
expectSame('0.3.0-RC1', $txt['version'] ?? null, 'version');
expectSame('5', $txt['protocol'] ?? null, 'protocol');
expectSame('Squid Proxy', $txt['label'] ?? null, 'escaped TXT value');
expectSame('Squid Proxy', unmAvahiUnescape('Squid\\032Proxy'), 'Avahi display unescape');
expectSame('Squid Proxy - Clone', unmCloneName(' Squid Proxy - Clone '), 'clone name trim');
expectSame(true, str_starts_with(unmCloneObjectStem('Squid Proxy'),'unmotion-clone-squid-proxy-'), 'clone ZFS stem');
$newUuid=unmNewUuid();
expectSame(36, strlen($newUuid), 'clone UUID length');
expectSame('4', $newUuid[14], 'clone UUID version');
expectSame(true, in_array($newUuid[19],['8','9','a','b'],true), 'clone UUID variant');
try { unmCloneName('../unsafe'); fwrite(STDERR,"Unsafe clone name was accepted.\n"); exit(1); } catch(InvalidArgumentException $expected) {}
expectSame(true, unmZfsObjectNameSafe('cache/domains/Multi Disk Mixed'), 'ZFS dataset spaces');
expectSame(true, unmZfsObjectNameSafe('cache/vm-zvols/Data Disk 2'), 'ZFS zvol spaces');
foreach (['cache','/cache/domains/vm','cache/domains/','cache/domains/../system','cache/domains/vm@snapshot',"cache/domains/vm\nother"] as $unsafeZfs) {
    expectSame(false, unmZfsObjectNameSafe($unsafeZfs), 'unsafe ZFS object ' . json_encode($unsafeZfs));
}

echo "PHP RC2 behavior regressions passed.\n";
