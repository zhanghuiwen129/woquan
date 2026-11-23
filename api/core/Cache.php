<?php
/**
 * Hublog 缓存类
 * 支持文件缓存，通配符删除，预留Redis接口
 */

namespace Hublog\Core;

/**
 * 分布式锁所需的最小 Redis 原语（可注入假实现，便于在无 Redis 服务的环境测试核心逻辑）。
 * 方法语义与 PhpRedis 同名方法保持一致：
 *   - set：SET key value [选项]，拿到锁返回 true，key 已存在返回 false；
 *   - eval：执行 Lua 脚本，返回脚本结果；
 *   - getLockKeyPrefix：返回连接层 OPT_PREFIX，因为 PhpRedis 会自动给 SET 的 key 加此前缀。
 */
interface RedisLockConnection
{
    public function set(string $key, string $value, array $options): mixed;

    public function eval(string $script, array $args = [], int $numKeys = 0): mixed;

    public function getLockKeyPrefix(): string;
}

/**
 * 把真实 \Redis 适配成 RedisLockConnection，仅做薄转发，不含锁协议逻辑。
 * 锁协议（NX/PX、Lua 安全释放、重试降级）集中在 Cache::withRedisLock 中，可被假连接完整观测。
 */
final class PhpRedisLockConnection implements RedisLockConnection
{
    public function __construct(private \Redis $redis)
    {
    }

    public function set(string $key, string $value, array $options): mixed
    {
        return $this->redis->set($key, $value, $options);
    }

    public function eval(string $script, array $args = [], int $numKeys = 0): mixed
    {
        return $this->redis->eval($script, $args, $numKeys);
    }

    public function getLockKeyPrefix(): string
    {
        $prefix = $this->redis->getOption(\Redis::OPT_PREFIX);
        return is_string($prefix) ? $prefix : '';
    }
}

class Cache
{
    private static string $cacheDir = __DIR__ . '/../../cache/';
    private static ?\Redis $redis = null;
    private static bool $redisChecked = false;
    /** P0-11：单航班进程内重入保护（remember 同 key 嵌套时不再加锁，避免自死锁） */
    private static array $inflight = [];

    /** 分布式锁 key 的统一后缀前缀，避免与业务缓存 key 冲突（连接层 OPT_PREFIX 仍会再加一层） */
    private const LOCK_PREFIX = 'lock:';
    /**
     * 锁自动过期时间（毫秒）。
     * 取舍：设短可防止进程崩溃/回调卡死留下永久锁（死锁），但【若回调执行超过该 TTL，
     * 锁会被 Redis 自动释放，其他进程可能拿到锁进入同一临界区，临界区不再互斥】。
     * 当前所有 withLock 调用点（限流计数、登录失败锁定、remember 回源）都是毫秒级短操作，
     * 10s 是「防死锁」与「临界区重叠风险」之间的保守折中；不实现看门狗续期以避免额外复杂度。
     */
    private const LOCK_TTL_MS = 10000;
    /** 拿不到锁后的最大重试次数（不含首次），总尝试次数 = 1 + 该值 */
    private const LOCK_RETRY_MAX = 4;
    /** 每次重试前的退避微秒数，总等待上限约 4 * 30ms = 120ms，短等待以免拖慢请求 */
    private const LOCK_RETRY_INTERVAL_US = 30000;
    /**
     * 安全释放脚本：仅当锁的 token 与本机持有者一致时才删除，防止误删「锁已过期被他人重新持有」的新锁。
     * key 通过 ARGV[1] 传入完整值（含 OPT_PREFIX），规避 phpredis 对 EVAL 的 KEYS 是否自动加前缀的版本差异。
     */
    private const LUA_RELEASE_LOCK = "if redis.call('get', ARGV[1]) == ARGV[2] then return redis.call('del', ARGV[1]) else return 0 end";

