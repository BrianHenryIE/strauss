<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Pipeline;

use BrianHenryIE\Strauss\Helpers\Flysystem\FileSystem;
use BrianHenryIE\Strauss\Pipeline\ClassFileRenamer;
use BrianHenryIE\Strauss\TestCase;
use Exception;
use InvalidArgumentException;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\ClassFileRenamer
 */
class ClassFileRenamerTest extends TestCase
{
    protected const PROJECT = 'mem://project';

    /**
     * @param string               $convention One of {@see ClassFileRenamer::CONVENTIONS}.
     * @param array<string,string> $files      Path relative to the project => contents.
     */
    protected function getSut(string $convention, array $files, ?FileSystem &$filesystem = null): ClassFileRenamer
    {
        $filesystem = $this->getNewInMemoryFileSystem();
        foreach ($files as $relativePath => $contents) {
            $filesystem->write(self::PROJECT . '/' . $relativePath, $contents);
        }

        return new ClassFileRenamer(
            'Company\\Project',
            $convention,
            [self::PROJECT . '/src'],
            self::PROJECT,
            $filesystem,
            new NullLogger()
        );
    }

    /**
     * @param string[] $relativePaths Paths relative to the project.
     *
     * @return string[] Absolute paths.
     */
    protected function absolute(array $relativePaths): array
    {
        return array_map(fn(string $relativePath): string => self::PROJECT . '/' . $relativePath, $relativePaths);
    }

    /**
     * @param array<string,string> $moves Absolute paths.
     *
     * @return array<string,string> Paths relative to the project.
     */
    protected function relative(array $moves): array
    {
        $relative = [];
        foreach ($moves as $old => $new) {
            $relative[substr($old, strlen(self::PROJECT) + 1)] = substr($new, strlen(self::PROJECT) + 1);
        }
        return $relative;
    }

    /**
     * @covers ::__construct
     */
    public function test_unknown_convention(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->getSut('pear', []);
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string, 3:string}> Convention, type, name, expected path.
     */
    public static function relativePathDataProvider(): array
    {
        return [
            'psr4, in the namespace' => ['psr4', 'class', 'Company\\Project\\Greeter', 'Greeter.php'],
            'psr4, below the namespace' => ['psr4', 'class', 'Company\\Project\\AttrDef\\CSS\\Color', 'AttrDef/CSS/Color.php'],
            'psr4, interface' => ['psr4', 'interface', 'Company\\Project\\Greeting\\Greeter', 'Greeting/Greeter.php'],
            'wordpress, in the namespace' => ['wordpress', 'class', 'Company\\Project\\Greeter', 'class-greeter.php'],
            'wordpress, below the namespace' => ['wordpress', 'class', 'Company\\Project\\Admin_UI\\View_Model\\Settings_Page', 'admin-ui/view-model/class-settings-page.php'],
            'wordpress, case' => ['wordpress', 'class', 'Company\\Project\\AttrDef\\CSSColor', 'attrdef/class-csscolor.php'],
            'wordpress, interface' => ['wordpress', 'interface', 'Company\\Project\\Greeter', 'interface-greeter.php'],
            'wordpress, trait' => ['wordpress', 'trait', 'Company\\Project\\REST\\Args_Trait', 'rest/trait-args-trait.php'],
            'wordpress, enum' => ['wordpress', 'enum', 'Company\\Project\\Status', 'enum-status.php'],
        ];
    }

    /**
     * @covers ::getRelativePath
     * @dataProvider \BrianHenryIE\Strauss\Tests\Unit\Pipeline\ClassFileRenamerTest::relativePathDataProvider
     *
     * @param string $convention One of {@see ClassFileRenamer::CONVENTIONS}.
     * @param string $type       `class`, `interface`, `trait` or `enum`.
     * @param string $name       The fully qualified name.
     * @param string $expected   The path below the namespace's directory.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('relativePathDataProvider')]
    public function test_get_relative_path(string $convention, string $type, string $name, string $expected): void
    {
        self::assertSame($expected, $this->getSut($convention, [])->getRelativePath($type, $name));
    }

    /**
     * @return array<string,string>
     */
    protected function getFiles(): array
    {
        return [
            'src/Acme/Greeter.php' => "<?php\nnamespace Company\\Project;\n\ninterface Greeter {}\n",
            'src/Acme/Greeting/Formal.php' => "<?php\nnamespace Company\\Project\\Greeting;\n\nclass Formal implements \\Company\\Project\\Greeter {}\n",
            // Already where it belongs, for PSR-4.
            'src/Status.php' => "<?php\nnamespace Company\\Project;\n\nenum Status {}\n",
            // Not moved: a function too, two classes, no class, another namespace, no namespace.
            'src/Acme/Legacy.php' => "<?php\nnamespace Company\\Project;\n\nfunction acme_legacy() {}\nclass Legacy {}\n",
            'src/Acme/Two.php' => "<?php\nnamespace Company\\Project;\n\nclass One {}\nclass Two {}\n",
            'src/functions.php' => "<?php\nnamespace Company\\Project;\n\nfunction acme_f() {}\n",
            'src/Other.php' => "<?php\nnamespace Other;\n\nclass Thing {}\n",
            'src/Global.php' => "<?php\nclass Acme_Global {}\n",
            'src/view.php' => "<p><?php echo 1; ?></p>\n",
            // Not in the paths.
            'lib/Outside.php' => "<?php\nnamespace Company\\Project;\n\nclass Outside {}\n",
        ];
    }

