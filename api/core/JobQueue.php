<?php
/**
 * Hublog 异步任务队列
 *
 * 【为什么需要】
 * 视频压缩走 ffmpeg，最坏一次要几十分钟（V94 前是「6 次串行 exec × 300s = 30 分钟」），
 * 发生在请求内 → 用户上传视频要干等，PHP-FPM worker 也被长期占住。
 *
 * 项目原有的两种「异步」都不够用：
 *   1. Plugin::defer()：同进程内跑，进程被回收任务就没了，而且照样占着这个 worker；
 *   2. 外部 cron 打 /api/cron/scheduled：定时粒度，适合「每小时扫一次徽章」，
 *      不适合「上传后 10 秒内就要压缩好」这种秒级任务。
 *
 * 所以引入 jobs 表做持久化队列：
 *   - 请求侧只做「落盘 + 入队」，立刻返回；
 *   - worker 侧（/api/cron/jobs）拉取任务执行，完成后回写状态。
 *
 * 【为什么不用 Redis 队列】
 * 项目已支持 Redis 但它本来就是可选依赖（缺失回退文件缓存），
 * 把任务队列绑到可选组件上会让「没装 Redis 的站点」功能缺失。
 * jobs 表用 MySQL 实现，零额外依赖，和现有 cron_secret 鉴权模式一致。
 *
 * 【为什么用幂等键】
 * 同一个文件可能被重复入队（比如用户重试上传、网络抖动重发）。
 * uk_job_dedupe 保证「同一 dedupe_key 只有一条任务」，
 * 重复入队直接复用已有任务，不会压缩两次。
 */

declare(strict_types=1);

namespace Hublog\Core;

class JobQueue
{
    /** 任务类型：视频压缩 */
    public const TYPE_COMPRESS_VIDEO = 'compress_video';

    /** 任务类型：插件异步任务（AI 评论/审核/摘要/搜索，见 AsyncTask） */
    public const TYPE_PLUGIN_TASK = 'plugin_task';

    /** 状态 */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    /** 单次 worker 执行的任务上限（防止一次拉太多把 worker 拖死） */
    private const BATCH_LIMIT = 5;

    /**
     * 入队一个任务。
     *
     * @param string $type      任务类型
     * @param array  $payload   任务参数（会存成 JSON）
     * @param string $dedupeKey 幂等键；同键不重复排队
     * @param int    $delaySec  延迟多少秒再可执行（0 = 立即）
     * @return int 任务 ID；命中幂等键时返回已有任务 ID
     */
    public static function enqueue(string $type, array $payload, string $dedupeKey, int $delaySec = 0): int
    {
        $runAfter = date('Y-m-d H:i:s', time() + max(0, $delaySec));

        // 先查幂等键：命中就直接复用，避免依赖 INSERT ... ON DUPLICATE 的返回语义
        $existing = Database::fetchOne(
            "SELECT `id` FROM `jobs` WHERE `dedupe_key` = ? LIMIT 1",
            [$dedupeKey]
        );
        if ($existing) {
            return (int)$existing['id'];
        }

        try {
            return Database::insert('jobs', [
                'type'       => $type,
                'status'     => self::STATUS_PENDING,
                'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'dedupe_key' => $dedupeKey,
                'attempts'   => 0,
                'run_after'  => $runAfter,
            ]);
        } catch (\Throwable $e) {
            // 并发下两个请求同时入队 → 唯一索引冲突，此时另一个已经插进去了，复用它即可
            $existing = Database::fetchOne(
                "SELECT `id` FROM `jobs` WHERE `dedupe_key` = ? LIMIT 1",
                [$dedupeKey]
            );
            if ($existing) {
                return (int)$existing['id'];
            }
            error_log('[Hublog JobQueue] 入队失败: ' . $e->getMessage()); // @allow-error-log
            return 0;
        }
    }

    /**
     * 拉取待执行任务并逐个执行。
     *
     * 认领（claim）用 UPDATE ... WHERE status='pending' 原子抢占，
     * 和充值回调的「抢占 status」是同一套思路：并发 worker 不会重复执行同一个任务。
     *
     * @param int $limit 本次最多处理几个
     * @return array{processed:int,succeeded:int,failed:int,details:list<array>}
     */
    public static function runPending(int $limit = self::BATCH_LIMIT): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $details = [];

