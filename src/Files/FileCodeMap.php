<?php
/**
 * Everything Strauss knows about one PHP file after parsing it once: the symbols it defines (by type), every
 * usage of a symbol (by type), and every comment, string and inline-HTML region that may contain symbol names
 * as text.
 *
 * Built by {@see \BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer} from {@see \BrianHenryIE\Strauss\Pipeline\FileSymbolScanner}
 * and stored on the {@see File}. The file is never parsed again: {@see \BrianHenryIE\Strauss\Pipeline\ChangePlanner}
 * decides the edits from the map and stores them here ({@see self::setPlannedEdits()}); the
 * {@see \BrianHenryIE\Strauss\Pipeline\Prefixer} only applies them ({@see self::applyPlannedEdits()}), which also
 * updates the map to describe the new contents.
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
     * The pre-calculated changes to make to the file: position, end and replacement string.
     *
     * @var array<array{start:int,end:int,replacement:string}>
     */
    protected array $plannedEdits = [];

    /**
     * Distinguishes "planned, and there is nothing to change" from "not planned yet".
     */
    protected bool $isPlanned = false;

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

    /**
     * @param array<array{start:int,end:int,replacement:string}> $plannedEdits
     */
    public function setPlannedEdits(array $plannedEdits): void
    {
        $this->plannedEdits = array_values($plannedEdits);
        $this->isPlanned = true;
    }

    /**
     * @return array<array{start:int,end:int,replacement:string}>
     */
    public function getPlannedEdits(): array
    {
        return $this->plannedEdits;
    }

    public function isPlanned(): bool
    {
        return $this->isPlanned;
    }

    public function hasPlannedEdits(): bool
    {
        return !empty($this->plannedEdits);
    }

    /**
     * Apply the pre-calculated changes to the contents and update the map to describe the result.
     *
     * Consumes the plan: afterwards the map is unplanned (with up-to-date positions), ready for a later pipeline
     * phase to plan a new set of changes against it.
     *
     * @return string The updated contents.
     */
    public function applyPlannedEdits(string $contents): string
    {
        $edits = $this->plannedEdits;
        $this->plannedEdits = [];
        $this->isPlanned = false;

        return $this->applyEdits($contents, $edits);
    }

    /**
     * Sort edits ascending by start and drop overlaps, keeping the earliest-starting edit.
     *
     * @param array<array{start:int,end:int,replacement:string}> $edits
     *
     * @return array<array{start:int,end:int,replacement:string}>
     */
    public static function sortFilterEdits(array $edits): array
    {
        usort($edits, fn($a, $b) => $a['start'] <=> $b['start']);

        $previousEnd = -1;
        return array_values(array_filter(
            $edits,
            function (array $edit) use (&$previousEnd): bool {
                if ($edit['start'] < $previousEnd) {
                    return false;
                }
                $previousEnd = max($previousEnd, $edit['end']);
                return true;
            }
        ));
    }

    /**
     * Apply text replacements to the contents and update the map to match the result.
     *
     * Edits must not overlap one another. A location whose range exactly matches an edit gets the replacement as
     * its new text; edits strictly inside a location (e.g. a classname inside a comment) are spliced into its text;
     * a location partially overlapped by an edit can no longer be tracked and is dropped from the map.
     *
     * @param string $contents The contents the map currently describes.
     * @param array<array{start:int,end:int,replacement:string}> $edits
     *
     * @return string The updated contents.
     */
    public function applyEdits(string $contents, array $edits): string
    {
        if (empty($edits)) {
            return $contents;
        }

        $edits = self::sortFilterEdits($edits);

        // Rebuild the string in one pass rather than `substr_replace()` per edit, which is quadratic for large files.
        $parts = [];
        $cursor = 0;
        foreach ($edits as $edit) {
            $parts[] = substr($contents, $cursor, $edit['start'] - $cursor);
            $parts[] = $edit['replacement'];
            $cursor = $edit['end'];
        }
        $parts[] = substr($contents, $cursor);
        $contents = implode('', $parts);

        $updatedLocations = [];
        foreach ($this->locations as $location) {
            $shift = 0;
            $newText = $location->getText();
            $modified = false;
            $dropped = false;

            foreach ($edits as $edit) {
                $delta = strlen($edit['replacement']) - ($edit['end'] - $edit['start']);

                if ($edit['end'] <= $location->getStart()) {
                    // Entirely before: shift.
                    $shift += $delta;
                } elseif ($edit['start'] >= $location->getEnd()) {
                    // Entirely after: no effect.
                    continue;
                } elseif ($edit['start'] === $location->getStart() && $edit['end'] === $location->getEnd()) {
                    $newText = $edit['replacement'];
                    $modified = true;
                } elseif ($edit['start'] >= $location->getStart() && $edit['end'] <= $location->getEnd()) {
                    // Strictly inside: splice into the text. Edits are ascending, but any earlier inside-edits
                    // changed the text length, so adjust by the accumulated inner delta.
                    $innerOffset = $edit['start'] - $location->getStart() + (strlen($newText) - $location->getLength());
                    $newText = substr_replace(
                        $newText,
                        $edit['replacement'],
                        $innerOffset,
                        $edit['end'] - $edit['start']
                    );
                    $modified = true;
                } else {
                    // Partial overlap: position can no longer be tracked.
                    $dropped = true;
                    break;
                }
            }

            if ($dropped) {
                continue;
            }

            $location->setStart($location->getStart() + $shift);
            if ($modified) {
                $location->setText($newText);
                // The written text changed; what it now resolves to is not re-derived.
                $location->setResolvedName(null);
            }
            $updatedLocations[] = $location;
        }

        $this->locations = $updatedLocations;
        $this->contentsCrc32 = crc32($contents);

        return $contents;
    }
}
