<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ReplacerIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
 * @coversNothing
 */
class ReplacerIntegrationTest extends IntegrationTestCase
{

    public function testReplaceNamespace()
    {
        $this->markTestSkipped('Ironically, this is failing because it downloads a newer psr/log but strauss has already loaded an older one.');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/replacerintegrationtest",
  "require": {
    "google/apiclient": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_"
    },
    "google/apiclient-services": [
	  "Calendar"
	]
  },
  "scripts": {
    "pre-autoload-dump": [
      "@delete-unused-google-apis"
    ],
    "delete-unused-google-apis": [
        "Google\\Task\\Composer::cleanup"
    ]
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed/';
        $absoluteTargetDir = $workingDir . $relativeTargetDir;

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($absoluteTargetDir . 'google/apiclient/src/Client.php');

        self::assertStringContainsString('use BrianHenryIE\Strauss\Google\AccessToken\Revoke;', $updatedFile);
    }

    public function testReplaceClass()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "setasign/fpdf": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": false
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir .'vendor-prefixed/' . 'setasign/fpdf/fpdf.php');

        self::assertStringContainsString('class BrianHenryIE_Strauss_FPDF', $updatedFile);
    }

    public function testSimpleReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\(.*)~" : "BrianHenryIE\\MyProject\\\\$1"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace BrianHenryIE\MyProject\WP_Logger;', $updatedFile);
    }

    public function testExaggeratedReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\WP_Logger~" : "AnotherProject\\Logger"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace AnotherProject\Logger;', $updatedFile);
    }

    public function testRidiculousReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\([^\\\\]*)(\\\\.*)?~" : "AnotherProject\\\\$1\\\\MyProject$2"
      }
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/api/class-api.php');

        self::assertStringContainsString('namespace AnotherProject\WP_Logger\MyProject\API;', $updatedFile);
    }
}
