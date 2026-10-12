<?php

declare(strict_types=1);

// The templates are left out: their whitespace ends up in the HTML.
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->append([
        __DIR__ . '/build-report.php',
        __DIR__ . '/merge-reports.php',
        __DIR__ . '/render-html.php',
        __DIR__ . '/rector.php',
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x1Migration' => true,
        '@PHP8x1Migration:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
