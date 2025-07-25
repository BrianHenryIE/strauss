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
    public function testGetters()
    {

        $composerExtraStraussJson = <<<'EOD'
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

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('pimple/pimple', $sut->getPackages());

        self::assertEqualsRN(getcwd() . '/target_directory/', $sut->getTargetDirectory());

        self::assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());

        self::assertEqualsRN('BrianHenryIE_Strauss_', $sut->getClassmapPrefix());

        self::assertArrayHasKey('clancats/container', $sut->getOverrideAutoload());

        self::assertFalse($sut->isDeleteVendorFiles());
    }

    /**
     * Test how it handles an extra key.
     *
     * Turns out it just ignores it... good!
     */
    public function testExtraKey()
    {

        $composerExtraStraussJson = <<<'EOD'
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

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $exception = null;

        try {
            $sut = new StraussConfig($composer);
        } catch (\Exception $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }

    /**
     * straussconfig-test-3.json has no target_dir key.
     *
     * If no target_dir is specified, used "strauss/"
     */
    public function testDefaultTargetDir()
    {

        $composerExtraStraussJson = <<<'EOD'
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

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN(getcwd() . '/vendor-prefixed/', $sut->getTargetDirectory());
    }

    /**
     * When the namespace prefix isn't provided, use the PSR-4 autoload key name.
     */
    public function testDefaultNamespacePrefixFromAutoloaderPsr4()
    {

        $composerExtraStraussJson = <<<'EOD'
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

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());
    }

    /**
     * When the namespace prefix isn't provided, use the PSR-0 autoload key name.
     */
    public function testDefaultNamespacePrefixFromAutoloaderPsr0()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("BrianHenryIE\\Strauss", $sut->getNamespacePrefix());
    }

    /**
     * When the namespace prefix isn't provided, and there's no PSR-0 or PSR-4 autoloader to figure it from...
     *
     * brianhenryie/strauss-config-test
     */
    public function testDefaultNamespacePrefixWithNoAutoloader()
    {

        $composerExtraStraussJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  }
}
EOD;

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("Brianhenryie\\Strauss_Config_Test", $sut->getNamespacePrefix());
    }

    /**
     * When the classmap prefix isn't provided, use the PSR-4 autoload key name.
     */
    public function testDefaultClassmapPrefixFromAutoloaderPsr4()
    {

        $composerExtraStraussJson = <<<'EOD'
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

        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("BrianHenryIE_Strauss_", $sut->getClassmapPrefix());
    }

    /**
     * When the classmap prefix isn't provided, use the PSR-0 autoload key name.
     */
    public function testDefaultClassmapPrefixFromAutoloaderPsr0()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);


        $sut = new StraussConfig($composer);

        self::assertEqualsRN("BrianHenryIE_Strauss_", $sut->getClassmapPrefix());
    }

    /**
     * When the classmap prefix isn't provided, and there's no PSR-0 or PSR-4 autoloader to figure it from...
     *
     * brianhenryie/strauss-config-test
     */
    public function testDefaultClassmapPrefixWithNoAutoloader()
    {

        $composerExtraStraussJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test",
  "require": {
    "league/container": "*"
  }

}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("Brianhenryie_Strauss_Config_Test", $sut->getClassmapPrefix());
    }

    /**
     * When Strauss config has packages specified, obviously use them.
     */
    public function testGetPackagesFromConfig()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('pimple/pimple', $sut->getPackages());
    }


    public function testGetOldSyntaxExcludePackagesFromPrefixing()
    {
        $this->markTestSkipped('Currently needs a reflectable property in the target object');

        $composerExtraStraussJson = <<<'EOD'
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
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());
    }


    public function testGetExcludePackagesFromPrefixing()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());
    }


    public function testGetExcludeFilePatternsFromPrefixingDefault()
    {

        $composerExtraStraussJson = <<<'EOD'
{
  "name": "brianhenryie/strauss-config-test"
}

EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        // Changed in v0.14.0.
        self::assertNotContains('/^psr.*$/', $sut->getExcludeFilePatternsFromPrefixing());
    }

    /**
     * When excluding a package, the default file pattern exclusion was being forgotten.
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/32
     */
    public function testGetExcludeFilePatternsFromPrefixingDefaultAfterExcludingPackages()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        // Changed in v0.14.0.
        self::assertNotContains('/^psr.*$/', $sut->getExcludeFilePatternsFromPrefixing());
    }

    /**
     * When Strauss config has no packages specified, use composer.json's require list.
     */
    public function testGetPackagesNoConfig()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('league/container', $sut->getPackages());
    }

    /**
     * For backwards compatibility, if a Mozart config is present, use it.
     */
    public function testMapMozartConfig()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertContains('pimple/pimple', $sut->getPackages());

        self::assertEqualsRN(getcwd() . '/dep_directory/', $sut->getTargetDirectory());

        self::assertEqualsRN("My_Mozart_Config", $sut->getNamespacePrefix());

        self::assertEqualsRN('My_Mozart_Config_', $sut->getClassmapPrefix());

        self::assertContains('psr/container', $sut->getExcludePackagesFromPrefixing());

        self::assertArrayHasKey('clancats/container', $sut->getOverrideAutoload());

        // Mozart default was true.
        self::assertTrue($sut->isDeleteVendorFiles());
    }

    /**
     * Since sometimes the namespace we're prefixing will already have a leading backslash, sometimes
     * the namespace_prefix will want its slash at the beginning, sometimes at the end.
     *
     * @see Prefixer::replaceNamespace()
     *
     * @covers \BrianHenryIE\Strauss\Composer\Extra\StraussConfig::getNamespacePrefix
     */
    public function testNamespacePrefixHasNoSlash()
    {

        $composerExtraStraussJson = <<<'EOD'
{
  "extra": {
    "mozart": {
      "dep_namespace": "My_Mozart_Config\\"
    }
  }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertEqualsRN("My_Mozart_Config", $sut->getNamespacePrefix());
    }

    public function testIncludeModifiedDateDefaultTrue()
    {

        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\"
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertTrue($sut->isIncludeModifiedDate());
    }

    /**
     * "when I add "include_modified_date": false to the extra/strauss object it doesn't take any effect, the date is still added to the header."
     *
     * @see https://github.com/BrianHenryIE/strauss/issues/35
     */
    public function testIncludeModifiedDate()
    {

        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "include_modified_date": false
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertFalse($sut->isIncludeModifiedDate());
    }


    public function testIncludeAuthorDefaultTrue()
    {

        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\"
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertTrue($sut->isIncludeAuthor());
    }


    public function testIncludeAuthorFalse()
    {

        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "include_author": false
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertFalse($sut->isIncludeAuthor());
    }

    public function testDeleteVendorPackages()
    {

        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "delete_vendor_packages": true
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertTrue($sut->isDeleteVendorPackages());
    }


    public function testUpdateCallSitesConfigTrue()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertNull($sut->getUpdateCallSites());
    }

    public function testUpdateCallSitesConfigFalse()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertIsArray($sut->getUpdateCallSites());
        self::assertEmpty($sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesConfigList()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        self::assertIsArray($sut->getUpdateCallSites());
        self::assertCount(2, $sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesCliTrue()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);


        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=true';
        $sut->updateFromCli($this->getInput($cli));

        self::assertNull($sut->getUpdateCallSites());
    }

    public function testUpdateCallSitesCliFalse()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=false';
        $sut->updateFromCli($this->getInput($cli));

        self::assertIsArray($sut->getUpdateCallSites());
        self::assertEmpty($sut->getUpdateCallSites());
    }


    public function testUpdateCallSitesCliList()
    {

        $composerExtraStraussJson = <<<'EOD'
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
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $cli = '--updateCallSites=src,templates';
        $sut->updateFromCli($this->getInput($cli));

        self::assertIsArray($sut->getUpdateCallSites());
        self::assertCount(2, $sut->getUpdateCallSites());
    }

    public function test_functions_prefix(): void
    {
        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "functions_prefix": "brianhenryie_strauss_"
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertEquals('brianhenryie_strauss_', $result);
    }

    public function test_functions_prefix_disabled(): void
    {
        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "functions_prefix": false
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertNull($result);
    }


    public function test_functions_not_set(): void
    {
        $composerExtraStraussJson = <<<'EOD'
{
 "extra":{
  "strauss": {
   "namespace_prefix": "BrianHenryIE\\Strauss\\",
   "classmap_prefix": "brianhenryie_strauss_function_prefix_not_set_"
  }
 }
}
EOD;
        $tmpfname = tempnam(sys_get_temp_dir(), 'strauss-test-');
        file_put_contents($tmpfname, $composerExtraStraussJson);

        $composer = Factory::create(new NullIO(), $tmpfname);

        $sut = new StraussConfig($composer);

        $result = $sut->getFunctionsPrefix();

        $this->assertEquals('brianhenryie_strauss_function_prefix_not_set_', $result);
    }
}
