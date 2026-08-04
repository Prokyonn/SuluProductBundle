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

namespace Sulu\Product\Infrastructure\Sulu\Content\Normalizer;

use Sulu\Content\Application\ContentNormalizer\Normalizer\NormalizerInterface;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Domain\Measurement\MeasurementRegistry;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;

class ProductAttributesNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly AttributeTypeRegistry $attributeTypeRegistry,
        private readonly MeasurementRegistry $measurementRegistry,
    ) {
    }

    /**
     * @return string[]
     */
    public function getIgnoredAttributes(object $object): array
    {
        if (!$object instanceof ProductDimensionContentInterface) {
            return [];
        }

        return ['attributes'];
    }

    /**
     * @param array<string, mixed> $normalizedData
     *
     * @return array<string, mixed>
     */
    public function enhance(object $object, array $normalizedData): array
    {
        if (!$object instanceof ProductDimensionContentInterface) {
            return $normalizedData;
        }

        $attributesMap = [];

        $productFamily = $object->getProductFamily();
        if (null !== $productFamily) {
            foreach ($productFamily->getFamilyAttributes() as $familyAttribute) {
                $attribute = $familyAttribute->getAttribute();
                if (!$this->attributeTypeRegistry->has($attribute->getType())) {
                    continue;
                }
                $type = $this->attributeTypeRegistry->get($attribute->getType());

                foreach ($type->getValueKeys() as $key) {
                    $attributesMap[$attribute->getId() . '_' . $key] = null;
                }

                $unit = $attribute->getConfig()['unit'] ?? null;
                if (\is_string($unit) && null !== $this->measurementRegistry->findUnit($unit)) {
                    $attributesMap[$attribute->getId() . '_unit'] = $unit;
                }
            }
        }

        /** @var array<int, array{attribute: AttributeInterface, rows: array<string, ProductAttributeValueInterface>}> $byAttributeId */
        $byAttributeId = [];
        foreach ($object->getAttributes() as $attrValue) {
            $attribute = $attrValue->getAttribute();
            $attributeId = $attribute->getId();
            $byAttributeId[$attributeId]['attribute'] = $attribute;
            $byAttributeId[$attributeId]['rows'][$attrValue->getValueKey()] = $attrValue;
        }

        foreach ($byAttributeId as $attributeId => $group) {
            $type = $this->attributeTypeRegistry->get($group['attribute']->getType());

            foreach ($type->readValue($group['rows']) as $key => $value) {
                $attributesMap[$attributeId . '_' . $key] = $value;
            }
        }

        $normalizedData['attributes'] = $attributesMap;

        return $normalizedData;
    }
}
