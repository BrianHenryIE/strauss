<?php
/**
 * @see https://github.com/coenjacobs/mozart/issues/128
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue128Test
 * @coversNothing
 */
class MozartIssue128Test extends IntegrationTestCase
{
    /**
     * Because the neither package was a sub-package of the other, the replacing was not occurring
     * throughout.
     */
    public function test_fpdf()
    {

        if (version_compare(phpversion(), '7.0', '>')) {
            $this->markTestSkipped("Package specified for test is not PHP 8.0 compatible. Running tests under PHP " . phpversion());
        }

        $composerJsonString = <<<'EOD'
{
  "require": {
    "setasign/fpdf": "1.8",
    "setasign/fpdi": "2.3"
  },
  "require-dev": {
    "coenjacobs/mozart": "dev-master#3b1243ca8505fa6436569800dc34269178930f39"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "\\Strauss\\"
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $mpdf_php = $this->getFileSystem()->read($this->testsWorkingDir .'strauss/setasign/fpdi/src/FpdfTpl.php');

        // Confirm problem is gone.
        self::assertStringNotContainsString('class FpdfTpl extends \FPDF', $mpdf_php);

        // Confirm solution is correct.
        self::assertStringContainsString('class FpdfTpl extends \Strauss_FPDF', $mpdf_php);
    }
}
