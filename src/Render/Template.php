<?php

declare(strict_types=1);

namespace LanSpy\Render;

use RuntimeException;

/** Renders the PHP templates in the templates/ folder of the project. */
final class Template
{
    private const DIR = __DIR__ . '/../../templates';

    /** @param array<string, mixed> $vars the variables the template sees */
    public static function render(string $name, array $vars = []): string
    {
        return (static function (string $templateFile, array $templateVars): string {
            extract($templateVars, EXTR_SKIP);
            ob_start();
            require $templateFile;
            return (string) ob_get_clean();
        })(self::DIR . "/$name", $vars);
    }

    /** A file from templates/ as it is, for inlining CSS and JS. */
    public static function file(string $name): string
    {
        $contents = file_get_contents(self::DIR . "/$name");
        if ($contents === false) {
            throw new RuntimeException("cannot read template $name");
        }
        return $contents;
    }
}
