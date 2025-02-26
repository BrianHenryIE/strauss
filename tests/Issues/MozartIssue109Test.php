<?php
/**
 * nesbot/carbon empty searchNamespace
 * @see https://github.com/coenjacobs/mozart/issues/109
 *
 * Comments were being prefixed.
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue109Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue109Test extends IntegrationTestCase
{

    public function testTheOutputDoesNotPrefixComments()
    {

        $composerJsonString = <<<'EOD'
{
  "minimum-stability": "dev",
  "require": {
    "nesbot/carbon":"1.39.0"
  },
  "config": {
    "process-timeout": 0,
    "sort-packages": true,
    "allow-plugins": {
        "kylekatarnls/update-helper": true
    }
  },
  "extra": {
    "mozart": {
      "dep_namespace": "Mozart\\",
      "dep_directory": "/vendor-prefixed/",
      "delete_vendor_files": false,
      "exclude_packages": [
        "kylekatarnls/update-helper",
        "symfony/polyfill-intl-idn",
        "symfony/translation",
        "symfony/polyfill-mbstring",
        "symfony/translation-contracts",
        "composer-plugin-api"
      ]
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        assert(file_exists($this->testsWorkingDir .'vendor/nesbot/carbon/src/Carbon/Carbon.php'));
        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        $phpString = file_get_contents($this->testsWorkingDir .'vendor-prefixed/nesbot/carbon/src/Carbon/Carbon.php');

        self::assertStringNotContainsString('*Mozart\\ This file is part of the Carbon package.Mozart\\', $phpString);
    }
}
