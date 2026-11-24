<?php

/**
 * Renames class files to the name their class has under a naming convention, and moves them to the directory their
 * namespace names.
 *
 * @package brianhenryie/strauss
 */

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\PhpFileAnalyzer;
use BrianHenryIE\Strauss\Pipeline\FileSymbol\SymbolDefinition;
use Exception;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * PSR-4: `Company\Project\Admin\Settings_Page` is `Admin/Settings_Page.php`.
 *
 * WordPress Coding Standards: `admin/class-settings-page.php`; the file name is the class name in lowercase with
 * hyphens, prefixed with `class-`, `interface-`, `trait-` or `enum-`, and the directories are in lowercase with
 * hyphens.
 *
 * Only a file which declares exactly one class, interface, trait or enum, and nothing else, in the namespace or below
 * it, is moved: function files and templates are loaded by path.
 *
 * The paths of the moved files written in the project's PHP and text files, e.g. `phpstan.neon`, are updated.
 *
 * The files git tracks are moved with `git mv`, so the renames are staged.
 *
 * @see \BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand
 */
class ClassFileRenamer
{
    public const CONVENTION_PSR4 = 'psr4';
    public const CONVENTION_WORDPRESS = 'wordpress';

    public const CONVENTIONS = [
        self::CONVENTION_PSR4,
        self::CONVENTION_WORDPRESS,
    ];

    protected const CLASS_LIKE_TYPES = [
        SymbolDefinition::TYPE_CLASS,
        SymbolDefinition::TYPE_INTERFACE,
        SymbolDefinition::TYPE_TRAIT,
        SymbolDefinition::TYPE_ENUM,
    ];

    /**
     * The extensions of the files, other than PHP files, which may mention a class file by its path.
     */
    protected const TEXT_EXTENSIONS = ['md', 'neon', 'xml', 'yml', 'yaml', 'json', 'txt', 'dist', 'sh', 'js', 'ts', 'feature', 'ini', 'toml'];

    /**
     * Files without an extension which may too.
     */
    protected const TEXT_FILE_NAMES = ['.distignore', '.gitattributes', '.gitignore', '.editorconfig', 'Makefile'];

    /**
     * Directories whose files are not the project's own.
     */
    protected const EXCLUDED_DIRECTORIES = ['vendor', 'vendor-prefixed', 'node_modules'];

    /**
     * `__DIR__ . '/relative/path'` and `dirname(__FILE__) . '/relative/path'`.
     */
    protected const DIRECTORY_RELATIVE_PATH_PATTERN = '#(__DIR__|dirname\(\s*__FILE__\s*\))(\s*\.\s*)(\'|")(/[^\'"$]*)\3#';

    protected string $namespace;

    protected string $convention;

    /**
     * @var string[]
     */
    protected array $absolutePaths;

    protected string $projectDirectory;

    protected FileSystem $filesystem;

    protected LoggerInterface $logger;

    /**
     * Whether to move the files git tracks with `git mv`.
     */
    protected bool $useGit;

