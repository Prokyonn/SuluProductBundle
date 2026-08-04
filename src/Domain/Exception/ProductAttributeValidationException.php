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

namespace Sulu\Product\Domain\Exception;

abstract class ProductAttributeValidationException extends \Exception
{
    public function __construct(
        private readonly string $attributeKey,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getAttributeKey(): string
    {
        return $this->attributeKey;
    }
}
