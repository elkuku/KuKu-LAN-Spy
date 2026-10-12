#!/usr/bin/env php
<?php
/**
 * Render a lan-spy report or merged inventory as a standalone HTML page.
 *
 * Usage: ./render-html.php <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html
 *
 * Pass - as the report to read it from stdin.
 *
 * Accepts the output of lan-spy.sh (single scan) or merge-reports.php (merged).
 * The page is built from the templates in templates/ and inlines their CSS and JS.
 * Custom device names are read from device-names.txt next to this script, if it
 * exists (see device-names.example.txt).
 */

declare(strict_types=1);

const DEFAULT_NAMES = __DIR__ . '/device-names.txt';

/** A device type: its label and icon on the page, and the words in a custom name that give it away. */
enum DeviceType: string
{
    case Router = 'router';
    case Wifi = 'wifi';
    case Computer = 'computer';
    case Laptop = 'laptop';
    case Server = 'server';
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Tv = 'tv';
    case Camera = 'camera';
    case Speaker = 'speaker';
    case Printer = 'printer';
    case Bulb = 'bulb';
    case Plug = 'plug';
    case Unknown = 'unknown';

    // The order custom names are checked for keywords in; the first match wins.
    private const NAME_ORDER = [
        self::Camera, self::Bulb, self::Phone, self::Tablet, self::Tv, self::Laptop, self::Computer,
        self::Server, self::Printer, self::Speaker, self::Router, self::Wifi, self::Plug,
    ];

    public function label(): string
    {
        return match ($this) {
            self::Router => 'Router',
            self::Wifi => 'Access point / extender',
            self::Computer => 'Computer',
            self::Laptop => 'Laptop',
            self::Server => 'Server / NAS',
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Tv => 'TV / streaming',
            self::Camera => 'Camera',
            self::Speaker => 'Speaker',
            self::Printer => 'Printer',
            self::Bulb => 'Light',
            self::Plug => 'Smart plug / switch',
            self::Unknown => 'Unknown device',
        };
    }

    /** Inline SVG body for a 24x24 viewBox, drawn with strokes. */
    public function icon(): string
    {
        return match ($this) {
            self::Router => '<rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 17.5h.01M10 17.5h.01M15 14V9"/>'
                . '<path d="M11.5 6.5a5 5 0 0 1 7 0M9 4a8.5 8.5 0 0 1 12 0"/>',
            self::Wifi => '<path d="M2 9a14 14 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01"/>',
            self::Computer => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
            self::Laptop => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
            self::Server => '<rect x="2" y="3" width="20" height="8" rx="2"/>'
                . '<rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
            self::Phone => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
            self::Tablet => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/>',
            self::Tv => '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="m7 2 5 5 5-5"/>',
            self::Camera => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/>'
                . '<circle cx="12" cy="13" r="3"/>',
            self::Speaker => '<rect x="5" y="2" width="14" height="20" rx="2"/>'
                . '<circle cx="12" cy="14" r="4"/><path d="M12 6h.01"/>',
            self::Printer => '<path d="M6 9V2h12v7"/><rect x="2" y="9" width="20" height="9" rx="2"/>'
                . '<rect x="6" y="14" width="12" height="8"/>',
            self::Bulb => '<path d="M9 18h6M10 22h4"/>'
                . '<path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V18h6v-1.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>',
            self::Plug => '<path d="M9 2v6M15 2v6M12 18v4M6 8h12v4a6 6 0 0 1-12 0z"/>',
            self::Unknown => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>',
        };
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return match ($this) {
            self::Camera => ['cam', 'camara', 'camera', 'cámara', 'doorbell', 'timbre', 'webcam'],
            self::Bulb => ['bulb', 'foco', 'lamp', 'lampara', 'light', 'luz', 'lámpara'],
            self::Phone => ['celular', 'galaxy', 'handy', 'iphone', 'movil', 'móvil', 'phone', 'pixel', 'telefono', 'teléfono'],
            self::Tablet => ['ipad', 'tablet'],
            self::Tv => ['chromecast', 'firestick', 'roku', 'television', 'televisión', 'tv'],
            self::Laptop => ['laptop', 'macbook', 'notebook', 'thinkpad'],
            self::Computer => ['computadora', 'computer', 'desktop', 'imac', 'pc'],
            self::Server => ['nas', 'pi', 'raspberry', 'server', 'servidor'],
            self::Printer => ['drucker', 'impresora', 'printer'],
            self::Speaker => ['alexa', 'echo', 'parlante', 'sonos', 'speaker'],
            self::Router => ['gateway', 'modem', 'módem', 'router'],
            self::Wifi => ['ap', 'extender', 'mesh', 'repeater', 'repetidor'],
            self::Plug => ['enchufe', 'outlet', 'plug', 'socket'],
            self::Unknown => [],
        };
    }

