<?php

declare(strict_types=1);

// Loads the LanSpy\ classes from this folder, e.g. LanSpy\Render\Page from Render/Page.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'LanSpy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
