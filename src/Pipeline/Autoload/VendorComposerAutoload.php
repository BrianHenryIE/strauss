<?php

/**
 * Edit vendor/autoload.php to also load the vendor/composer/autoload_aliases.php file and the vendor-prefixed/autoload.php file.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Config\AutoloadConfigInterface;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Cleanup\InstalledJson;
use JsonException;
use League\Flysystem\FilesystemException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-import-type InstalledJsonArray from InstalledJson
 */
class VendorComposerAutoload
{
    use LoggerAwareTrait;

    protected FileSystem $fileSystem;

    protected AutoloadConfigInterface $config;

    public function __construct(
        AutoloadConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->fileSystem = $filesystem;
        $this->setLogger($logger);
    }

    /**
     * @throws FilesystemException
     */
    public function addVendorPrefixedAutoloadToVendorAutoload(): void
    {
        if ($this->config->isTargetDirectoryVendor()) {
            $this->logger->info("Target dir is source dir, no autoload.php to add.");
            return;
        }

        $composerAutoloadPhpFilepath = $this->config->getAbsoluteVendorDirectory() . '/autoload.php';

        if (!$this->fileSystem->fileExists($composerAutoloadPhpFilepath)) {
            $this->logger->info(
                "No autoload.php found: {composerAutoloadPhpFilepath}",
                [
                    'composerAutoloadPhpFilepath' => $composerAutoloadPhpFilepath,
                ]
            );
            return;
        }

        $newAutoloadPhpFilepath = $this->config->getAbsoluteTargetDirectory() . '/autoload.php';

        // TODO: fix dry-run.
        if (!$this->fileSystem->fileExists($newAutoloadPhpFilepath) & !$this->config->isDryRun()) {
            $this->logger->warning(
                "No new autoload.php found: {newAutoloadPhpFilepath}",
                [
                    'newAutoloadPhpFilepath' => $newAutoloadPhpFilepath,
                ]
            );
        }

        $this->logger->info('Modifying original autoload.php to add `' . $newAutoloadPhpFilepath);

        $composerAutoloadPhpFileString = $this->fileSystem->read($composerAutoloadPhpFilepath);

        $newComposerAutoloadPhpFileString = $this->addVendorPrefixedAutoloadToComposerAutoload($composerAutoloadPhpFileString);

        if ($newComposerAutoloadPhpFileString !== $composerAutoloadPhpFileString) {
            $this->logger->info('Writing new autoload.php');
            $this->fileSystem->write($composerAutoloadPhpFilepath, $newComposerAutoloadPhpFileString);
        } else {
            $this->logger->debug('No changes to autoload.php');
        }
    }

    /**
     * Given the PHP code string for `vendor/autoload.php`, add a `require_once autoload_aliases.php`
     * before require autoload_real.php.
     *
     * @throws FilesystemException
     * @throws JsonException
     */
    public function addAliasesFileToComposer(): void
    {
        if ($this->isComposerInstalled()) {
            $this->logger->info("Strauss installed via Composer, no need to add `autoload_aliases.php` to `vendor/autoload.php`");
            return;
        }

        $composerAutoloadPhpFilepath = $this->config->getAbsoluteVendorDirectory() . '/autoload.php';

        if (!$this->fileSystem->fileExists($composerAutoloadPhpFilepath)) {
            // No `vendor/autoload.php` file to add `autoload_aliases.php` to.
            $this->logger->error("No autoload.php found: " . $composerAutoloadPhpFilepath);
            // TODO: Should probably throw an exception here.
            return;
        }

        if ($this->isComposerNoDev()) {
            $this->logger->notice("Composer was run with `--no-dev`, no need to add `autoload_aliases.php` to `vendor/autoload.php`");
            return;
        }

        $this->logger->info('Modifying original autoload.php to add autoload_aliases.php in ' . $this->config->getAbsoluteVendorDirectory());

        $composerAutoloadPhpFileString = $this->fileSystem->read($composerAutoloadPhpFilepath);

        $newComposerAutoloadPhpFileString = $this->addAliasesFileToComposerAutoload($composerAutoloadPhpFileString);

        if ($newComposerAutoloadPhpFileString !== $composerAutoloadPhpFileString) {
            $this->logger->info('Writing new autoload.php');
            $this->fileSystem->write($composerAutoloadPhpFilepath, $newComposerAutoloadPhpFileString);
        } else {
            $this->logger->debug('No changes to autoload.php');
        }
    }

