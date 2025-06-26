<?php
/**
 * Prepares the destination by deleting any files about to be copied.
 * Copies the files.
 *
 * TODO: Exclude files list.
 *
 * @author CoenJacobs
 * @author BrianHenryIE
 *
 * @license MIT
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Config\CopierConfigInterface;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Copier
{
    use LoggerAwareTrait;

    protected DiscoveredFiles $files;

    protected FileSystem $filesystem;

    protected CopierConfigInterface $config;

    /**
     * Copier constructor.
     *
     * @param DiscoveredFiles $files Contains a collections of Files with source and target paths.
     * @param CopierConfigInterface $config
     * @param FileSystem $filesystem A filesystem instance.
     * @param LoggerInterface $logger A logger implementation.
     */
    public function __construct(
        DiscoveredFiles $files,
        CopierConfigInterface $config,
        FileSystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->files = $files;
        $this->config = $config;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
    }
    
    /**
     * If the target dir does not exist, create it.
     * If it already exists, delete any files we're about to copy.
     *
     * @throws FilesystemException
     */
    public function prepareTarget(): void
    {
        if (! $this->filesystem->directoryExists($this->config->getTargetDirectory())) {
            $this->logger->info('Creating directory at ' . $this->config->getTargetDirectory());
            $this->filesystem->createDirectory($this->config->getTargetDirectory());
        }

        foreach ($this->files->getFiles() as $file) {
            if (!$file->isDoCopy()) {
                $this->logger->debug('Skipping ' . $file->getSourcePath());
                continue;
            }

            $targetAbsoluteFilepath = $file->getAbsoluteTargetPath();

            if ($this->filesystem->fileExists($targetAbsoluteFilepath)) {
                $this->logger->info('Deleting existing destination file at ' . $targetAbsoluteFilepath);
                $this->filesystem->delete($targetAbsoluteFilepath);
            }
        }
    }

    /**
     * @throws FilesystemException
     */
    public function copy(): void
    {
        $this->logger->notice('Copying files');

        /**
         * @var File $file
         */
        foreach ($this->files->getFiles() as $file) {
            if (!$file->isDoCopy()) {
                $this->logger->debug('Skipping {sourcePath}', ['sourcePath' => $file->getSourcePath()]);
                continue;
            }

            $sourceAbsoluteFilepath = $file->getSourcePath();
            $targetAbsolutePath = $file->getAbsoluteTargetPath();

            if ($this->filesystem->directoryExists($sourceAbsoluteFilepath)) {
                $this->logger->info(
                    'Creating directory at {targetPath}',
                    ['targetPath' => $targetAbsolutePath]
                );
                $this->filesystem->createDirectory($targetAbsolutePath);
            } elseif ($this->filesystem->fileExists($sourceAbsoluteFilepath)) {
                $this->logger->info(
                    'Copying file to {targetPath}',
                    ['targetPath' => $targetAbsolutePath]
                );
                $this->filesystem->copy($sourceAbsoluteFilepath, $targetAbsolutePath);
            } else {
                $file->setDoPrefix(false);
                $this->logger->warning(
                    'Expected file not found: {sourcePath}',
                    ['sourcePath' => $sourceAbsoluteFilepath]
                );
            }
        }
    }
}
