<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class AutoloadStatic
{
    use LoggerAwareTrait;

    protected string $workingDir;
    protected CleanupConfigInterface $config;
    protected FileSystem $filesystem;

    public function __construct(
        string $workingDir,
        CleanupConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->workingDir = $workingDir;
        $this->config = $config;
        $this->filesystem = $filesystem;

        $this->setLogger($logger);
    }

    protected function getVendorDirectory(): string
    {
        return $this->workingDir . $this->config->getVendorDirectory();
    }

    public function cleanupAutoloadStatic()
    {
        // Remove dead file entries from autoload_static.php

        $autoloadStaticFilepath = $this->getVendorDirectory() . 'composer/autoload_static.php';

        $contents = $this->filesystem->read($autoloadStaticFilepath);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return;
        }

        $searchFor = [
            'files',
            'prefixLengthsPsr4',
            'prefixDirsPsr4',
            'fallbackDirsPsr4',
            'prefixesPsr0',
            'classMap',
        ];

        $traverser = new NodeTraverser();
        $visitor = $this->getVisitor($searchFor);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        $foundNodes = $visitor->getValues();

        foreach ($foundNodes as $name => $node) {
            switch ($name) {
                case 'files':
                    foreach ($node->items as $index => $filesArrayItem) {
                        $vendorRelativePath = $filesArrayItem->value->right->value;
                        if (!$this->filesystem->fileExists($this->getVendorDirectory() . $vendorRelativePath)) {
                            $this->logger->info("Removing $vendorRelativePath from autoload_static.php");
                            unset($node->items[$index]);
                        }
                    }
                    break;
                case 'prefixLengthsPsr4':
                    foreach ($node->items as $lettersArrayItem) {
                        foreach ($lettersArrayItem->value->items as $item) {
                            // TODO: compare $item->key->value with changed namespaces.
                            // $item->key->value = 'brian';
                            $item->value->value = strlen($item->key->value);
                        }
                    }
                    break;
                case 'prefixDirsPsr4':
                    break;
                case 'fallbackDirsPsr4':
                    break;
                case 'prefixesPsr0':
                    break;
                case 'classMap':
                    break;
                default:
                    throw new \Exception('Should not reach here ' . $name);
            }
        }

        $prettyPrinter = new Standard();
        $phpString = $prettyPrinter->prettyPrintFile($ast);

        $this->filesystem->write($autoloadStaticFilepath, $phpString);
    }

    /**
     * TODO: extract to file.
     *
     * @param string[] $findStaticVariables
     * @return NodeVisitorAbstract (extended)
     */
    protected function getVisitor(array $findStaticVariables)
    {
        return new class($findStaticVariables) extends NodeVisitorAbstract {

            protected array $searchForNames;

            protected array $nameOnLine = [];

            protected array $valueOnLine = [];

            public function __construct(array $namesa)
            {
                $this->searchForNames = $namesa;
            }

            public function getValues(): array
            {
                return $this->valueOnLine;
            }

            public function enterNode(Node $node)
            {
                // I think this will match static variables but not instance variables.
                if (get_class($node) === \PhpParser\Node\VarLikeIdentifier::class) {
                    if (in_array($node->name, $this->searchForNames)) {
                        $this->nameOnLine[$node->getStartLine()] = $node->name;
                    }

                    return $node;
                }

                if (isset($this->nameOnLine[$node->getStartLine()])) {
                    $this->valueOnLine[$this->nameOnLine[$node->getStartLine()]] = $node;
                }

                return $node;
            }
        };
    }
}
