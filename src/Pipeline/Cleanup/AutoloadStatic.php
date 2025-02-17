<?php

namespace BrianHenryIE\Strauss\Pipeline\Cleanup;

use BrianHenryIE\Strauss\Config\CleanupConfigInterface;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use PhpParser\Error;
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

        $autoloadStaticFilepath = $this->getVendorDirectory() . '/composer/autoload_static.php';

        $contents = $this->filesystem->read($autoloadStaticFilepath);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($contents);
        } catch (Error $error) {
            $this->logger->error("Parse error: {$error->getMessage()}");
            return;
        }

        // TODO: Figure out what to do and how to do it.

        $prettyPrinter = new Standard();
        $phpString = $prettyPrinter->prettyPrintFile($ast);

        $this->filesystem->write($autoloadStaticFilepath, $phpString);
    }
}