        for ($i = 0; $i < $limit; $i++) {
            $job = self::claimNext();
            if (!$job) {
                break;
            }

            $processed++;
            $jobId = (int)$job['id'];
            $type = (string)$job['type'];

            try {
                $payload = json_decode((string)($job['payload'] ?? ''), true);
                if (!is_array($payload)) {
                    throw new \RuntimeException('payload 不是合法 JSON');
                }

                $ok = self::execute($type, $payload);
                if ($ok) {
                    self::markDone($jobId);
                    $succeeded++;
                    $details[] = ['id' => $jobId, 'type' => $type, 'ok' => true];
                } else {
                    self::markFailed($jobId, '执行返回失败（未产出结果）');
                    $failed++;
                    $details[] = ['id' => $jobId, 'type' => $type, 'ok' => false];
                }
            } catch (\Throwable $e) {
                self::markFailed($jobId, $e->getMessage());
                $failed++;
                $details[] = ['id' => $jobId, 'type' => $type, 'ok' => false, 'error' => $e->getMessage()];
                error_log('[Hublog JobQueue] 任务 ' . $jobId . ' 执行失败: ' . $e->getMessage()); // @allow-error-log
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'details'   => $details,
        ];
    }

    /**
     * 原子认领一个待执行任务。
     *
     * 用「先按 id 升序取一条 pending，再带 status 条件 UPDATE 抢占」实现，
     * 并发 worker 只有一个能抢到（rowCount=0 说明被别人抢了）。
     */
    private static function claimNext(): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM `jobs`
             WHERE `status` = ? AND `run_after` <= ?
             ORDER BY `id` ASC LIMIT 1",
            [self::STATUS_PENDING, date('Y-m-d H:i:s')]
        );
        if (!$row) {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            "UPDATE `jobs`
             SET `status` = ?, `attempts` = `attempts` + 1, `started_at` = ?
             WHERE `id` = ? AND `status` = ?"
        );
        $stmt->execute([self::STATUS_RUNNING, date('Y-m-d H:i:s'), (int)$row['id'], self::STATUS_PENDING]);

        if ($stmt->rowCount() === 0) {
            return null; // 被别的 worker 抢走了
        }

        return $row;
    }

    /**
     * 分发任务到具体处理器。
     */
    private static function execute(string $type, array $payload): bool
    {
        switch ($type) {
            case self::TYPE_COMPRESS_VIDEO:
                $source = (string)($payload['source_path'] ?? '');
                $target = (string)($payload['target_path'] ?? '');

                // worker 执行前复核目标文件安全性：
                // 扩展名白名单 + 必须位于 uploads/ 目录内。
                // 万一有别处能写 jobs 表，也不能借此覆盖站点外的文件。
                if (!VideoCompress::isSafeTarget($target)) {
                    throw new \RuntimeException('目标文件不合法（扩展名或路径未通过校验）');
                }

                return VideoCompress::compressInPlace($source, $target);

            case self::TYPE_PLUGIN_TASK:
                // 插件重活（AI 评论/审核/摘要/搜索）由 AsyncTask 白名单分发。
                // handler 名只能来自队列 payload，AsyncTask 内部再校验白名单，
                // 绝不据此实例化任意类/方法（防 RCE）。
                $handler = (string)($payload['handler'] ?? '');
                return AsyncTask::runHandler($handler, $payload);

            default:
                throw new \RuntimeException('未知任务类型: ' . $type);
        }
    }

    private static function markDone(int $jobId): void
    {
        Database::update('jobs', [
            'status'      => self::STATUS_DONE,
            'finished_at' => date('Y-m-d H:i:s'),
            'last_error'  => null,
        ], 'id = ?', [$jobId]);
    }

    /**
     * 标记失败。
     *
     * 失败不直接删任务：保留 failed 状态 + 错误摘要，运维能查到「哪个文件压缩失败了、为什么」。
     * attempts 达到上限的任务不再被拉取（status 不再是 pending）。
     */
    private static function markFailed(int $jobId, string $error): void
    {
        Database::update('jobs', [
            'status'      => self::STATUS_FAILED,
            'finished_at' => date('Y-m-d H:i:s'),
            'last_error'  => mb_substr($error, 0, 500),
        ], 'id = ?', [$jobId]);
    }

    /**
     * 统计各状态任务数（给 /health 与后台展示用）。
     *
     * @return array<string,int>
     */
    public static function stats(): array
    {
        $rows = Database::fetchAll("SELECT `status`, COUNT(*) AS `c` FROM `jobs` GROUP BY `status`");
        $out = [
            self::STATUS_PENDING  => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_DONE    => 0,
            self::STATUS_FAILED  => 0,
        ];
        foreach ($rows as $row) {
            $out[(string)$row['status']] = (int)$row['c'];
        }
        return $out;
    }

    /**
     * 清理已完成的历史任务。
     *
     * done 状态的任务没有保留价值（payload 里是文件路径，不是业务数据），
     * 但 failed 的要留着给运维查。
     */
    public static function purge(int $keepDays = 7): int
    {
        try {
            $before = date('Y-m-d H:i:s', time() - $keepDays * 86400);
            $stmt = Database::getInstance()->prepare(
                "DELETE FROM `jobs` WHERE `status` = ? AND `updated_at` < ?"
            );
            $stmt->execute([self::STATUS_DONE, $before]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[Hublog JobQueue] 清理历史任务失败: ' . $e->getMessage()); // @allow-error-log
            return 0;
        }
    }
}
