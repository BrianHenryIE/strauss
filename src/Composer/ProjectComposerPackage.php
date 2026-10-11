<?php
/**
 * Extends ComposerPackage to return the typed Strauss config.
 *
 * @package brianhenryie/strauss
 */

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use Composer\Factory;
use Composer\IO\NullIO;

/**
 * Loads the project's `composer.json` through Composer's {@see Factory} rather than from an already-installed package.
 * It records the first author's name (falling back to the vendor part of the package name) and the vendor directory
 * relative to the project, and builds a {@see StraussConfig} from the `extra.strauss` key on request.
 */
class ProjectComposerPackage extends ComposerPackage
{
    protected string $author;

    protected string $relativeVendorDirectory;

    /**
     * @param string                                                                                           $absolutePathFile
     * @param ?array{files?:array<string>,classmap?:array<string>,"psr-4"?:array<string,string|array<string>>} $overrideAutoload
     */
    public function __construct(string $absolutePathFile, ?array $overrideAutoload = null)
    {
        $composer = (new Factory())->createComposer(
            new NullIO(),
            $absolutePathFile,
            true
        );

        parent::__construct($composer, $overrideAutoload);

        $authors = $this->composer->getPackage()->getAuthors();
        if (empty($authors) || !isset($authors[0]['name'])) {
            $this->author = explode("/", $this->packageName, 2)[0];
        } else {
            $this->author = $authors[0]['name'];
        }

        /**
 * @var ?string $projectVendorAbsoluteDirectoryPath
*/
        $projectVendorAbsoluteDirectoryPath = $this->composer->getConfig()->get('vendor-dir');
        $this->relativeVendorDirectory = is_string($projectVendorAbsoluteDirectoryPath) && !empty($projectVendorAbsoluteDirectoryPath)
            ? ltrim(
                str_replace(
                    FileSystem::normalizeDirSeparator(dirname($absolutePathFile)),
                    '',
                    FileSystem::normalizeDirSeparator($projectVendorAbsoluteDirectoryPath)
                ),
                '\\/'
            )
            :  'vendor';
    }

    /**
     * @return StraussConfig
     * @throws \Exception
     */
    public function getStraussConfig(): StraussConfig
    {
        $config = new StraussConfig($this->composer);
        $config->setRelativeVendorDirectory($this->getRelativeVendorDirectory());
        return $config;
    }


    public function getAuthor(): string
    {
        return $this->author;
    }

    /**
     * Relative vendor directory with trailing slash.
     *
     * No leading or trailing slash
     */
    public function getRelativeVendorDirectory(): string
    {
        return trim($this->relativeVendorDirectory, '\\/');
    }

    /**
     * Get all values in the autoload key as a flattened array.
     *
     * @return string[]
     */
    public function getFlatAutoloadKey(): array
    {
        $autoload = $this->getAutoload();
        $values = [];
        array_walk_recursive(
            $autoload,
            function ($value, $key) use (&$values) {
                if (is_string($value)) {
                    $values[] = $value;
                }
            }
        );
        return $values;
    }
}
