<?php
/**
 * Strings matching classname were replaced.
 *
 * Caused by single-level namespaces. But now classnames in strings are replaced as fqdn.
 *
 * @see https://github.com/BrianHenryIE/strauss/pull/112
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue112Test extends IntegrationTestCase
{
    public function test_dont_replace_in_string(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/issue112",
  "config": {
    "allow-plugins": {
        "php-http/discovery": true
    }
  },
  "classmap": [
    "src"
  ],
  "require": {
        "mailgun/mailgun-php": "^4.2",
        "nyholm/psr7": "^1.8"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue112\\",
      "update_call_sites": true
    }
  }
}
EOD;
        $phpFileString = <<<'EOD'
<?php

namespace Dartui\StraussPrefixError;

use Mailgun\Mailgun;

class Test
{
    public function __construct()
    {
        $this->mailgun = Mailgun::create();
        $this->title = 'Mailgun';
    }
}
EOD;


        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/src');
        $this->getFileSystem()->write($this->testsWorkingDir . '/src/Test.php', $phpFileString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $phpStringAfter = $this->getFileSystem()->read($this->testsWorkingDir . '/src/Test.php');

        $this->assertStringNotContainsString("title = 'Strauss\\Issue112\\Mailgun';", $phpStringAfter);
        $this->assertStringContainsString("title = 'Mailgun';", $phpStringAfter);
    }
}
