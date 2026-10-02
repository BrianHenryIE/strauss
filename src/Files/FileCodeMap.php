<?php
/**
 * Everything Strauss knows about one PHP file after parsing it once: the symbols it defines (by type), every
 * usage of a symbol (by type), and every comment, string and inline-HTML region that may contain symbol names
 * as text.
 *
 * Built by {@see \BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer} from {@see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner}
 * and stored on the {@see File}. The file is never parsed again: {@see \BrianHenryIE\Strauss\Pipeline\ChangePlanner}
 * decides the edits from the map and the {@see \BrianHenryIE\Strauss\Pipeline\Prefixer} applies them.
 *
 * A value object: it describes one exact contents string ({@see self::matchesContent()}) and is never modified.
 * When the Prefixer changes the contents it builds a new map for the result.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Files;

use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;

class FileCodeMap
{
    /** @var CodeLocation[] Sorted by start position. */
    protected array $locations;

    /**
     * The symbols the file defines, in declaration order.
     *
     * @var SymbolDefinition[]
     */
    protected array $definitions;

    /**
     * Namespace names declared in the file, in source order, unique. `\` for the global namespace.
     * A file with no namespace declaration reports the global namespace.
     *
     * @var string[]
     */
    protected array $namespaceNames;

    /**
     * Checksum of the contents the map's positions refer to.
     */
    protected int $contentsCrc32;

    /**
     * @param CodeLocation[] $locations
     * @param SymbolDefinition[] $definitions
     * @param string[] $namespaceNames
     */
    public function __construct(array $locations, string $contents, array $definitions = [], array $namespaceNames = ['\\'])
    {
        usort($locations, fn(CodeLocation $a, CodeLocation $b) => $a->getStart() <=> $b->getStart());
        $this->locations = $locations;
        $this->definitions = array_values($definitions);
        $this->namespaceNames = empty($namespaceNames) ? ['\\'] : array_values($namespaceNames);
        $this->contentsCrc32 = crc32($contents);
    }

    /**
     * @return CodeLocation[]
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @return CodeLocation[]
     */
    public function getLocationsOfType(string ...$types): array
    {
        $typesLookup = array_fill_keys($types, true);
        return array_values(array_filter(
            $this->locations,
            fn(CodeLocation $location) => isset($typesLookup[$location->getType()])
        ));
    }

    /**
     * The symbols this file defines, optionally of one {@see SymbolDefinition::TYPES} type.
     *
     * @return SymbolDefinition[]
     */
    public function getDefinitions(?string $type = null): array
    {
        if (is_null($type)) {
            return $this->definitions;
        }
        return array_values(array_filter(
            $this->definitions,
            fn(SymbolDefinition $definition) => $type === $definition->getType()
        ));
    }

    /**
     * The symbols this file defines, grouped by {@see SymbolDefinition::TYPES} type.
     *
     * @return array<string, SymbolDefinition[]>
     */
    public function getDefinitionsByType(): array
    {
        $byType = array_fill_keys(SymbolDefinition::TYPES, []);
        foreach ($this->definitions as $definition) {
            $byType[$definition->getType()][] = $definition;
        }
        return $byType;
    }

    /**
     * Every use of a symbol name in this file, optionally of one {@see CodeLocation::USAGE_TYPES} type.
     *
     * @return CodeLocation[]
     */
    public function getUsages(?string $type = null): array
    {
        return is_null($type)
            ? $this->getLocationsOfType(...CodeLocation::USAGE_TYPES)
            : $this->getLocationsOfType($type);
    }

    /**
     * Every use of a symbol name in this file, grouped by {@see CodeLocation::USAGE_TYPES} type.
     *
     * @return array<string, CodeLocation[]>
     */
    public function getUsagesByType(): array
    {
        $byType = array_fill_keys(CodeLocation::USAGE_TYPES, []);
        foreach ($this->locations as $location) {
            if ($location->isUsage()) {
                $byType[$location->getType()][] = $location;
            }
        }
        return $byType;
    }

    /**
     * The distinct names used in the file (as php-parser normalizes them, no leading `\`), across every usage type.
     *
     * @return string[]
     */
    public function getUsedNames(): array
    {
        $names = [];
        foreach ($this->getUsages() as $usage) {
            $names[$usage->getName()] = true;
        }
        return array_keys($names);
    }

    /**
     * Comments, strings and inline HTML: text that may contain symbol names.
     *
     * @return CodeLocation[]
     */
    public function getRegions(): array
    {
        return $this->getLocationsOfType(...CodeLocation::REGION_TYPES);
    }

    /**
     * @return string[]
     */
    public function getNamespaceNames(): array
    {
        return $this->namespaceNames;
    }

    /**
     * Do the given contents match the contents this map's positions were calculated for?
     */
    public function matchesContent(string $contents): bool
    {
        return crc32($contents) === $this->contentsCrc32;
    }
}
