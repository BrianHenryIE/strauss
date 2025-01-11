<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\TestCase;

class AliasesTest extends TestCase
{
    public function test_a(): void
    {

        $phpString = <<<'EOD'
<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit1dbefeff499a0676e84b3a5dceac7c83
{
    // ...
    public static function getLoader()
    {
        $filesToLoad = [];
        $requireFile = \Closure::bind(static function ($fileIdentifier, $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
                $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;

                require $file;
            }
        }, null, null);
        foreach ($filesToLoad as $fileIdentifier => $file) {
            $requireFile($fileIdentifier, $file);
        }

        return $loader;
    }
}
EOD;

        $expected = <<<'EOD'
<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit1dbefeff499a0676e84b3a5dceac7c83
{
    // ...
    public static function getLoader()
    {
        $filesToLoad = [];
        $requireFile = \Closure::bind(static function ($fileIdentifier, $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
                $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;

                require $file;
            }
        }, null, null);
        foreach ($filesToLoad as $fileIdentifier => $file) {
            $requireFile($fileIdentifier, $file);
        }
        
        require_once 'autoload_aliases.php';

        return $loader;
    }
}
EOD;

        $sut = new Aliases();

        $result = $sut->addAliasesFileToComposer($phpString);

        $this->assertEqualsRN($expected, $result);
    }
}
