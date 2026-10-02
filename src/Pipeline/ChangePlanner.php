<?php
/**
 * Determines every change to make to each file, before any file is modified.
 *
 * Works entirely from the file's {@see FileCodeMap} — the locations (and texts) of every symbol definition, usage,
 * comment and string recorded when {@see FileSymbolScanner} parsed the file — so planning needs neither the file
 * contents nor a parser. The result, a list of (start, end, replacement) edits, is stored on the file
 * ({@see File::setPlannedEdits()}) for {@see Prefixer} to apply.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\DeepDependenciesCollection;
use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Exception;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ChangePlanner
{
    use LoggerAwareTrait;

    /**
     * For each package: itself, every package in its dependency tree, and the packages it suggests with theirs.
     *
     * @var array<string, array<string, mixed>> Keyed by package name, then by package name.
     */
    protected array $availablePackageNames = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Plan the changes for every scanned PHP file.
     *
     * Files without a code map (non-PHP, unparseable, or never scanned) are skipped.
     *
     * @param iterable<FileBase> $files
     *
     * @throws Exception
     */
    public function planInFiles(DiscoveredSymbols $discoveredSymbols, iterable $files): void
    {
        foreach ($files as $file) {
            if (!($file instanceof File) || !$file->isPhpFile()) {
                continue;
            }
            $codeMap = $file->getCodeMap();
            if (is_null($codeMap)) {
                $this->logger->debug('No code map to plan changes against (unparseable or unscanned)::::{filePath}', [
                    'filePath' => $file->getSourcePath(),
                ]);
                continue;
            }
            $file->setPlannedEdits($this->plan($discoveredSymbols, $codeMap, $file));
        }
    }

    /**
     * Determine all changes for one file.
     *
     * @return array<array{start:int,end:int,replacement:string}> The planned edits, ascending by start.
     *
     * @throws Exception
     */
    public function plan(DiscoveredSymbols $discoveredSymbols, FileCodeMap $codeMap, ?FileBase $file = null): array
    {
        $fileAbsolutePath = is_null($file) ? null : $file->getTargetAbsolutePath();

        $functionsToRename = $discoveredSymbols->getDiscoveredFunctions()->getToRename();

        $positions = array_merge(
            $this->findUseStatementPositionsForNamespacedClasses($codeMap, $discoveredSymbols),
            $this->findNamespacePositions($codeMap, $discoveredSymbols, $file),
            $this->findFunctionPositions($codeMap, $functionsToRename),
            $this->findDocCommentPositions($codeMap, $discoveredSymbols),
            $this->findPositionsOfUsesOfNamespacedConstants($discoveredSymbols, $codeMap),
            $this->findGlobalSymbolsPositions($codeMap, $discoveredSymbols),
            // Last, so its `use const` replacement wins over the aliased one from findGlobalSymbolsPositions().
            $this->findConstantPositions($codeMap, $discoveredSymbols),
            // Symbols inside strings, e.g. `is_a( $recurrence, 'CronExpression' )`.
            $this->findSymbolsPositionsInStrings($codeMap, $discoveredSymbols, $file)
        );

        // Descending by start; PHP's sort is stable, so for equal starts the later-found position comes later and
        // wins in the dedupe below.
        usort($positions, fn($a, $b) => $b['start'] <=> $a['start']);

        $edits = [];
        foreach ($positions as $position) {
            $edits[$position['start']] = $position;
        }

        // Overlapping positions are a bug in a finder – there is no valid case for them.
        $cursor = PHP_INT_MAX;
        foreach ($edits as $edit) {
            if ($edit['end'] > $cursor) {
                throw new Exception(sprintf(
                    'Overlapping replacement in %s at %d-%d ("%s") overlaps a replacement starting at %d.',
                    $fileAbsolutePath ?? 'file',
                    $edit['start'],
                    $edit['end'],
                    $edit['replacement'],
                    $cursor
                ));
            }
            $cursor = $edit['start'];
        }

        return array_reverse(array_values($edits));
    }

    /**
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findPositionsOfUsesOfNamespacedConstants(DiscoveredSymbols $symbols, FileCodeMap $codeMap): array
    {
        $namespaceSymbols = $symbols->getNamespaces();
        if (count($namespaceSymbols) === 0) {
            return [];
        }

        $positions = [];

        foreach ($codeMap->getLocationsOfType(CodeLocation::CONST_FETCH) as $fetch) {
            if (!$fetch->isFullyQualified()) {
                continue;
            }
            $parts = $fetch->getNameParts();
            $local = array_pop($parts);
            if (empty($parts)) {
                // not namespaced;
                continue;
            }
            $namespaceName = implode('\\', $parts);
            $namespace = $namespaceSymbols->get($namespaceName);

            if ($namespace) {
                $replacementNamespace = $namespace->getLocalReplacement();
                $newName = '\\' . $replacementNamespace . '\\' . $local;

                $positions[] = [
                    'start' => $fetch->getStart(),
                    'end' => $fetch->getEnd(),
                    'replacement' => $newName,
                ];
            }
        }

        return $positions;
    }

    /**
     * Replace class/interface/trait `use` statements driven by registered ClassSymbols.
     *
     * A namespace is "active" when at least one ClassSymbol is registered within it.
     * For exact-match ClassSymbols the symbol's own replacement is used; for other classes
     * in the namespace, namespace-prefix replacement is applied.
     * Namespaces with no registered ClassSymbol are left alone.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findUseStatementPositionsForNamespacedClasses(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
    {
        $activeNamespaces = [];
        /** @var NamespacedSymbol $symbol */
        foreach ($discoveredSymbols->getNamespacedSymbols()->getToRename()->notGlobal()->toArray() as $symbol) {
            $ns = $symbol->getNamespace();
            $original = rtrim($ns->getOriginalFqdnName(), '\\');
            $replacement = rtrim($ns->getReplacementFqdnName(), '\\');
            $activeNamespaces[$original] = $replacement;
        }

        if (empty($activeNamespaces)) {
            return [];
        }

        uksort($activeNamespaces, fn($a, $b) => strlen($b) - strlen($a));

        $positions = [];

        $namespacedSymbols = $discoveredSymbols->getNamespacedSymbols()->getToRename()->notGlobal();
        foreach ($codeMap->getLocationsOfType(CodeLocation::USE_NAME) as $item) {
            $nameStr = $item->getName();
            // Full match.
            if ($namespacedSymbols->get($nameStr)) {
                $positions[] = [
                    'start'       => $item->getStart(),
                    'end'         => $item->getEnd(),
                    'replacement' => $namespacedSymbols->get($nameStr)->getReplacementFqdnName(),
                ];
            } else { // Partial match (group)
                foreach ($activeNamespaces as $original => $replacement) {
                    if (str_starts_with($nameStr, $original . '\\')) {
                        /** @var ?NamespacedSymbol $classSymbol */
                        $classSymbol = $namespacedSymbols->get($nameStr);
                        if ($classSymbol && $classSymbol->isDoRename()) {
                            $nsReplacement = rtrim($classSymbol->getNamespace()->getReplacementFqdnName(), '\\');
                            $newName       = $nsReplacement . '\\' . $classSymbol->getLocalReplacement();
                        } else {
                            $newName = $replacement . substr($nameStr, strlen($original));
                        }

                        $positions[] = [
                            'start'       => $item->getStart(),
                            'end'         => $item->getEnd(),
                            'replacement' => $newName,
                        ];
                    }
                }
            }
        }

        return $positions;
    }

    protected function checkPregError(): void
    {
        $matchingError = preg_last_error();
        if (0 !== $matchingError) {
            throw new Exception(preg_last_error_msg());
        }
    }

    /**
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findNamespacePositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols, ?FileBase $file): array
    {
        $namespaces = $discoveredSymbols->getNamespaces();
        // Only symbols actually being renamed: a symbol whose replacement is its original name would
        // produce no-op positions that overwrite real replacements for the same range in the dedupe.
        $namespacedChanges = $discoveredSymbols->getNamespacedSymbols()->getToRename()->notGlobal();
        if (count($namespaces->getToRename()) === 0) {
            return [];
        }

        $isDoPrefix = is_null($file) || $file->isDoPrefix();
        // When the file's own symbols are not being prefixed, references to them within the file are left alone.
        $unprefixedFileSymbols = $isDoPrefix ? null : $file->getDiscoveredSymbols();

        /** @var NamespaceSymbol[] $symbolMap indexed by exact original symbol (no trailing \) */
        $symbolMap = [];
        foreach ($namespaces->getToRename() as $symbol) {
            if (isset($symbolMap[rtrim($symbol->getOriginalFqdnName(), '\\')])) {
                throw new Exception('losing data');
            }
            $symbolMap[rtrim($symbol->getOriginalFqdnName(), '\\')] = $symbol;
        }
        uksort($symbolMap, fn($a, $b) => strlen($b) - strlen($a));

        $positions = [];
        $handled = [];

        /**
         * Prefix lookup for qualified names like Aws\SomeClass or Aws\boolean_value():
         * walks from the longest prefix down to length-1, doing exact key lookups.
         * Returns ['symbol' => DiscoveredSymbol, 'suffix' => 'remaining\parts'] or null.
         *
         * @param string[] $parts
         */
        $findPrefixSymbol = function (array $parts) use ($symbolMap): ?array {
            for ($len = count($parts) - 1; $len >= 1; $len--) {
                $prefix = implode('\\', array_slice($parts, 0, $len));

                if (isset($symbolMap[$prefix])) {
                    return [
                        'symbol' => $symbolMap[$prefix],
                        'suffix' => implode('\\', array_slice($parts, $len)),
                    ];
                }
            }
            return null;
        };

        // A: namespace declarations — keep relative (no leading \)
        foreach ($codeMap->getLocationsOfType(CodeLocation::NAMESPACE_DEFINITION) as $ns) {
            if (!$isDoPrefix) {
                $handled[$ns->getStart()] = true;
                continue;
            }
            $nameStr = $ns->getName();

            if (isset($symbolMap[$nameStr])) {
                $namespaceSymbol = $symbolMap[$nameStr];
                $positions[] = [
                    'start' => $ns->getStart(),
                    'end' => $ns->getEnd(),
                    'replacement' => $namespaceSymbol->getReplacementFqdnName(),
                ];
                $handled[$ns->getStart()] = true;
                // A class-like symbol may share the namespace's fqdn (e.g. class `PhpParser\Node\Name` and
                // namespace `PhpParser\Node\Name`); without this, the lookup below could add a second
                // position for the same range which would overwrite this one in the last-wins dedupe.
                continue;
            }

            if ($symbol = $namespacedChanges->get($nameStr)) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($ns->getNameParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $ns->getStart(),
                'end' => $ns->getEnd(),
                'replacement' => $replacement,
            ];
            $handled[$ns->getStart()] = true;
        }

        // B: use items.
        // Class/interface/trait use items are always marked as handled to prevent section D from
        // prepending '\'; their replacement is produced by findUseStatementPositionsForNamespacedClasses.
        // Function and constant use items keep namespace-prefix replacement here.
        foreach ($codeMap->getLocationsOfType(CodeLocation::USE_NAME) as $item) {
            if ($item->isInGroupUse()) {
                continue;
            }
            $nameStr = $item->getName();
            // Always mark use item names as handled so section D never adds a spurious '\' prefix.
            $handled[$item->getStart()] = true;
            if ($item->getUseType() !== CodeLocation::USE_TYPE_NORMAL) {
                // TYPE_FUNCTION / TYPE_CONSTANT: replace directly here.
                if ($symbol = $discoveredSymbols->get($nameStr)) {
                    $replacement = $symbol->getReplacementFqdnName();
                } elseif ($match = $findPrefixSymbol($item->getNameParts())) {
                    // groups
                    $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
                } else {
                    continue;
                }
                $positions[] = [
                    'start' => $item->getStart(),
                    'end' => $item->getEnd(),
                    'replacement' => $replacement,
                ];
            } elseif (isset($symbolMap[$nameStr])) {
                // TYPE_NORMAL with an exact namespace match: findUseStatementPositionsForNamespacedClasses
                // only handles class/trait/interface/enum symbols, so handle pure namespace use items here.
                $positions[] = [
                    'start' => $item->getStart(),
                    'end' => $item->getEnd(),
                    'replacement' => $symbolMap[$nameStr]->getReplacementFqdnName(),
                ];
            }
        }
        // It would be necessary to split `use My\Namespace\{Class1, Class2};` into individual lines if one of
        // those classes is excluded and one should be updated.
        foreach ($codeMap->getLocationsOfType(CodeLocation::GROUP_USE_PREFIX) as $groupUse) {
            $nameStr = $groupUse->getName();
            if ($symbol = $discoveredSymbols->get($nameStr)) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($groupUse->getNameParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $groupUse->getStart(),
                'end' => $groupUse->getEnd(),
                'replacement' => $replacement,
            ];
            $handled[$groupUse->getStart()] = true;
        }

        $names = $codeMap->getLocationsOfType(...CodeLocation::NAME_TYPES);

        // C: fully-qualified names — retain leading \
        foreach ($names as $name) {
            if (!$name->isFullyQualified() || isset($handled[$name->getStart()])) {
                continue;
            }
            if ($symbol = $namespacedChanges->get($name->getName())) {
                $replacement = $symbol->getReplacementFqdnName();
            } elseif ($match = $findPrefixSymbol($name->getNameParts())) {
                $replacement = rtrim($match['symbol']->getReplacementFqdnName(), '\\') . '\\' . $match['suffix'];
            } else {
                continue;
            }
            $positions[] = [
                'start' => $name->getStart(),
                'end' => $name->getEnd(),
                'replacement' => '\\' . $replacement,
            ];
            $handled[$name->getStart()] = true;
        }

        // D: relative qualified names (e.g. Aws\boolean_value, Aws\SomeClass) — promote to FQ.
        // Uses part-by-part prefix lookup so only full namespace-segment boundaries are matched.
        foreach ($names as $name) {
            if ($name->isFullyQualified() || $name->countNameParts() < 2 || isset($handled[$name->getStart()])) {
                continue;
            }

            if (isset($symbolMap[$name->getName()])) {
                $namespaceSymbol = $symbolMap[$name->getName()];
                $positions[] = [
                    'start' => $name->getStart(),
                    'end' => $name->getEnd(),
                    'replacement' => $namespaceSymbol->getReplacementFqdnName(),
                ];
                continue;
            }

            $match = $findPrefixSymbol($name->getNameParts());
            if (!$match) {
                continue;
            }
            $namespaceSymbol = $match['symbol'];

            if ($unprefixedFileSymbols && $unprefixedFileSymbols->has($namespaceSymbol)) {
                continue;
            }

            $positions[] = [
                'start' => $name->getStart(),
                'end' => $name->getEnd(),
                'replacement' => '\\' . $namespaceSymbol->getReplacementFqdnName() . '\\' . $match['suffix'],
            ];
        }

        return $positions;
    }

    /**
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findGlobalSymbolsPositionsInComment(CodeLocation $comment, DiscoveredSymbols $globalSymbols): array
    {
        $positions = [];
        foreach ($globalSymbols->getGlobalClassesInterfacesTraitsToRename() as $discoveredSymbol) {
            $positions = array_merge($positions, $this->findGlobalSymbolPositionInComment($comment, $discoveredSymbol));
        }
        return $positions;
    }

    /**
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findGlobalSymbolPositionInComment(CodeLocation $comment, DiscoveredSymbol $globalSymbol): array
    {
        $positions = [];

        $searchStr = '\\' . $globalSymbol->getOriginalFqdnName();
        $searchLen = strlen($searchStr);

        $commentText = $comment->getText();
        $startFilePos = $comment->getStart();
        $offset = 0;
        while (($pos = strpos($commentText, $searchStr, $offset)) !== false) {
            $nextPos = $pos + $searchLen;
            if ($nextPos >= strlen($commentText)
                || !preg_match('/[a-zA-Z0-9_\x7f-\xff\\\\]/', $commentText[$nextPos])
            ) {
                $positions[] = [
                    'start' => $startFilePos + $pos,
                    'end' => $startFilePos + $nextPos,
                    'replacement' => '\\' . $globalSymbol->getLocalReplacement(),
                ];
            }
            $offset = $pos + 1;
        }

        return $positions;
    }

    /**
     * Find symbols inside strings, e.g. `is_a( $recurrence, 'CronExpression' )`, for every namespace and
     * namespaced symbol marked for renaming which has not opted out of string replacement.
     *
     * The search runs over the recorded string, comment and inline HTML regions rather than the whole file. The
     * regions are joined into one buffer so each symbol's pattern is run once per file, as before.
     *
     * A file in a package can only be referring to symbols of that package, of the packages it requires
     * (transitively) and of the packages it suggests, so symbols from every other package are not searched for: {@see self::filterToSymbolsAvailableToFile()}.
     *
     * TODO: optionally filter to only namespaces of more than a single depth.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findSymbolsPositionsInStrings(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols, ?FileBase $file): array
    {
        $fileAbsolutePath = is_null($file) ? null : $file->getTargetAbsolutePath();

        $discoveredSymbolsCount = count($discoveredSymbols->toArray());
        $this->logger->debug(sprintf(
            'Searching in {filename} for {count} symbol%s as string',
            $discoveredSymbolsCount === 1 ? '' : 's'
        ), [
            'filename' => basename($fileAbsolutePath ?? 'file'),
            'count' => $discoveredSymbolsCount,
        ]);

        /** @var DiscoveredSymbol[] $symbols */
        $symbols = array_merge(
            $discoveredSymbols->getNamespaces()->getToRename()->toArray(),
            $discoveredSymbols->getNamespacedSymbols()->getToRename()->toArray()
        );
        $symbols = array_filter($symbols, fn(DiscoveredSymbol $symbol): bool => $symbol->isReplaceInString());
        $symbols = $this->filterToSymbolsAvailableToFile($symbols, $file);

        if (empty($symbols)) {
            return [];
        }

        $regions = $codeMap->getRegions();

        if (empty($regions)) {
            return [];
        }

        // One buffer of every region, each surrounded by its own space, so the pattern's boundary characters match
        // at the edges of a region the way they matched the code around a string in the whole file. The pattern
        // consumes the boundary character after a match, so adjacent regions need a space each.
        $bufferParts = [];
        /** @var int[] $bufferStarts Offset of each region's text in the buffer. */
        $bufferStarts = [];
        $offset = 0;
        foreach ($regions as $region) {
            $bufferParts[] = ' ';
            $offset++;
            $bufferStarts[] = $offset;
            $bufferParts[] = $region->getText();
            $bufferParts[] = ' ';
            $offset += $region->getLength() + 1;
        }
        $buffer = implode('', $bufferParts);

        /**
         * The region a buffer offset falls in, by binary search.
         */
        $regionIndexAt = function (int $bufferOffset) use ($bufferStarts): int {
            $low = 0;
            $high = count($bufferStarts) - 1;
            while ($low < $high) {
                $mid = intdiv($low + $high + 1, 2);
                if ($bufferStarts[$mid] <= $bufferOffset) {
                    $low = $mid;
                } else {
                    $high = $mid - 1;
                }
            }
            return $low;
        };

        $positions = [];

        foreach ($symbols as $symbol) {
            foreach ($this->findSymbolPositionsInStrings($buffer, $symbol) as $position) {
                $index = $regionIndexAt($position['start']);
                $region = $regions[$index];
                $shift = $region->getStart() - $bufferStarts[$index];
                $position['start'] += $shift;
                $position['end'] += $shift;
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * Remove the symbols the file cannot be referring to: those only defined in packages which the file's package
     * neither requires, directly or transitively, nor suggests.
     *
     * Nothing is removed for files that are not part of a package (project files, Composer's autoload files), and
     * symbols whose package is unknown are always kept.
     *
     * @param DiscoveredSymbol[] $symbols
     *
     * @return DiscoveredSymbol[]
     */
    protected function filterToSymbolsAvailableToFile(array $symbols, ?FileBase $file): array
    {
        if (!($file instanceof FileWithDependency)) {
            return $symbols;
        }

        $availablePackageNames = $this->getAvailablePackageNames($file->getDependency());

        return array_filter(
            $symbols,
            function (DiscoveredSymbol $symbol) use ($availablePackageNames): bool {
                $symbolPackages = $symbol->getDependencies()->toArray();
                return empty($symbolPackages)
                    || !empty(array_intersect_key($symbolPackages, $availablePackageNames));
            }
        );
    }

    /**
     * The package itself, every package in its dependency tree, and the packages it suggests with theirs.
     *
     * @return array<string, mixed> Keyed by package name.
     */
    protected function getAvailablePackageNames(ComposerPackage $package): array
    {
        $packageName = $package->getPackageName();

        if (!isset($this->availablePackageNames[$packageName])) {
            $this->availablePackageNames[$packageName] = [$packageName => true]
                + $package->getFlatDependencyTree()->toArray()
                // Optional integrations, e.g. `class_exists('Monolog\\Logger')`.
                + (new DeepDependenciesCollection($package->getSuggestedPackages()))->toArray();
        }

        return $this->availablePackageNames[$packageName];
    }

    /**
     * Find a single symbol inside quoted strings, e.g. `is_a( $recurrence, 'CronExpression' )`,
     * `"My\\Namespace\\" . $var` and `'My\Namespace\Classname::$staticProperty'`.
     *
     * @param string $contents The joined text of the file's string, comment and inline HTML regions, never the whole
     *                         file: {@see self::findSymbolsPositionsInStrings()}.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     * @throws Exception
     */
    protected function findSymbolPositionsInStrings(string $contents, DiscoveredSymbol $symbol): array
    {
        $positions = [];

        $alsoSearchForVariableClassname = false;
        $alsoSearchForStaticProperty = false;

        if ($symbol instanceof NamespacedSymbol && $symbol->getNamespace()->isGlobal()) {
            $replacementSymbolString = $symbol->getLocalReplacement();
            $originalSymbolString    = $symbol->getOriginalLocalName();
        } elseif ($symbol instanceof NamespaceSymbol) {
            if ($symbol->isGlobal()) {
                return [];
            }
            $originalSymbolString = $symbol->getOriginalFqdnName();
            $replacementSymbolString = $symbol->getReplacementFqdnName();

            // E.g. `My\Namespace\$var` is used in some libraries.
            $alsoSearchForVariableClassname = true;

            $positions = $this->findComposerClassLoaderPrefixPositions($contents, $originalSymbolString, $replacementSymbolString);
        } elseif ($symbol instanceof NamespacedSymbol) {
            $originalSymbolString = $symbol->getOriginalFqdnName();
            $replacementSymbolString = $symbol->getReplacementFqdnName();
            $alsoSearchForStaticProperty = true;
        } else {
            throw new Exception('I dont think we can reach here');
        }

        // A symbol can only match if its first name segment appears verbatim (segments contain no backslashes,
        // so the pattern's `\\`-doubling cannot disguise it). This cheap check skips most of the regex calls.
        $firstNameSegment = strstr($originalSymbolString, '\\', true) ?: $originalSymbolString;
        if (false === strpos($contents, $firstNameSegment)) {
            return $positions;
        }

        /**
         * `[^a-zA-Z0-9_\x7f-\xff\\\\]+` is anything but classname valid characters.
         *
         * TODO: Run this without the classname characters, log everytime a replacement is made across all test cases, add those to the test assertions, ensure this is always correct.
         */
        $pattern =    '/
(
                            [^a-zA-Z0-9_\x7f-\xff\\\\]
                            [\'"]
                            [\\\\]{0,2}
)
                        ('
                            . str_replace('\\', '[\\\\]{1,2}', $originalSymbolString) .
                        ')(
                        '
                      // This only applies to namespaces, `"My\\Namespace\\" . $var`.
                      // The trailing-backslashes-only alternative matches namespace prefix strings compared
                      // against FQDNs, e.g. `substr($className, 0, 16) === 'PHP_CodeSniffer\\'`.
                      . ( $alsoSearchForVariableClassname ? '([\\\\]{1,2}\$[a-zA-Z0-9_\x7f-\xff]*|[\\\\]{1,2})?' : '' ) .
                      ( $alsoSearchForStaticProperty ? '(:{2}\$[a-zA-Z0-9_\x7f-\xff]*)?' : '' ) .
                      '
                            [\'"]
                            [^a-zA-Z0-9_\x7f-\xff\\\\]
)
                        /Ux';       // U: Non-greedy matching, x: ignore whitespace in pattern.

        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
        $this->checkPregError();

        $escapedOriginalSymbolString = str_replace('\\', '\\\\', $originalSymbolString);
        $escapedReplacementSymbolString = str_replace('\\', '\\\\', $replacementSymbolString);

        foreach ($matches[2] as [$symbolString, $offset]) {
            if ($symbolString === $originalSymbolString) {
                $replacement = $replacementSymbolString;
            } elseif ($symbolString === $escapedOriginalSymbolString) {
                $replacement = $escapedReplacementSymbolString;
            } else {
                // Mixed single/double backslashes – leave as is.
                continue;
            }

            $positions[] = [
                'start' => $offset,
                'end' => $offset + strlen($symbolString),
                'replacement' => $replacement,
            ];
        }

        return $positions;
    }

    /**
     * Handle special case with null character `\0` in Composer's AutoloadGenerator.
     *
     * `$prefix = "\0Composer\Autoload\ClassLoader\0";` must become
     * `$prefix = "\0Project\Prefix\Composer\Autoload\ClassLoader\0";`.
     *
     * Only the exact unprefixed class name is matched (an already prefixed line is left alone), and the returned
     * position covers only the class name, so it starts at the same offset as any doc comment match for the same
     * text and is deduplicated rather than overlapping it. This matters when Strauss prefixes its own source.
     *
     * TODO: I'm worried that dump-autoload when running via `.phar` will include `BrianHenryIE\Strauss` prefix. I don't think I have addressed that issue here.
     * I.e. in strauss.phar, AutoloadGenerator should have `$prefix = "\0BRianHenryIE\Strauss\Composer\Autoload\ClassLoader\0";`
     * but in vendor-prefixed/composer/... of a prefixed project, it should be `$prefix = "\0Project\Prefix\Composer\Autoload\ClassLoader\0";`
     *
     * @see \Composer\Autoload\AutoloadGenerator
     * @see vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php
     * @see strauss.phar/src/Pipeline/Prefixer.php
     * @see strauss.phar/vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findComposerClassLoaderPrefixPositions(string $contents, string $originalNamespace, string $replacementNamespace): array
    {
        // Must be concatenated so it is not unintentionally replaced!
        $unprefixedNamespace = implode('\\', ['Comp'.'oser','Autoload']);
        $isComposerAutoloadNamespace = str_ends_with($originalNamespace, $unprefixedNamespace);
        $hasComposerAutoloadNamespace = str_contains($contents, $unprefixedNamespace);
        if (!$isComposerAutoloadNamespace || !$hasComposerAutoloadNamespace) {
            return [];
        }

        $disguisedNamespaceString = implode("\\\\", ['Comp'.'oser','Autoload','ClassLoader']);

        // The string literal `"\0Composer\Autoload\ClassLoader\0"`.
        $pattern = "/\\\"\\\\0($disguisedNamespaceString)\\\\0\\\"/";

        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
        $this->checkPregError();

        $positions = [];
        foreach ($matches[1] as [$classnameString, $classnameOffset]) {
            $positions[] = [
                'start' => $classnameOffset,
                'end' => $classnameOffset + strlen($classnameString),
                'replacement' => $replacementNamespace . '\\ClassLoader',
            ];
        }

        return $positions;
    }

    /**
     * In a namespace:
     * * use \Classname;
     * * new \Classname()
     *
     * In a global namespace:
     * * new Classname()
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findGlobalSymbolsPositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
    {
        $globalClassesInterfacesTraitsToRename = $discoveredSymbols->getGlobalClassesInterfacesTraits()->getToRename();

        if (count($globalClassesInterfacesTraitsToRename) === 0) {
            return [];
        }

        $positions = [];

        foreach ($codeMap->getLocationsOfType(CodeLocation::COMMENT, CodeLocation::DOC_COMMENT) as $comment) {
            $positions = array_merge(
                $positions,
                $this->findGlobalSymbolsPositionsInComment($comment, $discoveredSymbols)
            );
        }

        $names = $codeMap->getLocationsOfType(...CodeLocation::NAME_TYPES);

        // Replace \Classname (fully qualified) references in any namespace context.
        foreach ($names as $name) {
            if (!$name->isFullyQualified() || !$this->getGlobalSymbolByName($name->getName(), $discoveredSymbols)) {
                continue;
            }
            $positions[] = [
                'start' => $name->getStart(),
                'end' => $name->getEnd(),
                'replacement' => '\\' . $discoveredSymbols->getNamespacedSymbols()->get($name->getName())->getReplacementFqdnName(),
            ];
        }

        // In named namespaces, `use Classname;` must become `use PrefixedClassname as Classname;`
        // so that unqualified references within the namespace continue to resolve correctly.
        foreach ($codeMap->getLocationsOfType(CodeLocation::USE_NAME) as $useItem) {
            if ($useItem->isInGlobalNamespace() || $useItem->isFullyQualified()) {
                continue;
            }
            $fqdn_name = $useItem->getName();
            $discoveredSymbol = $globalClassesInterfacesTraitsToRename->get($fqdn_name);
            if ($discoveredSymbol && $discoveredSymbol->isDoRename()) {
                $replacementClassname = $discoveredSymbol->getLocalReplacement();
                $useClassname = array_reverse(explode('\\', $fqdn_name))[0];

                $replacementString = $discoveredSymbol->getLocalReplacement();
                if ($replacementClassname !== $useClassname && !$useItem->hasAlias()) {
                    $replacementString .= ' as ' . $useClassname;
                }

                $positions[] = [
                    'start' => $useItem->getStart(),
                    'end' => $useItem->getEnd(),
                    'replacement' => $replacementString,
                ];
            }
        }

        // In global namespace context (either implicit, or explicit `namespace {}`), replace
        // class/interface/trait/enum declarations and unqualified class name references.
        foreach ($codeMap->getLocationsOfType(
            CodeLocation::CLASS_DEFINITION,
            CodeLocation::INTERFACE_DEFINITION,
            CodeLocation::TRAIT_DEFINITION,
            CodeLocation::ENUM_DEFINITION
        ) as $definition) {
            if (!$definition->isInGlobalNamespace()) {
                continue;
            }
            $globalSymbol = $this->getGlobalSymbolByName($definition->getText(), $globalClassesInterfacesTraitsToRename);
            if (!$globalSymbol) {
                continue;
            }
            $positions[] = [
                'start' => $definition->getStart(),
                'end' => $definition->getEnd(),
                'replacement' => $globalSymbol->getLocalReplacement(),
            ];
        }

        foreach ($names as $name) {
            if (!$name->isInGlobalNamespace() || $name->isFullyQualified()) {
                continue;
            }
            $globalSymbol = $this->getGlobalSymbolByName($name->getName(), $globalClassesInterfacesTraitsToRename);
            if (!$globalSymbol) {
                continue;
            }
            $positions[] = [
                'start' => $name->getStart(),
                'end' => $name->getEnd(),
                'replacement' => $globalSymbol->getReplacementFqdnName(),
            ];
        }

        return $positions;
    }

    /**
     * Return whichever of class/interface/trait/enum is found first by name, or null.
     *
     * There should just be one of any global name: `use MyABC;` (a class) is indistinguishable from `use MyABC;` (an interface).
     */
    protected function getGlobalSymbolByName(string $name, DiscoveredSymbols $discoveredSymbols): ?DiscoveredSymbol
    {
        return $discoveredSymbols->getClass($name)
            ?? $discoveredSymbols->getInterface($name)
            ?? $discoveredSymbols->getTrait($name)
            ?? $discoveredSymbols->getEnum($name);
    }

    /**
     * Find global constants to rename.
     *
     * Matches constant fetches (`MY_CONST`, `\MY_CONST`), `use const MY_CONST;`, top-level `const MY_CONST = 1;`
     * declarations, and the string argument to `define('MY_CONST', ...)` and `defined('MY_CONST')`.
     *
     * Class constants (`self::MY_CONST`) and namespaced constant fetches are not matched here.
     *
     * @see self::findPositionsOfUsesOfNamespacedConstants()
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findConstantPositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
    {
        /** @var array<string,string> $constantsToRename original name => replacement name */
        $constantsToRename = [];
        /** @var ConstantSymbol $constant */
        foreach ($discoveredSymbols->getConstants()->getToRename() as $constant) {
            $constantsToRename[$constant->getOriginalFqdnName()] = $constant->getReplacementFqdnName();
        }

        if (empty($constantsToRename)) {
            return [];
        }

        $positions = [];

        foreach ($codeMap->getLocationsOfType(
            CodeLocation::CONST_FETCH,
            CodeLocation::USE_NAME,
            CodeLocation::CONST_DEFINITION,
            CodeLocation::DEFINE_STRING_ARG
        ) as $location) {
            switch ($location->getType()) {
                case CodeLocation::CONST_FETCH:
                    $name = $location->getName();
                    if ((!$location->isFullyQualified() || 1 === $location->countNameParts())
                        && isset($constantsToRename[$name])
                    ) {
                        $positions[] = [
                            'start' => $location->getStart(),
                            'end' => $location->getEnd(),
                            'replacement' => $location->isFullyQualified()
                                ? '\\' . $constantsToRename[$name]
                                : $constantsToRename[$name],
                        ];
                    }
                    break;
                case CodeLocation::USE_NAME:
                    if (CodeLocation::USE_TYPE_CONSTANT !== $location->getUseType()) {
                        break;
                    }
                    $name = $location->getName();
                    if (isset($constantsToRename[$name])) {
                        $positions[] = [
                            'start' => $location->getStart(),
                            'end' => $location->getEnd(),
                            'replacement' => $constantsToRename[$name],
                        ];
                    }
                    break;
                case CodeLocation::CONST_DEFINITION:
                    $name = $location->getText();
                    if (isset($constantsToRename[$name])) {
                        $positions[] = [
                            'start' => $location->getStart(),
                            'end' => $location->getEnd(),
                            'replacement' => $constantsToRename[$name],
                        ];
                    }
                    break;
                case CodeLocation::DEFINE_STRING_ARG:
                    $name = $location->getName();
                    if (isset($constantsToRename[$name])) {
                        // Inside the quotes.
                        $positions[] = [
                            'start' => $location->getStart(),
                            'end' => $location->getEnd(),
                            'replacement' => $constantsToRename[$name],
                        ];
                    }
                    break;
            }
        }

        return $positions;
    }

    /**
     * Find references to a function in a doc comment: `value()`, `\value()`, `@see value`, `@uses value`, or `value` in backticks.
     *
     * Bare words (`the default value`), variables (`$value`) and method calls (`->value()`, `::value()`) are ignored.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findFunctionPositionsInDocComment(CodeLocation $doc, FunctionSymbol $symbol): array
    {
        $positions = [];

        $name = preg_quote($symbol->getOriginalFqdnName(), '/');
        $replacement = $symbol->getReplacementFqdnName();

        // The optional leading backslash is included in the match so the position starts at the same offset as
        // the one from findGlobalSymbolsPositionsInComment() for `\value` and is deduplicated rather than overlapping.
        $patterns = [
            // `value()` – not preceded by an identifier character, namespace separator, `$`, `->` or `::`.
            '/(?<![a-zA-Z0-9_\x7f-\xff\\\\$>:])\\\\?' . $name . '(?=\s*\()/',
            // `@see value` / `@uses value` – followed by a non-identifier character.
            '/@(?:see|uses)\s+\K\\\\?' . $name . '(?![a-zA-Z0-9_\x7f-\xff\\\\])/',
            // Inline code: `value` or `\value`.
            '/`\K\\\\?' . $name . '(?=`)/',
        ];

        $text = $doc->getText();

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            $this->checkPregError();
            foreach ($matches[0] as [$match, $offset]) {
                $positions[] = [
                    'start' => $doc->getStart() + $offset,
                    'end' => $doc->getStart() + $offset + strlen($match),
                    'replacement' => str_starts_with($match, '\\') ? '\\' . $replacement : $replacement,
                ];
            }
        }

        return $positions;
    }

    /**
     * Look for declared functions, function calls, and built-in functions that accept a function as their parameter.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findFunctionPositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
    {
        if (count($discoveredSymbols) === 0) {
            return [];
        }

        $positions = [];

        foreach ($codeMap->getLocationsOfType(
            CodeLocation::FUNCTION_DEFINITION,
            CodeLocation::FUNCTION_CALL,
            CodeLocation::CALLABLE_STRING_ARG
        ) as $location) {
            switch ($location->getType()) {
                case CodeLocation::FUNCTION_DEFINITION:
                    // Function declarations (global only): the local name is looked up.
                    $functionSymbol = $discoveredSymbols->getFunction($location->getText());
                    if ($functionSymbol && $functionSymbol->isDoRename()) {
                        $positions[] = [
                            'start'       => $location->getStart(),
                            'end'         => $location->getEnd(),
                            'replacement' => $functionSymbol->getReplacementFqdnName(),
                        ];
                    }
                    break;
                case CodeLocation::FUNCTION_CALL:
                    $functionSymbol = $discoveredSymbols->getFunction($location->getName());
                    if ($functionSymbol) {
                        if (str_contains($location->getName(), '\\')) {
                            $replacement = '\\' . $functionSymbol->getReplacementFqdnName();
                        } else {
                            $replacement = $functionSymbol->getLocalReplacement();
                        }
                        $positions[] = [
                            'start'       => $location->getStart(),
                            'end'         => $location->getEnd(),
                            'replacement' => $replacement,
                        ];
                    }
                    break;
                case CodeLocation::CALLABLE_STRING_ARG:
                    // A built-in function that accepts a function name as its argument, e.g. `function_exists('foo')`.
                    $functionSymbol = $discoveredSymbols->getFunction($location->getName());
                    if ($functionSymbol) {
                        $positions[] = [
                            'start'       => $location->getStart(), // do not change quotes
                            'end'         => $location->getEnd(),
                            'replacement' => $functionSymbol->getReplacementFqdnName(),
                        ];
                    }
                    break;
            }
        }

        return $positions;
    }

    /**
     * Doc comments: scan for \OriginalNamespace references in @param/@return/etc.
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    protected function findDocCommentPositions(FileCodeMap $codeMap, DiscoveredSymbols $discoveredSymbols): array
    {
        $positions = [];

        $docComments = $codeMap->getLocationsOfType(CodeLocation::DOC_COMMENT);

        if (count($docComments) === 0) {
            return [];
        }

        $namespacedSymbols = $discoveredSymbols->getNamespacedSymbols()->getToRename();

        $this->logger->debug('Searching {number_of_comments} comments for {number_of_symbols} symbols', [
            'number_of_comments' => count($docComments),
            'number_of_symbols' => count($namespacedSymbols),
        ]);

        foreach ($docComments as $doc) {
            $text = $doc->getText();
            /** @var NamespacedSymbol $symbol */
            foreach ($namespacedSymbols as $symbol) {
                $replacement = $symbol->getReplacementFqdnName();

                // Function names can be common words (e.g. `value`, `when`), so only replace them when
                // they are clearly a reference to the function: `value()` or `@see value`.
                if ($symbol instanceof FunctionSymbol) {
                    $positions = array_merge(
                        $positions,
                        $this->findFunctionPositionsInDocComment($doc, $symbol)
                    );
                    continue;
                }
                $docSearchStr = $symbol->getOriginalFqdnName();
                $docSearchLen = strlen($docSearchStr);
                // For global symbols (no \ in name), also treat \ as a boundary character
                // so \GlobalClass is left to findGlobalSymbolPositionInComment.
                $beforePattern = strpos($docSearchStr, '\\') === false
                    ? '/[a-zA-Z0-9_\x7f-\xff\\\\]/'
                    : '/[a-zA-Z0-9_\x7f-\xff]/';
                $offset = 0;
                while (($pos = strpos($text, $docSearchStr, $offset)) !== false) {
                    $after = $pos + $docSearchLen;
                    $beforeOk = $pos === 0 || !preg_match($beforePattern, $text[$pos - 1]);
                    $afterOk  = $after >= strlen($text) || !preg_match('/[a-zA-Z0-9_\x7f-\xff]/', $text[$after]);
                    if ($beforeOk && $afterOk) {
                        $positions[] = [
                            'start' => $doc->getStart() + $pos,
                            'end' => $doc->getStart() + $after,
                            'replacement' => $replacement,
                        ];
                    }
                    $offset = $pos + 1;
                }
            }
        }

        return $positions;
    }
}
