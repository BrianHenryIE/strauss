<?php
/**
 * @see https://github.com/coenjacobs/mozart/issues/128
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

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
    public function test_fpdf(): void
    {
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.0', 'setasign/fpdi v2.3.0 requires php ^5.6 || ^7.0');

        $composerJsonString = <<<'EOD'
{
  "require": {
    "setasign/fpdf": "1.8",
    "setasign/fpdi": "2.3"
  },
  "extra": {
    "strauss": {
      "target_directory": "vendor-prefixed",
      "namespace_prefix": "\\Strauss\\"
    }
  },
  "config": {
    "audit": {
      "block-insecure": false
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $mpdfPhpString = $this->getFileSystem()->read($this->testsWorkingDir .'/vendor-prefixed/setasign/fpdi/src/FpdfTpl.php');

        // Confirm problem is gone.
        $this->assertStringNotContainsString('class FpdfTpl extends \FPDF', $mpdfPhpString);

        // Confirm solution is correct.
        $this->assertStringContainsString('class FpdfTpl extends \Strauss_FPDF', $mpdfPhpString);
    }
}
