<?php
// file_patterns

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class ExcludeFromPrefixFeatureTest extends IntegrationTestCase
{

    public function test_exclude_from_prefix_file_patterns(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "strauss/exclude-from-prefix",
    "require": {
        "psr/container": "*"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "Strauss\\ExcludeFromPrefixTest\\",
            "exclude_from_prefix": {
                "file_patterns": [
                    "/^psr.*$/"
                ]
            }
        }
    }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        exec('composer dump-autoload');

        $vendorPrefixedAutoloadFilesPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/psr/container/src/ContainerInterface.php');

        $this->assertStringNotContainsString('namespace Strauss\ExcludeFromPrefixTest\Psr\Container;', $vendorPrefixedAutoloadFilesPhpString);
        $this->assertStringContainsString('namespace Psr\Container;', $vendorPrefixedAutoloadFilesPhpString);
    }
}
