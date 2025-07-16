<?php

namespace BrianHenryIE\Strauss\Autoload;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class DumpAutoloadFeatureTest extends IntegrationTestCase
{
    /**
     * I think what's been happening is that the vendor-prefixed autoloader also includes the autoload directives
     * in the root composer.json. When `files` are involved, they get `require`d twice.
     */
    public function test_fix_double_loading_of_files_autoloaders(): void
    {

        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/dump-autoload-feature-test",
    "autoload": {
        "files": [
            "src/DumpAutoloadFeatureTest.php"
        ]
    },
    "require": {
        "symfony/deprecation-contracts": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
            "target_directory": "vendor-prefixed",
            "delete_vendor_packages": true
        }
    }
}
EOD;

        mkdir($this->testsWorkingDir . 'src');
        file_put_contents($this->testsWorkingDir . 'src/DumpAutoloadFeatureTest.php', '<?php // whatever');

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $vendorAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor/composer/autoload_files.php');
        $this->assertStringContainsString('DumpAutoloadFeatureTest.php', $vendorAutoloadFilesPhpString);

        $vendorPrefixedAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/composer/autoload_files.php');
        $this->assertStringNotContainsString('DumpAutoloadFeatureTest.php', $vendorPrefixedAutoloadFilesPhpString);
    }
}
