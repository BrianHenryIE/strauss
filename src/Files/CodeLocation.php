<?php
/**
 * A range of text in a PHP file: a symbol definition, a symbol usage, or a region (comment, string, inline HTML)
 * that may contain symbol names as text.
 *
 * Positions are byte offsets into the file contents the {@see FileCodeMap} was built from.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Files;

class CodeLocation
{
    /**
     * `use` statement types. Values match {@see \PhpParser\Node\Stmt\Use_::TYPE_NORMAL} etc. so consumers
     * do not need php-parser.
     */
    public const USE_TYPE_NORMAL = 1;
    public const USE_TYPE_FUNCTION = 2;
    public const USE_TYPE_CONSTANT = 3;

    // Symbol definitions. The location is the name token in the declaration.
    public const NAMESPACE_DEFINITION = 'namespace_definition';
    public const CLASS_DEFINITION = 'class_definition';
    public const INTERFACE_DEFINITION = 'interface_definition';
    public const TRAIT_DEFINITION = 'trait_definition';
    public const ENUM_DEFINITION = 'enum_definition';
    public const FUNCTION_DEFINITION = 'function_definition';
    public const CONST_DEFINITION = 'const_definition';

    // Symbol usages.
    public const USE_NAME = 'use_name'; // The name in a `use ...;` import statement (or a group use item).
    public const GROUP_USE_PREFIX = 'group_use_prefix'; // The prefix in `use Prefix\{A, B};`.
    public const NAME = 'name'; // Any other name: type declarations, extends, instanceof, `new`, static calls, attributes...
    public const CONST_FETCH = 'const_fetch'; // The name in a constant fetch expression.
    public const FUNCTION_CALL = 'function_call'; // The name in a function call.
    public const CALLABLE_STRING_ARG = 'callable_string_arg'; // String argument to `call_user_func()` etc. Excludes quotes.
    public const DEFINE_STRING_ARG = 'define_string_arg'; // String argument to `define()`/`defined()`. Excludes quotes.

    // Regions that may contain symbol names as text.
    public const COMMENT = 'comment';
    public const DOC_COMMENT = 'doc_comment';
    public const STRING = 'string'; // A string literal, including its quotes/heredoc markers.
    public const INLINE_HTML = 'inline_html'; // Content outside the PHP open/close tags.

    public const DEFINITION_TYPES = [
        self::NAMESPACE_DEFINITION,
        self::CLASS_DEFINITION,
        self::INTERFACE_DEFINITION,
        self::TRAIT_DEFINITION,
        self::ENUM_DEFINITION,
        self::FUNCTION_DEFINITION,
        self::CONST_DEFINITION,
    ];

    public const USAGE_TYPES = [
        self::USE_NAME,
        self::GROUP_USE_PREFIX,
        self::NAME,
        self::CONST_FETCH,
        self::FUNCTION_CALL,
        self::CALLABLE_STRING_ARG,
        self::DEFINE_STRING_ARG,
    ];

    public const REGION_TYPES = [
        self::COMMENT,
        self::DOC_COMMENT,
        self::STRING,
        self::INLINE_HTML,
    ];

    /**
     * Location types whose text is a PHP name (`Foo\Bar`, `\Foo\Bar`, `Foo`).
     */
    public const NAME_TYPES = [
        self::NAMESPACE_DEFINITION,
        self::USE_NAME,
        self::GROUP_USE_PREFIX,
        self::NAME,
        self::CONST_FETCH,
        self::FUNCTION_CALL,
    ];

    protected string $type;

    protected int $start;

    protected int $length;

    /**
     * The text at the location, exactly as written in the file (for names, includes any leading `\`).
     */
    protected string $text;

    /**
     * The namespace the location is in. Null for the global namespace.
     */
    protected ?string $namespace;

    /**
     * The fully qualified name the text resolves to, when known. No leading slash.
     */
    protected ?string $resolvedName = null;

    /**
     * The name to match against discovered symbols, normalized the way php-parser's `Name::toString()`
     * writes it: no leading `\`, and no `namespace\` operator. For string arguments, the unescaped string value.
     * Null when the text itself (minus any leading `\`) is the name.
     */
    protected ?string $matchName = null;

    /**
     * Whether the parser treated the name as fully qualified.
     *
     * NB: this is not derivable from the text: `use \Bar\Baz;` is written with a leading `\` but is a plain name
     * to the parser (imports are inherently fully qualified). Null when unknown (non-name locations).
     */
    protected ?bool $isFullyQualifiedName = null;

    /**
     * {@see self::USE_TYPE_NORMAL} etc. of the `use` statement this location belongs to.
     */
    protected ?int $useType = null;

    protected bool $inGroupUse = false;

    /**
     * Whether the `use` item has an `as` alias.
     */
    protected bool $hasAlias = false;

    public function __construct(
        string $type,
        int $start,
        string $text,
        ?string $namespace = null,
        ?int $length = null
    ) {
        $this->type = $type;
        $this->start = $start;
        $this->text = $text;
        $this->namespace = $namespace;
        $this->length = $length ?? strlen($text);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isDefinition(): bool
    {
        return in_array($this->type, self::DEFINITION_TYPES, true);
    }

    public function isUsage(): bool
    {
        return in_array($this->type, self::USAGE_TYPES, true);
    }

    public function isRegion(): bool
    {
        return in_array($this->type, self::REGION_TYPES, true);
    }

    public function isNameType(): bool
    {
        return in_array($this->type, self::NAME_TYPES, true);
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setStart(int $start): void
    {
        $this->start = $start;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Exclusive end offset.
     */
    public function getEnd(): int
    {
        return $this->start + $this->length;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
        $this->length = strlen($text);
        // The normalized name and qualified-ness were derived from the old text's parse.
        $this->matchName = null;
        $this->isFullyQualifiedName = null;
    }

    public function isInGlobalNamespace(): bool
    {
        return is_null($this->namespace);
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getResolvedName(): ?string
    {
        return $this->resolvedName;
    }

    public function setResolvedName(?string $resolvedName): void
    {
        $this->resolvedName = $resolvedName;
    }

    public function getUseType(): ?int
    {
        return $this->useType;
    }

    public function setUseType(?int $useType): void
    {
        $this->useType = $useType;
    }

    public function isInGroupUse(): bool
    {
        return $this->inGroupUse;
    }

    public function setInGroupUse(bool $inGroupUse): void
    {
        $this->inGroupUse = $inGroupUse;
    }

    public function hasAlias(): bool
    {
        return $this->hasAlias;
    }

    public function setHasAlias(bool $hasAlias): void
    {
        $this->hasAlias = $hasAlias;
    }

    public function setIsFullyQualifiedName(?bool $isFullyQualifiedName): void
    {
        $this->isFullyQualifiedName = $isFullyQualifiedName;
    }

    /**
     * Is this a fully qualified name?
     */
    public function isFullyQualified(): bool
    {
        return $this->isFullyQualifiedName ?? str_starts_with($this->text, '\\');
    }

    public function setMatchName(?string $matchName): void
    {
        $this->matchName = $matchName;
    }

    /**
     * The name without any leading `\`.
     */
    public function getName(): string
    {
        return $this->matchName ?? ltrim($this->text, '\\');
    }

    /**
     * The `\`-separated parts of the name (ignoring any leading `\`).
     *
     * @return string[]
     */
    public function getNameParts(): array
    {
        return explode('\\', $this->getName());
    }

    /**
     * The number of `\`-separated parts in the name (ignoring any leading `\`).
     */
    public function countNameParts(): int
    {
        return substr_count($this->getName(), '\\') + 1;
    }
}
