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

echo "PHP RC1 behavior regressions passed.\n";
