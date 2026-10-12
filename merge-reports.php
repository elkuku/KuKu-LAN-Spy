#!/usr/bin/env php
<?php
/**
 * Merge lan-spy JSON reports into one device inventory.
 *
 * Usage: ./merge-reports.php [report.json ...] > lan-devices-merged.json
 * With no arguments, merges every lan-report-*.json in the current directory.
 *
 * Devices are matched by MAC address (by IP when there is no MAC). For each
 * device the newest report with a value wins; port and OS data come together
 * from the newest report in which the device was actually port-scanned.
 */

declare(strict_types=1);

/** One lan-spy report as read from disk. */
final class ReportFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $date,
        public readonly array $data,
    ) {
    }

    /** @throws RuntimeException|JsonException if the file cannot be read or parsed */
    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(error_get_last()['message'] ?? 'cannot read file');
        }
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return new self($path, $data['scan']['date'] ?? throw new RuntimeException('no scan date'), $data);
    }

    /** @return list<array> */
    public function devices(): array
    {
        return $this->data['devices'] ?? [];
    }
}

/** A device across all reports; serializes to its entry in the merged inventory. */
final class MergedDevice implements JsonSerializable
{
    // Copied from the newest report that has a value.
    private const SIMPLE_FIELDS = [
        'vendor' => 'vendor', 'mac_locally_administered' => 'macLocallyAdministered',
        'hostname' => 'hostname', 'netbios' => 'netbios', 'role' => 'role',
    ];
    // Copied together from the newest report that port-scanned the device.
    private const SCAN_FIELDS = [
        'ports' => 'ports', 'ports_not_shown' => 'portsNotShown', 'os' => 'os',
        'network_distance' => 'networkDistance', 'latency_ms' => 'latencyMs',
    ];

    public string $ip;
    /** @var list<string> */
    public array $ipsSeen = [];
    public ?string $vendor = null;
    public ?bool $macLocallyAdministered = null;
    public ?string $hostname = null;
    public ?string $netbios = null;
    public ?string $role = null;
    public string $lastSeen;
    public int $timesSeen = 0;
    /** @var list<string> */
    public array $seenBy = [];
    /** @var list<array> */
    public array $mdns = [];
    public ?string $lastPortScan = null;
    public ?array $ports = null;
    public ?string $portsNotShown = null;
    public ?array $os = null;
    public ?int $networkDistance = null;
    public int|float|null $latencyMs = null;

    public function __construct(public readonly ?string $mac, public readonly string $firstSeen)
    {
    }

    /** Fold in the device as one report saw it; reports must come oldest first. */
    public function add(array $device, string $date): void
    {
        $this->ip = $device['ip'];
        self::addUnique($this->ipsSeen, $device['ip']);
        $this->lastSeen = $date;
        $this->timesSeen++;
        foreach ($device['seen_by'] ?? [] as $source) {
            self::addUnique($this->seenBy, $source);
        }
        foreach (self::SIMPLE_FIELDS as $key => $property) {
            if (isset($device[$key])) {
                $this->$property = $device[$key];
            }
        }
        foreach ($device['mdns'] ?? [] as $entry) {
            self::addUnique($this->mdns, $entry);
        }
        // "local" is this machine, whose ports are read from the kernel instead.
        if (in_array($device['deep_scan'] ?? null, ['ok', 'local'], true)) {
            $this->lastPortScan = $date;
            foreach (self::SCAN_FIELDS as $key => $property) {
                $this->$property = $device[$key] ?? null;
            }
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'ip' => $this->ip, 'mac' => $this->mac, 'ips_seen' => $this->ipsSeen,
            'vendor' => $this->vendor, 'mac_locally_administered' => $this->macLocallyAdministered,
            'hostname' => $this->hostname, 'netbios' => $this->netbios, 'role' => $this->role,
            'first_seen' => $this->firstSeen, 'last_seen' => $this->lastSeen, 'times_seen' => $this->timesSeen,
            'seen_by' => $this->seenBy, 'mdns' => $this->mdns, 'last_port_scan' => $this->lastPortScan,
            'ports' => $this->ports, 'ports_not_shown' => $this->portsNotShown, 'os' => $this->os,
            'network_distance' => $this->networkDistance, 'latency_ms' => $this->latencyMs,
        ];
    }

    private static function addUnique(array &$list, mixed $value): void
    {
        if (!in_array($value, $list, true)) {
            $list[] = $value;
        }
    }
}

final class Inventory
{
    /** @var array<string, MergedDevice> keyed by MAC, or by IP when there is no MAC */
    private array $devices = [];

    /** @param list<ReportFile> $reports oldest first, so newer values overwrite */
    public function __construct(private readonly array $reports)
    {
        foreach ($reports as $report) {
            foreach ($report->devices() as $device) {
                $mac = ($device['mac'] ?? '') ?: null;
                // The prefix keeps PHP from turning numeric-looking keys into integers.
                $key = 'k' . ($mac ?? $device['ip']);
                ($this->devices[$key] ??= new MergedDevice($device['mac'] ?? null, $report->date))->add($device, $report->date);
            }
        }
    }

    public function toArray(): array
    {
        $newest = $this->reports[array_key_last($this->reports)]->data['scan'];
        $devices = array_values($this->devices);
        usort($devices, fn(MergedDevice $left, MergedDevice $right) => ip_cmp($left->ip, $right->ip));
        return [
            'merged_from' => array_map(
                fn(ReportFile $report) => ['file' => $report->path, 'date' => $report->date, 'devices' => count($report->devices())],
                $this->reports,
            ),
            'network' => $newest['network'] ?? null,
            'gateway' => $newest['gateway'] ?? null,
            'local_ip' => $newest['local_ip'] ?? null,
            'devices' => $devices,
        ];
    }
}

/**
 * Load the reports that can be read, oldest first; the others are skipped with a warning.
 *
 * @param list<string> $paths
 * @return list<ReportFile>
 */
function load_reports(array $paths): array
{
    $reports = [];
    foreach ($paths as $path) {
        try {
            $reports[] = ReportFile::load($path);
        } catch (JsonException|RuntimeException $error) {
            fwrite(STDERR, "skipping $path: {$error->getMessage()}\n");
        }
    }
    usort($reports, fn(ReportFile $left, ReportFile $right) => [$left->date, $left->path] <=> [$right->date, $right->path]);
    return $reports;
}

function ip_cmp(string $left, string $right): int
{
    $left = inet_pton($left);
    $right = inet_pton($right);
    return [strlen($left), $left] <=> [strlen($right), $right];
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
    $reports = load_reports(array_slice($argv, 1) ?: glob('lan-report-*.json'));
    if (!$reports) {
        fwrite(STDERR, "no reports to merge\n");
        return 1;
    }
    json_out((new Inventory($reports))->toArray());
    return 0;
}

exit(main($argv));
