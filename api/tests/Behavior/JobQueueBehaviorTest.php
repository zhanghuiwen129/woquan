<?php
/**
 * 行为级测试：异步任务队列（JobQueue，V94 + P2-4 优先级/死信）
 *
 * 背景：视频压缩原在请求内同步跑 ffmpeg（最坏 30 分钟占住 worker），
 * V94 改成「请求内只入队，cron worker 异步消费」。
 *
 * 这个测试不读源码文本，而是真的建表、真的入队、真的跑 worker，
 * 验证队列的每一条关键性质：
 *   1. 入队后任务落库，状态 pending
 *   2. 幂等键：同一 dedupe_key 重复入队只有一条任务
 *   3. worker 认领任务并执行，状态流转 pending → running → done
 *   4. 失败任务被标记 failed 且保留错误摘要（可观测）
 *   5. 未到执行时间（run_after）的任务不会被拉取
 *   6. 已完成的历史任务可被清理，failed / dead_letter 的保留给运维查
 *   7. 高优先级先被认领；不传 priority 仍按 0 入队（向后兼容）
 *   8. attempts 达到 MAX_ATTEMPTS 时失败任务转 dead_letter，且不再被拉取
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\JobQueue;

final class JobQueueBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE IF EXISTS `jobs`');
        $pdo->exec(<<<'SQL'
        CREATE TABLE `jobs` (
            `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
            `type`        TEXT    NOT NULL,
            `status`      TEXT    NOT NULL DEFAULT 'pending',
            `payload`     TEXT    DEFAULT NULL,
            `dedupe_key`  TEXT    NOT NULL DEFAULT '',
            `attempts`    INTEGER NOT NULL DEFAULT 0,
            `last_error`  TEXT    DEFAULT NULL,
            `run_after`   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            `started_at`  TEXT    DEFAULT NULL,
            `finished_at` TEXT    DEFAULT NULL,
            `priority`    INTEGER NOT NULL DEFAULT 0,
            `created_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            `updated_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (`dedupe_key`)
        );
        CREATE INDEX `idx_jobs_priority` ON `jobs` (`status`, `priority`, `run_after`);
        SQL);
    }

    // ── 入队 ──────────────────────────────────────────────────────────────

    public function testEnqueuePersistsPendingJob(): void
    {
        $id = JobQueue::enqueue(
            JobQueue::TYPE_COMPRESS_VIDEO,
            ['source_path' => '/tmp/a.mp4', 'target_path' => '/tmp/a.mp4'],
            'compress_video:/tmp/a.mp4'
        );

        $this->assertGreaterThan(0, $id, '入队应返回任务 ID');
        $rows = $this->rows('jobs');
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('compress_video', $rows[0]['type']);
        $this->assertSame('compress_video:/tmp/a.mp4', $rows[0]['dedupe_key']);

        $payload = json_decode((string)$rows[0]['payload'], true);
        $this->assertSame('/tmp/a.mp4', $payload['source_path']);
    }

    public function testSameDedupeKeyDoesNotCreateDuplicateJob(): void
    {
        $first = JobQueue::enqueue(
            JobQueue::TYPE_COMPRESS_VIDEO,
            ['source_path' => '/tmp/x.mp4', 'target_path' => '/tmp/x.mp4'],
            'compress_video:/tmp/x.mp4'
        );
        $second = JobQueue::enqueue(
            JobQueue::TYPE_COMPRESS_VIDEO,
            ['source_path' => '/tmp/x.mp4', 'target_path' => '/tmp/x.mp4'],
            'compress_video:/tmp/x.mp4'
        );

        $this->assertSame($first, $second, '同幂等键应复用同一个任务 ID');
        $this->assertCount(1, $this->rows('jobs'), '同幂等键只能有一条任务');
    }

    public function testDifferentDedupeKeysCreateSeparateJobs(): void
    {
        JobQueue::enqueue(JobQueue::TYPE_COMPRESS_VIDEO, ['source_path' => '/tmp/a.mp4'], 'k:a');
        JobQueue::enqueue(JobQueue::TYPE_COMPRESS_VIDEO, ['source_path' => '/tmp/b.mp4'], 'k:b');
        $this->assertCount(2, $this->rows('jobs'));
    }

    // ── worker 执行 ───────────────────────────────────────────────────────

    public function testWorkerProcessesPendingJobs(): void
    {
        // 用一个未知类型来触发「处理器不存在」分支 → 任务应被标记 failed
        JobQueue::enqueue('unknown_type', ['x' => 1], 'k:unknown');

        $result = JobQueue::runPending();

        $this->assertSame(1, $result['processed'], '应处理 1 个任务');
        $this->assertSame(0, $result['succeeded']);
        $this->assertSame(1, $result['failed']);

        $rows = $this->rows('jobs');
        $this->assertSame('failed', $rows[0]['status'], '失败任务应标记 failed');
        $this->assertNotEmpty($rows[0]['last_error'], '失败任务必须保留错误摘要');
        $this->assertSame(1, (int)$rows[0]['attempts'], 'attempts 应记录尝试次数');
    }

    public function testWorkerSkipsJobsNotYetDue(): void
    {
        // run_after 在未来 → 现在不该被拉取
        $id = JobQueue::enqueue(
            JobQueue::TYPE_COMPRESS_VIDEO,
            ['source_path' => '/tmp/later.mp4'],
            'k:later',
            3600
        );
        $this->assertGreaterThan(0, $id);

        $result = JobQueue::runPending();

        $this->assertSame(0, $result['processed'], '未到执行时间的任务不应被拉取');
        $this->assertSame('pending', $this->rows('jobs')[0]['status']);
    }

    public function testRunPendingRespectsLimit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            JobQueue::enqueue('unknown_type', ['i' => $i], 'k:' . $i);
        }

        $result = JobQueue::runPending(3);

        $this->assertSame(3, $result['processed'], '一次最多处理 limit 个任务');
        $this->assertCount(8, $this->rows('jobs'), '未处理的任务应留在队列里');
    }

    public function testStatsReflectTaskStates(): void
    {
        JobQueue::enqueue('unknown_type', ['x' => 1], 'k:1');
        JobQueue::enqueue('unknown_type', ['x' => 2], 'k:2');

        $stats = JobQueue::stats();
        $this->assertSame(2, $stats['pending']);

        JobQueue::runPending();

        $stats = JobQueue::stats();
        $this->assertSame(2, $stats['failed']);
        $this->assertSame(0, $stats['pending']);
    }

    // ── 优先级 / 死信（P2-4） ─────────────────────────────────────────────

    public function testHigherPriorityJobIsClaimedFirst(): void
    {
        // 先入队低优先级，后入队高优先级；同 run_after 下高优先级应插队成功
        JobQueue::enqueue('unknown_type', ['x' => 1], 'k:low', 0, 1);
        $highId = JobQueue::enqueue('unknown_type', ['x' => 2], 'k:high', 0, 9);

        $result = JobQueue::runPending(1);

        $this->assertSame(1, $result['processed']);
        $this->assertSame($highId, $result['details'][0]['id'], '高优先级任务应先被认领');
        $rows = $this->rows('jobs');
        $this->assertSame('failed', $rows[1]['status'], '高优先级任务已被执行');
        $this->assertSame('pending', $rows[0]['status'], '低优先级任务仍在队列');
    }

    public function testEqualPriorityKeepsInsertionOrder(): void
    {
        $first = JobQueue::enqueue('unknown_type', ['x' => 1], 'k:first', 0, 5);
        JobQueue::enqueue('unknown_type', ['x' => 2], 'k:second', 0, 5);

        $result = JobQueue::runPending(1);

        $this->assertSame($first, $result['details'][0]['id'], '同优先级按 id 升序，保持公平');
    }

    public function testPriorityDefaultsToZeroForLegacyCallers(): void
    {
        // 既有调用点只传 3/4 个参数，必须照常工作且 priority 落库为 0
        $id = JobQueue::enqueue('unknown_type', ['x' => 1], 'k:legacy');
        $this->assertGreaterThan(0, $id);

        $rows = $this->rows('jobs');
        $this->assertSame(0, (int)$rows[0]['priority'], '不传 priority 应默认 0');
    }

    public function testDedupeReusesExistingJobWithOriginalPriority(): void
    {
        $first = JobQueue::enqueue('unknown_type', ['x' => 1], 'k:same', 0, 7);
        $second = JobQueue::enqueue('unknown_type', ['x' => 2], 'k:same', 0, 2);

        $this->assertSame($first, $second, '新优先级下 dedupe 仍应复用同一任务');
        $rows = $this->rows('jobs');
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int)$rows[0]['priority'], '复用已有任务时不应偷偷改优先级');
    }

    public function testFailureReachesDeadLetterAfterMaxAttempts(): void
    {
        // 未知类型 → execute() 抛异常路径；每次失败后重置为 pending 模拟运维重试入队
        $id = JobQueue::enqueue('unknown_type', ['x' => 1], 'k:dead');
        $this->assertTrue($id > 0);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                $this->pdo()->exec(
                    "UPDATE `jobs` SET `status` = 'pending' WHERE `id` = " . (int)$id
                );
            }
            $result = JobQueue::runPending(1);
            $this->assertSame(1, $result['processed']);
        }

        $rows = $this->rows('jobs');
        $this->assertSame(JobQueue::STATUS_DEAD_LETTER, $rows[0]['status'], '达到上限应转死信');
        $this->assertSame(3, (int)$rows[0]['attempts']);
        $this->assertNotEmpty($rows[0]['last_error'], '死信也要保留错误摘要');

        // 死信是终态：再跑 worker 不会被拉取
        $again = JobQueue::runPending();
        $this->assertSame(0, $again['processed'], '死信任务不应再被拉取');
    }

    public function testFalseReturnPathAlsoReachesDeadLetter(): void
    {
        // plugin_task + 非法 handler → AsyncTask::runHandler() 返回 false（非异常路径）
        $id = JobQueue::enqueue(
            JobQueue::TYPE_PLUGIN_TASK,
            ['handler' => 'no_such_handler'],
            'k:false-path'
        );

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                $this->pdo()->exec(
                    "UPDATE `jobs` SET `status` = 'pending' WHERE `id` = " . (int)$id
                );
            }
            JobQueue::runPending(1);
        }

        $rows = $this->rows('jobs');
        $this->assertSame(JobQueue::STATUS_DEAD_LETTER, $rows[0]['status'], '返回 false 也要能进死信');
    }

    public function testStatsIncludeDeadLetterCount(): void
    {
        $this->pdo()->exec(
            "INSERT INTO `jobs` (`type`, `status`, `dedupe_key`, `attempts`, `last_error`)
             VALUES ('unknown_type', 'dead_letter', 'k:dead-seed', 3, '重试耗尽')"
        );

        $stats = JobQueue::stats();

        $this->assertArrayHasKey('dead_letter', $stats);
        $this->assertSame(1, $stats['dead_letter']);
        $this->assertSame(0, $stats['pending']);
    }

    // ── 清理 ──────────────────────────────────────────────────────────────

    public function testPurgeRemovesOnlyOldDoneJobs(): void
    {
        // 一条 done 的旧任务 + 一条 failed 的旧任务
        $this->pdo()->exec(
            "INSERT INTO `jobs` (`type`,`status`,`dedupe_key`,`updated_at`)
             VALUES ('compress_video','done','k:done', datetime('now','localtime','-30 days'))"
        );
        $this->pdo()->exec(
            "INSERT INTO `jobs` (`type`,`status`,`dedupe_key`,`updated_at`)
             VALUES ('compress_video','failed','k:failed', datetime('now','localtime','-30 days'))"
        );

        $purged = JobQueue::purge(7);

        $this->assertSame(1, $purged, '只清理 done 状态的旧任务');
        $rows = $this->rows('jobs');
        $this->assertCount(1, $rows);
        $this->assertSame('failed', $rows[0]['status'], 'failed 任务要保留给运维查');
    }

    public function testPurgeKeepsRecentDoneJobs(): void
    {
        $this->pdo()->exec(
            "INSERT INTO `jobs` (`type`,`status`,`dedupe_key`,`updated_at`)
             VALUES ('compress_video','done','k:recent', datetime('now','localtime'))"
        );

        $purged = JobQueue::purge(7);

        $this->assertSame(0, $purged, '7 天内的完成任务不应被清理');
        $this->assertCount(1, $this->rows('jobs'));
    }

    public function testPurgeKeepsDeadLetterJobs(): void
    {
        $this->pdo()->exec(
            "INSERT INTO `jobs` (`type`,`status`,`dedupe_key`,`updated_at`)
             VALUES ('unknown_type','dead_letter','k:old-dead', datetime('now','localtime','-30 days'))"
        );

        $purged = JobQueue::purge(7);

        $this->assertSame(0, $purged, '死信任务必须留给运维排查');
        $this->assertSame('dead_letter', $this->rows('jobs')[0]['status']);
    }
}
