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

namespace Sulu\Product\Tests\Unit\Infrastructure\Sulu\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataLoaderInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Product\Application\AttributeType\AbstractAttributeType;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Application\AttributeType\NumberAttributeType;
use Sulu\Product\Domain\Measurement\MeasurementRegistry;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\AttributeTranslationInterface;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Sulu\Product\Infrastructure\Sulu\Admin\AttributeFieldFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AttributeFieldFactory::class)]
class AttributeFieldFactoryTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<FormMetadataLoaderInterface> */
    private ObjectProphecy $formMetadataLoader;

    protected function setUp(): void
    {
        $this->formMetadataLoader = $this->prophesize(FormMetadataLoaderInterface::class);
    }

    private function factory(): AttributeFieldFactory
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Unit');

        return new AttributeFieldFactory(
            new AttributeTypeRegistry([new NumberAttributeType()]),
            $this->formMetadataLoader->reveal(),
            new MeasurementRegistry(),
            $translator,
        );
    }

    /**
     * A 'value' field carrying an option and a block type, so the option/block-type
     * cloning loops in {@see AttributeFieldFactory::cloneFieldWithName()} run at least once.
     */
    private function fragmentWithValueField(): FormMetadata
    {
        $field = new FieldMetadata('value');
        $field->setType('number');
        $field->setColSpan(12);

        $option = new OptionMetadata();
        $option->setName('step');
        $option->setValue('1');
        $field->addOption($option);

        $blockType = new FormMetadata();
        $blockType->setKey('block_type_1');
        $field->addType($blockType);

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_number');
        $fragment->addItem($field);

        return $fragment;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return ObjectProphecy<AttributeInterface>
     */
    private function attribute(
        int $id,
        string $key,
        string $type,
        array $config,
        ?string $localeTranslationName,
        ?string $description = null,
        ?string $defaultLocale = null,
        ?string $defaultLocaleTranslationName = null,
    ): ObjectProphecy {
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn($id);
        $attribute->getKey()->willReturn($key);
        $attribute->getType()->willReturn($type);
        $attribute->getConfig()->willReturn($config);
        $attribute->getDefaultLocale()->willReturn($defaultLocale);

        if (null !== $localeTranslationName) {
            $translation = $this->prophesize(AttributeTranslationInterface::class);
            $translation->getName()->willReturn($localeTranslationName);
            $translation->getDescription()->willReturn($description);
            $attribute->getTranslation('en')->willReturn($translation->reveal());
        } else {
            $attribute->getTranslation('en')->willReturn(null);
        }

        if (null !== $defaultLocale) {
            if (null !== $defaultLocaleTranslationName) {
                $defaultTranslation = $this->prophesize(AttributeTranslationInterface::class);
                $defaultTranslation->getName()->willReturn($defaultLocaleTranslationName);
                $defaultTranslation->getDescription()->willReturn(null);
                $attribute->getTranslation($defaultLocale)->willReturn($defaultTranslation->reveal());
            } else {
                $attribute->getTranslation($defaultLocale)->willReturn(null);
            }
        }

        return $attribute;
    }

    /**
     * @return ObjectProphecy<ProductFamilyAttributeInterface>
     */
    private function familyAttribute(AttributeInterface $attribute, bool $required = false): ObjectProphecy
    {
        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute);
        $familyAttribute->isRequired()->willReturn($required);

        return $familyAttribute;
    }

    public function testReturnsNullWhenAttributeTypeIsUnknown(): void
    {
        $attribute = $this->attribute(1, 'color', AttributeInterface::TYPE_TEXT, [], 'Color');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        self::assertNull($this->factory()->build($familyAttribute->reveal(), 'en'));
    }

    public function testReturnsNullWhenTemplateIsNotFormMetadata(): void
    {
        $attribute = $this->attribute(1, 'weight', AttributeInterface::TYPE_NUMBER, [], 'Weight');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])->willReturn(null);

        self::assertNull($this->factory()->build($familyAttribute->reveal(), 'en'));
    }

    public function testReturnsNullWhenTemplateHasNoValueField(): void
    {
        $attribute = $this->attribute(1, 'weight', AttributeInterface::TYPE_NUMBER, [], 'Weight');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_number');

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])->willReturn($fragment);

        self::assertNull($this->factory()->build($familyAttribute->reveal(), 'en'));
    }

    public function testBuildsFieldWithTranslationForRequestedLocaleAndNoUnit(): void
    {
        $attribute = $this->attribute(7, 'weight', AttributeInterface::TYPE_NUMBER, [], 'Weight', '<b>Heavy</b> item');
        $familyAttribute = $this->familyAttribute($attribute->reveal(), true);

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields, $unitField] = $result;

        self::assertCount(1, $fields);
        self::assertSame('attributes/7_value', $fields[0]->getName());
        self::assertSame('Weight', $fields[0]->getLabel('en'));
        self::assertTrue($fields[0]->isRequired());
        self::assertSame('Heavy item', $fields[0]->getDescription('en'));
        self::assertSame(12, $fields[0]->getColSpan());
        self::assertNull($unitField);
    }

    public function testSingleKeyTypeProducesSuffixedField(): void
    {
        $attribute = $this->attribute(7, 'weight', AttributeInterface::TYPE_NUMBER, [], 'Weight');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields, $unitField] = $result;

        self::assertCount(1, $fields);
        self::assertSame('attributes/7_value', $fields[0]->getName());
        self::assertSame(12, $fields[0]->getColSpan());
        self::assertNull($unitField);
    }

    public function testUnitSidecarShrinksSingleValueFieldToEight(): void
    {
        $attribute = $this->attribute(7, 'length', AttributeInterface::TYPE_NUMBER, ['unit' => 'MILLIMETER'], 'Length');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields, $unitField] = $result;

        self::assertSame(8, $fields[0]->getColSpan());
        self::assertNotNull($unitField);
        self::assertSame('attributes/7_unit', $unitField->getName());
    }

    /**
     * A stub two-key type (standing in for the future `range` type) exercises the multi-key
     * label suffix and the colspan-fitting remainder top-up, neither of which any currently
     * registered single-key type can reach.
     */
    public function testMultiKeyTypeProducesLabeledFieldsAndDistributesColSpanRemainder(): void
    {
        $stubType = new class() extends AbstractAttributeType {
            public function getKey(): string
            {
                return 'stub-multi';
            }

            public function getFormKey(): string
            {
                return 'product_attribute_stub_multi';
            }

            public function getValueKeys(): array
            {
                return ['min', 'max'];
            }

            public function readValue(array $values): array
            {
                return ['min' => null, 'max' => null];
            }

            public function writeValue(array $values, array $raw): void
            {
            }
        };

        $minField = new FieldMetadata('min');
        $minField->setType('number');
        $minField->setColSpan(5);

        $maxField = new FieldMetadata('max');
        $maxField->setType('number');
        $maxField->setColSpan(5);

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_stub_multi');
        $fragment->addItem($minField);
        $fragment->addItem($maxField);

        $this->formMetadataLoader->getMetadata('product_attribute_stub_multi', 'en', [])
            ->willReturn($fragment);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'sulu_product.value_key_min' === $id ? 'Min' : $id,
        );

        $factory = new AttributeFieldFactory(
            new AttributeTypeRegistry([$stubType]),
            $this->formMetadataLoader->reveal(),
            new MeasurementRegistry(),
            $translator,
        );

        $attribute = $this->attribute(9, 'range', 'stub-multi', [], 'Range');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $result = $factory->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields, $unitField] = $result;

        self::assertCount(2, $fields);
        self::assertSame('attributes/9_min', $fields[0]->getName());
        self::assertSame('Range (Min)', $fields[0]->getLabel('en'));
        self::assertSame('attributes/9_max', $fields[1]->getName());
        self::assertSame('Range (max)', $fields[1]->getLabel('en'));

        // 5 + 5 fits into 12 with a remainder of 2, floored to the first field.
        self::assertSame(7, $fields[0]->getColSpan());
        self::assertSame(5, $fields[1]->getColSpan());
        self::assertNull($unitField);
    }

    public function testReturnsNullWhenDeclaredKeyHasNoTemplateProperty(): void
    {
        $stubType = new class() extends AbstractAttributeType {
            public function getKey(): string
            {
                return 'stub';
            }

            public function getFormKey(): string
            {
                return 'product_attribute_number';
            }

            public function getValueKeys(): array
            {
                return ['missing'];
            }

            public function readValue(array $values): array
            {
                return ['missing' => null];
            }

            public function writeValue(array $values, array $raw): void
            {
            }
        };

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Unit');

        $factory = new AttributeFieldFactory(
            new AttributeTypeRegistry([$stubType]),
            $this->formMetadataLoader->reveal(),
            new MeasurementRegistry(),
            $translator,
        );

        $attribute = $this->attribute(1, 'mystery', 'stub', [], 'Mystery');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        self::assertNull($factory->build($familyAttribute->reveal(), 'en'));
    }

    public function testFallsBackToDefaultLocaleTranslationWhenRequestedLocaleHasNone(): void
    {
        $attribute = $this->attribute(
            2,
            'weight',
            AttributeInterface::TYPE_NUMBER,
            [],
            null,
            null,
            'de',
            'Gewicht',
        );
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields] = $result;

        self::assertSame('Gewicht', $fields[0]->getLabel('en'));
        self::assertNull($fields[0]->getDescription('en'));
    }

    public function testFallsBackToAttributeKeyWhenNoTranslationExists(): void
    {
        $attribute = $this->attribute(3, 'weight', AttributeInterface::TYPE_NUMBER, [], null);
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields] = $result;

        self::assertSame('weight', $fields[0]->getLabel('en'));
    }

    public function testBuildsUnitFieldWhenAttributeHasUnitConfigured(): void
    {
        $attribute = $this->attribute(4, 'length', AttributeInterface::TYPE_NUMBER, ['unit' => 'MILLIMETER'], 'Length');
        $familyAttribute = $this->familyAttribute($attribute->reveal());

        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $result = $this->factory()->build($familyAttribute->reveal(), 'en');

        self::assertNotNull($result);
        [$fields, $unitField] = $result;

        self::assertSame(8, $fields[0]->getColSpan());

        self::assertNotNull($unitField);
        self::assertSame('attributes/4_unit', $unitField->getName());
        self::assertSame('single_select', $unitField->getType());
        self::assertSame('true', $unitField->getDisabledCondition());

        $values = $unitField->findOption('values');
        self::assertNotNull($values);
        self::assertSame(OptionMetadata::TYPE_COLLECTION, $values->getType());

        $valueOptions = $values->getValue();
        self::assertIsArray($valueOptions);
        self::assertCount(1, $valueOptions);
        $valueOption = $valueOptions[0];
        self::assertSame('MILLIMETER', $valueOption->getName());
        self::assertSame('MILLIMETER', $valueOption->getValue());
        self::assertSame('mm', $valueOption->getTitle('en'));
    }
}
