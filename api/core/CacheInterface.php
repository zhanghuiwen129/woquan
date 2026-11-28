<?php
/**
 * 缓存可替换点契约（P1-2）
 *
 * 9 个缓存原语：基础读写删 + 前缀失效 + 回源 + 命名空间版本族。
 * 明确排除 flush()（全局运维动作）、getCacheDir()（文件驱动实现细节）、
 * withLock()/withRedisLock()（已有 RedisLockConnection 专门契约承载）。
 *
 * 默认参数 CACHE_TTL 由全局常量在调用时求值，bootstrap.php / config.php /
 * api/tests/bootstrap.php 均已定义；本文件加载时不需要常量已存在。
 */

declare(strict_types=1);

namespace Hublog\Core;

interface CacheInterface
{
    /** 写缓存，ttl 秒后过期。 */
    public function set(string $key, mixed $value, int $ttl = CACHE_TTL): bool;

    /** 读缓存；未命中或已过期返回 null。 */
    public function get(string $key): mixed;

    /** 删除单个键；键不存在时仍返回 true。 */
    public function delete(string $key): bool;

    /** 按前缀批量失效。 */
    public function deleteByPrefix(string $prefix): void;

    /** 是否存在有效缓存项。 */
    public function has(string $key): bool;

    /** 未命中时回源并把结果写入缓存（含 single-flight 语义）。 */
    public function remember(string $key, callable $callback, int $ttl = CACHE_TTL): mixed;

    /** 读取命名空间版本；初始版本为 1。 */
    public function nsVersion(string $ns): int;

    /** 立即升版（内容变更，正确性优先）。 */
    public function bumpNsVersion(string $ns): void;

    /** 窗口内合并升版（cosmetic 变更，允许至多 window 秒延迟）。 */
    public function bumpNsVersionThrottled(string $ns, int $window = 20): void;
}
