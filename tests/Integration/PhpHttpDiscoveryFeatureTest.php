<?php
/**
 * php-http/discovery's candidate class strings must be prefixed even though the candidates are not in its dependency
 * tree.
 *
 * php-http/discovery finds an installed PSR-17/PSR-18 implementation by testing a hardcoded list of class name
 * strings, e.g. `'Nyholm\Psr7\Factory\Psr17Factory'` in `CommonPsr17ClassesStrategy`. It does not `require` any of
 * them; they are packages which `provide` `psr/http-factory-implementation`. When symbol replacement in strings is
 * limited to the symbols defined in a file's package's dependency tree, those strings are left unprefixed, and at
 * runtime discovery fails:
 *
 * `DiscoveryFailedException: No valid candidate found using strategy "...\CommonPsr17ClassesStrategy". We tested the
 * following candidates: Phalcon\Http\Message\ResponseFactory, Nyholm\Psr7\Factory\Psr17Factory, ...`
 *
 * @see \BrianHenryIE\Strauss\Pipeline\ChangePlanner::filterToSymbolsAvailableToFile()
 *
 * @see https://github.com/wielebenwir/commonsbooking/pull/2318#issuecomment-6058612135
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss;

/**
 * @coversNothing
 */
class PhpHttpDiscoveryFeatureTest extends IntegrationTestCase
{
    public function test_discovery_candidate_strings_are_prefixed(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "strauss/php-http-discovery",
  "require": {
    "php-http/curl-client": "^2.3",
    "nyholm/psr7": "^1.8"
  },
  "config": {
    "allow-plugins": {
      "php-http/discovery": false
    }
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "Strauss\\PhpHttpDiscovery\\"
    }
  }
}
EOD;

        chdir($this->testsWorkingDir);

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        exec('composer install');

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $nyholmPsr17FactoryString = $this->getFileSystem()->read(
            $this->testsWorkingDir . '/vendor-prefixed/nyholm/psr7/src/Factory/Psr17Factory.php'
        );
        $this->assertStringContainsString('namespace Strauss\PhpHttpDiscovery\Nyholm\Psr7\Factory;', $nyholmPsr17FactoryString);

        $commonPsr17ClassesStrategyString = $this->getFileSystem()->read(
            $this->testsWorkingDir . '/vendor-prefixed/php-http/discovery/src/Strategy/CommonPsr17ClassesStrategy.php'
        );
        $this->assertStringContainsString(
            "'Strauss\PhpHttpDiscovery\Nyholm\Psr7\Factory\Psr17Factory'",
            $commonPsr17ClassesStrategyString
        );
        $this->assertStringNotContainsString(
            "'Nyholm\Psr7\Factory\Psr17Factory'",
            $commonPsr17ClassesStrategyString
        );
    }
}
