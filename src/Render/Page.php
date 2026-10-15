<?php

declare(strict_types=1);

namespace LanSpy\Render;

/** The HTML page for a report: one row per device, plus stats and filters. */
final readonly class Page
{
    // Icon for the "All types" entry of the type filter.
    private const string ALL_TYPES_ICON = '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
        . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>';

    public function __construct(
        private Report $report,
        private DeviceNames $names,
        private int $refresh,
    ) {}

    public function render(): string
    {
        $report = $this->report;
        $rows = [];
        $types = [];
        foreach ($report->devices as $device) {
            $custom = $this->names->lookup($device);
            $type = $custom->type ?? DeviceType::guess($device, $custom->name);
            $types[] = $type->value;
            $rows[] = [
                'device' => $device,
                'type' => $type,
                'customName' => $custom->name,
                'online' => $report->isOnline($device),
            ];
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

        return Template::render('page.html.twig', [
            'refresh' => $this->refresh,
            'network' => $report->network,
            'gateway' => $report->gateway,
            'latestScan' => $report->latest,
            'stats' => $stats,
            'typeOptions' => $typeOptions,
            'seenHeader' => $report->merged ? 'Seen' : 'Port scan',
            'rows' => $rows,
            'merged' => $report->merged,
            'scans' => $report->scans,
            'history' => $this->hasHistory() ? new History($report->scanDates) : null,
        ]);
    }

    /** Whether the report is a merged inventory that records which scans saw each device. */
    private function hasHistory(): bool
    {
        return $this->report->merged && array_any($this->report->devices, fn(Device $device): bool => $device->seenIn !== null);
    }
}
