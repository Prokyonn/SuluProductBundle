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

namespace Sulu\Product\Infrastructure\Symfony\Twig;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Product\Application\AttributeType\AttributeTypeRegistry;
use Sulu\Product\Domain\Measurement\MeasurementRegistry;
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;
use Sulu\Product\Domain\Model\ProductDimensionContentInterface;
use Sulu\Product\Domain\Model\ProductInterface;
use Sulu\Product\Domain\Repository\ProductRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ProductTwigExtension extends AbstractExtension
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ContentAggregatorInterface $contentAggregator,
        private RequestAnalyzerInterface $requestAnalyzer,
        private ReferenceStoreInterface $referenceStore,
        private ContentResolverInterface $contentResolver,
        private MeasurementRegistry $measurementRegistry,
        private AttributeTypeRegistry $attributeTypeRegistry,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_product_load', [$this, 'loadProduct']),
        ];
    }

    /**
     * @param array<string, string> $properties
     *
     * @return array{attributes: list<array{key: string, label: string, type: string, value: mixed, formattedValue: string|null}>, ...}|null
     */
    public function loadProduct(
        string $uuid,
        array $properties,
        ?string $locale = null,
    ): ?array {
        if (null === $locale) {
            $localization = $this->requestAnalyzer->getCurrentLocalization();
            if (null === $localization) { // @phpstan-ignore identical.alwaysFalse
                return null;
            }
            $locale = $localization->getLocale();
        }

        $product = $this->productRepository->findOneBy(
            [
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_LIVE,
                'version' => DimensionContentInterface::CURRENT_VERSION,
            ],
            [
                ProductRepositoryInterface::SELECT_PRODUCT_CONTENT => [
                    DimensionContentQueryEnhancer::GROUP_SELECT_CONTENT_WEBSITE => true,
                ],
            ]
        );

        if (null === $product) {
            return null;
        }

        /** @var ProductDimensionContentInterface $dimensionContent */
        $dimensionContent = $this->contentAggregator->aggregate(
            $product,
            [
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_LIVE,
                'version' => DimensionContentInterface::CURRENT_VERSION,
            ]
        );

        $resolvedContent = $this->contentResolver->resolve($dimensionContent, $properties);
        $resolvedContent['attributes'] = $this->formatAttributes($dimensionContent, $locale);

        $this->referenceStore->add($product->getUuid(), ProductInterface::RESOURCE_KEY);

        return $resolvedContent;
    }

    /**
     * @return list<array{key: string, label: string, type: string, value: mixed, formattedValue: string|null}>
     */
    private function formatAttributes(ProductDimensionContentInterface $dimensionContent, string $locale): array
    {
        // Grouped by the attribute's object identity, not its (denormalised, reusable) key
        // string: attributeKey is a snapshot taken at row construction and never resynced, so a
        // rename followed by another attribute reusing the freed key would otherwise collide two
        // unrelated attributes into a single bucket. spl_object_id() also works for non-persisted
        // entities, where getId() throws.
        /** @var array<int, ProductAttributeValueInterface> $firstRowByAttribute */
        $firstRowByAttribute = [];
        /** @var array<int, array<string, ProductAttributeValueInterface>> $rowsByAttribute */
        $rowsByAttribute = [];
        foreach ($dimensionContent->getAttributes() as $row) {
            $attributeId = \spl_object_id($row->getAttribute());
            $firstRowByAttribute[$attributeId] ??= $row;
            $rowsByAttribute[$attributeId][$row->getValueKey()] = $row;
        }

        $result = [];

        foreach ($firstRowByAttribute as $attributeId => $first) {
            $rows = $rowsByAttribute[$attributeId];
            $attribute = $first->getAttribute();

            $value = match ($attribute->getType()) {
                AttributeInterface::TYPE_OPTIONS => $first->getAttributeOption()?->getTranslation($locale)?->getName()
                    ?? $first->getAttributeOptionKey(),
                AttributeInterface::TYPE_TEXT => $first->getText(),
                AttributeInterface::TYPE_NUMBER => $first->getNumber(),
                AttributeInterface::TYPE_DATE => $this->resolveDate($first->getNumber()),
                default => $this->readGeneric($attribute, $rows),
            };

            $formattedValue = match ($attribute->getType()) {
                AttributeInterface::TYPE_OPTIONS, AttributeInterface::TYPE_DATE => null,
                default => $this->formatValue($attribute, $rows),
            };

            $result[] = [
                'key' => $first->getAttributeKey(),
                'label' => $attribute->getTranslation($locale)?->getName() ?? $first->getAttributeKey(),
                'type' => $attribute->getType(),
                'value' => $value,
                'formattedValue' => $formattedValue,
            ];
        }

        return $result;
    }

    /**
     * Guarded registry lookup for types without an explicit match arm above (e.g. range,
     * or any type registered by extending code). Returns the raw scalar when the type's only
     * value key is the default 'value' key (so plain 'value' unwraps like before) and the
     * whole keyed map otherwise.
     *
     * @param array<string, ProductAttributeValueInterface> $rows
     */
    private function readGeneric(AttributeInterface $attribute, array $rows): mixed
    {
        if (!$this->attributeTypeRegistry->has($attribute->getType())) {
            return null;
        }

        $type = $this->attributeTypeRegistry->get($attribute->getType());
        $read = $type->readValue($rows);

        return ['value'] === $type->getValueKeys() ? $read['value'] : $read;
    }

    private function resolveDate(?float $timestamp): ?string
    {
        if (null === $timestamp) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (int) $timestamp))->format('Y-m-d');
    }

    /**
     * @param array<string, ProductAttributeValueInterface> $rows
     */
    private function formatValue(AttributeInterface $attribute, array $rows): ?string
    {
        $config = $attribute->getConfig();
        $format = $config['displayFormat'] ?? null;

        if (!\is_string($format) || '' === $format) {
            return null;
        }

        if (!$this->attributeTypeRegistry->has($attribute->getType())) {
            return null;
        }

        $read = $this->attributeTypeRegistry->get($attribute->getType())->readValue($rows);
        $filled = \array_filter($read, static fn (mixed $v): bool => null !== $v && '' !== $v);
        if (\count($filled) !== \count($read)) {
            return null;
        }

        $unitKey = $config['unit'] ?? null;
        $unit = \is_string($unitKey) ? $this->measurementRegistry->findUnit($unitKey) : null;

        $search = ['%unit%'];
        $replace = [$unit?->getSymbol() ?? ''];
        foreach ($read as $key => $partValue) {
            $search[] = '%' . $key . '%';
            $replace[] = \is_scalar($partValue) ? (string) $partValue : '';
        }

        return \trim(\str_replace($search, $replace, $format));
    }
}
