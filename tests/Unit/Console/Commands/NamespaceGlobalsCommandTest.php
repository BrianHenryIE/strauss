<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;
use Composer\Util\Platform;
use InvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\NamespaceGlobalsCommand
 */
class NamespaceGlobalsCommandTest extends TestCase
{
    /**
     * @return NamespaceGlobalsCommand&object{getPipelineSteps(): string[], getWorkerPool(): ?WorkerPool, parse(array<string, mixed>): array{namespace:string, stripPrefixes:string[], symbolPaths:string[], updatePaths:string[]}}
     */
    protected function getSut(): NamespaceGlobalsCommand
    {
        return new class extends NamespaceGlobalsCommand {
            public function getPipelineSteps(): array
            {
                return parent::getPipelineSteps();
            }

            public function getWorkerPool(): ?WorkerPool
            {
                return parent::getWorkerPool();
            }

            /**
             * @param array<string, mixed> $options The command line options.
             *
             * @return array{namespace:string, stripPrefixes:string[], symbolPaths:string[], updatePaths:string[]}
             */
            public function parse(array $options): array
            {
                $this->updateConfigFromCli(new ArrayInput($options, $this->getDefinition()));

                return [
                    'namespace' => $this->namespace,
                    'stripPrefixes' => $this->stripPrefixes,
                    'symbolPaths' => $this->symbolPaths,
                    'updatePaths' => $this->updatePaths,
                ];
            }
        };
    }

    /**
     * @covers ::getPipelineSteps
     */
    public function test_pipeline_steps_exist(): void
    {
        $sut = $this->getSut();

        foreach ($sut->getPipelineSteps() as $step) {
            self::assertTrue(method_exists($sut, $step), $step);
        }
    }

    /**
     * @covers ::getWorkerPool
     */
    public function test_files_are_not_analysed_in_worker_processes(): void
    {
        self::assertNull($this->getSut()->getWorkerPool());
    }

    /**
     * @covers ::configure
     * @covers ::updateConfigFromCli
     * @covers ::toAbsolutePaths
     */
    public function test_options(): void
    {
        $cwd = rtrim(str_replace('\\', '/', Platform::getcwd()), '/');

        $result = $this->getSut()->parse(
            [
                '--namespace' => '\\Company\\Project\\',
                '--strip-prefix' => ['Acme_', 'Other_'],
                '--paths' => 'src, ./lib/',
                '--update-call-sites' => '/absolute/tests,src',
            ]
        );

        self::assertSame('Company\\Project', $result['namespace']);
        self::assertSame(['Acme_', 'Other_'], $result['stripPrefixes']);
        self::assertSame([$cwd . '/src', $cwd . '/lib'], $result['symbolPaths']);
        self::assertSame([$cwd . '/src', $cwd . '/lib', '/absolute/tests'], $result['updatePaths']);
    }

    /**
     * @covers ::updateConfigFromCli
     * @covers ::toAbsolutePaths
     */
    public function test_paths_default_to_the_current_directory(): void
    {
        $result = $this->getSut()->parse(['--namespace' => 'Company\\Project']);

        self::assertSame([rtrim(str_replace('\\', '/', Platform::getcwd()), '/')], $result['symbolPaths']);
        self::assertSame([], $result['stripPrefixes']);
    }

    /**
     * @covers ::updateConfigFromCli
     */
    public function test_namespace_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->getSut()->parse([]);
    }

    /**
     * Doubled backslashes, e.g. typed in single quotes, are collapsed.
     *
     * @covers ::updateConfigFromCli
     */
    public function test_namespace_backslashes_are_collapsed(): void
    {
        $result = $this->getSut()->parse(['--namespace' => 'Company\\\\Project\\\\']);

        self::assertSame('Company\\Project', $result['namespace']);
    }

    /**
     * @covers ::configure
     * @covers ::updateConfigFromCli
     */
    public function test_rename_files_must_be_a_known_convention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--rename-files');

        $this->getSut()->parse(['--namespace' => 'Company\\Project', '--rename-files' => 'pear']);
    }
}
