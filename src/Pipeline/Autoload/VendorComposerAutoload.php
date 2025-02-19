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
        string $workingDir,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->workingDir = $workingDir;
        $this->fileSystem = $filesystem;
        $this->setLogger($logger);
    }

    /**
     * Given the PHP code string for `vendor/autoload.php`, add a `require_once autoload_aliases.php`
     * before require autoload_real.php.
     *
     * @param string $code
     */
    public function addAliasesFileToComposer(): void
    {

        $autoloadRealFilepath = $this->workingDir . $this->config->getVendorDirectory() . 'autoload.php';

        if (!$this->fileSystem->fileExists($autoloadRealFilepath)) {
            $this->logger->info("No autoload.php found:" . $autoloadRealFilepath);
            return;
        }

        $composerFileString = $this->fileSystem->read($autoloadRealFilepath);

        $newComposerAutoloadReal = $this->addAliasesFileToComposerAutoload($composerFileString);

        $this->fileSystem->write($autoloadRealFilepath, $newComposerAutoloadReal);
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
}
