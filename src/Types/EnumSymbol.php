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
     * PHP >= 8.1 — making the original name a true alias of the renamed enum: case identity (`===`), `instanceof`,
     * `match` arms, and `::from()` all behave identically.
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
