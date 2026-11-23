<?php
/**
 * 批次五 · 系统设置杂项域 SQL 收敛对照测试
 *
 * 对照法：把 HEAD 中 MetricsController / HealthController / JobController /
 * AdminUpgradeController / AdminCrashController / AdminAppVersionController /
 * AdminAttachmentController / AdminFriendLinkController / PostSettingsTrait /
 * AdminAiController / AdminLicenseController / LocationController 的原始内联 SQL
 * 原样写在本文件 original*() 私有方法里，与下沉后的 Repository 结果逐字段 assertSame，
 * 覆盖空结果、单行、多页、筛选/状态负例、写副作用与动态表名注入负例。
 *
 * 仅补建被测表；SQLite 专有函数（CURDATE）通过 sqliteCreateFunction 注册，
 * 使 HEAD 原文口径可被真实比对，而不是被改写掩盖。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AppRepository;
use Hublog\Core\FriendLinkRepository;
use Hublog\Core\SettingsRepository;
use Hublog\Core\SystemRepository;
use Hublog\Core\UploadRepository;
use PDO;

final class Batch5SystemSettingsBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 给基础 uploads 表补齐后台附件路径用到的列（ALTER 只增列，不动底座）
        $this->pdo()->exec('ALTER TABLE `uploads` ADD COLUMN `post_id` INTEGER DEFAULT NULL');
        $this->pdo()->exec('ALTER TABLE `uploads` ADD COLUMN `size` INTEGER NOT NULL DEFAULT 0');
        $this->pdo()->exec('ALTER TABLE `uploads` ADD COLUMN `updated_at` TEXT DEFAULT NULL');
        $this->pdo()->exec('ALTER TABLE `uploads` ADD COLUMN `created_at` TEXT DEFAULT NULL');

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `jobs` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `type`        TEXT    DEFAULT NULL,
    `status`      TEXT    DEFAULT NULL,
    `attempts`    INTEGER NOT NULL DEFAULT 0,
    `last_error`  TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `finished_at` TEXT    DEFAULT NULL
);
CREATE TABLE `app_crash_logs` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER DEFAULT NULL,
    `event`       TEXT    DEFAULT NULL,
    `platform`    TEXT    DEFAULT NULL,
    `client`      TEXT    DEFAULT NULL,
    `route`       TEXT    DEFAULT NULL,
    `api_url`     TEXT    DEFAULT NULL,
    `api_code`    INTEGER DEFAULT NULL,
    `message`     TEXT    DEFAULT NULL,
    `stack`       TEXT    DEFAULT NULL,
    `context`     TEXT    DEFAULT NULL,
    `ip`          TEXT    DEFAULT NULL,
    `occurred_at` TEXT    DEFAULT NULL
);
CREATE TABLE `app_versions` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `platform`     TEXT    DEFAULT NULL,
    `version_code` INTEGER NOT NULL DEFAULT 0,
    `version_name` TEXT    DEFAULT NULL,
    `update_type`  INTEGER NOT NULL DEFAULT 0,
    `changelog`    TEXT    DEFAULT NULL,
    `download_url` TEXT    DEFAULT NULL,
    `status`       INTEGER NOT NULL DEFAULT 1,
    `created_at`   TEXT    DEFAULT NULL,
    `updated_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `friend_links` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`        TEXT    DEFAULT NULL,
    `url`         TEXT    DEFAULT NULL,
    `description` TEXT    DEFAULT NULL,
    `sort_order`  INTEGER NOT NULL DEFAULT 0,
    `status`      INTEGER NOT NULL DEFAULT 1,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `comments` (
    `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL DEFAULT 0,
    `user_id` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `likes` (
    `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL DEFAULT 0,
    `user_id` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `notifications` (
    `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `posts` (
    `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0
);
SQL);

        // HEAD 的 COUNT(... CURDATE()) 依赖 MySQL 函数；注册 SQLite 等价实现
        $this->pdo()->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
    }

    // ==================== SystemRepository：探针 / settings 值 / 元信息转发 ====================

    public function testPingAndSettingValueMatchOriginal(): void
    {
        $this->assertSame(1, (int)SystemRepository::ping(), 'SELECT 1 连通性探针恒为 1');

        // 空库负例：未配置的 key 返回 null，控制器按空串 fail-closed
        $this->assertNull(SystemRepository::findSettingValue('metrics_secret'));

        $this->seed('settings', ['key' => 'metrics_secret', 'value' => '  top-secret  ']);
        $this->seed('settings', ['key' => 'cron_secret', 'value' => 'cron-key']);
        $expected = $this->originalSettingValue('metrics_secret');
        $this->assertSame($expected, SystemRepository::findSettingValue('metrics_secret'));
        $this->assertSame('  top-secret  ', $expected['value'], '原始值不裁剪，trim 仍在控制器');
        $this->assertSame($this->originalSettingValue('cron_secret'), SystemRepository::findSettingValue('cron_secret'));
    }

    public function testStatsAndSlowQueryStatsAreForwardedMetadata(): void
    {
        // 元信息转发例外：返回结构键存在即可，值来自 Database 自身计数/缓存文件
        $stats = SystemRepository::stats();
        $this->assertIsArray($stats);
        foreach (['query_count', 'query_time', 'slow_query_threshold_ms'] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
        $slow = SystemRepository::slowQueryStats();
        $this->assertIsArray($slow);
        foreach (['count', 'total_ms', 'by_table', 'recent'] as $key) {
            $this->assertArrayHasKey($key, $slow);
        }
        // 与 FQN 直调结果一致，证明是纯转发而非重算
        $this->assertSame(\Hublog\Core\Database::getStats(), SystemRepository::stats());
        $this->assertSame(\Hublog\Core\Database::slowQueryStats(), SystemRepository::slowQueryStats());
    }

    public function testRecentJobsMatchOriginal(): void
    {
        $this->assertSame([], SystemRepository::recentJobs(), '空队列负例');
        foreach ([
            [3, 'compress', 'done', 1, '2026-01-03 00:00:00', null, null],
            [1, 'publish', 'failed', 2, '2026-01-01 00:00:00', 'boom', '2026-01-02 00:00:00'],
            [2, 'badge', 'pending', 0, '2026-01-02 00:00:00', null, null],
        ] as [$id, $type, $status, $attempts, $created, $err, $finished]) {
            $this->seed('jobs', [
                'id' => $id, 'type' => $type, 'status' => $status, 'attempts' => $attempts,
                'last_error' => $err, 'created_at' => $created, 'finished_at' => $finished,
            ]);
        }
        $this->assertSame($this->originalRecentJobs(), SystemRepository::recentJobs(), '字段/顺序/ORDER/LIMIT 逐字等价');
        $this->assertSame([3, 2, 1], array_column(SystemRepository::recentJobs(), 'id'), 'ORDER BY id DESC');
    }

    public function testUpgradeRepoSettingsKeepKeyPairAndWhitelist(): void
    {
        $this->assertSame([], SystemRepository::fetchUpgradeRepoSettings(), '空库负例');
        $this->seed('settings', ['key' => 'upgrade_repo_owner', 'value' => 'acme']);
        $this->seed('settings', ['key' => 'upgrade_repo_name', 'value' => 'repo']);
        $this->seed('settings', ['key' => 'upgrade_repo_provider', 'value' => 'gitee']);
        $this->seed('settings', ['key' => 'unrelated_key', 'value' => 'leak']);
        $expected = $this->originalUpgradeRepoSettings();
        $this->assertSame($expected, SystemRepository::fetchUpgradeRepoSettings(), 'FETCH_KEY_PAIR 形态与 key IN 白名单必须保留');
        $this->assertArrayNotHasKey('unrelated_key', SystemRepository::fetchUpgradeRepoSettings(), '白名单外的键不得返回');
    }

    public function testBackupTableRowsMatchOriginalAndRejectInjectedTable(): void
    {
        foreach (['users', 'posts', 'notifications'] as $table) {
            $this->assertSame([], SystemRepository::fetchBackupTableRows($table), "空表负例 {$table}");
        }
        $this->assertSame(
            $this->pdo()->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_ASSOC),
            SystemRepository::fetchBackupTableRows('settings'),
            'settings 表也走动态表名白名单路径，逐字等价'
        );
        $this->seed('users', ['id' => 1, 'username' => 'alice']);
        $this->seed('users', ['id' => 2, 'username' => 'bob']);
        $this->assertSame(
            $this->pdo()->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC),
            SystemRepository::fetchBackupTableRows('users'),
            '动态表名 SELECT * 必须与 HEAD 逐字等价'
        );

        // 动态表名注入负例：白名单外的表名必须被仓储本体拒绝
        foreach (['users; DROP TABLE users', 'sqlite_master', 'users--', '../etc/passwd', 'USERS'] as $bad) {
            try {
                SystemRepository::fetchBackupTableRows($bad);
                $this->fail("非法表名应被拒绝: {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('非法备份表名', $e->getMessage());
            }
        }
        $this->assertCount(2, $this->rows('users'), '注入尝试后 users 表仍完好');
    }

    // ==================== AppRepository：崩溃日志 ====================

    public function testCrashLogsMatchOriginalWithSharedWhereAndPagination(): void
    {
        // 空库负例
        $this->assertSame(0, AppRepository::countCrashLogs('', '', '', ''));
        $this->assertSame([], AppRepository::fetchCrashLogs('', '', '', '', 10, 0));
        $this->assertSame([], AppRepository::fetchCrashTop('', '', '', ''));
        $this->assertSame(0, AppRepository::countTodayCrashLogs());

        $today = date('Y-m-d') . ' 10:00:00';
        $old = '2020-01-01 10:00:00';
        foreach ([
            [1, 10, 'error', 'android', '/a', '2026-01-01 boom', $today],
            [2, 20, 'error', 'ios', '/b', '2026-01-01 boom', $today],
            [3, 10, 'error', 'android', '/a', 'different', $today],
            [4, 30, 'error', 'h5', '/c', 'old one', $old],
        ] as [$id, $uid, $event, $platform, $route, $message, $at]) {
            $this->seed('app_crash_logs', [
                'id' => $id, 'user_id' => $uid, 'event' => $event, 'platform' => $platform,
                'route' => $route, 'message' => $message, 'occurred_at' => $at,
            ]);
        }

        // 全量：字段集/顺序/ORDER/分页逐字等价
        $cases = [
            ['', '', '', '', 10, 0],
            ['error', '', '', '', 10, 0],
            ['', 'ios', '', '', 10, 0],
            ['error', 'android', '', '', 2, 0],
            ['error', 'android', '', '', 2, 1],
            ['', '', '', 'boom', 10, 0],
            ['error', 'android', '2020-01-01', '', 10, 0], // 日期筛选
        ];
        foreach ($cases as [$event, $platform, $date, $kw, $limit, $offset]) {
            $expected = $this->originalCrashLogs($event, $platform, $date, $kw, $limit, $offset);
            $actual = AppRepository::fetchCrashLogs($event, $platform, $date, $kw, $limit, $offset);
            $this->assertSame($expected, $actual, '崩溃日志列表字段/顺序/分页必须与 HEAD 原文一致');
            $this->assertSame(
                $this->originalCrashCount($event, $platform, $date, $kw),
                AppRepository::countCrashLogs($event, $platform, $date, $kw),
                '计数与列表必须共用同一 WHERE'
            );
        }

        // top 聚合：同 event+message 合并，order by cnt desc
        $expectedTop = $this->originalCrashTop('', '', '', '');
        $actualTop = AppRepository::fetchCrashTop('', '', '', '');
        $this->assertSame($expectedTop, $actualTop, 'Top 聚合字段/分组/排序必须与 HEAD 一致');
        $this->assertSame(2, (int)$actualTop[0]['cnt'], 'error/2026-01-01 boom 合并为 2 条');

        // 今日新增不受筛选影响
        $this->assertSame(
            (int)$this->pdo()->query("SELECT COUNT(*) FROM app_crash_logs WHERE DATE(occurred_at) = CURDATE()")->fetchColumn(),
            AppRepository::countTodayCrashLogs()
        );
        $this->assertSame(3, AppRepository::countTodayCrashLogs());

        // 非法筛选值被白名单忽略（等价于不过滤），与 HEAD 守卫行为一致
        $this->assertSame(
            AppRepository::countCrashLogs('', '', '', ''),
            AppRepository::countCrashLogs('drop table', '', '', ''),
            '非法 event 值应被白名单忽略，与 HEAD 口径一致'
        );
        $this->assertSame(
            AppRepository::fetchCrashLogs('', '', '', '', 10, 0),
            AppRepository::fetchCrashLogs('', 'windows', '', '', 10, 0),
            '非法 platform 值应被白名单忽略'
        );
    }

    public function testCrashAlertThresholdInsertThenUpdate(): void
    {
        // fetchColumn 空结果返回 false；控制器按 `?: 0` 兜底，仓储返回原始标量
        $this->assertFalse(AppRepository::fetchCrashAlertThreshold(), '空库负例');

        // 首次：insert
        AppRepository::saveCrashAlertThreshold('5');
        $this->assertSame('5', AppRepository::fetchCrashAlertThreshold());
        $row = $this->rowByKey('settings', 'crash_alert_threshold');
        $this->assertSame('5', $row['value']);
        $this->assertSame(['key', 'value'], array_keys($row), 'insert 不写额外时间戳字段（与 HEAD 一致）');

        // 再次：唯一键冲突 → catch 分支 update
        AppRepository::saveCrashAlertThreshold('9');
        $this->assertSame('9', AppRepository::fetchCrashAlertThreshold());
        $this->assertSame(1, count(array_filter(
            $this->rows('settings'),
            static fn(array $r): bool => $r['key'] === 'crash_alert_threshold'
        )), '先插后更不得产生重复键');
    }

    // ==================== AppRepository：应用版本 ====================

    public function testAdminAppVersionsMatchOriginalAndFieldSetDiffersFromPublic(): void
    {
        $this->assertSame([], AppRepository::fetchAdminAppVersions(), '空库负例');
        foreach ([
            [3, 'android', 30, 'v3.0', '/d3'],
            [1, 'ios', 10, 'v1.0', '/d1'],
            [2, 'android', 20, 'v2.0', '/d2'],
        ] as [$id, $platform, $code, $name, $url]) {
            $this->seed('app_versions', [
                'id' => $id, 'platform' => $platform, 'version_code' => $code,
                'version_name' => $name, 'download_url' => $url, 'status' => 1,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->assertSame($this->originalAdminAppVersions(), AppRepository::fetchAdminAppVersions());
        $this->assertSame([3, 2, 1], array_column(AppRepository::fetchAdminAppVersions(), 'id'), 'version_code DESC, id DESC');

        // 与公开版 findLatestVersion 字段集不同：后台版含 status/created_at、字段更多
        $adminKeys = array_keys(AppRepository::fetchAdminAppVersions()[0]);
        $this->assertContains('status', $adminKeys);
        $this->assertContains('created_at', $adminKeys);

        // 写：update / insert / delete
        AppRepository::updateAppVersion(1, [
            'platform' => 'ios', 'version_code' => 11, 'version_name' => 'v1.1',
            'update_type' => 0, 'changelog' => 'fix', 'download_url' => '/d1b',
            'status' => 0, 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $updated = $this->rowById('app_versions', 1);
        $this->assertSame('v1.1', $updated['version_name']);
        $this->assertSame(0, (int)$updated['status']);

        $newId = AppRepository::insertAppVersion([
            'platform' => 'h5', 'version_code' => 40, 'version_name' => 'v4.0',
            'update_type' => 1, 'changelog' => 'new', 'download_url' => '/d4',
            'status' => 1, 'updated_at' => '2026-06-02 00:00:00', 'created_at' => '2026-06-02 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame('v4.0', $this->rowById('app_versions', $newId)['version_name']);

        AppRepository::deleteAppVersion(2);
        $this->assertNull($this->rowByIdOrNull('app_versions', 2), '删除后记录不存在');
    }

    // ==================== UploadRepository：后台附件 ====================

    public function testAttachmentMethodsMatchOriginalAndKeepDistinctFieldSets(): void
    {
        $this->assertNull(UploadRepository::findAttachmentPostInfo(999), '空库负例');
        $this->assertNull(UploadRepository::findAttachmentForDelete(999));
        $this->assertSame(0, UploadRepository::countUploadsByUrlExceptId('/none', 999));

        $this->seed('uploads', [
            'id' => 1, 'file_hash' => 'h1', 'url' => '/same.jpg', 'post_id' => 10, 'status' => 1, 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->seed('uploads', [
            'id' => 2, 'file_hash' => 'h2', 'url' => '/same.jpg', 'post_id' => null, 'status' => 1, 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->seed('uploads', [
            'id' => 3, 'file_hash' => 'h3', 'url' => '/other.jpg', 'post_id' => 20, 'status' => 0, 'updated_at' => '2026-01-01 00:00:00',
        ]);

        // 字段集不同：post_id,url vs id,url，交叉断言防误合并
        $postInfo = UploadRepository::findAttachmentPostInfo(1);
        $forDelete = UploadRepository::findAttachmentForDelete(1);
        $this->assertSame($this->originalAttachmentPostInfo(1), $postInfo);
        $this->assertSame($this->originalAttachmentForDelete(1), $forDelete);
        $this->assertSame(['post_id', 'url'], array_keys($postInfo));
        $this->assertSame(['id', 'url'], array_keys($forDelete));
        $this->assertSame(10, (int)$postInfo['post_id']);

        $this->assertSame(
            (int)$this->pdo()->query("SELECT COUNT(*) FROM uploads WHERE url = '/same.jpg' AND id != 1")->fetchColumn(),
            UploadRepository::countUploadsByUrlExceptId('/same.jpg', 1)
        );
        $this->assertSame(1, UploadRepository::countUploadsByUrlExceptId('/same.jpg', 1));
        $this->assertSame(0, UploadRepository::countUploadsByUrlExceptId('/other.jpg', 3));

        // 写：状态更新（含 updated_at）
        UploadRepository::updateAttachmentStatus(3, 1, '2026-06-01 00:00:00');
        $row = $this->rowById('uploads', 3);
        $this->assertSame(1, (int)$row['status']);
        $this->assertSame('2026-06-01 00:00:00', $row['updated_at']);
    }

    // ==================== FriendLinkRepository ====================

    public function testFriendLinksMatchOriginalWithDistinctFieldSets(): void
    {
        $this->assertSame([], FriendLinkRepository::fetchAdminFriendLinks());
        $this->assertSame([], FriendLinkRepository::fetchActiveFriendLinks());

        foreach ([
            [2, 'B', '/b', 'desc-b', 2, 1],
            [1, 'A', '/a', 'desc-a', 1, 1],
            [3, 'C', '/c', 'desc-c', 3, 0],
        ] as [$id, $name, $url, $desc, $sort, $status]) {
            $this->seed('friend_links', [
                'id' => $id, 'name' => $name, 'url' => $url, 'description' => $desc,
                'sort_order' => $sort, 'status' => $status, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        $this->assertSame($this->originalAdminFriendLinks(), FriendLinkRepository::fetchAdminFriendLinks());
        $this->assertSame($this->originalActiveFriendLinks(), FriendLinkRepository::fetchActiveFriendLinks());
        $this->assertSame([1, 2, 3], array_column(FriendLinkRepository::fetchAdminFriendLinks(), 'id'), '后台全量含 status=0');
        $this->assertSame([1, 2], array_column(FriendLinkRepository::fetchActiveFriendLinks(), 'id'), '前台仅 status=1');
        $adminKeys = array_keys(FriendLinkRepository::fetchAdminFriendLinks()[0]);
        $publicKeys = array_keys(FriendLinkRepository::fetchActiveFriendLinks()[0]);
        $this->assertSame(['id', 'name', 'url', 'description', 'sort_order', 'status', 'created_at'], $adminKeys);
        $this->assertSame(['id', 'name', 'url', 'description'], $publicKeys);

        // 写：update / insert / delete
        FriendLinkRepository::updateFriendLink(1, [
            'name' => 'A2', 'url' => '/a2', 'description' => 'd2',
            'sort_order' => 5, 'status' => 0, 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->assertSame('A2', $this->rowById('friend_links', 1)['name']);
        $this->assertSame(0, (int)$this->rowById('friend_links', 1)['status']);

        $newId = FriendLinkRepository::insertFriendLink([
            'name' => 'D', 'url' => '/d', 'description' => 'desc-d',
            'sort_order' => 9, 'status' => 1, 'updated_at' => '2026-06-01 00:00:00',
            'created_at' => '2026-06-01 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        FriendLinkRepository::deleteFriendLink(3);
        $this->assertNull($this->rowByIdOrNull('friend_links', 3));
    }

    // ==================== SettingsRepository：license LIKE + 复用方法 ====================

    public function testLicenseSettingsAndReusedSettingsMethods(): void
    {
        $this->assertSame([], SettingsRepository::fetchLicenseSettings(), '空库负例');
        $this->seed('settings', ['key' => 'license_remote_url', 'value' => 'https://a']);
        $this->seed('settings', ['key' => 'license_public_key', 'value' => 'PEM']);
        $this->seed('settings', ['key' => 'site_name', 'value' => '我圈']);
        $this->seed('settings', ['key' => 'not_license', 'value' => 'leak']);

        $expected = $this->originalLicenseSettings();
        $this->assertSame($expected, SettingsRepository::fetchLicenseSettings(), 'license_ LIKE 过滤逐字等价');
        $this->assertCount(2, $expected, '只返回 license_ 前缀键');
        $keys = array_column($expected, 'key');
        $this->assertContains('license_remote_url', $keys);
        $this->assertContains('license_public_key', $keys);
        $this->assertNotContains('site_name', $keys);

        // 复用方法零回归：fetchKeyValueRows 仍返回原始行，fetchValueByKey 仍返回单值
        $this->assertSame(
            $this->pdo()->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_ASSOC),
            SettingsRepository::fetchKeyValueRows()
        );
        $this->assertSame('我圈', SettingsRepository::fetchValueByKey('site_name'));
        $this->assertFalse(SettingsRepository::fetchValueByKey('missing'));
    }

    // ==================== HEAD 原始 SQL 快照 ====================

    private function originalSettingValue(string $key): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function originalUpgradeRepoSettings(): array
    {
        $rows = $this->pdo()->query(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ('upgrade_repo_provider','upgrade_repo_owner','upgrade_repo_name')"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }

    private function originalRecentJobs(): array
    {
        return $this->pdo()->query(
            "SELECT `id`, `type`, `status`, `attempts`, `last_error`, `created_at`, `finished_at`
             FROM `jobs` ORDER BY `id` DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** AdminCrashController::crashLogs() 列表原文（LIMIT/OFFSET 用绑定保持等价） */
    private function originalCrashLogs(
        string $event,
        string $platform,
        string $date,
        string $keyword,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = $this->originalCrashWhere($event, $platform, $date, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT id, user_id, event, platform, client, route, api_url, api_code, message, context, ip, occurred_at
             FROM app_crash_logs {$where} ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCrashCount(string $event, string $platform, string $date, string $keyword): int
    {
        [$where, $params] = $this->originalCrashWhere($event, $platform, $date, $keyword);
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM app_crash_logs {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function originalCrashTop(string $event, string $platform, string $date, string $keyword): array
    {
        [$where, $params] = $this->originalCrashWhere($event, $platform, $date, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT event, message, COUNT(*) AS cnt, MAX(occurred_at) AS last_at
             FROM app_crash_logs {$where} GROUP BY event, message ORDER BY cnt DESC LIMIT 20"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:array} */
    private function originalCrashWhere(string $event, string $platform, string $date, string $keyword): array
    {
        $where = [];
        $params = [];
        if (in_array($event, ['error', 'api_error'], true)) {
            $where[] = 'event = ?';
            $params[] = $event;
        }
        if (in_array($platform, ['android', 'ios', 'h5'], true)) {
            $where[] = 'platform = ?';
            $params[] = $platform;
        }
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'DATE(occurred_at) = ?';
            $params[] = $date;
        }
        if ($keyword !== '') {
            $where[] = '(message LIKE ? OR route LIKE ? OR api_url LIKE ? OR stack LIKE ? OR context LIKE ?)';
            $like = '%' . $keyword . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    private function originalAdminAppVersions(): array
    {
        return $this->pdo()->query(
            "SELECT id, platform, version_code, version_name, update_type, changelog, download_url, status, created_at
             FROM app_versions ORDER BY version_code DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAttachmentPostInfo(int $id): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT post_id, url FROM uploads WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function originalAttachmentForDelete(int $id): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT id, url FROM uploads WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function originalAdminFriendLinks(): array
    {
        return $this->pdo()->query(
            "SELECT id, name, url, description, sort_order, status, created_at FROM friend_links ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalActiveFriendLinks(): array
    {
        return $this->pdo()->query(
            "SELECT id, name, url, description FROM friend_links WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalLicenseSettings(): array
    {
        $stmt = $this->pdo()->prepare("SELECT `key`, `value` FROM settings WHERE `key` LIKE ?");
        $stmt->execute(['license_%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rowById(string $table, int $id): array
    {
        $row = $this->rowByIdOrNull($table, $id);
        $this->assertNotNull($row, "{$table} 中找不到 id={$id}");
        return $row;
    }

    private function rowByIdOrNull(string $table, int $id): ?array
    {
        foreach ($this->rows($table) as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    private function rowByKey(string $table, string $key): array
    {
        foreach ($this->rows($table) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        $this->fail("{$table} 中找不到 key={$key}");
    }
}
