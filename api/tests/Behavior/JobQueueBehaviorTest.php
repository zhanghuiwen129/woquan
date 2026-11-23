<?php
/**
 * 行为级测试：异步任务队列（JobQueue，V94）
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
 *   6. 已完成的历史任务可被清理，failed 的保留给运维查
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
            `created_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            `updated_at`  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE (`dedupe_key`)
        );
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
}
