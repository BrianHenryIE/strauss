<?php
/**
 * Given the `exclude_files_from_update` config options:
 * - `file_patterns`
 * - `packages`
 * - `namespaces`
 * mark files matching those rules to be excluded from any changes.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\MarkFilesExcludedFromChangesConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Files\FileWithDependency;
use Psr\Log\LoggerInterface;

class MarkFilesExcludedFromChanges
{
    protected MarkFilesExcludedFromChangesConfigInterface $config;
    protected LoggerInterface $logger;

    public function __construct(
        MarkFilesExcludedFromChangesConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function scanDiscoveredFiles(DiscoveredFiles $discoveredFiles): void
    {
        foreach ($discoveredFiles->getFiles() as $file) {
            if ($this->fileMatchesFilePattern($file)
                || $this->fileMatchesNamespace($file)
                || $this->fileMatchesPackage($file)
            ) {
                $file->setDoUpdate(false);
            }
        }
    }

    protected function fileMatchesFilePattern(File $file): bool
    {
        $vendorRelativePath = $file->getVendorRelativePath();
        foreach ($this->config->getExcludeFilesFromUpdateFilePatterns() as $excludeFilePattern) {
            if (1 === preg_match($this->preparePattern($excludeFilePattern), $vendorRelativePath)) {
                $this->logger->info('Exclude from changes: {filePath} matches pattern {pattern}', [
                    'filePath' => $file->getVendorRelativePath(),
                    'pattern' => $excludeFilePattern
                ]);

                return true;
            }
        }
        return false;
    }

    /**
     * TODO: This should be moved into the class parsing the config.
     */
    private function preparePattern(string $pattern): string
    {
        $delimiter = '#';

        if (substr($pattern, 0, 1) !== substr($pattern, - 1, 1)) {
            $pattern = $delimiter . $pattern . $delimiter;
        }

        return $pattern;
    }
    /**
     * If any excluded namespaces are in the file, do not make changes to the file.
     *
     * A PHP file can contain multiple namespaces, but that is generally discouraged.
     */
    protected function fileMatchesNamespace(File $file): bool
    {
        $matchingNamespaces = array_intersect(
            $file->getDiscoveredSymbols()->getDiscoveredNamespaces()->toArray(),
            $this->config->getExcludeFileFromUpdateNamespaces()
        );

        if (empty($matchingNamespaces)) {
            return false;
        }

        $this->logger->info('Exclude from changes: {filePath} matches namespace {namespaces}', [
            'filePath' => $file->getTargetAbsolutePath(),
            'namespaces' => implode(', ', $matchingNamespaces)
        ]);

        return true;
    }

    protected function fileMatchesPackage(File $file): bool
    {
        if (!($file instanceof FileWithDependency)) {
            return false;
        }

        $excludePackages = $this->config->getExcludeFilesFromUpdatePackages();
        $matchingKey = array_search(
            $file->getDependency()->getPackageName(),
            $excludePackages
        );

        if ($matchingKey === false) {
            return false;
        }

        $this->logger->info('Exclude from changes: {filePath} matches package {package}', [
            'filePath' => $file->getTargetAbsolutePath(),
            'package' => $excludePackages[$matchingKey]
        ]);


        return true;
    }
}
