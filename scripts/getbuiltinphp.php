<?php
/**
 * Get all built-in PHP classes, interfaces, traits, enums and functions.
 *
 * Run once per PHP version, oldest first, e.g.
 * `for v in 8.1 8.2 8.3 8.4 8.5 8.6; do /opt/homebrew/opt/php@$v/bin/php scripts/getbuiltinphp.php; done`
 * then copy the output into `src/Pipeline/FileSymbol/builtinsymbols.php`.
 *
 * TODO: consider using JetBrains/phpstorm-stubs or PhpStan stubs to build the list of built-in classes, interfaces, traits.
 */

$outputFile = __DIR__ . '/builtins.php';

$builtins = file_exists($outputFile) ? require $outputFile : [];

$currentPhpVersion = implode(
    '.',
    array_slice(
        explode('.', phpversion()),
        0,
        2
    )
);

$symbolTypes = ['classes', 'interfaces', 'traits', 'enums', 'functions'];

if (!isset($builtins[$currentPhpVersion])) {
    $builtins[$currentPhpVersion] = [];
}
// Ensure every version has every key, e.g. `enums` for versions recorded before it was added.
foreach ($builtins as $phpVersion => $builtinsArray) {
    foreach ($symbolTypes as $symbolType) {
        $builtins[$phpVersion][$symbolType] = $builtinsArray[$symbolType] ?? [];
    }
}

/**
 * Enums are returned by `get_declared_classes()`. `ReflectionClass::isEnum()` exists since PHP 8.1.
 */
$isBuiltInEnum = function (string $className): bool {
    $reflector = new \ReflectionClass($className);
    return empty($reflector->getFileName())
        && method_exists($reflector, 'isEnum')
        && $reflector->isEnum();
};

$classes = array_filter(
    get_declared_classes(),
    function (string $className) use ($isBuiltInEnum): bool {
        $reflector = new \ReflectionClass($className);
        return empty($reflector->getFileName()) && !$isBuiltInEnum($className);
    }
);

$enums = array_filter(get_declared_classes(), $isBuiltInEnum);

$interfaces = array_filter(
    get_declared_interfaces(),
    function (string $interfaceName): bool {
        $reflector = new \ReflectionClass($interfaceName);
        return empty($reflector->getFileName());
    }
);

$traits = array_filter(
    get_declared_traits(),
    function (string $traitName): bool {
        $reflector = new \ReflectionClass($traitName);
        return empty($reflector->getFileName());
    }
);

$functions = array_filter(
    get_defined_functions()['internal'],
    function ($functionName): bool {
        $reflector = new \ReflectionFunction($functionName);
        return empty($reflector->getFileName());
    }
);


$found = [
    'classes' => $classes,
    'interfaces' => $interfaces,
    'traits' => $traits,
    'enums' => $enums,
    'functions' => $functions,
];
unset($classes, $interfaces, $traits, $enums, $functions);

// Symbol names are case-insensitive in PHP but Strauss's built-in lookup is exact, so use the spelling reported
// by reflection on this PHP version. E.g. pre-release PHP 8.4 used `DOM\Attr` where the release uses `Dom\Attr`;
// rename stale spellings everywhere so they are not recorded again as new symbols.
foreach ($symbolTypes as $symbolType) {
    $canonicalByLowercase = array_combine(array_map('strtolower', $found[$symbolType]), $found[$symbolType]);
    foreach ($builtins as $phpVersion => $builtinsArray) {
        $builtins[$phpVersion][$symbolType] = array_unique(array_map(
            fn(string $symbol): string => $canonicalByLowercase[strtolower($symbol)] ?? $symbol,
            $builtinsArray[$symbolType]
        ));
    }
}

$diffCaseInsensitive = fn(array $symbols, array $remove): array => array_udiff($symbols, $remove, 'strcasecmp');

// Enums were previously recorded under `classes`; move them out wherever they appear.
foreach ($builtins as $phpVersion => $builtinsArray) {
    $builtins[$phpVersion]['classes'] = $diffCaseInsensitive($builtinsArray['classes'], $found['enums']);
}

// Remove symbols that are built-in in this PHP version from future versions.
foreach ($builtins as $phpVersion => $builtinsArray) {
    if (version_compare($phpVersion, $currentPhpVersion, '>')) {
        foreach ($symbolTypes as $symbolType) {
            $builtins[$phpVersion][$symbolType] = $diffCaseInsensitive($builtinsArray[$symbolType], $found[$symbolType]);
        }
    }
}

// Remove from this PHP version's list symbols that already exist in older versions.
foreach ($builtins as $phpVersion => $builtinsArray) {
    if (version_compare($phpVersion, $currentPhpVersion, '<')) {
        foreach ($symbolTypes as $symbolType) {
            $found[$symbolType] = $diffCaseInsensitive($found[$symbolType], $builtinsArray[$symbolType]);
        }
    }
}

foreach ($symbolTypes as $symbolType) {
    $builtins[$currentPhpVersion][$symbolType] = array_unique(array_merge($builtins[$currentPhpVersion][$symbolType], $found[$symbolType]));
}

// Fixed key order and sorted values for every version.
foreach ($builtins as $phpVersion => $builtinsArray) {
    $ordered = [];
    foreach ($symbolTypes as $symbolType) {
        $values = $builtinsArray[$symbolType];
        asort($values);
        $ordered[$symbolType] = $values;
    }
    $builtins[$phpVersion] = $ordered;
}
uksort($builtins, 'version_compare');


/**
 * Print in the same layout as `src/Pipeline/FileSymbol/builtinsymbols.php` so the diff there is minimal.
 *
 * @param array<string, array<string, string[]>> $builtins
 */
$export = function (array $builtins): string {
    $indent = fn(int $depth): string => str_repeat('    ', $depth);
    $lines = ['<?php', 'return array ('];
    foreach ($builtins as $phpVersion => $builtinsArray) {
        $lines[] = $indent(1) . var_export((string) $phpVersion, true) . ' =>';
        $lines[] = $indent(2) . 'array (';
        foreach ($builtinsArray as $symbolType => $symbols) {
            $lines[] = $indent(3) . var_export($symbolType, true) . ' =>';
            $lines[] = $indent(4) . 'array (';
            foreach ($symbols as $symbol) {
                $lines[] = $indent(5) . var_export($symbol, true) . ',';
            }
            $lines[] = $indent(4) . '),';
        }
        $lines[] = $indent(2) . '),';
    }
    $lines[] = ');';
    return implode(PHP_EOL, $lines) . PHP_EOL;
};

file_put_contents($outputFile, $export($builtins));
