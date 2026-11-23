<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\Cache;
use Hublog\Core\RedisLockConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// 接口 RedisLockConnection 与 Cache 声明在同一文件，AutoLoader 不会按接口名单独找文件。
// 显式加载一次，保证下方 FakeRedisLockConnection implements 时接口已定义。
require_once dirname(__DIR__) . '/core/Cache.php';

/**
 * 缓存层测试（V165）
 *
 * 覆盖两件事：
 *  1) file 驱动的基础契约：set/get/has/delete/deleteByPrefix/remember/命名空间版本号。
 *     这是当前所有环境的默认后端，也是唯一在用的后端。
 *  2) 【关键】Redis 不可用时的优雅降级：把 CACHE_DRIVER 配成 redis 但扩展未加载、
 *     或连不上 Redis 时，必须静默退回 file，绝不能让整个站点挂掉。
 *
 * 这条守卫的意义：一旦哪天给 Cache.php 加新驱动分支（如 memcached），
 * 这里能第一时间发现「分支写错导致整个缓存层不可用」这种全站级故障。
 */
final class CacheTest extends TestCase
{
    private string $testKey;

    protected function setUp(): void
    {
        $this->testKey = 'cache_test_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        Cache::delete($this->testKey);
        Cache::delete($this->testKey . '_ns');
    }

    public function testSetGetRoundTrip(): void
    {
        $this->assertTrue(Cache::set($this->testKey, ['hello' => 'world'], 60));
        $this->assertTrue(Cache::has($this->testKey));

        $val = Cache::get($this->testKey);
        $this->assertIsArray($val);
        $this->assertSame('world', $val['hello']);
    }

    public function testGetMissReturnsNull(): void
    {
        Cache::delete($this->testKey);
        $this->assertNull(Cache::get($this->testKey));
        $this->assertFalse(Cache::has($this->testKey));
    }

    public function testDeleteRemovesEntry(): void
    {
        Cache::set($this->testKey, 'v', 60);
        $this->assertTrue(Cache::has($this->testKey));

        $this->assertTrue(Cache::delete($this->testKey));
        $this->assertFalse(Cache::has($this->testKey));
    }

    public function testRememberCallsCallbackOnlyOnce(): void
    {
        $calls = 0;
        $cb = static function () use (&$calls): string {
            $calls++;
            return 'computed_' . $calls;
        };

        $first = Cache::remember($this->testKey, $cb, 60);
        $this->assertSame('computed_1', $first);
        $this->assertSame(1, $calls);

        $second = Cache::remember($this->testKey, $cb, 60);
        $this->assertSame('computed_1', $second, '第二次应命中缓存，不再回源');
        $this->assertSame(1, $calls, '回调不应被再次执行');
    }

    public function testNamespaceVersionBumps(): void
    {
        $ns = 'cachetest_ns_' . bin2hex(random_bytes(4));

        Cache::delete($ns . '_version');
        $v0 = Cache::nsVersion($ns);
        Cache::bumpNsVersion($ns);
        $v1 = Cache::nsVersion($ns);

        $this->assertGreaterThan($v0, $v1, 'bumpNsVersion 后版本号必须变大');

        Cache::delete($ns . '_version');
    }

    public function testRedisUnavailableFallsBackGracefully(): void
    {
        $ref = new ReflectionClass(Cache::class);

        // CACHE_DRIVER 在当前测试环境恒为 file，getRedis() 必须直接返回 null，
        // 且不得因为「没装 redis 扩展」而抛异常——这是降级逻辑的第一层。
        if (!$ref->hasMethod('getRedis')) {
            $this->markTestSkipped('Cache 没有 getRedis() 方法，跳过降级测试。');
            return;
        }

        $m = $ref->getMethod('getRedis');
        $m->setAccessible(true);

        $result = null;
        try {
            $result = $m->invoke(null);
        } catch (\Throwable $e) {
            $this->fail('file 驱动下调用 getRedis() 不应抛出异常，实际：' . $e->getMessage());
        }

        if (defined('CACHE_DRIVER') && CACHE_DRIVER === 'file') {
            $this->assertNull($result, '驱动为 file 时 getRedis() 应返回 null');
        }

        // 无论 Redis 是否可用，file 驱动的读写契约必须始终成立
        Cache::set($this->testKey, 'still_works', 30);
        $this->assertSame('still_works', Cache::get($this->testKey));
    }

    // ===== 分布式锁（Redis 驱动）语义测试：全部基于可注入假连接，无需本机 Redis 服务 =====

