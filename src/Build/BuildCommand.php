<?php

declare(strict_types=1);

namespace LanSpy\Build;

use LanSpy\Util\Json;
use LanSpy\Util\Timezone;

/** build-report.php: turn the files lan-spy.sh collected into the JSON report. */
final class BuildCommand
{
    private const USAGE = "Usage: build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json\n";

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        if (count($argv) < 6) {
            fwrite(STDERR, self::USAGE);
            return 2;
        }
        [, $dir, $iface, $cidr, $gateway, $myhost] = $argv;
        Timezone::useLocal();
        $builder = new ReportBuilder(new ScanDir($dir), $iface, explode('/', $cidr)[0], $gateway, $myhost);
        Json::write($builder->build(self::networkOf($cidr)));
        return 0;
    }

    /** The network address of an IPv4 CIDR, e.g. 192.168.1.129/24 => 192.168.1.0/24. */
    private static function networkOf(string $cidr): string
    {
        [$ip, $prefix] = explode('/', $cidr) + [1 => '32'];
        $prefix = (int) $prefix;
        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
        return long2ip(ip2long($ip) & $mask) . "/$prefix";
    }
}