    /** The type a custom name gives away, if any. */
    public static function fromName(string $name): ?self
    {
        $words = preg_split('/\s+/u', mb_strtolower(str_replace(['-', '_'], ' ', $name)), -1, PREG_SPLIT_NO_EMPTY);
        foreach (self::NAME_ORDER as $type) {
            if (array_intersect($words, $type->keywords())) {
                return $type;
            }
        }
        return null;
    }

    /** Guess the type from the scan data and the custom name, strongest signal first. */
    public static function guess(Device $device, ?string $name): self
    {
        if ($device->role === 'gateway') {
            return self::Router;
        }
        if ($device->role === 'this machine') {
            return self::Computer;
        }
        $openPorts = $device->openPorts();
        $isOpen = fn(int ...$numbers): bool => (bool) array_filter($openPorts, fn(Port $port) => in_array($port->port, $numbers, true));
        // avahi-browse may report "_ipp._tcp" or its readable name "Internet Printer", so match loosely.
        $mdns = mb_strtolower(implode(' ', array_map(fn(MdnsEntry $entry) => $entry->service ?? '', $device->mdns)));
        $rtsp = array_filter($openPorts, fn(Port $port) => str_contains($port->service ?? '', 'rtsp'));
        $osName = mb_strtolower($device->os->bestGuess ?? '');
        $fromName = $name !== null ? self::fromName($name) : null;

        return match (true) {
            $isOpen(554) || $rtsp => self::Camera,
            $isOpen(8009) || contains_any($mdns, 'googlecast', 'google cast', 'airplay', 'androidtvremote') => self::Tv,
            $isOpen(631, 9100) || contains_any($mdns, '_ipp', 'printer', 'pdl-datastream') => self::Printer,
            $fromName !== null => $fromName,
            contains_any($mdns, 'raop', 'airtunes', 'spotify', 'sonos') => self::Speaker,
            $isOpen(6668, 9999) || contains_any(mb_strtolower($device->vendor ?? ''), 'tuya', 'espressif', 'shelly', 'sonoff')
                => self::Plug,
            $isOpen(53) => self::Wifi,
            str_contains($osName, 'android') || in_array('ios', preg_split('/\s+/u', $osName), true) => self::Phone,
            contains_any($osName, 'windows', 'macos', 'mac os') => self::Computer,
            $device->macRandomized => self::Phone,
            default => self::Unknown,
        };
    }
}

final class Port
{
    public function __construct(
        public readonly int $port,
        public readonly ?string $state,
        public readonly ?string $service,
        public readonly ?string $version,
        public readonly ?string $hint,
    ) {
    }

    public static function fromArray(array $port): self
    {
        return new self((int) $port['port'], str_or_null($port['state'] ?? null), str_or_null($port['service'] ?? null),
            str_or_null($port['version'] ?? null), str_or_null($port['hint'] ?? null));
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }
}

final class MdnsEntry
{
    public function __construct(public readonly ?string $hostname, public readonly ?string $service)
    {
    }

    public static function fromArray(array $entry): self
    {
        return new self(str_or_null($entry['hostname'] ?? null), str_or_null($entry['service'] ?? null));
    }
}

final class OsGuess
{
    public function __construct(
        public readonly ?string $bestGuess,
        public readonly ?string $details,
        public readonly int|float|null $accuracy,
        public readonly ?string $source,
    ) {
    }

