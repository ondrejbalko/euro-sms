<?php

declare(strict_types=1);

namespace EuroSms\Entities;

use ArrayAccess;
use Countable;
use JsonSerializable;
use TypeError;

/**
 * @implements ArrayAccess<int|string|null, mixed>
 */
abstract class CollectionAbstract implements ArrayAccess, Countable, JsonSerializable
{
    /** @var array<int|string, mixed> $collection */
    protected array $collection = [];

    /**
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->collection;
    }

    /**
     * @return array<int|string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /**
     * @return int
     */
    #[\Override]
    public function count(): int
    {
        return count($this->collection);
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        if (!is_int($offset) && !is_string($offset)) {
            return false;
        }

        return isset($this->collection[$offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset) && !is_string($offset)) {
            return null;
        }

        return $this->collection[$offset] ?? null;
    }

    /**
     * Only what an array may be keyed by is accepted, anything else is refused the same way
     * writing into a plain array would refuse it.
     * @param mixed $offset
     * @param mixed $value
     * @return void
     * @throws TypeError
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->collection[] = $value;

            return;
        }

        if (!is_int($offset) && !is_string($offset)) {
            throw new TypeError('Illegal offset type.');
        }

        $this->collection[$offset] = $value;
    }

    /**
     * @param mixed $offset
     * @return void
     */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        if (!is_int($offset) && !is_string($offset)) {
            return;
        }

        if ($this->offsetExists($offset)) {
            unset($this->collection[$offset]);
        }
    }
}
