<?php

declare(strict_types=1);

namespace LanSpy\Render;

/** The HTML page for a report: one row per device, plus stats and filters. */
final class Page
{
    // Icon for the "All types" entry of the type filter.
    private const ALL_TYPES_ICON = '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
        . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>';

    public function __construct(
        private readonly Report $report,
        private readonly DeviceNames $names,
        private readonly int $refresh,
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
            'latestScan' => Html::formatTime($report->latest),
            'stats' => $stats,
            'typeOptions' => $typeOptions,
            'seenHeader' => $report->merged ? 'Seen' : 'Port scan',
            'rows' => $rows,
            'generated' => date('Y-m-d H:i:s'),
        ]);
    }
}
