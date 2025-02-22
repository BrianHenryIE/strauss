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

    protected string $workingDir;

    protected AutoloadConfigInterace $config;

    /**
     * VendorComposerAutoload constructor.
     *
     * @param StraussConfig $config
     * @param string $workingDir
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        AutoloadConfigInterace $config,
        string                 $workingDir,
        Filesystem             $filesystem,
        LoggerInterface        $logger
    )
    {
        $this->config = $config;
        $this->workingDir = $workingDir;
        $this->fileSystem = $filesystem;
        $this->setLogger($logger);
    }

    protected function getVendorDirectory(): string
    {
        return $this->workingDir . $this->config->getVendorDirectory();
    }
    protected function getTargetDirectory(): string
    {
        return $this->workingDir . $this->config->getTargetDirectory();
    }

    /**
     * Given the PHP code string for `vendor/autoload.php`, add a `require_once autoload_aliases.php`
     * before require autoload_real.php.
     *
     * @param string $code
     */
    public function addAliasesFileToComposer(): void
    {
        if ($this->isComposerInstalled()) {
            $this->logger->info("Strauss installed via Composer, no need to modify vendor/autoload.php");
            return;
        }

        $autoloadPhpFilepath = $this->workingDir . $this->config->getVendorDirectory() . 'autoload.php';

        if (!$this->fileSystem->fileExists($autoloadPhpFilepath)) {
            $this->logger->info("No autoload.php found:" . $autoloadPhpFilepath);
            return;
        }

        $this->logger->info('Modifying original autoload.php to add new autoload.php, autoload_aliases.php in ' . $this->config->getVendorDirectory());

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
     * `require_once __DIR__ . '/composer/autoload_real.php';`
     */
    protected function addAliasesFileToComposerAutoload(string $code): string
    {

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return $code;
        }

        $targetDirectoryName = trim($this->config->getTargetDirectory());

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($targetDirectoryName) extends NodeVisitorAbstract {

            protected string $targetDirectoryName;
            public function __construct(string $targetDirectoryName)
            {
                $this->targetDirectoryName = $targetDirectoryName;
            }

            public function leaveNode(Node $node)
            {
                if (get_class($node) === \PhpParser\Node\Stmt\Expression::class) {
                    $prettyPrinter = new Standard();
                    $maybeRequireAutoloadReal = $prettyPrinter->prettyPrintExpr($node->expr);

                    $target = "require_once __DIR__ . '/composer/autoload_real.php'";

                    if ($maybeRequireAutoloadReal !== $target) {
                        return $node;
                    }


                    $requireOnceStraussAutoload = new Node\Stmt\Expression(
                        new Node\Expr\Include_(
                            new \PhpParser\Node\Expr\BinaryOp\Concat(
                                new \PhpParser\Node\Scalar\MagicConst\Dir(),
                                // TODO: obviously update path to match the config.
                                new Node\Scalar\String_('/../'.$this->targetDirectoryName.'autoload.php')
                            ),
                            Node\Expr\Include_::TYPE_REQUIRE_ONCE
                        )
                    );

                    // Add a blank line. Probably not the correct way to do this.
                    $requireOnceStraussAutoload->setAttribute('comments', [new \PhpParser\Comment('')]);

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
                        $requireOnceStraussAutoload,
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
     * Determine is Strauss installed via Composer (otherwise presumably run via phar).
     */
    protected function isComposerInstalled(): bool
    {
        if (!$this->fileSystem->fileExists($this->getVendorDirectory() . 'composer/installed.json')) {
            return false;
        }

        $installedJsonArray = json_decode($this->fileSystem->read($this->getVendorDirectory() . 'composer/installed.json'));

        return isset($installedJsonArray['dev-package-names']['brianhenryie/strauss']);
    }
}
