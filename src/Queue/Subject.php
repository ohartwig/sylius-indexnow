<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Queue;

/**
 * What changed: a product, a taxon, the homepages, or a URL resolved in advance.
 *
 * References are resolved to URLs by the worker, not during the admin's save:
 * a slug edited twice before the next run is announced once, in its final
 * form. Only removals are resolved beforehand, because afterwards the record
 * is gone.
 */
final readonly class Subject
{
    public const PRODUCT = 'product';
    public const TAXON = 'taxon';
    public const HOMEPAGE = 'homepage';
    public const URL = 'url';

    private function __construct(
        public string $type,
        public int $id,
        public string $url,
    ) {}

    public static function product(int $id): self
    {
        return new self(self::PRODUCT, $id, '');
    }

    public static function taxon(int $id): self
    {
        return new self(self::TAXON, $id, '');
    }

    public static function homepage(): self
    {
        return new self(self::HOMEPAGE, 0, '');
    }

    public static function url(string $url): self
    {
        return new self(self::URL, 0, $url);
    }

    public static function fromRow(string $type, int $id, string $url): ?self
    {
        return in_array($type, [self::PRODUCT, self::TAXON, self::HOMEPAGE, self::URL], true) ? new self($type, $id, $url) : null;
    }

    public function key(): string
    {
        return sha1($this->type . '|' . $this->id . '|' . $this->url);
    }
}
