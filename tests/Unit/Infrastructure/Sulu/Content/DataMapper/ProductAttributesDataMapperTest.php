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

namespace Sulu\Product\Tests\Unit\Infrastructure\Sulu\Content\DataMapper;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Application\AttributeType\NumberAttributeType;
use Sulu\Product\Application\AttributeType\RangeAttributeType;
use Sulu\Product\Application\AttributeType\TextAttributeType;
use Sulu\Product\Domain\Exception\IncompleteProductAttributeRangeException;
use Sulu\Product\Domain\Exception\RequiredProductAttributeMissingException;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\Product;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;
use Sulu\Product\Domain\Model\ProductDimensionContent;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;
use Sulu\Product\Domain\Model\ProductFamily;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Sulu\Product\Domain\Model\ProductFamilyInterface;
use Sulu\Product\Domain\Model\ProductInterface;
use Sulu\Product\Infrastructure\Sulu\Content\DataMapper\ProductAttributesDataMapper;

#[CoversClass(ProductAttributesDataMapper::class)]
class ProductAttributesDataMapperTest extends TestCase
{
    use ProphecyTrait;

    private ProductAttributesDataMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProductAttributesDataMapper(
            new AttributeTypeRegistry([new NumberAttributeType(), new RangeAttributeType(), new TextAttributeType()]),
        );
    }

    public function testEarlyReturnWhenUnlocalizedNotProductDimensionContent(): void
    {
        $other = $this->prophesize(DimensionContentInterface::class);

        $this->mapper->map($other->reveal(), $other->reveal(), ['attributes' => ['1_value' => 5.0]]);

        $this->addToAssertionCount(1);
    }

    public function testEarlyReturnWhenLocalizedNotProductDimensionContent(): void
    {
        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $locOther = $this->prophesize(DimensionContentInterface::class);

        $this->mapper->map($unloc->reveal(), $locOther->reveal(), ['attributes' => ['1_value' => 5.0]]);

        $this->addToAssertionCount(1);
    }

    public function testNoOpWhenAttributesKeyAbsent(): void
    {
        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getResource()->shouldNotBeCalled();
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['locale' => 'en', 'template' => 'product']);

        $this->addToAssertionCount(1);
    }

    public function testNoOpWhenProductFamilyIsNull(): void
    {
        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getProductFamily()->willReturn(null);
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => ['1_value' => 5.0]]);

        $unloc->getAttributes()->shouldNotHaveBeenCalled();
        $this->addToAssertionCount(1);
    }

    public function testSkipsAttributeNotInFamily(): void
    {
        $fixture = $this->makeProductFixture(1, false);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['99_value' => 5.0]]);

        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->addToAssertionCount(1);
    }

    public function testIgnoresUnitSidecarKey(): void
    {
        $fixture = $this->makeProductFixture(1, false);

        // "1_unit" is submitted alongside a number attribute value (unit selector); it must be ignored
        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => 7.5, '1_unit' => 'KILOGRAM']]);

        $fixture['unloc_prophecy']->addAttribute(Argument::that(
            static fn ($v): bool => $v instanceof ProductAttributeValueInterface && 7.5 === $v->getNumber()
        ))->shouldHaveBeenCalledOnce();
    }

    public function testMapsSuffixedPayloadKey(): void
    {
        $fixture = $this->makeProductFixture(7, false);
        $fixture['unloc_prophecy']->addAttribute(Argument::that(
            static fn ($v): bool => $v instanceof ProductAttributeValueInterface
                && 'value' === $v->getValueKey()
                && 42.0 === $v->getNumber()
        ))->shouldBeCalledOnce()->willReturn($fixture['unloc_prophecy']->reveal());

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_value' => 42.0]]);
    }

    public function testIgnoresUnknownValueKey(): void
    {
        $fixture = $this->makeProductFixture(7, false);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_bogus' => 42.0]]);

        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->addToAssertionCount(1);
    }

    public function testIgnoresNonStringKeys(): void
    {
        $fixture = $this->makeProductFixture(1, false);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => [1 => 5.0]]);

        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->addToAssertionCount(1);
    }

    public function testIgnoresMalformedStringKeys(): void
    {
        $fixture = $this->makeProductFixture(1, false);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['not-an-attribute-key' => 5.0]]);

        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->addToAssertionCount(1);
    }

    public function testCreatesNewAttributeValue(): void
    {
        $fixture = $this->makeProductFixture(1, false);
        $fixture['unloc_prophecy']->addAttribute(Argument::that(
            static fn ($v): bool => $v instanceof ProductAttributeValueInterface && 7.5 === $v->getNumber()
        ))->shouldBeCalled()->willReturn($fixture['unloc_prophecy']->reveal());

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => 7.5]]);
    }

    public function testRemovesValueWhenNull(): void
    {
        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(1);
        $attribute->getKey()->willReturn('attr-1');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn(false);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);

        // The row's own dimension content is what removal targets, so it must be the same
        // object the mapper receives — not an unrelated dimension content instance.
        $existingValue = new ProductAttributeValue($unloc->reveal(), $attribute->reveal(), 'attr-1');
        $existingValue->setNumber(5.0);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($this->prophesizeNonVariantResource());
        $unloc->getAttributes()->willReturn(new ArrayCollection([$existingValue]));
        $unloc->removeAttribute($existingValue)->shouldBeCalled()->willReturn($unloc->reveal());
        $unloc->addAttribute(Argument::cetera())->shouldNotBeCalled();
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);
        $loc->getAttributes()->willReturn(new ArrayCollection());

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => ['1_value' => null]]);
    }

    public function testIsEmptyForEmptyString(): void
    {
        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(1);
        $attribute->getKey()->willReturn('attr-1');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn(false);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);

        $existingValue = new ProductAttributeValue($unloc->reveal(), $attribute->reveal(), 'attr-1');
        $existingValue->setNumber(3.0);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($this->prophesizeNonVariantResource());
        $unloc->getAttributes()->willReturn(new ArrayCollection([$existingValue]));
        $unloc->removeAttribute($existingValue)->shouldBeCalled()->willReturn($unloc->reveal());
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);
        $loc->getAttributes()->willReturn(new ArrayCollection());

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => ['1_value' => '']]);
    }

    public function testRequiredMissingThrows(): void
    {
        $fixture = $this->makeProductFixture(1, true);
        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(RequiredProductAttributeMissingException::class);
        $this->expectExceptionMessage('attr-1');

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => null]]);
    }

    public function testRequiredWithValuePasses(): void
    {
        $fixture = $this->makeProductFixture(1, true);
        $fixture['unloc_prophecy']->addAttribute(Argument::that(
            static fn ($v): bool => $v instanceof ProductAttributeValueInterface && 10.0 === $v->getNumber()
        ))->shouldBeCalled()->willReturn($fixture['unloc_prophecy']->reveal());

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => 10.0]]);

        $this->addToAssertionCount(1);
    }

    public function testRequiredExistingButEmptyValueThrows(): void
    {
        $concretePdc = new ProductDimensionContent(new Product());

        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(1);
        $attribute->getKey()->willReturn('attr-1');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn(false);

        // Row exists but was never populated (e.g. left over from a prior partial save) — empty per readValue().
        $existingValue = new ProductAttributeValue($concretePdc, $attribute->reveal(), 'attr-1');

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(true);
        $familyAttribute->isVariantSpecific()->willReturn(false);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($this->prophesizeNonVariantResource());
        $unloc->getAttributes()->willReturn(new ArrayCollection([$existingValue]));
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);
        $loc->getAttributes()->willReturn(new ArrayCollection());

        $this->expectException(RequiredProductAttributeMissingException::class);
        $this->expectExceptionMessage('attr-1');

        // Submitted data doesn't mention attribute 1, so the existing (empty) row is left untouched.
        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => []]);
    }

    public function testUpdatesExistingValueInPlace(): void
    {
        $concretePdc = new ProductDimensionContent(new Product());

        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(1);
        $attribute->getKey()->willReturn('attr-1');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn(false);

        $existingValue = new ProductAttributeValue($concretePdc, $attribute->reveal(), 'attr-1');
        $existingValue->setNumber(1.0);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($this->prophesizeNonVariantResource());
        $unloc->getAttributes()->willReturn(new ArrayCollection([$existingValue]));
        $unloc->addAttribute(Argument::cetera())->shouldNotBeCalled();
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);
        $loc->getAttributes()->willReturn(new ArrayCollection());

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => ['1_value' => 99.0]]);

        $this->assertSame(99.0, $existingValue->getNumber());
    }

    public function testCreatesLocalizedAttributeOnLocalizedDimensionContent(): void
    {
        $fixture = $this->makeProductFixture(1, false, true);

        $fixture['loc_prophecy']->addAttribute(Argument::that(
            static fn ($v): bool => $v instanceof ProductAttributeValueInterface && 7.5 === $v->getNumber()
        ))->shouldBeCalledOnce()->willReturn($fixture['loc_prophecy']->reveal());

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => 7.5]]);

        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function testRemovesLocalizedValueFromLocalizedDimensionContent(): void
    {
        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(1);
        $attribute->getKey()->willReturn('attr-1');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn(true);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);

        $existingValue = new ProductAttributeValue($loc->reveal(), $attribute->reveal(), 'attr-1');
        $existingValue->setNumber(5.0);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($this->prophesizeNonVariantResource());
        $unloc->getAttributes()->willReturn(new ArrayCollection());
        $unloc->removeAttribute(Argument::cetera())->shouldNotBeCalled();
        $loc->getAttributes()->willReturn(new ArrayCollection([$existingValue]));
        $loc->removeAttribute($existingValue)->shouldBeCalled()->willReturn($loc->reveal());

        $this->mapper->map($unloc->reveal(), $loc->reveal(), ['attributes' => ['1_value' => null]]);
    }

    public function testVariantSkipsRequiredNonVariantAttribute(): void
    {
        $fixture = $this->makeProductFixture(1, required: true, isVariantAttribute: false, isVariantResource: true);
        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotBeCalled();

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => null]]);

        $this->addToAssertionCount(1);
    }

    public function testVariantStillEnforcesRequiredVariantAttribute(): void
    {
        $fixture = $this->makeProductFixture(1, required: true, isVariantAttribute: true, isVariantResource: true);
        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(RequiredProductAttributeMissingException::class);
        $this->expectExceptionMessage('attr-1');

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => null]]);
    }

    public function testNonVariantProductStillEnforcesRequiredNonVariantAttribute(): void
    {
        $fixture = $this->makeProductFixture(1, required: true, isVariantAttribute: false, isVariantResource: false);
        $fixture['unloc_prophecy']->addAttribute(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(RequiredProductAttributeMissingException::class);
        $this->expectExceptionMessage('attr-1');

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['1_value' => null]]);
    }

    public function testCreatesBothRangeRows(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
        ]);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '10', '7_max' => '20']]);

        self::assertCount(2, $fixture['unloc']->getAttributes());
    }

    public function testExplicitFullClearRemovesEveryPart(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
        ]);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '10', '7_max' => '20']]);
        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '', '7_max' => '']]);

        self::assertCount(0, $fixture['unloc']->getAttributes());
    }

    public function testPartialSubmissionThrowsAndAttachesNothing(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
        ]);

        try {
            $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '10']]);
            self::fail('Expected a validation exception.');
        } catch (IncompleteProductAttributeRangeException) {
            // expected
        }

        self::assertCount(0, $fixture['unloc']->getAttributes());
    }

    public function testAttributeAbsentFromPayloadKeepsStoredRows(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
            ['id' => 9, 'type' => AttributeInterface::TYPE_TEXT],
        ]);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '10', '7_max' => '20']]);
        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['9_value' => 'x']]);

        self::assertCount(3, $fixture['unloc']->getAttributes());   // 7_min, 7_max, 9_value
    }

    public function testZeroBoundIsNotTreatedAsEmpty(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
        ]);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '0', '7_max' => '0']]);

        self::assertCount(2, $fixture['unloc']->getAttributes());
    }

    public function testRequiredRangeWithOnlyOnePartFails(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE, 'required' => true],
        ]);

        // Pre-seed a single 'min' row directly on the dimension content; 'max' was never written.
        $existingMin = new ProductAttributeValue($fixture['unloc'], $fixture['attributes'][7], 'attr-7', 'min');
        $existingMin->setNumber(10.0);
        $fixture['unloc']->addAttribute($existingMin);

        $this->expectException(RequiredProductAttributeMissingException::class);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => []]);
    }

    public function testVariantIgnoresNonVariantSpecificAttribute(): void
    {
        $fixture = $this->makeMultiPartFixture(
            [['id' => 7, 'type' => AttributeInterface::TYPE_TEXT, 'variantSpecific' => false]],
            isVariantResource: true,
        );

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_value' => 'x']]);

        self::assertCount(0, $fixture['unloc']->getAttributes());
    }

    public function testHalfFilledRangeWithAbsentKeyIsRejectedNotCleared(): void
    {
        $fixture = $this->makeMultiPartFixture([
            ['id' => 7, 'type' => AttributeInterface::TYPE_RANGE],
        ]);

        $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => '10', '7_max' => '20']]);

        // '7_max' is entirely absent from the payload, not just empty — a half-filled range,
        // not an explicit full clear. Must be rejected, and the stored rows must survive.
        try {
            $this->mapper->map($fixture['unloc'], $fixture['loc'], ['attributes' => ['7_min' => null]]);
            self::fail('Expected a validation exception.');
        } catch (IncompleteProductAttributeRangeException) {
            // expected
        }

        self::assertCount(2, $fixture['unloc']->getAttributes());
    }

    /**
     * @param list<array{id: int, type: string, required?: bool, variantSpecific?: bool}> $attributeSpecs
     *
     * @return array{
     *     unloc: ProductDimensionContentInterface,
     *     loc: ProductDimensionContentInterface,
     *     attributes: array<int, AttributeInterface>,
     * }
     */
    private function makeMultiPartFixture(array $attributeSpecs, bool $isVariantResource = false): array
    {
        $family = new ProductFamily();
        $attributes = [];

        foreach ($attributeSpecs as $spec) {
            /** @var ObjectProphecy<AttributeInterface> $attributeProphecy */
            $attributeProphecy = $this->prophesize(AttributeInterface::class);
            $attributeProphecy->getId()->willReturn($spec['id']);
            $attributeProphecy->getKey()->willReturn('attr-' . $spec['id']);
            $attributeProphecy->getType()->willReturn($spec['type']);
            $attributeProphecy->isLocalized()->willReturn(false);
            $attribute = $attributeProphecy->reveal();
            $attributes[$spec['id']] = $attribute;

            /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttributeProphecy */
            $familyAttributeProphecy = $this->prophesize(ProductFamilyAttributeInterface::class);
            $familyAttributeProphecy->getAttribute()->willReturn($attribute);
            $familyAttributeProphecy->isRequired()->willReturn($spec['required'] ?? false);
            $familyAttributeProphecy->isVariantSpecific()->willReturn($spec['variantSpecific'] ?? true);

            $family->addFamilyAttribute($familyAttributeProphecy->reveal());
        }

        /** @var ObjectProphecy<ProductInterface> $resource */
        $resource = $this->prophesize(ProductInterface::class);
        $resource->isType(ProductInterface::TYPE_VARIANT)->willReturn($isVariantResource);

        $unloc = new ProductDimensionContent($resource->reveal());
        $unloc->setProductFamily($family);

        $loc = new ProductDimensionContent($resource->reveal());

        return ['unloc' => $unloc, 'loc' => $loc, 'attributes' => $attributes];
    }

    private function prophesizeNonVariantResource(): ProductInterface
    {
        /** @var ObjectProphecy<ProductInterface> $resource */
        $resource = $this->prophesize(ProductInterface::class);
        $resource->isType(ProductInterface::TYPE_VARIANT)->willReturn(false);

        return $resource->reveal();
    }

    /**
     * @return array{
     *     unloc_prophecy: ObjectProphecy<ProductDimensionContentInterface>,
     *     loc_prophecy: ObjectProphecy<ProductDimensionContentInterface>,
     *     unloc: ProductDimensionContentInterface,
     *     loc: ProductDimensionContentInterface,
     *     attribute: AttributeInterface,
     *     familyAttribute: ProductFamilyAttributeInterface,
     * }
     */
    private function makeProductFixture(
        int $attributeId,
        bool $required,
        bool $localized = false,
        bool $isVariantAttribute = false,
        bool $isVariantResource = false,
    ): array {
        /** @var ObjectProphecy<AttributeInterface> $attribute */
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn($attributeId);
        $attribute->getKey()->willReturn('attr-' . $attributeId);
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->isLocalized()->willReturn($localized);

        /** @var ObjectProphecy<ProductFamilyAttributeInterface> $familyAttribute */
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn($required);
        $familyAttribute->isVariantSpecific()->willReturn($isVariantAttribute);

        /** @var ObjectProphecy<ProductFamilyInterface> $family */
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        /** @var ObjectProphecy<ProductInterface> $resource */
        $resource = $this->prophesize(ProductInterface::class);
        $resource->isType(ProductInterface::TYPE_VARIANT)->willReturn($isVariantResource);

        /** @var ObjectProphecy<ProductDimensionContentInterface> $unloc */
        $unloc = $this->prophesize(ProductDimensionContentInterface::class);
        $unloc->getProductFamily()->willReturn($family->reveal());
        $unloc->getResource()->willReturn($resource->reveal());
        $unloc->getAttributes()->willReturn(new ArrayCollection());
        $unloc->addAttribute(Argument::cetera())->willReturn($unloc->reveal());
        $unloc->removeAttribute(Argument::cetera())->willReturn($unloc->reveal());
        /** @var ObjectProphecy<ProductDimensionContentInterface> $loc */
        $loc = $this->prophesize(ProductDimensionContentInterface::class);
        $loc->getAttributes()->willReturn(new ArrayCollection());
        $loc->addAttribute(Argument::cetera())->willReturn($loc->reveal());
        $loc->removeAttribute(Argument::cetera())->willReturn($loc->reveal());

        return [
            'unloc_prophecy' => $unloc,
            'loc_prophecy' => $loc,
            'unloc' => $unloc->reveal(),
            'loc' => $loc->reveal(),
            'attribute' => $attribute->reveal(),
            'familyAttribute' => $familyAttribute->reveal(),
        ];
    }
}
