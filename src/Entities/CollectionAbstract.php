<?php

namespace EuroSms\Entities;

use ArrayAccess;
use Countable;
use JsonSerializable;

/**
 * @implements ArrayAccess<mixed, mixed>
 */
abstract class CollectionAbstract implements ArrayAccess, Countable, JsonSerializable
{
    /** @var array<mixed, mixed> $collection */
    protected array $collection = [];

    /**
     * @return array<mixed, mixed>
     */
    public function all(): array
    {
        return $this->collection;
    }

    /**
     * @return array<mixed, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->collection);
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->collection[$offset]);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->collection[$offset] ?? null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->collection[] = $value;
        } else {
            $this->collection[$offset] = $value;
        }
    }

    /**
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        if ($this->offsetExists($offset)) {
            unset($this->collection[$offset]);
        }
    }
}
