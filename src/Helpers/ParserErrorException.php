<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserErrorHandler;

class ParserErrorException extends \Exception
{
    public function __construct(ParserErrorHandler $parserErrorHandler, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Parsing failed with %d errors',
                count($parserErrorHandler->getErrors())
            ),
            $code,
            $previous
        );

        $this->parserErrorHandler = $parserErrorHandler;
    }

    /**
     * @return ParserErrorHandler
     */
    public function getParserErrorHandler(): ParserErrorHandler
    {
        return $this->parserErrorHandler;
    }
}
