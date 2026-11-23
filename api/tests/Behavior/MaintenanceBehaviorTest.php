<?php
/**
 * 行为级测试：维护任务调度器（P1-3）
 *
 * 治理目标：把「过期上传清理」「定时发布」这类请求内同步重活移出请求热路径。
 * 原来它们的代价由随机撞上的那个用户请求承担（5% 概率触发 SELECT + 删文件 + DELETE，
 * 以及 60 秒节流的 COUNT + UPDATE），用户看到的是「自己点了一下却慢了 200ms」。
 *
 * 本测试验证真实行为：
 *   ① 未到窗口 → 不登记任何延迟任务（常态零开销）
 *   ② 到窗口但仍在容忍期 → 只记「到期」，不抢着做（交给 cron）
 *   ③ 超过容忍期 → 登记延迟任务，且同一请求内只登记一次
 *   ④ 延迟任务真正执行时：过期上传被删、到点的定时动态被发布、状态被记录
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Maintenance;
use Hublog\Core\Plugin;
use PDO;
use ReflectionProperty;

final class MaintenanceBehaviorTest extends BehaviorTestCase
{
    private string $tempDir = '';
    private string $originalStateFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_maint_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0777, true);

        $ref = new ReflectionProperty(Maintenance::class, 'stateFile');
        $ref->setAccessible(true);
        $this->originalStateFile = (string)$ref->getValue();
        $ref->setValue(null, $this->tempDir . DIRECTORY_SEPARATOR . 'maintenance_state');

        // 维护任务要用的两张表（覆盖底座自带的简化版）
        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE IF EXISTS `uploads`');
        $pdo->exec(<<<'SQL'
        CREATE TABLE `uploads` (
            `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
            `post_id`    INTEGER DEFAULT NULL,
            `url`        TEXT    DEFAULT NULL,
            `size`       INTEGER NOT NULL DEFAULT 0,
            `expires_at` TEXT    DEFAULT NULL,
            `file_hash`  TEXT    DEFAULT NULL
        );
        SQL);
        $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS `posts` (
            `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
            `status`       INTEGER NOT NULL DEFAULT 1,
            `scheduled_at` TEXT    DEFAULT NULL,
            `audit_state`  INTEGER NOT NULL DEFAULT 0
        );
        SQL);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionProperty(Maintenance::class, 'stateFile');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->originalStateFile);

        // 清掉本进程登记的延迟任务，避免影响同进程的后续用例
        $deferred = new ReflectionProperty(Plugin::class, 'deferred');
        $deferred->setAccessible(true);
        $deferred->setValue(null, []);

        $scheduled = new ReflectionProperty(Maintenance::class, 'scheduled');
        $scheduled->setAccessible(true);
        $scheduled->setValue(null, false);

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ── 调度语义 ─────────────────────────────────────────────────────────

    /** ① 未到窗口：什么都不该发生（常态路径零开销）。 */
    public function testTickDoesNothingWhenNotDue(): void
    {
        $this->writeState(['last' => time(), 'due' => 0]);

        Maintenance::tick();

        $this->assertSame([], $this->deferredTasks(), '未到窗口不应登记延迟任务');
        $this->assertSame(0, Maintenance::getState()['due'], '未到窗口不应标记到期');
        $this->assertFalse(Maintenance::isOverdue());
    }

    /** ② 到窗口但仍在容忍期：只记「到期」，把活留给 cron。 */
    public function testTickMarksDueWithoutRunningWhenWithinTolerance(): void
    {
        $this->writeState([
            'last' => time() - Maintenance::INTERVAL - 5,
            'due'  => 0,
        ]);

        Maintenance::tick();

        $this->assertSame([], $this->deferredTasks(), '容忍期内不应抢着做');
        $this->assertGreaterThan(0, Maintenance::getState()['due'], '应记录「已到期」供巡检观察');
        $this->assertTrue(Maintenance::isOverdue());
    }

    /** ③ 超过容忍期：登记延迟任务，且同一请求内只登记一次。 */
    public function testTickSchedulesFallbackAfterTolerance(): void
    {
        $this->writeState([
            'last' => time() - Maintenance::FALLBACK_AFTER - 60,
            'due'  => 0,
        ]);

        Maintenance::tick(true); // force = 忽略概率门（生产仍为 1%）

        $this->assertCount(1, $this->deferredTasks(), '超过容忍期应登记一个延迟任务');

        Maintenance::tick(true);
        $this->assertCount(1, $this->deferredTasks(), '同一请求内不得重复登记');
    }

    /** ④ 延迟任务真正执行时的真实副作用。 */
    public function testRunDueCleansExpiredUploadsAndPublishesScheduledPosts(): void
    {
        $pdo = $this->pdo();

        // 过期且未绑定动态的上传 → 应被清理
        $pdo->exec("INSERT INTO `uploads` (`post_id`, `url`, `expires_at`) VALUES (NULL, '/uploads/x.png', '2000-01-01 00:00:00')");
        // 未过期的上传 → 必须保留
        $pdo->exec("INSERT INTO `uploads` (`post_id`, `url`, `expires_at`) VALUES (NULL, '/uploads/keep.png', '2999-01-01 00:00:00')");
        // 已绑定动态的上传 → 必须保留（不能删用户已发出去的内容）
        $pdo->exec("INSERT INTO `uploads` (`post_id`, `url`, `expires_at`) VALUES (7, '/uploads/bound.png', '2000-01-01 00:00:00')");

        // 到点的定时动态 → 应被发布
        $pdo->exec("INSERT INTO `posts` (`status`, `scheduled_at`, `audit_state`) VALUES (0, '2000-01-01 00:00:00', 0)");
        // 未到点的定时动态 → 必须保留
        $pdo->exec("INSERT INTO `posts` (`status`, `scheduled_at`, `audit_state`) VALUES (0, '2999-01-01 00:00:00', 0)");
        // 待审核的定时动态 → 绝不能自动放出去（否则等于绕过审核）
        $pdo->exec("INSERT INTO `posts` (`status`, `scheduled_at`, `audit_state`) VALUES (0, '2000-01-01 00:00:00', 1)");

        $result = Maintenance::runDue('test');

        $this->assertSame(1, $result['uploads'], '只应删除 1 条过期且未绑定的上传');
        $this->assertSame(1, $result['published'], '只应发布 1 条到点且审核通过的定时动态');
        $this->assertSame([], $result['errors']);

        $remaining = $this->rows('uploads');
        $this->assertCount(2, $remaining, '未过期 / 已绑定的上传必须保留');

        $posts = $this->rows('posts');
        $published = array_values(array_filter($posts, static fn(array $r): bool => (int)$r['status'] === 1));
        $this->assertCount(1, $published, '只应有 1 条定时动态被转正');
        $this->assertSame(
            0,
            (int)$published[0]['audit_state'],
            '待审核的动态绝不能被定时任务自动放出去'
        );
    }

    /** 执行完成后状态被记录，下次请求不会重复立刻再跑一遍。 */
    public function testRunDueRecordsState(): void
    {
        Maintenance::runDue('test');

        $state = Maintenance::getState();
        $this->assertGreaterThan(0, $state['last']);
        $this->assertSame(0, $state['due']);
        $this->assertSame('test', $state['trigger']);
        $this->assertFalse(Maintenance::isOverdue(), '刚跑过就不该再被判为超期');
    }

    /** 重复执行必须幂等：不会重复发布、不会重复删。 */
    public function testRunDueIsIdempotent(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO `posts` (`status`, `scheduled_at`, `audit_state`) VALUES (0, '2000-01-01 00:00:00', 0)");

        $first = Maintenance::runDue('test');
        $second = Maintenance::runDue('test');

        $this->assertSame(1, $first['published']);
        $this->assertSame(0, $second['published'], '已发布的动态不能被重复发布');
    }

    // ── 工具 ─────────────────────────────────────────────────────────────

    /** @return list<callable> */
    private function deferredTasks(): array
    {
        $ref = new ReflectionProperty(Plugin::class, 'deferred');
        $ref->setAccessible(true);
        $tasks = $ref->getValue();
        return is_array($tasks) ? array_values($tasks) : [];
    }

    /** @param array<string,mixed> $data */
    private function writeState(array $data): void
    {
        $ref = new ReflectionProperty(Maintenance::class, 'stateFile');
        $ref->setAccessible(true);
        file_put_contents((string)$ref->getValue(), json_encode($data));
    }
}
