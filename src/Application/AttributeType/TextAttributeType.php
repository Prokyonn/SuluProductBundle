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

use Sulu\Product\Domain\Model\AttributeInterface;
use Webmozart\Assert\Assert;

final class TextAttributeType extends AbstractAttributeType
{
    public function getKey(): string
    {
        return AttributeInterface::TYPE_TEXT;
    }

    public function getFormKey(): string
    {
        return 'product_attribute_text';
    }

    public function readValue(array $values): array
    {
        return ['value' => ($values['value'] ?? null)?->getText()];
    }

    public function writeValue(array $values, array $raw): void
    {
        $row = $values['value'];

        $value = $raw['value'] ?? null;
        if (null === $value) {
            $row->setText(null);

            return;
        }

        Assert::string($value);

        $row->setText($value);
    }
}
