<?php
/**
 * How to handle prefixed dependencies also used by dev-dependencies
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/81
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue81Test extends IntegrationTestCase
{

    /**
     * TODO: figure out what to do for delete_vendor_files
     */
    public function test_aliased_class(): void
    {
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.2', 'Fatal error: Allowed memory size of 134217728 bytes exhausted');

        // `psr/log` isn't a good example to use because it uses PHPUnit without declaring it as a dependency.
        $composerJsonString = <<<'EOD'
{
  "name": "issue/81",
  "require": {
    "brianhenryie/bh-wc-logger": "0.1.1"
  },
  "require-dev": {
    "psr/log": "1.1.4",
    "phpunit/phpunit": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Alias\\",
      "delete_vendor_packages": true
    }
  },
  "config": {
    "classmap-authoritative": true,
    "optimize-autoloader": true
  }
}
EOD;

        $file1 = <<<'EOD'
<?php

namespace Whatever;

require_once __DIR__ . '/vendor-prefixed/autoload.php';
require_once __DIR__ . '/vendor/composer/autoload_aliases.php';
require_once __DIR__ . '/vendor/autoload.php';

new \Psr\Log\NullLogger();

new \Strauss\Alias\Psr\Log\NullLogger();

return 0;

EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/file1.php', $file1);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $exitCode = $this->runStrauss($output, 'include-autoloader');
        $this->assertEquals(0, $exitCode, $output);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir .'vendor/composer/autoload_aliases.php');
        $this->assertStringContainsString("'extends' => 'Strauss\\\\Alias\\\\Psr\\\\Log\\\\NullLogger'", $phpString);

        exec('composer dump-autoload');

        exec('php ' . $this->testsWorkingDir . '/file1.php', $output, $return_var);

        //Fatal error: Uncaught Error: Class "Psr\Log\NullLogger" not found in /private/var/folders/sh/cygymmqn36714790jj3r33200000gn/T/strausstestdir/file1.php:8
        //Stack trace:
        //#0 {main}
        //thrown in /private/var/folders/sh/cygymmqn36714790jj3r33200000gn/T/strausstestdir/file1.php on line 8

        $this->assertEmpty($output, implode(PHP_EOL, $output));
        $this->assertEquals(0, $return_var);
    }

    public function test_snake_case_cli_argument_supersedes_configured_option_false_to_true()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--delete_vendor_packages=true');
        assert($exitCode === 0, $output);

        $this->assertFalse($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }

    public function test_snake_case_cli_argument_supersedes_configured_option_false_to_flag()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--delete_vendor_packages');
        assert($exitCode === 0, $output);

        $this->assertFalse($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }

    public function test_snake_case_cli_argument_supersedes_configured_option_true_to_false()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--delete_vendor_packages=false');
        assert($exitCode === 0, $output);

        $this->assertTrue($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }

    public function test_camel_case_cli_argument_supersedes_configured_option_false_to_true()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--deleteVendorPackages=true');
        assert($exitCode === 0, $output);

        $this->assertFalse($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }
    public function test_camel_case_cli_argument_supersedes_configured_option_false_to_flag()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": false
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--deleteVendorPackages');
        assert($exitCode === 0, $output);

        $this->assertFalse($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }

    public function test_camel_case_cli_argument_supersedes_configured_option_true_to_false()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "issue/80",
  "require": {
    "psr/log": "1.0"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Company\\Project\\",
      "classmap_prefix": "Issue_81_",
      "delete_vendor_packages": true
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output, '--deleteVendorPackages=false');
        assert($exitCode === 0, $output);

        $this->assertTrue($this->getFileSystem()->fileExists($this->testsWorkingDir . 'vendor/psr/log/composer.json'));
    }
}
