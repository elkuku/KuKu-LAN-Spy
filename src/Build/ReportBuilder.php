<?php

declare(strict_types=1);

namespace LanSpy\Build;

use LanSpy\Util\Ip;
use LanSpy\Util\Text;
use SimpleXMLElement;

/** Collects the scan files into the devices of one report. */
final class ReportBuilder
{
    /** @var array<string, Device> keyed by IP */
    private array $devices = [];

    public function __construct(
        private readonly ScanDir $scan,
        private readonly string $iface,
        private readonly string $localIp,
        private readonly string $gateway,
        private readonly string $myhost,
    ) {
    }

    public function build(string $network): array
    {
        $this->addArpScan();
        $this->addDiscovery();
        $this->addNames();
        $this->addMdns();
        $this->addTargets();
        $this->addDeepScan();
        $this->addThisMachine();
        $this->addRolesAndVendors();

        $list = array_values($this->devices);
        usort($list, fn(Device $left, Device $right) => Ip::compare($left->ip, $right->ip));
        return [
            'scan' => [
                'date' => date('c'),
                'host' => $this->myhost, 'interface' => $this->iface, 'network' => $network,
                'local_ip' => $this->localIp, 'local_mac' => $this->devices[$this->localIp]->mac,
                'gateway' => $this->gateway, 'device_count' => count($this->devices),
            ],
            'devices' => $list,
        ];
    }

    private function device(string $ip): Device
    {
        return $this->devices[$ip] ??= new Device($ip);
    }

    private function addArpScan(): void
    {
        foreach ($this->scan->tsv('arp.tsv') as $ip => $mac) {
            $device = $this->device((string) $ip);
            $device->mac = strtolower(trim($mac));
            $device->seenBy[] = 'arp-scan';
        }
    }

    private function addDiscovery(): void
    {
        foreach (self::upHosts($this->scan->nmapHosts('discovery.xml')) as $ip => $nmapHost) {
            $device = $this->device($ip);
            $device->seenBy[] = 'nmap';
            $macAddress = NmapXml::first($nmapHost, "address[@addrtype='mac']");
            if ($macAddress !== null) {
                $device->mac = strtolower((string) NmapXml::attr($macAddress, 'addr'));
                $device->vendor = NmapXml::attr($macAddress, 'vendor') ?: $device->vendor;
            }
        }
    }

    private function addNames(): void
    {
        foreach ($this->scan->tsv('dns.tsv') as $ip => $name) {
            $this->device((string) $ip)->hostname = $name;
        }
        foreach ($this->scan->tsv('netbios.tsv') as $ip => $name) {
            $this->device((string) $ip)->netbios = $name;
        }
    }

    /** avahi-browse -p lines: =;iface;proto;name;service;domain;hostname;address;port;txt */
    private function addMdns(): void
    {
        foreach ($this->scan->lines('mdns.txt') as $line) {
            $fields = explode(';', $line);
            if (count($fields) < 10 || (!isset($this->devices[$fields[7]]) && $fields[7] !== $this->localIp)) {
                continue;
            }
            $txt = array_values(array_filter(array_map(fn(string $item) => trim($item, '"'), explode('" "', $fields[9])), Text::present(...)));
            $entry = ['name' => $fields[3], 'service' => $fields[4], 'hostname' => $fields[6],
                'port' => ctype_digit($fields[8]) ? (int) $fields[8] : null];
            if ($txt) {
                $entry['txt'] = $txt;
            }
            $device = $this->device($fields[7]);
            if (!in_array($entry, $device->mdns, true)) {
                $device->mdns[] = $entry;
            }
        }
    }

    /** Every target starts as unanswered; the deep scan marks the ones that replied. */
    private function addTargets(): void
    {
        foreach ($this->scan->words('targets.txt') as $ip) {
            $this->device($ip)->deepScan = 'no response (device asleep or not answering ARP)';
        }
    }

    private function addDeepScan(): void
    {
        $hosts = [...$this->scan->nmapHosts('deep.xml'), ...$this->scan->nmapHosts('deep-retry.xml')];
        foreach ($hosts as $nmapHost) {
            $ip = NmapXml::hostIp($nmapHost);
            if ($ip !== null && $ip !== '' && NmapXml::isUp($nmapHost)) {
                $this->addDeepScanHost($this->device($ip), $nmapHost);
            }
        }
    }

    private function addDeepScanHost(Device $device, SimpleXMLElement $nmapHost): void
    {
        $device->deepScan = 'ok';
        $srtt = NmapXml::attr($nmapHost->times[0] ?? null, 'srtt');
        if (Text::present($srtt) && $device->latencyMs === null) {
            $device->latencyMs = (float) sprintf('%.2f', (int) $srtt / 1000);
        }
        $portsEl = $nmapHost->ports[0] ?? null;
        if ($portsEl === null) {
            return;
        }
        $device->ports = array_map(Port::fromNmap(...), iterator_to_array($portsEl->port, false));
        $extra = $portsEl->extraports[0] ?? null;
        if ($extra !== null) {
            $device->portsNotShown = NmapXml::attr($extra, 'count') . ' ' . NmapXml::attr($extra, 'state');
        }
        $matches = isset($nmapHost->os[0]) ? iterator_to_array($nmapHost->os[0]->osmatch, false) : [];
        if ($matches) {
            $device->os = self::nmapOs($matches);
        }
        $dist = $nmapHost->distance[0] ?? null;
        if ($dist !== null) {
            $device->networkDistance = (int) NmapXml::attr($dist, 'value');
        }
    }

