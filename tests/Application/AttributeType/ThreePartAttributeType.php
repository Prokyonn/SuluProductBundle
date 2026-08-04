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

namespace Sulu\Product\Tests\Application\AttributeType;

use Sulu\Product\Application\AttributeType\AbstractAttributeType;
use Webmozart\Assert\Assert;

/**
 * Test-only fixture proving the multi-part attribute value mechanism generalises beyond the
 * two-key {@see \Sulu\Product\Application\AttributeType\RangeAttributeType}: it declares three
 * value keys and must round-trip through the field factory, data mapper and normalizer with no
 * change to any of those classes.
 */
final class ThreePartAttributeType extends AbstractAttributeType
{
    public function getKey(): string
    {
        return 'three_part';
    }

    public function getFormKey(): string
    {
        return 'product_attribute_three_part';
    }

    public function getValueKeys(): array
    {
        return ['a', 'b', 'c'];
    }

    public function readValue(array $values): array
    {
        return [
            'a' => ($values['a'] ?? null)?->getNumber(),
            'b' => ($values['b'] ?? null)?->getNumber(),
            'c' => ($values['c'] ?? null)?->getNumber(),
        ];
    }

    public function writeValue(array $values, array $raw): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $row = $values[$key] ?? null;
            $value = $raw[$key] ?? null;

            if (null === $value || '' === $value) {
                $row?->setNumber(null);

                continue;
            }

            Assert::numeric($value);
            $row?->setNumber((float) $value);
        }
    }
}
