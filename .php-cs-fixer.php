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
        // Matches the minimum PHP version in composer.json.
        '@PHP7x4Migration' => true,

        // PER-CS: no trailing comma when a list is on a single line. Without it, `method_argument_space` turns
        // `foo($a,)` into `foo($a, )`, which fails PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket.
        'no_trailing_comma_in_singleline' => true,
    ])
    ->setFinder($finder)
    ;