    public static function fromArray(?array $os): self
    {
        return new self(str_or_null($os['best_guess'] ?? null), str_or_null($os['details'] ?? null),
            $os['accuracy'] ?? null, str_or_null($os['source'] ?? null));
    }
}

/** One device from a single report or a merged inventory; fields missing from the input are null. */
final class Device
{
    /**
     * @param list<MdnsEntry> $mdns
     * @param ?list<Port> $ports null if the device was not port scanned
     */
    public function __construct(
        public readonly string $ip,
        public readonly ?string $mac,
        public readonly bool $macRandomized,
        public readonly ?string $vendor,
        public readonly ?string $hostname,
        public readonly ?string $netbios,
        public readonly ?string $role,
        public readonly array $mdns,
        public readonly ?array $ports,
        public readonly ?string $portsNotShown,
        public readonly OsGuess $os,
        public readonly int|float|null $latencyMs,
        public readonly int $timesSeen,
        public readonly ?string $lastSeen,
        public readonly ?string $deepScan,
    ) {
    }

    public static function fromArray(array $device): self
    {
        return new self(
            ip: $device['ip'],
            mac: str_or_null($device['mac'] ?? null),
            macRandomized: !empty($device['mac_locally_administered']),
            vendor: str_or_null($device['vendor'] ?? null),
            hostname: str_or_null($device['hostname'] ?? null),
            netbios: str_or_null($device['netbios'] ?? null),
            role: str_or_null($device['role'] ?? null),
            mdns: array_map(MdnsEntry::fromArray(...), $device['mdns'] ?? []),
            ports: isset($device['ports']) ? array_map(Port::fromArray(...), $device['ports']) : null,
            portsNotShown: str_or_null($device['ports_not_shown'] ?? null),
            os: OsGuess::fromArray($device['os'] ?? null),
            latencyMs: $device['latency_ms'] ?? null,
            timesSeen: $device['times_seen'] ?? 0,
            lastSeen: str_or_null($device['last_seen'] ?? null),
            deepScan: str_or_null($device['deep_scan'] ?? null),
        );
    }

    /** @return list<Port> */
    public function openPorts(): array
    {
        return array_values(array_filter($this->ports ?? [], fn(Port $port) => $port->isOpen()));
    }

    /** Hostname, NetBIOS and mDNS names, without duplicates. @return list<string> */
    public function names(): array
    {
        $names = [$this->hostname, $this->netbios, ...array_map(fn(MdnsEntry $entry) => $entry->hostname, $this->mdns)];
        return array_values(array_unique(array_filter($names, fn(?string $name) => $name !== null)));
    }

    /** Announced mDNS services, sorted. @return list<string> */
    public function mdnsServices(): array
    {
        $services = array_unique(array_filter(array_map(fn(MdnsEntry $entry) => $entry->service, $this->mdns), fn(?string $value) => $value !== null));
        sort($services, SORT_STRING);
        return $services;
    }
}

/** A single lan-spy report or the output of merge-reports.php. */
final class Report
{
    /** @param list<Device> $devices sorted by IP */
    private function __construct(
        public readonly array $devices,
        public readonly bool $merged,
        public readonly ?string $latest,
        public readonly int $scans,
        public readonly ?string $network,
        public readonly ?string $gateway,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $devices = array_map(Device::fromArray(...), $data['devices']);
        usort($devices, fn(Device $left, Device $right) => ip_cmp($left->ip, $right->ip));
        if (!array_key_exists('merged_from', $data)) {
            $scan = $data['scan'];
            return new self($devices, false, $scan['date'], 1, $scan['network'] ?? null, $scan['gateway'] ?? null);
        }
        $dates = array_column($data['merged_from'], 'date');
        return new self($devices, true, $dates ? max($dates) : null, count($data['merged_from']),
            $data['network'] ?? null, $data['gateway'] ?? null);
    }

    public function isOnline(Device $device): bool
    {
        return !$this->merged || $device->lastSeen === $this->latest;
    }

    public function openPortCount(): int
    {
        return array_sum(array_map(fn(Device $device) => count($device->openPorts()), $this->devices));
    }
}