    /**
     * @covers ::findMoves
     * @covers ::getRoot
     */
    public function test_find_moves_psr4(): void
    {
        $files = $this->getFiles();
        $sut = $this->getSut('psr4', $files);

        self::assertSame(
            [
                'src/Acme/Greeter.php' => 'src/Greeter.php',
                'src/Acme/Greeting/Formal.php' => 'src/Greeting/Formal.php',
            ],
            $this->relative($sut->findMoves($this->absolute(array_keys($files))))
        );
    }

    /**
     * @covers ::findMoves
     * @covers ::getRoot
     */
    public function test_find_moves_wordpress(): void
    {
        $files = $this->getFiles();
        $sut = $this->getSut('wordpress', $files);

        self::assertSame(
            [
                'src/Acme/Greeter.php' => 'src/interface-greeter.php',
                'src/Acme/Greeting/Formal.php' => 'src/greeting/class-formal.php',
                'src/Status.php' => 'src/enum-status.php',
            ],
            $this->relative($sut->findMoves($this->absolute(array_keys($files))))
        );
    }

    /**
     * @covers ::findCollisions
     */
    public function test_find_collisions(): void
    {
        $sut = $this->getSut('psr4', ['src/Taken.php' => '<?php', 'src/a/Taken.php' => '<?php']);

        // A target which is itself moving away is free.
        self::assertSame(
            [],
            $sut->findCollisions(
                array_combine(
                    $this->absolute(['src/a/Taken.php', 'src/Taken.php']),
                    $this->absolute(['src/Taken.php', 'src/b/Taken.php'])
                )
            )
        );

        $collisions = $sut->findCollisions(
            array_combine(
                $this->absolute(['src/a/Foo.php', 'src/b/foo.php', 'src/a/Taken.php']),
                $this->absolute(['src/Foo.php', 'src/FOO.php', 'src/Taken.php'])
            )
        );

        self::assertCount(2, $collisions);
        self::assertStringContainsString('src/a/Foo.php and mem://project/src/b/foo.php', $collisions[0]);
        self::assertStringContainsString('src/Taken.php already exists', $collisions[1]);
    }

    /**
     * @covers ::rename
     * @covers ::move
     * @covers ::rewritePaths
     * @covers ::normalize
     * @covers ::getRelativePathFrom
     */
    public function test_rename(): void
    {
        $files = [
            'plugin.php' => "<?php\nrequire_once __DIR__ . '/src/Acme/Greeter.php';\nrequire_once dirname( __FILE__ ) . \"/src/Acme/Greeting/Formal.php\";\nrequire_once ACME_DIR . 'src/Acme/Greeting/Formal.php';\nrequire __DIR__ . '/src/functions.php';\n",
            'src/functions.php' => "<?php\nnamespace Company\\Project;\n\nfunction acme_f() {}\n",
            'src/Acme/Greeter.php' => "<?php\nnamespace Company\\Project;\n\nrequire_once __DIR__ . '/Greeting/Formal.php';\nrequire_once __DIR__ . '/../functions.php';\n\ninterface Greeter {}\n",
            'src/Acme/Greeting/Formal.php' => "<?php\nnamespace Company\\Project\\Greeting;\n\nrequire_once __DIR__ . '/../../functions.php';\n\nclass Formal { public \$views = __DIR__ . '/../views/'; }\n",
        ];
        $sut = $this->getSut('wordpress', $files, $filesystem);

        $moves = $sut->rename($this->absolute(array_keys($files)));

        self::assertSame(
            [
                'src/Acme/Greeter.php' => 'src/interface-greeter.php',
                'src/Acme/Greeting/Formal.php' => 'src/greeting/class-formal.php',
            ],
            $this->relative($moves)
        );

        self::assertFalse($filesystem->fileExists(self::PROJECT . '/src/Acme/Greeter.php'));
        self::assertFalse($filesystem->directoryExists(self::PROJECT . '/src/Acme'), 'Empty directories are deleted.');

        self::assertSame(
            "<?php\nrequire_once __DIR__ . '/src/interface-greeter.php';\nrequire_once dirname( __FILE__ ) . \"/src/greeting/class-formal.php\";\nrequire_once ACME_DIR . 'src/greeting/class-formal.php';\nrequire __DIR__ . '/src/functions.php';\n",
            $filesystem->read(self::PROJECT . '/plugin.php')
        );
        self::assertSame(
            "<?php\nnamespace Company\\Project;\n\nrequire_once __DIR__ . '/greeting/class-formal.php';\nrequire_once __DIR__ . '/functions.php';\n\ninterface Greeter {}\n",
            $filesystem->read(self::PROJECT . '/src/interface-greeter.php')
        );
        self::assertSame(
            "<?php\nnamespace Company\\Project\\Greeting;\n\nrequire_once __DIR__ . '/../functions.php';\n\nclass Formal { public \$views = __DIR__ . '/../Acme/views/'; }\n",
            $filesystem->read(self::PROJECT . '/src/greeting/class-formal.php')
        );
    }

