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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataLoaderInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapper\NumberPropertyMetadataMapper;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapperRegistry;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Application\AttributeType\NumberAttributeType;
use Sulu\Product\Domain\Measurement\MeasurementRegistry;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\AttributeTranslationInterface;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Sulu\Product\Domain\Model\ProductFamilyInterface;
use Sulu\Product\Domain\Model\ProductFamilyTranslationInterface;
use Sulu\Product\Domain\Repository\ProductFamilyRepositoryInterface;
use Sulu\Product\Infrastructure\Sulu\Admin\ProductAttributeFormMetadataVisitor;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ProductAttributeFormMetadataVisitor::class)]
class ProductAttributeFormMetadataVisitorTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ProductFamilyRepositoryInterface> */
    private ObjectProphecy $productFamilyRepository;

    /** @var ObjectProphecy<FormMetadataLoaderInterface> */
    private ObjectProphecy $formMetadataLoader;

    protected function setUp(): void
    {
        $this->productFamilyRepository = $this->prophesize(ProductFamilyRepositoryInterface::class);
        $this->formMetadataLoader = $this->prophesize(FormMetadataLoaderInterface::class);
    }

    private function visitor(): ProductAttributeFormMetadataVisitor
    {
        $mapperContainer = new Container();
        $mapperContainer->set('number', new NumberPropertyMetadataMapper());

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'sulu_product.attributes' => 'Attributes',
                default => 'Unit',
            },
        );

        return new ProductAttributeFormMetadataVisitor(
            $this->productFamilyRepository->reveal(),
            new AttributeTypeRegistry([new NumberAttributeType()]),
            $this->formMetadataLoader->reveal(),
            new PropertyMetadataMapperRegistry($mapperContainer),
            new MeasurementRegistry(),
            $translator,
        );
    }

    /**
     * @return ObjectProphecy<ProductFamilyInterface>
     */
    private function familyProphecy(?string $translatedName = 'XLR Cable Connectors', int $id = 5): ObjectProphecy
    {
        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getId()->willReturn($id);
        $family->getDefaultLocale()->willReturn(null);

        $translation = null;
        if (null !== $translatedName) {
            $translationProphecy = $this->prophesize(ProductFamilyTranslationInterface::class);
            $translationProphecy->getName()->willReturn($translatedName);
            $translation = $translationProphecy->reveal();
        }

        $family->getTranslation('en')->willReturn($translation);

        return $family;
    }

    private function fragmentWithValueField(): FormMetadata
    {
        $field = new FieldMetadata('value');
        $field->setType('number');
        $field->setColSpan(12);

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_number');
        $fragment->addItem($field);

        return $fragment;
    }

    public function testIgnoresOtherForms(): void
    {
        $form = new FormMetadata();
        $form->setKey('product_family_details');  // not product_details

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        self::assertSame([], $form->getItems());
    }

    public function testNoIdInjectsNothing(): void
    {
        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', []);

        self::assertSame([], $form->getItems());
    }

    public function testInjectsFieldPerEnabledFamilyAttribute(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn('Weight in kilograms');

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(true);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $items = $form->getItems();
        self::assertArrayHasKey('product_family_5', $items);
        $section = $items['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        self::assertSame('XLR Cable Connectors', $section->getLabel('en'));
        $sectionItems = $section->getItems();
        self::assertArrayHasKey('attributes/7', $sectionItems);
        $field = $sectionItems['attributes/7'];
        self::assertInstanceOf(FieldMetadata::class, $field);
        self::assertSame('number', $field->getType());
        self::assertSame('Weight', $field->getLabel('en'));
        self::assertSame('Weight in kilograms', $field->getDescription('en'));
        self::assertTrue($field->isRequired());
        self::assertFalse($form->isCacheable());
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function provideMissingFamilyNames(): iterable
    {
        yield 'no translation for locale' => [null];
        yield 'empty translated name' => [''];
    }

    #[DataProvider('provideMissingFamilyNames')]
    public function testUsesGenericSectionLabelWhenFamilyNameMissing(?string $translatedName): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy($translatedName, 9);
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_9'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        self::assertSame('Attributes', $section->getLabel('en'));
    }

    public function testUsesDefaultLocaleFamilyNameWhenLocaleTranslationMissing(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $familyTranslation = $this->prophesize(ProductFamilyTranslationInterface::class);
        $familyTranslation->getName()->willReturn('XLR Kabelstecker');

        $family = $this->prophesize(ProductFamilyInterface::class);
        $family->getId()->willReturn(5);
        $family->getTranslation('en')->willReturn(null);
        $family->getDefaultLocale()->willReturn('de');
        $family->getTranslation('de')->willReturn($familyTranslation->reveal());
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        self::assertSame('XLR Kabelstecker', $section->getLabel('en'));
    }

    public function testInjectsValidationSchemaForAttributes(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn(['min' => 0, 'max' => 10]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(true);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $schema = $form->getSchema()->toJsonSchema();

        self::assertSame([
            'allOf' => [
                ['type' => ['number', 'string', 'boolean', 'object', 'array', 'null']],
                [
                    'type' => 'object',
                    'properties' => [
                        'attributes' => [
                            'type' => 'object',
                            'properties' => [
                                7 => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 10.0],
                            ],
                            'required' => ['7'],
                        ],
                    ],
                ],
            ],
        ], $schema);
    }

    public function testDoesNotSetDescriptionWhenAttributeHasNone(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        $field = $section->getItems()['attributes/7'];
        self::assertInstanceOf(FieldMetadata::class, $field);
        self::assertNull($field->getDescription('en'));
    }

    public function testSkipsAttributeWithUnknownType(): void
    {
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getType()->willReturn('unknown_type');
        $attribute->getConfig()->willReturn([]);

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        self::assertSame([], $form->getItems());
    }

    public function testSkipsAttributeWhenFragmentIsNotFormMetadata(): void
    {
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])->willReturn(null);

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        self::assertSame([], $form->getItems());
    }

    public function testCloneCopiesFragmentOptionsAndTypes(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());

        $field = new FieldMetadata('value');
        $field->setType('single_select');
        $option = new OptionMetadata();
        $option->setName('opt');
        $field->addOption($option);
        $blockType = new FormMetadata();
        $blockType->setKey('some_block');
        $field->addType($blockType);

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_number');
        $fragment->addItem($field);
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])->willReturn($fragment);

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        $injected = $section->getItems()['attributes/7'];
        self::assertInstanceOf(FieldMetadata::class, $injected);
        self::assertArrayHasKey('opt', $injected->getOptions());
        self::assertCount(1, $injected->getTypes());
    }

    public function testSkipsAttributeWhenFragmentHasNoValueField(): void
    {
        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());

        $fragment = new FormMetadata();
        $fragment->setKey('product_attribute_number');
        $other = new FieldMetadata('other');
        $other->setType('number');
        $fragment->addItem($other);
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])->willReturn($fragment);

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        self::assertSame([], $form->getItems());
    }

    public function testInjectsUnitFieldDerivedFromStoredUnit(): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn(['unit' => 'KILOGRAM']);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        $sectionItems = $section->getItems();

        $valueField = $sectionItems['attributes/7'];
        self::assertInstanceOf(FieldMetadata::class, $valueField);
        self::assertSame(8, $valueField->getColSpan());

        self::assertArrayHasKey('attributes/7_unit', $sectionItems);
        $unitField = $sectionItems['attributes/7_unit'];
        self::assertInstanceOf(FieldMetadata::class, $unitField);
        self::assertSame('single_select', $unitField->getType());
        self::assertSame(4, $unitField->getColSpan());
        self::assertSame('true', $unitField->getDisabledCondition());
        self::assertSame('Unit', $unitField->getLabel('en'));

        $valuesOption = $unitField->getOptions()['values'];
        /** @var OptionMetadata[] $valueOptions */
        $valueOptions = $valuesOption->getValue();
        self::assertCount(1, $valueOptions);
        self::assertSame('KILOGRAM', $valueOptions[0]->getName());
        self::assertSame('KILOGRAM', $valueOptions[0]->getValue());
        self::assertSame('kg', $valueOptions[0]->getTitle('en'));
    }

    public function testDoesNotInjectUnitFieldWhenUnitMissing(): void
    {
        $this->assertNoUnitFieldForConfig([]);
    }

    public function testDoesNotInjectUnitFieldWhenUnitUnknown(): void
    {
        $this->assertNoUnitFieldForConfig(['unit' => 'NON_EXISTENT_UNIT']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertNoUnitFieldForConfig(array $config): void
    {
        $translation = $this->prophesize(AttributeTranslationInterface::class);
        $translation->getName()->willReturn('Weight');
        $translation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn($config);
        $attribute->getTranslation('en')->willReturn($translation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        $sectionItems = $section->getItems();
        self::assertArrayHasKey('attributes/7', $sectionItems);
        self::assertArrayNotHasKey('attributes/7_unit', $sectionItems);
        self::assertSame(12, $sectionItems['attributes/7']->getColSpan());
    }

    public function testInjectsNothingWhenNoFamilyFound(): void
    {
        $this->productFamilyRepository->findOneBy(['productUuid' => 'missing'])->willReturn(null);

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'missing']);

        self::assertSame([], $form->getItems());
    }

    public function testUsesDefaultLocaleTranslationWhenLocaleTranslationMissing(): void
    {
        $fallbackTranslation = $this->prophesize(AttributeTranslationInterface::class);
        $fallbackTranslation->getName()->willReturn('Gewicht');
        $fallbackTranslation->getDescription()->willReturn(null);

        $attribute = $this->prophesize(AttributeInterface::class);
        $attribute->getId()->willReturn(7);
        $attribute->getKey()->willReturn('weight');
        $attribute->getType()->willReturn(AttributeInterface::TYPE_NUMBER);
        $attribute->getConfig()->willReturn([]);
        $attribute->getTranslation('en')->willReturn(null);
        $attribute->getDefaultLocale()->willReturn('de');
        $attribute->getTranslation('de')->willReturn($fallbackTranslation->reveal());

        $familyAttribute = $this->prophesize(ProductFamilyAttributeInterface::class);
        $familyAttribute->getAttribute()->willReturn($attribute->reveal());
        $familyAttribute->isRequired()->willReturn(false);

        $family = $this->familyProphecy();
        $family->getFamilyAttributes()->willReturn([$familyAttribute->reveal()]);

        $this->productFamilyRepository->findOneBy(['productUuid' => 'uuid-1'])->willReturn($family->reveal());
        $this->formMetadataLoader->getMetadata('product_attribute_number', 'en', [])
            ->willReturn($this->fragmentWithValueField());

        $form = new FormMetadata();
        $form->setKey('product_details');

        $this->visitor()->visitFormMetadata($form, 'en', ['id' => 'uuid-1']);

        $section = $form->getItems()['product_family_5'];
        self::assertInstanceOf(SectionMetadata::class, $section);
        $field = $section->getItems()['attributes/7'];
        self::assertInstanceOf(FieldMetadata::class, $field);
        self::assertSame('Gewicht', $field->getLabel('en'));
    }
}
