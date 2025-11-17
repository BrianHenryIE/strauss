<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Composer\ProjectComposerPackage;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use BrianHenryIE\Strauss\Pipeline\Copier;
use BrianHenryIE\Strauss\Pipeline\FileCopyScanner;
use BrianHenryIE\Strauss\Pipeline\FileEnumerator;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
class ChangeEnumeratorIntegrationTest extends IntegrationTestCase
{
    /**
     * After v0.25.0, v0.26.0, the `tests` directory of `wordpress/mcp-adapter` was being considered for changes
     * although it is not in the package's autoload key.
     */
    public function testsPrepareTarget()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "repositories": {
      "newfold": {
        "type": "composer",
        "url": "https://newfold-labs.github.io/satis/",
        "only": [
            "newfold-labs/*"
        ]
      }
    },
  "require": {
    "newfold-labs/wp-module-mcp": "1.2.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "packages": [
        "wordpress/mcp-adapter"
      ]
    }
  }
}
EOD;
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "wordpress/mcp-adapter": "0.3.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_"
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/wordpress/mcp-adapter/includes/Transport/Infrastructure/SessionManager.php');
        $this->assertStringNotContainsString(' = brianhenryie_strauss_wp_generate_uuid4(', $phpString);
        $this->assertStringContainsString(' = wp_generate_uuid4(', $phpString);

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/wordpress/mcp-adapter/includes/Cli/McpCommand.php');
        $this->assertStringNotContainsString('class McpCommand extends \\BrianHenryIE_Strauss_WP_CLI_Command', $phpString);
        $this->assertStringContainsString('class McpCommand extends \\WP_CLI_Command', $phpString);
    }
}
