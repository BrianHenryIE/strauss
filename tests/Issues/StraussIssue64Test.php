<?php
/**
 * @see https://github.com/BrianHenryIE/strauss/issues/64
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\Compose;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StraussIssue64Test extends IntegrationTestCase
{
    public function test_fails_when_symlinked_and_delete_vendor_files(): void
    {
        // Do not use color-logger for this test, use the actual consolelogger.
        $this->logger = null;

        $paths = [
            $main_package_dir = $this->testsWorkingDir . '/main-package',
            $symlinked_package_dir = $this->testsWorkingDir . '/symlinked-package',
        ];

        mkdir($main_package_dir);
        mkdir($symlinked_package_dir . '/src/', 0777, true);

        file_put_contents($main_package_dir . '/composer.json', $this->packageComposerFile());
        file_put_contents($symlinked_package_dir . '/composer.json', $this->symlinkedComposerFile());
        file_put_contents($symlinked_package_dir . '/src/File.php', $this->symlinkedPhpFile());

        chdir($main_package_dir);
        exec('composer install');

        $exitCode = $this->runStrauss($output);

        $this->assertNotEquals(0, $exitCode);

        $this->assertStringContainsString('[error] Symlinked packages detected', $output);
        $this->assertStringContainsString('COMPOSER_MIRROR_PATH_REPOS=1', $output);
    }

    private function packageComposerFile(): string
    {
        return <<<JSON
{
	"repositories": [
		{
			"type": "path",
			"url": "../symlinked-package",
			"options": {
				"symlink": true
			}
		}
	],
	"name": "strauss-test/main-package",
	"require": {
		"strauss-test/symlinked-package": "@dev"
	},
	"extra": {
		"strauss": {
			"target_directory": "vendor_prefixed",
			"namespace_prefix": "Prefixed\\\\",
			"classmap_prefix": "Prefixed_",
			"delete_vendor_files": true
		}
	}
}
JSON;
    }

    private function symlinkedComposerFile(): string
    {
        return <<<JSON
{
	"name": "strauss-test/symlinked-package",
	"autoload": {
		"psr-4": {
			"Internal\\\\Package\\\\": "src/"
		}
	}
}
JSON;
    }

    private function symlinkedPhpFile(): string
    {
        return <<<PHP
<?php
namespace Internal\Package;
final class File {
}
PHP;
    }
}
