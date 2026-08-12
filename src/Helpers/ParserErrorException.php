<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserErrorHandler;
use Exception;
use Throwable;

class ParserErrorException extends Exception
{
    protected ParserErrorHandler $parserErrorHandler;

    public function __construct(
        ParserErrorHandler $parserErrorHandler,
        int $code = 0,
        ?Throwable $previous = null
    ) {
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