    public function testRedisLockAcquireAndSafeRelease(): void
    {
        $conn = new FakeRedisLockConnection([true]);
        $key = 'rlk_acquire_' . bin2hex(random_bytes(4));

        $result = Cache::withRedisLock($conn, $key, static fn (): string => 'done');

        $this->assertSame('done', $result);

        // 加锁：SET <lock:md5(key)> <token> NX PX 10000
        $this->assertCount(1, $conn->setCalls, '首次应一次拿到锁');
        $set = $conn->setCalls[0];
        $this->assertSame('lock:' . md5($key), $set['key']);
        $this->assertSame(['NX', 'PX' => 10000], $set['options']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $set['value'], 'token 应为 128-bit 随机值');

        // 释放：Lua 校验 token，完整 key（含 OPT_PREFIX）通过 ARGV 传入，numKeys=0
        $this->assertCount(1, $conn->evalCalls);
        $ev = $conn->evalCalls[0];
        $this->assertSame(0, $ev['numKeys']);
        $this->assertSame('hublog_' . 'lock:' . md5($key), $ev['args'][0]);
        $this->assertSame($set['value'], $ev['args'][1], '释放必须使用加锁时的同一 token');
        $this->assertStringContainsString("redis.call('get'", $ev['script']);
        $this->assertStringContainsString("redis.call('del'", $ev['script']);
        $this->assertStringContainsString('ARGV[1]', $ev['script']);
        $this->assertStringContainsString('ARGV[2]', $ev['script']);
    }

