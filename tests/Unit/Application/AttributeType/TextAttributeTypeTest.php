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
use Sulu\Product\Application\AttributeType\TextAttributeType;
use Sulu\Product\Domain\Model\Attribute;
use Sulu\Product\Domain\Model\AttributeGroup;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\Product;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductDimensionContent;

#[CoversClass(TextAttributeType::class)]
class TextAttributeTypeTest extends TestCase
{
    public function testKeyAndFormKey(): void
    {
        $type = new TextAttributeType();
        self::assertSame(AttributeInterface::TYPE_TEXT, $type->getKey());
        self::assertSame('product_attribute_text', $type->getFormKey());
    }

    public function testValueRoundTrip(): void
    {
        $type = new TextAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');

        $type->writeValue(['value' => $row], ['value' => 'Hello']);

        self::assertSame('Hello', $row->getText());
        self::assertSame(['value' => 'Hello'], $type->readValue(['value' => $row]));
    }

    public function testDeclaresSingleValueKey(): void
    {
        self::assertSame(['value'], (new TextAttributeType())->getValueKeys());
    }

    public function testReadValueToleratesMissingRow(): void
    {
        self::assertSame(['value' => null], (new TextAttributeType())->readValue([]));
    }

    public function testWriteNullClearsText(): void
    {
        $type = new TextAttributeType();
        $row = new ProductAttributeValue(new ProductDimensionContent(new Product()), new Attribute(new AttributeGroup()), 'k');
        $type->writeValue(['value' => $row], ['value' => 'world']);

        $type->writeValue(['value' => $row], ['value' => null]);

        self::assertNull($row->getText());
    }
}
