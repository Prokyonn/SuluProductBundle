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

final class DateAttributeType extends AbstractAttributeType
{
    private const FORMAT = 'Y-m-d';

    public function getKey(): string
    {
        return AttributeInterface::TYPE_DATE;
    }

    public function getFormKey(): string
    {
        return 'product_attribute_date';
    }

    public function readValue(array $values): array
    {
        $timestamp = ($values['value'] ?? null)?->getNumber();

        if (null === $timestamp) {
            return ['value' => null];
        }

        return ['value' => (new \DateTimeImmutable('@' . (int) $timestamp))->format(self::FORMAT)];
    }

    public function writeValue(array $values, array $raw): void
    {
        $row = $values['value'];

        $value = $raw['value'] ?? null;
        if (null === $value || '' === $value) {
            $row->setNumber(null);

            return;
        }

        Assert::string($value);

        $date = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new \DateTimeZone('UTC'));

        Assert::isInstanceOf($date, \DateTimeImmutable::class, \sprintf('Expected a date in format "%s", got "%s".', self::FORMAT, $value));
        Assert::same($date->format(self::FORMAT), $value, \sprintf('Expected a valid date in format "%s", got "%s".', self::FORMAT, $value));

        $row->setNumber((float) $date->getTimestamp());
    }
}
