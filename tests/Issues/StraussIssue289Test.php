<?php
/**
 * `installed.json` package autoload key removed although it is not in `packages` list.
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/289
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 *
 * @see InstalledJson::cleanTargetDirInstalledJson()
 */
class StraussIssue289Test extends IntegrationTestCase
{

    public function test_do_not_remove_autoload_key(): void
    {

        $dependencyComposerJsonString = <<<'EOD'
{
  "name": "strauss/symlinked-package",
  "autoload": {
    "classmap": ["src"]
  }
}

EOD;

        $mainComposerJsonString = <<<'EOD'
{
  "name": "issue/289",
  "minimum-stability": "dev",
  "repositories": {
    "strauss/symlinked-package": {
      "url": "../dependency",
      "type": "path",
      "options": {
        "symlink": true
      }
    }
  },
  "require": {
    "strauss/symlinked-package": "*",
    "psr/log": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Issue289\\",
      "delete_vendor_packages": true,
      "packages": [
        "psr/log"
      ]
    }
  }
}
EOD;

        mkdir($this->testsWorkingDir . '/dependency');
        $this->getFileSystem()->write($this->testsWorkingDir . '/dependency/composer.json', $dependencyComposerJsonString);
        mkdir($this->testsWorkingDir . '/dependency/src');

        mkdir($this->testsWorkingDir . '/project');
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $mainComposerJsonString);
        chdir($this->testsWorkingDir . '/project');
        exec('composer install');

        $installed_json_package_autoload = function (string $package_name): array {
            $installed_before_string = $this->getFileSystem()->read($this->testsWorkingDir . '/project/vendor/composer/installed.json');
            $installed_json          = json_decode($installed_before_string, true, 512, JSON_THROW_ON_ERROR);
            $package_json            = array_values(array_filter(
                $installed_json['packages'],
                fn(array $package) => $package['name'] === $package_name
            ));
            return $package_json[0]['autoload'] ?? [];
        };

        $before = $installed_json_package_autoload("strauss/symlinked-package");
        $this->assertNotEmpty($before);

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $after = $installed_json_package_autoload("strauss/symlinked-package");
        $this->assertNotEmpty($after, 'Autoload keys before: ["' . implode('", "', array_keys($before)) . '"], after: ["' . implode('", "', array_keys($after)) . '"].');
    }
}
