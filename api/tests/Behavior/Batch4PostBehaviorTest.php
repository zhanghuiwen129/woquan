<?php
/**
 * 批次四 · 帖子域 SQL 收敛对照测试（PostFeed / PostUpdate / PostUpload）
 *
 * 对照法：HEAD 原文 SQL 原样保存在本文件的 original*() 私有方法里，在同一个
 * SQLite 内存库中与下沉后的 Repository 结果 assertSame 逐字段比对。
 * 覆盖空结果 / 单行 / 多页 / 软删与可见性负例 / 事务成功与异常回滚 /
 * post_type 铁律 / 相似 SQL 字段集差异交叉断言 / 入口守卫下沉断言。
 *
 * MySQL 方言：产品 Repository 的 SQL 保持 HEAD 原文（含 DATE_SUB/NOW/JSON_LENGTH），
 * 本测试用只 override prepare()/query() 的 PostDialectPdo 在「执行前」改写
 * INTERVAL 语法并注册等价函数；原始 SQL 快照仍以 HEAD 文本形式保留。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\PostRepository;
use Hublog\Core\UploadRepository;
use Hublog\Core\Database;
use PDO;
use PDOStatement;
use ReflectionProperty;

final class Batch4PostBehaviorTest extends BehaviorTestCase
{
    private ?PostDialectPdo $dialectPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 复制底座 schema，再把 uploads 换成与 MySQL 一致的结构：
        // 底座 uploads.file_hash NOT NULL 会让 recordUploads 的 INSERT 分支失真。
        $copy = new PostDialectPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $schemaSql = $this->pdo()->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($schemaSql as $sql) {
            $copy->exec((string)$sql);
        }
        $copy->exec('DROP TABLE `uploads`');
        $copy->exec(<<<'SQL'
CREATE TABLE `uploads` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER DEFAULT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `type`       TEXT    DEFAULT 'image',
    `filename`   TEXT    DEFAULT NULL,
    `url`        TEXT    DEFAULT NULL,
    `size`       INTEGER NOT NULL DEFAULT 0,
    `file_hash`  TEXT    DEFAULT NULL,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `expires_at` TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL,
    `updated_at` TEXT    DEFAULT NULL
);
SQL);
        foreach ([
            'deleted_at TEXT DEFAULT NULL',
            'allow_mention INTEGER NOT NULL DEFAULT 1',
            'realname_verified INTEGER NOT NULL DEFAULT 0',
            'phone_verified_at TEXT DEFAULT NULL',
        ] as $column) {
            $copy->exec('ALTER TABLE users ADD COLUMN ' . $column);
        }
        $copy->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `category_id`   INTEGER DEFAULT NULL,
    `title`         TEXT    DEFAULT NULL,
    `content`       TEXT    DEFAULT NULL,
    `images`        TEXT    DEFAULT NULL,
    `videos`        TEXT    DEFAULT NULL,
    `attachments`   TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL,
    `updated_at`    TEXT    DEFAULT NULL,
    `status`        INTEGER NOT NULL DEFAULT 1,
    `audit_state`   INTEGER NOT NULL DEFAULT 0,
    `scheduled_at`  TEXT    DEFAULT NULL,
    `is_pinned`     INTEGER NOT NULL DEFAULT 0,
    `pinned_at`     TEXT    DEFAULT NULL,
    `location`      TEXT    DEFAULT NULL,
    `location_lat`  REAL    DEFAULT NULL,
    `location_lng`  REAL    DEFAULT NULL,
    `is_anonymous`  INTEGER NOT NULL DEFAULT 0,
    `declaration`   TEXT    DEFAULT NULL,
    `post_type`     TEXT    DEFAULT 'normal',
    `visibility`    TEXT    DEFAULT 'public',
    `circle_id`     INTEGER DEFAULT NULL,
    `origin_id`     INTEGER DEFAULT NULL,
    `like_count`    INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `removed_at`    TEXT    DEFAULT NULL
);
CREATE TABLE `categories` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `parent_id` INTEGER DEFAULT NULL,
    `name`      TEXT    DEFAULT NULL,
    `status`    INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `post_tags` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `tag`        TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `likes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `comments` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `parent_id`  INTEGER DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `image`      TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `follows` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id`  INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `status`    INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `circles` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`            TEXT    DEFAULT NULL,
    `owner_id`        INTEGER NOT NULL DEFAULT 0,
    `status`          INTEGER NOT NULL DEFAULT 1,
    `post_permission` TEXT    DEFAULT 'all',
    `limited`         INTEGER NOT NULL DEFAULT 0,
    `pay_type`        INTEGER NOT NULL DEFAULT 0,
    `removed_at`      TEXT    DEFAULT NULL,
    `post_count`      INTEGER NOT NULL DEFAULT 0,
    `updated_at`      TEXT    DEFAULT NULL
);
CREATE TABLE `favorites` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL
);
CREATE TABLE `topics` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`       TEXT    DEFAULT NULL,
    `slug`       TEXT    DEFAULT NULL,
    `description` TEXT   DEFAULT NULL,
    `cover`      TEXT    DEFAULT NULL,
    `post_count` INTEGER NOT NULL DEFAULT 0,
    `status`     INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `feed_feedback` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`      INTEGER NOT NULL,
    `action`       TEXT    DEFAULT NULL,
    `target_type`  TEXT    DEFAULT NULL,
    `target_value` TEXT    DEFAULT NULL,
    `created_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `mentions` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`     INTEGER NOT NULL,
    `comment_id`  INTEGER DEFAULT NULL,
    `user_id`     INTEGER NOT NULL,
    `mentioned_by` INTEGER NOT NULL,
    `type`        TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `sender_id`  INTEGER NOT NULL,
    `type`       TEXT    DEFAULT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `is_read`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
SQL);

        // MySQL 等价函数，保证 HEAD 原文快照在 SQLite 中可真实执行。
        $copy->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $copy->sqliteCreateFunction('JSON_LENGTH', static function ($value): int {
            if ($value === null || $value === '') {
                return 0;
            }
            $decoded = json_decode((string)$value, true);
            return is_array($decoded) ? count($decoded) : 0;
        }, 1);
        $copy->sqliteCreateFunction('LEAST', static fn(...$args): mixed => min($args));
        $copy->sqliteCreateFunction('GREATEST', static fn(...$args): mixed => max($args));
        $copy->sqliteCreateFunction('TIMESTAMPDIFF', static function (string $unit, ?string $start, ?string $end): int {
            if ($start === null || $end === null) {
                return 0;
            }
            $seconds = strtotime($end) - strtotime($start);
            return match (strtoupper($unit)) {
                'DAY' => intdiv($seconds, 86400),
                default => intdiv($seconds, 3600),
            };
        }, 3);

        $ref = new ReflectionProperty(Database::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $copy);
        $this->dialectPdo = $copy;
    }

    protected function pdo(): PDO
    {
        return $this->dialectPdo ?? parent::pdo();
    }

    // ==================== 信息流 ====================

    public function testTimelineIdsCountCursorAndPinnedShareWhere(): void
    {
        $this->seedUsersForFeed();
        $now = date('Y-m-d H:i:s');
        $this->seed('posts', ['id' => 1, 'user_id' => 1, 'content' => 'p1', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'is_pinned' => 1, 'pinned_at' => $now, 'created_at' => $now, 'category_id' => 10]);
        $this->seed('posts', ['id' => 2, 'user_id' => 2, 'content' => 'p2', 'post_type' => 'question', 'status' => 1, 'visibility' => 'public', 'created_at' => $now, 'category_id' => 10]);
        $this->seed('posts', ['id' => 3, 'user_id' => 1, 'content' => 'p3', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'private', 'created_at' => $now, 'category_id' => 10]);
        $this->seed('posts', ['id' => 4, 'user_id' => 1, 'content' => 'p4', 'post_type' => 'normal', 'status' => 0, 'visibility' => 'public', 'created_at' => $now, 'category_id' => 10]);

        [$visWhere, $params] = $this->guestWhere();
        $where = 'WHERE p.status = 1 AND ' . $visWhere;

        // 空结果：无匹配 username 时 where=1=0
        $this->assertSame([], PostRepository::fetchTimelinePostIds('WHERE 1=0', [], 0, 10));

        $expected = array_column($this->originalTimelineIds($where, $params, 0, 10), 'id');
        $actualRows = PostRepository::fetchTimelinePostIds($where, $params, 0, 10);
        $this->assertSame($expected, array_column($actualRows, 'id'), '置顶优先 + 时间倒序不得变化');
        $this->assertArrayHasKey('post_type', $actualRows[0], 'posts 行查询必须带 post_type');
        $this->assertSame([2], array_column(PostRepository::fetchTimelinePostIds($where, $params, 1, 10), 'id'), '第二页从 IDs 偏移而非跳过置顶');

        // 计数与列表同一 WHERE
        $this->assertSame($this->originalPostCount($where, $params), PostRepository::getPostCount($where, $params));
        $this->assertSame(2, PostRepository::getPostCount($where, $params), 'status=0 与 private 均不得计入');

        // 游标流（仅非置顶）与首屏置顶集合：ORDER BY 与 LIMIT 都不同，不能合并
        $streamWhere = $where . ' AND p.is_pinned = 0';
        $this->assertSame(
            array_column($this->originalCursorIds($streamWhere, $params, 10), 'id'),
            array_column(PostRepository::fetchCursorPostIds($streamWhere, $params, 10), 'id')
        );
        $this->assertSame(
            array_column($this->originalCursorPinnedIds($where, $params, 50), 'id'),
            array_column(PostRepository::fetchCursorPinnedPostIds($where, $params, 50), 'id')
        );
        $this->assertSame([1], array_column(PostRepository::fetchCursorPinnedPostIds($where, $params, 50), 'id'));
    }

    public function testPinnedLongFieldListKeepsExactColumnOrder(): void
    {
        $this->seedUsersForFeed();
        $now = date('Y-m-d H:i:s');
        $this->seed('posts', ['id' => 11, 'user_id' => 1, 'category_id' => 10, 'title' => '置顶', 'content' => 'hello', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'is_pinned' => 1, 'pinned_at' => $now, 'created_at' => $now]);
        $this->seed('posts', ['id' => 12, 'user_id' => 2, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'private', 'is_pinned' => 1, 'pinned_at' => $now, 'created_at' => $now]);

        [$visWhere, $visParams] = $this->guestWhere();
        $expected = $this->originalPinnedRows($visWhere, $visParams);
        $actual = PostRepository::fetchVisiblePinnedPosts($visWhere, $visParams);

        $this->assertCount(1, $actual, '私密置顶对游客不可见');
        $this->assertArrayHasKey('post_type', $actual[0], 'posts 行查询必须带 post_type');
        foreach ($actual as $index => $row) {
            $withPostType = $row;
            unset($withPostType['post_type']);
            $this->assertSame($expected[$index], $withPostType, '长字段清单顺序、u.id as uid 别名不得改变');
        }
    }

    public function testTopicIndexCursorPageAndLikeFallbackAreDistinct(): void
    {
        $this->seedUsersForFeed();
        $now = date('Y-m-d H:i:s');
        foreach ([21, 22, 23] as $id) {
            $this->seed('posts', ['id' => $id, 'user_id' => 1, 'content' => '#话题#', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => $now]);
            $this->seed('post_tags', ['id' => $id, 'post_id' => $id, 'tag' => '话题']);
        }
        $this->seed('posts', ['id' => 24, 'user_id' => 1, 'content' => '#话题#', 'post_type' => 'normal', 'status' => 0, 'visibility' => 'public', 'created_at' => $now]);
        $this->seed('post_tags', ['id' => 24, 'post_id' => 24, 'tag' => '话题']);
        $this->seed('posts', ['id' => 25, 'user_id' => 1, 'content' => '旧数据 #话题#', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => $now]);
        // 私有帖同样带话题标签：count 与 cursor 两条路径都必须过滤掉
        $this->seed('posts', ['id' => 26, 'user_id' => 1, 'content' => '#话题# 私密', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'private', 'created_at' => $now]);
        $this->seed('post_tags', ['id' => 26, 'post_id' => 26, 'tag' => '话题']);

        [$visWhere, $visParams] = $this->guestWhere();

        $this->assertSame(
            $this->originalTopicIndexCount('话题', $visWhere, $visParams),
            PostRepository::countTopicIndexPosts('话题', $visWhere, $visParams)
        );
        $this->assertSame(3, PostRepository::countTopicIndexPosts('话题', $visWhere, $visParams), 'status=0 与 private 的帖子不进索引计数');

        $cursorRows = PostRepository::fetchTopicPostIdsByCursor('话题', 26, 3, $visWhere, $visParams);
        $this->assertSame(
            array_column($this->originalTopicCursorIds('话题', 26, 3, $visWhere, $visParams), 'id'),
            array_column($cursorRows, 'id')
        );
        $this->assertSame([23, 22, 21], array_column($cursorRows, 'id'), 'cursor 路径不得返回 private 帖');
        $this->assertSame([23, 22, 21], array_column($cursorRows, 'id'), 'cursor 路径必须 id < cursor 且 LIMIT ?');
        $this->assertArrayHasKey('post_type', $cursorRows[0]);

        $pageRows = PostRepository::fetchTopicPostIdsByPage('话题', $visWhere, $visParams, 1, 2);
        $this->assertSame(
            array_column($this->originalTopicPageIds('话题', $visWhere, $visParams, 1, 2), 'id'),
            array_column($pageRows, 'id')
        );
        $this->assertSame([22, 21], array_column($pageRows, 'id'), '分页 LIMIT ?, ? 顺序为 offset, limit');
        $this->assertSame([], PostRepository::fetchTopicPostIdsByPage('不存在', $visWhere, $visParams, 0, 2));

        $pattern = '%#话题#%';
        $this->assertSame($this->originalTopicLikeCount($pattern, $visWhere, $visParams), PostRepository::countTopicLikePosts($pattern, $visWhere, $visParams));
        $likeRows = PostRepository::fetchTopicLikePostIds($pattern, $visWhere, $visParams, 0, 2);
        $this->assertSame(
            array_column($this->originalTopicLikeIds($pattern, $visWhere, $visParams, 0, 2), 'id'),
            array_column($likeRows, 'id')
        );
        $this->assertSame([25, 23], array_column($likeRows, 'id'), 'LIKE 兜底按 p.id DESC 而非 pt.post_id DESC');
    }

    public function testCategoryTreeCountAndPageShareWhere(): void
    {
        $this->seed('categories', ['id' => 1, 'parent_id' => null, 'name' => '根', 'status' => 1]);
        $this->seed('categories', ['id' => 2, 'parent_id' => 1, 'name' => '子A', 'status' => 1]);
        $this->seed('categories', ['id' => 3, 'parent_id' => 1, 'name' => '子B', 'status' => 0]);
        $now = date('Y-m-d H:i:s');
        $this->seed('posts', ['id' => 31, 'user_id' => 1, 'category_id' => 2, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => $now]);
        $this->seed('posts', ['id' => 32, 'user_id' => 1, 'category_id' => 3, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => $now]);
        $this->seed('posts', ['id' => 33, 'user_id' => 1, 'category_id' => 2, 'post_type' => 'normal', 'status' => 0, 'visibility' => 'public', 'created_at' => $now]);

        $this->assertSame($this->originalCategoryMeta(1), PostRepository::findCategoryForFeed(1));
        $this->assertSame($this->originalChildCategories(1), PostRepository::fetchActiveChildCategories(1));
        $this->assertSame([['id' => 2]], PostRepository::fetchActiveChildCategories(1), 'status=0 子类不展开');

        [$visWhere, $visParams] = $this->guestWhere();
        $ids = [1, 2];
        $this->assertSame($this->originalCategoryCount($ids, $visWhere, $visParams), PostRepository::countCategoryPosts($ids, $visWhere, $visParams));
        $this->assertSame(1, PostRepository::countCategoryPosts($ids, $visWhere, $visParams));
        $rows = PostRepository::fetchCategoryPostIds($ids, $visWhere, $visParams, 0, 10);
        $this->assertSame(
            array_column($this->originalCategoryIds($ids, $visWhere, $visParams, 0, 10), 'id'),
            array_column($rows, 'id')
        );
        $this->assertArrayHasKey('post_type', $rows[0]);
        $this->assertSame([], PostRepository::fetchCategoryPostIds([], $visWhere, $visParams, 0, 10), '空白名单守卫');
        $this->assertSame(0, PostRepository::countCategoryPosts([], $visWhere, $visParams));
    }

    public function testLatestIdsAndCheckNewKeepAggregatesAndUserGuard(): void
    {
        $now = date('Y-m-d H:i:s');
        for ($id = 40; $id <= 42; $id++) {
            $this->seed('posts', ['id' => $id, 'user_id' => $id % 2, 'post_type' => 'normal', 'status' => 1, 'created_at' => $now]);
            $this->seed('likes', ['id' => $id, 'post_id' => $id, 'user_id' => 1, 'created_at' => $now]);
            $this->seed('comments', ['id' => $id, 'post_id' => $id, 'user_id' => 1, 'created_at' => $now]);
        }
        $this->seed('posts', ['id' => 43, 'user_id' => 1, 'post_type' => 'normal', 'status' => 0, 'created_at' => $now]);

        $this->assertSame($this->originalLatestIds(), PostRepository::fetchLatestActivityIds());
        $this->assertSame('42', (string)PostRepository::fetchLatestActivityIds()[0]['latest'], 'MAX 聚合只取 status=1 的最大 ID');

        $this->assertSame($this->originalNewActivityCounts(null, 40, 40, 40), PostRepository::fetchNewActivityCounts(null, 40, 40, 40));
        $this->assertSame($this->originalNewActivityCounts(1, 40, 40, 40), PostRepository::fetchNewActivityCounts(1, 40, 40, 40));
        $withUser = PostRepository::fetchNewActivityCounts(1, 40, 40, 40);
        $all = PostRepository::fetchNewActivityCounts(null, 40, 40, 40);
        $this->assertSame(2, (int)$all[0]['cnt'], '无 user 条件时统计全部 status=1 且 id>40 的帖子');
        $this->assertNotSame($withUser[0]['cnt'], $all[0]['cnt'], 'user 条件的有无必须产生不同聚合口径');
        $this->assertSame(0, (int)PostRepository::fetchNewActivityCounts(999, 0, 0, 0)[0]['cnt'], '空结果负例');
    }

    public function testRecommendationKeepsTagSimilarAndFeedbackSignals(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 3600);
        $this->seedUsersForFeed();
        foreach ([50, 51, 52] as $id) {
            $this->seed('posts', ['id' => $id, 'user_id' => $id === 50 ? 2 : 3, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'content' => '内容', 'created_at' => $recent]);
        }
        // 我关注 2；给 51 点过赞；标签「php」命中 51 两次、50 一次
        $this->seed('follows', ['follower_id' => 1, 'following_id' => 2]);
        $this->seed('likes', ['id' => 1, 'post_id' => 51, 'user_id' => 1, 'created_at' => $recent]);
        $this->seed('post_tags', ['id' => 1, 'post_id' => 50, 'tag' => 'php']);
        $this->seed('post_tags', ['id' => 2, 'post_id' => 51, 'tag' => 'php']);
        $this->seed('post_tags', ['id' => 3, 'post_id' => 51, 'tag' => 'sql']);
        // 对 52 点过「不感兴趣」→ 必须从候选中剔除
        $this->seed('feed_feedback', ['user_id' => 1, 'action' => 'dislike', 'target_type' => 'post', 'target_value' => '52', 'created_at' => $recent]);

        $tags = ['php', 'sql'];
        $similar = [3];
        $expected = $this->originalRecommendedIds(1, 0, 10, $tags, $similar);
        $actual = PostRepository::fetchRecommendedPostIds(1, 0, 10, $tags, $similar);
        $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), '打分排序不得改变');
        foreach ($actual as $row) {
            $this->assertArrayHasKey('post_type', $row, 'posts 行查询必须带 post_type');
            $this->assertArrayHasKey('score', $row, 'score 列必须保留');
        }
        $this->assertNotContains(52, array_column($actual, 'id'), '负反馈目标必须被剔除');
        $this->assertCount(2, $actual);

        // 空标签 + 空相似用户 → CASE 片段退化为 0，参数不翻倍
        $cold = PostRepository::fetchRecommendedPostIds(1, 0, 10, [], []);
        $this->assertSame(array_column($this->originalRecommendedIds(1, 0, 10, [], []), 'id'), array_column($cold, 'id'));
        // 空标签/空相似用户时两个 CASE 片段退化为 0，只保留关注/点赞与时间衰减信号
        $coldScores = [];
        foreach ($cold as $row) {
            $coldScores[$row['id']] = $row['score'];
        }
        $this->assertSame([50 => 29.85, 51 => -0.15], $coldScores, '冷启动不得出现标签命中/相似用户加分，负反馈目标始终剔除');
        $this->assertSame([], PostRepository::fetchRecommendedPostIds(1, 100, 10, [], []), 'offset 越界空结果负例');
    }

    public function testInterestHotTagAndSimilarUserKeepEmptyGuards(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 3600);
        $this->seed('posts', ['id' => 60, 'user_id' => 1, 'post_type' => 'normal', 'status' => 1, 'created_at' => $recent]);
        $this->seed('likes', ['id' => 1, 'post_id' => 60, 'user_id' => 7, 'created_at' => $recent]);
        $this->seed('comments', ['id' => 1, 'post_id' => 60, 'user_id' => 8, 'created_at' => $recent]);
        $this->seed('post_tags', ['id' => 1, 'post_id' => 60, 'tag' => 'php']);
        $this->seed('post_tags', ['id' => 2, 'post_id' => 60, 'tag' => 'sql']);

        $this->assertSame($this->originalInterestTags(7), PostRepository::fetchMyInterestTagRows(7));
        $this->assertSame([], PostRepository::fetchMyInterestTagRows(999), '空结果负例');
        $this->assertSame($this->originalHotTags(5), PostRepository::fetchHotTagRows(5));
        $this->assertSame($this->originalSimilarUsers(['php', 'sql'], 7, 50), PostRepository::fetchSimilarUserRows(['php', 'sql'], 7, 50));
        $this->assertSame([], PostRepository::fetchSimilarUserRows([], 7, 50), '空 tags 守卫已下沉到仓储本体');
    }

    public function testFeedFeedbackReplaceKeepsNoTransactionSemantics(): void
    {
        PostRepository::replaceFeedFeedback(1, 'dislike', 'post', '100', '2026-01-01 00:00:00');
        $this->assertCount(1, $this->rows('feed_feedback'));
        PostRepository::replaceFeedFeedback(1, 'dislike', 'post', '100', '2026-01-02 00:00:00');
        $rows = $this->rows('feed_feedback');
        $this->assertCount(1, $rows, '重复点击只更新时间不新增行');
        $this->assertSame('2026-01-02 00:00:00', $rows[0]['created_at']);
    }

    public function testTopicListsAndPopularOrderKeepOriginalShapes(): void
    {
        $this->seed('topics', ['id' => 1, 'name' => 'PHP', 'slug' => 'php', 'post_count' => 9, 'status' => 1]);
        $this->seed('topics', ['id' => 2, 'name' => 'SQL', 'slug' => 'sql', 'post_count' => 3, 'status' => 1]);
        $this->seed('topics', ['id' => 3, 'name' => '隐藏', 'slug' => 'hidden', 'post_count' => 100, 'status' => 0]);
        $this->seed('post_tags', ['id' => 1, 'post_id' => 70, 'tag' => '热门']);
        $this->seed('post_tags', ['id' => 2, 'post_id' => 71, 'tag' => '热门']);
        $this->seed('posts', ['id' => 70, 'user_id' => 1, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => date('Y-m-d H:i:s')]);
        $this->seed('posts', ['id' => 71, 'user_id' => 1, 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => date('Y-m-d H:i:s')]);
        $this->seed('likes', ['id' => 1, 'post_id' => 71, 'user_id' => 2, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame($this->originalTopicSearch('%P%', 10), PostRepository::searchTopics('%P%', 10));
        $this->assertSame($this->originalTopicList(10), PostRepository::fetchTopics(10));
        $this->assertSame($this->originalHotTopicTags(10), PostRepository::fetchHotTopicTags(10));
        $this->assertSame(['name', 'post_count'], array_keys(PostRepository::fetchTopics(10)[0]));
        $this->assertSame([], PostRepository::searchTopics('%不存在%', 10), '空结果负例');

        [$visWhere, $visParams] = $this->guestWhere();
        foreach (['comments', 'likes', 'favorites'] as $type) {
            $expected = array_column($this->originalPopularIds($type, $visWhere, $visParams, 10), 'id');
            $actual = PostRepository::fetchPopularPostIds($type, $visWhere, $visParams, 10);
            $this->assertSame($expected, array_column($actual, 'id'), "排序类型 {$type} 不得漂移");
            $this->assertArrayHasKey('post_type', $actual[0]);
        }
        $this->assertSame([71, 70], array_column(PostRepository::fetchPopularPostIds('likes', $visWhere, $visParams, 10), 'id'));
    }

    // ==================== 上传 ====================

    public function testUploadOwnershipQueriesKeepTwoDistinctWhereShapes(): void
    {
        $this->seed('uploads', ['id' => 1, 'user_id' => 1, 'post_id' => null, 'type' => 'image', 'url' => '/uploads/a.png', 'filename' => 'a.png', 'status' => 1]);
        $this->seed('uploads', ['id' => 2, 'user_id' => 1, 'post_id' => 7, 'type' => 'image', 'url' => 'http://host/uploads/b.png', 'filename' => 'b.png', 'status' => 1]);
        $this->seed('uploads', ['id' => 3, 'user_id' => 2, 'post_id' => null, 'type' => 'image', 'url' => '/uploads/c.png', 'filename' => 'c.png', 'status' => 1]);

        $this->assertSame($this->originalOwnUnbound('/uploads/a.png', 1), UploadRepository::findOwnUnboundUploadId('/uploads/a.png', 1));
        $this->assertSame($this->originalOwnForEdit('http://host/uploads/b.png', 1, 7), UploadRepository::findOwnUploadIdForEdit('http://host/uploads/b.png', 1, 7));
        $this->assertSame($this->originalBoundToPost('http://host/uploads/b.png', 7), UploadRepository::findUploadBoundToPost('http://host/uploads/b.png', 7));
        $this->assertSame($this->originalCanonicalUnbound('http://host/uploads/a.png', 1), UploadRepository::findCanonicalUnboundUploadUrl('http://host/uploads/a.png', 1));
        $this->assertSame($this->originalCanonicalForEdit('http://host/uploads/b.png', 1, 7), UploadRepository::findCanonicalUploadUrlForEdit('http://host/uploads/b.png', 1, 7));

        // 相似 SQL 交叉断言：同一 URL 在发布态 / 编辑态必须给出不同结论
        $this->assertNull(UploadRepository::findOwnUnboundUploadId('http://host/uploads/b.png', 1), '已绑定 URL 不得通过发布态校验');
        $this->assertNotNull(UploadRepository::findOwnUploadIdForEdit('http://host/uploads/b.png', 1, 7), '同一 URL 在编辑态必须放行');
        $this->assertNull(UploadRepository::findOwnUploadIdForEdit('/uploads/c.png', 1, 7), '他人 URL 在任何口径都不可用');

        // 入口守卫下沉：非法协议 / 空串在仓储本体即被拒绝
        $this->assertNull(UploadRepository::findOwnUnboundUploadId('javascript:alert(1)', 1));
        $this->assertNull(UploadRepository::findCanonicalUnboundUploadUrl('', 1));
        $this->assertNull(UploadRepository::findCanonicalUploadUrlForEdit('data:text/plain,x', 1, 7));
        $this->assertNull(UploadRepository::findUploadBoundToPost('javascript:alert(1)', 7));
    }

    public function testUploadInsertMethodsKeepExactColumnSets(): void
    {
        $imageId = UploadRepository::insertImageUpload(1, 'i.png', '/uploads/i.png', 10, '', '2026-01-01 00:00:00');
        $attachId = UploadRepository::insertAttachmentUpload(1, 'a.zip', '/uploads/a.zip', 20, 'hash-a', '2026-01-01 00:00:00');
        $videoId = UploadRepository::insertVideoUpload(1, 'v.mp4', '/uploads/v.mp4', 30, '', '2026-01-01 00:00:00');
        $directId = UploadRepository::insertDirectRegisterUpload(1, 'image', 'd.png', '/uploads/d.png', 40, '2026-01-02 00:00:00', '2026-01-01 00:00:00');

        $rows = $this->rows('uploads');
        $this->assertCount(4, $rows);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $image = $byId[$imageId];
        $attachment = $byId[$attachId];
        $video = $byId[$videoId];
        $direct = $byId[$directId];

        $this->assertSame('image', $image['type']);
        $this->assertNull($image['file_hash'], '空 file_hash 归一化为 NULL（原表达式）');
        $this->assertSame('hash-a', $attachment['file_hash'], '附件 file_hash 原样入库');
        $this->assertSame('video', $video['type']);
        $this->assertNull($video['file_hash'], '视频 !empty 判定与原实现一致');
        $this->assertSame('2026-01-02 00:00:00', $direct['expires_at'], '直传记录多写 expires_at');
        $this->assertNull($image['expires_at']);
        $this->assertSame(40, (int)$direct['size'], '直传 size 使用 max(0, fileSize)');
        $this->assertSame(
            ['id', 'user_id', 'post_id', 'type', 'filename', 'url', 'size', 'file_hash', 'status', 'expires_at', 'created_at', 'updated_at'],
            array_keys($image),
            '登记 INSERT 列结构与 HEAD 逐字一致'
        );
    }

    public function testBindUploadsAndResetKeepUpdateInsertAndCoverBranches(): void
    {
        $this->seed('uploads', ['id' => 1, 'user_id' => 1, 'post_id' => null, 'type' => 'image', 'url' => '/uploads/existing.png', 'status' => 1, 'expires_at' => '2026-01-01 00:00:00']);
        $this->seed('uploads', ['id' => 2, 'user_id' => 2, 'post_id' => null, 'type' => 'image', 'url' => '/uploads/other.png', 'status' => 1]);
        $this->seed('uploads', ['id' => 3, 'user_id' => 1, 'post_id' => null, 'type' => 'image', 'url' => '/uploads/cover.jpg', 'status' => 1]);

        UploadRepository::bindUploads(1, 99, ['/uploads/existing.png', '/uploads/new.png'], [
            ['url' => '/uploads/v.mp4', 'cover' => '/uploads/cover.jpg', 'filename' => 'v.mp4'],
            ['url' => ''],
            [],
        ], [['url' => '/uploads/a.zip', 'filename' => 'a.zip', 'size' => 12]]);

        $rows = $this->rows('uploads');
        $byUrl = [];
        foreach ($rows as $row) {
            $byUrl[$row['url']] = $row;
        }
        $this->assertSame(99, (int)$byUrl['/uploads/existing.png']['post_id'], '命中未绑定记录走 UPDATE');
        $this->assertNull($byUrl['/uploads/existing.png']['expires_at'], '绑定时清空 expires_at');
        $this->assertSame(99, (int)$byUrl['/uploads/new.png']['post_id'], '未命中走 INSERT 兜底');
        $this->assertSame('new.png', $byUrl['/uploads/new.png']['filename']);
        $this->assertSame(99, (int)$byUrl['/uploads/cover.jpg']['post_id'], '视频封面图也要绑定');
        $this->assertSame(12, (int)$byUrl['/uploads/a.zip']['size'], '附件 size 取自请求');
        $this->assertNull($byUrl['/uploads/other.png']['post_id'], '他人上传不得被改动');
        $this->assertCount(6, $rows, '非法/空项被跳过，未产生多余记录');

        UploadRepository::resetAndBindPostUploads(1, 99, ['/uploads/again.png'], [], []);
        $rows = $this->rows('uploads');
        $bound = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['post_id'] === 99));
        $this->assertCount(1, $bound, '重绑前清空旧绑定，只留新提交的一张图');
        $this->assertSame('/uploads/again.png', $bound[0]['url']);
    }

    public function testOrphanAndExpiredCleanupQueriesKeepGuards(): void
    {
        $past = date('Y-m-d H:i:s', time() - 3 * 86400);
        $this->seed('uploads', ['id' => 1, 'user_id' => 1, 'post_id' => null, 'url' => '/uploads/old.png', 'expires_at' => $past, 'created_at' => $past]);
        $this->seed('uploads', ['id' => 2, 'user_id' => 1, 'post_id' => null, 'url' => '/uploads/recent.png', 'expires_at' => date('Y-m-d H:i:s', time() + 86400), 'created_at' => date('Y-m-d H:i:s')]);
        $this->seed('uploads', ['id' => 3, 'user_id' => 1, 'post_id' => 9, 'url' => '/uploads/bound.png', 'expires_at' => $past, 'created_at' => $past]);

        $cutoff = date('Y-m-d H:i:s', time() - 86400);
        $this->assertSame($this->originalOrphanUploads($cutoff, 1000), UploadRepository::fetchOrphanUploads($cutoff, 1000));
        $this->assertSame([1], array_map('intval', array_column(UploadRepository::fetchOrphanUploads($cutoff, 1000), 'id')), '已绑定文件不得进入孤儿清理');

        $this->assertSame(1, UploadRepository::deleteUploadsByUrls(['/uploads/old.png', '/uploads/bound.png']), '只删未绑定记录');
        $this->assertSame(0, UploadRepository::deleteUploadsByUrls([]), '空集合守卫');
        $this->assertSame([2, 3], array_map('intval', array_column($this->rows('uploads'), 'id')));

        $expired = UploadRepository::fetchExpiredUnboundUploads(date('Y-m-d H:i:s'), 999);
        $this->assertSame([], $expired, '过期未绑定记录已删，空结果负例');
        $this->assertSame(0, UploadRepository::deleteUploadsByIds([]), '空 ID 集合守卫');
        $this->assertSame(1, UploadRepository::deleteUploadsByIds([2]), '按主键删除');
    }

    // ==================== 编辑 / 事务 ====================

    public function testPostUpdateReadsKeepDistinctFieldSetsAndPostType(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seed('posts', ['id' => 80, 'user_id' => 1, 'post_type' => 'question', 'status' => 0, 'audit_state' => 1, 'scheduled_at' => null, 'circle_id' => 5, 'visibility' => 'circle', 'title' => '标题', 'created_at' => $now]);
        $this->seed('posts', ['id' => 81, 'user_id' => 2, 'post_type' => 'normal', 'status' => 0, 'audit_state' => 0, 'scheduled_at' => null, 'circle_id' => null, 'visibility' => 'public', 'created_at' => $now]);
        $this->seed('circles', ['id' => 5, 'owner_id' => 1, 'post_permission' => 'all', 'status' => 1, 'limited' => 1, 'removed_at' => null]);

        $forEdit = PostRepository::findPostForEdit(80);
        $this->assertSame($this->originalPostForEdit(80), $forEdit, '作者编辑回显字段清单不得变化');
        $this->assertArrayHasKey('post_type', $forEdit);
        $this->assertNull(PostRepository::findPostForEdit(81), '管理员已下架帖不对作者放行');

        $admin = PostRepository::findAnyPostForAdminEdit(81);
        $this->assertSame($this->originalAdminPostForEdit(81), $admin);
        $this->assertSame(
            [['id', 'user_id', 'status', 'audit_state', 'scheduled_at', 'circle_id', 'post_type'], ['id', 'user_id', 'status', 'circle_id', 'post_type']],
            [array_keys($forEdit), array_keys($admin)],
            '相似 SQL 字段集差异交叉断言：管理员兜底不能多带 audit_state/scheduled_at'
        );

        $status = PostRepository::findPostStatusAfterEdit(80);
        $this->assertSame($this->originalPostStatusAfterEdit(80), array_intersect_key($status, array_flip(['status', 'audit_state'])));
        $this->assertArrayHasKey('post_type', $status);
        $this->assertSame($this->originalPostCircleForVisibility(80), array_intersect_key(PostRepository::findPostCircleIdForVisibility(80), array_flip(['circle_id'])));
        $this->assertSame($this->originalPostVisibilityState(80), array_intersect_key(PostRepository::findPostVisibilityState(80), array_flip(['visibility', 'scheduled_at'])));
        $this->assertSame($this->originalPostTitleForTags(80), array_intersect_key(PostRepository::findPostTitleForTags(80), array_flip(['title'])));
        foreach ([PostRepository::findPostCircleIdForVisibility(80), PostRepository::findPostVisibilityState(80), PostRepository::findPostTitleForTags(80)] as $row) {
            $this->assertArrayHasKey('post_type', $row, '编辑路径的 posts 行查询必须带 post_type');
        }

        $repostCircle = PostRepository::findCircleForRepost(5);
        $postCircle = PostRepository::findCircleForPost(5);
        $this->assertSame($this->originalCircleForRepost(5), $repostCircle);
        $this->assertNotSame(array_keys($repostCircle), array_keys($postCircle), 'repost 与发帖门禁的圈子字段集必须分开');
        $this->assertArrayNotHasKey('limited', $repostCircle);
        $this->assertArrayHasKey('limited', $postCircle);

        $this->assertNull(PostRepository::findPostForRepost(80), 'status=0 的待审帖不可作为转发来源');
        $this->seed('posts', ['id' => 82, 'user_id' => 1, 'post_type' => 'question', 'status' => 1, 'audit_state' => 0, 'visibility' => 'public', 'created_at' => $now]);
        $origin = PostRepository::findPostForRepost(82);
        $this->assertArrayHasKey('post_type', $origin, 'SELECT p.* 已含 post_type');
        $this->assertSame('question', $origin['post_type']);
    }

    public function testPersistPostUpdateCommitsAllBranchesAndRollsBackAtomically(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'author', 'status' => 1]);
        $this->seed('users', ['id' => 2, 'username' => 'bob', 'status' => 1]);
        $this->seed('posts', ['id' => 90, 'user_id' => 1, 'content' => '旧内容', 'post_type' => 'normal', 'status' => 1, 'visibility' => 'public', 'created_at' => date('Y-m-d H:i:s')]);
        $this->seed('mentions', ['id' => 1, 'post_id' => 90, 'comment_id' => null, 'user_id' => 2, 'mentioned_by' => 1, 'type' => 'post']);
        $this->seed('uploads', ['id' => 1, 'user_id' => 1, 'post_id' => 90, 'url' => '/uploads/old.png', 'status' => 1]);
        $this->seed('post_tags', ['id' => 1, 'post_id' => 90, 'tag' => 'old']);

        $tagged = [];
        $refresh = static function (string $content) use (&$tagged): void {
            $tagged[] = $content;
        };

        PostRepository::persistPostUpdate(
            90,
            1,
            ['content' => '#新内容#', 'updated_at' => '2026-01-01 00:00:00'],
            ['bob'],
            ['/uploads/new.png'],
            [],
            [],
            $refresh
        );

        $post = $this->pdo()->query('SELECT content, updated_at FROM posts WHERE id = 90')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('#新内容#', $post['content'], 'posts 更新落库');
        $this->assertSame(['#新内容# '], $tagged, '标签刷新内容与调用顺序保持原实现');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM post_tags WHERE post_id = 90')->fetchColumn(), '旧标签先删除');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM mentions WHERE post_id = 90')->fetchColumn(), '旧提及删除后只留新提交的提及');
        $uploads = $this->rows('uploads');
        $this->assertSame(1, count($uploads), '旧绑定删除后只留新绑定');
        $this->assertSame(90, (int)$uploads[0]['post_id']);
        $this->assertSame('/uploads/new.png', $uploads[0]['url']);

        // 异常回滚：SQLite trigger 在 tags 刷新前打断，整条事务不得留下任何写入
        $this->pdo()->exec("CREATE TRIGGER fail_tags BEFORE INSERT ON post_tags BEGIN SELECT RAISE(ABORT, 'tag failed'); END");
        $this->expectException(\PDOException::class);
        try {
            PostRepository::persistPostUpdate(
                90,
                1,
                ['content' => '应回滚', 'updated_at' => '2026-02-02 00:00:00'],
                ['bob'],
                ['/uploads/rollback.png'],
                [],
                [],
                function (string $content): void {
                    // 真实 INSERT 命中上方 trigger 的 RAISE(ABORT)，验证事务原子性
                    $this->pdo()->exec("INSERT INTO post_tags (post_id, tag) VALUES (90, 'rollback')");
                }
            );
        } catch (\PDOException $e) {
            $this->assertSame('#新内容#', $this->pdo()->query('SELECT content FROM posts WHERE id = 90')->fetchColumn(), 'posts 写入必须回滚到失败前一次成功事务的状态');
            $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM mentions WHERE post_id = 90')->fetchColumn(), 'mentions 删除必须回滚到失败前状态');
            $this->assertSame('/uploads/new.png', $this->pdo()->query('SELECT url FROM uploads LIMIT 1')->fetchColumn(), 'uploads 重绑必须回滚');
            throw $e;
        }
    }

    public function testPersistPostUpdateRejectsUnknownColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PostRepository::persistPostUpdate(1, 1, ['not_a_column' => 'x'], null, [], [], [], static function (string $content): void {
        });
    }

    // ==================== HEAD 原始 SQL 快照（私有，仅供对照） ====================

    /** @return array{0:string,1:array} */
    private function guestWhere(): array
    {
        return ["(p.visibility = 'public')", []];
    }

    private function seedUsersForFeed(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->seed('users', [
                'id' => $id,
                'username' => 'u' . $id,
                'nickname' => '用户' . $id,
                'avatar' => null,
                'role' => 'user',
                'status' => 1,
                'vip_level' => 0,
                'avatar_frame' => null,
            ]);
        }
    }

    private function originalTimelineIds(string $where, array $params, int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p {$where} ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalPostCount(string $where, array $params): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM posts p {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function originalCursorIds(string $where, array $params, int $limit): array
    {
        $stmt = $this->pdo()->prepare("SELECT p.id FROM posts p {$where} ORDER BY p.id DESC LIMIT ?");
        $stmt->execute(array_merge($params, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCursorPinnedIds(string $where, array $params, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p {$where} AND p.is_pinned = 1 ORDER BY p.pinned_at DESC, p.id DESC LIMIT ?"
        );
        $stmt->execute(array_merge($params, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalPinnedRows(string $visWhere, array $visParams): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.created_at, p.updated_at, p.status, p.is_pinned, p.pinned_at,
                    p.location, p.location_lat, p.location_lng, p.is_anonymous, p.declaration,
                    u.id as uid, u.username, u.nickname, u.avatar, u.role, u.status as ustatus, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.status = 1 AND p.is_pinned = 1 AND {$visWhere}
             ORDER BY p.created_at DESC"
        );
        $stmt->execute($visParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalTopicIndexCount(string $topic, string $visWhere, array $visParams): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*)
             FROM post_tags pt
             INNER JOIN posts p ON p.id = pt.post_id
             WHERE pt.tag = ? AND p.status = 1 AND {$visWhere}"
        );
        $stmt->execute(array_merge([$topic], $visParams));
        return (int)$stmt->fetchColumn();
    }

    private function originalTopicCursorIds(
        string $topic,
        int $cursor,
        int $limit,
        string $visWhere,
        array $visParams
    ): array {
        $stmt = $this->pdo()->prepare(
            "SELECT pt.post_id AS id FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND {$visWhere} AND pt.post_id < ?
                     ORDER BY pt.post_id DESC LIMIT ?"
        );
        $stmt->execute(array_merge([$topic], $visParams, [$cursor, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalTopicPageIds(string $topic, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT pt.post_id AS id FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND {$visWhere}
                     ORDER BY pt.post_id DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge([$topic], $visParams, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalTopicLikeCount(string $pattern, string $visWhere, array $visParams): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM posts p WHERE p.status = 1 AND p.content LIKE ? AND {$visWhere}"
        );
        $stmt->execute(array_merge([$pattern], $visParams));
        return (int)$stmt->fetchColumn();
    }

    private function originalTopicLikeIds(string $pattern, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p WHERE p.status = 1 AND p.content LIKE ? AND {$visWhere} ORDER BY p.id DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge([$pattern], $visParams, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCategoryMeta(int $id): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT id, parent_id, name, status FROM categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalChildCategories(int $id): array
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM categories WHERE parent_id = ? AND status = 1");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCategoryCount(array $ids, string $visWhere, array $visParams): int
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM posts p WHERE p.status = 1 AND p.category_id IN ({$ph}) AND {$visWhere}"
        );
        $stmt->execute(array_merge($ids, $visParams));
        return (int)$stmt->fetchColumn();
    }

    private function originalCategoryIds(array $ids, string $visWhere, array $visParams, int $offset, int $limit): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p WHERE p.status = 1 AND p.category_id IN ({$ph}) AND {$visWhere} ORDER BY p.created_at DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge($ids, $visParams, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalLatestIds(): array
    {
        $sql = "SELECT 'post' AS t, MAX(id) AS latest FROM posts WHERE status = 1
                UNION ALL
                SELECT 'comment', MAX(c.id) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.status = 1
                UNION ALL
                SELECT 'like', MAX(l.id) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.status = 1";
        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalNewActivityCounts(?int $userId, int $sinceId, int $sinceCommentId, int $sinceLikeId): array
    {
        $postParams = [];
        $commentParams = [];
        $likeParams = [];
        if ($userId) {
            $postParams[] = $userId;
            $commentParams[] = $userId;
            $likeParams[] = $userId;
        }
        $postParams[] = $sinceId;
        $commentParams[] = $sinceCommentId;
        $likeParams[] = $sinceLikeId;
        $sql = "SELECT 'post' AS t, COUNT(*) AS cnt, MAX(id) AS latest_id FROM posts WHERE status = 1" . ($userId ? " AND user_id = ?" : "") . " AND id > ?
                UNION ALL
                SELECT 'comment', COUNT(*), MAX(c.id) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.status = 1" . ($userId ? " AND p.user_id = ?" : "") . " AND c.id > ?
                UNION ALL
                SELECT 'like', COUNT(*), MAX(l.id) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.status = 1" . ($userId ? " AND p.user_id = ?" : "") . " AND l.id > ?";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_merge($postParams, $commentParams, $likeParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalRecommendedIds(int $userId, int $offset, int $limit, array $tags, array $similarUserIds): array
    {
        $tagScore = '0';
        $tagParams = [];
        if ($tags) {
            $tagIn = implode(',', array_fill(0, count($tags), '?'));
            $sub = "(SELECT COUNT(DISTINCT tag) FROM post_tags pt WHERE pt.post_id = p.id AND pt.tag IN ({$tagIn}))";
            $tagScore = "(CASE WHEN {$sub} >= 2 THEN 18 WHEN {$sub} >= 1 THEN 9 ELSE 0 END)";
            $tagParams = array_merge($tags, $tags);
        }
        $similarScore = '0';
        $similarParams = [];
        if ($similarUserIds) {
            $simIn = implode(',', array_fill(0, count($similarUserIds), '?'));
            $similarScore = "(CASE WHEN p.user_id IN ({$simIn}) THEN 6 ELSE 0 END)";
            $similarParams = $similarUserIds;
        }
        $sql = "
            SELECT p.id,
                   (
                       (CASE WHEN p.user_id IN (SELECT f.following_id FROM follows f WHERE f.follower_id = ?) THEN 30 ELSE 0 END)
                     + (CASE WHEN p.user_id IN (SELECT l.user_id FROM likes l WHERE l.user_id = ?) THEN 8 ELSE 0 END)
                     + {$tagScore}
                     + {$similarScore}
                     - LEAST(TIMESTAMPDIFF(HOUR, p.created_at, NOW()), 168) * 0.15
                   ) AS score
            FROM posts p
            LEFT JOIN feed_feedback ff
                   ON ff.user_id = ? AND ff.action = 'dislike' AND ff.target_type = 'post'
                  AND CAST(ff.target_value AS UNSIGNED) = p.id
            LEFT JOIN feed_feedback fa
                   ON fa.user_id = ? AND fa.action = 'author'
                  AND CAST(fa.target_value AS UNSIGNED) = p.user_id
            WHERE p.status = 1
              AND p.visibility = 'public'
              AND p.user_id <> ?
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND ff.id IS NULL
              AND fa.id IS NULL
            ORDER BY score DESC, p.created_at DESC
            LIMIT ?, ?";
        $params = array_merge([$userId, $userId], $tagParams, $similarParams, [$userId, $userId, $userId]);
        $params[] = $offset;
        $params[] = $limit;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalInterestTags(int $userId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT pt.tag, COUNT(*) AS c
             FROM post_tags pt
             WHERE pt.post_id IN (
                 SELECT l.post_id FROM likes l WHERE l.user_id = ?
                 UNION
                 SELECT c.post_id FROM comments c WHERE c.user_id = ? AND c.post_id > 0
             )
             GROUP BY pt.tag
             ORDER BY c DESC, pt.tag ASC
             LIMIT 20"
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalHotTags(int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT pt.tag, COUNT(*) AS c
             FROM post_tags pt
             INNER JOIN posts p ON p.id = pt.post_id
             WHERE p.status = 1 AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY pt.tag
             ORDER BY c DESC, pt.tag ASC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalSimilarUsers(array $tags, int $excludeUserId, int $limit): array
    {
        if (empty($tags)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($tags), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT actor.user_id, COUNT(*) AS weight
             FROM (
                 SELECT l.user_id AS user_id FROM likes l
                 INNER JOIN post_tags pt ON pt.post_id = l.post_id
                 WHERE pt.tag IN ({$ph})
                 UNION ALL
                 SELECT c.user_id AS user_id FROM comments c
                 INNER JOIN post_tags pt ON pt.post_id = c.post_id
                 WHERE pt.tag IN ({$ph}) AND c.post_id > 0 AND c.user_id > 0
             ) actor
             WHERE actor.user_id <> ?
             GROUP BY actor.user_id
             ORDER BY weight DESC
             LIMIT ?"
        );
        $stmt->execute(array_merge($tags, $tags, [$excludeUserId, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalTopicSearch(string $like, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT name, post_count FROM topics
                     WHERE status = 1 AND name LIKE ?
                     ORDER BY post_count DESC, id ASC LIMIT ?"
        );
        $stmt->execute([$like, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalTopicList(int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT name, post_count FROM topics
                     WHERE status = 1
                     ORDER BY post_count DESC, id ASC LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalHotTopicTags(int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT pt.tag AS name, COUNT(*) AS post_count
                     FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id AND p.status = 1
                     GROUP BY pt.tag
                     ORDER BY post_count DESC, pt.tag ASC
                     LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalPopularIds(string $type, string $visWhere, array $visParams, int $limit): array
    {
        $orderField = match ($type) {
            'likes' => '(SELECT COUNT(*) FROM likes WHERE post_id = p.id)',
            'favorites' => '(SELECT COUNT(*) FROM favorites WHERE post_id = p.id)',
            default => '(SELECT COUNT(*) FROM comments WHERE post_id = p.id)',
        };
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p WHERE p.status = 1 AND {$visWhere} ORDER BY {$orderField} DESC, p.created_at DESC LIMIT ?"
        );
        $stmt->execute(array_merge($visParams, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalOwnUnbound(string $url, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? AND post_id IS NULL LIMIT 1"
        );
        $stmt->execute([$url, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalOwnForEdit(string $url, int $userId, int $postId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM uploads WHERE url = ? AND user_id = ? AND (post_id IS NULL OR post_id = ?) LIMIT 1"
        );
        $stmt->execute([$url, $userId, $postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalBoundToPost(string $url, int $postId): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM uploads WHERE post_id = ? AND (url = ? OR url = ?) LIMIT 1"
        );
        $stmt->execute([$postId, $url, $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalCanonicalUnbound(string $url, int $userId): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $stmt = $this->pdo()->prepare(
            "SELECT url FROM uploads
                 WHERE user_id = ? AND post_id IS NULL AND (url = ? OR url = ?)
                 ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$userId, $url, $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalCanonicalForEdit(string $url, int $userId, int $postId): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $stmt = $this->pdo()->prepare(
            "SELECT url FROM uploads
                 WHERE user_id = ? AND (post_id IS NULL OR post_id = ?) AND (url = ? OR url = ?)
                 ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$userId, $postId, $url, $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalOrphanUploads(string $cutoff, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, url, size FROM uploads
                 WHERE post_id IS NULL
                   AND (expires_at IS NOT NULL AND expires_at < NOW() OR created_at < ?)
                 LIMIT ?"
        );
        $stmt->execute([$cutoff, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalPostForEdit(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, user_id, status, audit_state, scheduled_at, circle_id, post_type FROM posts
             WHERE id = ? AND (
                    status = 1
                    OR (status = 0 AND scheduled_at IS NOT NULL)
                    OR (status = 0 AND audit_state = 1)
             ) LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalAdminPostForEdit(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, user_id, status, circle_id, post_type FROM posts WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalPostStatusAfterEdit(int $id): array
    {
        $stmt = $this->pdo()->prepare("SELECT status, audit_state FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function originalPostCircleForVisibility(int $id): array
    {
        $stmt = $this->pdo()->prepare("SELECT circle_id FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function originalPostVisibilityState(int $id): array
    {
        $stmt = $this->pdo()->prepare("SELECT visibility, scheduled_at FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function originalPostTitleForTags(int $id): array
    {
        $stmt = $this->pdo()->prepare("SELECT title FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function originalCircleForRepost(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, owner_id, post_permission, status FROM circles WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

/**
 * 只重写 MySQL 方言片段的 PDO 子类，保证产品 Repository 与 HEAD 快照
 * 都在同一份内存库上执行真实 SQL，而不是靠 PHP 侧模拟结果。
 */
final class PostDialectPdo extends PDO
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
            ['DATE_SUB(NOW(), INTERVAL 30 DAY)', 'TIMESTAMPDIFF(HOUR,'],
            ["datetime('now', '-30 days')", "TIMESTAMPDIFF('HOUR',"],
            $sql
        );
    }
}
