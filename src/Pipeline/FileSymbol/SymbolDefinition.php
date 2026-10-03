<?php
/**
 * A symbol (namespace, class, interface, trait, enum, function, constant) defined in a PHP file, as found by
 * {@see PhpFileAnalyzer}. What the file "contains".
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\CodeLocation;

class SymbolDefinition
{
    public const TYPE_NAMESPACE = 'namespace';
    public const TYPE_CLASS = 'class';
    public const TYPE_INTERFACE = 'interface';
    public const TYPE_TRAIT = 'trait';
    public const TYPE_ENUM = 'enum';
    public const TYPE_FUNCTION = 'function';
    public const TYPE_CONSTANT = 'constant';

    public const TYPES = [
        self::TYPE_NAMESPACE,
        self::TYPE_CLASS,
        self::TYPE_INTERFACE,
        self::TYPE_TRAIT,
        self::TYPE_ENUM,
        self::TYPE_FUNCTION,
        self::TYPE_CONSTANT,
    ];

    protected string $type;

    /**
     * The fully qualified name, without leading slash. For a namespace, the namespace name (`\` for global).
     * For a constant, the name as written in the declaration (unqualified), as {@see \BrianHenryIE\Strauss\Types\ConstantSymbol}
     * expects it.
     */
    protected string $name;

    /**
     * The namespace the symbol is declared in. `\` for the global namespace.
     */
    protected string $namespace;

    /**
     * The name token in the declaration. Null for a namespace with no name (the global namespace).
     */
    protected ?CodeLocation $location;

    protected bool $isAbstract = false;

    /**
     * FQDN of the parent class, without leading slash. Classes only.
     */
    protected ?string $extends = null;

    /**
     * FQDNs of implemented interfaces (classes, enums) or extended interfaces (interfaces), without leading slashes.
     *
     * @var string[]
     */
    protected array $interfaces = [];

    /**
     * The enum's backing type, 'string' or 'int'; null for pure enums. Enums only.
     */
    protected ?string $backingType = null;

    public function __construct(
        string $type,
        string $name,
        string $namespace,
        ?CodeLocation $location = null
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->namespace = $namespace;
        $this->location = $location;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function isInGlobalNamespace(): bool
    {
        return '\\' === $this->namespace;
    }

    public function getLocation(): ?CodeLocation
    {
        return $this->location;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    public function setIsAbstract(bool $isAbstract): void
    {
        $this->isAbstract = $isAbstract;
    }

    public function getExtends(): ?string
    {
        return $this->extends;
    }

    public function setExtends(?string $extends): void
    {
        $this->extends = $extends;
    }

    /**
     * @return string[]
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /**
     * @param string[] $interfaces
     */
    public function setInterfaces(array $interfaces): void
    {
        $this->interfaces = $interfaces;
    }

    public function getBackingType(): ?string
    {
        return $this->backingType;
    }

    public function setBackingType(?string $backingType): void
    {
        $this->backingType = $backingType;
    }
}
