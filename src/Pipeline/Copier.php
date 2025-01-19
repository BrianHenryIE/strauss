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

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Files\DiscoveredFiles;
use BrianHenryIE\Strauss\Files\File;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Copier
{
    /**
     * The only path variable with a leading slash.
     * All directories in project end with a slash.
     *
     * @var string
     */
    protected string $workingDir;

    protected string $absoluteTargetDir;

    protected DiscoveredFiles $files;

    /** @var Filesystem */
    protected Filesystem $filesystem;

    protected StraussConfig $config;

    protected OutputInterface $output;

    /**
     * Copier constructor.
     *
     * @param DiscoveredFiles $files
     * @param string $workingDir
     * @param StraussConfig $config
     */
    public function __construct(
        DiscoveredFiles $files,
        string $workingDir,
        StraussConfig $config,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->files = $files;

        $this->config = $config;
        $this->logger = $logger;

        $this->absoluteTargetDir = $workingDir . $config->getTargetDirectory();

        $this->filesystem = $filesystem;
    }

    /**
     * If the target dir does not exist, create it.
     * If it already exists, delete any files we're about to copy.
     *
     * @return void
     * @throws FilesystemException
     */
    public function prepareTarget(): void
    {
        if (! $this->filesystem->isDir($this->absoluteTargetDir)) {
            $this->filesystem->createDirectory($this->absoluteTargetDir);
        } else {
            foreach ($this->files->getFiles() as $file) {
                if (!$file->isDoCopy()) {
                    continue;
                }

                $targetAbsoluteFilepath = $file->getAbsoluteTargetPath();

                if ($this->filesystem->fileExists($targetAbsoluteFilepath)) {
                    $this->filesystem->delete($targetAbsoluteFilepath);
                }
            }
        }
    }

    /**
     * @throws FilesystemException
     */
    public function copy(): void
    {
        /**
         * @var File $file
         */
        foreach ($this->files->getFiles() as $file) {
            if (!$file->isDoCopy()) {
                continue;
            }

            if ($this->config->isDryRun()) {
                $this->logger->info('Would copy ' . $file->getSourcePath() . ' to ' . $file->getAbsoluteTargetPath());
                continue;
            }

            $sourceAbsoluteFilepath = $file->getSourcePath();

            $targetAbsolutePath = $file->getAbsoluteTargetPath();

            $this->filesystem->copy($sourceAbsoluteFilepath, $targetAbsolutePath);
        }
    }
}
