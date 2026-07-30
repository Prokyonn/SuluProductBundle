<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Product\Tests\Unit\Infrastructure\Sulu\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Sulu\Product\Domain\Model\ProductFamilyInterface;
use Sulu\Product\Infrastructure\Sulu\Content\ProductAttributeDefaultValueEnhancer;

#[CoversClass(ProductAttributeDefaultValueEnhancer::class)]
class ProductAttributeDefaultValueEnhancerTest extends TestCase
{
    use ProphecyTrait;

    private ProductAttributeDefaultValueEnhancer $enhancer;

    protected function setUp(): void
    {
        $this->enhancer = new ProductAttributeDefaultValueEnhancer();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function dimensionContentWithAttribute(
        array $config,
        string $type = AttributeInterface::TYPE_TEXT,
    ): ProductDimensionContentInterface {
        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(42);
        $attribute->getType()->willReturn($type);
        $attribute->getConfig()->willReturn($config);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $dc */
        $dc = $this->prophesize(ProductDimensionContentInterface::class);
        $dc->getProductFamily()->willReturn($family->reveal());

        return $dc->reveal();
    }

    public function testEnhanceWithoutProductFamilyReturnsDataUnchanged(): void
    {
        /** @var ObjectProphecy<ProductDimensionContentInterface> $dc */
        $dc = $this->prophesize(ProductDimensionContentInterface::class);
        $dc->getProductFamily()->willReturn(null);

        $data = ['attributes' => [42 => null]];

        $this->assertSame($data, $this->enhancer->enhance($dc->reveal(), $data));
    }

    public function testEnhanceWithoutAttributesKeyReturnsDataUnchanged(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => 'fallback']);

        $data = ['title' => 'Product'];

        $this->assertSame($data, $this->enhancer->enhance($dc, $data));
    }

    public function testEnhanceFillsTextDefaultValue(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => '> 2 GΩ']);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertSame('> 2 GΩ', $result['attributes'][42]);
    }

    public function testEnhanceFillsNumericDefaultValueAsFloat(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => '2.5'], AttributeInterface::TYPE_NUMBER);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertSame(2.5, $result['attributes'][42]);
    }

    public function testEnhanceKeepsStoredValueOverDefaultValue(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => '2.5'], AttributeInterface::TYPE_NUMBER);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => 9.75]]);

        $this->assertIsArray($result['attributes']);
        $this->assertSame(9.75, $result['attributes'][42]);
    }

    public function testEnhanceIgnoresNonNumericDefaultValueForNumberAttribute(): void
    {
        $dc = $this->dimensionContentWithAttribute(
            ['defaultValue' => 'not-a-number'],
            AttributeInterface::TYPE_NUMBER,
        );

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresDefaultValueForJsonAttribute(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => 'ignored'], AttributeInterface::TYPE_JSON);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresDefaultValueForOptionsAttribute(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => 'ignored'], AttributeInterface::TYPE_OPTIONS);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresEmptyDefaultValue(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => '']);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresNonStringDefaultValue(): void
    {
        $dc = $this->dimensionContentWithAttribute(['defaultValue' => 42]);

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresNumericDefaultValueBelowMin(): void
    {
        $dc = $this->dimensionContentWithAttribute(
            ['defaultValue' => '2.5', 'min' => 10],
            AttributeInterface::TYPE_NUMBER,
        );

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceIgnoresNumericDefaultValueAboveMax(): void
    {
        $dc = $this->dimensionContentWithAttribute(
            ['defaultValue' => '2.5', 'max' => 1],
            AttributeInterface::TYPE_NUMBER,
        );

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertNull($result['attributes'][42]);
    }

    public function testEnhanceFillsNumericDefaultValueWithinMinAndMax(): void
    {
        $dc = $this->dimensionContentWithAttribute(
            ['defaultValue' => '2.5', 'min' => 0, 'max' => 10],
            AttributeInterface::TYPE_NUMBER,
        );

        $result = $this->enhancer->enhance($dc, ['attributes' => [42 => null]]);

        $this->assertIsArray($result['attributes']);
        $this->assertSame(2.5, $result['attributes'][42]);
    }
}
