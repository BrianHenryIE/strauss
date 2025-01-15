<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;
use BrianHenryIE\Strauss\Tests\Integration\Util\IntegrationTestCase;

class DryRunFeatureTest extends IntegrationTestCase
{
    /**
     * Test default config is false.
     *
     * TODO: This should be in a unit test.
     */
    public function test_not_enabled(): void
    {
        $config = new StraussConfig();

        $this->assertFalse($config->isDryRun());
    }
}
