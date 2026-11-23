<?php
/**
 * 行为级测试：只增日志表滚动归档（TableArchive）
 *
 * 真的建表、真的删数据，验证归档的核心不变量：
 *   1. 只删阈值之前的旧行，保留新行
 *   2. batchLimit 生效，一次调用不会清空整表
 *   3. 白名单之外的表不会被触碰
 *   4. 不同表可用各自的时间列（app_crash_logs 用 occurred_at）
 *   5. 阈值按 keepDays 计算，且最小保留 1 天
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\TableArchive;

final class TableArchiveBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = $this->pdo();
        foreach (['wallet_logs', 'app_crash_logs', 'notifications'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec(<<<'SQL'
        CREATE TABLE `wallet_logs` (
            `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
            `biz_key`    TEXT DEFAULT NULL,
            `created_at` TEXT NOT NULL
        );
        SQL);
        $pdo->exec(<<<'SQL'
        CREATE TABLE `app_crash_logs` (
            `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
            `occurred_at`  TEXT NOT NULL,
            `created_at`   TEXT NOT NULL
        );
        SQL);
        // 白名单外的表：即使有旧数据也不能被归档
        $pdo->exec(<<<'SQL'
        CREATE TABLE `notifications` (
            `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
            `is_read`    INTEGER NOT NULL DEFAULT 0,
            `created_at` TEXT NOT NULL
        );
        SQL);
    }

    /** 40 天前 / 今天 各插若干行 */
    private function seedTable(string $table, string $timeCol, int $oldCount, int $newCount): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $new = gmdate('Y-m-d H:i:s', time() - 60);
        for ($i = 0; $i < $oldCount; $i++) {
            $this->seed($table, [$timeCol => $old]);
        }
        for ($i = 0; $i < $newCount; $i++) {
            $this->seed($table, [$timeCol => $new]);
        }
    }

    public function testDeletesOnlyRowsOlderThanKeepDays(): void
    {
        $this->seedTable('wallet_logs', 'created_at', 3, 2);

        $result = TableArchive::run(30, 500);

        $this->assertSame(3, $result['deleted']['wallet_logs']);
        $this->assertArrayNotHasKey('wallet_logs', $result['errors']);
        $this->assertCount(2, $this->rows('wallet_logs'), '新行必须保留');
    }

    public function testBatchLimitCapsDeletionsPerRun(): void
    {
        $this->seedTable('wallet_logs', 'created_at', 10, 1);

        $result = TableArchive::run(30, 4);

        $this->assertSame(4, $result['deleted']['wallet_logs']);
        $this->assertCount(7, $this->rows('wallet_logs'));

        // 再跑一轮继续收敛，而不是一次性删完（避免长事务）
        $again = TableArchive::run(30, 4);
        $this->assertSame(4, $again['deleted']['wallet_logs']);
        $this->assertCount(3, $this->rows('wallet_logs'));
    }

    public function testTablesOutsideWhitelistAreUntouched(): void
    {
        $this->seedTable('notifications', 'created_at', 5, 0);

        $result = TableArchive::run(30, 500);

        $this->assertArrayNotHasKey('notifications', $result['deleted']);
        $this->assertCount(5, $this->rows('notifications'));
    }

    public function testUsesPerTableTimeColumn(): void
    {
        // app_crash_logs 的 occurred_at 是旧时间，created_at 是今天：
        // 若代码误用 created_at，这一行就不会被删
        $this->seed('app_crash_logs', [
            'occurred_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400),
            'created_at'  => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $result = TableArchive::run(30, 500);

        $this->assertSame(1, $result['deleted']['app_crash_logs']);
        $this->assertSame([], $this->rows('app_crash_logs'));
    }

    public function testKeepDaysIsClampedToAtLeastOneDay(): void
    {
        // ai_usage_logs 的当日配额依赖近 1 天数据，keepDays 不能传 0
        $result = TableArchive::run(0, 1);
        $this->assertSame(1, $result['keep_days']);
    }

    public function testMissingTableIsReportedButDoesNotAbortOtherTables(): void
    {
        // 白名单里的大多数表在测试库里不存在，应逐表报错但不中断循环
        $this->seedTable('wallet_logs', 'created_at', 1, 0);

        $result = TableArchive::run(30, 500);

        $this->assertSame(1, $result['deleted']['wallet_logs']);
        $this->assertArrayHasKey('points_logs', $result['errors']);
    }
}
