<?php

declare(strict_types=1);

namespace LanSpy\Merge;

use JsonException;
use LanSpy\Util\Json;
use RuntimeException;

/** merge-reports.php: merge lan-spy reports into one device inventory. */
final class MergeCommand
{
    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $reports = $this->loadReports(array_slice($argv, 1) ?: (glob('lan-report-*.json') ?: []));
        if ($reports === []) {
            fwrite(STDERR, "no reports to merge\n");
            return 1;
        }
        Json::write((new Inventory($reports))->toArray());
        return 0;
    }

    /**
     * Load the reports that can be read, oldest first; the others are skipped with a warning.
     *
     * @param list<string> $paths
     * @return list<ReportFile>
     */
    private function loadReports(array $paths): array
    {
        $reports = [];
        foreach ($paths as $path) {
            try {
                $reports[] = ReportFile::load($path);
            } catch (JsonException|RuntimeException $error) {
                fwrite(STDERR, "skipping $path: {$error->getMessage()}\n");
            }
        }
        usort($reports, fn(ReportFile $left, ReportFile $right): int => [$left->date, $left->path] <=> [$right->date, $right->path]);
        return $reports;
    }
}
