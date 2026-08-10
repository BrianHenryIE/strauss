<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\FileBase;

/**
 * @phpstan-import-type EnumAliasArray from AutoloadAliasInterface
 */
class EnumSymbol extends NamespacedSymbol implements AutoloadAliasInterface
{
    /**
     * The enum's backing type, 'string' or 'int'; null for pure enums.
     */
    protected ?string $backingType;

    /**
     * @var string[]
     */
    protected array $interfaces;

    /**
     * @param string $fqdnEnumName
     * @param FileBase $sourceFile
     * @param NamespaceSymbol $namespace
     * @param ?string $backingType
     * @param string[] $interfaces
     */
    public function __construct(
        string $fqdnEnumName,
        FileBase $sourceFile,
        NamespaceSymbol $namespace,
        ?string $backingType = null,
        array $interfaces = []
    ) {
        parent::__construct($fqdnEnumName, $sourceFile, $namespace);

        $this->backingType = $backingType;
        $this->interfaces = $interfaces;
    }

    public function getBackingType(): ?string
    {
        return $this->backingType;
    }

    /**
     * @return string[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /**
     * Enums are final and cannot be extended, so the `class OriginalName extends NewName {}` approach used for
     * classes is impossible. Instead, `autoload_aliases.php` calls `class_alias()` — which works for enums on
     * PHP >= 8.1 — making the original name a true alias of the renamed enum: case identity (`===`),
     * `match` arms, `::cases()`, `::from()`, and `instanceof` the enum itself all behave identically.
     * Unlike the class shim, the alias cannot re-implement the enum's original interface names, so
     * `instanceof` against an original (aliased) interface name is false; only the renamed interface matches.
     *
     * @see AliasAutoloader::autoload()
     *
     * @return EnumAliasArray
     */
    public function getAutoloadAliasArray(): array
    {
        return array (
            'type' => 'enum',
            'enumname' => $this->getOriginalLocalName(),
            'namespace' => $this->namespace->getOriginalFqdnName(),
            'concrete' => $this->getReplacementFqdnName(),
        );
    }
}
