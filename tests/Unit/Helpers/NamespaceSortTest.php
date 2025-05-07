<?php

namespace BrianHenryIE\Strauss\Helpers;

use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Helpers\NamespaceSort
 */
class NamespaceSortTest extends TestCase
{
    public static function namespaceSortDataProvider(): array
    {
        return [
            'simple case of equal levels, differing name length of final level' => [
                'inputs' => [
                    'Company\\Project\\Foo\\Bar\\Baz\\Qux',
                    'Company\\Project\\Foo\\Bar\\Baz\\Q',
                ],
                'order' => NamespaceSort::LONGEST,
                'expectedFirst' => 'Company\\Project\\Foo\\Bar\\Baz\\Qux'
            ],
            'more levels should ignore the string length' => [
                'inputs' => [
                    'Company\\Project\\Foo\\Bar\\Baz\\Qux',
                    'Company\\ProjectFooFoo\\BarBar\\BazBaz\\Qux',
                ],
                'order' => NamespaceSort::LONGEST,
                'expectedFirst' => 'Company\\Project\\Foo\\Bar\\Baz\\Qux'
            ],
            'zero length input compared to a regular namespace' => [
                'inputs' => [
                    '',
                    'Company\\Qux',
                ],
                'order' => NamespaceSort::LONGEST,
                'expectedFirst' => 'Company\\Qux',
            ],
            'zero length input compared to a regular namespace, shortest' => [
                'inputs' => [
                    '',
                    'Company\\Qux',
                ],
                'order' => NamespaceSort::SHORTEST,
                'expectedFirst' => '',
            ],
            'compare two single layer namespaces' => [
                'inputs' => [
                    'Brian\\',
                    'Company\\',
                ],
                'order' => NamespaceSort::SHORTEST,
                'expectedFirst' => 'Brian\\',
            ],
        ];
    }

    /**
     * @dataProvider namespaceSortDataProvider
     *
     * @param string[] $inputs A list of namespaces to sort
     * @param bool $order Longest (false)/shortest (true))
     * @param string $expectedFirst After sorting, the first element in the array should be this
     */
    public function testNamespaceSort(array $inputs, bool $order, string $expectedFirst)
    {

        usort($inputs, new NamespaceSort($order));

        $firstSorted = $inputs[0];

        $this->assertEquals($expectedFirst, $firstSorted, $expectedFirst . ' should be ' . ( $order ? '`SHORTEST`' : '`LONGEST`' ) . ' in the sorted array');
    }
}
