<?php
/**
 * Extend Composer's `AutoloadGenerator` to override the `getFileIdentifier()` method's hash to provide true uniqueness.
 *
 * `files` autoloaders' entries in `composer/autoload_static.php` and `composer/autoload_files.php` are given
 * a `fileIdentifier` that is a hash of the package name and path. It is used in
 * `$GLOBALS['__composer_autoload_files'][$fileIdentifier]` to ensure that the file is only `require`d once.
 *
 * It does not use the contents of the file in the hash. When two projects include the same package, that package's
 * files' identifiers will be the same in both.
 *
 * This subclass overrides the `getFileIdentifier()` method to include a unique string for the project, presumably
 * the `namespace_prefix`.
 *
 * {@see DumpAutoload::generatedPrefixedAutoloader()} calls {@see AutoloadGenerator::dump()} which eventually calls
 * {@see AutoloadGenerator::getFileIdentifier()} which is used in {@see AutoloadGenerator::getIncludeFilesFile()} to
 * generate `autoload_files.php` which is loaded in {@see AutoloadGenerator::getStaticFilesFile()} to create
 * `autoload_static.php`.
 */

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use Composer\Autoload\AutoloadGenerator;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

class ComposerAutoloadGenerator extends AutoloadGenerator
{
    /**
     * A string to include in the fileIdentifier hash to ensure it is unique across projects.
     */
    protected string $projectUniqueString;

    /**
     * Constructor
     *
     * @param string $projectUniqueString A string to include in the hash to ensure uniqueness across projects, probably `namespace_prefix`.
     * @param EventDispatcher $eventDispatcher Used to dispatch `optimize` script when {@see AutoloadGenerator::$runScripts} is true, which defaults to `false`.
     * @param IOInterface|null $io Used to write errors and warnings. Default `null`.
     */
    public function __construct(
        string $projectUniqueString,
        EventDispatcher $eventDispatcher,
        ?IOInterface $io = null
    ) {
        parent::__construct($eventDispatcher, $io);

        $this->projectUniqueString = $projectUniqueString;
    }

    /**
     * Get a unique id for the `files` autoload entry.
     *
     * `$path` here is `PackageInterface->getTargetDir()`.`PackageInterface::getAutoload()['files'][]`
     *
     * @override
     * @see AutoloadGenerator::getFileIdentifier()
     *
     * @param PackageInterface $package The package to get the file identifier for.
     * @param string $path Relative path from `vendor`.
     *
     * @return string
     */
    protected function getFileIdentifier(PackageInterface $package, string $path)
    {
        return hash('md5', $package->getName() . ':' . $path . ':' . $this->projectUniqueString);
    }
}
