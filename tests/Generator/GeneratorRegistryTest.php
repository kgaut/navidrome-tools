<?php

namespace App\Tests\Generator;

use App\Generator\GeneratorRegistry;
use App\Generator\PlaylistGeneratorInterface;
use PHPUnit\Framework\TestCase;

class GeneratorRegistryTest extends TestCase
{
    public function testIndexesByKeyAndExposesChoices(): void
    {
        $a = $this->makeGenerator('foo', 'Foo');
        $b = $this->makeGenerator('bar', 'Bar');

        $reg = new GeneratorRegistry([$a, $b]);
        $this->assertTrue($reg->has('foo'));
        $this->assertTrue($reg->has('bar'));
        $this->assertFalse($reg->has('baz'));
        $this->assertSame($a, $reg->get('foo'));

        $choices = $reg->choices();
        $this->assertSame(['Bar' => 'bar', 'Foo' => 'foo'], $choices);
    }

    public function testDuplicateKeyThrows(): void
    {
        $this->expectException(\LogicException::class);
        new GeneratorRegistry([
            $this->makeGenerator('dup', 'A'),
            $this->makeGenerator('dup', 'B'),
        ]);
    }

    public function testGetUnknownThrows(): void
    {
        $reg = new GeneratorRegistry([]);
        $this->expectException(\InvalidArgumentException::class);
        $reg->get('missing');
    }

    private function makeGenerator(string $key, string $label): PlaylistGeneratorInterface
    {
        return new class ($key, $label) implements PlaylistGeneratorInterface {
            public function __construct(private string $k, private string $l)
            {
            }
            public function getKey(): string
            {
                return $this->k;
            }
            public function getLabel(): string
            {
                return $this->l;
            }
            public function getDescription(): string
            {
                return '';
            }
            public function getParameterSchema(): array
            {
                return [];
            }
            public function generate(array $parameters, int $limit): array
            {
                return [];
            }
        };
    }
}
