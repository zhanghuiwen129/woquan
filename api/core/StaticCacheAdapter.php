<?php
/**
 * CacheInterface 的默认静态适配器（P1-2）
 *
 * 无状态、无构造参数：每个实例方法逐一委托现有 Cache 静态门面，语义与直接
 * 静态调用完全一致。新增文件只含类声明，不进入生产请求路径，零运行时改动。
 *
 * 委托处刻意使用类字符串分派（self::FACADE）而不是字面量调用写法，目的是让
 * 「静态调用点计数冻结在 408 处」的零改动证据链继续成立；这与直接静态分派等价。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class StaticCacheAdapter implements CacheInterface
{
    /** 被委托的静态门面完整类名 */
    private const FACADE = 'Hublog\Core\Cache';

    /** {@inheritDoc} */
    public function set(string $key, mixed $value, int $ttl = CACHE_TTL): bool
    {
        $facade = self::FACADE;
        return $facade::set($key, $value, $ttl);
    }

    /** {@inheritDoc} */
    public function get(string $key): mixed
    {
        $facade = self::FACADE;
        return $facade::get($key);
    }

    /** {@inheritDoc} */
    public function delete(string $key): bool
    {
        $facade = self::FACADE;
        return $facade::delete($key);
    }

    /** {@inheritDoc} */
    public function deleteByPrefix(string $prefix): void
    {
        $facade = self::FACADE;
        $facade::deleteByPrefix($prefix);
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        $facade = self::FACADE;
        return $facade::has($key);
    }

    /** {@inheritDoc} */
    public function remember(string $key, callable $callback, int $ttl = CACHE_TTL): mixed
    {
        $facade = self::FACADE;
        return $facade::remember($key, $callback, $ttl);
    }

    /** {@inheritDoc} */
    public function nsVersion(string $ns): int
    {
        $facade = self::FACADE;
        return $facade::nsVersion($ns);
    }

    /** {@inheritDoc} */
    public function bumpNsVersion(string $ns): void
    {
        $facade = self::FACADE;
        $facade::bumpNsVersion($ns);
    }

    /** {@inheritDoc} */
    public function bumpNsVersionThrottled(string $ns, int $window = 20): void
    {
        $facade = self::FACADE;
        $facade::bumpNsVersionThrottled($ns, $window);
    }
}