/** A line from device-names.txt: a name, a type in brackets, or both. */
final class CustomName
{
    public function __construct(public readonly ?string $name = null, public readonly ?DeviceType $type = null)
    {
    }
}

/** Custom device names: one '<mac-or-ip> [type] <name>' per line, '#' lines are comments. */
final class DeviceNames
{
    /** @param array<string, CustomName> $byAddress keyed by lowercase MAC (with colons) or IP */
    private function __construct(private readonly array $byAddress)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** @throws CliError on an unknown device type */
    public static function load(string $path): self
    {
        $byAddress = [];
        foreach (preg_split('/\r\n|\r|\n/', file_get_contents($path)) as $line) {
            $parts = preg_split('/\s+/u', ltrim($line), 2, PREG_SPLIT_NO_EMPTY);
            if (count($parts) !== 2 || str_starts_with($parts[0], '#')) {
                continue;
            }
            $name = trim($parts[1]);
            $type = null;
            if (str_starts_with($name, '[') && str_contains($name, ']')) {
                [$kind, $name] = explode(']', substr($name, 1), 2);
                $kind = mb_strtolower(trim($kind));
                $name = trim($name);
                $type = DeviceType::tryFrom($kind) ?? throw new CliError("unknown device type [$kind] in $path; use one of: "
                    . implode(', ', array_column(DeviceType::cases(), 'value')) . "\n", 1);
            }
            $byAddress[str_replace('-', ':', mb_strtolower($parts[0]))] = new CustomName(str_or_null($name), $type);
        }
        return new self($byAddress);
    }

    public function lookup(Device $device): CustomName
    {
        foreach ([$device->mac, $device->ip] as $address) {
            if ($address !== null && isset($this->byAddress[mb_strtolower($address)])) {
                return $this->byAddress[mb_strtolower($address)];
            }
        }
        return new CustomName();
    }
}

/** Renders the PHP templates in templates/, next to this script. */
final class Template
{
    private const DIR = __DIR__ . '/templates';

    /** @param array<string, mixed> $vars the variables the template sees */
    public static function render(string $name, array $vars = []): string
    {
        return (static function (string $templateFile, array $templateVars): string {
            extract($templateVars, EXTR_SKIP);
            ob_start();
            require $templateFile;
            return ob_get_clean();
        })(self::DIR . "/$name", $vars);
    }

    /** A file from templates/ as it is, for inlining CSS and JS. */
    public static function file(string $name): string
    {
        return file_get_contents(self::DIR . "/$name");
    }
}

final class Page
{
    // Icon for the "All types" entry of the type filter.
    private const ALL_TYPES_ICON = '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
        . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>';

    public function __construct(
        private readonly Report $report,
        private readonly DeviceNames $names,
        private readonly int $refresh,
    ) {
    }

    public function render(): string
    {
        $report = $this->report;
        $rows = [];
        $types = [];
        foreach ($report->devices as $device) {
            $custom = $this->names->lookup($device);
            $type = $custom->type ?? DeviceType::guess($device, $custom->name);
            $types[] = $type->value;
            $rows[] = rtrim(Template::render('device-row.html.php', [
                'device' => $device,
                'type' => $type,
                'customName' => $custom->name,
                'online' => $report->isOnline($device),
                'merged' => $report->merged,
                'scans' => $report->scans,
            ]));
        }

        $counts = array_count_values($types);
        $typeOptions = [['', 'All types', self::ALL_TYPES_ICON, count($report->devices)]];
        foreach (DeviceType::cases() as $type) {
            if (isset($counts[$type->value])) {
                $typeOptions[] = [$type->value, $type->label(), $type->icon(), $counts[$type->value]];
            }
        }

        $stats = [
            [count($report->devices), $report->merged ? 'devices known' : 'devices'],
            [count(array_filter($report->devices, $report->isOnline(...))), 'in latest scan'],
            [$report->openPortCount(), 'open ports'],
        ];
        if ($report->merged) {
            $stats[] = [$report->scans, 'scans merged'];
        }

        return Template::render('page.html.php', [
            'refresh' => $this->refresh,
            'css' => Template::file('style.css'),
            'script' => Template::file('script.js'),
            'network' => $report->network,
            'gateway' => $report->gateway,
            'latestScan' => fmt_time($report->latest),
            'stats' => $stats,
            'typeOptions' => $typeOptions,
            'seenHeader' => $report->merged ? 'Seen' : 'Port scan',
            'rows' => $rows,
            'generated' => date('Y-m-d H:i:s'),
        ]);
    }
}

