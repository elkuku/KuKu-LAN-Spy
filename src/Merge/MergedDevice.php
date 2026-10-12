<?php

declare(strict_types=1);

namespace LanSpy\Merge;

use JsonSerializable;

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
