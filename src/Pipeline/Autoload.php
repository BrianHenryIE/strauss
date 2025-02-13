<?php
/**
 * Generate an `autoload.php` file in the root of the target directory.
 *
 * @see \Composer\Autoload\ClassMapGenerator
 */

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Helpers\FileSystem;
use Composer\ClassMapGenerator\ClassMapGenerator;
use League\Flysystem\StorageAttributes;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Autoload
{
    use LoggerAwareTrait;

    protected FileSystem $filesystem;

    protected string $workingDir;

    protected StraussConfig $config;

    /**
     * The files autoloaders of packages that have been copied by Strauss.
     * Keyed by package path.
     *
     * @var array<string, array<string>> $discoveredFilesAutoloaders Array of packagePath => array of relativeFilePaths.
     */
    protected array $discoveredFilesAutoloaders;

    /**
     * Autoload constructor.
     *
     * @param StraussConfig $config
     * @param string $workingDir
     * @param array<string, array<string>> $discoveredFilesAutoloaders
     */
    public function __construct(
        StraussConfig $config,
        string $workingDir,
        array $discoveredFilesAutoloaders,
        Filesystem $filesystem,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->workingDir = $workingDir;
        $this->discoveredFilesAutoloaders = $discoveredFilesAutoloaders;

        $this->filesystem = $filesystem;
        $this->setLogger($logger ?? new NullLogger());
    }

    public function generate(): void
    {
        // Use native Composer's `autoload.php` etc. when the target directory is the vendor directory.
        if ($this->config->getTargetDirectory() === $this->config->getVendorDirectory()) {
            $this->logger->debug('Strauss is not generating autoload.php because the target directory is the vendor directory.');
            return;
        }

        if (! $this->config->isClassmapOutput()) {
            return;
        }

        $this->generateClassmap();

        $this->generateFilesAutoloader();

        $this->generateAutoloadPhp();
    }

    /**
     * Uses Composer's `ClassMapGenerator::createMap()` to scan the directories for classes and generate the map.
     *
     * createMap() returns the full local path, so we then replace the root of the path with a variable.
     *
     * @see ClassMapGenerator::dump()
     *
     */
    protected function generateClassmap(): void
    {

        // Hyphen used to match WordPress Coding Standards.
        $output_filename = "autoload-classmap.php";

        $targetDirectory = ltrim( sprintf(
            '%s/%s/',
            trim($this->workingDir, '/\\'),
            trim($this->config->getTargetDirectory(), '/\\')
        ), '/\\');

        $targetDir = $this->config->isDryRun()
                ? 'mem://' . ltrim($targetDirectory, '/')
                : $targetDirectory;

        $paths =
            array_map(
                function ($file) {
                    return $this->config->isDryRun()
                        ? new \SplFileInfo('mem://'.$file->path())
                        : new \SplFileInfo('/'.$file->path());
                },
                array_filter(
                    $this->filesystem->listContents($targetDir, true)->toArray(),
                    fn(StorageAttributes $file) => $file->isFile() && in_array(substr($file->path(), -3), ['php', 'inc', '.hh'])
                )
            );

        $dirMap = ClassMapGenerator::createMap($paths);

        array_walk(
            $dirMap,
            function (&$filepath, $_class) use ($targetDir) {
                $filepath = sprintf(
                    "\$strauss_src . '/%s'",
                    str_replace($targetDir, '', $filepath)
                );
            }
        );

        ob_start();

        echo "<?php\n\n";
        echo "// {$output_filename} @generated by Strauss\n\n";
        echo "\$strauss_src = dirname(__FILE__);\n\n";
        echo "return array(\n";
        foreach ($dirMap as $class => $file) {
            // Always use `/` in paths.
            $file = str_replace(DIRECTORY_SEPARATOR, '/', $file);
            echo "   '{$class}' => {$file},\n";
        }
        echo ");";

        $this->logger->info('Writing classmap to ' . basename($targetDirectory) . '/' . $output_filename);
        $this->filesystem->write(
            $targetDirectory . $output_filename,
            ob_get_clean()
        );
    }

    protected function generateFilesAutoloader(): void
    {

        // Hyphen used to match WordPress Coding Standards.
        $outputFilename = "autoload-files.php";

        $filesAutoloaders = $this->discoveredFilesAutoloaders;

        if (empty($filesAutoloaders)) {
            return;
        }

        $targetDirectory = getcwd()  // TODO: Why is this not $this->workingDir?
            . DIRECTORY_SEPARATOR
            . ltrim($this->config->getTargetDirectory(), '/\\');

        ob_start();

        echo "<?php\n\n";
        echo "// {$outputFilename} @generated by Strauss\n";
        echo "// @see https://github.com/BrianHenryIE/strauss/\n\n";

        foreach ($filesAutoloaders as $packagePath => $files) {
            foreach ($files as $file) {
                $filepath = DIRECTORY_SEPARATOR . $packagePath . DIRECTORY_SEPARATOR . $file;
                // TODO: is it bad that this is not using the Fly FileSystem?
                $filePathinfo = pathinfo(__DIR__ . $filepath); // TODO: Why is this not $this->workingDir?
                if (!isset($filePathinfo['extension']) || 'php' !== $filePathinfo['extension']) {
                    continue;
                }
                // Always use `/` in paths.
                $filepath = str_replace(DIRECTORY_SEPARATOR, '/', $filepath);
                echo "require_once __DIR__ . '{$filepath}';\n";
            }
        }

        $this->logger->info('Writing files autoloader to ' . basename($targetDirectory) . '/' . $outputFilename);
        $this->filesystem->write(
            $targetDirectory . $outputFilename,
            ob_get_clean()
        );
    }

    protected function generateAutoloadPhp(): void
    {

        $autoloadPhp = <<<'EOD'
<?php
// autoload.php @generated by Strauss

if ( file_exists( __DIR__ . '/autoload-classmap.php' ) ) {
    $class_map = include __DIR__ . '/autoload-classmap.php';
    if ( is_array( $class_map ) ) {
        spl_autoload_register(
            function ( $classname ) use ( $class_map ) {
                if ( isset( $class_map[ $classname ] ) && file_exists( $class_map[ $classname ] ) ) {
                    require_once $class_map[ $classname ];
                }
            }
        );
    }
    unset( $class_map, $strauss_src );
}

if ( file_exists( __DIR__ . '/autoload-files.php' ) ) {
    require_once __DIR__ . '/autoload-files.php';
}
EOD;

        $relativeFilepath = $this->config->getTargetDirectory() . 'autoload.php';
        $absoluteFilepath = $this->workingDir . $relativeFilepath;

        $this->logger->info("Writing autoload.php to $relativeFilepath");
        $this->filesystem->write(
            $absoluteFilepath,
            $autoloadPhp
        );
    }
}
