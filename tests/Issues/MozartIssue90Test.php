<?php
/**
 * @see https://github.com/coenjacobs/mozart/issues/90
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * Class MozartIssue90Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue90Test extends IntegrationTestCase
{

    /**
     * Issue 90. Needs "iio/libmergepdf".
     *
     * Error: "File already exists at path: classmap_directory/tecnickcom/tcpdf/tcpdf.php".
     */
    public function testLibpdfmergeSucceeds()
    {
        $this->markTestSkipped('This fails when php-parser parses. The laptop Im writing on fails with other tests. There is still hope');

        // `PHP Fatal error:  Declaration of BrianHenryIE\Strauss\setasign\Fpdi\FpdfTplTrait::setPageFormat($size, $orientation) must be compatible with BrianHenryIE_Strauss_TCPDF::setPageFormat($format, $orientation = 'P') in /tmp/strausstestdir67b0184f95896/vendor-prefixed/setasign/fpdi/src/FpdfTpl.php on line 48`
        // I think this only fails on newer PHP versions where inheritance signatures are checked more strictly.
        $this->markTestSkippedOnPhpVersionEqualOrAbove('8.0');

        $composerJsonString = <<<'EOD'
{
	"name": "brianhenryie/mozart-issue-90",
	"require": {
		"iio/libmergepdf": "4.0.4"
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

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // This test would only fail on Windows?
        self::assertDirectoryDoesNotExist($this->testsWorkingDir .'strauss/iio/libmergepdf/vendor/iio/libmergepdf/tcpdi');

        $this->assertTrue($this->getFileSystem()->fileExists($this->testsWorkingDir .'vendor-prefixed/iio/libmergepdf/tcpdi/tcpdi.php'));
    }
}
