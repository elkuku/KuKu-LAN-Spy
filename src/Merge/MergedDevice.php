<?php

declare(strict_types=1);

namespace LanSpy\Merge;

use JsonSerializable;

/** A device across all reports; serializes to its entry in the merged inventory. */
final class MergedDevice implements JsonSerializable
{
    // Copied from the newest report that has a value.
    private const array SIMPLE_FIELDS = [
        'vendor' => 'vendor', 'mac_locally_administered' => 'macLocallyAdministered',
        'hostname' => 'hostname', 'netbios' => 'netbios', 'role' => 'role',
    ];
    // Copied together from the newest report that port-scanned the device.
    private const array SCAN_FIELDS = [
        'ports' => 'ports', 'ports_not_shown' => 'portsNotShown', 'os' => 'os',
        'network_distance' => 'networkDistance', 'latency_ms' => 'latencyMs',
    ];

    // Read from outside, set only by add().
    public private(set) string $ip;
    /** @var list<string> */
    public private(set) array $ipsSeen = [];
    public private(set) ?string $vendor = null;
    public private(set) ?bool $macLocallyAdministered = null;
    public private(set) ?string $hostname = null;
    public private(set) ?string $netbios = null;
    public private(set) ?string $role = null;
    public private(set) string $lastSeen;
    public private(set) int $timesSeen = 0;
    /** @var list<string> */
    public private(set) array $seenBy = [];
    /** @var list<array> */
    public private(set) array $mdns = [];
    public private(set) ?string $lastPortScan = null;
    /** @var ?list<array<string, mixed>> */
    public private(set) ?array $ports = null;
    public private(set) ?string $portsNotShown = null;
    /** @var ?array<string, mixed> */
    public private(set) ?array $os = null;
    public private(set) ?int $networkDistance = null;
    public private(set) int|float|null $latencyMs = null;

    public function __construct(public readonly ?string $mac, public readonly string $firstSeen) {}

    /**
     * Fold in the device as one report saw it; reports must come oldest first.
     *
     * @param array<string, mixed> $device
     */
    public function add(array $device, string $date): void
    {
        $this->ip = $device['ip'];
        $this->addUnique($this->ipsSeen, $device['ip']);
        $this->lastSeen = $date;
        $this->timesSeen++;
        foreach ($device['seen_by'] ?? [] as $source) {
            $this->addUnique($this->seenBy, $source);
        }
        foreach (self::SIMPLE_FIELDS as $key => $property) {
            if (isset($device[$key])) {
                $this->$property = $device[$key];
            }
        }
        foreach ($device['mdns'] ?? [] as $entry) {
            $this->addUnique($this->mdns, $entry);
        }
        // "local" is this machine, whose ports are read from the kernel instead.
        if (in_array($device['deep_scan'] ?? null, ['ok', 'local'], true)) {
            $this->lastPortScan = $date;
            foreach (self::SCAN_FIELDS as $key => $property) {
                $this->$property = $device[$key] ?? null;
            }
        }
    }

    /** @return array<string, mixed> */
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

    /**
     * @template T
     * @param list<T> $list
     * @param T $value
     * @param-out list<T> $list
     */
    private function addUnique(array &$list, mixed $value): void
    {
        if (!in_array($value, $list, true)) {
            $list[] = $value;
        }
    }
}
