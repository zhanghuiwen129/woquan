<?php
/**
 * 批次四 · 后台域 SQL 收敛对照测试
 *
 * 对照法：把 HEAD 中 AdminController / AdminToolsController / AdminSettingsController
 * 的原始内联 SQL 原样写在本文件的 original*() 私有方法里，与下沉后的 Repository 结果
 * 逐字段 assertSame，覆盖空结果、单行、多页、状态/软删负例与写副作用。
 *
 * SQLite 兼容：产品 Repository 的 SQL 保持 HEAD 原文（含 MySQL 的 INTERVAL /
 * DATE_SUB / NOW），本测试用一个只override prepare() 的 PDO 子类在「执行前」做方言
 * 改写；原始 SQL 快照则以原文本形式保留在 original*() 中，验证口径不被改写掩盖。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AdminRepository;
use Hublog\Core\Database;
use Hublog\Core\SettingsRepository;
use PDO;
use PDOStatement;
use ReflectionProperty;

final class Batch4AdminBehaviorTest extends BehaviorTestCase
{
    private ?AdminDialectPdo $dialectPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 复制底座 schema，再把 settings 换成与 MySQL 一致的结构
        // （id 自增主键 + key 唯一），否则 SELECT id 恒为 NULL，先查后写口径失真。
        $copy = new AdminDialectPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $schemaSql = $this->pdo()->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($schemaSql as $sql) {
            $copy->exec((string)$sql);
        }
        $copy->exec('DROP TABLE `settings`');
        $copy->exec(
            'CREATE TABLE `settings` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `key`        TEXT NOT NULL UNIQUE,
                `value`      TEXT DEFAULT NULL,
                `created_at` TEXT DEFAULT NULL,
                `updated_at` TEXT DEFAULT NULL
            )'
        );
        $copy->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('sms_provider', 'none')");
        $this->dialectPdo = $copy;

        $ref = new ReflectionProperty(Database::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $copy);

        $this->pdo()->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
        $this->pdo()->sqliteCreateFunction('VERSION', static fn(): string => '8.0.36-test');

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `categories` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `parent_id`  INTEGER DEFAULT NULL,
    `name`       TEXT    DEFAULT NULL,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `sort_order` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `posts` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER NOT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `post_type`   TEXT    DEFAULT 'normal',
    `title`       TEXT    DEFAULT NULL,
    `content`     TEXT    DEFAULT NULL,
    `images`      TEXT    DEFAULT NULL,
    `videos`      TEXT    DEFAULT NULL,
    `status`      INTEGER NOT NULL DEFAULT 1,
    `is_pinned`   INTEGER NOT NULL DEFAULT 0,
    `pinned_at`   TEXT    DEFAULT NULL,
    `view_count`  INTEGER NOT NULL DEFAULT 0,
    `visibility`  TEXT    DEFAULT 'public',
    `removed_at`  TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `comments` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `parent_id`  INTEGER DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `is_ai`      INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `likes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `post_id`    INTEGER NOT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `featured_posts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL DEFAULT 0,
    `slot`       TEXT    DEFAULT 'home',
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `remark`     TEXT    DEFAULT NULL,
    `start_at`   TEXT    DEFAULT NULL,
    `end_at`     TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `topics` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`        TEXT    DEFAULT NULL,
    `slug`        TEXT    DEFAULT NULL,
    `description` TEXT    DEFAULT NULL,
    `cover`       TEXT    DEFAULT NULL,
    `status`      INTEGER NOT NULL DEFAULT 1,
    `post_count`  INTEGER NOT NULL DEFAULT 0,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `post_view_logs` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`   INTEGER NOT NULL,
    `view_date` TEXT    DEFAULT NULL,
    `count`     INTEGER NOT NULL DEFAULT 0
);
SQL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->dialectPdo = null;
    }

    /** 让 seed()/rows()/快照 SQL 与 Repository 使用同一个内存库 */
    protected function pdo(): PDO
    {
        return $this->dialectPdo ?? parent::pdo();
    }

    // ==================== dashboard ====================

    public function testDashboardCountsAndVersionMatchOriginalSql(): void
    {
        // 空库负例
        $this->assertSame(0, AdminRepository::countAllUsers());
        $this->assertSame(0, AdminRepository::countTodayUsers());
        $this->assertSame(0, AdminRepository::countAllPosts());
        $this->assertSame(0, AdminRepository::countTodayComments());
        $this->assertSame(0, AdminRepository::countAllLikes());

        $today = date('Y-m-d') . ' 10:00:00';
        $old = '2020-01-01 10:00:00';
        foreach ([
            [1, 'alice', 1, $today],
            [2, 'bob', 0, $today],
            [3, 'carol', 1, $old],
        ] as [$id, $name, $status, $created]) {
            $this->seed('users', ['id' => $id, 'username' => $name, 'status' => $status, 'created_at' => $created]);
        }
        foreach ([
            [1, 1, 1, $today],
            [2, 1, 0, $today],
            [3, 3, 1, $old],
            [4, 3, 1, $old, 'poll', 'public', '2020-06-01 00:00:00'],
        ] as $row) {
            $this->seed('posts', array_combine(
                ['id', 'user_id', 'status', 'created_at', 'post_type', 'visibility', 'removed_at'],
                array_pad($row, 7, null)
            ));
        }
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'created_at' => $today]);
        $this->seed('comments', ['id' => 2, 'post_id' => 3, 'user_id' => 1, 'created_at' => $old]);
        $this->seed('likes', ['id' => 1, 'user_id' => 2, 'post_id' => 1, 'created_at' => $today]);

        $this->assertSame($this->scalar("SELECT COUNT(*) FROM users"), AdminRepository::countAllUsers());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()"), AdminRepository::countTodayUsers());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM users WHERE status = 1"), AdminRepository::countActiveUsers());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM posts"), AdminRepository::countAllPosts());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()"), AdminRepository::countTodayPosts());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM posts WHERE status = 1"), AdminRepository::countActivePosts());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM comments"), AdminRepository::countAllComments());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()"), AdminRepository::countTodayComments());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM likes"), AdminRepository::countAllLikes());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM likes WHERE DATE(created_at) = CURDATE()"), AdminRepository::countTodayLikes());
        $this->assertSame('8.0.36-test', AdminRepository::fetchMysqlVersion());
    }

    // ==================== 内容管理：posts ====================

    public function testAdminPostsListAndCountShareWhereAndMatchOriginal(): void
    {
        $this->assertSame([], AdminRepository::fetchAdminPosts(-1, '', 0, 0, 20));
        $this->assertSame(0, AdminRepository::countAdminPosts(-1, '', 0));

        foreach ([1 => 'alice', 2 => 'bob'] as $uid => $name) {
            $this->seed('users', [
                'id' => $uid, 'username' => $name, 'nickname' => "n{$uid}", 'avatar' => "/a{$uid}.jpg", 'status' => 1,
            ]);
        }
        $this->seed('categories', ['id' => 1, 'name' => '生活', 'status' => 1, 'sort_order' => 1]);
        $this->seed('categories', ['id' => 2, 'name' => '摄影', 'status' => 1, 'sort_order' => 2]);

        $posts = [
            [1, 1, 1, '苹果 相册', 'content-a', '["a.jpg"]', '["v.mp4"]', 1, 1, '2026-03-01 00:00:00'],
            [2, 2, 2, '香蕉 日志', 'content-b', null, null, 1, 0, '2026-02-01 00:00:00'],
            [3, 1, 1, '苹果 摄影', 'content-c', null, null, 0, 0, '2026-04-01 00:00:00'],
            [4, 2, 2, '橘子 相册', 'content-d', null, null, 1, 1, '2026-05-01 00:00:00'],
            [5, 1, 1, '无匹配', 'content-e', null, null, 1, 0, '2026-01-01 00:00:00', 'poll', 'private', '2026-02-01 00:00:00'],
        ];
        foreach ($posts as $row) {
            $this->seed('posts', array_combine(
                ['id', 'user_id', 'category_id', 'title', 'content', 'images', 'videos', 'status', 'is_pinned', 'pinned_at', 'created_at', 'post_type', 'visibility', 'removed_at'],
                array_pad($row, 14, null)
            ));
        }

        $cases = [
            [-1, '', 0, 0, 20],       // 全量
            [1, '', 0, 0, 20],        // 状态筛选
            [-1, '苹果', 0, 0, 20],   // LIKE 标题
            [-1, 'alice', 0, 0, 20],  // LIKE 用户名
            [-1, '', 1, 0, 20],       // 分类筛选
            [-1, '', 0, 1, 2],        // 第二页
            [1, 'content-c', 0, 0, 20], // 复合筛选：空结果
        ];
        foreach ($cases as [$status, $keyword, $categoryId, $offset, $limit]) {
            $expected = $this->originalAdminPosts($status, $keyword, $categoryId, $offset, $limit);
            $actual = AdminRepository::fetchAdminPosts($status, $keyword, $categoryId, $offset, $limit);
            $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), '后台 posts 列表 ID/排序/分页必须与 HEAD 原文一致');
            $this->assertSame(
                $this->originalAdminPostsCount($status, $keyword, $categoryId),
                AdminRepository::countAdminPosts($status, $keyword, $categoryId),
                '分页计数必须与列表共用同一 WHERE'
            );
            if ($expected === []) {
                continue;
            }
            // post_type 是铁律追加的无害列，原列顺序不得被改动
            $expectedKeys = array_keys($expected[0]);
            $actualKeys = array_keys($actual[0]);
            $this->assertSame('post_type', array_pop($actualKeys));
            $this->assertSame($expectedKeys, $actualKeys, '原有字段清单与顺序不得变化');
        }

        // 置顶优先，其次 pinned_at/created_at；后台列表不过滤 status/软删（口径与前台不同）
        $this->assertSame([4, 1, 3, 2, 5], array_column(AdminRepository::fetchAdminPosts(-1, '', 0, 0, 20), 'id'));
    }

    public function testPostStatusCountsAndWritesMatchOriginal(): void
    {
        foreach ([[1, 1], [2, 1], [3, 1], [4, 0], [5, 0], [6, 2]] as [$id, $status]) {
            $this->seed('posts', ['id' => $id, 'user_id' => 1, 'status' => $status, 'post_type' => 'normal']);
        }
        $expected = $this->pdo()->query("SELECT status, COUNT(*) AS cnt FROM posts GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame($expected, AdminRepository::fetchPostStatusCountsRows());

        AdminRepository::updatePostStatus(1, 0, '2026-06-01 00:00:00');
        $row = $this->rowById('posts', 1);
        $this->assertSame(0, (int)$row['status']);
        $this->assertSame('2026-06-01 00:00:00', $row['updated_at']);

        $probe = AdminRepository::findPostIdRow(2);
        $this->assertSame(['id', 'post_type'], array_keys($probe), 'posts 探测行必须带 post_type');
        $this->assertNull(AdminRepository::findPostIdRow(999), '不存在的动态返回 null');

        AdminRepository::updatePostPinned(2, 1, '2026-06-02 00:00:00');
        $pinned = $this->rowById('posts', 2);
        $this->assertSame(1, (int)$pinned['is_pinned']);
        $this->assertSame('2026-06-02 00:00:00', $pinned['pinned_at']);
        AdminRepository::updatePostPinned(2, 0, '2026-06-03 00:00:00');
        $unpinned = $this->rowById('posts', 2);
        $this->assertSame(0, (int)$unpinned['is_pinned']);
        $this->assertNull($unpinned['pinned_at']);

        // 控制器入口校验已下沉，非法值直接调用仓储也不允许绕过
        try {
            AdminRepository::updatePostStatus(1, 7, '2026-06-01 00:00:00');
            $this->fail('非法状态应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('参数错误', $e->getMessage());
        }
    }

    // ==================== 精选推荐位 ====================

    public function testFeaturedQueriesAndWritesMatchOriginal(): void
    {
        foreach ([1, 2, 3] as $uid) {
            $this->seed('users', ['id' => $uid, 'username' => "u{$uid}", 'nickname' => "n{$uid}", 'avatar' => "/a{$uid}.jpg"]);
        }
        foreach ([1, 2, 3] as $pid) {
            $this->seed('posts', ['id' => $pid, 'user_id' => $pid, 'post_type' => 'normal', 'content' => "c{$pid}", 'created_at' => "2026-01-0{$pid} 00:00:00"]);
        }
        foreach ([
            [1, 1, 'home', 2, 'r1'],
            [2, 2, 'home', 1, 'r2'],
            [3, 3, 'circle', 1, 'r3'],
        ] as [$id, $postId, $slot, $sort, $remark]) {
            $this->seed('featured_posts', [
                'id' => $id, 'post_id' => $postId, 'user_id' => 1, 'slot' => $slot,
                'sort_order' => $sort, 'remark' => $remark,
                'start_at' => '2026-01-01 00:00:00', 'end_at' => null, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        $expected = $this->originalFeaturedList('home');
        $actual = AdminRepository::fetchFeaturedList('home');
        $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), '推荐位排序 sort_order ASC, id ASC 不得变化');
        $this->assertArrayHasKey('post_type', $actual[0]);
        $this->assertSame([], AdminRepository::fetchFeaturedList('not-a-slot'), '空结果负例');
        $this->assertSame(1, count(AdminRepository::fetchFeaturedList('circle')));

        $exist = AdminRepository::findFeaturedByPostAndSlot(2, 'home');
        $this->assertSame(2, (int)$exist['id']);
        $this->assertNull(AdminRepository::findFeaturedByPostAndSlot(2, 'circle'));

        AdminRepository::deleteFeaturedById(2);
        $this->assertSame([1, 3], array_column($this->rows('featured_posts'), 'id'), '只删除指定 slot 的推荐记录');

        $newId = AdminRepository::insertFeaturedRow(3, 1, 'home', 5, null, '2026-02-01 00:00:00');
        $inserted = $this->rowById('featured_posts', $newId);
        $this->assertSame(3, (int)$inserted['post_id']);
        $this->assertSame('home', $inserted['slot']);
        $this->assertSame(5, (int)$inserted['sort_order']);
        $this->assertNull($inserted['remark']);
        $this->assertNull($inserted['end_at']);
        $this->assertSame('2026-02-01 00:00:00', $inserted['start_at']);
    }

    // ==================== 评论管理（与 posts 列表字段集交叉断言） ====================

    public function testAdminCommentsListDeleteAndCrossDomainFieldSets(): void
    {
        $this->assertSame([], AdminRepository::fetchAdminComments(0, 20));
        $this->assertSame(0, AdminRepository::countAllComments());

        $this->seed('users', ['id' => 1, 'username' => 'author', 'nickname' => '作者', 'avatar' => '/a.jpg']);
        $this->seed('posts', [
            'id' => 10, 'user_id' => 1, 'post_type' => 'poll', 'content' => 'post body',
            'status' => 1, 'created_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([
            [1, 10, 1, 0, 'first', '2026-03-01 00:00:00'],
            [2, 10, 1, 1, 'child', '2026-03-02 00:00:00'],
            [3, 10, 1, 0, 'second', '2026-03-03 00:00:00'],
        ] as [$id, $postId, $uid, $parent, $content, $created]) {
            $this->seed('comments', [
                'id' => $id, 'post_id' => $postId, 'user_id' => $uid,
                'parent_id' => $parent ?: null, 'content' => $content, 'created_at' => $created,
            ]);
        }

        foreach ([[0, 20], [0, 1], [1, 1], [2, 1]] as [$offset, $limit]) {
            $expected = $this->originalAdminComments($offset, $limit);
            $actual = AdminRepository::fetchAdminComments($offset, $limit);
            $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), '后台评论列表/分页/排序必须与 HEAD 原文一致');
            if ($expected !== []) {
                $expectedKeys = array_keys($expected[0]);
                $actualKeys = array_keys($actual[0]);
                $this->assertSame('post_type', array_pop($actualKeys));
                $this->assertSame($expectedKeys, $actualKeys);
            }
        }
        $this->assertSame(3, AdminRepository::countAllComments());

        $commentRow = AdminRepository::fetchAdminComments(0, 1)[0];
        $postRow = AdminRepository::fetchAdminPosts(-1, '', 0, 0, 20)[0];
        // 陷阱一：后台评论列表带 post_content/post_type；后台 posts 列表带 category_name/images/videos。
        // 两者都查 posts/users，但字段集完全不同，任一方法串用都会在这里 FAIL。
        $this->assertArrayHasKey('post_content', $commentRow);
        $this->assertArrayHasKey('post_type', $commentRow);
        $this->assertArrayNotHasKey('category_name', $commentRow);
        $this->assertArrayNotHasKey('images', $commentRow);
        $this->assertArrayHasKey('category_name', $postRow);
        $this->assertArrayHasKey('images', $postRow);
        $this->assertArrayHasKey('videos', $postRow);
        $this->assertArrayNotHasKey('post_content', $postRow);

        $this->assertSame(10, (int)AdminRepository::findCommentPostId(1)['post_id']);
        $this->assertNull(AdminRepository::findCommentPostId(999));
        AdminRepository::deleteCommentWithChildren(1);
        $this->assertSame([3], array_column($this->rows('comments'), 'id'), '主评论与子评论一并删除');
    }

    // ==================== 导出（六种口径互不复用） ====================

    public function testExportQueriesKeepDistinctFieldSetsAndOrders(): void
    {
        // 空库
        $this->assertSame([], AdminRepository::fetchExportUsersOrdered());
        $this->assertSame([], AdminRepository::fetchExportCommentsAll());

        foreach ([[3, 'carol'], [1, 'alice'], [2, 'bob']] as [$id, $name]) {
            $this->seed('users', ['id' => $id, 'username' => $name, 'nickname' => $name, 'email' => "{$name}@x.com", 'role' => 'user', 'status' => 1, 'created_at' => '2026-01-01 00:00:00']);
        }
        foreach ([[3, 'p3', 0], [1, 'p1', 1], [2, 'p2', 1]] as [$id, $title, $status]) {
            $this->seed('posts', ['id' => $id, 'user_id' => 1, 'title' => $title, 'content' => "c{$id}", 'status' => $status, 'view_count' => $id * 10, 'created_at' => '2026-01-01 00:00:00', 'post_type' => 'normal']);
        }
        foreach ([[3, 0], [1, 1], [2, 0]] as [$id, $isAi]) {
            $this->seed('comments', ['id' => $id, 'post_id' => $id, 'user_id' => 1, 'content' => "c{$id}", 'is_ai' => $isAi, 'created_at' => '2026-01-01 00:00:00']);
        }

        $pairs = [
            [$this->originalExportUsers(true), AdminRepository::fetchExportUsersOrdered()],
            [$this->originalExportUsers(false), AdminRepository::fetchExportUsersAll()],
            [$this->originalExportPosts(true), AdminRepository::fetchExportPostsOrdered()],
            [$this->originalExportPosts(false), AdminRepository::fetchExportPostsAll()],
            [$this->originalExportComments(true), AdminRepository::fetchExportCommentsOrdered()],
            [$this->originalExportComments(false), AdminRepository::fetchExportCommentsAll()],
        ];
        foreach ($pairs as $i => [$expected, $actual]) {
            // posts 行查询追加 post_type：原列顺序保持，末列单独断言
            if ($i === 2 || $i === 3) {
                $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), "导出 posts 第 {$i} 组 ID 顺序");
                $expectedKeys = array_keys($expected[0]);
                $actualKeys = array_keys($actual[0]);
                $this->assertSame('post_type', array_pop($actualKeys));
                $this->assertSame($expectedKeys, $actualKeys, "导出 posts 第 {$i} 组原有字段顺序");
            } else {
                $this->assertSame($expected, $actual, "导出第 {$i} 组字段/顺序必须与 HEAD 原文一致");
            }
        }

        // 陷阱一回归：有序 comments 含 is_ai，all 分支不含；字段集不可互相串用
        $ordered = AdminRepository::fetchExportCommentsOrdered();
        $all = AdminRepository::fetchExportCommentsAll();
        $this->assertSame(['id', 'post_id', 'user_id', 'content', 'is_ai', 'created_at'], array_keys($ordered[0]));
        $this->assertSame(['id', 'post_id', 'user_id', 'content', 'created_at'], array_keys($all[0]));
        $this->assertSame([1, 2, 3], array_column($ordered, 'id'), '有序导出必须 ORDER BY id');
        $this->assertSame(array_column($this->originalExportUsers(false), 'id'), array_column(AdminRepository::fetchExportUsersAll(), 'id'), 'all 分支无 ORDER BY');
    }

    // ==================== 运营统计 ====================

    public function testStatsQueriesMatchOriginalWithSqliteDialectShims(): void
    {
        $this->assertSame([], AdminRepository::fetchPostsTrend('2026-01-01', '2026-01-02'));
        $this->assertSame([], AdminRepository::fetchViewsTrend('2026-01-01', '2026-01-02'));
        $this->assertSame([], AdminRepository::fetchTopicStats());

        $this->seed('users', ['id' => 1, 'username' => 'u1', 'status' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $this->seed('users', ['id' => 2, 'username' => 'u2', 'status' => 0, 'created_at' => '2026-01-02 00:00:00']);
        $this->seed('users', ['id' => 3, 'username' => 'u3', 'status' => 1, 'created_at' => '2099-01-01 00:00:00']);
        foreach ([[1, '2026-01-01 08:00:00'], [2, '2026-01-01 09:00:00'], [3, '2026-01-02 08:00:00'], [4, date('Y-m-d') . ' 08:00:00']] as [$id, $created]) {
            $this->seed('posts', ['id' => $id, 'user_id' => 1, 'view_count' => $id * 5, 'created_at' => $created, 'post_type' => 'normal']);
        }
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'created_at' => date('Y-m-d') . ' 09:00:00']);
        $this->seed('categories', ['id' => 1, 'name' => '生活', 'status' => 1, 'sort_order' => 2]);
        $this->seed('categories', ['id' => 2, 'name' => '摄影', 'status' => 1, 'sort_order' => 1]);
        foreach ([['2026-01-01', 2], ['2026-01-02', 8], ['2099-01-01', 3], ['2025-12-31', 1]] as [$date, $count]) {
            $this->seed('post_view_logs', ['view_date' => $date, 'post_id' => 1, 'count' => $count]);
        }
        foreach ([['A', 1, 9], ['B', 1, 3], ['C', 0, 99], ['D', 1, 6]] as [$name, $status, $count]) {
            $this->seed('topics', ['name' => $name, 'status' => $status, 'post_count' => $count]);
        }

        $this->assertSame($this->originalPostsTrend('2026-01-01', '2026-01-02'), AdminRepository::fetchPostsTrend('2026-01-01', '2026-01-02'));
        $this->assertSame($this->originalViewsTrend('2026-01-01', '2026-01-02'), AdminRepository::fetchViewsTrend('2026-01-01', '2026-01-02'));
        $this->assertSame($this->originalUserGrowth('2026-01-01', '2026-01-02'), AdminRepository::fetchUserGrowth('2026-01-01', '2026-01-02'));
        $this->assertSame($this->originalNewUsers30d(), AdminRepository::countNewUsers30d());
        $this->assertSame($this->originalActiveUsersCount(), AdminRepository::fetchActiveUsersCount());
        $this->assertSame($this->pdo()->query("SELECT c.name, COUNT(p.id) as count FROM categories c LEFT JOIN posts p ON c.id = p.category_id AND p.status = 1 GROUP BY c.id, c.name ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC), AdminRepository::fetchCategoryStats());
        $this->assertSame($this->pdo()->query("SELECT name, post_count FROM topics WHERE status = 1 ORDER BY post_count DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC), AdminRepository::fetchTopicStats());
        $this->assertSame((int)$this->scalar("SELECT SUM(view_count) FROM posts"), AdminRepository::countTotalViews());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM users WHERE status = 1"), AdminRepository::countActiveUsers());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM posts WHERE status = 1"), AdminRepository::countActivePosts());
        $this->assertSame($this->scalar("SELECT COUNT(*) FROM comments"), AdminRepository::countAllComments());
    }

    // ==================== 话题管理 ====================

    public function testTopicQueriesAndWritesMatchOriginal(): void
    {
        foreach ([['B', 1, 3], ['A', 1, 9], ['C', 0, 99]] as [$name, $status, $count]) {
            $this->seed('topics', ['name' => $name, 'slug' => strtolower($name), 'status' => $status, 'post_count' => $count]);
        }
        $expected = $this->pdo()->query("SELECT * FROM topics ORDER BY post_count DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame($expected, AdminRepository::fetchAllTopics());
        $this->assertSame(['C', 'A', 'B'], array_column(AdminRepository::fetchAllTopics(), 'name'));

        AdminRepository::updateTopicFields(1, [
            'name' => 'A2', 'slug' => 'a2', 'description' => 'd', 'status' => 0, 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $updated = $this->rowById('topics', 1);
        $this->assertSame('A2', $updated['name']);
        $this->assertSame(0, (int)$updated['status']);
        $this->assertNull($updated['cover'], '未传 cover 时不得清空/误写');

        $newId = AdminRepository::insertTopic('新话题', 'new', 'desc', '/cover.jpg', 1, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $inserted = $this->rowById('topics', $newId);
        $this->assertSame('新话题', $inserted['name']);
        $this->assertSame('/cover.jpg', $inserted['cover']);
        $this->assertSame('2026-01-01 00:00:00', $inserted['created_at']);

        AdminRepository::setTopicCover(2, '/new-cover.jpg', '2026-06-02 00:00:00');
        $this->assertSame('/new-cover.jpg', $this->rowById('topics', 2)['cover']);
        AdminRepository::deleteTopic(3);
        $this->assertSame([1, 2, $newId], array_column($this->rows('topics'), 'id'));

        try {
            AdminRepository::updateTopicFields(1, ['name = ?' => 'x']);
            $this->fail('非法列名应被拒绝');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('非法字段名', $e->getMessage());
        }
    }

    // ==================== SettingsRepository 新方法 ====================

    public function testSettingsRepositoryNewMethodsMatchOriginalSql(): void
    {
        $this->seed('settings', ['key' => 'site_name', 'value' => '我圈', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->seed('settings', ['key' => 'upload_compress', 'value' => '0', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->seed('categories', ['id' => 2, 'parent_id' => null, 'name' => '摄影', 'status' => 1, 'sort_order' => 1]);
        $this->seed('categories', ['id' => 1, 'parent_id' => null, 'name' => '生活', 'status' => 1, 'sort_order' => 2]);
        $this->seed('categories', ['id' => 3, 'parent_id' => null, 'name' => '隐藏', 'status' => 0, 'sort_order' => 0]);

        $raw = $this->pdo()->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame($raw, SettingsRepository::fetchKeyValueRows());
        $this->assertTrue(SettingsRepository::existsByKey('site_name'));
        $this->assertFalse(SettingsRepository::existsByKey('not_exist'));
        $this->assertSame('0', SettingsRepository::fetchValueByKey('upload_compress'));
        $this->assertFalse(SettingsRepository::fetchValueByKey('not_exist'));

        // 既有方法零回归：all() 仍返回 key=>value 映射
        $this->assertSame('我圈', SettingsRepository::all()['site_name']);

        $this->assertSame(
            $this->pdo()->query("SELECT id, parent_id, name, sort_order FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC),
            SettingsRepository::fetchActiveCategories()
        );
        $this->assertSame([2, 1], array_column(SettingsRepository::fetchActiveCategories(), 'id'));

        // upsert：首次插入 + 再次更新，与 HEAD 的先查后写逐字段一致
        SettingsRepository::upsert('homepage_cover', '/cover-1.jpg', '2026-02-01 00:00:00');
        $inserted = $this->rowByKey('settings', 'homepage_cover');
        $this->assertSame('/cover-1.jpg', $inserted['value']);
        $this->assertSame('2026-02-01 00:00:00', $inserted['created_at']);
        $this->assertSame('2026-02-01 00:00:00', $inserted['updated_at']);

        SettingsRepository::upsert('homepage_cover', '/cover-2.jpg', '2026-03-01 00:00:00');
        $updated = $this->rowByKey('settings', 'homepage_cover');
        $this->assertSame('/cover-2.jpg', $updated['value']);
        $this->assertSame('2026-02-01 00:00:00', $updated['created_at'], '更新时不得重写 created_at');
        $this->assertSame('2026-03-01 00:00:00', $updated['updated_at']);
        $this->assertSame(1, count(array_filter($this->rows('settings'), static fn(array $r): bool => $r['key'] === 'homepage_cover')));
    }

    // ==================== HEAD 原始 SQL 快照 ====================

    /** AdminController::posts() 列表原文（LIMIT 拼接方式也保持） */
    private function originalAdminPosts(int $status, string $keyword, int $categoryId, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status >= 0) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($keyword) {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)';
            $params = array_merge($params, ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $listSql = "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.status, p.is_pinned, p.pinned_at, p.created_at, u.username, u.nickname, u.avatar, c.name AS category_name FROM posts p INNER JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id {$where} ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC";
        $stmt = $this->pdo()->prepare($listSql . ' LIMIT ?, ?');
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** AdminController::posts() 计数原文 */
    private function originalAdminPostsCount(int $status, string $keyword, int $categoryId): int
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status >= 0) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($keyword) {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)';
            $params = array_merge($params, ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"]);
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $countSql = "SELECT COUNT(*) FROM posts p INNER JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id {$where}";
        $stmt = $this->pdo()->prepare($countSql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** AdminController::featuredList() 原文 */
    private function originalFeaturedList(string $slot): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT f.id, f.post_id, f.slot, f.sort_order, f.remark, f.start_at, f.end_at,
                p.content, p.created_at, u.username, u.nickname, u.avatar
             FROM featured_posts f
             LEFT JOIN posts p ON p.id = f.post_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE f.slot = ?
             ORDER BY f.sort_order ASC, f.id DESC"
        );
        $stmt->execute([$slot]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** AdminController::comments() 传给 paginate() 的 listSql 原文 */
    private function originalAdminComments(int $offset, int $limit): array
    {
        $sql = "SELECT c.id, c.content, c.created_at, c.post_id, u.username, u.nickname, u.avatar, p.content as post_content FROM comments c INNER JOIN users u ON c.user_id = u.id INNER JOIN posts p ON c.post_id = p.id ORDER BY c.created_at DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute([$offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** AdminToolsController::exportData() 六条 SQL 原文 */
    private function originalExportUsers(bool $ordered): array
    {
        $sql = "SELECT id, username, nickname, email, role, status, created_at FROM users" . ($ordered ? ' ORDER BY id' : '');
        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalExportPosts(bool $ordered): array
    {
        $sql = "SELECT id, user_id, title, content, status, view_count, created_at FROM posts" . ($ordered ? ' ORDER BY id' : '');
        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalExportComments(bool $ordered): array
    {
        $sql = $ordered
            ? "SELECT id, post_id, user_id, content, is_ai, created_at FROM comments ORDER BY id"
            : "SELECT id, post_id, user_id, content, created_at FROM comments";
        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** AdminToolsController::stats() —— HEAD 原文，方言改写由 AdminDialectPdo::prepare() 完成 */
    private function originalPostsTrend(string $startDate, string $endDate): array
    {
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count FROM posts WHERE created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at) ORDER BY date";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalViewsTrend(string $startDate, string $endDate): array
    {
        $sql = "SELECT view_date as date, COUNT(*) as count FROM post_view_logs WHERE view_date BETWEEN ? AND ? GROUP BY view_date ORDER BY date";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalUserGrowth(string $startDate, string $endDate): array
    {
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count FROM users WHERE created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at) ORDER BY date";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalNewUsers30d(): int
    {
        return $this->scalar("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    private function originalActiveUsersCount(): int
    {
        return (int)$this->pdo()->query(
            "SELECT COUNT(DISTINCT user_id) FROM posts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) UNION SELECT COUNT(DISTINCT user_id) FROM comments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();
    }

    private function scalar(string $sql): int
    {
        return (int)$this->pdo()->query($sql)->fetchColumn();
    }

    private function rowById(string $table, int $id): array
    {
        foreach ($this->rows($table) as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        $this->fail("{$table} 中找不到 id={$id}");
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

/**
 * 仅用于测试：把 MySQL 专有的日期语法在 prepare 阶段改写成 SQLite 等价形式。
 * 产品代码中的 SQL 不受影响，HEAD 原文快照也照原样传入本类。
 */
final class AdminDialectPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->rewrite($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function rewrite(string $sql): string
    {
        return str_replace(
            ['? + INTERVAL 1 DAY', 'DATE_SUB(NOW(), INTERVAL 30 DAY)'],
            ["date(?, '+1 day')", "datetime('now', '-30 days')"],
            $sql
        );
    }
}
