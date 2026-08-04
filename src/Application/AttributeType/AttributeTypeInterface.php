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
use Sulu\Product\Domain\Model\AttributeInterface;
use Sulu\Product\Domain\Model\ProductAttributeValueInterface;

interface AttributeTypeInterface
{
    public function getKey(): string;

    public function getFormKey(): string;

    /**
     * @return list<string> value keys, one ProductAttributeValue row each.
     *                      Non-empty, unique, /^[a-z][a-zA-Z0-9]{0,31}$/, never 'unit'.
     */
    public function getValueKeys(): array;

    public function configureField(
        FieldMetadata $field,
        AttributeInterface $attribute,
        string $locale,
        string $valueKey,
    ): void;

    /**
     * The map MAY be missing declared keys. Implementations read defensively.
     * MUST return exactly the keys declared by getValueKeys(), null-filled.
     *
     * @param array<string, ProductAttributeValueInterface> $values
     *
     * @return array<string, mixed>
     */
    public function readValue(array $values): array;

    /**
     * $values always contains every key from getValueKeys() — the data mapper constructs a
     * row per declared key before calling. Do NOT write defensive guards for missing rows
     * here; they are unreachable and the 100% coverage gate cannot cover them.
     * $raw MAY be missing keys — that is a half-filled value, which the type rejects.
     *
     * MUST validate all of $raw before mutating any row.
     *
     * @param array<string, ProductAttributeValueInterface> $values
     * @param array<string, mixed> $raw
     */
    public function writeValue(array $values, array $raw): void;
}
