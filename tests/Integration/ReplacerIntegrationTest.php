<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Pipeline\Prefixer;

/**
 * @see \BrianHenryIE\Strauss\Console\Commands\ReplaceCommand
 * @coversNothing
 */
class ReplacerIntegrationTest extends IntegrationTestCase
{

    public function testReplaceNamespace(): void
    {
        $this->markTestSkipped('Ironically, this is failing because it downloads a newer psr/log but strauss has already loaded an older one.');

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/replacerintegrationtest",
  "require": {
    "google/apiclient": "v2.16.1"
  },
  "config": {
    "audit": {
      "block-insecure": false
    }
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_"
    },
    "google/apiclient-services": [
	  "Calendar"
	]
  },
  "scripts": {
    "pre-autoload-dump": [
      "@delete-unused-google-apis"
    ],
    "delete-unused-google-apis": [
        "Google\\Task\\Composer::cleanup"
    ]
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed/';
        $absoluteTargetDir = $workingDir . $relativeTargetDir;

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($absoluteTargetDir . '/google/apiclient/src/Client.php');

        self::assertStringContainsString('use BrianHenryIE\Strauss\Google\AccessToken\Revoke;', $updatedFile);
    }

    public function testReplaceClass(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "setasign/fpdf": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\",
      "classmap_prefix": "BrianHenryIE_Strauss_",
      "delete_vendor_files": false
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir .'/vendor-prefixed/' . 'setasign/fpdf/fpdf.php');

