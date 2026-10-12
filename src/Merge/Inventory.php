<?php

declare(strict_types=1);

namespace LanSpy\Merge;

use LanSpy\Util\Ip;

/** All devices from a set of reports, merged by MAC address (or IP when there is no MAC). */
final class Inventory
{
    /** @var array<string, MergedDevice> keyed by MAC, or by IP when there is no MAC */
    private array $devices = [];

    /** @param non-empty-list<ReportFile> $reports oldest first, so newer values overwrite */
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $newest = array_last($this->reports)->data['scan'];
        $devices = array_values($this->devices);
        usort($devices, fn(MergedDevice $left, MergedDevice $right): int => Ip::compare($left->ip, $right->ip));
        return [
            'merged_from' => array_map(
                fn(ReportFile $report): array => ['file' => $report->path, 'date' => $report->date, 'devices' => count($report->devices())],
                $this->reports,
            ),
            'network' => $newest['network'] ?? null,
            'gateway' => $newest['gateway'] ?? null,
            'local_ip' => $newest['local_ip'] ?? null,
            'devices' => $devices,
        ];
    }
}
