<?php

/**
 * The class alias regression tests, when prefixing in place (`target_directory: vendor`): the files in `vendor` are
 * renamed, so the original names are only available through `vendor/composer/autoload_aliases.php`.
 *
 * Here the prefixed types are loaded by `vendor`'s own Composer autoloader, which is registered after the aliases
 * autoloader and so is ahead of it.
 *
 * @see \BrianHenryIE\Strauss\Pipeline\Aliases\ClassAliasesFeatureTest
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline\Aliases;

/**
 * @coversNothing
 */
class ClassAliasesInPlaceFeatureTest extends ClassAliasesFeatureTest
{
    protected function getStraussConfig(): array
    {
        return [
            'namespace_prefix' => 'Strauss\\Aliases\\',
            'target_directory' => 'vendor',
        ];
    }

    protected function assertOriginalNamesGone(): void
    {
        $greetingPhp = $this->getFileSystem()->read($this->testsWorkingDir . '/vendor/acme/greeter/src/Greeting.php');

        $this->assertStringContainsString('namespace Strauss\\Aliases\\Acme\\Greeter;', $greetingPhp);
    }
}
