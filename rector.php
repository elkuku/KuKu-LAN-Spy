<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/build-report.php',
        __DIR__ . '/merge-reports.php',
        __DIR__ . '/render-html.php',
    ])
    // The PHP version comes from composer.json.
    ->withPhpSets()
    ->withImportNames(removeUnusedImports: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true);
