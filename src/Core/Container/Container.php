<?php
declare(strict_types=1);

namespace PenPay\Core\Container;

use Psr\Container\ContainerInterface;
use InvalidArgumentException;
use Closure;

final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, Closure> */
    private array $factories = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory instanceof Closure ? $factory : Closure::fromCallable($factory);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new InvalidArgumentException("No binding found for {$id}");
        }

        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;

        return $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    // Optional: allow overriding singletons for testing
    public function singleton(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }
}