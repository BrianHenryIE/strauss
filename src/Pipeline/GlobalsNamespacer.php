<?php

/**
 * Chooses the namespaced names for global classes, interfaces, traits and enums, for `strauss namespace-globals`.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\ConstantSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\EnumSymbol;
use BrianHenryIE\Strauss\Types\FunctionSymbol;
use BrianHenryIE\Strauss\Types\InterfaceSymbol;
use BrianHenryIE\Strauss\Types\TraitSymbol;
use Psr\Log\LoggerInterface;

/**
 * `Plain` becomes `Company\Project\Plain`. With a prefix to strip, e.g. `HTMLPurifier_`, PEAR-style names have the
 * prefix removed and their `_` separated segments become namespace segments: `HTMLPurifier_AttrDef_CSS_Color` becomes
 * `Company\Project\AttrDef\CSS\Color`, and `HTMLPurifier` itself becomes `Company\Project\HTMLPurifier`.
 *
 * A short name PHP reserves, e.g. `List` or `Abstract`, is prepended with the segment before it, staying in the same
 * namespace as its siblings: `HTMLPurifier_HTMLModule_List` becomes `Company\Project\HTMLModule\HTMLModuleList`, and
 * `SLSWC_Extension_Abstract` becomes `Company\Project\Extension\ExtensionAbstract`. With no segment before it, the
 * library name is prepended, i.e. the stripped prefix without its `_` (or the last segment of the namespace):
 * `Acme_List` becomes `Company\Project\AcmeList`. Code which builds such a name at runtime, e.g.
 * `'HTMLPurifier_HTMLModule_' . 'List'`, must be updated by hand.
 *
 * Global functions and constants declared with `const` move with their file, keeping their names: into the namespace
 * of the file's classes, or, in a file without classes, into the namespace given. Constants declared with `define()`
 * are always global, so stay.
 *
 * A symbol is only moved when it is declared in one of the given paths, in a file which can gain a single
 * `namespace` declaration: no namespace already, and every class in it moving to the same namespace. Every other
 * symbol is marked not to rename. The edits are planned by {@see ChangePlanner}.
 */
class GlobalsNamespacer
{
    /**
     * Names PHP does not allow as a class name.
     *
     * @see https://www.php.net/manual/en/reserved.keywords.php
     * @see https://www.php.net/manual/en/reserved.other-reserved-words.php
     */
    public const RESERVED_NAMES = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'false', 'final', 'finally',
        'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace',
        'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'require_once', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    protected string $namespace;

    /**
     * @var string[]
     */
    protected array $stripPrefixes;

    /**
     * @var string[]
     */
    protected array $absolutePaths;

    protected LoggerInterface $logger;

