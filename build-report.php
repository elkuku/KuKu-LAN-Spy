#!/usr/bin/env php
<?php
/**
 * Build the lan-spy JSON report from the raw scan files lan-spy.sh collects.
 *
 * Usage: build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json
 *
 * <scan-dir> holds arp.tsv, discovery.xml, dns.tsv, netbios.tsv, mdns.txt,
 * targets.txt, deep.xml, local-ss.txt and (optionally) deep-retry.xml.
 *
 * This machine cannot scan itself over the LAN, so its MAC, OS and listening
 * ports are read locally (/sys, /etc/os-release and `ss -ltnp` output).
 */

declare(strict_types=1);

final class Port implements JsonSerializable
{
    // nmap names these after unrelated services; on a home LAN they usually mean this.
    private const IOT_HINTS = [
        6668 => 'Tuya smart device local API',
        8009 => 'Google Cast',
        9999 => 'TP-Link Kasa smart device API',
    ];

    public function __construct(
        public readonly int $port,
        public readonly ?string $proto,
        public readonly ?string $state,
        public readonly ?string $service,
        public readonly ?string $version,
        public readonly ?string $hint = null,
    ) {
    }

    public static function fromNmap(SimpleXMLElement $element): self
    {
        $port = (int) attr($element, 'portid');
        $state = attr($element->state[0] ?? null, 'state');
        $svc = $element->service[0] ?? null;
        $version = null;
        if ($svc !== null) {
            $extraInfo = present(attr($svc, 'extrainfo')) ? '(' . attr($svc, 'extrainfo') . ')' : null;
            $version = implode(' ', array_filter([attr($svc, 'product'), attr($svc, 'version'), $extraInfo], present(...)));
        }
        return new self($port, attr($element, 'protocol'), $state, attr($svc, 'name'), present($version) ? $version : null,
            $state === 'open' ? self::hintFor($port) : null);
    }

    /** @param list<string> $procs the processes listening on it */
    public static function local(int $port, array $procs): self
    {
        return new self($port, 'tcp', 'open', getservbyport($port, 'tcp') ?: null, implode(', ', $procs) ?: null,
            self::hintFor($port));
    }

    public static function hintFor(int $port): ?string
    {
        return self::IOT_HINTS[$port] ?? null;
    }

    public function jsonSerialize(): array
    {
        $out = ['port' => $this->port, 'proto' => $this->proto, 'state' => $this->state,
            'service' => $this->service, 'version' => $this->version];
        if ($this->hint !== null) {
            $out['hint'] = $this->hint;
        }
        return $out;
    }
}

/** A device as it is collected from the scan files; serializes to its JSON report entry. */
final class Device implements JsonSerializable
{
    public ?string $mac = null;
    public ?string $vendor = null;
    public ?bool $macLocallyAdministered = null;
    public ?string $hostname = null;
    public ?string $netbios = null;
    /** @var list<array{name: string, service: string, hostname: string, port: ?int, txt?: list<string>}> */
    public array $mdns = [];
    public ?string $role = null;
    public ?float $latencyMs = null;
    /** @var list<string> */
    public array $seenBy = [];
    public string $deepScan = 'not scanned';
    /** @var ?list<Port> null if the device was not port scanned */
    public ?array $ports = null;
    public ?array $os = null;
    // Only in the report when known.
    public ?string $portsNotShown = null;
    public ?int $networkDistance = null;

    public function __construct(public readonly string $ip)
    {
    }

    public function seenBy(string $source): void
    {
        if (!in_array($source, $this->seenBy, true)) {
            $this->seenBy[] = $source;
        }
    }

    public function jsonSerialize(): array
    {
        $out = [
            'ip' => $this->ip, 'mac' => $this->mac, 'vendor' => $this->vendor,
            'mac_locally_administered' => $this->macLocallyAdministered,
            'hostname' => $this->hostname, 'netbios' => $this->netbios, 'mdns' => $this->mdns, 'role' => $this->role,
            'latency_ms' => $this->latencyMs, 'seen_by' => $this->seenBy, 'deep_scan' => $this->deepScan,
            'ports' => $this->ports, 'os' => $this->os,
        ];
        if ($this->portsNotShown !== null) {
            $out['ports_not_shown'] = $this->portsNotShown;
        }
        if ($this->networkDistance !== null) {
            $out['network_distance'] = $this->networkDistance;
        }
        return $out;
    }
}

