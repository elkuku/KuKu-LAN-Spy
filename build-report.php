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

if ($argc < 6) {
    fwrite(STDERR, "Usage: build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json\n");
    exit(2);
}
[, $tmp, $iface, $cidr, $gateway, $myhost] = $argv;
$localIp = explode('/', $cidr)[0];
use_local_timezone();

// nmap names these after unrelated services; on a home LAN they usually mean this.
const IOT_HINTS = [
    6668 => 'Tuya smart device local API',
    8009 => 'Google Cast',
    9999 => 'TP-Link Kasa smart device API',
];

/** Python-style truthiness for optional strings: null and '' are missing, '0' is a value. */
function present(?string $s): bool
{
    return $s !== null && $s !== '';
}

/** Use the system timezone (PHP defaults to UTC) so dates carry the local offset. */
function use_local_timezone(): void
{
    $tz = getenv('TZ') ?: (preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $m) ? $m[1] : '');
    if (in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

function lines(string $path): array
{
    return is_file($path) ? preg_split('/\r?\n/', rtrim(file_get_contents($path), "\r\n"), -1, PREG_SPLIT_NO_EMPTY) : [];
}

function read_tsv(string $path): array
{
    $out = [];
    foreach (lines($path) as $l) {
        if (str_contains($l, "\t")) {
            [$k, $v] = explode("\t", $l, 2);
            $out[$k] = $v;
        }
    }
    return $out;
}

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

function parse_xml(string $path): ?SimpleXMLElement
{
    libxml_use_internal_errors(true);
    $xml = is_file($path) ? simplexml_load_file($path) : false;
    libxml_clear_errors();
    return $xml === false ? null : $xml;
}

function first(SimpleXMLElement $el, string $path): ?SimpleXMLElement
{
    return $el->xpath($path)[0] ?? null;
}

function attr(?SimpleXMLElement $el, string $name): ?string
{
    return $el !== null && isset($el[$name]) ? (string) $el[$name] : null;
}

function host_ip(SimpleXMLElement $h): ?string
{
    return attr(first($h, "address[@addrtype='ipv4']"), 'addr');
}

function ip_cmp(string $a, string $b): int
{
    $a = inet_pton($a);
    $b = inet_pton($b);
    return [strlen($a), $a] <=> [strlen($b), $b];
}

function json_out(mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    // Two-space indent, like the reports have always had.
    echo preg_replace_callback('/^(?: {4})+/m', fn($m) => str_repeat(' ', strlen($m[0]) / 2), $json), "\n";
}

$devices = [];

function &dev(string $ip): array
{
    global $devices;
    $devices[$ip] ??= ['ip' => $ip, 'mac' => null, 'vendor' => null, 'mac_locally_administered' => null,
        'hostname' => null, 'netbios' => null, 'mdns' => [], 'role' => null,
        'latency_ms' => null, 'seen_by' => [], 'deep_scan' => 'not scanned',
        'ports' => null, 'os' => null];
    return $devices[$ip];
}

foreach (read_tsv("$tmp/arp.tsv") as $ip => $mac) {
    $d = &dev((string) $ip);
    $d['mac'] = strtolower(trim($mac));
    $d['seen_by'][] = 'arp-scan';
    unset($d);
}

$root = parse_xml("$tmp/discovery.xml");
foreach ($root?->host ?? [] as $h) {
    $ip = host_ip($h);
    if (!$ip || attr($h->status[0] ?? null, 'state') !== 'up') {
        continue;
    }
    $d = &dev($ip);
    $d['seen_by'][] = 'nmap';
    $m = first($h, "address[@addrtype='mac']");
    if ($m !== null) {
        $d['mac'] = strtolower(attr($m, 'addr'));
        $d['vendor'] = attr($m, 'vendor') ?: $d['vendor'];
    }
    unset($d);
}

foreach (['dns.tsv' => 'hostname', 'netbios.tsv' => 'netbios'] as $file => $field) {
    foreach (read_tsv("$tmp/$file") as $ip => $name) {
        $d = &dev((string) $ip);
        $d[$field] = $name;
        unset($d);
    }
}

foreach (lines("$tmp/mdns.txt") as $line) {
    $f = explode(';', $line);
    if (count($f) < 10 || (!isset($devices[$f[7]]) && $f[7] !== $localIp)) {
        continue;
    }
    $txt = array_values(array_filter(array_map(fn($t) => trim($t, '"'), explode('" "', $f[9])), 'present'));
    $entry = ['name' => $f[3], 'service' => $f[4], 'hostname' => $f[6],
        'port' => ctype_digit($f[8]) ? (int) $f[8] : null];
    if ($txt) {
        $entry['txt'] = $txt;
    }
    $d = &dev($f[7]);
    if (!in_array($entry, $d['mdns'], true)) {
        $d['mdns'][] = $entry;
    }
    unset($d);
}

$targets = is_file("$tmp/targets.txt")
    ? preg_split('/\s+/', trim(file_get_contents("$tmp/targets.txt")), -1, PREG_SPLIT_NO_EMPTY) : [];
foreach ($targets as $ip) {
    $d = &dev($ip);
    $d['deep_scan'] = 'no response (device asleep or not answering ARP)';
    unset($d);
}

$deepHosts = [];
foreach (['deep.xml', 'deep-retry.xml'] as $name) {
    foreach (parse_xml("$tmp/$name")?->host ?? [] as $h) {
        $deepHosts[] = $h;
    }
}

foreach ($deepHosts as $h) {
    $ip = host_ip($h);
    if (!$ip || attr($h->status[0] ?? null, 'state') !== 'up') {
        continue;
    }
    $d = &dev($ip);
    $d['deep_scan'] = 'ok';
    $srtt = attr($h->times[0] ?? null, 'srtt');
    if (present($srtt) && $d['latency_ms'] === null) {
        $d['latency_ms'] = (float) sprintf('%.2f', (int) $srtt / 1000);
    }
    $portsEl = $h->ports[0] ?? null;
    if ($portsEl === null) {
        unset($d);
        continue;
    }
    $ports = [];
    foreach ($portsEl->port as $p) {
        $svc = $p->service[0] ?? null;
        $entry = ['port' => (int) attr($p, 'portid'), 'proto' => attr($p, 'protocol'),
            'state' => attr($p->state[0] ?? null, 'state'), 'service' => attr($svc, 'name'), 'version' => null];
        if ($svc !== null) {
            $extraInfo = present(attr($svc, 'extrainfo')) ? '(' . attr($svc, 'extrainfo') . ')' : null;
            $ver = implode(' ', array_filter([attr($svc, 'product'), attr($svc, 'version'), $extraInfo], 'present'));
            $entry['version'] = $ver !== '' ? $ver : null;
        }
        if (isset(IOT_HINTS[$entry['port']]) && $entry['state'] === 'open') {
            $entry['hint'] = IOT_HINTS[$entry['port']];
        }
        $ports[] = $entry;
    }
    $extra = $portsEl->extraports[0] ?? null;
    $d['ports'] = $ports;
    if ($extra !== null) {
        $d['ports_not_shown'] = attr($extra, 'count') . ' ' . attr($extra, 'state');
    }
    $matches = isset($h->os[0]) ? iterator_to_array($h->os[0]->osmatch, false) : [];
    if ($matches) {
        $classes = [];
        foreach ($matches as $m) {
            foreach ($m->osclass as $c) {
                $classes[] = $c;
            }
        }
        $types = array_unique(array_filter(array_map(fn($c) => attr($c, 'type'), $classes), 'present'));
        $cpes = array_unique(array_filter(array_map(fn($c) => isset($c->cpe[0]) ? (string) $c->cpe[0] : null, $classes), 'present'));
        sort($types, SORT_STRING);
        sort($cpes, SORT_STRING);
        $d['os'] = [
            'best_guess' => attr($matches[0], 'name'),
            'accuracy' => (int) attr($matches[0], 'accuracy'),
            'guesses' => array_map(fn($m) => ['name' => attr($m, 'name'), 'accuracy' => (int) attr($m, 'accuracy')],
                array_slice($matches, 0, 5)),
            'device_types' => $types,
            'cpe' => $cpes,
        ];
    }
    $dist = $h->distance[0] ?? null;
    if ($dist !== null) {
        $d['network_distance'] = (int) attr($dist, 'value');
    }
    unset($d);
}

/** TCP ports this machine listens on that are reachable from the LAN. */
function local_ports(string $tmp, string $localIp): array
{
    $ports = [];
    foreach (lines("$tmp/local-ss.txt") as $line) {
        $f = preg_split('/\s+/', trim($line), 6);
        if (count($f) < 5) {
            continue;
        }
        $pos = strrpos($f[3], ':');
        [$host, $port] = $pos === false ? ['', $f[3]] : [substr($f[3], 0, $pos), substr($f[3], $pos + 1)];
        $host = explode('%', trim($host, '[]'))[0];
        if (str_starts_with($host, '::ffff:')) {
            $host = substr($host, 7);
        }
        if (!in_array($host, ['*', '0.0.0.0', '::', $localIp], true) || !ctype_digit($port)) {
            continue;
        }
        preg_match_all('/\("([^"]+)"/', $f[5] ?? '', $m);
        $port = (int) $port;
        $ports[$port] ??= ['port' => $port, 'proto' => 'tcp', 'state' => 'open',
            'service' => null, 'version' => null, 'procs' => []];
        foreach ($m[1] as $proc) {
            if (!in_array($proc, $ports[$port]['procs'], true)) {
                $ports[$port]['procs'][] = $proc;
            }
        }
    }
    foreach ($ports as &$p) {
        $p['service'] = getservbyport($p['port'], 'tcp') ?: null;
        $p['version'] = implode(', ', $p['procs']) ?: null;
        unset($p['procs']);
        if (isset(IOT_HINTS[$p['port']])) {
            $p['hint'] = IOT_HINTS[$p['port']];
        }
    }
    unset($p);
    ksort($ports);
    return array_values($ports);
}

function local_os(): array
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

function fill_local(array &$d, string $tmp, string $iface, string $myhost, string $localIp): void
{
    $d['role'] = 'this machine';
    $d['deep_scan'] = 'local';
    $d['hostname'] = $d['hostname'] ?: $myhost;
    if (!in_array('local', $d['seen_by'], true)) {
        $d['seen_by'][] = 'local';
    }
    $mac = @file_get_contents("/sys/class/net/$iface/address");
    if ($mac !== false) {
        $d['mac'] = strtolower(trim($mac)) ?: $d['mac'];
    }
    $d['ports'] = local_ports($tmp, $localIp);
    $d['os'] = local_os();
}

$d = &dev($localIp);
fill_local($d, $tmp, $iface, $myhost, $localIp);
unset($d);
$ouis = oui_db();
foreach ($devices as $ip => &$d) {
    if ((string) $ip === $gateway) {
        $d['role'] = 'gateway';
    }
    if ($d['mac']) {
        $d['mac_locally_administered'] = (bool) (hexdec(substr($d['mac'], 0, 2)) & 0x02);
        if (!$d['vendor'] && !$d['mac_locally_administered']) {
            $d['vendor'] = $ouis[strtoupper(substr(str_replace(':', '', $d['mac']), 0, 6))] ?? null;
        }
    }
}
unset($d);

[$netIp, $prefix] = explode('/', $cidr) + [1 => '32'];
$mask = $prefix == 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
$network = long2ip(ip2long($netIp) & $mask) . '/' . (int) $prefix;

$list = array_values($devices);
usort($list, fn($a, $b) => ip_cmp($a['ip'], $b['ip']));
json_out([
    'scan' => [
        'date' => date('c'),
        'host' => $myhost, 'interface' => $iface, 'network' => $network,
        'local_ip' => $localIp, 'local_mac' => $devices[$localIp]['mac'],
        'gateway' => $gateway, 'device_count' => count($devices),
    ],
    'devices' => $list,
]);
