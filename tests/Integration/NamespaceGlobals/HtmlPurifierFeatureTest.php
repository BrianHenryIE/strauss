<?php

/**
 * `strauss namespace-globals` on HTML Purifier, a large PEAR-style library, then its own SimpleTest suite.
 *
 * `HTMLPurifier_AttrDef_CSS_Color` in `library/HTMLPurifier/AttrDef/CSS/Color.php` becomes
 * `Company\Project\AttrDef\CSS\Color`. Its tests stay global: `tests/path2class.func.php` derives the test class names
 * from their paths. Its tests are `export-ignore`d from the Composer dist, so it is cloned with git.
 *
 * What Strauss cannot do, and is done here by hand, as a user would:
 * - code which builds class names at runtime from parts which are not literals: module names containing `_`
 *   (`Tidy_Transitional`) and reserved short names (`List`, named `HTMLPurifierList`);
 * - the test suite's conventions which derive class names from strings: mock names, the class tested by
 *   `VarParserHarness`, and `HTMLPurifier_Encoder` in two `.htmlt` fixtures, which are not PHP files;
 * - the test autoloader, which `require`s blindly, so fails when the library checks `class_exists()`;
 * - the autoloader (as the command advises) and the serialized config schema, regenerated with HTML Purifier's own
 *   script.
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Integration\NamespaceGlobals;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class HtmlPurifierFeatureTest extends IntegrationTestCase
{
    protected const TAG = 'v4.19.1';

    /**
     * `simpletest/simpletest` is required as `dev-master`.
     */
    protected const SIMPLETEST_COMMIT = '11ff153526649b9da4264b57966e9db673072928';

    protected string $projectDir;

    /**
     * Replaces text in a file of the project, failing when it is not found, e.g. after an upstream change.
     *
     * @param string $relativePath The file, relative to the project.
     * @param string $search       The text to find; must occur exactly once.
     * @param string $replace      Its replacement.
     */
    protected function patch(string $relativePath, string $search, string $replace): void
    {
        $path = $this->projectDir . '/' . $relativePath;
        $contents = $this->getFileSystem()->read($path);

        $this->assertSame(1, substr_count($contents, $search), "Expected to patch {$relativePath} once: {$search}");

        $this->getFileSystem()->write($path, str_replace($search, $replace, $contents));
    }

    /**
     * @param string $command The shell command, run in the project.
     */
    protected function runInProject(string $command): string
    {
        exec($command . ' 2>&1', $output, $exitCode);
        $outputString = implode(PHP_EOL, $output);
        $this->assertEquals(0, $exitCode, $command . PHP_EOL . $outputString);
        return $outputString;
    }

    public function test_html_purifier_tests_pass_after_namespacing(): void
    {
        $this->projectDir = $this->testsWorkingDir . '/htmlpurifier';
        $php = escapeshellarg(PHP_BINARY);

        $this->runInProject(
            'git clone --quiet --depth 1 --branch ' . self::TAG . ' https://github.com/ezyang/htmlpurifier.git '
            . escapeshellarg($this->projectDir)
        );

        chdir($this->projectDir);

        $composerJson = json_decode($this->getFileSystem()->read($this->projectDir . '/composer.json'), true);
        $composerJson['require-dev']['simpletest/simpletest'] = 'dev-master#' . self::SIMPLETEST_COMMIT;
        $this->getFileSystem()->write($this->projectDir . '/composer.json', (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->runInProject('composer install --no-interaction');
        copy($this->projectDir . '/test-settings.sample.php', $this->projectDir . '/test-settings.php');

        $exitCode = $this->runStrauss(
            $output,
            'namespace-globals --namespace=Company\\Project --strip-prefix=HTMLPurifier_ --paths=library --update-call-sites=tests,extras,maintenance,smoketests'
        );
        $this->assertEquals(0, $exitCode, $output);

        $colorPhp = $this->getFileSystem()->read($this->projectDir . '/library/HTMLPurifier/AttrDef/CSS/Color.php');
        $this->assertStringContainsString('namespace Company\\Project\\AttrDef\\CSS;', $colorPhp);
        $this->assertStringContainsString('class Color extends \\Company\\Project\\AttrDef', $colorPhp);

        $listPhp = $this->getFileSystem()->read($this->projectDir . '/library/HTMLPurifier/HTMLModule/List.php');
        $this->assertStringContainsString('class HTMLPurifierList extends \\Company\\Project\\HTMLModule', $listPhp);

        // Module names, e.g. `Tidy_Transitional` and `List`, are joined to the namespace at runtime.
        $this->patch(
            'library/HTMLPurifier/HTMLModuleManager.php',
            <<<'EOD'
                                $module = $prefix . $original_module;
                                if (class_exists($module)) {
                                    $ok = true;
                                    break;
                                }
                EOD,
            <<<'EOD'
                                $module = $prefix . str_replace('_', '\\', $original_module);
                                if (class_exists($module)) {
                                    $ok = true;
                                    break;
                                }
                                $module = $prefix . 'HTMLPurifier' . $original_module;
                                if (class_exists($module)) {
                                    $ok = true;
                                    break;
                                }
                EOD
        );

        // The filter's global function moves with it, and its callable string is updated.
        $extractStyleBlocksPhp = $this->getFileSystem()->read($this->projectDir . '/library/HTMLPurifier/Filter/ExtractStyleBlocks.php');
        $this->assertStringContainsString('namespace Company\\Project\\Filter;', $extractStyleBlocksPhp);
        $this->assertStringContainsString("set_error_handler('Company\\\\Project\\\\Filter\\\\htmlpurifier_filter_extractstyleblocks_muteerrorhandler')", $extractStyleBlocksPhp);

        // The tests' conventions.
        $this->patch(
            'tests/generate_mock_once.func.php',
            "    \$mock_name = \$name . 'Mock';",
            "    \$mock_name = str_replace(array('Company\\\\Project\\\\', '\\\\'), array('HTMLPurifier_', '_'), \$name) . 'Mock';"
        );
        $this->patch(
            'tests/HTMLPurifier/VarParserHarness.php',
            '        $class = substr(get_class($this), 0, -4);',
            "        \$class = str_replace('_', '\\\\', preg_replace('/^HTMLPurifier_/', 'Company\\\\Project\\\\', substr(get_class(\$this), 0, -4)));"
        );
        $this->patch(
            'tests/common.php',
            "    require str_replace('_', '/', \$class) . '.php';",
            "    \$file = str_replace('_', '/', \$class) . '.php';\n    if (false === stream_resolve_include_path(\$file)) {\n        return false;\n    }\n    require \$file;"
        );
        foreach (glob($this->projectDir . '/tests/HTMLPurifier/HTMLT/*.htmlt') ?: [] as $htmltPath) {
            $htmlt = $this->getFileSystem()->read($htmltPath);
            $this->getFileSystem()->write($htmltPath, str_replace('HTMLPurifier_Encoder', '\\Company\\Project\\Encoder', $htmlt));
        }

        // As the command advises: the moved classes are no longer found by their PSR-0 entry.
        $composerJson = json_decode($this->getFileSystem()->read($this->projectDir . '/composer.json'), true);
        $composerJson['autoload'] = ['classmap' => ['library/'], 'files' => $composerJson['autoload']['files']];
        $this->getFileSystem()->write($this->projectDir . '/composer.json', (string) json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->runInProject('composer dump-autoload');

        // The schema is cached serialized, with the class names.
        $this->runInProject($php . ' -d auto_prepend_file=vendor/autoload.php maintenance/generate-schema-cache.php');

        $testOutput = $this->runInProject($php . ' tests/index.php');

        $this->assertStringContainsString('Test cases run: 226/226', $testOutput);
        $this->assertStringContainsString('Failures: 0, Exceptions: 0', $testOutput);
    }
}
