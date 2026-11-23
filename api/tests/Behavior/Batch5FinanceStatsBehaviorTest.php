<?php
/**
 * 批次五 · 后台财务积分统计域迁移回归测试
 *
 * 对照法：在内存 SQLite 里同时执行「迁移前 HEAD 原始 SQL」与「下沉后的 Repository
 * 方法」，逐字段比对返回结构与写库副作用；同时记录 Repository 实际 prepare 的
 * SQL 文本，断言与 HEAD 快照逐字一致（仅值改为 ? 绑定）。
 *
 * MySQL 专有片段（DATE_SUB(NOW(), INTERVAL ...) / HAVING 无 GROUP BY）由测试专用
 * PDO 子类做等价方言翻译，原始快照与 Repository 走同一层翻译，因此比较仍然严格。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AdminRepository;
use Hublog\Core\Database;
use Hublog\Core\FinanceRepository;
use Hublog\Core\PointRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 测试库连接：翻译 MySQL 方言并记录 prepare() 收到的 SQL。
 *
 * 记录 SQL 是为了直接证明「字段/别名/JOIN/WHERE/ORDER BY/LIMIT 逐字不变」，
 * 而不只是结果集碰巧相同。
 */
final class Batch5DialectPdo extends PDO
{
    /** @var list<string> */
    public array $sqlLog = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // 生产源码与测试夹具的工作区行尾可能分别为 CRLF/LF；SQL 语义与逐字比对
        // 都不该被行尾差异干扰，统一按 LF 记录。
        $translated = str_replace("\r\n", "\n", self::toSqlite($query));
        $this->sqlLog[] = $translated;
        return parent::prepare($translated, $options);
    }

    public static function toSqlite(string $sql): string
    {
        $sql = str_replace(
            [
                'DATE_SUB(NOW(), INTERVAL 1 DAY)',
                'DATE_SUB(NOW(), INTERVAL 7 DAY)',
                'DATE_SUB(NOW(), INTERVAL 1 MONTH)',
            ],
            [
                "datetime('now','localtime','-1 day')",
                "datetime('now','localtime','-7 day')",
                "datetime('now','localtime','-1 month')",
            ],
            $sql
        );
        // SQLite 要求 HAVING 前必须有 GROUP BY；users.id 为主键，逐用户过滤语义不变
        $sql = str_replace('HAVING score > 0', 'GROUP BY u.id HAVING score > 0', $sql);
        return str_replace('NOW()', "datetime('now','localtime')", $sql);
    }
}

