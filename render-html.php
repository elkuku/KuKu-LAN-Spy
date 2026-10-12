#!/usr/bin/env php
<?php

/**
 * Render a lan-spy report or merged inventory as a standalone HTML page.
 *
 * Usage: ./render-html.php <report.json> [--refresh SECONDS] [--names FILE] > lan-devices.html
 *
 * Pass - as the report to read it from stdin.
 *
 * Accepts the output of lan-spy.sh (single scan) or merge-reports.php (merged).
 * The page is built from the templates in templates/ and inlines their CSS and JS.
 * Custom device names are read from device-names.txt next to this script, if it
 * exists (see device-names.example.txt).
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

exit((new LanSpy\Render\RenderCommand())->run($_SERVER['argv']));
