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
use Webmozart\Assert\Assert;

final class OptionsAttributeType extends AbstractAttributeType
{
    public function getKey(): string
    {
        return AttributeInterface::TYPE_OPTIONS;
    }

    public function getFormKey(): string
    {
        return 'product_attribute_options';
    }

    public function configureField(
        FieldMetadata $field,
        AttributeInterface $attribute,
        string $locale,
        string $valueKey,
    ): void {
        $values = new OptionMetadata();
        $values->setName('values');
        $values->setType(OptionMetadata::TYPE_COLLECTION);

        foreach ($attribute->getOptions() as $option) {
            $valueOption = new OptionMetadata();
            $valueOption->setName($option->getKey());
            $valueOption->setValue($option->getKey());
            $valueOption->setTitle($option->getTranslation($locale)?->getName() ?? $option->getKey(), $locale);
            $values->addValueOption($valueOption);
        }

        $field->addOption($values);
    }

    public function readValue(array $values): array
    {
        return ['value' => ($values['value'] ?? null)?->getAttributeOptionKey()];
    }

    public function writeValue(array $values, array $raw): void
    {
        $row = $values['value'];

        $value = $raw['value'] ?? null;
        if (null === $value || '' === $value) {
            $row->setAttributeOptionKey(null);

            return;
        }

        Assert::string($value);

        $row->setAttributeOptionKey($value);
    }
}
