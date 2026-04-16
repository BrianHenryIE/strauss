<?php
/**
 * Should accept Strauss config and Mozart config.
 *
 * Should have sensible defaults.
 */

namespace BrianHenryIE\Strauss\Composer\Extra;

use BrianHenryIE\Strauss\Pipeline\Prefixer;
use BrianHenryIE\Strauss\TestCase;
use Composer\Factory;
use Composer\IO\NullIO;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Composer\Extra\StraussConfig
 */
class StraussConfigTest extends TestCase
{
    protected function getInput(string $cli): InputInterface
    {

        $inputDefinition = new \Symfony\Component\Console\Input\InputDefinition();
        $inputDefinition->addOption(
            new InputOption(
                'updateCallSites',
                null,
                InputArgument::OPTIONAL,
                'Should replacements also be performed in project files? true|list,of,paths|false'
            )
        );

        $argv = array_merge(['strauss'], array_filter(explode(' ', $cli)));
        $input = new ArgvInput($argv, $inputDefinition);

        return $input;
    }

    /**
     * With a full (at time of writing) config, test the getters.
     */
    public function testGetters(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "target_directory": "/target_directory/",
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "packages": [
        "pimple/pimple"
      ],
      "exclude_prefix_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      },
      "delete_vendor_files": false
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath = 'project/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertContains('pimple/pimple', $sut->getPackages());

        $this->assertEqualsPaths(
            $projectDir . '/target_directory/',
            $sut->getAbsoluteTargetDirectory()
        );

        $this->assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());

        $this->assertEqualsRN('BrianHenryIE_Strauss_', $sut->getClassmapPrefix());

        $this->assertArrayHasKey('clancats/container', $sut->getOverrideAutoload());

        $this->assertFalse($sut->isDeleteVendorFiles());
    }

    /**
     * Test how it handles an extra key.
     *
     * Turns out it just ignores it... good!
     */
    public function testExtraKey(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "target_directory": "/target_directory/",
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "packages": [
        "pimple/pimple"
      ],
      "exclude_prefix_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      },
      "delete_vendor_files": false,
      "unexpected_key": "here"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $exception = null;

        try {
            $sut = new StraussConfig($composer);
        } catch (\Exception $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    /**
     * straussconfig-test-3.json has no target_dir key.
     *
     * If no target_dir is specified, used "strauss/"
     */
    public function testDefaultTargetDir(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "exclude_prefix_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      },
      "delete_vendor_files": false,
      "unexpected_key": "here"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath = $projectDir .'/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute(
                $composerJsonPath
            )
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsPaths($projectDir . '/vendor-prefixed/', $sut->getAbsoluteTargetDirectory());
    }

    /**
     * When the namespace prefix isn't provided, use the PSR-4 autoload key name.
     */
    public function testDefaultNamespacePrefixFromAutoloaderPsr4(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },

  "autoload": {
    "psr-4": {
      "BrianHenryIE\\Strauss\\": "src"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());
    }

    /**
     * When the namespace prefix isn't provided, use the PSR-0 autoload key name.
     */
    public function testDefaultNamespacePrefixFromAutoloaderPsr0(): void
    {
        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },

  "autoload": {
    "psr-0": {
      "BrianHenryIE\\Strauss\\": "lib/"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());
    }

    /**
     * When the namespace prefix isn't provided, and there's no PSR-0 or PSR-4 autoloader to figure it from...
     *
     * brianhenryie/strauss-config-test
     */
    public function testDefaultNamespacePrefixWithNoAutoloader(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("Brianhenryie\\Strauss_Config_Test", $sut->getNamespacePrefix());
    }

    /**
     * When the classmap prefix isn't provided, use the PSR-4 autoload key name.
     */
    public function testDefaultClassmapPrefixFromAutoloaderPsr4(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },

  "autoload": {
    "psr-4": {
      "BrianHenryIE\\Strauss\\": "src"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("BrianHenryIE_Strauss_", $sut->getClassmapPrefix());
    }

    /**
     * When the classmap prefix isn't provided, use the PSR-0 autoload key name.
     */
    public function testDefaultClassmapPrefixFromAutoloaderPsr0(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },

  "autoload": {
    "psr-0": {
      "BrianHenryIE\\Strauss\\": "lib/"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("BrianHenryIE_Strauss_", $sut->getClassmapPrefix());
    }

    /**
     * When the classmap prefix isn't provided, and there's no PSR-0 or PSR-4 autoloader to figure it from...
     *
     * brianhenryie/strauss-config-test
     */
    public function testDefaultClassmapPrefixWithNoAutoloader(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  }

}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("Brianhenryie_Strauss_Config_Test", $sut->getClassmapPrefix());
    }

    /**
     * When Strauss config has packages specified, obviously use them.
     */
    public function testGetPackagesFromConfig(): void
    {
        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "target_directory": "/target_directory/",
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "packages": [
        "pimple/pimple"
      ],
      "exclude_prefix_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      },
      "delete_vendor_files": false
    }
  }
}

EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertContains('pimple/pimple', $sut->getPackages());
    }


    public function testGetOldSyntaxExcludePackagesFromPrefixing(): void
    {
        $this->markTestSkipped('Currently needs a reflectable property in the target object');

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "extra": {
    "strauss": {
      "exclude_prefix_packages": [
        "psr/container"
      ]
    }
  }
}

EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        $this->getFileSystem()->write($tmpfname, $composerJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $this->assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());
    }


    public function testGetExcludePackagesFromPrefixing(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "extra": {
    "strauss": {
        "exclude_from_prefix": {
            "packages": [
                "psr/container"
            ]
        }
    }
  }
}

EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());
    }


    public function testGetExcludeFilePatternsFromPrefixingDefault(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test"
}

EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        // Changed in v0.14.0.
        $this->assertNotContains('/^psr.*$/', $sut->getExcludeFilePatternsFromPrefixing());
    }

    /**
     * When excluding a package, the default file pattern exclusion was being forgotten.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/32
     */
    public function testGetExcludeFilePatternsFromPrefixingDefaultAfterExcludingPackages(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
    "extra": {
    "strauss": {
        "exclude_from_prefix": {
            "packages": ["yahnis-elsts/plugin-update-checker","erusev/parsedown"]
        }
    }
  }
}

EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        // Changed in v0.14.0.
        $this->assertNotContains('/^psr.*$/', $sut->getExcludeFilePatternsFromPrefixing());
    }

    /**
     * When Strauss config has no packages specified, use composer.json's require list.
     */
    public function testGetPackagesNoConfig(): void
    {

        $composerJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "exclude_prefix_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      },
      "delete_vendor_files": false,
      "unexpected_key": "here"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertContains('league/container', $sut->getPackages());
    }

    /**
     * For backwards compatibility, if a Mozart config is present, use it.
     */
    public function testMapMozartConfig(): void
    {

        $composerJson = <<<'EOD'
{
  "extra": {
    "mozart": {
      "dep_namespace": "My_Mozart_Config\\",
      "dep_directory": "/dep_directory/",
      "classmap_prefix": "My_Mozart_Config_",
      "classmap_directory": "/classmap_directory/",
      "packages": [
        "pimple/pimple"
      ],
      "exclude_packages": [
        "psr/container"
      ],
      "override_autoload": {
        "clancats/container": {
          "classmap": [
            "src/"
          ]
        }
      }
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertContains('pimple/pimple', $sut->getPackages());

        $this->assertEqualsPaths($projectDir . '/dep_directory', $sut->getAbsoluteTargetDirectory());

        $this->assertEqualsRN("My_Mozart_Config", $sut->getNamespacePrefix());

        $this->assertEqualsRN('My_Mozart_Config_', $sut->getClassmapPrefix());

        $this->assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());

        $this->assertArrayHasKey('clancats/container', $sut->getOverrideAutoload());

        // Mozart default was true.
        $this->assertTrue($sut->isDeleteVendorFiles());
    }

    /**
     * Since sometimes the namespace we're prefixing will already have a leading backslash, sometimes
     * the namespace_prefix will want its slash at the beginning, sometimes at the end.
     *
     * @see Prefixer::replaceNamespace()
     *
     * @covers \BrianHenryIE\Strauss\Composer\Extra\StraussConfig::getNamespacePrefix
     */
    public function testNamespacePrefixHasNoSlash(): void
    {

        $composerJson = <<<'EOD'
{
  "extra": {
    "mozart": {
      "dep_namespace": "My_Mozart_Config\\"
    }
  }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertEqualsRN("My_Mozart_Config", $sut->getNamespacePrefix());
    }

    public function testIncludeModifiedDateDefaultTrue(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\"
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertTrue($sut->isIncludeModifiedDate());
    }

    /**
     * "when I add "include_modified_date": false to the extra/strauss object it doesn't take any effect, the date is still added to the header."
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/35
     */
    public function testIncludeModifiedDate(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "include_modified_date": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertFalse($sut->isIncludeModifiedDate());
    }


    public function testIncludeAuthorDefaultTrue(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\"
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertTrue($sut->isIncludeAuthor());
    }


    public function testIncludeAuthorFalse(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "include_author": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertFalse($sut->isIncludeAuthor());
    }

    public function testDeleteVendorPackages(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertTrue($sut->isDeleteVendorPackages());
    }


    public function testUpdateCallSitesConfigTrue(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": true
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertNull($sut->getUpdateCallSites());
    }

    public function testUpdateCallSitesConfigFalse(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertIsArray($sut->getUpdateCallSites());
        $this->assertEmpty($sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesConfigList(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": [ "src", "templates" ]
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $this->assertIsArray($sut->getUpdateCallSites());
        $this->assertCount(2, $sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesCliTrue(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=true';
        $sut->updateFromCli($this->getInput($cli));

        $this->assertNull($sut->getUpdateCallSites());
    }

    public function testUpdateCallSitesCliFalse(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": true
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=false';
        $sut->updateFromCli($this->getInput($cli));

        $this->assertIsArray($sut->getUpdateCallSites());
        $this->assertEmpty($sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesCliList(): void
    {

        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true,
   "update_call_sites": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=src,templates';
        $sut->updateFromCli($this->getInput($cli));

        $this->assertIsArray($sut->getUpdateCallSites());
        $this->assertCount(2, $sut->getUpdateCallSites());
    }

    public function test_functions_prefix(): void
    {
        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "functions_prefix": "brianhenryie_strauss_"
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertEquals('brianhenryie_strauss_', $result);
    }

    public function test_functions_prefix_disabled(): void
    {
        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "functions_prefix": false
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertNull($result);
    }


    public function test_functions_not_set(): void
    {
        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "classmap_prefix": "brianhenryie_strauss_function_prefix_not_set_"
  }
 }
}
EOD;

        $projectDir = 'project';
        $composerJsonPath =  $projectDir . '/composer.json';
        $this->getFileSystem()->write($composerJsonPath, $composerJson);

        $composer = Factory::create(
            new NullIO(),
            $this->getFileSystem()->makeAbsolute($composerJsonPath)
        );

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertEquals('brianhenryie_strauss_function_prefix_not_set_', $result);
    }

    public function testConstantPrefixIsMappedFromComposerExtra(): void
    {
        $composerJson = <<<'EOD'
    {
     "extra":{
      "strauss": {
       "constant_prefix": "ST_TEST_"
      }
     }
    }
    EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $this->assertEquals('ST_TEST_', $sut->getConstantsPrefix());

        unlink($tmpfname);
    }

    public function test_optimize_autoloader_default_true(): void
    {
        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\"
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        try {
            file_put_contents($tmpfname, $composerJson);
            $composer = Factory::create(new NullIO(), $tmpfname);
            $sut = new StraussConfig($composer);
            $this->assertTrue($sut->isOptimizeAutoloader());
        } finally {
            unlink($tmpfname);
        }
    }

    public function test_optimize_autoloader_false(): void
    {
        $composerJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "optimize_autoloader": false
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        try {
            file_put_contents($tmpfname, $composerJson);
            $composer = Factory::create(new NullIO(), $tmpfname);
            $sut = new StraussConfig($composer);
            $this->assertFalse($sut->isOptimizeAutoloader());
        } finally {
            unlink($tmpfname);
        }
    }
}
