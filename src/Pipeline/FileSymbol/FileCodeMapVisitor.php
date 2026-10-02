<?php
/**
 * php-parser visitor which, in a single traversal, records the location of every symbol definition, symbol usage,
 * comment, string and inline HTML region in a file.
 *
 * Run after {@see \PhpParser\NodeVisitor\NameResolver} (with `replaceNodes` disabled) so `resolvedName` attributes
 * are available.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\CodeLocation;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Const_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

class FileCodeMapVisitor extends NodeVisitorAbstract
{
    /**
     * PHP functions whose first argument is a string naming a function.
     */
    public const FUNCTIONS_USING_CALLABLE = [
        'function_exists' => true,
        'call_user_func' => true,
        'call_user_func_array' => true,
        'forward_static_call' => true,
        'forward_static_call_array' => true,
        'register_shutdown_function' => true,
        'register_tick_function' => true,
        'unregister_tick_function' => true,
    ];

    /**
     * PHP functions whose first argument is a string naming a constant.
     */
    public const FUNCTIONS_USING_CONSTANT_NAME = [
        'define' => true,
        'defined' => true,
    ];

    protected string $contents;

    /** @var CodeLocation[] */
    protected array $locations = [];

    /** @var SymbolDefinition[] In declaration order. */
    protected array $definitions = [];

    /** @var array<string,bool> Namespace names in declaration order (`\` for global). */
    protected array $namespaceNames = [];

    /** @var ?string Current namespace, null when global. */
    protected ?string $currentNamespace = null;

    /** @var array<int,bool> spl_object_id of Name nodes already recorded with a specific role. */
    protected array $claimedNames = [];

    /**
     * Every comment token in the file, ascending by position. Taken from the tokens rather than from the nodes'
     * attached comments, which omit comments not followed by a node (e.g. after the last argument or array item).
     *
     * @var Token[]
     */
    protected array $commentTokens = [];

    /** Index in {@see self::$commentTokens} of the next comment to record. */
    protected int $nextCommentToken = 0;

    /**
     * @param Token[] $tokens The tokens of the contents: {@see \PhpParser\Parser::getTokens()}.
     */
    public function __construct(string $contents, array $tokens = [])
    {
        $this->contents = $contents;
        $this->commentTokens = array_values(array_filter(
            $tokens,
            fn(Token $token): bool => $token->is([T_COMMENT, T_DOC_COMMENT])
        ));
    }

    /**
     * @return CodeLocation[]
     */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /**
     * @return SymbolDefinition[]
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return string[]
     */
    public function getNamespaceNames(): array
    {
        return array_keys($this->namespaceNames);
    }

    public function enterNode(Node $node)
    {
        // Before a namespace node changes the current namespace, so each comment is recorded in the namespace it
        // is written in.
        $this->addCommentLocationsBefore($node->getStartFilePos());

        if ($node instanceof Namespace_) {
            $this->enterNamespace($node);
            return null;
        }

        if ($node instanceof Use_) {
            foreach ($node->uses as $item) {
                $this->addUseItemLocation($item, $node->type, false);
            }
            return null;
        }

        if ($node instanceof GroupUse) {
            $this->claimName($node->prefix);
            $location = $this->addNameLocation(CodeLocation::GROUP_USE_PREFIX, $node->prefix);
            $location->setUseType($node->type);
            foreach ($node->uses as $item) {
                $this->addUseItemLocation($item, $node->type, true);
            }
            return null;
        }

        if ($node instanceof ConstFetch) {
            $this->claimName($node->name);
            $this->addNameLocation(CodeLocation::CONST_FETCH, $node->name);
            return null;
        }

        if ($node instanceof FuncCall) {
            $this->enterFuncCall($node);
            return null;
        }

        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
            $this->enterClassLike($node);
            return null;
        }

        if ($node instanceof Function_) {
            $this->enterFunction($node);
            return null;
        }

        if ($node instanceof Const_) {
            $this->enterConst($node);
            return null;
        }

        if ($node instanceof Name) {
            if (!isset($this->claimedNames[spl_object_id($node)])) {
                $this->addNameLocation(CodeLocation::NAME, $node);
            }
            return null;
        }

        if ($node instanceof String_ || $node instanceof InterpolatedString || $node instanceof ShellExec) {
            $this->locations[] = new CodeLocation(
                CodeLocation::STRING,
                $node->getStartFilePos(),
                $this->getNodeText($node),
                $this->currentNamespace
            );
            return null;
        }

        if ($node instanceof InlineHTML) {
            $this->locations[] = new CodeLocation(
                CodeLocation::INLINE_HTML,
                $node->getStartFilePos(),
                $this->getNodeText($node),
                $this->currentNamespace
            );
            return null;
        }

        return null;
    }

    /**
     * Comments after the last node.
     */
    public function afterTraverse(array $nodes)
    {
        $this->addCommentLocationsBefore(PHP_INT_MAX);
        return null;
    }

    protected function addCommentLocationsBefore(int $position): void
    {
        while (isset($this->commentTokens[$this->nextCommentToken])
            && $this->commentTokens[$this->nextCommentToken]->pos < $position
        ) {
            $token = $this->commentTokens[$this->nextCommentToken++];
            $this->locations[] = new CodeLocation(
                $token->is(T_DOC_COMMENT) ? CodeLocation::DOC_COMMENT : CodeLocation::COMMENT,
                $token->pos,
                $token->text,
                $this->currentNamespace
            );
        }
    }

    protected function enterNamespace(Namespace_ $node): void
    {
        $this->currentNamespace = $node->name ? $node->name->toString() : null;
        $namespaceName = $this->currentNamespace ?? '\\';

        $location = null;
        if ($node->name) {
            $this->claimName($node->name);
            $location = $this->addNameLocation(CodeLocation::NAMESPACE_DEFINITION, $node->name);
        }

        if (!isset($this->namespaceNames[$namespaceName])) {
            $this->namespaceNames[$namespaceName] = true;
            $this->definitions[] = new SymbolDefinition(
                SymbolDefinition::TYPE_NAMESPACE,
                $namespaceName,
                $namespaceName,
                $location
            );
        }
    }

    protected function enterFuncCall(FuncCall $node): void
    {
        if (!($node->name instanceof Name)) {
            // E.g. a variable or expression being called.
            return;
        }

        $this->claimName($node->name);
        $this->addNameLocation(CodeLocation::FUNCTION_CALL, $node->name);

        $functionName = strtolower($node->name->toString());

        $firstArg = $node->args[0] ?? null;
        if (!($firstArg instanceof Node\Arg) || !($firstArg->value instanceof String_)) {
            return;
        }
        $stringNode = $firstArg->value;

        if (isset(self::FUNCTIONS_USING_CALLABLE[$functionName])) {
            $this->addStringArgLocation(CodeLocation::CALLABLE_STRING_ARG, $stringNode);
        }

        if (isset(self::FUNCTIONS_USING_CONSTANT_NAME[$functionName])) {
            $location = $this->addStringArgLocation(CodeLocation::DEFINE_STRING_ARG, $stringNode);

            if ('define' === $functionName && '' !== trim($stringNode->value, '\\')) {
                // NB: `define()` always defines a global constant, but Strauss has always recorded it under the
                // current namespace, e.g. `My\Namespace\MY_CONSTANT`.
                $this->definitions[] = new SymbolDefinition(
                    SymbolDefinition::TYPE_CONSTANT,
                    $stringNode->value,
                    $this->currentNamespace ?? '\\',
                    $location
                );
            }
        }
    }

    /**
     * @param Class_|Interface_|Trait_|Enum_ $node
     */
    protected function enterClassLike(Node $node): void
    {
        if (is_null($node->name)) {
            // Anonymous class.
            return;
        }

        $nameLocation = new CodeLocation(
            $this->getDefinitionLocationType($node),
            $node->name->getStartFilePos(),
            $node->name->name,
            $this->currentNamespace
        );
        $this->locations[] = $nameLocation;

        $definition = new SymbolDefinition(
            $this->getDefinitionSymbolType($node),
            $this->qualify($node->name->name),
            $this->currentNamespace ?? '\\',
            $nameLocation
        );

        if ($node instanceof Class_) {
            $definition->setIsAbstract($node->isAbstract());
            if ($node->extends) {
                $definition->setExtends($this->resolveName($node->extends));
            }
            $definition->setInterfaces(array_map([$this, 'resolveName'], $node->implements));
        } elseif ($node instanceof Interface_) {
            // For interfaces, the "interfaces" are the parent interfaces it extends.
            $definition->setInterfaces(array_map([$this, 'resolveName'], $node->extends));
        } elseif ($node instanceof Enum_) {
            $definition->setBackingType($node->scalarType instanceof Node\Identifier ? $node->scalarType->name : null);
            $definition->setInterfaces(array_map([$this, 'resolveName'], $node->implements));
        }

        $this->definitions[] = $definition;
    }

    protected function enterFunction(Function_ $node): void
    {
        $nameLocation = new CodeLocation(
            CodeLocation::FUNCTION_DEFINITION,
            $node->name->getStartFilePos(),
            $node->name->name,
            $this->currentNamespace
        );
        $this->locations[] = $nameLocation;

        $this->definitions[] = new SymbolDefinition(
            SymbolDefinition::TYPE_FUNCTION,
            $this->qualify($node->name->name),
            $this->currentNamespace ?? '\\',
            $nameLocation
        );
    }

    /**
     * `const NAME = ...;` (class constants are `ClassConst`, not `Const_`).
     */
    protected function enterConst(Const_ $node): void
    {
        foreach ($node->consts as $const) {
            $nameLocation = new CodeLocation(
                CodeLocation::CONST_DEFINITION,
                $const->name->getStartFilePos(),
                $const->name->name,
                $this->currentNamespace
            );
            $this->locations[] = $nameLocation;

            $this->definitions[] = new SymbolDefinition(
                SymbolDefinition::TYPE_CONSTANT,
                $const->name->name,
                $this->currentNamespace ?? '\\',
                $nameLocation
            );
        }
    }

    protected function addUseItemLocation(UseItem $item, int $stmtUseType, bool $inGroupUse): void
    {
        $this->claimName($item->name);
        $location = $this->addNameLocation(CodeLocation::USE_NAME, $item->name);
        // The item's own type wins over the statement's, e.g. `use Prefix\{function foo}`.
        $location->setUseType(Use_::TYPE_UNKNOWN !== $item->type ? $item->type : $stmtUseType);
        $location->setInGroupUse($inGroupUse);
        $location->setHasAlias(!is_null($item->alias));
        if (!$inGroupUse) {
            // An import is already fully qualified.
            $location->setResolvedName($item->name->toString());
        }
    }

    protected function addNameLocation(string $type, Name $name): CodeLocation
    {
        $location = new CodeLocation(
            $type,
            $name->getStartFilePos(),
            $this->getNodeText($name),
            $this->currentNamespace
        );
        $location->setMatchName($name->toString());
        // NB: from the node type, not the text: `use \Bar\Baz;` is written with a leading `\` but parses as a
        // plain Name.
        $location->setIsFullyQualifiedName($name instanceof FullyQualified);

        $resolvedName = $name->getAttribute('resolvedName');
        if ($resolvedName instanceof Name) {
            $location->setResolvedName($resolvedName->toString());
        } elseif ($name instanceof FullyQualified) {
            $location->setResolvedName($name->toString());
        }

        $this->locations[] = $location;

        return $location;
    }

    protected function addStringArgLocation(string $type, String_ $stringNode): CodeLocation
    {
        // Exclude the quotes so a replacement cannot change them.
        $start = $stringNode->getStartFilePos() + 1;
        $length = $stringNode->getEndFilePos() - $stringNode->getStartFilePos() - 1;
        $location = new CodeLocation(
            $type,
            $start,
            substr($this->contents, $start, $length),
            $this->currentNamespace,
            $length
        );
        // The unescaped string value, for matching against symbol names.
        $location->setMatchName($stringNode->value);
        $this->locations[] = $location;

        return $location;
    }

    protected function claimName(Name $name): void
    {
        $this->claimedNames[spl_object_id($name)] = true;
    }

    protected function getNodeText(Node $node): string
    {
        return substr(
            $this->contents,
            $node->getStartFilePos(),
            $node->getEndFilePos() - $node->getStartFilePos() + 1
        );
    }

    protected function qualify(string $localName): string
    {
        return is_null($this->currentNamespace)
            ? $localName
            : $this->currentNamespace . '\\' . $localName;
    }

    /**
     * The fully qualified name, without leading `\`, of a class name in `extends` or `implements`.
     */
    protected function resolveName(Name $name): string
    {
        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $name->toString();
    }

    /**
     * @param Class_|Interface_|Trait_|Enum_ $node
     */
    protected function getDefinitionLocationType(Node $node): string
    {
        switch (true) {
            case $node instanceof Class_:
                return CodeLocation::CLASS_DEFINITION;
            case $node instanceof Interface_:
                return CodeLocation::INTERFACE_DEFINITION;
            case $node instanceof Trait_:
                return CodeLocation::TRAIT_DEFINITION;
            default:
                return CodeLocation::ENUM_DEFINITION;
        }
    }

    /**
     * @param Class_|Interface_|Trait_|Enum_ $node
     */
    protected function getDefinitionSymbolType(Node $node): string
    {
        switch (true) {
            case $node instanceof Class_:
                return SymbolDefinition::TYPE_CLASS;
            case $node instanceof Interface_:
                return SymbolDefinition::TYPE_INTERFACE;
            case $node instanceof Trait_:
                return SymbolDefinition::TYPE_TRAIT;
            default:
                return SymbolDefinition::TYPE_ENUM;
        }
    }
}
