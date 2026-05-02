<?php

namespace BrianHenryIE\Strauss;

use Composer\Util\Platform;

/**
 * @mixin \PHPUnit\Framework\TestCase
 * @mixin \PHPUnit\Framework\Assert
 */
trait MarkTestsSkippedTrait
{
    /**
     * Only skip tests locally for convenience. Never skip tests in CI.
     *
     * Use `::markTestIncomplete()` if necessary.
     * The `::markTestSkipped...()` functions in this trait broadly call `parent::markTestSkippedBH()` directly.
     */
    public function markTestSkippedBH(string $message = ''): void
    {
        if (getenv('GITHUB_ACTIONS') === 'true') {
            return;
        }

        $this->markTestSkipped($message);
    }

    protected function markTestSkippedUnlessSpecificallyInFilter(): void
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $function = $backtrace[1]['function'];
        $argvFilterIndex = array_search('--filter', $GLOBALS['argv']);
        $phpunitFilter = $GLOBALS['argv'][$argvFilterIndex + 1];
        if (!str_contains($phpunitFilter, $function)) {
            $this->markTestSkippedBH('slow');
        }
    }

    protected function markTestSkippedOnWindows(string $message = 'Skipped on Windows'): void
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped($message);
        }
    }

    public function markTestSkippedOnPhpVersionBelow(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '<', $message);
    }
    public function markTestSkippedOnPhpVersionEqualOrBelow(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '<=', $message);
    }
    public function markTestSkippedOnPhpVersionAbove(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '>', $message);
    }
    public function markTestSkippedOnPhpVersionEqualOrAbove(string $php_version, string $message = '')
    {
        $this->markTestSkippedOnPhpVersion($php_version, '>=', $message);
    }

    /**
     * Checks both the PHP version the tests are running under and the system PHP version.
     */
    public function markTestSkippedOnPhpVersion(string $php_version, string $operator, string $message = '')
    {
        exec('php -v', $output);
        preg_match('/PHP\s([\d\\\.]*)/', $output[0], $php_version_capture);
        $system_php_version = $php_version_capture[1];

        $testPhpVersionConstraintMatch = version_compare(phpversion(), $php_version, $operator);
        $systemPhpVersionConstraintMatch = version_compare($system_php_version, $php_version, $operator);

        if ($testPhpVersionConstraintMatch || $systemPhpVersionConstraintMatch) {
            empty($message)
                ? $this->markTestSkipped("Package specified for test cannot run on PHP $operator $php_version. Running PHPUnit with PHP " . phpversion() . ', on system PHP ' . $system_php_version)
                : $this->markTestSkipped($message);
        }
    }
}
