<?php
/**
 * Parses a PHP file ONCE and produces everything Strauss needs to know about it as a {@see FileCodeMap}: the
 * symbols it defines, the namespaces it declares, and the location of every symbol usage, comment, string and
 * inline HTML region.
 *
 * This is the only place php-parser is used for symbol scanning and replacement. Downstream steps
 * ({@see \BrianHenryIE\Strauss\Pipeline\ChangePlanner}, {@see \BrianHenryIE\Strauss\Pipeline\Prefixer}) work from
 * the map and never parse.
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\FileSymbol;

use BrianHenryIE\Strauss\Files\CodeLocation;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PhpFileAnalyzer
{
    use LoggerAwareTrait;

    protected ?Parser $parser = null;

    /**
     * The parse error from the most recent {@see self::analyze()} call that returned null.
     */
    protected ?string $lastErrorMessage = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    /**
     * @param string $contents The file contents. May be a fragment without a `<?php` opener, or begin with HTML.
     * @param ?string $filePathForLogging
     *
     * @return ?FileCodeMap Null if the file could not be parsed (e.g. a `.blade.php` template).
     */
    public function analyze(string $contents, ?string $filePathForLogging = null): ?FileCodeMap
    {
        // Anything before the first `<?` is discarded before parsing: PHP (and php-parser) reject a `namespace`
        // declaration preceded by inline HTML, and that content has never been searched for symbols. A fragment
        // with no open tag at all is given one. Either way, the positions recorded are adjusted back to the
        // coordinates of the original contents below.
        $openTagPosition = strpos($contents, '<?');
        if (false === $openTagPosition) {
            $opener = "<?php\n";
            // The position in $contents of the first character of $parseContents: negative, the opener was prepended.
            $offset = -strlen($opener);
            $parseContents = $opener . $contents;
        } else {
            $offset = $openTagPosition;
            $parseContents = substr($contents, $openTagPosition);
        }

        $this->lastErrorMessage = null;

        try {
            $ast = $this->getParser()->parse($parseContents);
        } catch (Error $e) {
            // E.g. template files with placeholders: `namespace %g_namespace%\AdminMenus;`, or `x.blade.php`.
            $this->lastErrorMessage = $e->getMessage();
            $this->logger->warning('Failed to parse file {filePath} with error: {errorMessage}', [
                'filePath' => $filePathForLogging ?? 'string',
                'errorMessage' => $e->getMessage(),
            ]);
            return null;
        }

        if (is_null($ast)) {
            $this->lastErrorMessage = 'Parser returned no AST.';
            return null;
        }

        $visitor = new FileCodeMapVisitor($parseContents);

        $traverser = new NodeTraverser();
        /**
         * Sets `resolvedName` attributes on names (e.g. in `extends` and `implements`) without replacing the nodes,
         * so positions and texts are those of the source.
         */
        $traverser->addVisitor(new NameResolver(null, [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ]));
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $locations = $visitor->getLocations();

        if (0 !== $offset) {
            $locations = array_values(array_filter(
                array_map(
                    function (CodeLocation $location) use ($offset): CodeLocation {
                        $location->setStart($location->getStart() + $offset);
                        return $location;
                    },
                    $locations
                ),
                // Only the prepended opener itself can be at a negative position.
                fn(CodeLocation $location) => $location->getStart() >= 0
            ));
        }

        $namespaceNames = $visitor->getNamespaceNames();
        $definitions = $visitor->getDefinitions();

        if (empty($namespaceNames)) {
            // No `namespace` statement: everything is in the global namespace.
            $namespaceNames = ['\\'];
            array_unshift($definitions, new SymbolDefinition(SymbolDefinition::TYPE_NAMESPACE, '\\', '\\'));
        }

        return new FileCodeMap($locations, $contents, $definitions, $namespaceNames);
    }

    protected function getParser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }
}
