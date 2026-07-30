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

namespace Sulu\Product\Infrastructure\Sulu\Content;

use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;

/**
 * Fills configured attribute default values into the admin product form data.
 *
 * Defaults are form initial data only: they are never persisted by this class and a default becomes
 * stored data solely when an editor saves a form that contains it.
 */
class ProductAttributeDefaultValueEnhancer
{
    /**
     * @param array<string, mixed> $normalizedContent
     *
     * @return array<string, mixed>
     */
    public function enhance(ProductDimensionContentInterface $dimensionContent, array $normalizedContent): array
    {
        $productFamily = $dimensionContent->getProductFamily();
        if (null === $productFamily) {
            return $normalizedContent;
        }

        $attributes = $normalizedContent['attributes'] ?? null;
        if (!\is_array($attributes)) {
            return $normalizedContent;
        }

        foreach ($productFamily->getFamilyAttributes() as $familyAttribute) {
            $attribute = $familyAttribute->getAttribute();
            $id = $attribute->getId();

            if (null !== ($attributes[$id] ?? null)) {
                continue;
            }

            $attributes[$id] = $this->resolveDefaultValue($attribute);
        }

        $normalizedContent['attributes'] = $attributes;

        return $normalizedContent;
    }

    private function resolveDefaultValue(AttributeInterface $attribute): float|string|null
    {
        $config = $attribute->getConfig();

        $defaultValue = $config['defaultValue'] ?? null;
        if (!\is_string($defaultValue) || '' === $defaultValue) {
            return null;
        }

        if (AttributeInterface::TYPE_TEXT === $attribute->getType()) {
            return $defaultValue;
        }

        if (AttributeInterface::TYPE_NUMBER !== $attribute->getType() || !\is_numeric($defaultValue)) {
            return null;
        }

        $number = (float) $defaultValue;

        $min = $config['min'] ?? null;
        if (\is_numeric($min) && $number < (float) $min) {
            return null;
        }

        $max = $config['max'] ?? null;
        if (\is_numeric($max) && $number > (float) $max) {
            return null;
        }

        return $number;
    }
}
