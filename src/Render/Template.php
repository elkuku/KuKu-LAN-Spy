<?php

declare(strict_types=1);

namespace LanSpy\Render;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Renders the Twig templates in the templates/ folder of the project. */
final class Template
{
    private const string DIR = __DIR__ . '/../../templates';

    private static ?Environment $twig = null;

    /** @param array<string, mixed> $vars the variables the template sees */
    public static function render(string $name, array $vars = []): string
    {
        return self::twig()->render($name, $vars);
    }

    private static function twig(): Environment
    {
        if (!self::$twig instanceof Environment) {
            self::$twig = new Environment(new FilesystemLoader(self::DIR), [
                'autoescape' => 'html',
                'strict_variables' => true,
            ]);
        }
        return self::$twig;
    }
}
