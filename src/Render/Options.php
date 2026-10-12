<?php

declare(strict_types=1);

namespace LanSpy\Render;

use LanSpy\Util\CliError;

/** The command line of render-html.php. */
final readonly class Options
{
    // device-names.txt in the project folder, next to the scripts.
    public const string DEFAULT_NAMES = __DIR__ . '/../../device-names.txt';

    private const string USAGE = "usage: render-html.php <report.json> [--refresh SECONDS] [--names FILE]\n";

    private function __construct(
        public string $report,
        public int $refresh,
        public string $namesPath,
    ) {}

    /**
     * @param list<string> $args the command line without the script name
     * @throws CliError on invalid arguments
     */
    public static function parse(array $args): self
    {
        $report = null;
        $refresh = 0;
        $namesPath = self::DEFAULT_NAMES;
        while ($args) {
            $arg = array_shift($args);
            if ($arg === '-h' || $arg === '--help') {
                self::help();
            }
            if (preg_match('/^--(refresh|names)(?:=(.*))?$/s', $arg, $match)) {
                $value = $match[2] ?? array_shift($args) ?? throw self::error("--{$match[1]} needs a value");
                if ($match[1] === 'names') {
                    $namesPath = $value;
                } elseif (preg_match('/^-?\d+$/', trim($value))) {
                    $refresh = (int) $value;
                } else {
                    throw self::error("--refresh: invalid number: '$value'");
                }
            } elseif ($report === null && ($arg === '-' || !str_starts_with($arg, '-'))) {
                $report = $arg;
            } else {
                throw self::error("unrecognized argument: $arg");
            }
        }
        return new self($report ?? throw self::error('the report argument is required'), $refresh, $namesPath);
    }

    private static function help(): never
    {
        echo self::USAGE, "\n",
        "  --refresh SECONDS  auto-reload the page every N seconds\n",
        "  --names FILE       custom device names file (default: device-names.txt next to this script, if present)\n";
        exit(0);
    }

    private static function error(string $message): CliError
    {
        return new CliError(self::USAGE . "render-html.php: error: $message\n", 2);
    }
}
