<?php
/**
 * When prefixing `AutoloadGenerator` for the phar, a string in it gets prefixed twice.
 *
 * `$prefix = "\0Composer\Autoload\ClassLoader\0";`
 *
 * @see vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php
 */

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Config\PrefixerConfigInterface;
use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\Types\ClassSymbol;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use BrianHenryIE\Strauss\Types\NamespaceSymbol;
use Composer\Autoload\AutoloadGenerator;
use Mockery;

class PrefixingAutoloadGeneratorTest extends IntegrationTestCase
{

    public function test_prefix_autoload_generator(): void
    {
        $config = Mockery::mock(PrefixerConfigInterface::class);
        $config->allows('isTargetDirectoryVendor')->andReturnFalse();
        $config->allows('getConstantsPrefix')->andReturn('BH_');

        $prefixer = new Prefixer(
            $config,
            $this->getFileSystem(),
            $this->getTestLogger()
        );

        $this->getFileSystem()->copy(
            dirname(__DIR__, 3) . '/vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php',
            $this->testsWorkingDir . '/AutoloadGenerator.php'
        );

        $file = new File(
            $this->testsWorkingDir . '/AutoloadGenerator.php',
            $this->testsWorkingDir . '/AutoloadGenerator.php',
            $this->testsWorkingDir . '/AutoloadGenerator.php'
        );

        $namespaceSymbol = new NamespaceSymbol(
            'Composer\Autoload',
            $file,
        );
        $namespaceSymbol->setLocalReplacement('BrianHenryIE\Test\Composer\Autoload');
        $namespaceSymbol->setDoRename(true);

        $classSymbol = new ClassSymbol(
            'Composer\Autoload\ClassLoader',
            $file,
            $namespaceSymbol
        );
        $classSymbol->setDoRename(true);

        $discoveredSymbols = new DiscoveredSymbols([$namespaceSymbol, $classSymbol]);

        $prefixer->replaceInFiles(
            $discoveredSymbols,
            [$file]
        );

        $contentsAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/AutoloadGenerator.php');

        $this->assertStringNotContainsString('$prefix = "\0Composer\Autoload\ClassLoader\0";', $contentsAfter);
        $this->assertStringContainsString('$prefix = "\0BrianHenryIE\Test\Composer\Autoload\ClassLoader\0";', $contentsAfter);

        $prefixer->replaceInFiles(
            $discoveredSymbols,
            [$file]
        );

        $contentsAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/AutoloadGenerator.php');

        $this->assertStringNotContainsString('$prefix = "\0Composer\Autoload\ClassLoader\0";', $contentsAfter);
        $this->assertStringContainsString('$prefix = "\0BrianHenryIE\Test\Composer\Autoload\ClassLoader\0";', $contentsAfter);
    }
}
