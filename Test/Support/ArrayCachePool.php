<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/*
 * In-memory PSR-6 pool for tests (expiry is ignored). Parameters are left untyped so the class stays compatible with
 * psr/cache 1.0 (prefer-lowest) as well as 3.x.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function getItem($key): CacheItemInterface
    {
        return new class ($key, $this->values[$key] ?? null, array_key_exists($key, $this->values)) implements CacheItemInterface {
            public function __construct(private readonly string $key, private mixed $value, private bool $hit)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function get(): mixed
            {
                return $this->value;
            }

            public function isHit(): bool
            {
                return $this->hit;
            }

            public function set(mixed $value): static
            {
                $this->value = $value;

                return $this;
            }

            public function expiresAt($expiration): static
            {
                return $this;
            }

            public function expiresAfter($time): static
            {
                return $this;
            }
        };
    }

    public function getItems(array $keys = []): iterable
    {
        return array_map(fn ($key): CacheItemInterface => $this->getItem($key), $keys);
    }

    public function hasItem($key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function deleteItem($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->values[$item->getKey()] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
