<?php
namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CleanupIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
 * @coversNothing
 */
class CleanupIntegrationTest extends IntegrationTestCase
{

    public function testFilesAutoloader()
    {
        self::markTestSkipped('When this test was written, the files in a files autoloader were being deleted, but now they are replaced with an empty file.');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "symfony/polyfill-php80": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": true
    }
  }
}
EOD;
        chdir($this->testsWorkingDir);

        file_put_contents($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        assert(file_exists($this->testsWorkingDir . '/vendor/symfony/polyfill-php80/bootstrap.php'));

        $inputInterfaceMock = $this->createMock(InputInterface::class);
        $outputInterfaceMock = $this->createMock(OutputInterface::class);

        $strauss = new DependenciesCommand();

        $result = $strauss->run($inputInterfaceMock, $outputInterfaceMock);

        $autoload_static_php = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_static.php');
//       This was changed so an empty file is put in its place.
//        self::assertStringNotContainsString("__DIR__ . '/..' . '/symfony/polyfill-php80/bootstrap.php'", $autoload_static_php);

        $autoload_files_php = file_get_contents($this->testsWorkingDir .'vendor/composer/autoload_files.php');
//        self::assertStringNotContainsString("\$vendorDir . '/symfony/polyfill-php80/bootstrap.php'", $autoload_files_php);

//        self::assertStringContainsString("\$baseDir . '/vendor-prefixed/symfony/polyfill-php80/bootstrap.php'", $autoload_files_php);
    }
}
