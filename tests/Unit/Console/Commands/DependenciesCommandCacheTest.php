<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Files\FileCodeMap;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\AnalysisCache;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand
 */
class DependenciesCommandCacheTest extends TestCase
{
    /**
     * The command with its config and logger set, without running it.
     */
    protected function getSut(StraussConfig $config): DependenciesCommand
    {
        return new class ($config, $this->getLogger()) extends DependenciesCommand {
            public function __construct(StraussConfig $config, LoggerInterface $logger)
            {
                parent::__construct();
                $this->config = $config;
                $this->logger = $logger;
            }

            public function getAnalysisCache(): ?AnalysisCache
            {
                return parent::getAnalysisCache();
            }

            public function getAnalysisCacheDirectory(): ?string
            {
                return parent::getAnalysisCacheDirectory();
            }
        };
    }

    protected function getConfig(bool $isCache, bool $isDryRun = false): StraussConfig
    {
        $config = $this->createMock(StraussConfig::class);
        $config->method('isCache')->willReturn($isCache);
        $config->method('isDryRun')->willReturn($isDryRun);
        return $config;
    }

    /**
     * Run `$test` with Composer's cache directory set as `COMPOSER_CACHE_DIR` would set it.
     */
    protected function withComposerCacheDir(string $directory, callable $test): void
    {
        $previousEnv = getenv('COMPOSER_CACHE_DIR');
        $previousServer = $_SERVER['COMPOSER_CACHE_DIR'] ?? null;

        putenv('COMPOSER_CACHE_DIR=' . $directory);
        $_SERVER['COMPOSER_CACHE_DIR'] = $directory;

        try {
            $test();
        } finally {
            // Composer creates its cache directory, with a `.htaccess`, when its config is read.
            if (is_dir($directory) && 0 === strpos($directory, sys_get_temp_dir() . '/strauss-test-composer-cache')) {
                @unlink($directory . '/.htaccess');
                @rmdir($directory);
            }
            putenv(false === $previousEnv ? 'COMPOSER_CACHE_DIR' : 'COMPOSER_CACHE_DIR=' . $previousEnv);
            if (is_null($previousServer)) {
                unset($_SERVER['COMPOSER_CACHE_DIR']);
            } else {
                $_SERVER['COMPOSER_CACHE_DIR'] = $previousServer;
            }
        }
    }

    /**
     * @covers ::configure
     */
    public function test_cache_option_is_optional_and_distinguishes_absent_from_bare(): void
    {
        $definition = $this->getSut($this->getConfig(true))->getDefinition();

        self::assertTrue($definition->hasOption('cache'));
        self::assertTrue($definition->getOption('cache')->isValueOptional());

        self::assertFalse((new ArgvInput(['strauss'], $definition))->getOption('cache'), 'Not passed.');
        self::assertNull((new ArgvInput(['strauss', '--cache'], $definition))->getOption('cache'));
        self::assertSame('false', (new ArgvInput(['strauss', '--cache=false'], $definition))->getOption('cache'));
    }

    /**
     * @covers ::getAnalysisCacheDirectory
     */
    public function test_cache_is_in_composers_cache_directory(): void
    {
        $composerCacheDir = sys_get_temp_dir() . '/strauss-test-composer-cache';

        $this->withComposerCacheDir($composerCacheDir, function () use ($composerCacheDir): void {
            self::assertSame(
                $composerCacheDir . '/strauss/analysis',
                $this->getSut($this->getConfig(true))->getAnalysisCacheDirectory()
            );
        });
    }

    /**
     * Composer's own caches are disabled by pointing its cache directory at the null device.
     *
     * @covers ::getAnalysisCacheDirectory
     * @covers ::getAnalysisCache
     */
    public function test_no_cache_when_composers_cache_is_disabled(): void
    {
        $this->withComposerCacheDir('/dev/null', function (): void {
            $sut = $this->getSut($this->getConfig(true));

            self::assertNull($sut->getAnalysisCacheDirectory());
            self::assertNull($sut->getAnalysisCache());
        });
    }

    /**
     * @covers ::getAnalysisCache
     */
    public function test_no_cache_when_configured_off(): void
    {
        $this->withComposerCacheDir(sys_get_temp_dir() . '/strauss-test-composer-cache', function (): void {
            self::assertNull($this->getSut($this->getConfig(false))->getAnalysisCache());
        });
    }

    /**
     * @covers ::getAnalysisCache
     */
    public function test_cache_when_configured_on(): void
    {
        $this->withComposerCacheDir(sys_get_temp_dir() . '/strauss-test-composer-cache', function (): void {
            self::assertInstanceOf(AnalysisCache::class, $this->getSut($this->getConfig(true))->getAnalysisCache());
        });
    }

    /**
     * A dry run makes no changes, so it reads the cache without writing to it.
     *
     * @covers ::getAnalysisCache
     */
    public function test_cache_is_read_only_during_dry_run(): void
    {
        $composerCacheDir = sys_get_temp_dir() . '/strauss-test-composer-cache-' . bin2hex(random_bytes(6));

        $this->withComposerCacheDir($composerCacheDir, function () use ($composerCacheDir): void {
            $cache = $this->getSut($this->getConfig(true, true))->getAnalysisCache();
            self::assertInstanceOf(AnalysisCache::class, $cache);

            $contents = "<?php\nclass Widget {}\n";
            $codeMap = (new PhpFileAnalyzer())->analyze($contents);
            self::assertInstanceOf(FileCodeMap::class, $codeMap);

            $cache->set($contents, $codeMap);

            self::assertNull($cache->get($contents));
            self::assertDirectoryDoesNotExist($composerCacheDir . '/strauss');
        });
    }
}