    public function testRedisLockRetriesOnContention(): void
    {
        // 前两次被其他进程占用，第三次拿到
        $conn = new FakeRedisLockConnection([false, false, true]);
        $calls = 0;
        $result = Cache::withRedisLock($conn, 'rlk_retry', static function () use (&$calls): string {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertCount(3, $conn->setCalls, '竞争时应退避重试');
        $this->assertSame(1, $calls, '回调只执行一次');
        $this->assertCount(1, $conn->evalCalls, '拿到锁后应释放');
    }

    public function testRedisLockDegradesWhenNeverAcquired(): void
    {
        // 始终拿不到锁：[false] 会被重复用作每次结果
        $conn = new FakeRedisLockConnection([false]);
        $calls = 0;
        $result = Cache::withRedisLock($conn, 'rlk_busy', static function () use (&$calls): string {
            $calls++;
            return 'degraded';
        });

        $this->assertSame('degraded', $result, '拿不到锁应降级执行回调（功能可用）');
        $this->assertSame(1, $calls, '降级路径回调仍执行且仅一次');
        $this->assertCount(5, $conn->setCalls, '尝试次数 = 1 + 最大重试 4 次');
        $this->assertCount(0, $conn->evalCalls, '未持有锁不应执行释放（避免误删他人锁）');
    }

    public function testRedisLockReleasesOnCallbackException(): void
    {
        $conn = new FakeRedisLockConnection([true]);
        try {
            Cache::withRedisLock($conn, 'rlk_throw', static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('回调异常应向外传播');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount(1, $conn->evalCalls, '回调抛异常时 try/finally 仍须释放锁');
    }

    public function testRedisLockReleaseFailureDoesNotBreakBusinessResult(): void
    {
        $conn = new FakeRedisLockConnection([true]);
        $conn->throwOnEval = new \RuntimeException('redis down on release');

        $result = Cache::withRedisLock($conn, 'rlk_release_fail', static fn (): string => 'business_ok');

        $this->assertSame('business_ok', $result, '释放失败只告警，不影响业务返回');
        $this->assertCount(1, $conn->evalCalls);
    }

    public function testRedisLockTokensAreUniqueAcrossCalls(): void
    {
        $c1 = new FakeRedisLockConnection([true]);
        $c2 = new FakeRedisLockConnection([true]);
        Cache::withRedisLock($c1, 'rlk_same', static fn (): int => 1);
        Cache::withRedisLock($c2, 'rlk_same', static fn (): int => 1);

        $this->assertNotSame(
            $c1->setCalls[0]['value'],
            $c2->setCalls[0]['value'],
            '两次加锁必须使用不同 token，防止误删/重放'
        );
    }

    public function testRedisLockFullKeyWithoutPrefix(): void
    {
        $conn = new FakeRedisLockConnection([true]);
        $conn->prefix = ''; // 连接层未设 OPT_PREFIX 时，完整 key 应等于 suffix key
        $key = 'rlk_noprefix_' . bin2hex(random_bytes(4));
        Cache::withRedisLock($conn, $key, static fn (): int => 1);

        $this->assertSame('lock:' . md5($key), $conn->evalCalls[0]['args'][0]);
    }

    public function testPhpRedisLockConnectionForwardsToRedis(): void
    {
        // 验证真实适配器把锁原语正确转发给 \Redis（无需真实服务）
        $fake = new FakePhpRedis();
        $adapter = new \Hublog\Core\PhpRedisLockConnection($fake);

        $this->assertTrue($adapter->set('k', 'tok', ['NX', 'PX' => 10000]));
        $this->assertSame('hublog_', $adapter->getLockKeyPrefix());
        $this->assertSame(1, $adapter->eval('SCRIPT', ['a', 'b'], 0));

        $this->assertSame(['set', 'k', 'tok', ['NX', 'PX' => 10000]], $fake->calls[0]);
        $this->assertSame(['eval', 'SCRIPT', ['a', 'b'], 0], $fake->calls[1]);
    }

    public function testWithLockRoutesToRedisWhenConnectionAvailable(): void
    {
        // 把假 \Redis 注入 Cache 私有的连接缓存，令 getRedis() 返回连接 → withLock 必须走分布式锁
        $ref = new ReflectionClass(Cache::class);
        $pRedis = $ref->getProperty('redis');
        $pRedis->setAccessible(true);
        $pChecked = $ref->getProperty('redisChecked');
        $pChecked->setAccessible(true);

        $origRedis = $pRedis->getValue();
        $origChecked = $pChecked->getValue();
        $fake = new FakePhpRedis();
        $pRedis->setValue(null, $fake);
        $pChecked->setValue(null, true);

        try {
            $result = Cache::withLock('integ_key', static fn (): string => 'redis_path');
            $this->assertSame('redis_path', $result);

            // 1) 加锁 SET NX PX；2) 释放 Lua（ARGV 传完整 key + token）
            $this->assertCount(2, $fake->calls, '应各发生一次加锁与释放');
            $this->assertSame('set', $fake->calls[0][0]);
            $this->assertSame(['NX', 'PX' => 10000], $fake->calls[0][3]);
            $this->assertSame('eval', $fake->calls[1][0]);
            $this->assertStringContainsString("redis.call('del'", $fake->calls[1][1]);
        } finally {
            // 还原静态状态，避免污染其他测试
            $pRedis->setValue(null, $origRedis);
            $pChecked->setValue(null, $origChecked);
        }
    }

    // ===== file 驱动回归：withLock 行为不变 =====

    public function testWithLockFileDriverRunsCallback(): void
    {
        // 测试环境 CACHE_DRIVER=file，getRedis() 返回 null，必须走 flock 分支
        $key = 'flock_ok_' . bin2hex(random_bytes(4));
        $result = Cache::withLock($key, static fn (): string => 'file_ok');
        $this->assertSame('file_ok', $result);
    }

    public function testWithLockFileDriverReleasesLockOnException(): void
    {
        $key = 'flock_throw_' . bin2hex(random_bytes(4));
        try {
            Cache::withLock($key, static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('回调异常应向外传播');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        // 异常后锁须被释放，否则此处会自锁/卡死
        $this->assertSame('after', Cache::withLock($key, static fn (): string => 'after'));
    }

    // ===== Redis 前缀删除回归：复现并堵住 phpredis 双重前缀 bug =====

    public function testRedisDeleteByPrefixRemovesPrefixedKeys(): void
    {
        // 把会真实模拟 OPT_PREFIX 语义的假连接注入 Cache，复现 phpredis 行为：
        // SET 自动加前缀；SCAN MATCH 不加前缀但返回完整 key；DEL/UNLINK 再加前缀。
        $ref = new ReflectionClass(Cache::class);
        $pRedis = $ref->getProperty('redis');
        $pRedis->setAccessible(true);
        $pChecked = $ref->getProperty('redisChecked');
        $pChecked->setAccessible(true);

        $origRedis = $pRedis->getValue();
        $origChecked = $pChecked->getValue();
        $fake = new FakePhpRedis();
        $fake->set('post_alpha', 'a');
        $fake->set('post_beta', 'b');
        $fake->set('other_gamma', 'c');
        $this->assertSame(
            ['hublog_post_alpha' => 'a', 'hublog_post_beta' => 'b', 'hublog_other_gamma' => 'c'],
            $fake->store,
            '夹具必须复现 phpredis 的 SET 前缀语义'
        );

        $pRedis->setValue(null, $fake);
        $pChecked->setValue(null, true);
        try {
            Cache::deleteByPrefix('post_');

            $this->assertSame(
                ['hublog_other_gamma' => 'c'],
                $fake->store,
                '双重前缀会让 SCAN 匹配不到或删错 key，post_* 必须被清干净'
            );
            $this->assertSame(
                'hublog_',
                $fake->optionPrefix,
                '扫描/删除结束后必须恢复连接级 OPT_PREFIX'
            );
        } finally {
            $pRedis->setValue(null, $origRedis);
            $pChecked->setValue(null, $origChecked);
        }
    }
}

/**
 * 可注入的假 Redis 锁连接：记录所有加锁/释放调用，按预设序列模拟竞争。
 * 不依赖本机 redis 扩展或服务，核心锁协议逻辑可完整断言。
 */
final class FakeRedisLockConnection implements RedisLockConnection
{
    public string $prefix = 'hublog_';
    public array $setCalls = [];
    public array $evalCalls = [];
    public ?\Throwable $throwOnEval = null;

    private array $results;
    private int $index = 0;

    /** @param array $results 每次 set 的返回值序列；用尽后重复最后一个值 */
    public function __construct(array $results = [true])
    {
        $this->results = $results;
    }

    public function set(string $key, string $value, array $options): mixed
    {
        $this->setCalls[] = ['key' => $key, 'value' => $value, 'options' => $options];
        $result = $this->results[$this->index] ?? end($this->results);
        $this->index++;
        return $result;
    }

    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        $this->evalCalls[] = ['script' => $script, 'args' => $args, 'numKeys' => $numKeys];
        if ($this->throwOnEval !== null) {
            throw $this->throwOnEval;
        }
        return 1;
    }

    public function getLockKeyPrefix(): string
    {
        return $this->prefix;
    }
}

/**
 * 假 \Redis：覆盖锁适配器与 deleteByPrefix 需要的方法，不发起任何真实连接。
 * 方法签名与本机 PhpRedis 扩展一致（见反射）。store 保存的是「真实 key」——
 * 即 SET 时自动加 OPT_PREFIX 后的 key；SCAN 返回完整 key，DEL/UNLINK 再加前缀。
 * 这样才能复现「SCAN 不匹配 + 删除时二次叠前缀」的双重前缀 bug。
 */
final class FakePhpRedis extends \Redis
{
    public array $calls = [];
    /** @var array<string,mixed> 真实 key（含前缀）=> 值 */
    public array $store = [];
    /** 可由测试读取，断言 finally 后 OPT_PREFIX 已恢复 */
    public string $optionPrefix = 'hublog_';

    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
    {
        $this->calls[] = ['set', $key, $value, $options];
        $this->store[$this->optionPrefix . $key] = $value;
        return true;
    }

    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->calls[] = ['eval', $script, $args, $num_keys];
        return 1;
    }

    public function getOption(int $option): mixed
    {
        if ($option === \Redis::OPT_PREFIX) {
            return $this->optionPrefix;
        }
        return null;
    }

    public function setOption(int $option, mixed $value): bool
    {
        $this->calls[] = ['setOption', $option, $value];
        if ($option === \Redis::OPT_PREFIX) {
            $this->optionPrefix = is_string($value) ? $value : '';
        }
        return true;
    }

    public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $this->calls[] = ['scan', $iterator, $pattern, $count];
        $keys = array_keys($this->store);
        if ($pattern !== null && $pattern !== '') {
            // phpredis 的 SCAN MATCH 不自动加 OPT_PREFIX：直接拿完整真实 key 匹配。
            $keys = array_values(array_filter(
                $keys,
                static fn(string $k): bool => fnmatch($pattern, $k)
            ));
        }
        $iterator = 0; // 一次性返回全部命中，随后结束迭代
        return $keys;
    }

    public function unlink(array|string $key, string ...$other_keys): \Redis|int|false
    {
        return $this->removeKeys('unlink', $key, $other_keys);
    }

    public function del(array|string $key, string ...$other_keys): \Redis|int|false
    {
        return $this->removeKeys('del', $key, $other_keys);
    }

    /** @param array<string> $keys */
    private function removeKeys(string $op, array|string $key, array $otherKeys): int
    {
        $keys = is_array($key) ? $key : array_merge([$key], $otherKeys);
        $this->calls[] = [$op, $keys];
        $deleted = 0;
        foreach ($keys as $k) {
            // phpredis 的 DEL/UNLINK 会自动加 OPT_PREFIX。
            $realKey = $this->optionPrefix . (string)$k;
            if (array_key_exists($realKey, $this->store)) {
                unset($this->store[$realKey]);
                $deleted++;
            }
        }
        return $deleted;
    }
}
