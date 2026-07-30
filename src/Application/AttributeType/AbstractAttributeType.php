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
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;

abstract class AbstractAttributeType implements AttributeTypeInterface
{
    public function configureField(FieldMetadata $field, AttributeInterface $attribute, string $locale): void
    {
        $placeholder = $attribute->getConfig()['placeholder'] ?? null;
        if (!\is_string($placeholder) || '' === $placeholder) {
            return;
        }

        $option = new OptionMetadata();
        $option->setName('placeholder');
        $option->setValue($placeholder);
        $field->addOption($option);
    }

    public function readValue(ProductAttributeValueInterface $value): mixed
    {
        return $value->getJson();
    }

    public function writeValue(ProductAttributeValueInterface $value, mixed $raw): void
    {
        $value->setJson($raw);
    }
}
