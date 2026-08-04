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

final class AttributeTypeRegistry
{
    /** @var array<string, AttributeTypeInterface> */
    private array $types = [];

    /**
     * @param iterable<AttributeTypeInterface> $types
     */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            $this->assertValidValueKeys($type);
            $this->types[$type->getKey()] = $type;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): AttributeTypeInterface
    {
        return $this->types[$key]
            ?? throw new \InvalidArgumentException(\sprintf('No attribute type registered for key "%s".', $key));
    }

    private function assertValidValueKeys(AttributeTypeInterface $type): void
    {
        $keys = $type->getValueKeys();

        if ([] === $keys) {
            throw new \InvalidArgumentException(\sprintf('Attribute type "%s" must declare at least one value key.', $type->getKey()));
        }

        if (\count($keys) !== \count(\array_unique($keys))) {
            throw new \InvalidArgumentException(\sprintf('Attribute type "%s" declares duplicate value keys.', $type->getKey()));
        }

        foreach ($keys as $key) {
            if ('unit' === $key) {
                throw new \InvalidArgumentException(\sprintf('Attribute type "%s" may not declare the reserved value key "unit".', $type->getKey()));
            }

            if (1 !== \preg_match('/^[a-z][a-zA-Z0-9]{0,31}$/', $key)) {
                throw new \InvalidArgumentException(\sprintf('Attribute type "%s" declares an invalid value key "%s".', $type->getKey(), $key));
            }
        }
    }
}
