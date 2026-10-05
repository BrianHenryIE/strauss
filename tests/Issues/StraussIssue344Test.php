<?php
/**
 * `update_call_sites: true` with the target directory in the project's `autoload` key double-prefixes docblocks.
 *
 * With `update_call_sites: true`, the directories to update are taken from the project's `autoload` key. When that
 * lists the target directory, e.g. `"classmap": ["vendor"]` when prefixing in place, every package file was updated
 * a second time as a project file: `@throws \Psr\Log\InvalidArgumentException` became
 * `@throws \Prefix\Prefix\Psr\Log\InvalidArgumentException`.
 *
 * The files in the target directory are the packages' own files, never call sites.
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\DependenciesCommand::performReplacementsInProjectFiles()
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/344
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue344Test extends IntegrationTestCase
{
    /**
     * @return array<string, array{0:string, 1:string, 2:string}> `target_directory`, `autoload.classmap`, the
     *                                                             prefixed file's path.
     */
    public static function targetDirectoryInAutoloadProvider(): array
    {
        return [
            'in place, vendor in classmap' => [
                'vendor',
                '["vendor", "src"]',
                'vendor/psr/log/Psr/Log/LoggerInterface.php',
            ],
            'vendor-prefixed in classmap' => [
                'vendor-prefixed',
                '["vendor-prefixed/", "src"]',
                'vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php',
            ],
            'project root in classmap' => [
                'vendor-prefixed',
                '["."]',
                'vendor-prefixed/psr/log/Psr/Log/LoggerInterface.php',
            ],
        ];
    }

    /**
     * @dataProvider targetDirectoryInAutoloadProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('targetDirectoryInAutoloadProvider')]
    public function test_target_directory_is_not_updated_as_call_sites(
        string $targetDirectory,
        string $classmap,
        string $prefixedFilePath
    ): void {
        $composerJsonString = <<<EOD
{
  "name": "strauss/issue344",
  "require": {
    "psr/log": "1.1.4"
  },
  "autoload": {
    "classmap": $classmap
  },
  "extra": {
    "strauss": {
      "target_directory": "$targetDirectory",
      "namespace_prefix": "Strauss\\\\Issue344\\\\",
      "packages": ["psr/log"],
      "update_call_sites": true
    }
  }
}
EOD;

        $projectFileString = <<<'EOD'
<?php

namespace My\Project;

use Psr\Log\LoggerInterface;

class Consumer
{
    public function __construct(LoggerInterface $logger)
    {
    }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);
        $this->getFileSystem()->write($this->testsWorkingDir . '/src/Consumer.php', $projectFileString);

        // Composer cannot scan a classmap directory which does not exist yet.
        $this->getFileSystem()->createDirectory($this->testsWorkingDir . '/' . $targetDirectory);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $prefixedFileString = $this->getFileSystem()->read($this->testsWorkingDir . '/' . $prefixedFilePath);

        $this->assertStringContainsString('namespace Strauss\Issue344\Psr\Log;', $prefixedFileString);
        $this->assertStringContainsString(
            '@throws \Strauss\Issue344\Psr\Log\InvalidArgumentException',
            $prefixedFileString
        );
        $this->assertStringNotContainsString('Strauss\Issue344\Strauss\Issue344', $prefixedFileString);

        // The project's own files are still updated.
        $updatedProjectFileString = $this->getFileSystem()->read($this->testsWorkingDir . '/src/Consumer.php');
        $this->assertStringContainsString('use Strauss\Issue344\Psr\Log\LoggerInterface;', $updatedProjectFileString);
    }
}
