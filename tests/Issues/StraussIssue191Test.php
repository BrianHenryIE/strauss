<?php
/**
 * Really an issue in brianhenryie/simple-php-code-parser; this is a regression test
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/191
 * @see https://github.com/BrianHenryIE/Simple-PHP-Code-Parser/pull/8
 * @see https://github.com/BrianHenryIE/Simple-PHP-Code-Parser/releases/tag/0.15.1
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue191Test extends IntegrationTestCase
{
    public function test_fatal(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "adam/strauss-error",
  "description": "Minimal example for bug report",
  "type": "project",
  "license": "MIT",
  "autoload": {
    "psr-4": {
      "Adam\\StraussError\\": "src/"
    }
  },
  "require": {
    "league/mime-type-detection": "*"
  }
}

EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertStringNotContainsString(
            "Couldn't find constant \\League\\MimeTypeDetection\\FinfoMimeTypeDetector::INCONCLUSIVE_MIME_TYPES",
            $this->getActualOutputForAssertion()
        );
    }
}
