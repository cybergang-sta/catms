<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * A container, in the sense of a bag of lazily-built singletons.
 *
 * WHY NOT A FULL DI FRAMEWORK
 * The dependency graph is about twenty objects, all known at boot. A PSR-11
 * container with autowiring and attribute-based injection would add a build-time
 * code generator, a cache directory and a class of "why is nothing injected"
 * failures, in exchange for solving a problem this codebase does not have. What
 * it does need is one place where the wiring is visible and one place to swap an
 * implementation in a test — that is this.
 *
 * Construction order is explicit in `App\Core\App::registerCoreServices()`,
 * which reads like a dependency list and is the fastest way to understand the
 * system's shape.
 */
final class Container
{
    /**
     * @var array<string, Closure(self): mixed>
     */
    private array $factories = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * @param Closure(self): mixed $factory Called at most once; the result is
     *                                     shared by every later `get()`.
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register an already-constructed value. Used for scalars and for the test
     * doubles that replace a real service.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(
                sprintf('Service "%s" is not registered. Check App\Core\App::registerCoreServices().', $id)
            );
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function typed(string $id): object
    {
        $service = $this->get($id);
        if (!is_object($service)) {
            throw new RuntimeException(sprintf('Service "%s" is not an object.', $id));
        }

        return $service;
    }
}