final class Batch5FinanceStatsBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new Batch5DialectPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // 复用 BehaviorTestCase 的最小 schema，再把连接换成带方言翻译的子类
        $createSchema = new ReflectionMethod(BehaviorTestCase::class, 'createSchema');
        $createSchema->setAccessible(true);
        $createSchema->invoke($this, $pdo);

        $pdoProperty = new ReflectionProperty(BehaviorTestCase::class, 'pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($this, $pdo);

        $dbProperty = new ReflectionProperty(Database::class, 'instance');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue(null, $pdo);

        $pdo->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('sms_provider', 'none')");
        $pdo->exec(<<<'SQL'
ALTER TABLE `users` ADD COLUMN `password` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `deleted_at` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `realname_verified` INTEGER NOT NULL DEFAULT 0;
CREATE TABLE `wallet_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL,
    `amount` REAL NOT NULL, `type` TEXT, `biz_type` TEXT, `biz_id` INTEGER,
    `balance_after` REAL, `remark` TEXT, `created_at` TEXT
);
CREATE TABLE `vip_subscriptions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL,
    `level` INTEGER, `days` INTEGER, `amount` REAL, `order_no` TEXT UNIQUE,
    `status` INTEGER, `started_at` TEXT, `expire_at` TEXT, `created_at` TEXT, `remark` TEXT
);
CREATE TABLE `points_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL,
    `points` INTEGER, `type` TEXT, `biz_type` TEXT, `biz_id` INTEGER,
    `balance_after` INTEGER, `remark` TEXT, `created_at` TEXT
);
CREATE TABLE `point_tasks` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `title` TEXT, `desc` TEXT,
    `points` INTEGER, `task_type` TEXT, `target` INTEGER, `period` TEXT,
    `status` INTEGER, `sort` INTEGER
);
CREATE TABLE `user_task_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `task_id` INTEGER,
    `times` INTEGER, `claimed` INTEGER, `last_date` TEXT
);
CREATE TABLE `featured_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `slot` TEXT,
    `sort_order` INTEGER, `remark` TEXT, `start_at` TEXT, `end_at` TEXT
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `post_type` TEXT DEFAULT 'normal',
    `content` TEXT, `images` TEXT, `circle_id` INTEGER, `category_id` INTEGER,
    `visibility` TEXT DEFAULT 'public',
    `status` INTEGER DEFAULT 1, `audit_state` INTEGER DEFAULT 0,
    `is_pinned` INTEGER DEFAULT 0, `pinned_at` TEXT,
    `comment_count` INTEGER DEFAULT 0, `like_count` INTEGER DEFAULT 0,
    `view_count` INTEGER DEFAULT 0, `created_at` TEXT, `updated_at` TEXT
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `user_id` INTEGER,
    `content` TEXT, `created_at` TEXT,
    `status` INTEGER DEFAULT 1, `audit_state` INTEGER DEFAULT 0
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `user_id` INTEGER, `created_at` TEXT
);
CREATE TABLE `favorites` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `user_id` INTEGER
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `follower_id` INTEGER, `following_id` INTEGER
);
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `slug` TEXT, `cover` TEXT,
    `description` TEXT, `member_count` INTEGER DEFAULT 0, `status` INTEGER DEFAULT 1,
    `created_at` TEXT
);
CREATE TABLE `topics` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `name` TEXT, `slug` TEXT, `description` TEXT,
    `status` INTEGER DEFAULT 1, `post_count` INTEGER DEFAULT 0
);
CREATE TABLE `post_tags` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `tag` TEXT
);
SQL);
    }

    // ===== FinanceRepository：钱包流水 =====

    public function testWalletLogsMatchHeadSqlAcrossFiltersAndPages(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝',
            'vip_level' => 2, 'balance' => 100, 'status' => 1, 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seed('users', [
            'id' => 2, 'username' => 'bob', 'nickname' => '鲍勃',
            'vip_level' => 0, 'balance' => 30, 'status' => 1, 'created_at' => '2026-01-02 00:00:00',
        ]);
        foreach ([
            [1, 10.5, 'recharge', 'admin', 0, '后台充值'],
            [2, -3.0, 'adjust', 'admin', 0, '处罚'],
            [1, 2.25, 'reward', 'wheel', 7, '抽奖奖励'],
            [1, -1.0, 'adjust', 'admin', 0, '补偿扣款'],
        ] as $i => [$uid, $amount, $type, $bizType, $bizId, $remark]) {
            $this->seed('wallet_logs', [
                'id' => $i + 1, 'user_id' => $uid, 'amount' => $amount, 'type' => $type,
                'biz_type' => $bizType, 'biz_id' => $bizId, 'balance_after' => 100 + $amount,
                'remark' => $remark, 'created_at' => '2026-02-0' . ($i + 1) . ' 00:00:00',
            ]);
        }

        foreach ([['', '', 0, 20], ['recharge', '', 0, 20], ['', '爱丽丝', 0, 20],
                  ['adjust', 'bob', 0, 1], ['', '', 1, 2], ['', '不存在', 0, 20]] as $case) {
            [$type, $keyword, $offset, $limit] = $case;
            $this->pdo()->sqlLog = [];
            $expected = $this->originalWalletLogs($type, $keyword, $offset, $limit);
            $expectedSql = $this->pdo()->sqlLog;

            $this->pdo()->sqlLog = [];
            $actual = FinanceRepository::fetchWalletLogs($type, $keyword, $offset, $limit);
            $actualSql = $this->pdo()->sqlLog;

            $this->assertSame($expected, $actual, '钱包流水列表字段/顺序/排序/分页与 HEAD 不一致');
            $this->assertSame($expectedSql, $actualSql, '钱包流水 SQL 文本必须与 HEAD 逐字一致');
            $this->assertSame(
                $this->originalCountWalletLogs($type, $keyword),
                FinanceRepository::countWalletLogs($type, $keyword),
                '计数与列表必须共用同一套 WHERE'
            );
        }

        $rows = FinanceRepository::fetchWalletLogs('', '', 0, 20);
        $this->assertSame([4, 3, 2, 1], array_column($rows, 'id'), 'ORDER BY wl.id DESC');
        $this->assertSame(
            ['id', 'user_id', 'amount', 'type', 'biz_type', 'biz_id', 'balance_after',
             'remark', 'created_at', 'username', 'nickname', 'vip_level'],
            array_keys($rows[0]),
            'LEFT JOIN 字段顺序不得变化'
        );
        $this->assertSame([], FinanceRepository::fetchWalletLogs('', '不存在', 0, 20), '负例：无匹配');
    }

    public function testWalletAdjustMatchesOriginalCommitAndRollback(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'balance' => 100, 'status' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        $expectedAfter = $this->originalWalletAdjust(1, 30.0, '后台充值');
        $expectedLogs = $this->rowsWithoutId('wallet_logs');

        $this->pdo()->exec('DELETE FROM wallet_logs');
        $this->pdo()->exec('UPDATE users SET balance = 100 WHERE id = 1');
        $actualAfter = FinanceRepository::adjustWalletBalance(1, 30.0, '后台充值');

        $this->assertSame($expectedAfter, $actualAfter, '调整后余额口径不一致');
        $this->assertSame($expectedLogs, $this->rowsWithoutId('wallet_logs'), '钱包流水字段与 HEAD 不一致');
        $this->assertSame(130.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 1')->fetchColumn());

        // 预检查通过但事务内余额不足：条件更新 rowCount=0 → 抛原文案 → 整单回滚
        $before = $this->rowsWithoutId('wallet_logs');
        $this->pdo()->exec('UPDATE users SET balance = 20 WHERE id = 1');
        try {
            FinanceRepository::adjustWalletBalance(1, -50.0, '扣款');
            $this->fail('余额不足应抛出 RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('扣款后余额不能为负', $e->getMessage(), '错误文案不得改变');
        }
        $this->assertFalse($this->pdo()->inTransaction(), '异常路径必须已回滚');
        $this->assertSame($before, $this->rowsWithoutId('wallet_logs'), '回滚后不得残留流水');
        $this->assertSame(20.0, (float)$this->pdo()->query('SELECT balance FROM users WHERE id = 1')->fetchColumn());

        // 用户不存在分支
        try {
            FinanceRepository::adjustWalletBalance(999, 10.0, 'x');
            $this->fail('不存在用户应抛出 RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('用户不存在', $e->getMessage());
        }
        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertSame($before, $this->rowsWithoutId('wallet_logs'));
        $this->assertSame(
            ['id', 'username', 'balance'],
            array_keys(FinanceRepository::findWalletAdjustUser(1) ?? [])
        );
    }

    public function testVipOrdersAndGrantVipMatchHead(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'status' => 1,
            'vip_level' => 1, 'vip_expire_at' => '2020-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([1, 2, 3] as $i) {
            $this->seed('vip_subscriptions', [
                'id' => $i, 'user_id' => 1, 'level' => $i, 'days' => 30 * $i,
                'amount' => $i * 10, 'order_no' => "ADM{$i}", 'status' => 1,
                'started_at' => '2026-01-01 00:00:00',
                'expire_at' => '2026-02-0' . $i . ' 00:00:00',
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        foreach ([[0, 20], [1, 1], [0, 2]] as [$offset, $limit]) {
            $this->pdo()->sqlLog = [];
            $expected = $this->originalVipOrders($offset, $limit);
            $expectedSql = $this->pdo()->sqlLog;
            $this->pdo()->sqlLog = [];
            $actual = FinanceRepository::fetchVipOrders($offset, $limit);
            $this->assertSame($expected, $actual);
            $this->assertSame($expectedSql, $this->pdo()->sqlLog, '订购单 SQL 必须与 HEAD 逐字一致');
        }
        $this->assertSame(3, FinanceRepository::countVipOrders());
        $this->assertSame([3, 2, 1], array_column(FinanceRepository::fetchVipOrders(0, 20), 'id'));

        // 人工开通：与 HEAD 事务序列对比最终行
        $expireAt = '2026-12-31 00:00:00';
        $startedAt = '2026-01-05 00:00:00';
        $this->originalGrantVip(1, 2, 60, 'ADM-ORIGINAL', $startedAt, $expireAt);
        $expectedUser = $this->rows('users');
        $expectedVip = $this->rowsWithoutId('vip_subscriptions');

        $this->pdo()->exec('DELETE FROM vip_subscriptions WHERE order_no = \'ADM-ORIGINAL\'');
        $this->pdo()->exec("UPDATE users SET vip_level = 1, vip_expire_at = '2020-01-01 00:00:00' WHERE id = 1");
        FinanceRepository::grantVip(1, 2, 60, 'ADM-ORIGINAL', $startedAt, $expireAt);
        $this->assertSame($expectedUser, $this->rows('users'));
        $this->assertSame($expectedVip, $this->rowsWithoutId('vip_subscriptions'));
        $this->assertSame(
            ['id', 'vip_level', 'vip_expire_at'],
            array_keys(FinanceRepository::findVipGrantUser(1) ?? [])
        );

        // 事务回滚：order_no 唯一约束冲突时，用户行不得被更新
        $beforeUser = $this->rows('users');
        $beforeVip = $this->rows('vip_subscriptions');
        try {
            FinanceRepository::grantVip(1, 3, 90, 'ADM1', $startedAt, $expireAt);
            $this->fail('重复单号应触发异常');
        } catch (\Throwable $e) {
            $this->assertFalse($this->pdo()->inTransaction(), '异常路径必须已回滚');
        }
        $this->assertSame($beforeUser, $this->rows('users'));
        $this->assertSame($beforeVip, $this->rows('vip_subscriptions'));
    }

    // ===== PointRepository 新增方法 =====

    public function testAdminPointsLogsAndTasksMatchHead(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'points' => 50,
            'status' => 1, 'created_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([
            [1, 10, 'task', '发放'], [1, -5, 'spend', '消费'],
            [1, 2, 'adjust', '后台补偿'], [1, 3, 'reward', '奖励'],
        ] as $i => [$uid, $points, $type, $remark]) {
            $this->seed('points_logs', [
                'id' => $i + 1, 'user_id' => $uid, 'points' => $points, 'type' => $type,
                'biz_type' => 'admin', 'biz_id' => 0, 'balance_after' => 50 + $points,
                'remark' => $remark, 'created_at' => '2026-02-0' . ($i + 1) . ' 00:00:00',
            ]);
        }
        foreach ([1, 2, 3] as $i) {
            $this->seed('point_tasks', [
                'id' => $i, 'title' => "任务{$i}", 'desc' => "说明{$i}", 'points' => $i * 10,
                'task_type' => 'post', 'target' => $i, 'period' => 'daily',
                'status' => $i === 2 ? 0 : 1, 'sort' => 4 - $i,
            ]);
        }

        foreach ([['', '', 0, 20], ['task', '', 0, 20], ['', '爱丽丝', 0, 20],
                  ['adjust', 'alice', 1, 1], ['', '', 1, 2]] as [$type, $keyword, $offset, $limit]) {
            $this->pdo()->sqlLog = [];
            $expected = $this->originalAdminLogs($type, $keyword, $offset, $limit);
            $expectedSql = $this->pdo()->sqlLog;
            $this->pdo()->sqlLog = [];
            $actual = PointRepository::fetchAdminLogs($type, $keyword, $offset, $limit);
            $this->assertSame($expected, $actual, '后台积分流水与 HEAD 不一致');
            $this->assertSame($expectedSql, $this->pdo()->sqlLog, '流水 SQL 必须与 HEAD 逐字一致');
            $this->assertSame(
                $this->originalCountAdminLogs($type, $keyword),
                PointRepository::countAdminLogs($type, $keyword)
            );
        }

        $this->pdo()->sqlLog = [];
        $expectedTasks = $this->originalAdminTaskList();
        $expectedTaskSql = $this->pdo()->sqlLog;
        $this->pdo()->sqlLog = [];
        $actualTasks = PointRepository::fetchAllAdminTasks();
        $this->assertSame($expectedTasks, $actualTasks);
        $this->assertSame($expectedTaskSql, $this->pdo()->sqlLog, '任务列表 SQL 必须与 HEAD 逐字一致');
        $this->assertSame([3, 2, 1], array_column($actualTasks, 'id'), 'ORDER BY sort ASC, id ASC');
        $this->assertCount(3, $actualTasks, '后台任务列表含停用任务');
        $this->assertCount(2, PointRepository::fetchActiveTasks(), '既有 fetchActiveTasks 口径不得被改写');
        $this->assertSame(['id', 'points'], array_keys(PointRepository::findAdminAdjustUser(1) ?? []));
        $this->assertSame(['id'], array_keys(PointRepository::findTaskIdRow(2) ?? []));
        $this->assertNull(PointRepository::findTaskIdRow(999), '任务不存在负例');
    }

    public function testAdminPointTaskWritesKeepHeadSqlAndFieldOrder(): void
    {
        $this->pdo()->sqlLog = [];
        $newId = PointRepository::insertAdminTask('新任务', '说明', 88, 'like', 5, 'once', 1, 7);
        $insertSql = $this->pdo()->sqlLog[0] ?? '';
        $this->assertSame(
            'INSERT INTO `point_tasks` (`title`, `desc`, `points`, `task_type`, `target`, `period`, `status`, `sort`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            $insertSql,
            '新建字段顺序必须是 status → sort'
        );
        $this->assertSame([
            'title' => '新任务', 'desc' => '说明', 'points' => 88, 'task_type' => 'like',
            'target' => 5, 'period' => 'once', 'status' => 1, 'sort' => 7,
        ], $this->taskRow($newId), '新建任务逐字段核对');

        $this->pdo()->sqlLog = [];
        PointRepository::updateAdminTask($newId, '改标题', '改说明', 99, 'comment', 9, 'daily', 3, 0);
        $updateSql = $this->pdo()->sqlLog[0] ?? '';
        $this->assertSame(
            'UPDATE `point_tasks` SET `title` = ?, `desc` = ?, `points` = ?, `task_type` = ?, `target` = ?, `period` = ?, `sort` = ?, `status` = ? WHERE id = ?',
            $updateSql,
            '修改字段顺序必须是 sort → status，与新建不同，不得合并'
        );
        $this->assertSame([
            'title' => '改标题', 'desc' => '改说明', 'points' => 99, 'task_type' => 'comment',
            'target' => 9, 'period' => 'daily', 'status' => 0, 'sort' => 3,
        ], $this->taskRow($newId));

        $this->seed('user_task_logs', ['user_id' => 10, 'task_id' => $newId, 'times' => 2, 'claimed' => 1, 'last_date' => '2026-01-01']);
        $this->pdo()->sqlLog = [];
        PointRepository::deleteAdminTask($newId);
        $this->assertSame([
            'DELETE FROM `user_task_logs` WHERE task_id = ?',
            'DELETE FROM `point_tasks` WHERE id = ?',
        ], $this->pdo()->sqlLog, '删除顺序与 HEAD 一致（先日志后任务，无事务）');
        $this->assertSame([], $this->rows('point_tasks'));
        $this->assertSame([], $this->rows('user_task_logs'));
        PointRepository::deleteAdminTask(-1);
        $this->assertSame(0, $this->pdo()->query('SELECT COUNT(*) FROM point_tasks')->fetchColumn());
    }

    public function testFeaturedPostsMatchHeadSqlWithTimeWindow(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'avatar' => '/a.png',
            'status' => 1, 'created_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([1, 2, 3, 4] as $i) {
            $this->seed('posts', [
                'id' => $i, 'user_id' => 1, 'post_type' => $i === 3 ? 'circle' : 'normal',
                'content' => "内容{$i}", 'images' => '', 'status' => $i === 4 ? 0 : 1,
                'like_count' => $i, 'comment_count' => $i, 'created_at' => '2026-02-01 00:00:00',
            ]);
        }
        foreach ([1, 2, 3, 4] as $i) {
            $this->seed('featured_posts', [
                'id' => $i, 'post_id' => $i, 'slot' => $i === 4 ? 'circle' : 'home',
                'sort_order' => 5 - $i, 'remark' => "位{$i}",
                'start_at' => $i === 3 ? '2099-01-01 00:00:00' : null,
                'end_at' => $i === 2 ? '2020-01-01 00:00:00' : null,
            ]);
        }

        foreach ([['home', 10], ['home', 2], ['circle', 10], ['missing', 10]] as [$slot, $limit]) {
            $this->pdo()->sqlLog = [];
            $expected = $this->originalFeaturedPosts($slot, $limit);
            $expectedSql = $this->pdo()->sqlLog;
            $this->pdo()->sqlLog = [];
            $actual = PointRepository::fetchFeaturedPosts($slot, $limit);
            $this->assertSame($expected, $actual, '精选位与 HEAD 不一致');
            $this->assertSame($expectedSql, $this->pdo()->sqlLog, '精选位 SQL 必须与 HEAD 逐字一致');
        }
        $rows = PointRepository::fetchFeaturedPosts('home', 10);
        $this->assertSame([1], array_column($rows, 'id'), '时间窗 + status 过滤 + 排序');
        $this->assertArrayHasKey('post_type', $rows[0], 'posts 行查询必须带 p.post_type');
    }

    // ===== AdminRepository：统计与榜单 =====

    public function testSiteStatsReusesExactAggregateSql(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->seed('posts', [
                'id' => $i, 'user_id' => 1, 'post_type' => 'normal', 'status' => $i === 3 ? 0 : 1,
                'created_at' => '2026-01-01 00:00:00',
            ]);
            $this->seed('comments', ['id' => $i, 'post_id' => $i, 'user_id' => 1, 'content' => 'c']);
            $this->seed('likes', ['id' => $i, 'post_id' => $i, 'user_id' => 1]);
            $this->seed('users', [
                'id' => 10 + $i, 'username' => "u{$i}", 'status' => $i === 3 ? 0 : 1,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->assertSame(
            (int)$this->pdo()->query('SELECT COUNT(*) FROM posts WHERE status = 1')->fetchColumn(),
            AdminRepository::countActivePosts()
        );
        $this->assertSame(
            (int)$this->pdo()->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
            AdminRepository::countAllComments()
        );
        $this->assertSame(
            (int)$this->pdo()->query('SELECT COUNT(*) FROM likes')->fetchColumn(),
            AdminRepository::countAllLikes()
        );
        $this->assertSame(
            (int)$this->pdo()->query('SELECT COUNT(*) FROM users WHERE status = 1')->fetchColumn(),
            AdminRepository::countActiveUsers()
        );
        $this->assertSame(2, AdminRepository::countActivePosts());
        $this->assertSame(3, AdminRepository::countAllComments());
        $this->assertSame(3, AdminRepository::countAllLikes());
        $this->assertSame(2, AdminRepository::countActiveUsers());
    }

    #[DataProvider('periodProvider')]
    public function testRankingQueriesMatchHeadSqlForEveryPeriod(string $period): void
    {
        $this->seedRankingData();

        $cases = [
            'userRanking' => [
                fn() => AdminRepository::fetchUserRanking(2),
                fn() => $this->originalUserRanking(2),
            ],
            'users' => [
                fn() => AdminRepository::fetchRankingUsers($period, 2),
                fn() => $this->originalRankingUsers($period, 2),
            ],
            'posts' => [
                fn() => AdminRepository::fetchRankingPosts($period, 2),
                fn() => $this->originalRankingPosts($period, 2),
            ],
            'circles' => [
                fn() => AdminRepository::fetchRankingCircles($period, 2),
                fn() => $this->originalRankingCircles($period, 2),
            ],
            'topics' => [
                fn() => AdminRepository::fetchRankingTopics($period, 2),
                fn() => $this->originalRankingTopics($period, 2),
            ],
        ];

        $sqls = [];
        foreach ($cases as $name => [$repoCall, $originalCall]) {
            $this->pdo()->sqlLog = [];
            $expected = $originalCall();
            $expectedSql = $this->pdo()->sqlLog;

            $this->pdo()->sqlLog = [];
            $actual = $repoCall();
            $actualSql = $this->pdo()->sqlLog;

            $this->assertSame($expected, $actual, "{$name}/{$period} 榜单结果必须与 HEAD 一致");
            $this->assertSame($expectedSql, $actualSql, "{$name}/{$period} SQL 必须与 HEAD 逐字一致");
            $sqls[$name] = $actualSql[0] ?? '';
        }

        $this->assertNotSame($sqls['userRanking'], $sqls['users'], 'userRanking 与 rankingUsers 口径不同，不得互相复用');
        $this->assertStringContainsString('post_type', $sqls['posts'], 'posts 行查询必须带 p.post_type');
        if ($period !== 'all') {
            foreach (['users', 'posts', 'circles', 'topics'] as $name) {
                $this->assertStringContainsString('created_at >=', $sqls[$name], "{$name} 时间窗不得丢失");
            }
        }
        $this->assertSame([], AdminRepository::fetchRankingPosts('day', 0), 'limit=0 负例');
    }

    /** @return array<string,array{string}> */
    public static function periodProvider(): array
    {
        return [
            'all' => ['all'],
            'day' => ['day'],
            'week' => ['week'],
            'month' => ['month'],
        ];
    }

    // ===== AdminRepository：批量操作 =====

    #[DataProvider('postActionProvider')]
    public function testBatchPostActionsMatchOriginalSql(string $action): void
    {
        $this->seedBatchPosts();
        $ids = [1, 2, 3];
        $now = '2026-03-01 10:00:00';
        $this->originalBatchPost($action, $ids, $now);
        $expected = $this->rows('posts');

        $this->resetBatchPosts();
        $this->seedBatchPosts();
        $this->pdo()->sqlLog = [];
        match ($action) {
            'delete' => AdminRepository::deletePostsByIds($ids),
            'hide' => AdminRepository::hidePostsByIds($ids, $now),
            'show' => AdminRepository::showPostsByIds($ids, $now),
            'pin' => AdminRepository::pinPostsByIds($ids, $now),
            'unpin' => AdminRepository::unpinPostsByIds($ids),
            default => $this->fail('未知 action'),
        };
        $actualSql = $this->pdo()->sqlLog;

        $this->assertSame($expected, $this->rows('posts'), "批量 {$action} 写库结果与 HEAD 不一致");
        $this->assertCount(1, $actualSql, '每个批量动作只执行一条 SQL');
        $this->assertStringNotContainsString('2026-03-01', $actualSql[0], '时间值不得拼进 SQL');
        $this->assertStringNotContainsString('IN (1,2,3)', $actualSql[0], 'ID 列表不得拼进 SQL');
        $extraParams = match ($action) {
            'delete' => 0,
            'unpin' => 1,
            default => 2,
        };
        $this->assertSame(3, substr_count($actualSql[0], '?') - $extraParams, 'IN 占位符数量应等于 ID 数');
    }

    /** @return array<string,array{string}> */
    public static function postActionProvider(): array
    {
        return [
            'delete' => ['delete'],
            'hide' => ['hide'],
            'show' => ['show'],
            'pin' => ['pin'],
            'unpin' => ['unpin'],
        ];
    }

    public function testBatchCommentsAndUsersMatchOriginalSql(): void
    {
        $this->seedBatchUsers();
        foreach ([1, 2, 3] as $i) {
            $this->seed('comments', ['id' => $i, 'post_id' => 1, 'user_id' => 2, 'content' => "c{$i}"]);
        }

        $this->pdo()->exec('DELETE FROM comments WHERE id IN (1,2)');
        $expectedComments = $this->rows('comments');
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'content' => 'c1']);
        $this->seed('comments', ['id' => 2, 'post_id' => 1, 'user_id' => 2, 'content' => 'c2']);
        $this->pdo()->sqlLog = [];
        AdminRepository::deleteCommentsByIds([1, 2]);
        $this->assertSame($expectedComments, $this->rows('comments'));
        $this->assertSame('DELETE FROM comments WHERE id IN (?,?)', $this->pdo()->sqlLog[0] ?? '');

        foreach (['ban', 'unban'] as $action) {
            $this->resetBatchUsers();
            $this->seedBatchUsers();
            $this->pdo()->exec($action === 'ban'
                ? 'UPDATE users SET status = 0 WHERE id IN (1,2,3) AND id != 1'
                : 'UPDATE users SET status = 1 WHERE id IN (1,2,3)');
            $expectedUsers = $this->rows('users');

            $this->resetBatchUsers();
            $this->seedBatchUsers();
            $this->pdo()->sqlLog = [];
            if ($action === 'ban') {
                AdminRepository::banUsersByIds([1, 2, 3]);
            } else {
                AdminRepository::unbanUsersByIds([1, 2, 3]);
            }
            $this->assertSame($expectedUsers, $this->rows('users'), "批量 {$action} 与 HEAD 不一致");
            $this->assertSame(
                $action === 'ban'
                    ? 'UPDATE users SET status = ? WHERE id IN (?,?,?) AND id != ? AND role != ?'
                    : 'UPDATE users SET status = ? WHERE id IN (?,?,?)',
                $this->pdo()->sqlLog[0] ?? '',
                "批量 {$action} SQL 必须与 HEAD 逐字一致（值绑定）"
            );
        }

        // 软删 + 匿名化：与 HEAD Database::update 逐字段比对，并检查 role != admin 守卫
        $this->resetBatchUsers();
        $this->seedBatchUsers();
        $deadPwd = 'hashed';
        $nickname = '已注销用户_2';
        $tokenVersion = 123456;
        $now = '2026-03-01 10:00:00';
        $this->pdo()->prepare('UPDATE users SET `username` = ?, `nickname` = ?, `email` = ?, `avatar` = ?, `cover` = ?, `bio` = ?, `password` = ?, `status` = ?, `deleted_at` = ?, `token_version` = ?, `updated_at` = ? WHERE id = ? AND role != ? AND id != ?')
            ->execute([
                'deleted_2', $nickname, '', '/templates/moments/assets/images/default-avatar.svg',
                '', '', $deadPwd, 0, $now, $tokenVersion, $now, 2, 'admin', 1,
            ]);
        $expectedUserRows = $this->rows('users');

        $this->resetBatchUsers();
        $this->seedBatchUsers();
        $this->pdo()->sqlLog = [];
        AdminRepository::softDeleteUser(2, $now, $deadPwd, $nickname, $tokenVersion);
        $this->assertSame($expectedUserRows, $this->rows('users'));
        $this->assertSame(
            'UPDATE `users` SET `username` = ?, `nickname` = ?, `email` = ?, `avatar` = ?, `cover` = ?, `bio` = ?, `password` = ?, `status` = ?, `deleted_at` = ?, `token_version` = ?, `updated_at` = ? WHERE id = ? AND role != ? AND id != ?',
            $this->pdo()->sqlLog[0] ?? ''
        );

        $this->resetBatchUsers();
        $this->seedBatchUsers();
        AdminRepository::softDeleteUser(1, $now, $deadPwd, $nickname, $tokenVersion);
        $admin = $this->pdo()->query('SELECT * FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('admin', $admin['role'], '管理员账号不得被软删');
        $this->assertSame(1, (int)$admin['status']);
        try {
            AdminRepository::deletePostsByIds([]);
            $this->fail('空 ID 列表必须被仓储守卫拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('参数错误', $e->getMessage(), '仓储入口守卫不得被绕过');
        }
    }

    // ===== 迁移前 HEAD 原始 SQL 快照（仅供测试对照） =====

    private function originalWalletLogs(string $type, string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND wl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR wl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT wl.id, wl.user_id, wl.amount, wl.type, wl.biz_type, wl.biz_id,
                    wl.balance_after, wl.remark, wl.created_at,
                    u.username, u.nickname, u.vip_level
             FROM wallet_logs wl
             LEFT JOIN users u ON wl.user_id = u.id
             {$where}
             ORDER BY wl.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCountWalletLogs(string $type, string $keyword): int
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND wl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR wl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM wallet_logs wl LEFT JOIN users u ON wl.user_id = u.id {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** HEAD walletAdjust() 的完整事务序列 */
    private function originalWalletAdjust(int $userId, float $amount, string $remark): float
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND balance + ? >= 0");
            $stmt->execute([$amount, $userId, $amount]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
                $check->execute([$userId]);
                if ($check->fetchColumn() === false) {
                    throw new \RuntimeException('用户不存在');
                }
                throw new \RuntimeException('扣款后余额不能为负');
            }
            $check = $pdo->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
            $check->execute([$userId]);
            $after = round((float)$check->fetchColumn(), 2);
            $log = $pdo->prepare(
                'INSERT INTO `wallet_logs` (`user_id`, `amount`, `type`, `biz_type`, `balance_after`, `remark`) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $log->execute([
                $userId, $amount, $amount > 0 ? 'recharge' : 'adjust', 'admin', $after, $remark,
            ]);
            $pdo->commit();
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function originalVipOrders(int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT vs.id, vs.user_id, vs.level, vs.days, vs.amount, vs.order_no, vs.status,
                    vs.started_at, vs.expire_at, vs.created_at,
                    u.username, u.nickname
             FROM vip_subscriptions vs
             LEFT JOIN users u ON vs.user_id = u.id
             ORDER BY vs.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute([$offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD grantVip() 的事务序列 */
    private function originalGrantVip(
        int $userId,
        int $level,
        int $days,
        string $orderNo,
        string $startedAt,
        string $expireAt
    ): void {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE `users` SET `vip_level` = ?, `vip_expire_at` = ? WHERE id = ?')
                ->execute([$level, $expireAt, $userId]);
            $pdo->prepare(
                'INSERT INTO `vip_subscriptions` (`user_id`, `level`, `days`, `amount`, `order_no`, `status`, `started_at`, `expire_at`, `remark`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $level, $days, 0, $orderNo, 1, $startedAt, $expireAt, '后台人工开通']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function originalAdminLogs(string $type, string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND pl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR pl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT pl.id, pl.user_id, pl.points, pl.type, pl.biz_type, pl.biz_id,
                    pl.balance_after, pl.remark, pl.created_at,
                    u.username, u.nickname
             FROM points_logs pl
             LEFT JOIN users u ON pl.user_id = u.id
             {$where}
             ORDER BY pl.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCountAdminLogs(string $type, string $keyword): int
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND pl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR pl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM points_logs pl LEFT JOIN users u ON pl.user_id = u.id {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function originalAdminTaskList(): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, title, `desc`, points, task_type, target, period, status, sort
             FROM point_tasks ORDER BY sort ASC, id ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalFeaturedPosts(string $slot, int $limit): array
    {
        [$visWhere, $visParams] = \Hublog\Core\VisibilityPolicy::where(0);
        $stmt = $this->pdo()->prepare(
            "SELECT f.post_id, f.sort_order, f.remark,
    p.id, p.content, p.images, p.post_type, p.like_count, p.comment_count, p.created_at,
    u.username, u.nickname, u.avatar
 FROM featured_posts f
 INNER JOIN posts p ON p.id = f.post_id AND p.status = 1
 INNER JOIN users u ON u.id = p.user_id
 WHERE f.slot = ? AND (f.start_at IS NULL OR f.start_at <= NOW())
   AND (f.end_at IS NULL OR f.end_at >= NOW())
   AND {$visWhere}
 ORDER BY f.sort_order ASC, f.id ASC LIMIT ?"
        );
        $stmt->execute(array_merge([$slot], $visParams, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD rankingWindow() 原样：白名单周期拼固定 INTERVAL 片段 */
    private function originalRankingWindow(string $period): string
    {
        if ($period === 'all') {
            return '';
        }
        $map = ['day' => 'INTERVAL 1 DAY', 'week' => 'INTERVAL 7 DAY', 'month' => 'INTERVAL 1 MONTH'];
        return " AND p.created_at >= DATE_SUB(NOW(), {$map[$period]})";
    }

    private function originalUserRanking(int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.vip_level, u.avatar_frame,
                        (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND status = 1) AS post_count,
                        (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1) AS comment_count,
                        (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id) AS like_count,
                        (SELECT COUNT(*) FROM favorites f INNER JOIN posts p ON f.post_id = p.id WHERE p.user_id = u.id) AS fav_count,
                        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS follower_count,
                        (
                            (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND status = 1) * 3 +
                            (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1) * 2 +
                            (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id) * 4 +
                            (SELECT COUNT(*) FROM favorites f INNER JOIN posts p ON f.post_id = p.id WHERE p.user_id = u.id) * 2 +
                            (SELECT COUNT(*) FROM follows WHERE following_id = u.id) * 3
                        ) AS score
                    FROM users u
                    WHERE u.status = 1
                    HAVING score > 0
                    ORDER BY score DESC, u.created_at ASC
                    LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalRankingUsers(string $period, int $limit): array
    {
        $w = $this->originalRankingWindow($period);
        $stmt = $this->pdo()->prepare(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.vip_level, u.avatar_frame,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1{$w}) AS post_count,
                    (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1{$w}) AS comment_count,
                    (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id{$w}) AS like_count,
                    (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS follower_count,
                    (
                        (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1{$w}) * 3 +
                        (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1{$w}) * 2 +
                        (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id{$w}) * 4 +
                        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) * 3
                    ) AS score
                FROM users u
                WHERE u.status = 1
                HAVING score > 0
                ORDER BY score DESC, u.created_at ASC
                LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalRankingPosts(string $period, int $limit): array
    {
        $w = $this->originalRankingWindow($period);
        $stmt = $this->pdo()->prepare(
            "SELECT p.id, p.content, p.images, p.post_type, p.circle_id, p.comment_count, p.created_at,
                    u.username, u.nickname, u.avatar,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS like_count,
                    (SELECT COUNT(*) FROM comments WHERE post_id = p.id AND status = 1) AS comment_count,
                    (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) AS fav_count
                FROM posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE p.status = 1{$w}
                ORDER BY (like_count * 3 + comment_count * 2 + fav_count * 5) DESC, p.created_at DESC
                LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalRankingCircles(string $period, int $limit): array
    {
        $w = $this->originalRankingWindow($period);
        $stmt = $this->pdo()->prepare(
            "SELECT c.id, c.name, c.slug, c.cover, c.description, c.member_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.circle_id = c.id AND p.status = 1{$w}) AS post_count
                FROM circles c
                WHERE c.status = 1
                ORDER BY post_count DESC, c.member_count DESC, c.id ASC
                LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalRankingTopics(string $period, int $limit): array
    {
        $w = $this->originalRankingWindow($period);
        $stmt = $this->pdo()->prepare(
            "SELECT t.id, t.name, t.slug, t.description,
                    (SELECT COUNT(*) FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = t.name{$w}) AS post_count
                FROM topics t
                WHERE t.status = 1
                ORDER BY post_count DESC, t.id ASC
                LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD batchPosts() 的字符串插值 SQL，用于行为对照 */
    private function originalBatchPost(string $action, array $ids, string $now): void
    {
        $idStr = implode(',', $ids);
        $sql = match ($action) {
            'delete' => "DELETE FROM posts WHERE id IN ($idStr)",
            'hide' => "UPDATE posts SET status = 0, audit_state = 0, updated_at = '$now' WHERE id IN ($idStr)",
            'show' => "UPDATE posts SET status = 1, updated_at = '$now' WHERE id IN ($idStr)",
            'pin' => "UPDATE posts SET is_pinned = 1, pinned_at = '$now' WHERE id IN ($idStr)",
            'unpin' => "UPDATE posts SET is_pinned = 0, pinned_at = NULL WHERE id IN ($idStr)",
            default => $this->fail('未知 action'),
        };
        $this->pdo()->exec($sql);
    }

    // ===== 夹具 =====

    private function seedRankingData(): void
    {
        $now = time();
        foreach ([
            [1, 'alice', '2026-01-01 00:00:00'],
            [2, 'bob', '2026-01-02 00:00:00'],
            [3, 'carol', '2026-01-03 00:00:00'],
        ] as [$id, $name, $created]) {
            $this->seed('users', [
                'id' => $id, 'username' => $name, 'nickname' => $name, 'avatar' => '/a.png',
                'bio' => 'bio', 'realname_verified' => 0, 'vip_level' => 1,
                'avatar_frame' => null, 'status' => 1, 'created_at' => $created,
            ]);
        }
        foreach ([
            [1, 1, 'normal', 1, date('Y-m-d H:i:s', $now - 3600)],
            [2, 2, 'circle', 1, date('Y-m-d H:i:s', $now - 2 * 86400)],
            [3, 3, 'normal', 1, date('Y-m-d H:i:s', $now - 10 * 86400)],
            [4, 1, 'normal', 0, date('Y-m-d H:i:s', $now - 60)],
        ] as [$id, $uid, $type, $status, $created]) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => $uid, 'post_type' => $type, 'content' => "p{$id}",
                'images' => '', 'circle_id' => 1, 'status' => $status,
                'comment_count' => $id, 'created_at' => $created,
            ]);
        }
        foreach ([1, 2, 3] as $i) {
            $this->seed('comments', [
                'id' => $i, 'post_id' => 1, 'user_id' => $i, 'content' => 'c',
                'created_at' => date('Y-m-d H:i:s', $now - $i * 3600),
            ]);
            $this->seed('likes', [
                'id' => $i, 'post_id' => 1, 'user_id' => $i,
                'created_at' => date('Y-m-d H:i:s', $now - $i * 3600),
            ]);
            $this->seed('favorites', ['id' => $i, 'post_id' => 1, 'user_id' => $i]);
            $this->seed('follows', ['id' => $i, 'follower_id' => $i + 1, 'following_id' => 1]);
            $this->seed('post_tags', ['id' => $i, 'post_id' => 1, 'tag' => '话题' . $i]);
        }
        foreach ([1, 2] as $i) {
            $this->seed('circles', [
                'id' => $i, 'name' => "圈{$i}", 'slug' => "c{$i}", 'cover' => '',
                'description' => '', 'member_count' => 10 - $i, 'status' => 1,
                'created_at' => '2026-01-01 00:00:00',
            ]);
            $this->seed('topics', [
                'id' => $i, 'name' => '话题' . $i, 'slug' => "t{$i}",
                'description' => '', 'status' => 1, 'post_count' => 3 - $i,
            ]);
        }
    }

    private function seedBatchPosts(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->seed('posts', [
                'id' => $i, 'user_id' => 1, 'post_type' => 'normal', 'content' => "p{$i}",
                'status' => 1, 'is_pinned' => 0, 'pinned_at' => null,
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => null,
            ]);
        }
    }

    private function resetBatchPosts(): void
    {
        $this->pdo()->exec('DELETE FROM posts');
    }

    private function seedBatchUsers(): void
    {
        foreach ([
            [1, 'root', 'admin', 1], [2, 'u2', 'user', 1], [3, 'u3', 'user', 0],
        ] as [$id, $name, $role, $status]) {
            $this->seed('users', [
                'id' => $id, 'username' => $name, 'nickname' => $name, 'role' => $role,
                'status' => $status, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    private function resetBatchUsers(): void
    {
        $this->pdo()->exec('DELETE FROM users');
    }

    /**
     * 对照写操作结果时忽略自增主键：DELETE 后重新 INSERT 不会重置 sqlite_sequence，
     * 其余字段仍逐字段严格比对。
     *
     * @return list<array<string,mixed>>
     */
    private function rowsWithoutId(string $table): array
    {
        $rows = [];
        foreach ($this->rows($table) as $row) {
            unset($row['id']);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function taskRow(int $id): array
    {
        $row = $this->pdo()->query("SELECT title, `desc`, points, task_type, target, period, status, sort FROM point_tasks WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
