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

namespace Sulu\Product\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Product\Domain\Exception\IncompleteProductAttributeRangeException;

#[CoversClass(IncompleteProductAttributeRangeException::class)]
class IncompleteProductAttributeRangeExceptionTest extends TestCase
{
    public function testCarriesAttributeKeyAndReason(): void
    {
        $exception = new IncompleteProductAttributeRangeException('weight', 'min is greater than max');

        self::assertSame('weight', $exception->getAttributeKey());
        self::assertStringContainsString('weight', $exception->getMessage());
        self::assertStringContainsString('min is greater than max', $exception->getMessage());
    }

    public function testWrapsPreviousThrowable(): void
    {
        $previous = new \RuntimeException('boom');

        $exception = new IncompleteProductAttributeRangeException('weight', 'reason', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
