<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
final class CleanupSymlinkIntegrationTest extends IntegrationTestCase
{
    /**
     * Test case that ensures a symlinked package is not removed or cleared out by the strauss command.
     */
    public function testEnsureNoRemovalOfSymlinks(): void
    {
        $main_package_dir = $this->testsWorkingDir . 'main-package/';
        $symlinked_package_dir = $this->testsWorkingDir . 'symlinked-package/';

        mkdir($main_package_dir);
        mkdir($symlinked_package_dir . 'src/', 0777, true);

        file_put_contents($main_package_dir . 'composer.json', $this->packageComposerFile());
        file_put_contents($symlinked_package_dir . 'composer.json', $this->symlinkedComposerFile());
        file_put_contents($symlinked_package_dir . 'src/File.php', $this->symlinkedPhpFile());

        chdir($main_package_dir);
        exec('composer install');


        $relative_symlinked_package_dir = $main_package_dir . 'vendor/strauss-test/symlinked-package';

        $relative_symlinked_package_dir = str_replace(['/', '\\'], '/', $relative_symlinked_package_dir);

        assert(is_dir($relative_symlinked_package_dir));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExists($main_package_dir . 'vendor_prefixed/strauss-test/symlinked-package/src/File.php');

        self::assertDirectoryExists($symlinked_package_dir);
        self::assertDirectoryDoesNotExist($relative_symlinked_package_dir);
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
			"delete_vendor_packages": true
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
