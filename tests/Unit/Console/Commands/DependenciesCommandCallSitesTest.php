<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */
class DependenciesCommandCallSitesTest extends TestCase
{
    /**
     * The command with its config, filesystem and logger set, without running it.
     */
    protected function getSut(string $absoluteTargetDirectory): DependenciesCommand
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('getAbsoluteTargetDirectory')->willReturn($absoluteTargetDirectory);

        return new class ($config, $this->getInMemoryFileSystem(), $this->getLogger()) extends DependenciesCommand {
            public function __construct(StraussConfig $config, FileSystem $filesystem, LoggerInterface $logger)
            {
                parent::__construct();
                $this->config = $config;
                $this->filesystem = $filesystem;
                $this->logger = $logger;
            }

            public function isInTargetDirectory(string $absolutePath): bool
            {
                return parent::isInTargetDirectory($absolutePath);
            }
        };
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:bool}> Target directory, path, expected.
     */
    public static function inTargetDirectoryProvider(): array
    {
        return [
            'the directory itself' => ['/project/vendor-prefixed', '/project/vendor-prefixed', true],
            'with a trailing slash' => ['/project/vendor-prefixed', '/project/vendor-prefixed/', true],
            'target with a trailing slash' => ['/project/vendor-prefixed/', '/project/vendor-prefixed', true],
            'a file inside' => ['/project/vendor-prefixed', '/project/vendor-prefixed/psr/log/src/Logger.php', true],
            'a directory inside' => ['/project/vendor', '/project/vendor/psr', true],
            'not normalised' => ['/project/vendor', '/project/src/../vendor/psr/log', true],
            'a sibling' => ['/project/vendor-prefixed', '/project/src', false],
            'a sibling with the same beginning' => ['/project/vendor', '/project/vendor-prefixed/psr/log', false],
            'the parent' => ['/project/vendor-prefixed', '/project', false],
            'the same name elsewhere' => ['/project/vendor', '/project/src/vendor/file.php', false],
        ];
    }

    /**
     * @dataProvider inTargetDirectoryProvider
     *
     * @covers ::isInTargetDirectory
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('inTargetDirectoryProvider')]
    public function test_is_in_target_directory(string $targetDirectory, string $path, bool $expected): void
    {
        self::assertSame($expected, $this->getSut($targetDirectory)->isInTargetDirectory($path));
    }
}
