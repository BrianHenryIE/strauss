<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\IntegrationTestCase;
use BrianHenryIE\Strauss\Types\NamespacedSymbol;

/**
 * @covers \BrianHenryIE\Strauss\Pipeline\Autoload\DumpAutoload
 */
class AutoloadedEnumeratorFeatureTest extends IntegrationTestCase
{
    /**
     * nikic/php-parser PhpParser\Node\Stmt\Namespace_ not prefixed, although PhpParser\Node namespace is.
     *
     * @see NamespacedSymbol::isAutoloaded() was calling ::isAutoloaded() on its NameSpaceSymbol and the plain object was not getting directly setIsAutoloaded(true).
     *
     * I think the `_` in the class names is an issue.
     */
    public function testNamespacePrefix(): void
    {
        $composerJsonString = <<<'EOD'
{
  "name": "brianhenryie/testdumpautoload",
  "require": {
    "nikic/php-parser": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "BrianHenryIE\\TestStrauss\\"
    }
  }
}
EOD;
        // vendor/nikic/php-parser/lib/PhpParser/Node/Stmt/Namespace_.php
        // vendor-prefixed/nikic/php-parser/lib/PhpParser/Node/Stmt/Namespace_.php

        $this->getFileSystem()->write($this->testsWorkingDir . '/composer.json', $composerJsonString);

        chdir($this->testsWorkingDir);

        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        assert($exitCode === 0, $output);

        exec('composer dump-autoload', $composerDumpAutoloadOutput, $composerDumpAutoloadExitCode);
        $this->assertEquals(0, $composerDumpAutoloadExitCode, implode(PHP_EOL, $composerDumpAutoloadOutput));

        $stmtNamespaceString = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor-prefixed/nikic/php-parser/lib/PhpParser/Node/Stmt/Namespace_.php');

        $this->assertStringContainsString('namespace BrianHenryIE\\TestStrauss\\PhpParser\\Node\\Stmt', $stmtNamespaceString);
    }
}
