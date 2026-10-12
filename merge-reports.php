#!/usr/bin/env php
<?php

/**
 * Merge lan-spy JSON reports into one device inventory.
 *
 * Usage: ./merge-reports.php [report.json ...] > lan-devices-merged.json
 * With no arguments, merges every lan-report-*.json in the current directory.
 *
 * Devices are matched by MAC address (by IP when there is no MAC). For each
 * device the newest report with a value wins; port and OS data come together
 * from the newest report in which the device was actually port-scanned.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

exit(new LanSpy\Merge\MergeCommand()->run($_SERVER['argv']));