    /**
     * @param string          $namespace        The namespace whose directory each of the paths is, e.g. `Company\Project`.
     * @param string          $convention       One of {@see self::CONVENTIONS}.
     * @param string[]        $absolutePaths    The files and directories whose class files are renamed.
     * @param string          $projectDirectory The directory which paths written in the code may be relative to.
     * @param FileSystem      $filesystem       To read, write and move the files.
     * @param LoggerInterface $logger           Logs each file moved and changed.
     * @param bool            $useGit           Whether to move the files git tracks with `git mv`. Only when the
     *                                          filesystem is the real one: not during `--dry-run`.
     *
     * @throws InvalidArgumentException When the convention is not one of {@see self::CONVENTIONS}.
     */
    public function __construct(
        string $namespace,
        string $convention,
        array $absolutePaths,
        string $projectDirectory,
        FileSystem $filesystem,
        LoggerInterface $logger,
        bool $useGit = false
    ) {
        $this->useGit = $useGit;
        if (!in_array($convention, self::CONVENTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('Unknown file naming convention "%s": expected one of %s.', $convention, implode(', ', self::CONVENTIONS))
            );
        }

        $this->namespace = trim($namespace, '\\');
        $this->convention = $convention;
        $this->absolutePaths = array_map(fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/'), $absolutePaths);
        $this->projectDirectory = rtrim(str_replace('\\', '/', $projectDirectory), '/');
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Rename and move the class files among the files given, and update the paths written in all of them.
     *
     * @param string[] $absoluteFilePaths The project's PHP files: those which may be renamed, and those which may
     *                                    mention them.
     *
     * @return array<string,string> The files moved: old absolute path => new absolute path.
     *
     * @throws Exception When two files would get the same path, or a path is taken. Nothing is moved.
     * @throws FilesystemException
     */
    public function rename(array $absoluteFilePaths): array
    {
        $absoluteFilePaths = array_map(fn(string $path): string => str_replace('\\', '/', $path), $absoluteFilePaths);

        $moves = $this->findMoves($absoluteFilePaths);

        $collisions = $this->findCollisions($moves);
        if (!empty($collisions)) {
            throw new Exception('No files were renamed: ' . implode('; ', $collisions) . '.');
        }

        if (empty($moves)) {
            return [];
        }

        $this->move($moves);
        $this->rewritePaths($moves, $absoluteFilePaths);

        return $moves;
    }

    /**
     * The class files among the files given which are not at the path their class's name asks for.
     *
     * @param string[] $absoluteFilePaths The files to consider; only PHP files in the paths are.
     *
     * @return array<string,string> Old absolute path => new absolute path.
     *
     * @throws FilesystemException
     */
    public function findMoves(array $absoluteFilePaths): array
    {
        $analyzer = new PhpFileAnalyzer();
        $analyzer->setLogger($this->logger);

        $moves = [];
        foreach ($absoluteFilePaths as $filePath) {
            $filePath = str_replace('\\', '/', $filePath);
            $root = $this->getRoot($filePath);
            if (is_null($root) || 'php' !== strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
                continue;
            }

            $codeMap = $analyzer->analyze($this->filesystem->read($filePath), $filePath);
            if (is_null($codeMap)) {
                continue;
            }

            $definitions = array_values(
                array_filter(
                    $codeMap->getDefinitions(),
                    fn(SymbolDefinition $definition): bool => SymbolDefinition::TYPE_NAMESPACE !== $definition->getType()
                )
            );
            if (1 !== count($definitions) || !in_array($definitions[0]->getType(), self::CLASS_LIKE_TYPES, true)) {
                continue;
            }

            $classNamespace = trim($definitions[0]->getNamespace(), '\\');
            if ($classNamespace !== $this->namespace && 0 !== strpos($classNamespace, $this->namespace . '\\')) {
                continue;
            }

            $shortName = substr((string) strrchr('\\' . $definitions[0]->getName(), '\\'), 1);
            $newPath = $root . '/' . $this->getRelativePath($definitions[0]->getType(), $classNamespace . '\\' . $shortName);

            if ($newPath !== $filePath) {
                $moves[$filePath] = $newPath;
            }
        }

        ksort($moves);

        return $moves;
    }

    /**
     * The path of a class's file below the namespace's directory.
     *
     * @param string $type               `class`, `interface`, `trait` or `enum`: {@see SymbolDefinition::TYPES}.
     * @param string $fullyQualifiedName The class's name, in the namespace or below it.
     *
     * @return string E.g. `Admin/Settings_Page.php`, `admin/class-settings-page.php`.
     */
    public function getRelativePath(string $type, string $fullyQualifiedName): string
    {
        $segments = explode('\\', trim(substr(trim($fullyQualifiedName, '\\'), strlen($this->namespace)), '\\'));
        $shortName = (string) array_pop($segments);

        if (self::CONVENTION_PSR4 === $this->convention) {
            return implode('/', array_merge($segments, [$shortName . '.php']));
        }

        $hyphenate = fn(string $name): string => strtolower(str_replace('_', '-', $name));

        return implode('/', array_merge(array_map($hyphenate, $segments), [$type . '-' . $hyphenate($shortName) . '.php']));
    }

    /**
     * The directory which is the namespace's, for a file: the path it is in, or the directory of the path it is.
     *
     * @param string $absoluteFilePath The file.
     *
     * @return ?string Null when the file is not in the paths.
     */
    protected function getRoot(string $absoluteFilePath): ?string
    {
        $root = null;
        foreach ($this->absolutePaths as $path) {
            if ($absoluteFilePath === $path) {
                return dirname($path);
            }
            if (0 === strpos($absoluteFilePath, $path . '/') && strlen($path) > strlen((string) $root)) {
                $root = $path;
            }
        }
        return $root;
    }

    /**
     * What stops the moves: two files wanting one path, or a path which is taken by a file which is not moving.
     * Compared without case, as on a filesystem which ignores it.
     *
     * @param array<string,string> $moves Old absolute path => new absolute path.
     *
     * @return string[] One description per problem.
     *
     * @throws FilesystemException
     */
    public function findCollisions(array $moves): array
    {
        $problems = [];

        $wanted = [];
        foreach ($moves as $old => $new) {
            $wanted[strtolower($new)][] = $old;
        }
        foreach ($wanted as $olds) {
            if (count($olds) > 1) {
                $problems[] = sprintf('%s is wanted by %s', $moves[$olds[0]], implode(' and ', $olds));
            }
        }

        $moving = array_change_key_case($moves);
        foreach ($moves as $old => $new) {
            if (!isset($moving[strtolower($new)]) && $this->filesystem->fileExists($new)) {
                $problems[] = sprintf('%s already exists, wanted by %s', $new, $old);
            }
        }

        return $problems;
    }

    /**
     * Move the files, then delete the directories left empty.
     *
     * Each file goes to a temporary name first, so two files can swap names, and a name can change only in case.
     * The files git tracks are moved with `git mv`.
     *
     * @param array<string,string> $moves Old absolute path => new absolute path.
     *
     * @throws FilesystemException
     */
    protected function move(array $moves): void
    {
        $trackedByGit = $this->useGit ? $this->findFilesTrackedByGit(array_keys($moves)) : [];

        foreach ($moves as $old => $new) {
            $this->moveFile($old, $old . '.moving', isset($trackedByGit[$old]));
        }

        foreach ($moves as $old => $new) {
            $this->moveFile($old . '.moving', $new, isset($trackedByGit[$old]));
            $this->logger->notice('Renamed {oldPath} to {newPath}.', ['oldPath' => $old, 'newPath' => $new]);
        }

        foreach (array_unique(array_map('dirname', array_keys($moves))) as $directory) {
            while (
                !in_array($directory, $this->absolutePaths, true)
                && 0 === strpos($directory, $this->projectDirectory . '/')
                && $this->filesystem->directoryExists($directory)
                && $this->filesystem->isDirectoryEmpty($directory)
            ) {
                $this->filesystem->deleteDirectory($directory);
                $this->logger->info('Deleted empty directory {directory}.', ['directory' => $directory]);
                $directory = dirname($directory);
            }
        }
    }

    /**
     * Move one file, with `git mv` when git tracks it, so the rename is staged.
     *
     * @param string $old          The file's absolute path.
     * @param string $new          The absolute path to move it to.
     * @param bool   $trackedByGit Whether git tracks the file.
     *
     * @throws FilesystemException
     */
    protected function moveFile(string $old, string $new, bool $trackedByGit): void
    {
        if ($trackedByGit) {
            // `git mv` does not create directories.
            $this->filesystem->createDirectory(dirname($new));

            $process = new Process(['git', 'mv', '--', $old, $new], $this->projectDirectory);
            if (0 === $process->run()) {
                return;
            }

            $this->logger->warning(
                '`git mv` failed for {oldPath}, moving it without git: {errorMessage}',
                ['oldPath' => $old, 'errorMessage' => trim($process->getErrorOutput())]
            );
        }

        $this->filesystem->move($old, $new);
    }

    /**
     * The files among those given which git tracks. None when the project is not in a git repository, or git is not
     * installed.
     *
     * @param string[] $absoluteFilePaths The files to ask about.
     *
     * @return array<string,true> Keyed by absolute path.
     */
    protected function findFilesTrackedByGit(array $absoluteFilePaths): array
    {
        $tracked = [];
        // In batches, to keep the command line short.
        foreach (array_chunk($absoluteFilePaths, 100) as $batch) {
            $process = new Process(array_merge(['git', 'ls-files', '-z', '--full-name', '--'], $batch), $this->projectDirectory);
            if (0 !== $process->run()) {
                continue;
            }
            $repositoryDirectory = $this->getGitRepositoryDirectory();
            if (is_null($repositoryDirectory)) {
                return [];
            }
            foreach (array_filter(explode("\0", $process->getOutput())) as $repositoryRelativePath) {
                $trackedPath = $repositoryDirectory . '/' . $repositoryRelativePath;
                foreach ($batch as $absoluteFilePath) {
                    // The repository's path may be written differently, e.g. through a symlink.
                    if ($absoluteFilePath === $trackedPath || realpath($absoluteFilePath) === realpath($trackedPath)) {
                        $tracked[$absoluteFilePath] = true;
                    }
                }
            }
        }
        return $tracked;
    }

    /**
     * The top directory of the git repository the project is in.
     *
     * @return ?string Null when it is not in one.
     */
    protected function getGitRepositoryDirectory(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--show-toplevel'], $this->projectDirectory);

        return 0 === $process->run() ? rtrim(str_replace('\\', '/', trim($process->getOutput())), '/') : null;
    }

    /**
     * Update the paths written in the files given, and in the project's other PHP and text files, e.g. `phpstan.neon`,
     * so they reach what they did before:
     * * in PHP files, `__DIR__ . '/relative/path'`, in a file which moved, or to a file which moved;
     * * a moved file's path relative to the project, e.g. `includes/class-foo.php`, anywhere.
     *
     * Warns about what could not be followed: other uses of `__DIR__` and `__FILE__` in a file which changed
     * directory, and old file names which are still mentioned.
     *
     * @param array<string,string> $moves             Old absolute path => new absolute path, already moved.
     * @param string[]             $absoluteFilePaths The project's PHP files, by the paths they had before the moves.
     *
     * @throws FilesystemException
     */
    protected function rewritePaths(array $moves, array $absoluteFilePaths): void
    {
        $projectRelativeMoves = [];
        $oldFileNames = [];
        foreach ($moves as $old => $new) {
            if (0 === strpos($old, $this->projectDirectory . '/') && 0 === strpos($new, $this->projectDirectory . '/')) {
                $projectRelativeMoves[substr($old, strlen($this->projectDirectory) + 1)] = substr($new, strlen($this->projectDirectory) + 1);
            }
            if (basename($old) !== basename($new)) {
                $oldFileNames[basename($old)] = true;
            }
        }
        // The file names which are not also the new name of another file.
        $oldFileNames = array_diff_key($oldFileNames, array_flip(array_map('basename', $moves)));

        // Longest first, so a path is matched before a shorter path at its end.
        uksort($projectRelativeMoves, fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $projectRelativePattern = empty($projectRelativeMoves)
            ? null
            : $this->getMentionPattern(array_keys($projectRelativeMoves));
        $oldFileNamesPattern = empty($oldFileNames)
            ? null
            : $this->getMentionPattern(array_keys($oldFileNames));

        // The files given, by their old paths, and the project's other files; the moved files are among the first.
        $allFilePaths = array_unique(
            array_merge($absoluteFilePaths, array_diff($this->findProjectTextFiles(), array_values($moves)))
        );

        foreach ($allFilePaths as $oldFilePath) {
            $filePath = $moves[$oldFilePath] ?? $oldFilePath;
            if (!$this->filesystem->fileExists($filePath)) {
                continue;
            }
            $isPhp = 'php' === strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!$isPhp && !$this->isTextFile($filePath)) {
                continue;
            }

            $oldDirectory = dirname($oldFilePath);
            $newDirectory = dirname($filePath);

            $contents = $this->filesystem->read($filePath);

            $updated = !$isPhp ? $contents : (string) preg_replace_callback(
                self::DIRECTORY_RELATIVE_PATH_PATTERN,
                function (array $matches) use ($moves, $oldDirectory, $newDirectory): string {
                    $target = $this->normalize($oldDirectory . $matches[4]);
                    $relativePath = $this->getRelativePathFrom($newDirectory, $moves[$target] ?? $target)
                        . ('/' === substr($matches[4], -1) ? '/' : '');
                    // Only when it changes: the path as written may not be normalized.
                    return $oldDirectory === $newDirectory && !isset($moves[$target])
                        ? $matches[0]
                        : $matches[1] . $matches[2] . $matches[3] . $relativePath . $matches[3];
                },
                $contents
            );

            if (!is_null($projectRelativePattern)) {
                $updated = (string) preg_replace_callback(
                    $projectRelativePattern,
                    fn(array $matches): string => $projectRelativeMoves[$matches[1]],
                    $updated
                );
            }

            if ($updated !== $contents) {
                $this->filesystem->write($filePath, $updated);
                $this->logger->info('Updated the paths in {filePath}.', ['filePath' => $filePath]);
            }

            if (
                $oldDirectory !== $newDirectory
                && 1 === preg_match('#__DIR__|__FILE__#', (string) preg_replace(self::DIRECTORY_RELATIVE_PATH_PATTERN, '', $updated))
            ) {
                $this->logger->warning(
                    '{filePath} changed directory and uses __DIR__ or __FILE__ in a way which could not be followed: check it by hand.',
                    ['filePath' => $filePath]
                );
            }

            if (!is_null($oldFileNamesPattern) && preg_match_all($oldFileNamesPattern, $updated, $mentions)) {
                $this->logger->warning(
                    '{filePath} still mentions renamed files: {fileNames}. Check it by hand.',
                    ['filePath' => $filePath, 'fileNames' => implode(', ', array_unique($mentions[1]))]
                );
            }
        }
    }

    /**
     * A pattern matching any of the file names or paths, where it is not part of a longer name.
     *
     * @param string[] $names File names or paths.
     */
    protected function getMentionPattern(array $names): string
    {
        return '#(?<![A-Za-z0-9_-])('
            . implode('|', array_map(fn(string $name): string => preg_quote($name, '#'), $names))
            . ')(?![A-Za-z0-9_-]|\.[A-Za-z0-9])#';
    }

    /**
     * Is this a file, other than a PHP file, which may mention a class file by its path, e.g. `phpstan.neon`?
     *
     * @param string $filePath The file's path.
     */
    protected function isTextFile(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true)
            || in_array(basename($filePath), self::TEXT_FILE_NAMES, true);
    }

    /**
     * The project's own PHP and text files: those in the project directory, outside its dependencies' directories
     * and its hidden directories, other than `.github`.
     *
     * @return string[] Absolute paths.
     *
     * @throws FilesystemException
     */
    protected function findProjectTextFiles(): array
    {
        $files = [];
        $directories = [$this->projectDirectory];
        while (!empty($directories)) {
            $directory = (string) array_shift($directories);
            foreach ($this->filesystem->listContents($directory, false) as $attributes) {
                $path = str_replace('\\', '/', $this->filesystem->makeAbsolute($attributes->path()));
                $name = basename($path);
                if ($attributes->isDir()) {
                    if (
                        !in_array($name, self::EXCLUDED_DIRECTORIES, true)
                        && ('.' !== $name[0] || '.github' === $name)
                    ) {
                        $directories[] = $path;
                    }
                    continue;
                }
                if ('php' === strtolower(pathinfo($path, PATHINFO_EXTENSION)) || $this->isTextFile($path)) {
                    $files[] = $path;
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Resolve the `.` and `..` in an absolute path.
     *
     * @param string $path E.g. `/project/src/Acme/../functions.php`.
     */
    protected function normalize(string $path): string
    {
        $resolved = [];
        foreach (explode('/', $path) as $index => $segment) {
            if ('.' === $segment || ('' === $segment && $index > 0 && '' !== end($resolved) && !$this->endsWithScheme($resolved))) {
                continue;
            }
            if ('..' === $segment && count($resolved) > 1) {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }
        return implode('/', $resolved);
    }

    /**
     * Is the last segment a stream wrapper's scheme, e.g. `mem:` in `mem://project`, whose `//` is to be kept?
     *
     * @param string[] $segments The path's segments so far.
     */
    protected function endsWithScheme(array $segments): bool
    {
        return 1 === count($segments) && ':' === substr((string) end($segments), -1);
    }

    /**
     * The path from a directory to a target, starting with a slash, for use after `__DIR__`.
     *
     * @param string $directory The absolute path of the directory.
     * @param string $target    The absolute path of the file or directory to reach.
     */
    protected function getRelativePathFrom(string $directory, string $target): string
    {
        $from = explode('/', $directory);
        $to = explode('/', $target);

        while (!empty($from) && !empty($to) && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return '/' . implode('/', array_merge(array_fill(0, count($from), '..'), $to));
    }
}
