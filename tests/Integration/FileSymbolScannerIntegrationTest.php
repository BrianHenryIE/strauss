<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Pipeline\FileSymbolScanner;
use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
class FileSymbolScannerIntegrationTest extends IntegrationTestCase
{
    /**
     * @see FileSymbolScanner::addDiscoveredClassChange()
     */
    public function test_exclude_builtin(): void
    {

        $composerJsonString = <<<'EOD'
{
	"require": {
		"myclabs/php-enum": "1.8.5"
	},
    "config": {
		"platform": {
			"php": "7.4"
		}
    },
	"extra": {
		"strauss": {
			"namespace_prefix": "New\\Namespace",
			"classmap_prefix": "Class_Prefix_"
		}
	}
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/myclabs/php-enum/stubs/Stringable.php');
        $this->assertStringContainsString("interface Stringable", $phpString);
        $this->assertStringNotContainsString("interface BrianHenryIE_Strauss_Stringable", $phpString);
    }
}
