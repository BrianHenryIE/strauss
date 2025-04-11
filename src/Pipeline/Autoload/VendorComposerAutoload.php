<?php
/**
 * Edit vendor/autoload.php to also load the vendor/composer/autoload_aliases.php file and the vendor-prefixed/autoload.php file.
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Config\AutoloadConfigInterace;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class VendorComposerAutoload
{
    use LoggerAwareTrait;

    protected FileSystem $fileSystem;

    protected AutoloadConfigInterface $config;

    public function __construct(
        AutoloadConfigInterface $config,
        Filesystem             $filesystem,
        LoggerInterface        $logger
    ) {
        $this->config = $config;
        $this->fileSystem = $filesystem;
        $this->setLogger($logger);
    }

    public function addVendorPrefixedAutoloadToVendorAutoload(): void
    {
        $autoloadPhpFilepath = $this->config->getVendorDirectory() . 'autoload.php';

        if (!$this->fileSystem->fileExists($autoloadPhpFilepath)) {
            $this->logger->info("No autoload.php found:" . $autoloadPhpFilepath);
            return;
        }

        $this->logger->info('Modifying original autoload.php to add `' . $this->config->getTargetDirectory() . '/autoload.php`');

        $composerAutoloadPhpFileString = $this->fileSystem->read($autoloadPhpFilepath);

        $newComposerAutoloadPhpFileString = $this->addVendorPrefixedAutoloadToComposerAutoload($composerAutoloadPhpFileString);

        if ($newComposerAutoloadPhpFileString !== $composerAutoloadPhpFileString) {
            $this->logger->info('Writing new autoload.php');
            $this->fileSystem->write($autoloadPhpFilepath, $newComposerAutoloadPhpFileString);
        } else {
            $this->logger->debug('No changes to autoload.php');
        }
    }

    /**
     * Given the PHP code string for `vendor/autoload.php`, add a `require_once autoload_aliases.php`
     * before require autoload_real.php.
     */
    public function addAliasesFileToComposer(): void
    {
        if ($this->isComposerInstalled()) {
            $this->logger->info("Strauss installed via Composer, no need to add `autoload_aliases.php` to `vendor/autoload.php`");
            return;
        }

        $autoloadPhpFilepath = $this->config->getVendorDirectory() . 'autoload.php';

        if (!$this->fileSystem->fileExists($autoloadPhpFilepath)) {
            $this->logger->info("No autoload.php found:" . $autoloadPhpFilepath);
            return;
        }

        if ($this->isComposerNoDev()) {
            $this->logger->info("Composer was run with `--no-dev`, no need to add `autoload_aliases.php` to `vendor/autoload.php`");
            return;
        }

        $this->logger->info('Modifying original autoload.php to add autoload_aliases.php in ' . $this->config->getVendorDirectory());

        $composerAutoloadPhpFileString = $this->fileSystem->read($autoloadPhpFilepath);

        $newComposerAutoloadPhpFileString = $this->addAliasesFileToComposerAutoload($composerAutoloadPhpFileString);

        if ($newComposerAutoloadPhpFileString !== $composerAutoloadPhpFileString) {
            $this->logger->info('Writing new autoload.php');
            $this->fileSystem->write($autoloadPhpFilepath, $newComposerAutoloadPhpFileString);
        } else {
            $this->logger->debug('No changes to autoload.php');
        }
    }

    /**
     * Determine is Strauss installed via Composer (otherwise presumably run via phar).
     */
    protected function isComposerInstalled(): bool
    {
        if (!$this->fileSystem->fileExists($this->config->getVendorDirectory() . 'composer/installed.json')) {
            return false;
        }

        $installedJsonArray = json_decode($this->fileSystem->read($this->config->getVendorDirectory() . 'composer/installed.json'), true);

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
     */
    protected function isComposerNoDev(): bool
    {
        $installedJson = $this->fileSystem->read($this->config->getVendorDirectory() . 'composer/installed.json');
        $installedJsonArray = json_decode($installedJson, true);
        return !$installedJsonArray['dev'];
    }

    /**
     * This is a very over-engineered way to do a string replace.
     *
     * `require_once __DIR__ . '/composer/autoload_aliases.php';`
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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class() extends NodeVisitorAbstract {

            public function leaveNode(Node $node)
            {
                if (get_class($node) === \PhpParser\Node\Stmt\Expression::class) {
                    $prettyPrinter = new Standard();
                    $maybeRequireAutoloadReal = $prettyPrinter->prettyPrintExpr($node->expr);

                    // Every `vendor/autoload.php` should have this line.
                    $target = "require_once __DIR__ . '/composer/autoload_real.php'";

                    // If this node isn't the one we want to insert before, continue.
                    if ($maybeRequireAutoloadReal !== $target) {
                        return $node;
                    }

                    $requireOnceAutoloadAliases = new Node\Stmt\Expression(
                        new \PhpParser\Node\Expr\Include_(
                            new \PhpParser\Node\Expr\BinaryOp\Concat(
                                new \PhpParser\Node\Scalar\MagicConst\Dir(),
                                new \PhpParser\Node\Scalar\String_('/composer/autoload_aliases.php')
                            ),
                            \PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE
                        )
                    );

                    // Add a blank line. Probably not the correct way to do this.
                    $node->setAttribute('comments', [new \PhpParser\Comment('')]);

                    return [
                        $requireOnceAutoloadAliases,
                        $node
                    ];
                }
                return $node;
            }
        });

        $modifiedStmts = $traverser->traverse($ast);

        $prettyPrinter = new Standard();

        return $prettyPrinter->prettyPrintFile($modifiedStmts);
    }

    /**
     * `require_once __DIR__ . '/../vendor-prefixed/autoload.php';`
     */
    protected function addVendorPrefixedAutoloadToComposerAutoload(string $code): string
    {
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            $this->logger->info('Vendor directory is target directory, no autoloader to add.');
            return $code;
        }

        $targetDirAutoload = '/' . $this->fileSystem->getRelativePath($this->config->getVendorDirectory(), $this->config->getTargetDirectory()) . '/autoload.php';

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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($targetDirAutoload) extends NodeVisitorAbstract {

            protected bool $added = false;
            protected ?string $targetDirectoryAutoload;
            public function __construct(?string $targetDirectoryAutoload)
            {
                $this->targetDirectoryAutoload = $targetDirectoryAutoload;
            }

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
                        "require_once __DIR__ . '/composer/autoload_aliases.php'"
                    ];

                    if (!in_array($nodeText, $targets)) {
                        return $node;
                    }

                    $requireOnceStraussAutoload = new Node\Stmt\Expression(
                        new Node\Expr\Include_(
                            new \PhpParser\Node\Expr\BinaryOp\Concat(
                                new \PhpParser\Node\Scalar\MagicConst\Dir(),
                                new Node\Scalar\String_($this->targetDirectoryAutoload)
                            ),
                            Node\Expr\Include_::TYPE_REQUIRE_ONCE
                        )
                    );

                    // Add a blank line. Probably not the correct way to do this.
                    $requireOnceStraussAutoload->setAttribute('comments', [new \PhpParser\Comment('')]);

                    $this->added = true;

                    return [
                        $requireOnceStraussAutoload,
                        $node
                    ];
                }
                return $node;
            }
        });

        $modifiedStmts = $traverser->traverse($ast);

        $prettyPrinter = new Standard();

        return $prettyPrinter->prettyPrintFile($modifiedStmts);
    }
}
