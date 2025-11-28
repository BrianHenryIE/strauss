<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ReplacerIntegrationTest
 * @package BrianHenryIE\Strauss\Tests\Integration
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
    "google/apiclient": "*"
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $workingDir = $this->testsWorkingDir;
        $relativeTargetDir = 'vendor-prefixed/';
        $absoluteTargetDir = $workingDir . $relativeTargetDir;

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($absoluteTargetDir . 'google/apiclient/src/Client.php');

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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir .'vendor-prefixed/' . 'setasign/fpdf/fpdf.php');

        self::assertStringContainsString('class BrianHenryIE_Strauss_FPDF', $updatedFile);
    }

    public function testSimpleReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace BrianHenryIE\MyProject\WP_Logger;', $updatedFile);
    }

    public function testExaggeratedReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/class-logger.php');

        self::assertStringContainsString('namespace AnotherProject\Logger;', $updatedFile);
    }

    public function testRidiculousReplacementPatterns(): void
    {

        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/strauss",
  "require": {
    "brianhenryie/bh-wp-logger": "*"
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/api/class-api.php');

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
    "brianhenryie/bh-wp-logger": "*"
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

        file_put_contents($this->testsWorkingDir . 'composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'vendor-prefixed/brianhenryie/bh-wp-logger/src/interface-api-interface.php');

        $this->assertStringContainsString('namespace BrianHenryIE\\MyProject\\BrianHenryIE\\WP_Logger;', $updatedFile);
    }

    public function test_replace_classname_is_namespace_name(): void
    {
        $pdfHelpersComposer = <<<'JSON'
{
    "name": "brianhenryie/pdf-helpers",
    "autoload": {
        "psr-4": {
            "BrianHenryIE\\PdfHelpers\\": "src"
        }
    },
    "require": {
        "mpdf/mpdf": "*",
        "setasign/fpdf": "^1.8",
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

        mkdir($this->testsWorkingDir . 'bh-pdf-helpers/src', 0777, true);
        file_put_contents($this->testsWorkingDir . 'bh-pdf-helpers/composer.json', $pdfHelpersComposer);
        file_put_contents($this->testsWorkingDir . 'bh-pdf-helpers/src/MpdfCrop.php', $pdfHelpersPhp);

        mkdir($this->testsWorkingDir . 'project', 0777, true);
        file_put_contents($this->testsWorkingDir . 'project/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir.'project/');

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $updatedFile = file_get_contents($this->testsWorkingDir . 'project/vendor-prefixed/brianhenryie/pdf-helpers/src/MpdfCrop.php');

        self::assertStringContainsString('extends Mpdf', $updatedFile);
    }
}
