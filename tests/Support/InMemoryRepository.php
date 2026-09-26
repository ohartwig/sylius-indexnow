<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Support;

use Doctrine\Persistence\ObjectRepository;

/**
 * @template T of object
 *
 * @implements ObjectRepository<T>
 */
final class InMemoryRepository implements ObjectRepository
{
    /** @var array<int, T> */
    private array $objects = [];

    /** @param class-string<T> $className */
    public function __construct(private readonly string $className) {}

    /** @param T $object */
    public function add(int $id, object $object): void
    {
        $this->objects[$id] = $object;
    }

    public function find(mixed $id): ?object
    {
        return is_int($id) ? ($this->objects[$id] ?? null) : null;
    }

    public function findAll(): array
    {
        return array_values($this->objects);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return array_values(array_filter($this->objects, static function (object $object) use ($criteria): bool {
            foreach ($criteria as $field => $value) {
                $getter = [$object, ($field === 'enabled' ? 'is' : 'get') . ucfirst($field)];
                if (!is_callable($getter) || $getter() !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->findBy($criteria)[0] ?? null;
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}
