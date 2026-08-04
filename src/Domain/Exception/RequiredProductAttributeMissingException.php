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

class RequiredProductAttributeMissingException extends ProductAttributeValidationException
{
    public function __construct(string $attributeKey, ?\Throwable $previous = null)
    {
        parent::__construct(
            $attributeKey,
            \sprintf('The required product attribute "%s" is missing a value.', $attributeKey),
            $previous,
        );
    }
}