    /** @param list<SimpleXMLElement> $matches <osmatch> elements, best first */
    private static function nmapOs(array $matches): array
    {
        $classes = [];
        foreach ($matches as $match) {
            array_push($classes, ...iterator_to_array($match->osclass, false));
        }
        $types = array_unique(array_filter(array_map(fn($osClass) => NmapXml::attr($osClass, 'type'), $classes), Text::present(...)));
        $cpes = array_unique(array_filter(array_map(fn($osClass) => isset($osClass->cpe[0]) ? (string) $osClass->cpe[0] : null, $classes),
            Text::present(...)));
        sort($types, SORT_STRING);
        sort($cpes, SORT_STRING);
        return [
            'best_guess' => NmapXml::attr($matches[0], 'name'),
            'accuracy' => (int) NmapXml::attr($matches[0], 'accuracy'),
            'guesses' => array_map(fn($guess) => ['name' => NmapXml::attr($guess, 'name'), 'accuracy' => (int) NmapXml::attr($guess, 'accuracy')],
                array_slice($matches, 0, 5)),
            'device_types' => $types,
            'cpe' => $cpes,
        ];
    }

    private function addThisMachine(): void
    {
        $device = $this->device($this->localIp);
        $device->role = 'this machine';
        $device->deepScan = 'local';
        $device->hostname = $device->hostname ?: $this->myhost;
        $device->seenBy('local');
        $mac = @file_get_contents("/sys/class/net/$this->iface/address");
        if ($mac !== false) {
            $device->mac = strtolower(trim($mac)) ?: $device->mac;
        }
        $device->ports = $this->localPorts();
        $device->os = self::localOs();
    }

    /** @return list<Port> TCP ports this machine listens on that are reachable from the LAN */
    private function localPorts(): array
    {
        $procs = [];
        foreach ($this->scan->lines('local-ss.txt') as $line) {
            $fields = preg_split('/\s+/', trim($line), 6);
            if (count($fields) < 5) {
                continue;
            }
            $pos = strrpos($fields[3], ':');
            [$host, $port] = $pos === false ? ['', $fields[3]] : [substr($fields[3], 0, $pos), substr($fields[3], $pos + 1)];
            $host = explode('%', trim($host, '[]'))[0];
            if (str_starts_with($host, '::ffff:')) {
                $host = substr($host, 7);
            }
            if (!in_array($host, ['*', '0.0.0.0', '::', $this->localIp], true) || !ctype_digit($port)) {
                continue;
            }
            preg_match_all('/\("([^"]+)"/', $fields[5] ?? '', $procMatches);
            $port = (int) $port;
            $procs[$port] = array_values(array_unique([...$procs[$port] ?? [], ...$procMatches[1]]));
        }
        ksort($procs);
        return array_map(Port::local(...), array_keys($procs), $procs);
    }

    private static function localOs(): array
    {
        $info = [];
        foreach (is_readable('/etc/os-release') ? file('/etc/os-release') : [] as $line) {
            $parts = explode('=', trim($line), 2);
            $info[$parts[0]] = trim($parts[1] ?? '', '"');
        }
        $system = php_uname('s');
        $distro = ($info['PRETTY_NAME'] ?? '') ?: (($info['NAME'] ?? '') ?: $system);
        return [
            'best_guess' => sprintf('%s (%s %s, %s)', $distro, $system, php_uname('r'), php_uname('m')),
            'source' => 'local', 'device_types' => ['general purpose'],
        ];
    }

    /** Mark the gateway, flag randomized MACs and look up missing vendors in nmap's OUI list. */
    private function addRolesAndVendors(): void
    {
        $ouiDatabase = OuiDatabase::load();
        foreach ($this->devices as $ip => $device) {
            if ((string) $ip === $this->gateway) {
                $device->role = 'gateway';
            }
            if ($device->mac) {
                $device->macLocallyAdministered = (bool) (hexdec(substr($device->mac, 0, 2)) & 0x02);
                if (!$device->vendor && !$device->macLocallyAdministered) {
                    $device->vendor = $ouiDatabase->vendor($device->mac);
                }
            }
        }
    }

    /**
     * @param list<SimpleXMLElement> $hosts
     * @return iterable<string, SimpleXMLElement> the hosts that are up, keyed by IP
     */
    private static function upHosts(array $hosts): iterable
    {
        foreach ($hosts as $nmapHost) {
            $ip = NmapXml::hostIp($nmapHost);
            if ($ip !== null && $ip !== '' && NmapXml::isUp($nmapHost)) {
                yield $ip => $nmapHost;
            }
        }
    }
}