        self::assertStringContainsString('class BrianHenryIE_Strauss_FPDF', $updatedFile);
    }

    public function testSimpleReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "0.1"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\(.*)~" : "BrianHenryIE\\MyProject\\\\$1"
      }
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace BrianHenryIE\MyProject\WP_Logger;', $updatedFile);
    }

    public function testExaggeratedReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "0.1"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\WP_Logger~" : "AnotherProject\\Logger"
      }
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace AnotherProject\Logger;', $updatedFile);
    }

    public function testRidiculousReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "0.1"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "~BrianHenryIE\\\\([^\\\\]*)(\\\\.*)?~" : "AnotherProject\\\\$1\\\\MyProject$2"
      }
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/brianhenryie/bh-wp-logger/src/api/class-api.php');

        self::assertStringContainsString('namespace AnotherProject\WP_Logger\MyProject\API;', $updatedFile);
    }

    /**
     * After 0.25.0 namespaces not in psr-4 keys, i.e. only found by classmap scan, were not updated.
     */
    public function testSimpleReplacement(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strausstest",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "require": {
    "brianhenryie/bh-wp-logger": "0.1"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "exclude_from_copy": {
        "file_patterns": [
          "#[^/]*/[^/]*/tests/#"
        ]
      }
    }
  }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/brianhenryie/bh-wp-logger/src/interface-api-interface.php');

        $this->assertStringContainsString('namespace BrianHenryIE\\MyProject\\BrianHenryIE\\WP_Logger;', $updatedFile);
    }

    public function test_replace_classname_is_namespace_name(): void
    {
        $pdfHelpersComposer = <<<'JSON'
{
    "name": "brianhenryie/pdf-helpers",
    "autoload": {
        "psr-4": {
            "BrianHenryIE\\\\PdfHelpers\\\\": "src"
        }
    },
    "require": {
        "setasign/fpdi": "^2.3"
    }
}
JSON;

        $pdfHelpersPhp = <<<'PHP'
<?php

namespace BrianHenryIE\PdfHelpers;

use Mpdf\Mpdf;

class MpdfCrop extends Mpdf {

	public function clipRect( $x, $y, $width, $height ) {
		$this->pages[ $this->page ] .= $this->_setClippingPath( $x, $y, $width, $height );
	}

}
PHP;

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "repositories": {
    "brianhenryie/pdf-helpers": {
        "type": "path",
        "url": "../bh-pdf-helpers"
    }
  },
  "require": {
    "brianhenryie/pdf-helpers": "*"
  },
  "minimum-stability": "dev",
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\MyProject\\",
      "namespace_replacement_patterns": {
        "/BrianHenryIE\\\\(.*)/": "BrianHenryIE\\MyProject\\\\$1"
      }
    }
  }
}
EOD;

        mkdir($this->testsWorkingDir . '/bh-pdf-helpers/src', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . '/bh-pdf-helpers/composer.json', $pdfHelpersComposer);
        $this->getFileSystem()->write($this->testsWorkingDir . '/bh-pdf-helpers/src/MpdfCrop.php', $pdfHelpersPhp);

        mkdir($this->testsWorkingDir . '/project', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . '/project/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir.'/project/');

        exec('composer install');

        /**
         * `/Users/brianhenry/Sites/strauss/strauss/teststempdir/project/vendor-prefixed/brianhenryie/pdf-helpers/src/MpdfCrop.php`
         */
        $expectedTargetFilePath = $this->testsWorkingDir . '/project/vendor-prefixed/brianhenryie/pdf-helpers/src/MpdfCrop.php';

        $exitCode = $this->runStrauss($output, '--debug');
        $this->assertEquals(0, $exitCode, $output);

        $this->assertFileExistsInFileSystem($expectedTargetFilePath);
        $updatedFile = $this->getFileSystem()->read($expectedTargetFilePath);
        $this->assertStringContainsString('extends Mpdf', $updatedFile);
    }

    /**
     * @see Prefixer::replaceSingleClassnameInString()
     */
    public function test_replace_namespace_string(): void
    {
        $composerJsonString = <<<'JSON'
{
    "name": "brianhenryie/test-replace-namespace-string",
    "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
JSON;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed/';
        $absoluteTargetDir = $workingDir . '/' . $relativeTargetDir;

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($absoluteTargetDir . '/composer/autoload_real.php');

        $this->assertStringNotContainsString("if ('Composer\\Autoload\\ClassLoader' === \$class) {", $updatedFile);
        $this->assertStringContainsString("if ('BrianHenryIE\\Strauss\\Composer\\Autoload\\ClassLoader' === \$class) {", $updatedFile);
    }

    /**
     * @see Prefixer::replaceSingleClassnameInString()
     */
    public function test_replace_string(): void
    {
        $composerJsonString = <<<'JSON'
{
    "name": "brianhenryie/test-replace-string",
    "require": {
      "justinrainbow/json-schema": "6.8.0"
    },
    "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\Strauss\\"
    }
  }
}
JSON;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/justinrainbow/json-schema/src/JsonSchema/Constraints/Factory.php');

        $this->assertStringNotContainsString("'array' => 'JsonSchema\Constraints\CollectionConstraint'", $updatedFile);
        $this->assertStringContainsString("'array' => 'BrianHenryIE\Strauss\JsonSchema\Constraints\CollectionConstraint'", $updatedFile);
    }

    /**
     * Test an edge case where the class is surrounded by null character.
     *
     * @see AutoloadGenerator::getStaticFile()
     * @see vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php
     */
    public function test_ClassLoader(): void
    {
        $this->markTestSkippedUnlessSpecificallyInFilter();

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/exclude-from-prefix",
    "require": {
        "composer/composer": "2.9.7"
    },
    "provide": {
        "composer/ca-bundle": "*",
        "composer/class-map-generator": "*",
        "composer/metadata-minifier": "*",
        "composer/pcre": "*",
        "composer/semver": "*",
        "composer/spdx-licenses": "*",
        "composer/xdebug-handler": "*",
        "justinrainbow/json-schema": "*",
        "marc-mabe/php-enum": "*",
        "psr/container": "*",
        "psr/log": "*",
        "react/promise": "*",
        "seld/jsonlint": "*",
        "seld/phar-utils": "*",
        "seld/signal-handler": "*",
        "symfony/console": "*",
        "symfony/deprecation-contracts": "*",
        "symfony/filesystem": "*",
        "symfony/finder": "*",
        "symfony/polyfill-ctype": "*",
        "symfony/polyfill-intl-grapheme": "*",
        "symfony/polyfill-intl-normalizer": "*",
        "symfony/polyfill-mbstring": "*",
        "symfony/polyfill-php73": "*",
        "symfony/polyfill-php80": "*",
        "symfony/polyfill-php81": "*",
        "symfony/polyfill-php84": "*",
        "symfony/process": "*",
        "symfony/service-contracts": "*",
        "symfony/string": "*"
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor",
            "namespace_prefix": "BrianHenryIE\\Strauss\\"
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        $autoloadGeneratorString = file_get_contents($this->testsWorkingDir .'/vendor/composer/composer/src/Composer/Autoload/AutoloadGenerator.php');
        $this->assertStringNotContainsString('$prefix = "\\0Composer\Autoload\ClassLoader\\0";', $autoloadGeneratorString);
        $this->assertStringContainsString('$prefix = "\\0BrianHenryIE\Strauss\Composer\Autoload\ClassLoader\\0";', $autoloadGeneratorString);

        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/composer/src/Composer/Autoload/ClassLoader.php');
        $this->assertStringNotContainsString('namespace Composer\\Autoload;', $phpString);
        $this->assertStringContainsString('namespace BrianHenryIE\\Strauss\\Composer\\Autoload;', $phpString);

        // vendor/composer/composer/src/Composer/Autoload/ClassMapGenerator.php
        $phpString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/composer/composer/src/Composer/Autoload/ClassMapGenerator.php');
        $this->assertStringContainsString('namespace BrianHenryIE\\Strauss\\Composer\\Autoload;', $phpString);
        $this->assertStringNotContainsString('namespace Composer\\Autoload;', $phpString);
    }
    /**
     *
     */
    public function test_functions_replace_react_promise(): void
    {
        $this->markTestSkippedUnlessSpecificallyInFilter();

        $composerJsonString = <<<'EOD'
{
    "name": "strauss/react-promise-functions",
    "require": {
        "react/promise": "3.3.0"
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor",
            "namespace_prefix": "BrianHenryIE\\Strauss\\"
        }
    }
}
EOD;

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        // vendor/react/promise/src/Internal/RejectedPromise.php
        $autoloadGeneratorString = file_get_contents($this->testsWorkingDir .'/vendor/react/promise/src/Internal/RejectedPromise.php');
        $this->assertStringNotContainsString('use function React\Promise\resolve;', $autoloadGeneratorString);
        $this->assertStringContainsString('use function BrianHenryIE\Strauss\React\Promise\resolve;', $autoloadGeneratorString);
    }
}
