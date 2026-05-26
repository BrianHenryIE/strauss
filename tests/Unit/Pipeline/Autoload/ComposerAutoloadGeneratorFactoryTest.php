<?php

namespace BrianHenryIE\Strauss\Pipeline\Autoload;

use Composer\EventDispatcher\EventDispatcher;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Pipeline\Autoload\ComposerAutoloadGeneratorFactory
 */
class ComposerAutoloadGeneratorFactoryTest extends \BrianHenryIE\Strauss\TestCase
{
    /**
     * @covers ::get
     */
    public function testCreate(): void
    {
        $sut = new ComposerAutoloadGeneratorFactory();

        $result = $sut->get(
            'namespacePrefix',
            \Mockery::mock(EventDispatcher::class)
        );

        $this->assertInstanceOf(ComposerAutoloadGenerator::class, $result);
    }
}
