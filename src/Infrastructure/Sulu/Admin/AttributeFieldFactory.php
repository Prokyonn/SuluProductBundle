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

namespace Sulu\Product\Infrastructure\Sulu\Admin;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadataLoaderInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\OptionMetadata;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Domain\Measurement\MeasurementRegistry;
use Sulu\Product\Domain\Measurement\Unit;
use Sulu\Product\Domain\Model\ProductFamilyAttributeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the admin field metadata for a single {@see ProductFamilyAttributeInterface}, shared between
 * {@see ProductAttributeFormMetadataVisitor} (the product's Details tab) and
 * {@see ProductVariantAttributeFormMetadataVisitor} (the variant overlay), so the field-cloning/template
 * resolution logic is not duplicated across visitors.
 *
 * @internal
 */
class AttributeFieldFactory
{
    public function __construct(
        private readonly AttributeTypeRegistry $attributeTypeRegistry,
        private readonly FormMetadataLoaderInterface $formMetadataLoader,
        private readonly MeasurementRegistry $measurementRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{0: list<FieldMetadata>, 1: ?FieldMetadata}|null a [fields, unitField] tuple, or null when the
     *                                                               attribute's type is unknown or has no form
     *                                                               fragment for one of its declared value keys
     */
    public function build(ProductFamilyAttributeInterface $familyAttribute, string $locale): ?array
    {
        $attribute = $familyAttribute->getAttribute();
        $attributeConfig = $attribute->getConfig();
        $unitKey = $attributeConfig['unit'] ?? null;
        $unit = \is_string($unitKey) ? $this->measurementRegistry->findUnit($unitKey) : null;
        $hasUnit = $unit instanceof Unit;

        if (!$this->attributeTypeRegistry->has($attribute->getType())) {
            return null;
        }

        $type = $this->attributeTypeRegistry->get($attribute->getType());
        $keys = $type->getValueKeys();

        /** @var array<string, FieldMetadata> $templates */
        $templates = [];
        foreach ($keys as $valueKey) {
            $template = $this->resolveTemplateField($type->getFormKey(), $valueKey, $locale);
            if (null === $template) {
                return null;
            }

            $templates[$valueKey] = $template;
        }

        $translation = $attribute->getTranslation($locale)
            ?? (($defaultLocale = $attribute->getDefaultLocale()) !== null ? $attribute->getTranslation($defaultLocale) : null);

        $fields = [];
        foreach ($keys as $valueKey) {
            $field = $this->cloneFieldWithName($templates[$valueKey], 'attributes/' . $attribute->getId() . '_' . $valueKey);
            $field->setLabel($this->buildLabel($translation?->getName() ?? $attribute->getKey(), $keys, $valueKey, $locale), $locale);
            $field->setRequired($familyAttribute->isRequired());

            $description = $translation?->getDescription();
            if (null !== $description) {
                $field->setDescription(\strip_tags($description), $locale);
            }

            $type->configureField($field, $attribute, $locale, $valueKey);

            $fields[] = $field;
        }

        $unitField = $hasUnit ? $this->buildUnitField($attribute->getId(), $unit, $locale) : null;

        $this->fitColSpans($fields, null !== $unitField);

        return [$fields, $unitField];
    }

    /**
     * @param list<string> $keys
     */
    private function buildLabel(string $name, array $keys, string $valueKey, string $locale): string
    {
        if (1 === \count($keys)) {
            return $name;
        }

        $translationKey = 'sulu_product.value_key_' . $valueKey;
        $part = $this->translator->trans($translationKey, [], 'admin', $locale);

        return \sprintf('%s (%s)', $name, $part === $translationKey ? $valueKey : $part);
    }

    /**
     * @param list<FieldMetadata> $fields
     */
    private function fitColSpans(array $fields, bool $hasUnit): void
    {
        $available = $hasUnit ? 8 : 12;

        $sum = 0;
        foreach ($fields as $field) {
            $fitted = \max(2, (int) \floor($field->getColSpan() * $available / 12));
            $field->setColSpan($fitted);
            $sum += $fitted;
        }

        if ($sum < $available) {
            $fields[0]->setColSpan($fields[0]->getColSpan() + ($available - $sum));
        }
    }

    private function buildUnitField(int $attributeId, Unit $unit, string $locale): FieldMetadata
    {
        $field = new FieldMetadata('attributes/' . $attributeId . '_unit');
        $field->setType('single_select');
        $field->setColSpan(4);
        $field->setDisabledCondition('true');
        $field->setLabel($this->translator->trans('sulu_product.unit', [], 'admin', $locale), $locale);

        $unitKey = $unit->getKey();

        $values = new OptionMetadata();
        $values->setName('values');
        $values->setType(OptionMetadata::TYPE_COLLECTION);

        $valueOption = new OptionMetadata();
        $valueOption->setName($unitKey);
        $valueOption->setValue($unitKey);
        $valueOption->setTitle($unit->getSymbol(), $locale);
        $values->addValueOption($valueOption);

        $field->addOption($values);

        return $field;
    }

    private function resolveTemplateField(string $formKey, string $propertyName, string $locale): ?FieldMetadata
    {
        $fragment = $this->formMetadataLoader->getMetadata($formKey, $locale, []);
        if (!$fragment instanceof FormMetadata) {
            return null;
        }

        foreach ($fragment->getItems() as $item) {
            if ($item instanceof FieldMetadata && $propertyName === $item->getName()) {
                return $item;
            }
        }

        return null;
    }

    private function cloneFieldWithName(FieldMetadata $template, string $name): FieldMetadata
    {
        $field = new FieldMetadata($name);
        $field->setType($template->getType());
        $field->setColSpan($template->getColSpan());
        $field->setDefaultType($template->getDefaultType());
        $field->setVisibleCondition($template->getVisibleCondition());
        $field->setDisabledCondition($template->getDisabledCondition());
        $field->setMinOccurs($template->getMinOccurs());
        $field->setMaxOccurs($template->getMaxOccurs());
        $field->setSpaceAfter($template->getSpaceAfter());
        $field->setOnInvalid($template->getOnInvalid());
        $field->setTags($template->getTags());

        foreach ($template->getOptions() as $option) {
            $field->addOption($option);
        }

        foreach ($template->getTypes() as $blockType) {
            $field->addType($blockType);
        }

        $field->setDescriptions($template->getDescriptions());

        return $field;
    }
}
