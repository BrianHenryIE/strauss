<?php
/**
 * Given two namespaces, sort them by the number of levels, then the length of the final part
 */

namespace BrianHenryIE\Strauss\Helpers;

class NamespaceSort
{

    const LONGEST = false;
    const SHORTEST = true;

    protected bool $order;

    public function __construct(bool $order = self::SHORTEST)
    {
        $this->order = $order;
    }

    public function __invoke(string $a, string $b): int
    {
        $a = trim($a, '\\');
        $b = trim($b, '\\');

        return $this->order === self::LONGEST
            ? $this->sort($a, $b)
            : $this->sort($b, $a);
    }

    protected function sort(string $a, string $b): int
    {

        $aParts = explode('\\', $a);
        $bParts = explode('\\', $b);

        $aPartCount = count($aParts);
        $bPartCount = count($bParts);

        if ($aPartCount !== $bPartCount) {
            return $bPartCount - $aPartCount;
        }

        $bLastPart = array_pop($aParts);
        $aLastPart = array_pop($bParts);

        return strlen($aLastPart) - strlen($bLastPart);
    }
}
