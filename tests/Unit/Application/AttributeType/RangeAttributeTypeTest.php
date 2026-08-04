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
use Sulu\Product\Application\AttributeType\RangeAttributeType;
use Sulu\Product\Domain\Exception\IncompleteProductAttributeRangeException;
use Sulu\Product\Domain\Exception\ProductAttributeValidationException;
use Sulu\Product\Domain\Model\Attribute;
use Sulu\Product\Domain\Model\AttributeGroup;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\Product;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;
use Sulu\Product\Domain\Model\ProductDimensionContent;

#[CoversClass(RangeAttributeType::class)]
class RangeAttributeTypeTest extends TestCase
{
    /** @return array{0: RangeAttributeType, 1: ProductAttributeValueInterface, 2: ProductAttributeValueInterface} */
    private function fixture(): array
    {
        $content = new ProductDimensionContent(new Product());
        $attribute = new Attribute(new AttributeGroup());

        return [
            new RangeAttributeType(),
            new ProductAttributeValue($content, $attribute, 'k', 'min'),
            new ProductAttributeValue($content, $attribute, 'k', 'max'),
        ];
    }

    public function testKeyFormKeyAndValueKeys(): void
    {
        $type = new RangeAttributeType();
        self::assertSame(AttributeInterface::TYPE_RANGE, $type->getKey());
        self::assertSame('product_attribute_range', $type->getFormKey());
        self::assertSame(['min', 'max'], $type->getValueKeys());
    }

    public function testValueRoundTrip(): void
    {
        [$type, $min, $max] = $this->fixture();

        $type->writeValue(['min' => $min, 'max' => $max], ['min' => '10', 'max' => '20']);

        self::assertSame(10.0, $min->getNumber());
        self::assertSame(20.0, $max->getNumber());
        self::assertSame(['min' => 10.0, 'max' => 20.0], $type->readValue(['min' => $min, 'max' => $max]));
    }

    public function testZeroToZeroIsAValidRange(): void
    {
        [$type, $min, $max] = $this->fixture();

        $type->writeValue(['min' => $min, 'max' => $max], ['min' => '0', 'max' => '0']);

        self::assertSame(0.0, $min->getNumber());
        self::assertSame(0.0, $max->getNumber());
    }

    public function testMinGreaterThanMaxThrows(): void
    {
        [$type, $min, $max] = $this->fixture();

        $this->expectException(IncompleteProductAttributeRangeException::class);

        $type->writeValue(['min' => $min, 'max' => $max], ['min' => '20', 'max' => '10']);
    }

    public function testOnlyOneBoundThrows(): void
    {
        [$type, $min, $max] = $this->fixture();

        $this->expectException(IncompleteProductAttributeRangeException::class);

        $type->writeValue(['min' => $min, 'max' => $max], ['min' => '10', 'max' => '']);
    }

    public function testExistingRangeIsUnchangedAfterEveryThrow(): void
    {
        [$type, $min, $max] = $this->fixture();
        $type->writeValue(['min' => $min, 'max' => $max], ['min' => '10', 'max' => '20']);

        foreach ([['min' => '30', 'max' => '5'], ['min' => '30', 'max' => ''], ['min' => 'abc', 'max' => '40']] as $bad) {
            try {
                $type->writeValue(['min' => $min, 'max' => $max], $bad);
                self::fail('Expected a validation exception.');
            } catch (ProductAttributeValidationException) {
                // expected
            }

            self::assertSame(10.0, $min->getNumber(), 'min must not be mutated before validation completes');
            self::assertSame(20.0, $max->getNumber(), 'max must not be mutated before validation completes');
        }
    }

    public function testReadValueToleratesMissingRows(): void
    {
        self::assertSame(['min' => null, 'max' => null], (new RangeAttributeType())->readValue([]));
    }

    public function testConfigureFieldAddsMinMaxStepFromConfigToBothBounds(): void
    {
        $type = new RangeAttributeType();
        $attribute = new Attribute(new AttributeGroup());
        $attribute->setConfig(['min' => 0, 'max' => 100, 'step' => 0.5]);

        foreach (['min', 'max'] as $valueKey) {
            $field = new FieldMetadata('attributes/1_' . $valueKey);
            $type->configureField($field, $attribute, 'en', $valueKey);

            $options = $field->getOptions();
            self::assertSame('0', $options['min']->getValue());
            self::assertSame('100', $options['max']->getValue());
            self::assertSame('0.5', $options['step']->getValue());
        }
    }

    public function testConfigureFieldSkipsMissingConfigKeys(): void
    {
        $type = new RangeAttributeType();
        $attribute = new Attribute(new AttributeGroup());
        $attribute->setConfig(['min' => 0]);

        $field = new FieldMetadata('attributes/1_min');
        $type->configureField($field, $attribute, 'en', 'min');

        $options = $field->getOptions();
        self::assertArrayHasKey('min', $options);
        self::assertArrayNotHasKey('max', $options);
        self::assertArrayNotHasKey('step', $options);
    }
}
