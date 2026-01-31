<?php

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class ChangeEnumeratorIntegrationTest extends IntegrationTestCase
{
    /**
     * After v0.25.0, v0.26.0, the `tests` directory of `wordpress/mcp-adapter` was being considered for changes
     * although it is not in the package's autoload key.
     */
    public function testPrepareTarget(): void
    {
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

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/wordpress/mcp-adapter/includes/Transport/Infrastructure/SessionManager.php');
        $this->assertStringNotContainsString(' = brianhenryie_strauss_wp_generate_uuid4(', $phpString);
        $this->assertStringContainsString(' = wp_generate_uuid4(', $phpString);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/wordpress/mcp-adapter/includes/Cli/McpCommand.php');
        $this->assertStringNotContainsString('class McpCommand extends \\BrianHenryIE_Strauss_WP_CLI_Command', $phpString);
        $this->assertStringContainsString('class McpCommand extends \\WP_CLI_Command', $phpString);
    }

    public function testNamespaceInTwoPackagesExclude(): void
    {
        $packageComposerJson = <<<'EOD'
{
	"name": "test/namespaced-files-not-in-autoloader",
	 "require": {
        "art4/requests-psr18-adapter": "1.3.0"
    },
    "extra": {
        "strauss": {
            "namespace_prefix": "BrianHenryIE\\Strauss\\",
			"exclude_from_copy": {
                "packages": [
                    "rmccue/requests"
                ]
            },
			"exclude_from_prefix": {
                "file_patterns": [
                    "art4/requests-psr18-adapter/v1-compat"
                ]
            }
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $packageComposerJson);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $this->runStrauss();

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir .'vendor-prefixed/art4/requests-psr18-adapter/v1-compat/autoload.php');
        $this->assertStringNotContainsString("class_exists('BrianHenryIE\\Strauss\\WpOrg\\Requests\\Requests')", $phpString);
        $this->assertStringContainsString("class_exists('WpOrg\\Requests\\Requests')", $phpString);
    }
}
