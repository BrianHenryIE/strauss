<?php
/**
 * Extends ComposerPackage to return the typed Strauss config.
 */

namespace BrianHenryIE\Strauss\Composer;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use Composer\Factory;
use Composer\IO\NullIO;

class ProjectComposerPackage extends ComposerPackage
{
    protected string $author;

    protected string $vendorDirectory;

    /**
     * @param string $absolutePathFile
     * @param ?array{files?:array<string>,classmap?:array<string>,"psr-4"?:array<string,string|array<string>>} $overrideAutoload
     */
    public function __construct(string $absolutePathFile, ?array $overrideAutoload = null)
    {
        $composer = Factory::create(new NullIO(), $absolutePathFile, true);

        parent::__construct($composer, $overrideAutoload);

        $authors = $this->composer->getPackage()->getAuthors();
        if (empty($authors) || !isset($authors[0]['name'])) {
            $this->author = explode("/", $this->packageName, 2)[0];
        } else {
            $this->author = $authors[0]['name'];
        }

        $composerVendorDir = $this->composer->getConfig()->get('vendor-dir');
        $this->vendorDirectory = is_string($composerVendorDir) && !empty($composerVendorDir)
            ? ltrim(str_replace(dirname($absolutePathFile), '', $this->composer->getConfig()->get('vendor-dir')), '\\/')
            :  'vendor';
    }

    /**
     * @return StraussConfig
     * @throws \Exception
     */
    public function getStraussConfig(): StraussConfig
    {
        $config = new StraussConfig($this->composer);
        $config->setVendorDirectory($this->getVendorDirectory());
        return $config;
    }


    public function getAuthor(): string
    {
        return $this->author;
    }

    /**
     * Relative vendor directory with trailing slash.
     */
    public function getVendorDirectory(): string
    {
        return rtrim($this->vendorDirectory, '\\/') . '/';
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
                $values[] = $value;
            }
        );
        return $values;
    }
}
