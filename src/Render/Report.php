<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Ip;

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
        usort($devices, fn(Device $left, Device $right) => Ip::compare($left->ip, $right->ip));
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
