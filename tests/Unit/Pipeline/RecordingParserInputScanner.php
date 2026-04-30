<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\SimplePhpParser\Parsers\Helper\ParserContainer;

final class RecordingParserInputScanner extends ScannerHarness
{
    /** @var string[] */
    private array $parserInputs = [];

    /**
     * @return string[]
     */
    public function getParserInputs(): array
    {
        return $this->parserInputs;
    }

    protected function parsePhpCode(string $contents): ParserContainer
    {
        $this->parserInputs[] = $contents;

        return parent::parsePhpCode($contents);
    }
}