    /**
     * @param string          $namespace     The namespace to move the symbols into, e.g. `Company\Project`.
     * @param string[]        $stripPrefixes Class name prefixes to remove, e.g. `HTMLPurifier_`; longest is tried first.
     * @param string[]        $absolutePaths Files and directories whose global symbols are moved.
     * @param LoggerInterface $logger        For the symbols moved and those which cannot be.
     */
    public function __construct(string $namespace, array $stripPrefixes, array $absolutePaths, LoggerInterface $logger)
    {
        $this->namespace = trim($namespace, '\\');

        $this->stripPrefixes = array_values(array_filter($stripPrefixes));
        usort($this->stripPrefixes, fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->absolutePaths = array_map(
            fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/'),
            $absolutePaths
        );

        $this->logger = $logger;
    }

    /**
     * Sets the replacement and marks for renaming each global class, interface, trait and enum which can move; marks
     * every other symbol not to rename.
     *
     * @param DiscoveredSymbols $discoveredSymbols The symbols found in the files.
     */
    public function determineReplacements(DiscoveredSymbols $discoveredSymbols): void
    {
        foreach ($discoveredSymbols->toArray() as $symbol) {
            $symbol->setDoRename(false);
        }

        /**
 * @var array<string, FileBase> $files
*/
        $files = [];
        /**
 * @var array<string, array<string, DiscoveredSymbol>> $symbolsByFile
*/
        $symbolsByFile = [];
        foreach ($discoveredSymbols->toArray() as $symbol) {
            if (
                !($this->isClassLike($symbol) || $symbol instanceof FunctionSymbol || $symbol instanceof ConstantSymbol)
                || !$symbol->isGlobal()
            ) {
                continue;
            }
            foreach ($symbol->getSourceFiles() as $file) {
                if (
                    $this->isInPaths($file->getSourcePath())
                    && (!($symbol instanceof ConstantSymbol) || $this->isDeclaredWithConst($file, $symbol->getOriginalFqdnName()))
                ) {
                    $files[$file->getSourcePath()] = $file;
                    $symbolsByFile[$file->getSourcePath()][$symbol->getOriginalFqdnName()] = $symbol;
                }
            }
        }

        foreach ($symbolsByFile as $filePath => $symbols) {
            $fileNamespace = $this->getFileNamespace($files[$filePath], $symbols);
            if (is_null($fileNamespace)) {
                continue;
            }
            foreach ($symbols as $symbol) {
                $replacement = $this->isClassLike($symbol)
                    ? $this->getReplacement($symbol->getOriginalFqdnName())
                    : $fileNamespace . '\\' . $symbol->getOriginalFqdnName();
                $symbol->setLocalReplacement($replacement);
                $symbol->setDoRename(true);
                $this->logger->info(
                    'Moving {original} to {replacement}.',
                    ['original' => $symbol->getOriginalFqdnName(), 'replacement' => $replacement]
                );
                if ($this->hasReservedShortName($symbol->getOriginalFqdnName())) {
                    $this->logger->warning(
                        '{original} is named {replacement} because its short name is reserved. Update any code which builds its name at runtime.',
                        ['original' => $symbol->getOriginalFqdnName(), 'replacement' => $replacement]
                    );
                }
            }
        }
    }

    /**
     * The namespaced name for a global class name.
     *
     * @param string $globalName E.g. `HTMLPurifier_AttrDef_CSS_Color`.
     *
     * @return string E.g. `Company\Project\AttrDef\CSS\Color`.
     */
    public function getReplacement(string $globalName): string
    {
        ['segments' => $segments, 'libraryName' => $libraryName] = $this->splitName($globalName);

        $shortName = (string) array_pop($segments);
        if ($this->hasReservedShortName($globalName)) {
            $shortName = (empty($segments) ? $libraryName : (string) end($segments)) . $shortName;
        }
        $segments[] = $shortName;

        return $this->namespace . '\\' . implode('\\', $segments);
    }

    /**
     * Would the short name be one PHP reserves, e.g. `List` from `HTMLPurifier_HTMLModule_List`?
     *
     * @param string $globalName The global class name.
     */
    protected function hasReservedShortName(string $globalName): bool
    {
        $segments = $this->splitName($globalName)['segments'];

        return in_array(strtolower((string) end($segments)), self::RESERVED_NAMES, true);
    }

    /**
     * @param string $globalName The global class name.
     *
     * @return array{segments:string[], libraryName:string} The namespace segments under {@see self::$namespace} and the
     *                                                    short name; and the name to prepend to a reserved short name:
     *                                                    the stripped prefix without its `_`, or the last namespace
     *                                                    segment.
     */
    protected function splitName(string $globalName): array
    {
        $namespaceSegments = explode('\\', $this->namespace);

        foreach ($this->stripPrefixes as $prefix) {
            if (rtrim($prefix, '_') === $globalName) {
                break;
            }
            if (0 === strpos($globalName, $prefix) && strlen($globalName) > strlen($prefix)) {
                return [
                    'segments' => array_values(
                        array_filter(
                            explode('_', substr($globalName, strlen($prefix))),
                            fn(string $segment): bool => '' !== $segment
                        )
                    ),
                    'libraryName' => rtrim($prefix, '_'),
                ];
            }
        }

        return [
            'segments' => [$globalName],
            'libraryName' => (string) end($namespaceSegments),
        ];
    }

    /**
     * The namespace the file's symbols move into, or null when they cannot move.
     *
     * @param FileBase                        $file    The file, with its code map.
     * @param array<string, DiscoveredSymbol> $symbols The global classes, interfaces, traits, enums, functions and
     *                                                 constants declared in the file.
     */
    protected function getFileNamespace(FileBase $file, array $symbols): ?string
    {
        $filePath = $file->getSourcePath();
        $codeMap = $file instanceof File ? $file->getCodeMap() : null;

        if (is_null($codeMap)) {
            $this->logger->warning('Not moving symbols in {filePath}: it could not be parsed.', ['filePath' => $filePath]);
            return null;
        }

        if ($codeMap->getNamespaceNames() !== ['\\']) {
            $this->logger->warning(
                'Not moving the global symbols in {filePath}: it also declares a namespace.',
                ['filePath' => $filePath]
            );
            return null;
        }

        // One unnamed block, `namespace { }`, is given the name; with more, each would need the same name.
        if (count($codeMap->getUnnamedNamespaceOffsets()) > 1) {
            $this->logger->warning(
                'Not moving the global symbols in {filePath}: it declares more than one global namespace block.',
                ['filePath' => $filePath]
            );
            return null;
        }

        $namespaces = [];
        foreach ($symbols as $symbol) {
            if (!$this->isClassLike($symbol)) {
                continue;
            }
            $replacement = $this->getReplacement($symbol->getOriginalFqdnName());
            $namespaces[substr($replacement, 0, (int) strrpos($replacement, '\\'))] = true;
        }

        if (count($namespaces) > 1) {
            $this->logger->warning(
                'Not moving the symbols in {filePath}: its classes would be in different namespaces: {namespaces}.',
                ['filePath' => $filePath, 'namespaces' => implode(', ', array_keys($namespaces))]
            );
            return null;
        }

        // A file without classes: the namespace given.
        return (string) (array_key_first($namespaces) ?? $this->namespace);
    }

    /**
     * Is the constant declared with `const` in the file, rather than `define()`, which always declares a global constant?
     *
     * @param FileBase $file The file, with its code map.
     * @param string   $name The constant's name.
     */
    protected function isDeclaredWithConst(FileBase $file, string $name): bool
    {
        $codeMap = $file instanceof File ? $file->getCodeMap() : null;
        if (is_null($codeMap)) {
            return false;
        }
        foreach ($codeMap->getLocationsOfType(CodeLocation::CONST_DEFINITION) as $definition) {
            if ($definition->getText() === $name) {
                return true;
            }
        }
        return false;
    }

    protected function isClassLike(DiscoveredSymbol $symbol): bool
    {
        return $symbol instanceof ClassSymbol
            || $symbol instanceof InterfaceSymbol
            || $symbol instanceof TraitSymbol
            || $symbol instanceof EnumSymbol;
    }

    /**
     * @param string $absolutePath A file's absolute path.
     */
    protected function isInPaths(string $absolutePath): bool
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        foreach ($this->absolutePaths as $path) {
            if ($absolutePath === $path || 0 === strpos($absolutePath, $path . '/')) {
                return true;
            }
        }
        return false;
    }
}
