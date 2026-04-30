<?php

namespace BrianHenryIE\Strauss\Pipeline;

use BrianHenryIE\Strauss\Files\FileBase;
use BrianHenryIE\Strauss\Types\DiscoveredSymbols;

class ScannerHarness extends FileSymbolScanner
{
    public function scanString(string $contents, FileBase $file): DiscoveredSymbols
    {
        $this->find($contents, $file, null);

        return $this->discoveredSymbols;
    }
}
