<?php
/**
 * Metapackages!
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/22
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue22Test extends IntegrationTestCase
{

    /**
     * "Virtual packages are a way to specify the dependency on an implementation of an interface-only
     * repository without forcing a specific implementation. For HTTPlug, the virtual packages are
     * called php-http/client-implementation (though you should be using psr/http-client-implementation
     * to use PSR-18) and php-http/async-client-implementation."
     *
     * omnipay/common references php-http/client-implementation which should be automatically skipped.
     *
     * "Composer could not find the config file: /.../vendor/php-http/client-implementation/composer.json"
     *
     * @see https://docs.php-http.org/en/latest/clients.html
     */
    public function test_virtual_package()
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-22",
  "require": {
    "omnipay/common": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue22\\",
      "classmap_prefix": "Strauss_Issue22_"
    }
  },
  "config": {
    "allow-plugins": {
      "php-http/discovery": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertEquals(0, $exitCode, $output);
    }

    /**
     * league/omnipay is a meta-package.
     *
     * "metapackage: An empty package that contains requirements and will trigger their installation, but
     * contains no files and will not write anything to the filesystem. As such, it does not require a
     * dist or source key to be installable."
     *
     * A meta package will not exist on the filesystem. It must be fetched from a package repository.
     *
     * After league/omnipay is installed, the omnipay/common package should be present.
     * /strauss/omnipay/common/src/Omnipay.php
     *
     * "Composer could not find the config file: /.../vendor/league/omnipay/"
     *
     * @author BrianHenryIE
     */
    public function test_meta_package()
    {
        $this->markTestSkippedOnPhpVersionAbove('8.2', 'Fatal error: Allowed memory size of 134217728 bytes exhausted');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss-issue-22",
  "require": {
    "league/omnipay": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\Issue22\\",
      "classmap_prefix": "Strauss_Issue22_"
    }
  },
  "config": {
    "allow-plugins": {
      "php-http/discovery": true
    }
  }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert(0 === $exitCode, $output);

        self::assertFileExists($this->testsWorkingDir . 'vendor-prefixed/omnipay/common/src/Omnipay.php');
    }
}