/** An error to report on stderr; the code is the exit status. */
final class CliError extends RuntimeException
{
}

final class Options
{
    private const USAGE = "usage: render-html.php <report.json> [--refresh SECONDS] [--names FILE]\n";

    private function __construct(
        public readonly string $report,
        public readonly int $refresh,
        public readonly string $namesPath,
    ) {
    }

    /**
     * @param list<string> $args the command line without the script name
     * @throws CliError on invalid arguments
     */
    public static function parse(array $args): self
    {
        $report = null;
        $refresh = 0;
        $namesPath = DEFAULT_NAMES;
        while ($args) {
            $arg = array_shift($args);
            if ($arg === '-h' || $arg === '--help') {
                self::help();
            }
            if (preg_match('/^--(refresh|names)(?:=(.*))?$/s', $arg, $match)) {
                $value = $match[2] ?? array_shift($args) ?? throw self::error("--{$match[1]} needs a value");
                if ($match[1] === 'names') {
                    $namesPath = $value;
                } elseif (preg_match('/^-?\d+$/', trim($value))) {
                    $refresh = (int) $value;
                } else {
                    throw self::error("--refresh: invalid number: '$value'");
                }
            } elseif ($report === null && ($arg === '-' || !str_starts_with($arg, '-'))) {
                $report = $arg;
            } else {
                throw self::error("unrecognized argument: $arg");
            }
        }
        return new self($report ?? throw self::error('the report argument is required'), $refresh, $namesPath);
    }

    private static function help(): never
    {
        echo self::USAGE, "\n",
            "  --refresh SECONDS  auto-reload the page every N seconds\n",
            "  --names FILE       custom device names file (default: device-names.txt next to this script, if present)\n";
        exit(0);
    }

    private static function error(string $message): CliError
    {
        return new CliError(self::USAGE . "render-html.php: error: $message\n", 2);
    }
}

function esc(?string $value): string
{
    return strtr((string) $value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
}

/** Treat '' like a missing value, as the scan data does. */
function str_or_null(mixed $value): ?string
{
    return $value === null || $value === '' ? null : (string) $value;
}

function contains_any(string $haystack, string ...$needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

/** Use the system timezone (PHP defaults to UTC) so "Generated" shows local time. */
function use_local_timezone(): void
{
    $tz = getenv('TZ') ?: (preg_match('#zoneinfo/(.+)$#', (string) @readlink('/etc/localtime'), $match) ? $match[1] : '');
    if (in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

function ip_cmp(string $left, string $right): int
{
    $left = inet_pton($left);
    $right = inet_pton($right);
    return [strlen($left), $left] <=> [strlen($right), $right];
}

function fmt_time(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d H:i');
    } catch (Exception) {
        return $iso;
    }
}

/** @throws CliError if the report cannot be read or parsed */
function read_report(string $path): Report
{
    $raw = @file_get_contents($path === '-' ? 'php://stdin' : $path);
    if ($raw === false) {
        throw new CliError("cannot read $path\n", 1);
    }
    try {
        return Report::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
    } catch (JsonException $error) {
        throw new CliError("cannot parse $path: {$error->getMessage()}\n", 1);
    }
}

function main(array $argv): int
{
    use_local_timezone();
    try {
        $options = Options::parse(array_slice($argv, 1));
        $report = read_report($options->report);
        if ($options->namesPath !== DEFAULT_NAMES && !file_exists($options->namesPath)) {
            throw new CliError("names file not found: {$options->namesPath}\n", 1);
        }
        $names = file_exists($options->namesPath) ? DeviceNames::load($options->namesPath) : DeviceNames::none();
    } catch (CliError $error) {
        fwrite(STDERR, $error->getMessage());
        return $error->getCode();
    }
    echo (new Page($report, $names, $options->refresh))->render();
    return 0;
}

exit(main($argv));
