<?php
/**
 * 批次三：PointRepository / ShopRepository 迁移回归测试
 *
 * 对照法：每个被测查询都在本文件里保留一份「迁移前 HEAD 原文 SQL」私有方法，
 * 在 SQLite 内存库里同时执行原始 SQL 与新 Repository 方法，assertSame 逐字段比对。
 * 覆盖空结果 / 单行 / 多页 / 软删与状态负例 / 事务成功与异常回滚。
 *
 * 特别防批次二缺陷：wheel_prizes / users / shop_products / shop_orders 的
 * 「相似但字段清单不同」查询分别建立快照，Repository 也分别建方法，不共用。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\PointRepository;
use Hublog\Core\ShopRepository;
use PDO;

final class Batch3RepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // MySQL 的 CURDATE() / NOW() 在 SQLite 中注册等价实现
        $this->pdo()->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
        $this->pdo()->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `user_signins` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `sign_month` TEXT    NOT NULL,
    `sign_date`  TEXT    NOT NULL,
    `points`     INTEGER NOT NULL DEFAULT 0,
    `streak`     INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `points_logs` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `points`        INTEGER NOT NULL,
    `type`          TEXT    DEFAULT NULL,
    `biz_type`      TEXT    DEFAULT NULL,
    `biz_id`        INTEGER DEFAULT 0,
    `balance_after` INTEGER DEFAULT 0,
    `remark`        TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL
);
CREATE TABLE `point_tasks` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `title`     TEXT    DEFAULT NULL,
    `desc`      TEXT    DEFAULT NULL,
    `points`    INTEGER NOT NULL DEFAULT 0,
    `task_type` TEXT    DEFAULT NULL,
    `target`    INTEGER NOT NULL DEFAULT 1,
    `period`    TEXT    DEFAULT 'daily',
    `status`    INTEGER NOT NULL DEFAULT 1,
    `sort`      INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `user_task_logs` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`   INTEGER NOT NULL,
    `task_id`   INTEGER NOT NULL,
    `times`     INTEGER NOT NULL DEFAULT 0,
    `claimed`   INTEGER NOT NULL DEFAULT 0,
    `last_date` TEXT    DEFAULT NULL,
    UNIQUE (`user_id`, `task_id`)
);
CREATE TABLE `posts` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`   INTEGER NOT NULL,
    `post_type` TEXT    DEFAULT 'normal',
    `status`    INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT   DEFAULT NULL
);
CREATE TABLE `comments` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `post_id`    INTEGER NOT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `likes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `circle_members` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `follows` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `wheel_prizes` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`        TEXT    DEFAULT NULL,
    `prize_type`  TEXT    DEFAULT NULL,
    `value`       INTEGER NOT NULL DEFAULT 0,
    `probability` REAL    NOT NULL DEFAULT 0,
    `total`       INTEGER NOT NULL DEFAULT 0,
    `used`        INTEGER NOT NULL DEFAULT 0,
    `status`      INTEGER NOT NULL DEFAULT 1,
    `sort`        INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `wheel_records` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `prize_id`   INTEGER NOT NULL,
    `prize_type` TEXT    DEFAULT NULL,
    `value`      INTEGER NOT NULL DEFAULT 0,
    `free`       INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `wallet_logs` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `amount`        REAL    NOT NULL DEFAULT 0,
    `type`          TEXT    DEFAULT NULL,
    `biz_type`      TEXT    DEFAULT NULL,
    `biz_id`        INTEGER DEFAULT 0,
    `biz_key`       TEXT    DEFAULT NULL,
    `balance_after` TEXT    DEFAULT NULL,
    `remark`        TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL
);
CREATE TABLE `shop_products` (
    `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
    `seller_id`      INTEGER NOT NULL DEFAULT 0,
    `type`           TEXT    DEFAULT 'physical',
    `title`          TEXT    DEFAULT NULL,
    `cover`          TEXT    DEFAULT NULL,
    `images`         TEXT    DEFAULT NULL,
    `content`        TEXT    DEFAULT NULL,
    `price_balance`  REAL    NOT NULL DEFAULT 0,
    `price_points`   INTEGER NOT NULL DEFAULT 0,
    `vip_level`      INTEGER NOT NULL DEFAULT 0,
    `vip_days`       INTEGER NOT NULL DEFAULT 0,
    `discount_mode`  TEXT    DEFAULT 'percent',
    `discount_value` REAL    NOT NULL DEFAULT 0,
    `vip_levels`     TEXT    DEFAULT '',
    `valid_days`     INTEGER NOT NULL DEFAULT 0,
    `stock`          INTEGER NOT NULL DEFAULT 0,
    `sales`          INTEGER NOT NULL DEFAULT 0,
    `sort`           INTEGER NOT NULL DEFAULT 0,
    `status`         INTEGER NOT NULL DEFAULT 1,
    `created_at`     TEXT    DEFAULT NULL
);
CREATE TABLE `shop_orders` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `order_no`        TEXT    DEFAULT NULL,
    `user_id`         INTEGER NOT NULL,
    `product_id`      INTEGER NOT NULL,
    `qty`             INTEGER NOT NULL DEFAULT 1,
    `pay_currency`    TEXT    DEFAULT 'balance',
    `amount`          REAL    NOT NULL DEFAULT 0,
    `points`          INTEGER NOT NULL DEFAULT 0,
    `status`          TEXT    DEFAULT 'paid',
    `ship_status`     TEXT    DEFAULT 'none',
    `receiver_name`   TEXT    DEFAULT NULL,
    `receiver_phone`  TEXT    DEFAULT NULL,
    `receiver_address` TEXT   DEFAULT NULL,
    `paid_at`         TEXT    DEFAULT NULL,
    `created_at`      TEXT    DEFAULT NULL
);
CREATE TABLE `user_coupons` (
    `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`        INTEGER NOT NULL,
    `product_id`     INTEGER NOT NULL DEFAULT 0,
    `order_id`       INTEGER NOT NULL DEFAULT 0,
    `slot`           INTEGER NOT NULL DEFAULT 0,
    `title`          TEXT    DEFAULT NULL,
    `discount_mode`  TEXT    DEFAULT 'percent',
    `discount_value` REAL    NOT NULL DEFAULT 0,
    `vip_levels`     TEXT    DEFAULT '',
    `status`         TEXT    DEFAULT 'unused',
    `expire_at`      TEXT    DEFAULT NULL,
    `created_at`     TEXT    DEFAULT NULL
);
SQL);
    }

    // ====================================================================
    // PointRepository：基础读取（三条 points 查询刻意不合并）
    // ====================================================================

    public function testPointsReadsKeepTheirOriginalFetchModes(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'points' => 123]);
        $this->seed('users', ['id' => 8, 'username' => 'u8', 'points' => 0]);
        $this->seed('settings', ['key' => 'point_rate', 'value' => '12']);

        $this->assertSame($this->originalPointsRow(7), PointRepository::fetchPointsRowById(7));
        $this->assertSame($this->originalPointsScalar(7), PointRepository::fetchPointsColumnById(7));
        $this->assertSame($this->originalPointsScalarLimited(7), PointRepository::fetchPointsAfterById(7));
        // 三个方法虽然数值相同，但来自不同 SQL；不互相代用
        $this->assertSame(123, (int)PointRepository::fetchPointsColumnById(7));
        $this->assertNull(PointRepository::fetchPointsRowById(999));
        $this->assertSame('12', PointRepository::fetchSetting('point_rate'));
        $this->assertFalse(PointRepository::fetchSetting('not_configured'));
    }

    public function testSigninProbeQueriesKeepDistinctFieldLists(): void
    {
        $today = date('Y-m-d');
        $this->seed('user_signins', [
            'user_id' => 7, 'sign_month' => date('Y-m'), 'sign_date' => $today,
            'points' => 14, 'streak' => 3,
        ]);

        $this->assertSame($this->originalTodaySignin(7, $today), PointRepository::findTodaySignin(7, $today));
        $this->assertSame($this->originalSignDateProbe(7, $today), PointRepository::findSignDateByDate(7, $today));
        $this->assertSame($this->originalSignIdProbe(7, $today), PointRepository::findSigninIdByDate(7, $today));
        $this->assertSame(['id', 'points'], array_keys(PointRepository::findTodaySignin(7, $today)));
        $this->assertSame(['sign_date'], array_keys(PointRepository::findSignDateByDate(7, $today)));
        $this->assertSame(['id'], array_keys(PointRepository::findSigninIdByDate(7, $today)));
        $this->assertNull(PointRepository::findTodaySignin(8, $today), '未签到用户为空结果');
    }

    // ====================================================================
    // PointRepository：流水（方向/分类筛选、分页、统计）
    // ====================================================================

    public function testLogsFiltersPaginationAndSummaryMatchOriginalSql(): void
    {
        $rows = [
            [5, 10, 'signin'], [4, -5, 'spend'], [3, 6, 'task'],
            [2, -2, 'spend'], [1, 8, 'reward'],
        ];
        foreach ($rows as [$id, $points, $type]) {
            $this->seed('points_logs', [
                'id' => $id, 'user_id' => 7, 'points' => $points, 'type' => $type,
                'biz_type' => 'test', 'biz_id' => $id, 'balance_after' => 100,
                'remark' => 'r' . $id, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->seed('points_logs', [
            'id' => 6, 'user_id' => 8, 'points' => 999, 'type' => 'signin',
            'balance_after' => 999, 'remark' => 'other',
        ]);

        foreach ([
            ['all', '', 2, 0], ['all', '', 2, 2], ['income', '', 20, 0],
            ['expense', '', 20, 0], ['all', 'spend', 20, 0], ['all', 'task', 20, 0],
            ['expense', 'task', 20, 0], ['all', 'unknown_type', 20, 0],
        ] as [$direction, $type, $limit, $offset]) {
            $expected = $this->originalLogs(7, $direction, $type, $limit, $offset);
            $actual = PointRepository::fetchLogs(7, $direction, $type, $limit, $offset);
            $this->assertSame($expected, $actual, "流水筛选 {$direction}/{$type} 分页 {$offset}+{$limit} 必须逐字段一致");
            $this->assertSame(
                (int)$this->pdo()->query($this->originalLogsCountSql(7, $direction, $type))->fetchColumn(),
                PointRepository::countLogs(7, $direction, $type),
                '计数必须与同 WHERE 的原始 SQL 一致'
            );
        }
        $this->assertSame([], PointRepository::fetchLogs(999, 'all', '', 20, 0), '空结果负例');
        $this->assertSame(0, PointRepository::countLogs(999, 'all', ''));
        $this->assertSame(
            ['id', 'points', 'type', 'biz_type', 'biz_id', 'balance_after', 'remark', 'created_at'],
            array_keys(PointRepository::fetchLogs(7, 'all', '', 1, 0)[0]),
            '字段清单/顺序不得变化'
        );

        $stmt = $this->pdo()->prepare($this->originalLogsSummarySql());
        $stmt->execute([7]);
        $this->assertSame($stmt->fetch(PDO::FETCH_ASSOC), PointRepository::summarizeLogs(7));
        $this->assertSame(0, (int)PointRepository::summarizeLogs(999)['cnt'], '无流水时统计为 0');
    }

    // ====================================================================
    // PointRepository：任务列表与计数器（动态条件全部绑定）
    // ====================================================================

    public function testTaskQueriesAndCountersMatchOriginalSql(): void
    {
        $month = date('Y-m');
        $today = date('Y-m-d');
        $this->seed('users', [
            'id' => 7, 'username' => 'u7', 'nickname' => '昵称', 'avatar' => '/a.jpg', 'points' => 0,
        ]);
        $this->seed('point_tasks', [
            'id' => 1, 'title' => '完善资料', 'desc' => 'd1', 'points' => 5, 'task_type' => 'profile',
            'target' => 1, 'period' => 'once', 'status' => 1, 'sort' => 2,
        ]);
        $this->seed('point_tasks', [
            'id' => 2, 'title' => '发动态', 'desc' => 'd2', 'points' => 3, 'task_type' => 'post',
            'target' => 3, 'period' => 'daily', 'status' => 1, 'sort' => 1,
        ]);
        $this->seed('point_tasks', [
            'id' => 3, 'title' => '已下线', 'points' => 9, 'task_type' => 'like',
            'target' => 1, 'period' => 'once', 'status' => 0, 'sort' => 3,
        ]);
        $this->seed('user_task_logs', [
            'id' => 1, 'user_id' => 7, 'task_id' => 1, 'times' => 1, 'claimed' => 1, 'last_date' => $today,
        ]);

        // 计数器数据源
        $this->seed('posts', ['id' => 1, 'user_id' => 7, 'status' => 1, 'created_at' => $today . ' 10:00:00']);
        $this->seed('posts', ['id' => 2, 'user_id' => 7, 'status' => 0, 'created_at' => $today . ' 10:00:00']);
        $this->seed('comments', ['id' => 1, 'user_id' => 7, 'post_id' => 10, 'created_at' => $today . ' 10:00:00']);
        $this->seed('likes', ['id' => 1, 'user_id' => 7, 'created_at' => $today . ' 10:00:00']);
        $this->seed('circle_members', ['id' => 1, 'user_id' => 7, 'created_at' => $today . ' 10:00:00']);
        $this->seed('follows', ['id' => 1, 'follower_id' => 7, 'created_at' => $today . ' 10:00:00']);
        $this->seed('user_signins', [
            'id' => 10, 'user_id' => 7, 'sign_month' => $month, 'sign_date' => $today,
            'points' => 2, 'streak' => 1, 'created_at' => $today . ' 10:00:00',
        ]);
        $this->seed('user_signins', [
            'id' => 11, 'user_id' => 7, 'sign_month' => $month, 'sign_date' => '2020-01-01',
            'points' => 2, 'streak' => 1, 'created_at' => '2020-01-01 10:00:00',
        ]);

        $stmt = $this->pdo()->query(
            "SELECT id, title, `desc`, points, task_type, target, period, status, sort
             FROM point_tasks WHERE status = 1 ORDER BY sort ASC, id ASC"
        );
        $this->assertSame($stmt->fetchAll(PDO::FETCH_ASSOC), PointRepository::fetchActiveTasks());
        $this->assertSame([2, 1], array_column(PointRepository::fetchActiveTasks(), 'id'), 'sort ASC, id ASC');
        $this->assertSame(
            $this->pdo()->query("SELECT task_id, times, claimed, last_date FROM user_task_logs WHERE user_id = 7")
                ->fetchAll(PDO::FETCH_ASSOC),
            PointRepository::fetchUserTaskLogs(7)
        );
        $this->assertSame(
            $this->pdo()->query("SELECT * FROM user_task_logs WHERE user_id = 7 AND task_id = 1 LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC),
            PointRepository::findUserTaskLog(7, 1)
        );
        $this->assertNull(PointRepository::findUserTaskLog(7, 999));

        foreach (['profile', 'post', 'comment', 'like', 'circle_join', 'follow', 'signin_days'] as $type) {
            foreach ([null, $today] as $date) {
                $this->assertSame(
                    $this->originalTaskProgress(7, $type, $month, $date),
                    PointRepository::countTaskProgress(7, $type, $month, $date),
                    "任务计数器 {$type} / " . ($date ?? '累计') . " 必须一致"
                );
            }
        }
        $this->assertSame(0, PointRepository::countTaskProgress(7, 'unknown_type', $month, null));
        $this->assertSame(0, PointRepository::countTaskProgress(999, 'post', $month, null), '空结果负例');

        $this->assertSame(
            $this->pdo()->query("SELECT sign_date, points, streak FROM user_signins WHERE user_id = 7 AND sign_month = '{$month}'")
                ->fetchAll(PDO::FETCH_ASSOC),
            PointRepository::fetchMonthSignins(7, $month)
        );
        $this->assertSame(2, PointRepository::countMonthSignins(7, $month));
        $this->assertSame(0, PointRepository::countMonthSignins(999, $month));
    }

    // ====================================================================
    // PointRepository：转盘（三套奖品查询互不复用）
    // ====================================================================

    public function testWheelReadsKeepThreeDistinctPrizeQueriesAndRecordShape(): void
    {
        foreach ([
            [1, '积分券', 'points', 10, 50.0, 0, 0, 1, 2],
            [2, '余额券', 'balance', 100, 50.0, 5, 5, 1, 1], // 库存耗尽
            [3, '停用券', 'thanks', 0, 50.0, 0, 0, 0, 0],     // status=0
            [4, '会员券', 'vip', 30, 50.0, 10, 2, 1, 3],
        ] as [$id, $name, $type, $value, $prob, $total, $used, $status, $sort]) {
            $this->seed('wheel_prizes', [
                'id' => $id, 'name' => $name, 'prize_type' => $type, 'value' => $value,
                'probability' => $prob, 'total' => $total, 'used' => $used,
                'status' => $status, 'sort' => $sort,
            ]);
        }
        $available = PointRepository::fetchAvailableWheelPrizes();
        $drawable = PointRepository::fetchDrawableWheelPrizes();
        $admin = ShopRepository::fetchAllWheelPrizes();

        $this->assertSame([1, 4], array_column($available, 'id'), '可展示奖品：4 列 + sort,id 排序 + 库存过滤');
        $this->assertSame(['id', 'name', 'prize_type', 'value'], array_keys($available[0]));
        $this->assertSame([1, 4], array_column($drawable, 'id'));
        $this->assertArrayHasKey('probability', $drawable[0], '可抽取奖品必须 SELECT * 含概率/库存列');
        $this->assertSame([3, 2, 1, 4], array_column($admin, 'id'), '商城后台含停用奖品且按 sort,id 排序');
        $this->assertArrayHasKey('status', $admin[0]);
        $this->assertNotSame(array_keys($available[0]), array_keys($drawable[0]), '积分域两条奖品查询字段清单必须不同');
        $this->assertSame(
            $this->pdo()->query("SELECT id, name, prize_type, value FROM wheel_prizes WHERE status = 1 AND (total = 0 OR used < total) ORDER BY sort ASC, id ASC")
                ->fetchAll(PDO::FETCH_ASSOC),
            $available
        );
        $this->assertSame(
            $this->pdo()->query("SELECT * FROM wheel_prizes WHERE status = 1 AND (total = 0 OR used < total)")
                ->fetchAll(PDO::FETCH_ASSOC),
            $drawable
        );

        $today = date('Y-m-d') . ' 12:00:00';
        $this->seed('wheel_records', [
            'id' => 1, 'user_id' => 7, 'prize_id' => 1, 'prize_type' => 'points',
            'value' => 10, 'free' => 1, 'created_at' => $today,
        ]);
        $this->seed('wheel_records', [
            'id' => 2, 'user_id' => 7, 'prize_id' => 4, 'prize_type' => 'vip',
            'value' => 30, 'free' => 0, 'created_at' => $today,
        ]);
        $this->seed('wheel_records', [
            'id' => 3, 'user_id' => 7, 'prize_id' => 2, 'prize_type' => 'balance',
            'value' => 100, 'free' => 1, 'created_at' => '2020-01-01 12:00:00',
        ]);
        $this->assertSame(1, PointRepository::countTodayFreeDraws(7));
        $this->assertSame(0, PointRepository::countTodayFreeDraws(999));
        $this->assertSame($this->originalWheelRecords(7, 2), PointRepository::fetchWheelRecords(7, 2));
        $this->assertSame($this->originalWheelRecords(7, 1), PointRepository::fetchWheelRecords(7, 1));
        $this->assertSame([], PointRepository::fetchWheelRecords(999, 20));
        $this->assertSame(
            ['id', 'prize_id', 'prize_type', 'value', 'free', 'created_at', 'name'],
            array_keys(PointRepository::fetchWheelRecords(7, 20)[0])
        );
    }

    // ====================================================================
    // PointRepository：事务与资产敏感写
    // ====================================================================

    public function testSigninTransactionCommitsAndRollsBackAtomically(): void
    {
        $today = date('Y-m-d');
        $month = date('Y-m');
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'points' => 10]);
        $this->seed('user_signins', [
            'id' => 1, 'user_id' => 7, 'sign_month' => $month,
            'sign_date' => date('Y-m-d', strtotime($today . ' -1 day')), 'points' => 4, 'streak' => 3,
        ]);

        $after = PointRepository::insertSigninAndAddPoints(7, 4, 4, 4, $today, $month);
        $this->assertSame(14, $after, '返回事务内重读的真实余额');
        $this->assertSame(14, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn());
        $this->assertCount(2, $this->rows('user_signins'));
        $ledger = $this->rows('points_logs');
        $this->assertCount(1, $ledger);
        $this->assertSame('signin', $ledger[0]['type']);
        $this->assertSame(14, (int)$ledger[0]['balance_after']);
        $this->assertSame('每日签到（第 4 天，连续 4 天）', $ledger[0]['remark'], '签到文案逐字不变');

        // 强制在第二条写（积分流水）ABORT，验证签到入库被回滚
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_signin_ledger BEFORE INSERT ON points_logs
WHEN NEW.remark = '每日签到（第 99 天，连续 99 天）'
BEGIN SELECT RAISE(ABORT, 'forced rollback'); END;
SQL);
        try {
            PointRepository::insertSigninAndAddPoints(7, 8, 99, 99, $today, $month);
            $this->fail('应当抛出异常');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced rollback', $e->getMessage());
        }
        $this->assertSame(14, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn(), '余额零残留');
        $this->assertCount(2, $this->rows('user_signins'), '签到行零残留');
        $this->assertCount(1, $this->rows('points_logs'), '流水零残留');
    }

    public function testAddPointsAtomicUpdateErrorMessagesAndLedger(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'points' => 10]);

        $this->assertSame(15, PointRepository::addPoints(7, 5, 'reward', 'wheel', 1, '加积分'));
        $this->assertSame(6, PointRepository::addPoints(7, -9, 'spend', 'wheel', 0, '扣积分'));
        $this->assertSame(2, count($this->rows('points_logs')));

        try {
            PointRepository::addPoints(999, 1, 'adjust', 'admin', 0, 'x');
            $this->fail('用户不存在应抛异常');
        } catch (\Throwable $e) {
            $this->assertSame('用户不存在', $e->getMessage());
        }
        try {
            PointRepository::addPoints(7, -100, 'spend', 'wheel', 0, 'x');
            $this->fail('积分不足应抛异常');
        } catch (\Throwable $e) {
            $this->assertSame('积分不足', $e->getMessage());
        }
        $this->assertCount(2, $this->rows('points_logs'), '失败路径不得写流水');
    }

    /**
     * V147 回归：delta=0 必须走 HEAD 短路（不 UPDATE、不写流水），
     * 否则 MySQL 下 `points + 0` 的 rowCount=0 会被误判为「积分不足」，
     * 使 wheel_cost=0 / 0 分奖品配置整单回滚；SQLite 下则多写 0 分流水。
     */
    public function testAddPointsZeroDeltaShortCircuitsWithoutLedger(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'points' => 0]);
        $this->seed('users', ['id' => 8, 'username' => 'u8', 'points' => 37]);

        $this->assertSame(0, PointRepository::addPoints(7, 0, 'spend', 'wheel', 0, '零扣费'), '余额 0 时返回 0');
        $this->assertSame(37, PointRepository::addPoints(8, 0, 'reward', 'wheel', 1, '零奖励'), '返回当前余额');
        $this->assertSame([], $this->rows('points_logs'), 'delta=0 不得写流水（关键断言）');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn(), '余额不变');
        $this->assertSame(37, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 8')->fetchColumn(), '余额不变');
        $this->assertSame(0, PointRepository::addPoints(999, 0, 'adjust', 'admin', 0, 'x'), '用户不存在时与 HEAD currentPoints 一致：返回 0 且不抛异常');
        $this->assertSame([], $this->rows('points_logs'), '不存在的用户也不得写流水');
    }

    /** 覆盖 finishWheelDraw 内部 addPoints 的 zero-cost / zero-value 路径 */
    public function testWheelDrawWithZeroCostAndZeroValuePrizeWritesNoZeroLedger(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'points' => 100]);
        $this->seed('users', ['id' => 8, 'username' => 'u8', 'points' => 100]);
        foreach ([
            [1, '零分奖', 0],
            [2, '普通奖', 10],
        ] as [$id, $name, $value]) {
            $this->seed('wheel_prizes', [
                'id' => $id, 'name' => $name, 'prize_type' => 'points', 'value' => $value,
                'total' => 0, 'used' => 0, 'status' => 1,
            ]);
        }

        $zeroPrize = PointRepository::fetchDrawableWheelPrizes()[0];
        $result = PointRepository::finishWheelDraw(
            7, $zeroPrize, false, 0, 1, 1,
            ['type' => 'points', 'value' => 0]
        );
        $this->assertSame(100, $result['points'], 'cost=0 + value=0 余额不变');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs WHERE user_id = 7')->fetchColumn(), '两条 0 分流水都不得写入（关键断言）');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM wheel_records WHERE user_id = 7')->fetchColumn(), '抽奖记录照常落库');

        $normalPrize = PointRepository::fetchDrawableWheelPrizes()[1];
        $result = PointRepository::finishWheelDraw(
            8, $normalPrize, false, 0, 1, 1,
            ['type' => 'points', 'value' => 10]
        );
        $this->assertSame(110, $result['points'], 'cost=0 不扣费，只发奖');
        $logs = $this->pdo()->query('SELECT points, type FROM points_logs WHERE user_id = 8')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['points' => 10, 'type' => 'reward']], $logs, '只写中奖流水，不写 0 分扣费流水');
    }

    public function testWheelDrawCommitsAllAwardBranchesAndRollsBackAtomically(): void
    {
        foreach ([7, 8, 9] as $userId) {
            $this->seed('users', [
                'id' => $userId, 'username' => 'u' . $userId,
                'points' => 100, 'balance' => 20.0, 'vip_level' => 1,
                'vip_expire_at' => '2026-01-01 00:00:00',
            ]);
        }
        foreach ([
            [1, '积分奖', 'points', 10, 0, 0],
            [2, '余额奖', 'balance', 500, 0, 0],
            [3, '会员奖', 'vip', 30, 5, 1],
            [4, '限量奖', 'points', 10, 5, 0],
        ] as [$id, $name, $type, $value, $total, $used]) {
            $this->seed('wheel_prizes', [
                'id' => $id, 'name' => $name, 'prize_type' => $type, 'value' => $value,
                'total' => $total, 'used' => $used, 'status' => 1,
            ]);
        }

        $pointsPrize = PointRepository::fetchDrawableWheelPrizes()[0];
        $result = PointRepository::finishWheelDraw(
            7, $pointsPrize, false, 20, 1, 1,
            ['type' => 'points', 'value' => 10]
        );
        $this->assertSame(90, $result['points'], '扣 20 + 奖 10 后余额 90（事务内重读）');
        $this->assertSame(0, $result['free_left'], '非免费抽奖 free_left = freeDaily - usedFree');
        $this->assertSame(2, count($this->rows('points_logs')), '扣费与中奖两条流水');
        $this->assertSame(1, count($this->rows('wheel_records')));

        $balancePrize = PointRepository::fetchDrawableWheelPrizes()[1];
        $result = PointRepository::finishWheelDraw(
            8, $balancePrize, true, 20, 1, 0,
            ['type' => 'balance', 'value' => 500]
        );
        $this->assertSame(100, $result['points'], '免费抽奖不扣积分');
        $this->assertSame(0, $result['free_left']);
        $wallet = $this->rows('wallet_logs');
        $this->assertCount(1, $wallet);
        $this->assertSame(5.0, (float)$wallet[0]['amount']);
        $this->assertSame('大转盘中奖：余额奖', $wallet[0]['remark']);

        $vipPrize = PointRepository::fetchDrawableWheelPrizes()[2];
        $result = PointRepository::finishWheelDraw(
            9, $vipPrize, false, 20, 1, 1,
            ['type' => 'vip', 'value' => 30]
        );
        $this->assertArrayHasKey('expire_at', $result['award'], 'vip 奖励追加到期时间');
        $this->assertSame(80, $result['points']);
        $this->assertSame(2, (int)$this->pdo()->query('SELECT used FROM wheel_prizes WHERE id = 3')->fetchColumn(), '限量奖品用量 +1');

        // 在写抽奖记录时 ABORT，验证扣费/发奖/库存全部回滚
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_wheel_record BEFORE INSERT ON wheel_records WHEN NEW.prize_id = 4
BEGIN SELECT RAISE(ABORT, 'forced rollback'); END;
SQL);
        $limitedPrize = PointRepository::fetchDrawableWheelPrizes()[3];
        try {
            PointRepository::finishWheelDraw(
                7, $limitedPrize, false, 20, 1, 1,
                ['type' => 'points', 'value' => 10]
            );
            $this->fail('应当抛出异常');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced rollback', $e->getMessage());
        }
        $this->assertSame(90, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn(), '积分零残留');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT used FROM wheel_prizes WHERE id = 4')->fetchColumn(), '库存零残留');
        $this->assertSame(3, count($this->rows('wheel_records')), '前三次成功抽奖保留，失败分支零残留');
        $this->assertSame(3, count($this->rows('points_logs')), '前三次成功抽奖流水保留，失败分支零残留');
    }

    // ====================================================================
    // ShopRepository：商品列表（三套字段清单刻意不合并）
    // ====================================================================

    public function testProductReadsKeepThreeDistinctShapesAndFilters(): void
    {
        $this->seed('users', ['id' => 10, 'username' => 'sellerA', 'nickname' => '卖家甲', 'avatar' => '/a.jpg']);
        foreach ([
            [1, 10, 'info', '周边好物A', 0, 1, 3],
            [2, 0, 'vip', '折扣会员A', 0, 1, 1],
            [3, 0, 'physical', '官方周边', 0, 1, 2],
            [4, 10, 'info', '下架好物', 0, 0, 4],
            [5, 0, 'coupon', '折扣卡A', 0, 1, 5],
        ] as [$id, $seller, $type, $title, $price, $status, $sort]) {
            $this->seed('shop_products', [
                'id' => $id, 'seller_id' => $seller, 'type' => $type, 'title' => $title,
                'price_balance' => 10, 'price_points' => 20, 'stock' => 5, 'status' => $status, 'sort' => $sort,
            ]);
        }

        foreach ([
            ['all', '', 2, 0], ['all', '', 2, 2], ['info', '', 20, 0],
            ['vip', '', 20, 0], ['all', '周边', 20, 0], ['coupon', '不存在', 20, 0],
        ] as [$type, $keyword, $limit, $offset]) {
            $this->assertSame(
                $this->originalProducts($type, $keyword, $limit, $offset),
                ShopRepository::fetchProducts($type, $keyword, $limit, $offset),
                "前台商品列表 {$type}/{$keyword} 分页 {$offset}+{$limit}"
            );
            $this->assertSame(
                (int)$this->pdo()->query($this->originalProductsCountSql($type, $keyword))->fetchColumn(),
                ShopRepository::countProducts($type, $keyword)
            );
        }
        $this->assertSame([], ShopRepository::fetchProducts('info', '不存在', 20, 0), '空结果负例');
        $this->assertSame(
            ['id', 'seller_id', 'type', 'title', 'cover', 'price_balance', 'price_points',
             'vip_level', 'vip_days', 'discount_mode', 'discount_value', 'vip_levels',
             'valid_days', 'stock', 'sales', 'sort'],
            array_keys(ShopRepository::fetchProducts('all', '', 1, 0)[0]),
            '前台 16 列字段清单/顺序不得变化'
        );

        $this->assertSame(
            $this->pdo()->query('SELECT id, nickname, avatar FROM users WHERE id IN (10, 99)')->fetchAll(PDO::FETCH_ASSOC),
            ShopRepository::fetchSellersByIds([10, 99])
        );
        $this->assertSame([], ShopRepository::fetchSellersByIds([]));

        foreach ([['', '', '', 20, 0], ['info', '1', '', 20, 0], ['', '0', '好物', 20, 0], ['vip', '', '周边', 20, 0]] as [$t, $s, $k, $lim, $off]) {
            $this->assertSame(
                $this->originalAdminProducts($t, $s, $k, $lim, $off),
                ShopRepository::fetchAdminProducts($t, $s, $k, $lim, $off),
                "后台商品列表 {$t}/{$s}/{$k}"
            );
            $this->assertSame(
                (int)$this->pdo()->query($this->originalAdminProductsCountSql($t, $s, $k))->fetchColumn(),
                ShopRepository::countAdminProducts($t, $s, $k)
            );
        }
        $admin = ShopRepository::fetchAdminProducts('', '', '', 1, 0)[0];
        $public = ShopRepository::fetchProducts('all', '', 1, 0)[0];
        $this->assertArrayHasKey('seller_name', $admin, '后台列表额外带 seller_name');
        $this->assertArrayNotHasKey('seller_name', $public, '前台列表不得混入后台字段');
        $this->assertArrayHasKey('images', $admin, '后台 SELECT p.* 含 images/content');
        $this->assertArrayNotHasKey('images', $public, '前台 16 列不含 images');

        $this->assertSame(
            $this->pdo()->query('SELECT id, title, cover, images, content, price_balance, status, created_at FROM shop_products WHERE seller_id = 10 ORDER BY id DESC LIMIT 50')
                ->fetchAll(PDO::FETCH_ASSOC),
            ShopRepository::fetchMyProducts(10)
        );
        $this->assertSame(['id', 'seller_id', 'type'], array_keys(ShopRepository::findOwnInfoProduct(1)));
        $this->assertSame(['id', 'seller_id'], array_keys(ShopRepository::findPlatformProductById(1)));
        $this->assertSame(['id', 'nickname', 'avatar'], array_keys(ShopRepository::findSellerById(10)));
        $this->assertNull(ShopRepository::findProductById(999));
    }

    // ====================================================================
    // ShopRepository：订单 / 券 / 发货
    // ====================================================================

    public function testOrderCouponAndShipReadsMatchOriginalSql(): void
    {
        $this->seed('users', [
            'id' => 7, 'username' => 'buyer', 'nickname' => '买家', 'points' => 100, 'balance' => 100,
        ]);
        $this->seed('shop_products', [
            'id' => 1, 'seller_id' => 0, 'type' => 'coupon', 'title' => '折扣卡',
            'discount_mode' => 'percent', 'discount_value' => 20, 'vip_levels' => '1,2,3',
            'valid_days' => 30, 'status' => 1,
        ]);
        $this->seed('shop_products', [
            'id' => 2, 'seller_id' => 0, 'type' => 'physical', 'title' => '周边', 'status' => 1,
        ]);
        foreach ([
            [1, 'SP001', 1, 'paid', 'pending'],
            [2, 'SP002', 2, 'paid', 'shipped'],
            [3, 'SP003', 1, 'refunded', 'none'],
        ] as [$id, $no, $pid, $status, $ship]) {
            $this->seed('shop_orders', [
                'id' => $id, 'order_no' => $no, 'user_id' => 7, 'product_id' => $pid,
                'qty' => 1, 'pay_currency' => 'balance', 'amount' => 10, 'points' => 0,
                'status' => $status, 'ship_status' => $ship, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->seed('user_coupons', [
            'id' => 1, 'user_id' => 7, 'order_id' => 1, 'slot' => 0, 'title' => '折扣卡',
            'discount_mode' => 'percent', 'discount_value' => 20, 'vip_levels' => '1,2,3',
            'status' => 'unused', 'expire_at' => '2099-01-01 00:00:00',
        ]);
        $this->seed('user_coupons', [
            'id' => 2, 'user_id' => 7, 'order_id' => 2, 'slot' => 0, 'title' => '过期券',
            'status' => 'expired', 'expire_at' => '2020-01-01 00:00:00',
        ]);
        $this->seed('user_coupons', [
            'id' => 3, 'user_id' => 7, 'order_id' => 3, 'slot' => 0, 'title' => '未来券',
            'status' => 'unused', 'expire_at' => '2099-01-01 00:00:00',
        ]);
        $this->seed('user_coupons', [
            'id' => 4, 'user_id' => 7, 'order_id' => 1, 'slot' => 1, 'title' => '待过期券',
            'status' => 'unused', 'expire_at' => '2020-01-01 00:00:00',
        ]);

        foreach ([1, 2, 20] as $limit) {
            $this->assertSame($this->originalUserOrders(7, $limit), ShopRepository::fetchUserOrders(7, $limit));
        }
        $this->assertSame([], ShopRepository::fetchUserOrders(999, 20), '空结果负例');
        $userOrders = ShopRepository::fetchUserOrders(7, 20);
        $withCoupon = null;
        foreach ($userOrders as $row) {
            if ((int)$row['id'] === 1) {
                $withCoupon = $row;
                break;
            }
        }
        $this->assertNotNull($withCoupon);
        $this->assertSame('unused', $withCoupon['coupon_status'], '优惠券标量子查询字段必须存在');
        $this->assertSame(2, (int)$withCoupon['coupon_count']);

        foreach ([['', '', 20, 0], ['paid', '', 20, 0], ['', 'pending', 20, 0], ['refunded', 'none', 1, 1]] as [$s, $ship, $lim, $off]) {
            $this->assertSame(
                $this->originalAdminOrders($s, $ship, $lim, $off),
                ShopRepository::fetchAdminOrders($s, $ship, $lim, $off)
            );
            $this->assertSame(
                (int)$this->pdo()->query($this->originalAdminOrdersCountSql($s, $ship))->fetchColumn(),
                ShopRepository::countAdminOrders($s, $ship)
            );
        }
        $adminOrder = ShopRepository::fetchAdminOrders('', '', 1, 0)[0];
        $this->assertSame('buyer', $adminOrder['buyer_username'], '后台订单买家字段不得丢失（批次二教训）');
        $this->assertArrayHasKey('product_title', $adminOrder);
        $this->assertArrayNotHasKey('coupon_status', $adminOrder, '后台订单与用户订单字段清单必须不同');

        foreach ([['', '', 20, 0], ['unused', '', 20, 0], ['', '折扣', 20, 0], ['expired', '不存在', 20, 0]] as [$s, $k, $lim, $off]) {
            $this->assertSame(
                $this->originalAdminCoupons($s, $k, $lim, $off),
                ShopRepository::fetchAdminCoupons($s, $k, $lim, $off)
            );
            $this->assertSame(
                (int)$this->pdo()->query($this->originalAdminCouponsCountSql($s, $k))->fetchColumn(),
                ShopRepository::countAdminCoupons($s, $k)
            );
        }
        // 注意：adminShip 的 HEAD 原文 `SELECT id, type FROM shop_orders o LEFT JOIN shop_products p ...`
        // 在两表都有 id 的 schema 下列名歧义（SQLite/MySQL 均会报错）。按行为等价原则
        // 仓储逐字保留该 SQL，不在这里擅自补限定名；该既有风险在交付说明中单独提示。
        ShopRepository::expireStaleCoupons(7);
        $this->assertSame('expired', $this->pdo()->query('SELECT status FROM user_coupons WHERE id = 4')->fetchColumn(), '已过期未使用券应刷为 expired');
        $this->assertSame('unused', $this->pdo()->query('SELECT status FROM user_coupons WHERE id = 1')->fetchColumn(), '未过期券不得被刷');
        $this->assertSame('unused', $this->pdo()->query('SELECT status FROM user_coupons WHERE id = 3')->fetchColumn(), '未过期券不得被刷');
    }

    // ====================================================================
    // ShopRepository：下单事务（成功 / 回滚 / 幂等键）
    // ====================================================================

    public function testPurchaseCommitsWithLedgerStockAndCoupons(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'buyer', 'balance' => 100, 'points' => 100]);
        $this->seed('shop_products', [
            'id' => 1, 'type' => 'physical', 'title' => '周边', 'price_balance' => 30,
            'price_points' => 20, 'stock' => 2, 'sales' => 0, 'status' => 1,
        ]);

        $result = ShopRepository::purchase(7, ShopRepository::findProductById(1), 1, 2, 'balance', 30.0, 'SP-A', '张三', '13800000000', '地址', static fn(): array => ['ok' => true, 'expire' => '']);
        $this->assertSame('', $result['expire']);
        $this->assertSame([], $result['coupon_ids']);
        // 单价 30 × qty 2 = 60：余额按总额扣，不得只扣一次单价
        $this->assertSame(40.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 7')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query('SELECT stock FROM shop_products WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int)$this->pdo()->query('SELECT sales FROM shop_products WHERE id = 1')->fetchColumn());
        $order = $this->pdo()->query('SELECT * FROM shop_orders WHERE order_no = \'SP-A\'')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('pending', $order['ship_status']);
        $this->assertSame('张三', $order['receiver_name']);
        $this->assertEquals(60.0, $order['amount'], '订单金额必须是单价×数量（SQLite REAL / MySQL DECIMAL 均可）');
        $this->assertSame(2, (int)$order['qty']);
        $ledger = $this->rows('wallet_logs');
        $this->assertCount(1, $ledger);
        $this->assertSame('shop:SP-A', $ledger[0]['biz_key'], 'V92 幂等键必须写入');
        $this->assertSame(-60.0, (float)$ledger[0]['amount'], '流水金额按总额记');
        $this->assertSame(40.0, (float)$ledger[0]['balance_after'], '流水余额取事务内重读值');
    }

    public function testPurchaseCouponIssuesOneCouponPerQtyWithOrderIdAndSlot(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'buyer', 'balance' => 100, 'points' => 100]);
        $this->seed('shop_products', [
            'id' => 2, 'type' => 'coupon', 'title' => '折扣卡', 'price_points' => 10,
            'stock' => 3, 'discount_mode' => 'percent', 'discount_value' => 20,
            'vip_levels' => '1,2,3', 'valid_days' => 7, 'status' => 1,
        ]);
        $grantCalled = false;
        $result = ShopRepository::purchase(
            7, ShopRepository::findProductById(2), 2, 2, 'points', 10, 'SP-C', '', '', '',
            static function () use (&$grantCalled): array {
                $grantCalled = true;
                return ['ok' => true, 'expire' => ''];
            }
        );
        $this->assertFalse($grantCalled, 'coupon 类型不得触发会员发放');
        $this->assertCount(2, $result['coupon_ids']);
        // 积分单价 10 × qty 2 = 20
        $this->assertSame(80, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn(), '积分按 qty 倍率扣');
        $coupons = $this->rows('user_coupons');
        $this->assertSame([0, 1], array_map(static fn(array $c): int => (int)$c['slot'], $coupons), 'slot 唯一键逐张递增');
        $orderId = (int)$this->pdo()->query('SELECT id FROM shop_orders WHERE order_no = \'SP-C\'')->fetchColumn();
        $this->assertSame([$orderId, $orderId], array_map(static fn(array $c): int => (int)$c['order_id'], $coupons));
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn());
        $pointLog = $this->pdo()->query('SELECT points, balance_after FROM points_logs LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(-20, (int)$pointLog['points'], '积分流水必须记 -20（单价×qty）');
        $this->assertSame(80, (int)$pointLog['balance_after']);
        $order = $this->pdo()->query('SELECT points, qty FROM shop_orders WHERE order_no = \'SP-C\'')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(20, (int)$order['points'], '订单积分字段按总额写');
        $this->assertSame(2, (int)$order['qty']);
    }

    /** 核心资损护栏：qty>1 时按单价×数量扣款，漏乘 qty 必红 */
    public function testPurchaseChargesTotalNotUnitPrice(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'buyer', 'balance' => 100, 'points' => 100]);
        $this->seed('shop_products', [
            'id' => 3, 'type' => 'physical', 'title' => '周边', 'price_balance' => 30,
            'stock' => 0, 'sales' => 0, 'status' => 1, // stock=0 是不限库存的既有设计
        ]);

        ShopRepository::purchase(
            7, ShopRepository::findProductById(3), 3, 3, 'balance', 30.0, 'SP-QTY', '', '', '',
            static fn(): array => ['ok' => true, 'expire' => '']
        );

        $this->assertSame(10.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 7')->fetchColumn());
        $ledger = $this->rows('wallet_logs');
        $this->assertCount(1, $ledger);
        $this->assertSame(-90.0, (float)$ledger[0]['amount']);
        $order = $this->pdo()->query('SELECT amount, qty FROM shop_orders WHERE order_no = \'SP-QTY\'')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(90.0, $order['amount'], '订单金额必须是 30×3=90');
        $this->assertSame(3, (int)$order['qty']);
    }

    public function testPurchaseRollsBackEverythingOnWriteFailure(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'buyer', 'balance' => 100, 'points' => 100]);
        $this->seed('shop_products', [
            'id' => 1, 'type' => 'physical', 'title' => '周边', 'price_balance' => 30,
            'stock' => 2, 'status' => 1,
        ]);
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_order BEFORE INSERT ON shop_orders WHEN NEW.order_no = 'ROLLBACK'
BEGIN SELECT RAISE(ABORT, 'forced rollback'); END;
SQL);

        try {
            ShopRepository::purchase(7, ShopRepository::findProductById(1), 1, 1, 'balance', 30.0, 'ROLLBACK', '', '', '', static fn(): array => ['ok' => true, 'expire' => '']);
            $this->fail('应当抛出异常');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('forced rollback', $e->getMessage());
        }
        $this->assertSame(100.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 7')->fetchColumn(), '扣款零残留');
        $this->assertSame(2, (int)$this->pdo()->query('SELECT stock FROM shop_products WHERE id = 1')->fetchColumn(), '库存零残留');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT sales FROM shop_products WHERE id = 1')->fetchColumn(), '销量零残留');
        $this->assertSame([], $this->rows('shop_orders'));
        $this->assertSame([], $this->rows('wallet_logs'));

        try {
            ShopRepository::purchase(7, ShopRepository::findProductById(1), 1, 1, 'points', 999, 'SP-FAIL', '', '', '', static fn(): array => ['ok' => true, 'expire' => '']);
            $this->fail('积分不足应抛异常');
        } catch (\Throwable $e) {
            $this->assertSame('积分不足', $e->getMessage(), '错误文案不得变化');
        }
        $this->assertSame([], $this->rows('shop_orders'));
    }

    public function testVipGrantFailureRollsBackPurchase(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'buyer', 'balance' => 100, 'points' => 100]);
        $this->seed('shop_products', [
            'id' => 5, 'type' => 'vip', 'title' => '会员', 'price_balance' => 30,
            'vip_level' => 1, 'vip_days' => 30, 'status' => 1,
        ]);
        try {
            ShopRepository::purchase(
                7, ShopRepository::findProductById(5), 5, 1, 'balance', 30.0, 'SP-V', '', '', '',
                static fn(): array => ['ok' => false, 'reason' => '发放失败']
            );
            $this->fail('会员发放失败应抛异常');
        } catch (\Throwable $e) {
            $this->assertSame('发放失败', $e->getMessage());
        }
        $this->assertSame(100.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 7')->fetchColumn());
        $this->assertSame([], $this->rows('shop_orders'));
        $this->assertSame([], $this->rows('wallet_logs'));
    }

    // ====================================================================
    // 资损护栏：任务重复领奖 / 大转盘限量超发
    // ====================================================================

    /** 并发/重复领取护栏：第二次调用不得再加分（daily 周期） */
    public function testClaimTaskCannotDoubleAwardOnSecondCall(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'worker', 'points' => 100, 'balance' => 0.0]);
        $this->seed('point_tasks', [
            'id' => 1, 'title' => '签到任务', 'points' => 50, 'target' => 1,
            'period' => 'daily', 'status' => 1,
        ]);

        $first = PointRepository::claimTaskAndAddPoints(7, 1, 1, 50, '2026-11-20', '签到任务', 'daily');
        $this->assertSame(150, $first, '首次领取应加 50 分');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn());

        $second = PointRepository::claimTaskAndAddPoints(7, 1, 1, 50, '2026-11-20', '签到任务', 'daily');
        $this->assertSame(150, $second, '同一天重复领取余额不得变化');
        $this->assertSame(150, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn(), '重复领取不得再写流水');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM user_task_logs')->fetchColumn(), '进度行不得重复插入');

        // 换一天可再次领取（daily 语义未被误改成 once）
        $third = PointRepository::claimTaskAndAddPoints(7, 1, 1, 50, '2026-11-21', '签到任务', 'daily');
        $this->assertSame(200, $third);
        $this->assertSame(2, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn());
    }

    /** once 周期只能领一次，跨日期也不得重领 */
    public function testClaimOnceTaskCannotAwardTwiceAcrossDates(): void
    {
        $this->seed('users', ['id' => 8, 'username' => 'worker2', 'points' => 10, 'balance' => 0.0]);
        $this->seed('point_tasks', [
            'id' => 2, 'title' => '一次性任务', 'points' => 30, 'target' => 1,
            'period' => 'once', 'status' => 1,
        ]);

        $this->assertSame(40, PointRepository::claimTaskAndAddPoints(8, 2, 1, 30, '2026-11-20', '一次性任务', 'once'));
        $this->assertSame(40, PointRepository::claimTaskAndAddPoints(8, 2, 1, 30, '2026-11-22', '一次性任务', 'once'));
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn());
    }

    /** 限量奖品原子抢库存：旧快照 used=0 也不能突破 total=1 超发 */
    public function testWheelStockClaimIsAtomicAndStopsAtLimit(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'player', 'points' => 200, 'balance' => 0.0]);
        $this->seed('wheel_prizes', [
            'id' => 9, 'name' => '限量奖', 'prize_type' => 'points', 'value' => 50,
            'total' => 1, 'used' => 0, 'status' => 1,
        ]);
        $hit = [
            'id' => 9, 'prize_type' => 'points', 'value' => 50,
            'total' => 1, 'used' => 0, 'name' => '限量奖',
        ];

        PointRepository::finishWheelDraw(7, $hit, true, 0, 0, 0, ['type' => 'points', 'value' => 50]);
        $this->assertSame(1, (int)$this->pdo()->query('SELECT used FROM wheel_prizes WHERE id = 9')->fetchColumn());
        $this->assertSame(250, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn());

        // 第二次仍传旧的 used=0 快照：必须抛库存不足，扣费/发奖/记录全部不落地
        try {
            PointRepository::finishWheelDraw(7, $hit, false, 20, 0, 0, ['type' => 'points', 'value' => 50]);
            $this->fail('限量奖品售罄必须抛异常');
        } catch (\Throwable $e) {
            $this->assertSame('奖品库存不足', $e->getMessage());
        }
        $this->assertSame(1, (int)$this->pdo()->query('SELECT used FROM wheel_prizes WHERE id = 9')->fetchColumn(), '库存不得超发');
        $this->assertSame(250, (int)$this->pdo()->query('SELECT points FROM users WHERE id = 7')->fetchColumn(), '失败分支扣费必须回滚');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM wheel_records')->fetchColumn(), '失败分支不得发奖');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM points_logs')->fetchColumn(), '失败分支不得写中奖流水');
    }

    /**
     * 修复3（P2）负向测试：免费次数竞态。
     *
     * 控制器传来的 $isFree 是事务外旧快照：两次并发都看到 usedFree=0，于是都传 isFree=true。
     * 权威判定下沉仓储后，事务内按当天 free=1 记录数重判：第一次仍免费，
     * 第二次发现额度已满 → 强制转付费（照常扣 cost 积分），杜绝免费次数超发。
     */
    public function testFinishWheelDrawRechecksFreeQuotaInsideTransaction(): void
    {
        $this->seed('users', ['id' => 42, 'username' => 'racer', 'points' => 100]);
        $this->seed('wheel_prizes', [
            'id' => 60, 'name' => '谢谢参与', 'prize_type' => 'points', 'value' => 0,
            'total' => 0, 'used' => 0, 'status' => 1,
        ]);
        $hit = PointRepository::fetchDrawableWheelPrizes()[0];

        // 第一次：当天无免费记录 → 保持免费，不扣积分
        $r1 = PointRepository::finishWheelDraw(42, $hit, true, 20, 1, 0, ['type' => 'points', 'value' => 0]);
        $this->assertSame(100, $r1['points'], '第一次抽奖用免费次数，积分不变');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM wheel_records WHERE user_id = 42 AND free = 1')->fetchColumn(), '落一条免费记录');

        // 第二次：控制器仍传 isFree=true（旧快照），仓储事务内重判发现额度已满 → 强制扣费
        $r2 = PointRepository::finishWheelDraw(42, $hit, true, 20, 1, 0, ['type' => 'points', 'value' => 0]);
        $this->assertSame(80, $r2['points'], '第二次免费额度已满，必须照常扣 20 积分');
        $this->assertSame(0, $r2['free_left'], '强制转付费后 free_left 归零，不再误导前端');
        $this->assertSame(2, (int)$this->pdo()->query('SELECT COUNT(*) FROM wheel_records WHERE user_id = 42')->fetchColumn(), '两次都落记录');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM wheel_records WHERE user_id = 42 AND free = 1')->fetchColumn(), '免费记录仍只有一条，未超发');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM points_logs WHERE user_id = 42 AND type = 'spend' AND points = -20")->fetchColumn(), '只有第二次产生一条扣 20 积分流水');
    }

    // ====================================================================
    // 迁移前原始 SQL 快照（HEAD 原文，仅测试使用）
    // ====================================================================

    private function originalPointsRow(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT points FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalPointsScalar(int $userId): mixed
    {
        $stmt = $this->pdo()->prepare('SELECT points FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }

    private function originalPointsScalarLimited(int $userId): mixed
    {
        $stmt = $this->pdo()->prepare('SELECT points FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }

    private function originalTodaySignin(int $userId, string $today): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id, points FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1');
        $stmt->execute([$userId, $today]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalSignDateProbe(int $userId, string $date): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT sign_date FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1');
        $stmt->execute([$userId, $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalSignIdProbe(int $userId, string $date): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM user_signins WHERE user_id = ? AND sign_date = ? LIMIT 1');
        $stmt->execute([$userId, $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function originalLogsWhere(int $userId, string $direction, string $type): array
    {
        $where = 'user_id = ?';
        $params = [$userId];
        if ($direction === 'income') {
            $where .= ' AND points > 0';
        } elseif ($direction === 'expense') {
            $where .= ' AND points < 0';
        }
        if ($type !== '' && in_array($type, ['signin', 'task', 'spend', 'reward', 'adjust', 'refund', 'post', 'comment'], true)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        return [$where, $params];
    }

    private function originalLogs(int $userId, string $direction, string $type, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalLogsWhere($userId, $direction, $type);
        $stmt = $this->pdo()->prepare(
            "SELECT id, points, type, biz_type, biz_id, balance_after, remark, created_at
             FROM points_logs WHERE {$where}
             ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalLogsCountSql(int $userId, string $direction, string $type): string
    {
        [$where, $params] = $this->originalLogsWhere($userId, $direction, $type);
        $sql = "SELECT COUNT(*) FROM points_logs WHERE {$where}";
        foreach ($params as $i => $value) {
            $sql = preg_replace('/\?/', is_int($value) ? (string)$value : "'" . addslashes((string)$value) . "'", $sql, 1);
        }
        return $sql;
    }

    private function originalLogsSummarySql(): string
    {
        return "SELECT
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS spent,
                COUNT(*) AS cnt
             FROM points_logs WHERE user_id = ?";
    }

    /** HEAD 的 TASK_COUNTERS + str_replace 原逻辑，逐字保留 */
    private function originalTaskProgress(int $userId, string $type, string $month, ?string $date): int
    {
        $map = [
            'profile'     => "SELECT 1 FROM users u WHERE u.id = {UID} AND u.nickname <> '' AND u.avatar IS NOT NULL AND u.avatar <> ''",
            'post'        => "SELECT COUNT(*) FROM posts p WHERE p.user_id = {UID} AND p.status = 1",
            'comment'     => "SELECT COUNT(*) FROM comments c WHERE c.user_id = {UID} AND c.post_id > 0",
            'like'        => "SELECT COUNT(*) FROM likes l WHERE l.user_id = {UID}",
            'circle_join' => "SELECT COUNT(*) FROM circle_members cm WHERE cm.user_id = {UID}",
            'follow'      => "SELECT COUNT(*) FROM follows f WHERE f.follower_id = {UID}",
            'signin_days' => "SELECT COUNT(*) FROM user_signins s WHERE s.user_id = {UID} AND s.sign_month = '{TODAY_MONTH}'",
        ];
        $sql = str_replace(['{UID}', '{TODAY_MONTH}'], [(string)$userId, $month], $map[$type]);
        if ($date !== null) {
            $sql .= " AND DATE(created_at) = '{$date}'";
        }
        return (int)$this->pdo()->query($sql)->fetchColumn();
    }

    private function originalWheelRecords(int $userId, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT r.id, r.prize_id, r.prize_type, r.value, r.free, r.created_at, p.name
             FROM wheel_records r LEFT JOIN wheel_prizes p ON p.id = r.prize_id
             WHERE r.user_id = ? ORDER BY r.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function originalProductsWhere(string $type, string $keyword): array
    {
        $where = "WHERE p.status = 1";
        $params = [];
        if (in_array($type, ['vip', 'physical', 'info', 'coupon'], true)) {
            $where .= " AND p.type = ?";
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= " AND p.title LIKE ?";
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    private function originalProducts(string $type, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalProductsWhere($type, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT p.id, p.seller_id, p.type, p.title, p.cover, p.price_balance, p.price_points,
                    p.vip_level, p.vip_days, p.discount_mode, p.discount_value, p.vip_levels,
                    p.valid_days, p.stock, p.sales, p.sort
             FROM shop_products p {$where} ORDER BY p.sort ASC, p.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalProductsCountSql(string $type, string $keyword): string
    {
        [$where, $params] = $this->originalProductsWhere($type, $keyword);
        $sql = "SELECT COUNT(*) FROM shop_products p {$where}";
        foreach ($params as $value) {
            $sql = preg_replace('/\?/', "'" . addslashes((string)$value) . "'", $sql, 1);
        }
        return $sql;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function originalAdminProductsWhere(string $type, string $status, string $keyword): array
    {
        $where = '';
        $params = [];
        if (in_array($type, ['vip', 'physical', 'info', 'coupon'], true)) {
            $where .= " AND p.type = ?";
            $params[] = $type;
        }
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $where .= " AND p.status = ?";
            $params[] = (int)$status;
        }
        if ($keyword !== '') {
            $where .= " AND p.title LIKE ?";
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    private function originalAdminProducts(string $type, string $status, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalAdminProductsWhere($type, $status, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT p.*, u.nickname AS seller_name
             FROM shop_products p LEFT JOIN users u ON u.id = p.seller_id
             WHERE 1=1 {$where} ORDER BY p.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminProductsCountSql(string $type, string $status, string $keyword): string
    {
        [$where, $params] = $this->originalAdminProductsWhere($type, $status, $keyword);
        $sql = "SELECT COUNT(*) FROM shop_products p WHERE 1=1 {$where}";
        foreach ($params as $value) {
            $sql = preg_replace('/\?/', is_int($value) ? (string)$value : "'" . addslashes((string)$value) . "'", $sql, 1);
        }
        return $sql;
    }

    private function originalUserOrders(int $userId, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT o.id, o.order_no, o.product_id, o.qty, o.pay_currency, o.amount, o.points,
                    o.status, o.ship_status, o.created_at, p.title, p.cover, p.type,
                    (SELECT c.status FROM user_coupons c WHERE c.order_id = o.id ORDER BY c.id ASC LIMIT 1) AS coupon_status,
                    (SELECT c.expire_at FROM user_coupons c WHERE c.order_id = o.id ORDER BY c.id ASC LIMIT 1) AS coupon_expire,
                    (SELECT COUNT(*) FROM user_coupons c WHERE c.order_id = o.id) AS coupon_count,
                    p.discount_mode, p.discount_value, p.vip_levels, p.valid_days
             FROM shop_orders o LEFT JOIN shop_products p ON p.id = o.product_id
             WHERE o.user_id = ? ORDER BY o.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function originalAdminOrdersWhere(string $status, string $ship): array
    {
        $where = '';
        $params = [];
        if (in_array($status, ['paid', 'refunded', 'closed'], true)) {
            $where .= " AND o.status = ?";
            $params[] = $status;
        }
        if (in_array($ship, ['none', 'pending', 'shipped', 'completed'], true)) {
            $where .= " AND o.ship_status = ?";
            $params[] = $ship;
        }
        return [$where, $params];
    }

    private function originalAdminOrders(string $status, string $ship, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalAdminOrdersWhere($status, $ship);
        $stmt = $this->pdo()->prepare(
            "SELECT o.*, p.title AS product_title, p.type AS product_type,
                    u.nickname AS buyer_name, u.username AS buyer_username
             FROM shop_orders o
             LEFT JOIN shop_products p ON p.id = o.product_id
             LEFT JOIN users u ON u.id = o.user_id
             WHERE 1=1 {$where} ORDER BY o.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminOrdersCountSql(string $status, string $ship): string
    {
        [$where, $params] = $this->originalAdminOrdersWhere($status, $ship);
        $sql = "SELECT COUNT(*) FROM shop_orders o WHERE 1=1 {$where}";
        foreach ($params as $value) {
            $sql = preg_replace('/\?/', "'" . addslashes((string)$value) . "'", $sql, 1);
        }
        return $sql;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function originalAdminCouponsWhere(string $status, string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($status, ['unused', 'used', 'expired'], true)) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND (c.title LIKE ? OR u.nickname LIKE ? OR u.username LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    private function originalAdminCoupons(string $status, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalAdminCouponsWhere($status, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT c.*, u.nickname AS user_name, u.username AS user_account
             FROM user_coupons c LEFT JOIN users u ON u.id = c.user_id
             {$where} ORDER BY c.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminCouponsCountSql(string $status, string $keyword): string
    {
        [$where, $params] = $this->originalAdminCouponsWhere($status, $keyword);
        $sql = "SELECT COUNT(*) FROM user_coupons c LEFT JOIN users u ON u.id = c.user_id {$where}";
        foreach ($params as $value) {
            $sql = preg_replace('/\?/', "'" . addslashes((string)$value) . "'", $sql, 1);
        }
        return $sql;
    }
}