    /**
     * 获取 Redis 连接（如果配置了 Redis 驱动且扩展可用）
     */
    private static function getRedis(): ?\Redis
    {
        if (self::$redisChecked) {
            return self::$redis;
        }
        self::$redisChecked = true;

        $driver = defined('CACHE_DRIVER') ? CACHE_DRIVER : 'file';
        if ($driver !== 'redis') {
            return null;
        }
        if (!extension_loaded('redis')) {
            error_log('[Hublog Cache] Redis driver configured but redis extension not loaded, falling back to file');
            return null;
        }

        try {
            $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
            $port = defined('REDIS_PORT') ? REDIS_PORT : 6379;
            $password = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : '';
            $db = defined('REDIS_DB') ? REDIS_DB : 0;

            $redis = new \Redis();
            if (!$redis->connect($host, $port, 2.0)) {
                return null;
            }
            if (!empty($password)) {
                $redis->auth($password);
            }
            if ($db > 0) {
                $redis->select($db);
            }
            $redis->setOption(\Redis::OPT_PREFIX, defined('CACHE_PREFIX') ? CACHE_PREFIX : 'hublog_');
            self::$redis = $redis;
        } catch (\Throwable $e) {
            error_log('[Hublog Cache] Redis connection failed: ' . $e->getMessage() . ', falling back to file');
            self::$redis = null;
        }

        return self::$redis;
    }

    public static function set(string $key, mixed $value, int $ttl = CACHE_TTL): bool
    {
        // Redis 驱动
        $redis = self::getRedis();
        if ($redis) {
            try {
                return $redis->setex($key, $ttl, serialize($value));
            } catch (\Throwable $e) {
                error_log('[Hublog Cache] Redis set failed, falling back to file: ' . $e->getMessage());
            }
        }

        // 文件驱动
        $file = self::$cacheDir . md5($key) . '.cache';
        $data = [
            'key' => $key,
            'expire' => time() + $ttl,
            'value' => $value
        ];
        // P0-10：原子写（tmp + rename）。原实现直接 file_put_contents 目标文件，
        // 并发 get 可能读到写了一半的内容被误判为过期而删除；rename 同目录原子替换杜绝该窗口。
        // 同时不再维护 .index 全局索引（原实现每次 set 都在全局锁下读改写整个索引，
        // 所有 worker 在此串行，且索引只增不减）——前缀删除改为扫描缓存文件内嵌 key，见 deleteByPrefix。
        $tmp = $file . '.' . getmypid() . '.tmp';
        $result = file_put_contents($tmp, serialize($data), LOCK_EX) !== false;
        if ($result) {
            $result = @rename($tmp, $file);
            if (!$result) {
                @unlink($tmp);
            }
        }
        return $result;
    }