    /**
     * Determine is Strauss installed via Composer (otherwise presumably run via phar).
     *
     * @throws JsonException
     * @throws FilesystemException
     */
    protected function isComposerInstalled(): bool
    {
        if (!$this->fileSystem->fileExists($this->config->getAbsoluteVendorDirectory() . '/composer/installed.json')) {
            return false;
        }

        /**
 * @var InstalledJsonArray $installedJsonArray
*/
        $installedJsonArray = json_decode(
            $this->fileSystem->read($this->config->getAbsoluteVendorDirectory() . '/composer/installed.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return isset($installedJsonArray['dev-package-names']['brianhenryie/strauss']);
    }

    /**
     * Read `vendor/composer/installed.json` to determine if the composer was run with `--no-dev`.
     *
     * {
     *   "packages": [],
     *   "dev": true,
     *   "dev-package-names": []
     * }
     *
     * @throws FilesystemException
     */
    protected function isComposerNoDev(): bool
    {
        $installedJson = $this->fileSystem->read($this->config->getAbsoluteVendorDirectory() . '/composer/installed.json');
        /**
 * @var InstalledJsonArray $installedJsonArray
*/
        $installedJsonArray = json_decode($installedJson, true);
        return !isset($installedJsonArray['dev']) || !$installedJsonArray['dev'];
    }

    /**
     * Requires `autoload_aliases.php` once Composer's autoloader is registered, so the aliases autoloader, which
     * prepends itself, is ahead of it:
     *
     * ```
     * return call_user_func(static function ($loader) {
     *     if (file_exists(__DIR__ . '/composer/autoload_aliases.php')) {
     *         require_once __DIR__ . '/composer/autoload_aliases.php';
     *     }
     *     return $loader;
     * }, ComposerAutoloaderInit…::getLoader());
     * ```
     *
     * When prefixing in place, `vendor`'s Composer autoloader loads the prefixed types, and the aliases autoloader must
     * see them load to alias their original names. This is the same order as when Strauss is installed with Composer,
     * where `bootstrap.php`, a `files` autoload, requires the aliases file.
     *
     * @param string $code The contents of `vendor/autoload.php`.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/363
     */
    protected function addAliasesFileToComposerAutoload(string $code): string
    {
        if (false !== strpos($code, '/composer/autoload_aliases.php')) {
            $this->logger->info('vendor/autoload.php already includes autoload_aliases.php');
            return $code;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return $code;
        }
        if (is_null($ast)) {
            return $code;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class extends NodeVisitorAbstract {
                /**
                 * @param Node $node The node under inspection.
                 *
                 * @see NodeVisitor::leaveNode()
                 * @see NodeVisitorAbstract::leaveNode()
                 */
                public function leaveNode(Node $node): Node
                {
                    // Every `vendor/autoload.php` ends with `return ComposerAutoloaderInit…::getLoader();`.
                    if (
                        !($node instanceof Node\Stmt\Return_)
                        || !($node->expr instanceof Node\Expr\StaticCall)
                        || !($node->expr->name instanceof Node\Identifier)
                        || 'getLoader' !== $node->expr->name->toString()
                    ) {
                        return $node;
                    }

                    // __DIR__ . '/composer/autoload_aliases.php'
                    $path = new Node\Expr\BinaryOp\Concat(
                        new Node\Scalar\MagicConst\Dir(),
                        new Node\Scalar\String_('/composer/autoload_aliases.php')
                    );

                    // if (file_exists(...)) { require_once ...; }
                    $ifFileExistsRequireOnceAutoloadAliases = new Node\Stmt\If_(
                        new Node\Expr\FuncCall(
                            new Node\Name('file_exists'),
                            [new Node\Arg($path)]
                        ),
                        [
                            'stmts' => [
                                new Node\Stmt\Expression(
                                    new Node\Expr\Include_($path, Node\Expr\Include_::TYPE_REQUIRE_ONCE)
                                ),
                            ],
                        ]
                    );

                    $loader = new Node\Expr\Variable('loader');

                    // static function ($loader) { ...; return $loader; }
                    $requireAliasesAfterLoader = new Node\Expr\Closure(
                        [
                        'static' => true,
                        'params' => [new Node\Param($loader)],
                        'stmts' => [
                            $ifFileExistsRequireOnceAutoloadAliases,
                            new Node\Stmt\Return_($loader),
                        ],
                        ]
                    );

                    // `call_user_func()` rather than `(...)(...)`, which needs PHP 7, so the PHP version check above
                    // still parses.
                    $node->expr = new Node\Expr\FuncCall(
                        new Node\Name('call_user_func'),
                        [
                            new Node\Arg($requireAliasesAfterLoader),
                            new Node\Arg($node->expr),
                        ]
                    );

                    return $node;
                }
            }
        );

        $modifiedStmts = $traverser->traverse($ast);

        $prettyPrinter = new Standard();

        return $prettyPrinter->prettyPrintFile($modifiedStmts);
    }

    /**
     * `require_once __DIR__ . '/../vendor-prefixed/autoload.php';`
     *
     * @param string $code The contents of `vendor/autoload.php`.
     */
    protected function addVendorPrefixedAutoloadToComposerAutoload(string $code): string
    {
        if ($this->config->isTargetDirectoryVendor()) {
            $this->logger->info('Vendor directory is target directory, no autoloader to add.');
            return $code;
        }

        $targetDirAutoload = '/' . $this->fileSystem->getRelativePath($this->config->getAbsoluteVendorDirectory(), $this->config->getAbsoluteTargetDirectory()) . '/autoload.php';

        if (false !== strpos($code, $targetDirAutoload)) {
            $this->logger->info('vendor/autoload.php already includes ' . $targetDirAutoload);
            return $code;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return $code;
        }
        if (is_null($ast)) {
            return $code;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($targetDirAutoload) extends NodeVisitorAbstract {
                protected bool $added = false;
                protected string $targetDirectoryAutoload;
                public function __construct(string $targetDirectoryAutoload)
                {
                    $this->targetDirectoryAutoload = $targetDirectoryAutoload;
                }

                /**
                 * @param Node $node The node under inspection.
                 *
                 * @see NodeVisitor::leaveNode()
                 * @see NodeVisitorAbstract::leaveNode()
                 *
                 * @return Node|array{0:\PhpParser\Node\Stmt\If_,1:Node}
                 */
                public function leaveNode(Node $node)
                {
                    if ($this->added) {
                        return $node;
                    }

                    if (get_class($node) === \PhpParser\Node\Stmt\Expression::class) {
                        $prettyPrinter = new Standard();
                        $nodeText = $prettyPrinter->prettyPrintExpr($node->expr);

                        $targets = [
                            "require_once __DIR__ . '/composer/autoload_real.php'",
                        ];

                        if (!in_array($nodeText, $targets)) {
                            return $node;
                        }

                        // __DIR__ . '../vendor-prefixed/autoload.php'
                        $path = new \PhpParser\Node\Expr\BinaryOp\Concat(
                            new \PhpParser\Node\Scalar\MagicConst\Dir(),
                            new Node\Scalar\String_($this->targetDirectoryAutoload)
                        );

                        // require_once
                        $requireOnceStraussAutoload = new Node\Stmt\Expression(
                            new Node\Expr\Include_(
                                $path,
                                Node\Expr\Include_::TYPE_REQUIRE_ONCE
                            )
                        );

                        // if(file_exists()){}
                        $ifFileExistsRequireOnceStraussAutoload = new \PhpParser\Node\Stmt\If_(
                            new \PhpParser\Node\Expr\FuncCall(
                                new \PhpParser\Node\Name('file_exists'),
                                [
                                    new \PhpParser\Node\Arg($path),
                                ],
                            ),
                            [
                                'stmts' => [
                                    $requireOnceStraussAutoload,
                                ],
                            ]
                        );

                        // Add a blank line. Probably not the correct way to do this.
                        $node->setAttribute('comments', [new \PhpParser\Comment('')]);
                        $ifFileExistsRequireOnceStraussAutoload->setAttribute('comments', [new \PhpParser\Comment('')]);

                        $this->added = true;

                        return [
                            $ifFileExistsRequireOnceStraussAutoload,
                            $node,
                        ];
                    }
                    return $node;
                }
            }
        );

        $modifiedStmts = $traverser->traverse($ast);

        $prettyPrinter = new Standard();

        return $prettyPrinter->prettyPrintFile($modifiedStmts);
    }
}
