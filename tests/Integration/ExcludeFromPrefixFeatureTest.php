<?php
// file_patterns

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\IntegrationTestCase;

class ExcludeFromPrefixFeatureTest extends IntegrationTestCase
{

    public function test_exclude_from_prefix_file_patterns(): void
    {
        $composerJsonString = <<<'EOD'
{
    "name": "strauss/exclude-from-prefix",
    "require": {
        "lucatume/di52": "4.0.1",
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

        $psrContainerPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/psr/container/src/ContainerInterface.php');
        $this->assertStringNotContainsString('namespace Strauss\ExcludeFromPrefixTest\Psr\Container;', $psrContainerPhpString);
        $this->assertStringContainsString('namespace Psr\Container;', $psrContainerPhpString);

        $di52ContainerPhpString = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/lucatume/di52/src/Container.php');
        $this->assertStringNotContainsString('use Strauss\ExcludeFromPrefixTest\Psr\Container\ContainerInterface;', $di52ContainerPhpString);
        $this->assertStringContainsString('use Psr\Container\ContainerInterface;', $di52ContainerPhpString);
    }
}
