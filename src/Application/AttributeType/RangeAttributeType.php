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

namespace Sulu\Product\Application\AttributeType;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Product\Domain\Exception\IncompleteProductAttributeRangeException;
use Sulu\Product\Domain\Model\AttributeInterface;
use Webmozart\Assert\Assert;

final class RangeAttributeType extends AbstractAttributeType
{
    public function getKey(): string
    {
        return AttributeInterface::TYPE_RANGE;
    }

    public function getFormKey(): string
    {
        return 'product_attribute_range';
    }

    public function getValueKeys(): array
    {
        return ['min', 'max'];
    }

    public function configureField(
        FieldMetadata $field,
        AttributeInterface $attribute,
        string $locale,
        string $valueKey,
    ): void {
        $config = $attribute->getConfig();

        // These are the allowed numeric domain for each input field (from the attribute's
        // config), not the stored range bounds — despite the name collision with the 'min'/'max'
        // value keys this type declares in getValueKeys().
        foreach (['min', 'max', 'step'] as $name) {
            $value = $config[$name] ?? null;
            if (null === $value) {
                continue;
            }

            Assert::numeric($value);

            $option = new OptionMetadata();
            $option->setName($name);
            $option->setValue((string) $value);
            $field->addOption($option);
        }
    }

    public function readValue(array $values): array
    {
        return [
            'min' => ($values['min'] ?? null)?->getNumber(),
            'max' => ($values['max'] ?? null)?->getNumber(),
        ];
    }

    public function writeValue(array $values, array $raw): void
    {
        $attributeKey = $values['min']->getAttributeKey();

        // Validate everything before touching a single row (spec §6.2).
        $bounds = [];
        foreach (['min', 'max'] as $key) {
            $value = $raw[$key] ?? null;

            if (null === $value || '' === $value) {
                throw new IncompleteProductAttributeRangeException($attributeKey, \sprintf('the "%s" bound is missing', $key));
            }

            if (!\is_numeric($value)) {
                throw new IncompleteProductAttributeRangeException($attributeKey, \sprintf('the "%s" bound is not numeric', $key));
            }

            $bounds[$key] = (float) $value;
        }

        if ($bounds['min'] > $bounds['max']) {
            throw new IncompleteProductAttributeRangeException($attributeKey, 'min is greater than max');
        }

        $values['min']->setNumber($bounds['min']);
        $values['max']->setNumber($bounds['max']);
    }
}
