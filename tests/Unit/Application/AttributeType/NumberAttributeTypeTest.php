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

namespace Sulu\Product\Tests\Unit\Application\AttributeType;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Product\Application\AttributeType\NumberAttributeType;
use Sulu\Product\Domain\Model\Attribute;
use Sulu\Product\Domain\Model\AttributeGroup;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\Product;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductDimensionContent;

#[CoversClass(NumberAttributeType::class)]
class NumberAttributeTypeTest extends TestCase
{
    public function testKeyAndFormKey(): void
    {
        $type = new NumberAttributeType();
        self::assertSame(AttributeInterface::TYPE_NUMBER, $type->getKey());
        self::assertSame('product_attribute_number', $type->getFormKey());
    }

    public function testValueRoundTripUsesNumberColumn(): void
    {
        $type = new NumberAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $type->writeValue(['value' => $row], ['value' => 42.5]);

        self::assertSame(42.5, $row->getNumber());
        self::assertSame(['value' => 42.5], $type->readValue(['value' => $row]));
    }

    public function testWriteNullClearsNumber(): void
    {
        $type = new NumberAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');
        $type->writeValue(['value' => $row], ['value' => 1.0]);

        $type->writeValue(['value' => $row], ['value' => null]);

        self::assertNull($row->getNumber());
    }

    public function testWriteCoercesNumericStringFromForm(): void
    {
        $type = new NumberAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $type->writeValue(['value' => $row], ['value' => '42.5']);

        self::assertSame(42.5, $row->getNumber());
    }

    public function testWriteEmptyStringClearsNumber(): void
    {
        $type = new NumberAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');
        $type->writeValue(['value' => $row], ['value' => 1.0]);

        $type->writeValue(['value' => $row], ['value' => '']);

        self::assertNull($row->getNumber());
    }

    public function testConfigureFieldAddsMinMaxStepFromConfig(): void
    {
        $type = new NumberAttributeType();
        $attribute = new Attribute(new AttributeGroup());
        $attribute->setConfig(['min' => 0, 'max' => 100, 'step' => 0.5]);

        $field = new FieldMetadata('attributes/1');
        $type->configureField($field, $attribute, 'en', 'value');

        $options = $field->getOptions();
        self::assertArrayHasKey('min', $options);
        self::assertArrayHasKey('max', $options);
        self::assertArrayHasKey('step', $options);
        self::assertSame('0', $options['min']->getValue());
        self::assertSame('100', $options['max']->getValue());
        self::assertSame('0.5', $options['step']->getValue());
    }

    public function testConfigureFieldSkipsMissingConfigKeys(): void
    {
        $type = new NumberAttributeType();
        $attribute = new Attribute(new AttributeGroup());
        $attribute->setConfig(['min' => 0]);

        $field = new FieldMetadata('attributes/1');
        $type->configureField($field, $attribute, 'en', 'value');

        $options = $field->getOptions();
        self::assertArrayHasKey('min', $options);
        self::assertArrayNotHasKey('max', $options);
        self::assertArrayNotHasKey('step', $options);
    }
}
