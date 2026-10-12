<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Text;

/** One device from a single report or a merged inventory; fields missing from the input are null. */
final readonly class Device
{
    /**
     * @param list<MdnsEntry> $mdns
     * @param ?list<Port> $ports null if the device was not port scanned
     */
    public function __construct(
        public string $ip,
        public ?string $mac,
        public bool $macRandomized,
        public ?string $vendor,
        public ?string $hostname,
        public ?string $netbios,
        public ?string $role,
        public array $mdns,
        public ?array $ports,
        public ?string $portsNotShown,
        public OsGuess $os,
        public int|float|null $latencyMs,
        public int $timesSeen,
        public ?string $lastSeen,
        public ?string $deepScan,
    ) {}

    /** @param array<string, mixed> $device */
    public static function fromArray(array $device): self
    {
        return new self(
            ip: $device['ip'],
            mac: Text::orNull($device['mac'] ?? null),
            macRandomized: !empty($device['mac_locally_administered']),
            vendor: Text::orNull($device['vendor'] ?? null),
            hostname: Text::orNull($device['hostname'] ?? null),
            netbios: Text::orNull($device['netbios'] ?? null),
            role: Text::orNull($device['role'] ?? null),
            mdns: array_values(array_map(MdnsEntry::fromArray(...), $device['mdns'] ?? [])),
            ports: isset($device['ports']) ? array_values(array_map(Port::fromArray(...), $device['ports'])) : null,
            portsNotShown: Text::orNull($device['ports_not_shown'] ?? null),
            os: OsGuess::fromArray($device['os'] ?? null),
            latencyMs: $device['latency_ms'] ?? null,
            timesSeen: $device['times_seen'] ?? 0,
            lastSeen: Text::orNull($device['last_seen'] ?? null),
            deepScan: Text::orNull($device['deep_scan'] ?? null),
        );
    }

    /** @return list<Port> */
    public function openPorts(): array
    {
        return array_values(array_filter($this->ports ?? [], fn(Port $port): bool => $port->isOpen()));
    }

    /**
     * Hostname, NetBIOS and mDNS names, without duplicates.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [$this->hostname, $this->netbios, ...array_map(fn(MdnsEntry $entry): ?string => $entry->hostname, $this->mdns)];
        return array_values(array_unique(array_filter($names, fn(?string $name): bool => $name !== null)));
    }

    /**
     * Announced mDNS services, sorted.
     *
     * @return list<string>
     */
    public function mdnsServices(): array
    {
        $services = array_unique(array_filter(array_map(fn(MdnsEntry $entry): ?string => $entry->service, $this->mdns), fn(?string $value): bool => $value !== null));
        sort($services, SORT_STRING);
        return $services;
    }
}
