<?php

declare(strict_types=1);

namespace LanSpy\Render;

use DateTimeImmutable;

/** Which scans of a merged inventory saw each device, laid out as one slot per scan. */
final readonly class History
{
    // A pause between two scans longer than this breaks the strips.
    private const int GAP_SECONDS = 3600;

    /** @param list<string> $scanDates ISO dates, oldest first */
    public function __construct(private array $scanDates) {}

    public function scanCount(): int
    {
        return count($this->scanDates);
    }

    /**
     * The scan dates as shown in the tooltips.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(fn(string $date): string => new DateTimeImmutable($date)->format('Y-m-d H:i'), $this->scanDates);
    }

    /** '1' for each scan that saw the device and '0' for the others. */
    public function pattern(Device $device): string
    {
        $pattern = str_repeat('0', $this->scanCount());
        foreach ($device->seenIn ?? [] as $scan) {
            if ($scan >= 0 && $scan < strlen($pattern)) {
                $pattern[$scan] = '1';
            }
        }
        return $pattern;
    }

    /**
     * Runs of consecutive scans in which the device was seen, or not.
     *
     * @return list<array{start: int, length: int, online: bool}>
     */
    public function runs(Device $device): array
    {
        $runs = [];
        foreach (str_split($this->pattern($device)) as $scan => $state) {
            $online = $state === '1';
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['online'] === $online) {
                $runs[$last]['length']++;
            } else {
                $runs[] = ['start' => $scan, 'length' => 1, 'online' => $online];
            }
        }
        return $runs;
    }

    /**
     * The long pauses between scans, each at the index of the scan that ended it.
     *
     * @return list<array{scan: int, hours: int}>
     */
    public function gaps(): array
    {
        $gaps = [];
        $times = array_map(strtotime(...), $this->scanDates);
        foreach ($times as $scan => $time) {
            $pause = $scan > 0 && $time !== false && $times[$scan - 1] !== false ? $time - $times[$scan - 1] : 0;
            if ($pause > self::GAP_SECONDS) {
                $gaps[] = ['scan' => $scan, 'hours' => (int) round($pause / 3600)];
            }
        }
        return $gaps;
    }
}
