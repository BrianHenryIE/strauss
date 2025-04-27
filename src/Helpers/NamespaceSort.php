<?php
/**
 * Sort namespaces by the depth, followed by the length of the last part.
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

    public function __invoke(string $a, string $b)
    {
        return $this->order === self::LONGEST
            ? $this->sort($a, $b)
            : $this->sort($b, $a);
    }

    protected function sort($a, $b)
    {

        $aParts = explode('\\', $a);
        $bParts = explode('\\', $b);

        $aPathParts = array_slice($aParts, 0, -1);
        $bPathParts = array_slice($bParts, 0, -1);

        // 0 is a valid string length for the global namespace

        $aPath = implode('/', $aPathParts);
        $bPath = implode('/', $bPathParts);
        $aPathLength = strlen($aPath);
        $bPathLength = strlen($bPath);

        // This isn't done right yet, when the path length is equal, the comparison should be done inccludingthe partent directory/path.

        if ($aPathLength === $bPathLength) {
            // What happens with count() < 1/0?
            $aa = implode('/', array_slice($aParts, -2));
            $bb = implode('/', array_slice($bParts, -2));

            return strlen($bb) - strlen($aa);
        }

        if ($aPathLength !== $bPathLength) {
            return $bPathLength - $aPathLength;
        }

        $bPop = array_pop($bPathParts);
        $aPop = array_pop($aPathParts);

        return strlen($bPop) - strlen($aPop);
    }
}
