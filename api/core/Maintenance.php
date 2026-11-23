<?php
/**
 * 维护任务调度器 —— 把「请求内同步重活」移出请求热路径
 *
 * 【问题】
 * api/index.php 入口原本直接跑三件事：
 *   ① Migration::run()                          —— 迁移契约自检（TTL 到期时打 information_schema）
 *   ② PostController::lazyCleanupExpiredUploads() —— 5% 概率触发：SELECT + 删文件 + DELETE
 *   ③ PostController::lazyPublishScheduled()      —— 60 秒缓存锁节流：1 次 SELECT + UPDATE
 *
 * ② ③ 的代价由「随机撞上的那个用户请求」承担：用户点了一下却慢了 200ms，
 * 而且完全无法预测什么时候会慢 —— 这是典型的「延迟落在随机用户请求上」。
 * ① 已在别处处理（见 Migration::run 里的 P1-3 惊群修复）。
 *
 * 【做法】
 *   - 请求路径只做一次**廉价**的到期判断：读本地状态文件的 mtime，不查库、不写库；
 *   - 到期后把重活登记成延迟任务（Plugin::defer），响应先发出去，
 *     重活在 fastcgi_finish_request() 之后才跑 —— 客户端延迟恒为 0；
 *   - cron 请求同样走这条路径，所以「准点执行」不需要新增 cron 条目：
 *     站主原有的 cron 打到 /api/cron/* 时，维护任务会在该请求响应后立刻执行。
 *
 * 【为什么不干脆全删掉交给 cron】
 *   定时发布与过期上传清理历史上都因为「没有访问就没有执行」而失效过
 *   （publishScheduledPosts() 曾全项目零调用点，等于「定时发布」是假的）。
 *   所以保留请求侧兜底：超过 FALLBACK_AFTER 仍未执行时，允许极低概率的请求顶上。
 *
 * 【可观测】
 *   状态文件 cache/.maintenance_state 记录上次完成时间与到期标记，
 *   供 /health、/metrics 与运维巡检读取（见 getState()）。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class Maintenance
{
    /** 维护窗口（秒）：两次维护之间至少间隔多久 */
    public const INTERVAL = 300;

    /**
     * 请求侧兜底的容忍期（秒）。
     * 超过这个时间仍没有执行过维护（例如根本没配 cron），才允许某个请求顶上。
     */
    public const FALLBACK_AFTER = 1800;

    /**
     * 请求侧兜底的触发概率（%）。
     * 所有请求都去抢同一个机会反而更糟（惊群），所以压到 1%。
     */
    private const FALLBACK_PERCENT = 1;

    /** 状态文件：记录上次维护完成时间、到期时间与最后一次结果 */
    private static string $stateFile = __DIR__ . '/../../cache/.maintenance_state';

    /** 同一请求内只登记一次延迟任务 */
    private static bool $scheduled = false;

    /**
     * 请求路径入口（api/index.php 调用）。
     *
     * 常态开销：一次 filemtime()（OS 缓存内，约几微秒），不查库、不写库。
     *
     * @param bool $force 忽略兜底概率门（仅供测试与运维手动触发用），仍受间隔约束
     */
    public static function tick(bool $force = false): void
    {
        if (self::$scheduled) {
            return;
        }

        $state = self::getState();
        $last = (int)($state['last'] ?? 0);
        $elapsed = $last > 0 ? time() - $last : PHP_INT_MAX;

        if ($elapsed < self::INTERVAL) {
            return;
        }

        // 到了窗口但还在容忍期内：先记「到期」，由 cron / 后续请求接手。
        if ($elapsed < self::FALLBACK_AFTER) {
            if ((int)($state['due'] ?? 0) === 0) {
                self::writeState(['last' => $last, 'due' => time()]);
            }
            return;
        }

        // 兜底：cron 没配 / cron 挂了时的最后一道保险，低概率触发。
        if (!$force && mt_rand(1, 100) > self::FALLBACK_PERCENT) {
            return;
        }
        self::schedule('request-fallback');
    }

    /**
     * 把维护任务登记成延迟任务：响应先发出去，重活随后再跑。
     *
     * 用 Plugin::defer 而不是直接执行，是因为 api/index.php 在 echo 响应之后
     * 才会调用 Plugin::runDeferred()，而 runDeferred() 内部先 fastcgi_finish_request()
     * 断开连接 —— 客户端完全感知不到这段耗时。
     */
    public static function schedule(string $trigger = 'request'): bool
    {
        if (self::$scheduled) {
            return false;
        }
        self::$scheduled = true;

        Plugin::defer(static function () use ($trigger): void {
            self::runDue($trigger);
        });
        return true;
    }

    /**
     * 执行维护任务（幂等，可重复调用）。
     *
     * 也供 cron 端点直接调用：任何 /api/cron/* 请求都会经过 api/index.php，
     * 因而不需要新增 cron 条目就能获得「准点执行」。
     *
     * @return array{uploads:int,published:int,errors:list<string>}
     */
    public static function runDue(string $trigger = 'cron'): array
    {
        $result = ['uploads' => 0, 'published' => 0, 'errors' => []];

        try {
            $result['uploads'] = \Hublog\Controllers\PostController::cleanupExpiredUploads();
        } catch (\Throwable $e) {
            $result['errors'][] = 'uploads: ' . $e->getMessage();
            Logger::error('[Hublog Maintenance] 过期上传清理失败: ' . $e->getMessage());
        }

        try {
            $result['published'] = \Hublog\Controllers\PostController::publishScheduledPosts();
        } catch (\Throwable $e) {
            $result['errors'][] = 'published: ' . $e->getMessage();
            Logger::error('[Hublog Maintenance] 定时发布失败: ' . $e->getMessage());
        }

        self::writeState([
            'last' => time(),
            'due' => 0,
            'trigger' => $trigger,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * 维护状态（供 /health、/metrics、巡检读取）。
     *
     * @return array{last:int,due:int,trigger:string,result:array}
     */
    public static function getState(): array
    {
        $empty = ['last' => 0, 'due' => 0, 'trigger' => '', 'result' => []];
        if (!is_file(self::$stateFile)) {
            return $empty;
        }
        $raw = @file_get_contents(self::$stateFile);
        if ($raw === false || $raw === '') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $empty;
        }
        return [
            'last' => (int)($data['last'] ?? 0),
            'due' => (int)($data['due'] ?? 0),
            'trigger' => (string)($data['trigger'] ?? ''),
            'result' => (array)($data['result'] ?? []),
        ];
    }

    /**
     * 维护是否已超期未执行（true = 该跑了但还没跑）。
     */
    public static function isOverdue(): bool
    {
        $state = self::getState();
        $last = (int)($state['last'] ?? 0);
        return $last === 0 || (time() - $last) >= self::INTERVAL;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function writeState(array $data): void
    {
        $dir = dirname(self::$stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents(self::$stateFile, $payload, LOCK_EX);
        }
    }
}