/** The folder of intermediate files lan-spy.sh writes; missing files read as empty. */
final class ScanDir
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return list<string> the non-empty lines */
    public function lines(string $name): array
    {
        $file = "$this->path/$name";
        return is_file($file) ? preg_split('/\r?\n/', rtrim(file_get_contents($file), "\r\n"), -1, PREG_SPLIT_NO_EMPTY) : [];
    }

    /** @return array<string, string> first column => second column */
    public function tsv(string $name): array
    {
        $out = [];
        foreach ($this->lines($name) as $line) {
            if (str_contains($line, "\t")) {
                [$key, $value] = explode("\t", $line, 2);
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return list<string> whitespace-separated words */
    public function words(string $name): array
    {
        $file = "$this->path/$name";
        return is_file($file) ? preg_split('/\s+/', trim(file_get_contents($file)), -1, PREG_SPLIT_NO_EMPTY) : [];
    }

    /** @return list<SimpleXMLElement> the <host> elements of an nmap XML file */
    public function nmapHosts(string $name): array
    {
        $file = "$this->path/$name";
        libxml_use_internal_errors(true);
        $xml = is_file($file) ? simplexml_load_file($file) : false;
        libxml_clear_errors();
        return $xml === false ? [] : iterator_to_array($xml->host, false);
    }
}

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
        usort($list, fn(Device $left, Device $right) => ip_cmp($left->ip, $right->ip));
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
            $macAddress = first($nmapHost, "address[@addrtype='mac']");
            if ($macAddress !== null) {
                $device->mac = strtolower((string) attr($macAddress, 'addr'));
                $device->vendor = attr($macAddress, 'vendor') ?: $device->vendor;
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
            $txt = array_values(array_filter(array_map(fn(string $item) => trim($item, '"'), explode('" "', $fields[9])), present(...)));
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
            $ip = host_ip($nmapHost);
            if ($ip !== null && $ip !== '' && self::isUp($nmapHost)) {
                $this->addDeepScanHost($this->device($ip), $nmapHost);
            }
        }
    }

    private function addDeepScanHost(Device $device, SimpleXMLElement $nmapHost): void
    {
        $device->deepScan = 'ok';
        $srtt = attr($nmapHost->times[0] ?? null, 'srtt');
        if (present($srtt) && $device->latencyMs === null) {
            $device->latencyMs = (float) sprintf('%.2f', (int) $srtt / 1000);
        }
        $portsEl = $nmapHost->ports[0] ?? null;
        if ($portsEl === null) {
            return;
        }
        $device->ports = array_map(Port::fromNmap(...), iterator_to_array($portsEl->port, false));
        $extra = $portsEl->extraports[0] ?? null;
        if ($extra !== null) {
            $device->portsNotShown = attr($extra, 'count') . ' ' . attr($extra, 'state');
        }
        $matches = isset($nmapHost->os[0]) ? iterator_to_array($nmapHost->os[0]->osmatch, false) : [];
        if ($matches) {
            $device->os = self::nmapOs($matches);
        }
        $dist = $nmapHost->distance[0] ?? null;
        if ($dist !== null) {
            $device->networkDistance = (int) attr($dist, 'value');
        }
    }

    /** @param list<SimpleXMLElement> $matches <osmatch> elements, best first */
    private static function nmapOs(array $matches): array
    {
        $classes = [];
        foreach ($matches as $match) {
            array_push($classes, ...iterator_to_array($match->osclass, false));
        }
        $types = array_unique(array_filter(array_map(fn($osClass) => attr($osClass, 'type'), $classes), present(...)));
        $cpes = array_unique(array_filter(array_map(fn($osClass) => isset($osClass->cpe[0]) ? (string) $osClass->cpe[0] : null, $classes),
            present(...)));
        sort($types, SORT_STRING);
        sort($cpes, SORT_STRING);
        return [
            'best_guess' => attr($matches[0], 'name'),
            'accuracy' => (int) attr($matches[0], 'accuracy'),
            'guesses' => array_map(fn($guess) => ['name' => attr($guess, 'name'), 'accuracy' => (int) attr($guess, 'accuracy')],
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
        $ouis = oui_db();
        foreach ($this->devices as $ip => $device) {
            if ((string) $ip === $this->gateway) {
                $device->role = 'gateway';
            }
            if ($device->mac) {
                $device->macLocallyAdministered = (bool) (hexdec(substr($device->mac, 0, 2)) & 0x02);
                if (!$device->vendor && !$device->macLocallyAdministered) {
                    $device->vendor = $ouis[strtoupper(substr(str_replace(':', '', $device->mac), 0, 6))] ?? null;
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
            $ip = host_ip($nmapHost);
            if ($ip !== null && $ip !== '' && self::isUp($nmapHost)) {
                yield $ip => $nmapHost;
            }
        }
    }

    private static function isUp(SimpleXMLElement $nmapHost): bool
    {
        return attr($nmapHost->status[0] ?? null, 'state') === 'up';
    }
}

/** Python-style truthiness for optional strings: null and '' are missing, '0' is a value. */
function present(?string $value): bool
{
    return $value !== null && $value !== '';
}

/** Use the system timezone (PHP defaults to UTC) so dates carry the local offset. */
function use_local_timezone(): void
{
    $tz = getenv('TZ') ?: (preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $match) ? $match[1] : '');
    if (in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

/** @return array<string, string> MAC prefix (6 hex digits, upper case) => vendor */
function oui_db(): array
{
    $db = [];
    $fh = @fopen('/usr/share/nmap/nmap-mac-prefixes', 'r');
    if (!$fh) {
        return $db;
    }
    while (($line = fgets($fh)) !== false) {
        if (!str_starts_with($line, '#')) {
            $parts = explode(' ', trim($line), 2);
            $db[strtoupper($parts[0])] = $parts[1] ?? '';
        }
    }
    fclose($fh);
    return $db;
}

function first(SimpleXMLElement $el, string $path): ?SimpleXMLElement
{
    return $el->xpath($path)[0] ?? null;
}

function attr(?SimpleXMLElement $el, string $name): ?string
{
    return $el !== null && isset($el[$name]) ? (string) $el[$name] : null;
}

function host_ip(SimpleXMLElement $nmapHost): ?string
{
    return attr(first($nmapHost, "address[@addrtype='ipv4']"), 'addr');
}

function ip_cmp(string $left, string $right): int
{
    $left = inet_pton($left);
    $right = inet_pton($right);
    return [strlen($left), $left] <=> [strlen($right), $right];
}

/** The network address of an IPv4 CIDR, e.g. 192.168.1.129/24 => 192.168.1.0/24. */
function network_of(string $cidr): string
{
    [$ip, $prefix] = explode('/', $cidr) + [1 => '32'];
    $prefix = (int) $prefix;
    $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
    return long2ip(ip2long($ip) & $mask) . "/$prefix";
}

function json_out(mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    // Two-space indent, like the reports have always had.
    echo preg_replace_callback('/^(?: {4})+/m', fn($indent) => str_repeat(' ', strlen($indent[0]) / 2), $json), "\n";
}

function main(array $argv): int
{
    if (count($argv) < 6) {
        fwrite(STDERR, "Usage: build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json\n");
        return 2;
    }
    [, $dir, $iface, $cidr, $gateway, $myhost] = $argv;
    use_local_timezone();
    $builder = new ReportBuilder(new ScanDir($dir), $iface, explode('/', $cidr)[0], $gateway, $myhost);
    json_out($builder->build(network_of($cidr)));
    return 0;
}

exit(main($argv));
