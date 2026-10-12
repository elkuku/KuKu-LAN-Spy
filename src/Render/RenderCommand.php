<?php

declare(strict_types=1);

namespace LanSpy\Render;

use JsonException;
use LanSpy\Util\CliError;
use LanSpy\Util\Timezone;

/** render-html.php: render a report or merged inventory as a standalone HTML page. */
final class RenderCommand
{
    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        Timezone::useLocal();
        try {
            $options = Options::parse(array_slice($argv, 1));
            $report = $this->readReport($options->report);
            if ($options->namesPath !== Options::DEFAULT_NAMES && !file_exists($options->namesPath)) {
                throw new CliError("names file not found: {$options->namesPath}\n", 1);
            }
            $names = file_exists($options->namesPath) ? DeviceNames::load($options->namesPath) : DeviceNames::none();
        } catch (CliError $error) {
            fwrite(STDERR, $error->getMessage());
            return $error->getCode();
        }
        echo (new Page($report, $names, $options->refresh))->render();
        return 0;
    }

    /** @throws CliError if the report cannot be read or parsed */
    private function readReport(string $path): Report
    {
        $raw = @file_get_contents($path === '-' ? 'php://stdin' : $path);
        if ($raw === false) {
            throw new CliError("cannot read $path\n", 1);
        }
        try {
            return Report::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException $error) {
            throw new CliError("cannot parse $path: {$error->getMessage()}\n", 1, $error);
        }
    }
}
