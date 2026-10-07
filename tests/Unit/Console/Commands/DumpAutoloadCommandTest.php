<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Console\Application;
use BrianHenryIE\Strauss\Console\Commands\DumpAutoloadCommand;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\TestCase;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DumpAutoloadCommand
 */
class DumpAutoloadCommandTest extends TestCase
{
    /**
     * The command with its config, filesystem and logger set, without running it.
     */
    protected function getSut(StraussConfig $config, FileSystem $filesystem): DumpAutoloadCommand
    {
        return new class ($config, $filesystem, $this->getLogger()) extends DumpAutoloadCommand {
            public function __construct(StraussConfig $config, FileSystem $filesystem, LoggerInterface $logger)
            {
                parent::__construct();
                $this->config = $config;
                $this->filesystem = $filesystem;
                $this->logger = $logger;
            }

            public function getPipelineSteps(): array
            {
                return parent::getPipelineSteps();
            }

            public function generateAutoloader(): void
            {
                parent::generateAutoloader();
            }

            public function loadPackagesFromTargetInstalledJson(): void
            {
                parent::loadPackagesFromTargetInstalledJson();
            }
        };
    }

    /**
     * @covers ::configure
     */
    public function test_is_registered_with_application(): void
    {
        $application = new Application('1.0.0');

        self::assertInstanceOf(DumpAutoloadCommand::class, $application->get('dump-autoload'));
        self::assertInstanceOf(DumpAutoloadCommand::class, $application->get('dumpautoload'));
        // The default command is unchanged.
        self::assertNotInstanceOf(DumpAutoloadCommand::class, $application->get('dependencies'));
    }

    /**
     * @covers ::getPipelineSteps
     */
    public function test_does_not_copy_or_prefix_packages(): void
    {
        $sut = $this->getSut($this->createMock(StraussConfig::class), $this->getInMemoryFileSystem());

        self::assertSame(
            ['loadPackagesFromTargetInstalledJson', 'generateAutoloader', 'prefixComposerAutoloadFiles'],
            $sut->getPipelineSteps()
        );
    }

    /**
     * @covers ::loadPackagesFromTargetInstalledJson
     */
    public function test_fails_when_strauss_has_not_been_run(): void
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('getAbsoluteTargetDirectory')->willReturn('/project/vendor-prefixed');

        $sut = $this->getSut($config, $this->getInMemoryFileSystem());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Run `strauss` first');

        $sut->loadPackagesFromTargetInstalledJson();
    }

    /**
     * The packages are read from the target directory, without needing the `vendor` directory.
     *
     * @covers ::loadPackagesFromTargetInstalledJson
     */
    public function test_packages_are_loaded_from_target_installed_json(): void
    {
        $filesystem = $this->getInMemoryFileSystem();
        $filesystem->write(
            '/project/vendor-prefixed/composer/installed.json',
            (string) json_encode([
                'packages' => [
                    ['name' => 'psr/log', 'version' => '1.1.4', 'install-path' => '../psr/log'],
                    ['name' => 'psr/container', 'version' => '1.0.0', 'install-path' => '../psr/container'],
                ],
            ])
        );

        $config = $this->createMock(StraussConfig::class);
        $config->method('getAbsoluteTargetDirectory')->willReturn('/project/vendor-prefixed');
        $config->method('getExcludePackagesFromPrefixing')->willReturn(['psr/container']);
        $config->expects(self::once())->method('setPackagesToCopy')->with(
            self::callback(fn(array $packages): bool => ['psr/log', 'psr/container'] === array_keys($packages))
        );
        $config->expects(self::once())->method('setPackagesToPrefix')->with(
            self::callback(fn(array $packages): bool => ['psr/log'] === array_keys($packages))
        );

        $this->getSut($config, $filesystem)->loadPackagesFromTargetInstalledJson();
    }

    /**
     * @covers ::generateAutoloader
     */
    public function test_does_nothing_when_classmap_output_is_disabled(): void
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('isClassmapOutput')->willReturn(false);
        $config->expects(self::never())->method('getAbsoluteTargetDirectory');

        $sut = $this->getSut($config, $this->getInMemoryFileSystem());

        $sut->generateAutoloader();
    }
}
