<?php
/**
 * After a PSR-0 namespaced class is renamed, its directory structure must be updated to match the required format.
 *
 * @see https://www.php-fig.org/psr/psr-0/
 * @see vendor/composer/composer/res/composer-schema.json
 * @see vendor/pimple/pimple/composer.json
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use BrianHenryIE\Strauss\Helpers\Filesystem;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Psr0
{
    use LoggerAwareTrait;

    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
    }

    public function setTargetDirectory(DiscoveredFiles $files)
    {
        /** @var FileBase $file */
        foreach ($files as $file) {
            if (!($file instanceof FileWithDependency)) {
                continue;
            }

            if (!$file->isPhpFile()) {
                continue;
            }

            $composerAutoloadKey = $file->getDependency()->getAutoload();
            if (!array_key_exists('psr-0', $composerAutoloadKey)) {
                continue;
            }

            foreach ($composerAutoloadKey['psr-0'] as $psrRootNamespace => $path) {
                $namespacesInFile = $file->getNamespaces()->notGlobal();

                if (count($namespacesInFile) > 1) {
                    // I must check the spec, surely it's a contradiction.
                    $this->logger->warning('More than one namespace in PSR-0 file.');
                }

                // This line is necessary because `array_pop()` will call unimplemented `::offsetUnset()`.
                $namespacesArray = $namespacesInFile->toArray();

                /** @var NamespaceSymbol $fileNamespaceSymbol */
                $fileNamespaceSymbol = array_pop($namespacesArray);

                $packageRelativePath = $file->getPackageRelativePath();

                $originalNamespaceString = $this->filesystem->normalizePath(
                    $path .'/'.$fileNamespaceSymbol->getOriginalSymbol()
                );
                $replacementNamespaceString = $this->filesystem->normalizePath(
                    $path .'/'.$fileNamespaceSymbol->getReplacementFqdnName()
                );

                $updatedRelativePath = preg_replace(
                    '#^'.$originalNamespaceString.'#',
                    $replacementNamespaceString,
                    $packageRelativePath
                );

                $updatedTargetPath = preg_replace(
                    '#'.$packageRelativePath.'$#',
                    $updatedRelativePath,
                    $file->getTargetAbsolutePath()
                );

                $file->setTargetAbsolutePath($this->filesystem->normalizePath($updatedTargetPath));
            }
        }
    }
}
