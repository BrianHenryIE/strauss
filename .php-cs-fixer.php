<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Relative to the `in()` directories.
    ->exclude([
        'Benchmark',
        'Issues/data',
        '_reports',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP8x2Migration' => true,
    ])
    ->setFinder($finder)
    ;
