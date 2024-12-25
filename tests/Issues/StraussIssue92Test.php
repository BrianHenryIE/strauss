<?php
/**
 * "It would be helpful to have a --plain-version flag"
 *
 * @see https://github.com/BrianHenryIE/strauss/issues/92
 */

namespace BrianHenryIE\Strauss\Tests\Issues;

use BrianHenryIE\Strauss\Console\Commands\DependenciesCommand;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;
use Mockery;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package BrianHenryIE\Strauss\Tests\Issues
 * @coversNothing
 */
class StraussIssue92Test extends IntegrationTestCase
{
    public function test_plain_version_output(): void
    {
        $strauss = new DependenciesCommand();

        $applicationMock = Mockery::mock(Application::class)->makePartial();
        $applicationMock->expects('getVersion')->andReturn('0.1.2')->once();

        $strauss->setApplication($applicationMock);

        $inputInterfaceMock = new ArgvInput(['strauss', 'plainVersion']);

        $outputInterfaceMock = Mockery::mock(OutputInterface::class);
        $outputInterfaceMock->expects('getVerbosity')->andReturn(OutputInterface::VERBOSITY_NORMAL)->once();
        $outputInterfaceMock->shouldReceive('writeln')->with('0.1.2')->once();

        $exit_code = $strauss->run($inputInterfaceMock, $outputInterfaceMock);

        $this->assertEquals(0, $exit_code);
    }
}
