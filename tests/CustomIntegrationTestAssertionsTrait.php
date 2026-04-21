<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Helpers\FileSystem;

trait CustomIntegrationTestAssertionsTrait
{

    protected function assertFileNotExistsInFileSystem(string $filePath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();
        $result = $filesystem->fileExists($filePath);
        $this->assertFalse(
            $result,
            $message ?? $filePath . ' should not exist.'
        );
    }

    protected function assertFileExistsInFileSystem(string $filePath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();

        $result = $filesystem->fileExists($filePath);

        $append = $result ? '' : $this->getParentDirectoryAssertFailureMessagePart($filePath, $filesystem);

        $this->assertTrue(
            $result,
            $message ?? $filePath . ' should exist' . $append
        );
    }

    protected function assertDirectoryNotExistsInFileSystem(string $directoryPath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();
        $result = $filesystem->directoryExists($directoryPath);
        $this->assertFalse(
            $result,
            $message ?? $directoryPath . ' should not exist.'
        );
    }

    protected function assertDirectoryExistsInFileSystem(string $directoryPath, ?FileSystem $filesystem = null, ?string $message = null): void
    {
        $filesystem = $filesystem ?? $this->getFileSystem();

        $result = $filesystem->directoryExists($directoryPath);

        $append = $result ? '' : $this->getParentDirectoryAssertFailureMessagePart($directoryPath, $filesystem);

        $this->assertTrue(
            $result,
            $message ?? $directoryPath . ' should exist' . $append
        );
    }
}