    /**
     * The other text files in the project which mention a renamed file by its path are updated too, e.g.
     * `phpstan.neon`, whether or not they were among the files given.
     *
     * @covers ::rename
     * @covers ::rewritePaths
     * @covers ::findProjectTextFiles
     */
    public function test_rename_updates_other_files_in_the_project(): void
    {
        $neon = "parameters:\n    excludePaths:\n        - src/Acme/Greeter.php\n        - %currentWorkingDirectory%/src/Acme/Greeting/Formal.php\n        - src/Acme/Greeter.php.bak\n";
        $files = [
            'src/Acme/Greeter.php' => "<?php\nnamespace Company\\Project;\n\ninterface Greeter {}\n",
            'src/Acme/Greeting/Formal.php' => "<?php\nnamespace Company\\Project\\Greeting;\n\nclass Formal {}\n",
            'phpstan.neon' => $neon,
            'config/phpcs.xml.dist' => "<exclude-pattern>../src/Acme/Greeter.php</exclude-pattern>\n",
            '.distignore' => "/src/Acme/Greeter.php\n",
            'composer.json' => '{"autoload": {"classmap": ["src/Acme/Greeter.php"]}}',
            'tests/bootstrap.php' => "<?php\nrequire_once __DIR__ . '/../src/Acme/Greeter.php';\n",
            // Not text, or not the project's own.
            'assets/logo.png' => 'src/Acme/Greeter.php',
            'vendor/package/readme.md' => 'src/Acme/Greeter.php',
            'node_modules/package/readme.md' => 'src/Acme/Greeter.php',
            '.git/index' => 'src/Acme/Greeter.php',
            'composer.lock' => 'src/Acme/Greeter.php',
        ];
        $sut = $this->getSut('wordpress', $files, $filesystem);

        // Only the files in the path are given.
        $sut->rename($this->absolute(['src/Acme/Greeter.php', 'src/Acme/Greeting/Formal.php']));

        $read = fn(string $relativePath): string => $filesystem->read(self::PROJECT . '/' . $relativePath);

        self::assertSame(
            "parameters:\n    excludePaths:\n        - src/interface-greeter.php\n        - %currentWorkingDirectory%/src/greeting/class-formal.php\n        - src/Acme/Greeter.php.bak\n",
            $read('phpstan.neon')
        );
        self::assertSame("<exclude-pattern>../src/interface-greeter.php</exclude-pattern>\n", $read('config/phpcs.xml.dist'));
        self::assertSame("/src/interface-greeter.php\n", $read('.distignore'));
        self::assertSame('{"autoload": {"classmap": ["src/interface-greeter.php"]}}', $read('composer.json'));
        self::assertSame("<?php\nrequire_once __DIR__ . '/../src/interface-greeter.php';\n", $read('tests/bootstrap.php'));

        foreach (['assets/logo.png', 'vendor/package/readme.md', 'node_modules/package/readme.md', '.git/index', 'composer.lock'] as $untouched) {
            self::assertSame('src/Acme/Greeter.php', $read($untouched), $untouched);
        }
    }

    /**
     * Two files can swap names, and a name can change only in its case.
     *
     * @covers ::move
     */
    public function test_rename_swap(): void
    {
        $files = [
            'src/Foo.php' => "<?php\nnamespace Company\\Project;\n\nclass Bar {}\n",
            'src/Bar.php' => "<?php\nnamespace Company\\Project;\n\nclass Foo {}\n",
            'src/baz.php' => "<?php\nnamespace Company\\Project;\n\nclass Baz {}\n",
        ];
        $sut = $this->getSut('psr4', $files, $filesystem);

        $sut->rename($this->absolute(array_keys($files)));

        self::assertStringContainsString('class Foo', $filesystem->read(self::PROJECT . '/src/Foo.php'));
        self::assertStringContainsString('class Bar', $filesystem->read(self::PROJECT . '/src/Bar.php'));
        self::assertTrue($filesystem->fileExists(self::PROJECT . '/src/Baz.php'));
    }

    /**
     * @covers ::rename
     */
    public function test_rename_stops_on_a_collision(): void
    {
        $files = [
            'src/a/Foo.php' => "<?php\nnamespace Company\\Project;\n\nclass Foo {}\n",
            'src/b/Foo.php' => "<?php\nnamespace Company\\Project;\n\nclass Foo {}\n",
        ];
        $sut = $this->getSut('psr4', $files, $filesystem);

        try {
            $sut->rename($this->absolute(array_keys($files)));
            self::fail('Expected an exception.');
        } catch (Exception $exception) {
            self::assertStringContainsString('src/Foo.php is wanted by', $exception->getMessage());
        }

        self::assertTrue($filesystem->fileExists(self::PROJECT . '/src/a/Foo.php'), 'Nothing is moved.');
        self::assertTrue($filesystem->fileExists(self::PROJECT . '/src/b/Foo.php'));
    }
}
