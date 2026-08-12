<?php

namespace BrianHenryIE\Strauss\Types;

use BrianHenryIE\Strauss\Files\File;
use BrianHenryIE\Strauss\TestCase;

/**
 * @coversDefaultClass \BrianHenryIE\Strauss\Types\EnumSymbol
 */
class EnumSymbolTest extends TestCase
{
    protected function getFile(): File
    {
        return new File(
            'vendor/path/to/file.php',
            'path/to/file.php',
            'vendor-prefixed/path/to/file.php',
        );
    }

    /**
     * @covers ::__construct
     * @covers ::getBackingType
     * @covers ::getInterfaces
     */
    public function testGetters(): void
    {
        $namespace = new NamespaceSymbol('Foo\Bar');

        $sut = new EnumSymbol(
            'Foo\Bar\Status',
            $this->getFile(),
            $namespace,
            'string',
            ['Foo\Bar\HasLabel']
        );

        $this->assertSame('string', $sut->getBackingType());
        $this->assertSame(['Foo\Bar\HasLabel'], $sut->getInterfaces());
        $this->assertSame('Foo\Bar\Status', $sut->getOriginalFqdnName());
        $this->assertSame('Status', $sut->getOriginalLocalName());
        $this->assertSame($namespace, $sut->getNamespace());
    }

    /**
     * @covers ::__construct
     * @covers ::getBackingType
     * @covers ::getInterfaces
     */
    public function testGettersDefaults(): void
    {
        $sut = new EnumSymbol(
            'Foo\Bar\Direction',
            $this->getFile(),
            new NamespaceSymbol('Foo\Bar')
        );

        $this->assertNull($sut->getBackingType());
        $this->assertSame([], $sut->getInterfaces());
    }

    /**
     * @covers ::getAutoloadAliasArray
     */
    public function testGetAutoloadAliasArrayNamespaced(): void
    {
        $namespace = new NamespaceSymbol('Foo\Bar');
        $namespace->setLocalReplacement('Prefix\Foo\Bar');
        $namespace->setDoRename(true);

        $sut = new EnumSymbol(
            'Foo\Bar\Status',
            $this->getFile(),
            $namespace,
            'string',
            ['Foo\Bar\HasLabel']
        );
        $sut->setDoRename(true);

        $this->assertSame(
            array(
                'type' => 'enum',
                'enumname' => 'Status',
                'namespace' => 'Foo\Bar',
                'concrete' => 'Prefix\Foo\Bar\Status',
            ),
            $sut->getAutoloadAliasArray()
        );
    }

    /**
     * @covers ::getAutoloadAliasArray
     */
    public function testGetAutoloadAliasArrayGlobal(): void
    {
        $namespace = new NamespaceSymbol('\\');

        $sut = new EnumSymbol(
            'Status',
            $this->getFile(),
            $namespace
        );
        $sut->setLocalReplacement('Prefix_Status');
        $sut->setDoRename(true);

        $this->assertSame(
            array(
                'type' => 'enum',
                'enumname' => 'Status',
                'namespace' => '\\',
                'concrete' => 'Prefix_Status',
            ),
            $sut->getAutoloadAliasArray()
        );
    }
}
