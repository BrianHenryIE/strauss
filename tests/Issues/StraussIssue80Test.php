<?php
/**
 * Incorrectly not prefixing when the word "namespace" is on the same line as `<?php `.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/80
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue80Test extends IntegrationTestCase
{

    /**
     */
    public function test_issue_80()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "league/oauth2-linkedin": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_80_"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/league/oauth2-linkedin/src/Provider/LinkedInResourceOwner.php');
        self::assertStringNotContainsString('class Issue_80_LinkedInResourceOwner extends GenericResourceOwner', $php_string);
        self::assertStringContainsString('namespace Company\Project\League\OAuth2\Client\Provider;', $php_string);
    }


    /**
     */
    public function test_google_api_single_backslash_in_string(): void
    {
        self::markTestSkipped('Slow test. Was for double \\ inside strings.');

        $composerJsonString = <<<'EOD'
{
  "name": "issue/81",
  "require": {
    "google/apiclient": "2.15.1"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Prefix_",
      "exclude_from_copy": {
        "packages": [
          "firebase/php-jwt",
          "guzzlehttp/guzzle",
          "guzzlehttp/promises",
          "guzzlehttp/psr7",
          "psr/log",
          "psr/cache",
          "psr/http-client",
          "psr/http-message",
          "psr/http-factory",
          "monolog/monolog",
          "paragonie/constant_time_encoding",
          "paragonie/random_compat",
          "phpseclib/phpseclib",
          "ralouphie/getallheaders",
          "symfony/deprecation-contracts"
          ]
       }
    },
	"google/apiclient-services": [
	  "Calendar"
	]
  },
  "scripts": {
    "delete-unused-google-apis": [
        "Google\\Task\\Composer::cleanup"
    ]
  }
}
EOD;

        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');
        exec('composer delete-unused-google-apis');

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $outputInterfaceMock = $this->createMock(OutputInterface::class);

        $strauss = new Compose();

        $result = $strauss->run($inputInterfaceMock, $outputInterfaceMock);

        $php_string = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/google/apiclient/src/aliases.php');
        self::assertStringNotContainsString("'Company\\Project\\\Google\\\\Client' => 'Prefix_Google_Client',", $php_string);
        self::assertStringContainsString("'Company\\\\Project\\\\Google\\\\Client' => 'Prefix_Google_Client',", $php_string);
    }
}
