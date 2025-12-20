<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\ColorLogger\ColorLogger;
use BrianHenryIE\Strauss\Helpers\Log\PadColonColumnsLogProcessor;
use BrianHenryIE\Strauss\TestCase;
use Monolog\Handler\PsrHandler;
use Monolog\Logger;

class PadColonColumnsLogProcessorIntegrationTest extends TestCase
{
    public function testHappyPath(): void
    {
        $colorLogger = new ColorLogger();

        $logger = new Logger('logger');
        $logger->pushProcessor(new PadColonColumnsLogProcessor());
        $logger->pushHandler(new PsrHandler($colorLogger));

        $logger->info('Brian:::was here');
        $logger->info('Brian Henry:::was here');
        $logger->info('Brian Henry O\'Beirne:::was here');
        $logger->notice('Brian:::was here again');

        $this->assertTrue($colorLogger->hasNotice('Brian:                was here again'));
    }
}