    public static function get(string $key): mixed
    {
        // Redis 驱动
        $redis = self::getRedis();
        if ($redis) {
            try {
                $raw = $redis->get($key);
                if ($raw === false) {
                    return null;
                }
                return @unserialize($raw, ['allowed_classes' => false]);
            } catch (\Throwable $e) {
                error_log('[Hublog Cache] Redis get failed, falling back to file: ' . $e->getMessage());
            }
        }

        // 文件驱动
        $file = self::$cacheDir . md5($key) . '.cache';
        if (!file_exists($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = ($raw === false || $raw === '') ? false : @unserialize($raw, ['allowed_classes' => false]);
        // 文件损坏 / 写入一半时被读到：unserialize 返回 false，此时取 $data['expire'] 会报
        // "Trying to access array offset on value of type bool"，必须显式判空后按过期处理
        if (!is_array($data) || !isset($data['expire']) || (int)$data['expire'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'] ?? null;
    }

    public static function delete(string $key): bool
    {
        // Redis 驱动
        $redis = self::getRedis();
        if ($redis) {
            try {
                $redis->del($key);
            } catch (\Throwable $e) {
                // 继续执行文件删除
            }
        }

        // 文件驱动
        $file = self::$cacheDir . md5($key) . '.cache';
        $ok = true;
        if (file_exists($file)) {
            $ok = @unlink($file);
        }
        return $ok;
    }

    /**
     * 按缓存键前缀删除（推荐方式）
     * 1) Redis 驱动：使用 SCAN + UNLINK（或 del 回退）批量删，严禁 KEYS（生产 O(N) 阻塞）
     * 2) 文件驱动：直接扫描缓存文件，用文件内嵌的原始 key 精确匹配前缀后删除。
     *    P0-10 起不再维护 .index 全局索引：原方案每次 set 都在全局锁下读改写整个索引
     *    （所有 worker 串行 + 索引只增不减），且索引与缓存文件可能漂移。
     *    缓存文件自带 key（见 set()），扫描结果即权威来源，无锁、无漂移、无膨胀。
     *    注意：扫描期间新建的同前缀 key 可能漏删（与旧实现同样的竞态），最坏晚一个 TTL 过期。
     */
    public static function deleteByPrefix(string $prefix): void
    {
        // P2-13：Redis 驱动前缀删除（SCAN 迭代 + UNLINK，KEYS 会锁死单线程 Redis 主线程）
        // P1-5：phpredis 的 SCAN MATCH 不会自动加 OPT_PREFIX，返回的却是带前缀的真实 key，
        // 而 DEL/UNLINK 又会自动再加一次前缀 —— 双重前缀会让删除全部落空（授权撤销窗口）。
        // 按官方 workaround：临空 OPT_PREFIX，pattern 手动拼真实前缀，删完 finally 恢复。
        $redis = self::getRedis();
        if ($redis && $prefix !== '') {
            $oldPrefix = $redis->getOption(\Redis::OPT_PREFIX);
            $effectivePrefix = is_string($oldPrefix)
                ? $oldPrefix
                : (defined('CACHE_PREFIX') ? CACHE_PREFIX : 'hublog_');
            $redis->setOption(\Redis::OPT_PREFIX, '');
            try {
                $iterator = null;
                $pattern = $effectivePrefix . $prefix . '*';
                $do = true;
                $maxScans = 1000;
                $scans = 0;
                $useUnlink = method_exists($redis, 'unlink');
                $batchSize = 200;
                $bucket = [];
                $flushBucket = function (array &$keys) use ($redis, $useUnlink): void {
                    if (empty($keys)) return;
                    $unique = array_values(array_unique($keys));
                    if ($useUnlink) {
                        $redis->unlink($unique);
                    } else {
                        $redis->del($unique);
                    }
                    $keys = [];
                };
                while ($do) {
                    $scans++;
                    if ($scans > $maxScans) break; // 安全上限，防止极端卡死
                    // PhpRedis 扩展的 scan：第 1 参数是 iterator 引用（null 表示从头开始）
                    // SCAN 返回值：命中的 key 数组（非 PhpRedis 原生驱动实现时可能是字符串）。
                    // 这些 key 已含真实前缀，且此刻 OPT_PREFIX 为空，可直接交给 unlink/del。
                    $keys = $redis->scan($iterator, $pattern, $batchSize);
                    if ($keys === false || $keys === null) {
                        $do = false;
                    } else {
                        if (!is_array($keys)) $keys = [$keys];
                        foreach ($keys as $k) {
                            if (!is_string($k)) continue;
                            $bucket[] = $k;
                            if (count($bucket) >= $batchSize) {
                                $flushBucket($bucket);
                            }
                        }
                        if ($iterator === 0) $do = false; // PhpRedis 返回 0 表示迭代结束
                    }
                }
                $flushBucket($bucket);
            } catch (\Throwable $e) {
                // Redis 失败不阻断文件驱动删除
                error_log('[Hublog Cache deleteByPrefix Redis] ' . $e->getMessage());
            } finally {
                // 必须恢复连接级前缀，否则后续 set/get/del 会写到无前缀的裸 key 上
                $redis->setOption(\Redis::OPT_PREFIX, $oldPrefix);
            }
        }

        // 文件驱动删除（无锁扫描：缓存文件内嵌原始 key 即权威前缀匹配来源）
        if ($prefix !== '') {
            foreach (glob(self::$cacheDir . '*.cache') ?: [] as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false || $raw === '') {
                    continue;
                }
                $data = @unserialize($raw, ['allowed_classes' => false]);
                if (!is_array($data) || !isset($data['key']) || !is_string($data['key'])) {
                    continue; // 旧格式无内嵌 key：无法判定前缀，保守跳过（等 TTL 自然过期）
                }
                if (!str_starts_with($data['key'], $prefix)) {
                    continue;
                }
                @unlink($file);
            }
        }
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function remember(string $key, callable $callback, int $ttl = CACHE_TTL): mixed
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        // P0-11：单航班（single-flight）——同 key 并发未命中时只放一个请求去算，
        // 其余请求在锁上等待，算完直接复用结果。把「缓存失效惊群」
        // 从 N 个并发重查询降为 1 个（file 驱动下还能顺带避免 N 次重复写文件）。
        // 进程内重入保护：同一进程已在算该 key（嵌套 remember）时不再加锁——
        // flock 对同进程第二个 fd 会自锁，必须绕过。
        // Redis 驱动下的正确性：withLock 走跨机分布式锁；竞争失败的进程降级直接执行下方
        // 回调，但回调内的「双重检查 get($key)」保证：若持锁者已算完并写入，降级方会命中
        // 缓存直接返回，不会重复回源。仅当持锁者仍在计算（或锁超时）时降级方才会重复回源，
        // 这是与 file 驱动 flock 失败一致的 best-effort 取舍，功能正确、非强一致 single-flight。
        if (isset(self::$inflight[$key])) {
            return $callback();
        }
        self::$inflight[$key] = true;
        try {
            return self::withLock('remember_' . $key, function () use ($key, $callback, $ttl) {
                // 双重检查：排队期间可能已被其他进程算完
                $value = self::get($key);
                if ($value !== null) {
                    return $value;
                }
                $value = $callback();
                self::set($key, $value, $ttl);
                return $value;
            });
        } finally {
            unset(self::$inflight[$key]);
        }
    }

    /**
     * P0-11：命名空间缓存版本号。失效 = 换版本号（旧 key 靠 TTL 自然过期），
     * 取代 deleteByPrefix 全量删除——后者在文件驱动下每次都要 glob 扫描全部
     * 缓存文件，且「一次浏览删全站列表缓存」会让命中率归零、引发惊群。
     * 版本值即「最近一次升版的时间戳」，同时充当合并升版的窗口判断依据。
     * 用法：缓存键里嵌 nsVersion($ns)，失效时 bumpNsVersion($ns)。
     */
    public static function nsVersion(string $ns): int
    {
        $v = self::get('nsver_' . $ns);
        return ($v !== null && (int)$v > 0) ? (int)$v : 1;
    }

    /**
     * P0-11：立即升版（内容变更：发帖/删帖/点赞/评论/关注等，正确性优先）。
     * 版本号严格单调：同一秒内多次升版用 last+1，避免「一秒内两次内容变更
     * 只失效一次」的漏失效（时间戳相同则版本号相同，旧 key 不会被换掉）。
     */
    public static function bumpNsVersion(string $ns): void
    {
        $current = self::get('nsver_' . $ns);
        $last = ($current !== null && (int)$current > 0) ? (int)$current : 0;
        self::set('nsver_' . $ns, max(time(), $last + 1), 604800);
    }

    /**
     * P0-11：窗口内合并升版（浏览量等 cosmetic 变更）：窗口内多次触发只升一次，
     * 把「每次浏览都全站列表冷启动」收敛为「至多每 window 秒一次」。
     * 浏览量进列表有至多 window 秒延迟，换取列表缓存真实命中。
     */
    public static function bumpNsVersionThrottled(string $ns, int $window = 20): void
    {
        $current = self::get('nsver_' . $ns);
        $last = ($current !== null && (int)$current > 0) ? (int)$current : 0;
        if ($last > 0 && time() - $last < max($window, 5)) {
            return; // 窗口内已升过版，合并本次触发
        }
        self::bumpNsVersion($ns);
    }

    public static function flush(): void
    {
        foreach (glob(self::$cacheDir . '*.cache') as $file) {
            unlink($file);
        }
        // 清理限流锁文件（以 lock_ 前缀开头，避免误删 install.lock 等非缓存锁文件）
        foreach (glob(self::$cacheDir . 'lock_*.lock') as $file) {
            @unlink($file);
        }
        // 清理遗留的原子写临时文件（P0-10 之前的直接写不会产生，防御未来异常退出）
        foreach (glob(self::$cacheDir . '*.cache.*.tmp') as $file) {
            @unlink($file);
        }
        // P0-10：.index 已废弃（前缀删除改为扫描缓存文件内嵌 key），清理遗留文件
        $indexFile = self::$cacheDir . '.index';
        if (file_exists($indexFile)) @unlink($indexFile);
    }

    /**
     * 获取缓存目录路径
     */
    public static function getCacheDir(): string
    {
        return self::$cacheDir;
    }

    /**
     * 在独占锁下执行回调，保证读-改-写操作的原子性（限流、去重、single-flight 等）。
     * Redis 驱动：走跨机共享的分布式锁（withRedisLock），使多机部署下锁真正互斥。
     * file 驱动：仍用本机 flock（下方逻辑逐字保留，零行为变化）。
     * 两种驱动在「拿不到锁」时的降级语义一致：直接执行回调（非原子，功能仍可用）+ 告警。
     *
     * @param string $onContention 'run'（默认，保持所有存量调用行为不变）= 拿不到锁仍执行回调；
     *                             'skip' = 拿不到锁返回 null，供 installation_id 生成 / 心跳上报等
     *                             「正确性依赖互斥」的场景使用，避免降级执行导致双 UUID / 重复上报。
     */
    public static function withLock(string $key, callable $callback, string $onContention = 'run'): mixed
    {
        $skip = ($onContention === 'skip');
        $redis = self::getRedis();
        if ($redis) {
            return self::withRedisLock(new PhpRedisLockConnection($redis), $key, $callback, $onContention);
        }

        // ===== 以下 file 驱动 flock 逻辑保持不变（仅新增 skip 分支） =====
        $lockFile = self::$cacheDir . 'lock_' . md5($key) . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            // 锁文件创建失败：skip 模式跳过本次执行，否则降级为非原子直接跑回调
            return $skip ? null : $callback();
        }
        try {
            if (flock($fp, LOCK_EX)) {
                return $callback();
            }
            // 获取锁失败：skip 模式跳过本次执行，否则降级为非原子直接跑回调
            return $skip ? null : $callback();
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /**
     * Redis 分布式锁核心逻辑。
     *
     * 设计要点：
     *  1) SET suffixKey token NX PX ttl：原子完成「占锁 + 过期」，避免 SETNX+EXPIRE 两步间崩溃留下永久锁；
     *  2) 随机 token（128-bit）：配合 Lua 校验，确保只删自己持有的锁；
     *  3) 拿不到锁做有限次短退避重试，仍失败则与 flock 失败一致「直接执行回调」降级，并写 error_log 告警；
     *  4) try/finally 保证回调正常返回或抛异常都释放锁；释放异常只告警、不影响业务返回；
     *  5) 回调超过 LOCK_TTL_MS 的边界见常量注释：锁会自动过期，临界区可能重叠，属可用性优先的取舍。
     *
     * @internal 公开仅为可注入假连接做测试，业务代码请走 withLock()
     */
    public static function withRedisLock(RedisLockConnection $conn, string $key, callable $callback, string $onContention = 'run'): mixed
    {
        $skip = ($onContention === 'skip');
        $suffixKey = self::LOCK_PREFIX . md5($key);
        // PhpRedis 会自动给 SET 的 key 加 OPT_PREFIX，故实际存储的完整 key = 前缀 + suffixKey。
        // 释放时把完整 key 放 ARGV 传给 Lua，与存储 key 严格一致（不依赖 EVAL KEYS 的前缀行为）。
        $fullKey = $conn->getLockKeyPrefix() . $suffixKey;
        $token = bin2hex(random_bytes(16));

        $acquired = false;
        for ($attempt = 0; $attempt <= self::LOCK_RETRY_MAX; $attempt++) {
            try {
                // NX：仅 key 不存在时写入；PX：毫秒级过期。成功返回 true 即拿到锁。
                if ($conn->set($suffixKey, $token, ['NX', 'PX' => self::LOCK_TTL_MS]) === true) {
                    $acquired = true;
                    break;
                }
            } catch (\Throwable $e) {
                // Redis 瞬时故障：记录并按「拿不到锁」降级，绝不让缓存锁拖垮业务。
                error_log('[Hublog Cache] Redis lock acquire failed: ' . $e->getMessage());
                break;
            }
            if ($attempt < self::LOCK_RETRY_MAX) {
                usleep(self::LOCK_RETRY_INTERVAL_US);
            }
        }

        if (!$acquired) {
            // 与 flock 失败时一致的降级语义：直接执行回调（非原子，功能可用）；
            // skip 模式下跳过本次执行，避免并发双 UUID / 重复上报
            error_log('[Hublog Cache] Redis lock busy for key ' . $key
                . ($skip ? ', skipped (skip mode)' : ', degraded to non-atomic execution'));
            return $skip ? null : $callback();
        }

        try {
            return $callback();
        } finally {
            try {
                // 仅当 token 匹配才删除，防止误删他人锁；释放失败不影响业务结果，仅告警。
                $conn->eval(self::LUA_RELEASE_LOCK, [$fullKey, $token], 0);
            } catch (\Throwable $e) {
                error_log('[Hublog Cache] Redis lock release failed: ' . $e->getMessage());
            }
        }
    }
}
