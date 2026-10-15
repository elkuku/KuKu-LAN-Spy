<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\Ip;

/** A single lan-spy report or the output of merge-reports.php. */
final readonly class Report
{
    /**
     * @param list<Device> $devices sorted by IP
     * @param list<string> $scanDates ISO dates of the merged scans, oldest first
     */
    private function __construct(
        public array $devices,
        public bool $merged,
        public ?string $latest,
        public int $scans,
        public ?string $network,
        public ?string $gateway,
        public array $scanDates = [],
    ) {}

    /** @param array<string, mixed> $data the decoded report or merged inventory */
    public static function fromArray(array $data): self
    {
        $devices = array_map(Device::fromArray(...), $data['devices']);
        usort($devices, fn(Device $left, Device $right): int => Ip::compare($left->ip, $right->ip));
        if (!array_key_exists('merged_from', $data)) {
            $scan = $data['scan'];
            return new self($devices, false, $scan['date'], 1, $scan['network'] ?? null, $scan['gateway'] ?? null);
        }
        $dates = array_column($data['merged_from'], 'date');
        return new self(
            $devices,
            true,
            $dates !== [] ? max($dates) : null,
            count($data['merged_from']),
            $data['network'] ?? null,
            $data['gateway'] ?? null,
            $dates,
        );
    }

    public function isOnline(Device $device): bool
    {
        return !$this->merged || $device->lastSeen === $this->latest;
    }

    public function openPortCount(): int
    {
        return array_sum(array_map(fn(Device $device): int => count($device->openPorts()), $this->devices));
    }
}
