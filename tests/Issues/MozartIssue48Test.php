<?php
/**
 * Multiple paths inside PSR-4 key
 * @see https://github.com/coenjacobs/mozart/issues/48
 *
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

/**
 * Class MozartIssue48Test
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class MozartIssue48Test extends IntegrationTestCase
{

    /**
     * rubix/tensor
     *
     * Mozart was only processing one of the PSR-4 autoload paths, in which case it was not copying (amongst others)
     * `EigenvalueDecomposition.php` at all. Test for its presence.
     */
    public function testRubixTensorBothPathsPersist()
    {

        $composerJsonString = <<<'EOD'
{
    "name": "brianhenryie/mozart-issue-48",
    "require": { "rubix/tensor": "2.0.5" }
}
EOD;

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        // EigenvalueDecomposition.php
        // assert file exists somewhere in the tree

        // https://stackoverflow.com/questions/17160696/php-glob-scan-in-subfolders-for-a-file
        $rsearch = function ($folder, $pattern) {
            $dir = new \RecursiveDirectoryIterator($folder);
            $ite = new \RecursiveIteratorIterator($dir);
            $files = new \RegexIterator($ite, $pattern, \RegexIterator::GET_MATCH);
            $fileList = array();
            foreach ($files as $file) {
                $fileList = array_merge($fileList, $file);
            }
            return $fileList;
        };

        $found = $rsearch($this->testsWorkingDir . 'vendor-prefixed', '~EigenvalueDecomposition\.php~');

        self::assertNotEmpty($found);
    }
}
