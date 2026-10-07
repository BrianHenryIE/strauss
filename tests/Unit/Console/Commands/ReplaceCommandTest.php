<?php

declare(strict_types=1);

namespace BrianHenryIE\Strauss\Tests\Unit\Console\Commands;

use BrianHenryIE\Strauss\Console\Commands\AbstractRenamespacerCommand;
use BrianHenryIE\Strauss\Console\Commands\ReplaceCommand;
use BrianHenryIE\Strauss\Pipeline\Parallel\WorkerPool;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Console\Commands\ReplaceCommand
 */
class ReplaceCommandTest extends TestCase
{
    /**
     * @return ReplaceCommand&object{getPipelineSteps(): string[], getWorkerPool(): ?WorkerPool, getLicenseAuthor(): string}
     */
    protected function getSut(): ReplaceCommand
    {
        return new class () extends ReplaceCommand {
            public function getPipelineSteps(): array
            {
                return parent::getPipelineSteps();
            }

            public function getWorkerPool(): ?WorkerPool
            {
                return parent::getWorkerPool();
            }

            public function getLicenseAuthor(): string
            {
                return parent::getLicenseAuthor();
            }
        };
    }

    /**
     * Every step must be a method, most of which are shared with the other commands.
     *
     * @covers ::getPipelineSteps
     */
    public function test_pipeline_steps_exist(): void
    {
        $sut = $this->getSut();

        $steps = $sut->getPipelineSteps();

        self::assertNotEmpty($steps);
        foreach ($steps as $step) {
            self::assertTrue(method_exists($sut, $step), $step);
        }
        self::assertTrue(method_exists(AbstractRenamespacerCommand::class, 'performReplacements'));
        self::assertSame('addLicenses', end($steps));
    }

    /**
     * @covers ::getWorkerPool
     */
    public function test_files_are_not_analysed_in_worker_processes(): void
    {
        self::assertNull($this->getSut()->getWorkerPool());
    }

    /**
     * There is no `composer.json` to read an author from.
     *
     * @covers ::getLicenseAuthor
     */
    public function test_license_author_does_not_need_composer_json(): void
    {
        self::assertNotEmpty($this->getSut()->getLicenseAuthor());
    }
}
