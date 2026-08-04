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

namespace Sulu\Product\Infrastructure\Sulu\Content\DataMapper;

use Sulu\Content\Application\ContentDataMapper\DataMapper\DataMapperInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Domain\Exception\RequiredProductAttributeMissingException;
use Sulu\Product\Domain\Model\ProductAttributeValue;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Sulu\Product\Domain\Model\ProductInterface;

class ProductAttributesDataMapper implements DataMapperInterface
{
    public function __construct(
        private readonly AttributeTypeRegistry $attributeTypeRegistry,
    ) {
    }

    public function map(
        DimensionContentInterface $unlocalizedDimensionContent,
        DimensionContentInterface $localizedDimensionContent,
        array $data,
    ): void {
        if (!$unlocalizedDimensionContent instanceof ProductDimensionContentInterface) {
            return;
        }

        if (!$localizedDimensionContent instanceof ProductDimensionContentInterface) {
            return;
        }

        if (!\array_key_exists('attributes', $data)) {
            return;
        }

        $productFamily = $unlocalizedDimensionContent->getProductFamily();
        if (null === $productFamily) {
            return;
        }

        /** @var array<int|string, mixed> $submitted */
        $submitted = $data['attributes'] ?? [];

        /** @var array<int, ProductFamilyAttributeInterface> $familyAttributes */
        $familyAttributes = [];
        foreach ($productFamily->getFamilyAttributes() as $familyAttribute) {
            $familyAttributes[$familyAttribute->getAttribute()->getId()] = $familyAttribute;
        }

        /** @var array<int, array<string, ProductAttributeValueInterface>> $allExisting */
        $allExisting = [];
        foreach ($unlocalizedDimensionContent->getAttributes() as $value) {
            $allExisting[$value->getAttribute()->getId()][$value->getValueKey()] = $value;
        }
        foreach ($localizedDimensionContent->getAttributes() as $value) {
            $allExisting[$value->getAttribute()->getId()][$value->getValueKey()] = $value;
        }

        /** @var array<int, array<string, mixed>> $grouped */
        $grouped = [];
        foreach ($submitted as $key => $raw) {
            if (!\is_string($key) || 1 !== \preg_match('/^(\d+)_([a-zA-Z0-9]+)$/', $key, $m)) {
                continue;
            }

            $attributeId = (int) $m[1];
            $familyAttribute = $familyAttributes[$attributeId] ?? null;
            if (null === $familyAttribute) {
                continue;
            }

            $type = $this->attributeTypeRegistry->get($familyAttribute->getAttribute()->getType());
            if (!\in_array($m[2], $type->getValueKeys(), true)) {
                continue;   // drops the _unit sidecar and any unknown key
            }

            $grouped[$attributeId][$m[2]] = $raw;
        }

        foreach ($grouped as $attributeId => $rawByKey) {
            $familyAttribute = $familyAttributes[$attributeId];
            $attribute = $familyAttribute->getAttribute();
            $targetDimensionContent = $attribute->isLocalized()
                ? $localizedDimensionContent
                : $unlocalizedDimensionContent;
            $type = $this->attributeTypeRegistry->get($attribute->getType());
            $existingRows = $allExisting[$attributeId] ?? [];

            if ($this->isEmptyGroup($rawByKey)) {
                foreach ($existingRows as $existingRow) {
                    $targetDimensionContent->removeAttribute($existingRow);
                }
                unset($allExisting[$attributeId]);

                continue;
            }

            /** @var array<string, ProductAttributeValueInterface> $rowsByKey */
            $rowsByKey = [];
            $newRows = [];
            foreach ($type->getValueKeys() as $valueKey) {
                $row = $existingRows[$valueKey] ?? null;
                if (null === $row) {
                    $row = new ProductAttributeValue($targetDimensionContent, $attribute, $attribute->getKey(), $valueKey);
                    $row->setProductFamilyAttribute($familyAttribute);
                    $newRows[] = $row;
                }

                $rowsByKey[$valueKey] = $row;
            }

            $type->writeValue($rowsByKey, $rawByKey);

            foreach ($newRows as $newRow) {
                $targetDimensionContent->addAttribute($newRow);
            }

            $allExisting[$attributeId] = $rowsByKey;
        }

        $isVariant = $unlocalizedDimensionContent->getResource()->isType(ProductInterface::TYPE_VARIANT);

        $this->assertRequiredSatisfied($familyAttributes, $allExisting, $isVariant);
    }

    /**
     * @param array<int, ProductFamilyAttributeInterface> $familyAttributes
     * @param array<int, array<string, ProductAttributeValueInterface>> $existingByAttributeId
     *
     * @throws RequiredProductAttributeMissingException
     */
    private function assertRequiredSatisfied(array $familyAttributes, array $existingByAttributeId, bool $isVariant): void
    {
        foreach ($familyAttributes as $attributeId => $familyAttribute) {
            if (!$familyAttribute->isRequired()) {
                continue;
            }

            if ($isVariant && !$familyAttribute->isVariantSpecific()) {
                // Shared attributes are inherited from (and required on) the parent, not the variant.
                continue;
            }

            $rows = $existingByAttributeId[$attributeId] ?? null;
            if (null === $rows) {
                throw new RequiredProductAttributeMissingException($familyAttribute->getAttribute()->getKey());
            }

            $type = $this->attributeTypeRegistry->get($familyAttribute->getAttribute()->getType());
            $read = $type->readValue($rows);

            foreach ($type->getValueKeys() as $valueKey) {
                if ($this->isEmpty($read[$valueKey] ?? null)) {
                    throw new RequiredProductAttributeMissingException($familyAttribute->getAttribute()->getKey());
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $rawByKey
     */
    private function isEmptyGroup(array $rawByKey): bool
    {
        foreach ($rawByKey as $raw) {
            if (!$this->isEmpty($raw)) {
                return false;
            }
        }

        return true;
    }

    private function isEmpty(mixed $raw): bool
    {
        return null === $raw || '' === $raw;
    }
}
