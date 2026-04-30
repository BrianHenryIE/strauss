<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserContainer;
use BrianHenryIE\SimplePhpParser\Parsers\PhpCodeParser;

final class LegacyGetFromStringScanner extends ScannerHarness
{
    protected function parsePhpCode(string $contents): ParserContainer
    {
        return PhpCodeParser::getFromString($contents);
    }
}
