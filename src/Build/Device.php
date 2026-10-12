<?php

declare(strict_types=1);

namespace LanSpy\Build;

use JsonSerializable;

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
