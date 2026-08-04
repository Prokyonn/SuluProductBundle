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
use Sulu\Product\Application\AttributeType\DateAttributeType;
use Sulu\Product\Domain\Model\Attribute;
use Sulu\Product\Domain\Model\AttributeGroup;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\Product;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductDimensionContent;

#[CoversClass(DateAttributeType::class)]
class DateAttributeTypeTest extends TestCase
{
    public function testKeyAndFormKey(): void
    {
        $type = new DateAttributeType();
        self::assertSame(AttributeInterface::TYPE_DATE, $type->getKey());
        self::assertSame('product_attribute_date', $type->getFormKey());
    }

    public function testValueRoundTripStoresUnixTimestamp(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $type->writeValue(['value' => $row], ['value' => '2026-07-24']);

        $expected = (float) (new \DateTimeImmutable('2026-07-24 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        self::assertSame($expected, $row->getNumber());
        self::assertSame(['value' => '2026-07-24'], $type->readValue(['value' => $row]));
    }

    public function testWriteNullClearsNumber(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');
        $type->writeValue(['value' => $row], ['value' => '2026-07-24']);

        $type->writeValue(['value' => $row], ['value' => null]);

        self::assertNull($row->getNumber());
        self::assertSame(['value' => null], $type->readValue(['value' => $row]));
    }

    public function testWriteEmptyStringClearsNumber(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');
        $type->writeValue(['value' => $row], ['value' => '2026-07-24']);

        $type->writeValue(['value' => $row], ['value' => '']);

        self::assertNull($row->getNumber());
    }

    public function testWriteInvalidFormatThrows(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $this->expectException(\InvalidArgumentException::class);

        $type->writeValue(['value' => $row], ['value' => 'not-a-date']);
    }

    public function testWriteOverflowDateThrows(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $this->expectException(\InvalidArgumentException::class);

        $type->writeValue(['value' => $row], ['value' => '2026-02-31']);
    }

    public function testReadValueReturnsNullWhenNoNumber(): void
    {
        $type = new DateAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        self::assertSame(['value' => null], $type->readValue(['value' => $row]));
    }
}
