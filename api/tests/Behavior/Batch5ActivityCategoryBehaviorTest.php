<?php
/**
 * 批次五 · 活动 / 分类 / 搜索域迁移回归测试
 *
 * 对照法：每条被测查询都在本文件保存一份「迁移前 HEAD 原文 SQL」私有方法，
 * 在 SQLite 内存库中同时执行原始 SQL 与新 Repository 方法，assertSame 逐字段比对。
 * 覆盖空结果 / 单行 / 多页 / 停用与未报名负例 / 事务成功与 trigger 异常回滚 /
 * 前台与后台相似 SQL 字段集交叉断言 / 动态列名白名单守卫。
 *
 * 注意：本文件中的原始 SQL 只用于「执行后比对结果」，不用于任何源码文本断言。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\ActivityRepository;
use Hublog\Core\CategoryRepository;
use Hublog\Core\SearchRepository;
use InvalidArgumentException;
use PDO;
use PDOException;
use ReflectionMethod;

final class Batch5ActivityCategoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();

        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.STATISTICS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT)');
        $pdo->exec('ALTER TABLE uploads ADD COLUMN post_id INTEGER DEFAULT NULL');

        $pdo->exec(<<<'SQL'
CREATE TABLE `circles` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`         TEXT    DEFAULT NULL,
    `owner_id`     INTEGER DEFAULT 0,
    `member_count` INTEGER DEFAULT 0,
    `status`       INTEGER DEFAULT 1
);
CREATE TABLE `activities` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`    INTEGER DEFAULT NULL,
    `creator_id`   INTEGER DEFAULT 0,
    `title`        TEXT    DEFAULT NULL,
    `description`  TEXT    DEFAULT NULL,
    `cover`        TEXT    DEFAULT NULL,
    `type`         TEXT    DEFAULT 'signup',
    `mode`         TEXT    DEFAULT 'free',
    `capacity`     INTEGER DEFAULT 0,
    `checkin_goal` INTEGER DEFAULT 0,
    `start_at`     TEXT    DEFAULT NULL,
    `end_at`       TEXT    DEFAULT NULL,
    `status`       TEXT    DEFAULT 'open',
    `created_at`   TEXT    DEFAULT NULL,
    `updated_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `activity_registrations` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `activity_id` INTEGER NOT NULL,
    `user_id`     INTEGER NOT NULL,
    `status`      TEXT    DEFAULT 'joined',
    `note`        TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `categories` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `parent_id`   INTEGER DEFAULT NULL,
    `name`        TEXT    DEFAULT NULL,
    `sort_order`  INTEGER DEFAULT 0,
    `status`      INTEGER DEFAULT 1,
    `description` TEXT    DEFAULT NULL,
    `cover`       TEXT    DEFAULT NULL,
    `post_count`  INTEGER DEFAULT 0,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `category_follows` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER NOT NULL,
    `category_id` INTEGER NOT NULL,
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `posts` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `category_id`   INTEGER DEFAULT 0,
    `title`         TEXT    DEFAULT NULL,
    `content`       TEXT    DEFAULT NULL,
    `post_type`     TEXT    DEFAULT 'normal',
    `images`        TEXT    DEFAULT NULL,
    `videos`        TEXT    DEFAULT NULL,
    `attachments`   TEXT    DEFAULT NULL,
    `is_pinned`     INTEGER DEFAULT 0,
    `pinned_at`     TEXT    DEFAULT NULL,
    `is_anonymous`  INTEGER DEFAULT 0,
    `location`      TEXT    DEFAULT NULL,
    `visibility`    TEXT    DEFAULT 'public',
    `circle_id`     INTEGER DEFAULT NULL,
    `status`        INTEGER DEFAULT 1,
    `created_at`    TEXT    DEFAULT NULL,
    `updated_at`    TEXT    DEFAULT NULL
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
    `parent_id`  INTEGER DEFAULT 0,
    `content`    TEXT    DEFAULT NULL,
    `image`      TEXT    DEFAULT NULL,
    `is_ai`      INTEGER DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
SQL);
    }

    // ==================== 通用：执行 HEAD 原始 SQL 快照 ====================

    /** @return array<int,array<string,mixed>> */
    private function legacy(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function legacyOne(string $sql, array $params = []): ?array
    {
        $row = $this->legacy($sql, $params);
        return $row[0] ?? null;
    }

    private function legacyColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    // ==================== HEAD 快照：活动前台 ====================

    /** ActivityController::list 原始 SQL（WHERE 组装与 HEAD 一致，LIMIT 由 paginate 追加） */
    private function legacyFrontActivityList(
        int $circleId,
        string $type,
        string $status,
        string $keyword,
        int $offset,
        int $limit
    ): array {
        $where = 'WHERE 1=1';
        $params = [];
        if ($circleId > 0) {
            $where .= ' AND a.circle_id = ?';
            $params[] = $circleId;
        }
        if ($type) {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if ($status) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = "%{$keyword}%";
        }
        return $this->legacy(
            "SELECT a.id, a.circle_id, a.title, a.description, a.cover, a.type, a.mode, a.capacity,
                    a.start_at, a.end_at, a.status, a.created_at,
                    c.name AS circle_name,
                    (SELECT COUNT(*) FROM activity_registrations r
                      WHERE r.activity_id = a.id AND r.status = 'joined') AS joined_count
             FROM activities a
             LEFT JOIN circles c ON c.id = a.circle_id
             {$where}
             ORDER BY a.created_at DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    private function legacyFrontActivityCount(int $circleId, string $type, string $status, string $keyword): int
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($circleId > 0) {
            $where .= ' AND a.circle_id = ?';
            $params[] = $circleId;
        }
        if ($type) {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if ($status) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = "%{$keyword}%";
        }
        return (int)$this->legacyColumn("SELECT COUNT(*) FROM activities a {$where}", $params);
    }

    private function legacyActivityDetail(int $id): ?array
    {
        return $this->legacyOne(
            "SELECT a.*, c.name AS circle_name
             FROM activities a LEFT JOIN circles c ON c.id = a.circle_id
             WHERE a.id = ? LIMIT 1",
            [$id]
        );
    }

    // ==================== HEAD 快照：活动后台 ====================

    private function legacyAdminActivityList(
        string $keyword,
        string $type,
        string $status,
        array $owned,
        int $offset,
        int $limit
    ): array {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($type === 'signup') {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if (in_array($status, ['open', 'closed', 'ended'], true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if (!empty($owned)) {
            $ph = implode(',', array_fill(0, count($owned), '?'));
            $where .= " AND a.circle_id IN ({$ph})";
            $params = array_merge($params, $owned);
        }
        return $this->legacy(
            "SELECT a.id, a.circle_id, a.creator_id, a.title, a.description, a.cover,
                    a.type, a.mode, a.capacity, a.checkin_goal, a.start_at, a.end_at,
                    a.status, a.created_at,
                    c.name AS circle_name,
                    (SELECT COUNT(*) FROM activity_registrations r
                      WHERE r.activity_id = a.id AND r.status = 'joined') AS joined_count
             FROM activities a
             LEFT JOIN circles c ON c.id = a.circle_id
             {$where}
             ORDER BY a.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    private function legacyAdminActivityCount(string $keyword, string $type, string $status, array $owned): int
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND a.title LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        if ($type === 'signup') {
            $where .= ' AND a.type = ?';
            $params[] = $type;
        }
        if (in_array($status, ['open', 'closed', 'ended'], true)) {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if (!empty($owned)) {
            $ph = implode(',', array_fill(0, count($owned), '?'));
            $where .= " AND a.circle_id IN ({$ph})";
            $params = array_merge($params, $owned);
        }
        return (int)$this->legacyColumn("SELECT COUNT(*) FROM activities a {$where}", $params);
    }

    // ==================== HEAD 快照：分类 ====================

    private function legacyActiveCategory(int $id): ?array
    {
        return $this->legacyOne("SELECT id FROM categories WHERE id = ? AND status = 1 LIMIT 1", [$id]);
    }

    private function legacyFollowEdge(int $userId, int $categoryId): ?array
    {
        return $this->legacyOne(
            "SELECT id FROM category_follows WHERE user_id = ? AND category_id = ? LIMIT 1",
            [$userId, $categoryId]
        );
    }

    private function legacyFollowingCategories(int $userId): array
    {
        return $this->legacy(
            "SELECT c.id, c.name, c.parent_id, c.description, c.cover, c.post_count
             FROM category_follows f
             INNER JOIN categories c ON c.id = f.category_id
             WHERE f.user_id = ? AND c.status = 1
             ORDER BY f.created_at DESC",
            [$userId]
        );
    }

    private function legacyAllCategories(): array
    {
        return $this->legacy(
            "SELECT id, parent_id, name, sort_order, status FROM categories ORDER BY sort_order ASC, id ASC"
        );
    }

    private function legacyActiveCategories(): array
    {
        return $this->legacy(
            "SELECT id, parent_id, name, sort_order, status FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC"
        );
    }

    // ==================== HEAD 快照：搜索 ====================

    private function legacySearchIds(
        string $q,
        int $categoryId,
        string $mediaType,
        int $beforeId,
        int $offset,
        int $limit,
        string $visWhere,
        array $visParams
    ): array {
        $conditions = ['p.status = 1', $visWhere];
        $params = $visParams;
        if (!empty($q)) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $conditions[] = '(p.title LIKE ? OR p.content LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($categoryId > 0) {
            $conditions[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($mediaType === 'image') {
            $conditions[] = "p.images IS NOT NULL AND p.images != '[]' AND p.images != ''";
        } elseif ($mediaType === 'video') {
            $conditions[] = "p.videos IS NOT NULL AND p.videos != '[]' AND p.videos != ''";
        } elseif ($mediaType === 'text') {
            $conditions[] = "(p.images IS NULL OR p.images = '[]' OR p.images = '') AND (p.videos IS NULL OR p.videos = '[]' OR p.videos = '')";
        }
        if ($beforeId > 0) {
            $conditions[] = 'p.id < ?';
            $params[] = $beforeId;
        }
        $whereClause = implode(' AND ', $conditions);
        $limitSql = $beforeId > 0 ? 'LIMIT ?' : 'LIMIT ?, ?';
        $limitParams = $beforeId > 0 ? [$limit] : [$offset, $limit];
        return $this->legacy(
            "SELECT p.id FROM posts p WHERE {$whereClause} ORDER BY p.created_at DESC {$limitSql}",
            array_merge($params, $limitParams)
        );
    }

    private function legacySearchCount(
        string $q,
        int $categoryId,
        string $mediaType,
        int $beforeId,
        string $visWhere,
        array $visParams
    ): int {
        $conditions = ['p.status = 1', $visWhere];
        $params = $visParams;
        if (!empty($q)) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $conditions[] = '(p.title LIKE ? OR p.content LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($categoryId > 0) {
            $conditions[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($mediaType === 'image') {
            $conditions[] = "p.images IS NOT NULL AND p.images != '[]' AND p.images != ''";
        } elseif ($mediaType === 'video') {
            $conditions[] = "p.videos IS NOT NULL AND p.videos != '[]' AND p.videos != ''";
        } elseif ($mediaType === 'text') {
            $conditions[] = "(p.images IS NULL OR p.images = '[]' OR p.images = '') AND (p.videos IS NULL OR p.videos = '[]' OR p.videos = '')";
        }
        if ($beforeId > 0) {
            $conditions[] = 'p.id < ?';
            $params[] = $beforeId;
        }
        return (int)$this->legacyColumn(
            'SELECT COUNT(*) FROM posts p WHERE ' . implode(' AND ', $conditions),
            $params
        );
    }

    private function legacyPostsByIds(array $ids, string $visWhere, array $visParams): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->legacy(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.is_anonymous, p.location, p.visibility, p.circle_id, p.created_at, p.updated_at,
                    u.username, u.nickname, u.avatar, u.role
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id IN ({$ph}) AND {$visWhere}
             ORDER BY p.created_at DESC",
            array_merge(array_values($ids), $visParams)
        );
    }

    private function legacyLikesByIds(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->legacy(
            "SELECT l.post_id, u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id IN ({$ph})
             ORDER BY l.created_at ASC",
            array_values($ids)
        );
    }

    private function legacyCommentsByIds(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->legacy(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$ph})
             ORDER BY c.created_at ASC",
            array_values($ids)
        );
    }

    private function legacyBlockedUploads(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->legacy(
            "SELECT url FROM uploads WHERE post_id IN ({$ph}) AND status = 0",
            array_values($ids)
        );
    }

    // ==================== 活动：列表 / 分页 / 空结果 ====================

    public function testFrontActivityListMatchesLegacyAcrossEmptySingleAndMultiplePages(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '一圈', 'owner_id' => 7]);
        $this->seed('circles', ['id' => 2, 'name' => '二圈', 'owner_id' => 8]);
        $this->assertSame([], ActivityRepository::fetchActivities(0, 'signup', 'open', '', 0, 20));
        $this->assertSame(0, ActivityRepository::countActivities(0, 'signup', 'open', ''));

        foreach ([
            [11, 1, 'alpha open', 'open', '2026-01-01 00:00:00'],
            [12, 1, 'beta open', 'open', '2026-01-02 00:00:00'],
            [13, 2, 'gamma closed', 'closed', '2026-01-03 00:00:00'],
            [14, 2, 'delta open', 'open', '2026-01-04 00:00:00'],
            [15, 1, 'epsilon open', 'open', '2026-01-05 00:00:00'],
        ] as [$id, $circleId, $title, $status, $created]) {
            $this->seed('activities', [
                'id' => $id, 'circle_id' => $circleId, 'creator_id' => 7, 'title' => $title,
                'description' => "desc{$id}", 'cover' => null, 'type' => 'signup', 'mode' => 'count',
                'capacity' => 10, 'checkin_goal' => 3, 'start_at' => null, 'end_at' => null,
                'status' => $status, 'created_at' => $created,
            ]);
        }
        $this->seed('activity_registrations', ['id' => 1, 'activity_id' => 11, 'user_id' => 3, 'status' => 'joined']);
        $this->seed('activity_registrations', ['id' => 2, 'activity_id' => 11, 'user_id' => 4, 'status' => 'canceled']);

        foreach ([[0, 2], [2, 2], [4, 2]] as [$offset, $limit]) {
            $this->assertSame(
                $this->legacyFrontActivityList(0, 'signup', 'open', '', $offset, $limit),
                ActivityRepository::fetchActivities(0, 'signup', 'open', '', $offset, $limit),
                "前台活动列表第 {$offset} 页不一致"
            );
        }
        $this->assertSame(
            $this->legacyFrontActivityCount(0, 'signup', 'open', ''),
            ActivityRepository::countActivities(0, 'signup', 'open', '')
        );

        // 单表单行 + 关键词 + 空 status（不过滤）
        $this->assertSame(
            $this->legacyFrontActivityList(2, 'signup', '', 'gamma', 0, 10),
            ActivityRepository::fetchActivities(2, 'signup', '', 'gamma', 0, 10)
        );
        $this->assertSame(
            $this->legacyFrontActivityCount(2, 'signup', '', 'gamma'),
            ActivityRepository::countActivities(2, 'signup', '', 'gamma')
        );
        $this->seed('activity_registrations', ['id' => 3, 'activity_id' => 13, 'user_id' => 5, 'status' => 'joined']);
        $row = ActivityRepository::fetchActivities(2, 'signup', '', 'gamma', 0, 10)[0];
        $this->assertSame(1, $row['joined_count']);
        $this->assertSame('二圈', $row['circle_name']);
        $this->assertSame(
            ['id', 'circle_id', 'title', 'description', 'cover', 'type', 'mode', 'capacity',
                'start_at', 'end_at', 'status', 'created_at', 'circle_name', 'joined_count'],
            array_keys($row),
            '前台字段清单/顺序必须与 HEAD 一致，且不出现后台专属列'
        );
        $this->assertSame([], ActivityRepository::fetchActivities(999, 'signup', 'open', '', 0, 10));
    }

    public function testFrontActivityDetailRegistrationAndMineMatchLegacy(): void
    {
        $this->seed('circles', ['id' => 3, 'name' => '详情圈']);
        $this->seed('activities', [
            'id' => 21, 'circle_id' => 3, 'creator_id' => 5, 'title' => '详情活动',
            'description' => 'd', 'type' => 'signup', 'mode' => 'free', 'capacity' => 2,
            'status' => 'open', 'created_at' => '2026-02-01 00:00:00',
        ]);
        $this->seed('activities', [
            'id' => 22, 'circle_id' => 3, 'title' => '截止活动', 'status' => 'closed',
            'created_at' => '2026-02-02 00:00:00',
        ]);
        $this->seed('activity_registrations', [
            'id' => 31, 'activity_id' => 21, 'user_id' => 9, 'status' => 'joined',
            'note' => 'note-a', 'updated_at' => '2026-02-03 00:00:00',
        ]);
        $this->seed('activity_registrations', [
            'id' => 32, 'activity_id' => 21, 'user_id' => 10, 'status' => 'canceled',
        ]);

        $this->assertSame($this->legacyActivityDetail(21), ActivityRepository::findActivityDetailById(21));
        $this->assertNull(ActivityRepository::findActivityDetailById(999));
        $this->assertSame(
            (int)$this->legacyColumn(
                "SELECT COUNT(*) FROM activity_registrations WHERE activity_id = ? AND status = 'joined'",
                [21]
            ),
            ActivityRepository::countJoinedRegistrations(21)
        );
        $this->assertSame(0, ActivityRepository::countJoinedRegistrations(999));
        $this->assertSame(
            (bool)$this->legacyColumn(
                "SELECT id FROM activity_registrations WHERE activity_id = ? AND user_id = ? AND status = 'joined' LIMIT 1",
                [21, 9]
            ),
            ActivityRepository::hasJoinedRegistration(21, 9)
        );
        $this->assertFalse(ActivityRepository::hasJoinedRegistration(21, 10), '已取消报名不应算已报名');
        $this->assertSame(
            $this->legacyOne('SELECT * FROM activities WHERE id = ? LIMIT 1', [21]),
            ActivityRepository::findActivityById(21)
        );
        $this->assertSame(
            $this->legacyOne(
                'SELECT id, status FROM activity_registrations WHERE activity_id = ? AND user_id = ? LIMIT 1',
                [21, 9]
            ),
            ActivityRepository::findRegistration(21, 9)
        );
        $this->assertSame(
            $this->legacy(
                "SELECT a.id, a.title, a.cover, a.type, a.status, a.end_at,
                        r.status AS reg_status, r.note, r.updated_at
                 FROM activity_registrations r
                 INNER JOIN activities a ON a.id = r.activity_id
                 WHERE r.user_id = ? AND r.status = 'joined'
                 ORDER BY a.created_at DESC LIMIT 50",
                [9]
            ),
            ActivityRepository::fetchMyActivities(9)
        );
        $this->assertSame([], ActivityRepository::fetchMyActivities(10));
    }

    public function testActivityWritePathsKeepExactRows(): void
    {
        $this->seed('activities', [
            'id' => 41, 'title' => '报名活动', 'type' => 'signup', 'status' => 'open',
            'capacity' => 3, 'created_at' => '2026-03-01 00:00:00',
        ]);

        ActivityRepository::insertRegistration(41, 17, 'first');
        $this->assertSame('joined', $this->rows('activity_registrations')[0]['status']);
        $this->assertSame('first', $this->rows('activity_registrations')[0]['note']);
        $registrationId = (int)$this->rows('activity_registrations')[0]['id'];

        ActivityRepository::updateRegistrationToJoined($registrationId, null, '2026-03-02 00:00:00');
        $this->assertNull($this->rows('activity_registrations')[0]['note']);
        $this->assertSame('2026-03-02 00:00:00', $this->rows('activity_registrations')[0]['updated_at']);

        $this->assertSame(1, ActivityRepository::cancelRegistration(41, 17, '2026-03-03 00:00:00'));
        $this->assertSame('canceled', $this->rows('activity_registrations')[0]['status']);
        $this->assertSame(0, ActivityRepository::cancelRegistration(41, 17, '2026-03-04 00:00:00'));

        $newId = ActivityRepository::insertActivity([
            'circle_id' => null, 'title' => '后台新建', 'description' => 'd', 'cover' => null,
            'type' => 'signup', 'mode' => 'free', 'capacity' => 0, 'checkin_goal' => 0,
            'start_at' => null, 'end_at' => null, 'status' => 'open',
            'updated_at' => '2026-03-05 00:00:00', 'creator_id' => 2,
            'created_at' => '2026-03-05 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        ActivityRepository::updateActivityFields($newId, ['title' => '改名', 'status' => 'closed', 'updated_at' => '2026-03-06 00:00:00']);
        $row = $this->legacyOne('SELECT title, status, updated_at FROM activities WHERE id = ?', [$newId]);
        $this->assertSame(['title' => '改名', 'status' => 'closed', 'updated_at' => '2026-03-06 00:00:00'], $row);

        $this->assertSame(1, ActivityRepository::deleteActivityById($newId));
        $this->assertSame(0, ActivityRepository::deleteActivityById($newId));

        $this->expectException(InvalidArgumentException::class);
        ActivityRepository::updateActivityFields($newId, ['title OR 1=1' => 'x']);
    }

    // ==================== 活动：后台口径与前台交叉断言 ====================

    public function testAdminActivitiesMatchLegacyAndAreNotReusedWithFrontendQuery(): void
    {
        $this->seed('circles', ['id' => 51, 'name' => '甲圈', 'owner_id' => 70]);
        $this->seed('circles', ['id' => 52, 'name' => '乙圈', 'owner_id' => 71]);
        foreach ([
            [61, 51, 'apple', 'open', '2026-04-01 00:00:00'],
            [62, 52, 'banana', 'closed', '2026-04-02 00:00:00'],
            [63, 51, 'cherry', 'open', '2026-04-03 00:00:00'],
        ] as [$id, $circleId, $title, $status, $created]) {
            $this->seed('activities', [
                'id' => $id, 'circle_id' => $circleId, 'creator_id' => 70, 'title' => $title,
                'description' => 'x', 'type' => 'signup', 'mode' => 'count', 'capacity' => 5,
                'checkin_goal' => 2, 'status' => $status, 'created_at' => $created,
            ]);
        }
        $this->seed('activity_registrations', ['id' => 1, 'activity_id' => 62, 'user_id' => 1, 'status' => 'joined']);

        foreach ([[0, 2], [2, 2]] as [$offset, $limit]) {
            $this->assertSame(
                $this->legacyAdminActivityList('', 'signup', '', [], $offset, $limit),
                ActivityRepository::fetchAdminActivities('', 'signup', '', [], $offset, $limit)
            );
        }
        $this->assertSame(
            $this->legacyAdminActivityCount('', 'signup', '', []),
            ActivityRepository::countAdminActivities('', 'signup', '', [])
        );
        $this->assertSame(
            $this->legacyAdminActivityList('an', 'signup', 'closed', [51, 52], 0, 10),
            ActivityRepository::fetchAdminActivities('an', 'signup', 'closed', [51, 52], 0, 10)
        );
        $this->assertSame(
            $this->legacyAdminActivityCount('an', 'signup', 'closed', [51, 52]),
            ActivityRepository::countAdminActivities('an', 'signup', 'closed', [51, 52])
        );
        $this->assertSame([], ActivityRepository::fetchAdminActivities('', '', '', [999], 0, 10));

        $adminKeys = array_keys(ActivityRepository::fetchAdminActivities('', '', '', [], 0, 1)[0]);
        $frontKeys = array_keys(ActivityRepository::fetchActivities(0, '', '', '', 0, 1)[0]);
        $this->assertContains('creator_id', $adminKeys);
        $this->assertContains('checkin_goal', $adminKeys);
        $this->assertNotContains('creator_id', $frontKeys);
        $this->assertNotContains('checkin_goal', $frontKeys);
        $adminIds = array_column(ActivityRepository::fetchAdminActivities('', '', '', [], 0, 10), 'id');
        $frontIds = array_column(ActivityRepository::fetchActivities(0, '', '', '', 0, 10), 'id');
        $this->assertSame([63, 62, 61], $adminIds, '后台按 a.id DESC');
        $this->assertSame([63, 62, 61], $frontIds, '本例创建时间与 id 同序，排序差异另造数据验证');

        // 交叉口径：造一组 id 与 created_at 逆序的数据，两侧排序必须不同
        $this->seed('activities', [
            'id' => 99, 'title' => '最早创建', 'type' => 'signup', 'status' => 'open',
            'created_at' => '2020-01-01 00:00:00',
        ]);
        $this->assertSame(
            [63, 62, 61, 99],
            array_column(ActivityRepository::fetchActivities(0, '', '', '', 0, 10), 'id'),
            '前台按 a.created_at DESC'
        );
        $this->assertSame(
            [99, 63, 62, 61],
            array_column(ActivityRepository::fetchAdminActivities('', '', '', [], 0, 10), 'id'),
            '后台按 a.id DESC'
        );
    }

    public function testAdminCircleReadsMatchLegacy(): void
    {
        $this->seed('circles', ['id' => 81, 'name' => 'A', 'owner_id' => 5, 'member_count' => 2, 'status' => 1]);
        $this->seed('circles', ['id' => 82, 'name' => 'B', 'owner_id' => 5, 'member_count' => 3, 'status' => 0]);
        $this->seed('circles', ['id' => 83, 'name' => 'C', 'owner_id' => 6, 'member_count' => 4, 'status' => 1]);

        $this->assertSame(
            $this->legacy('SELECT id, name, owner_id, member_count, status FROM circles ORDER BY id DESC LIMIT 200'),
            ActivityRepository::fetchAdminMyCircles()
        );
        $this->assertSame(
            $this->legacy(
                'SELECT id, name, owner_id, member_count, status FROM circles WHERE id IN (?, ?) ORDER BY id DESC',
                [81, 83]
            ),
            ActivityRepository::fetchOwnedCircles([81, 83])
        );
        $this->assertSame([], ActivityRepository::fetchOwnedCircles([]));
        $this->assertSame(
            (int)$this->legacyColumn('SELECT COUNT(*) FROM circles WHERE owner_id = ?', [5]),
            ActivityRepository::countOwnedCircles(5)
        );
        $this->assertSame(
            $this->legacy('SELECT id FROM circles WHERE owner_id = ?', [5]),
            ActivityRepository::fetchOwnedCircleIds(5)
        );
        $this->assertSame(
            (int)$this->legacyColumn('SELECT COUNT(*) FROM circles WHERE id = ? LIMIT 1', [81]),
            ActivityRepository::countCircleById(81)
        );
        $this->assertSame(0, ActivityRepository::countCircleById(999));

        $this->seed('activities', ['id' => 91, 'circle_id' => 0, 'title' => '全站', 'status' => 'open']);
        $this->seed('activities', ['id' => 92, 'circle_id' => 81, 'title' => '圈内', 'status' => 'closed']);
        $this->assertSame(
            $this->legacyColumn('SELECT circle_id FROM activities WHERE id = ? LIMIT 1', [91]),
            ActivityRepository::fetchActivityCircleId(91)
        );
        $this->assertTrue(ActivityRepository::existsActivityById(92));
        $this->assertFalse(ActivityRepository::existsActivityById(999));
        $this->assertSame(
            $this->legacyOne('SELECT id, circle_id FROM activities WHERE id = ? LIMIT 1', [92]),
            ActivityRepository::findActivityCircleRow(92)
        );
        $this->expectException(InvalidArgumentException::class);
        ActivityRepository::insertActivity(['bad-column' => 1]);
    }

    // ==================== 分类：关注事务、守卫、读路径 ====================

    public function testCategoryFollowReadsMatchLegacyAndDisabledCategoriesAreExcluded(): void
    {
        $this->seed('categories', [
            'id' => 101, 'parent_id' => null, 'name' => '启用', 'sort_order' => 2,
            'status' => 1, 'description' => 'd', 'cover' => 'c', 'post_count' => 9,
        ]);
        $this->seed('categories', [
            'id' => 102, 'parent_id' => 101, 'name' => '停用子节点', 'sort_order' => 1,
            'status' => 0, 'description' => 'd2', 'post_count' => 3,
        ]);
        $this->seed('categories', [
            'id' => 103, 'parent_id' => 101, 'name' => '另一子', 'sort_order' => 1,
            'status' => 1, 'description' => 'd3', 'post_count' => 4,
        ]);
        $this->seed('category_follows', ['id' => 1, 'user_id' => 6, 'category_id' => 101, 'created_at' => '2026-05-02 00:00:00']);
        $this->seed('category_follows', ['id' => 2, 'user_id' => 6, 'category_id' => 102, 'created_at' => '2026-05-01 00:00:00']);
        $this->seed('category_follows', ['id' => 3, 'user_id' => 7, 'category_id' => 101, 'created_at' => '2026-05-03 00:00:00']);

        $this->assertSame($this->legacyActiveCategory(101), CategoryRepository::findActiveCategoryId(101));
        $this->assertNull(CategoryRepository::findActiveCategoryId(102), '停用分类不可关注');
        $this->assertSame($this->legacyFollowEdge(6, 101), CategoryRepository::findFollowEdge(6, 101));
        $this->assertNull(CategoryRepository::findFollowEdge(8, 101));
        $this->assertSame(
            (int)$this->legacyColumn('SELECT COUNT(*) FROM category_follows WHERE category_id = ?', [101]),
            CategoryRepository::countFollowers(101)
        );
        $this->assertSame(
            $this->legacyFollowingCategories(6),
            CategoryRepository::fetchFollowingCategories(6)
        );
        $this->assertSame([101], array_column(CategoryRepository::fetchFollowingCategories(6), 'id'));
        $this->assertSame([], CategoryRepository::fetchFollowingCategories(999));

        $this->assertSame($this->legacyAllCategories(), CategoryRepository::fetchAllCategories());
        $this->assertSame($this->legacyActiveCategories(), CategoryRepository::fetchActiveCategories());
        $this->assertCount(3, CategoryRepository::fetchAllCategories());
        $this->assertCount(2, CategoryRepository::fetchActiveCategories(), '后台启用列表必须过滤 status = 0');
        $allKeys = array_keys(CategoryRepository::fetchAllCategories()[0]);
        $activeKeys = array_keys(CategoryRepository::fetchActiveCategories()[0]);
        $this->assertSame(['id', 'parent_id', 'name', 'sort_order', 'status'], $allKeys);
        $this->assertSame($allKeys, $activeKeys);

        $this->assertSame(
            $this->legacy('SELECT id FROM categories WHERE parent_id = ?', [101]),
            CategoryRepository::fetchChildCategoryIds(101)
        );
        $this->assertSame(
            (int)$this->legacyColumn('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [101]),
            CategoryRepository::countChildCategories(101)
        );
    }

    public function testToggleFollowTransactionSuccessGuardAndRollback(): void
    {
        $this->seed('categories', ['id' => 201, 'name' => '可关注', 'status' => 1]);
        $this->seed('categories', ['id' => 202, 'name' => '停用', 'status' => 0]);

        $this->assertTrue(CategoryRepository::toggleFollow(201, 11, '2026-06-01 00:00:00'));
        $this->assertCount(1, $this->rows('category_follows'));
        $this->assertSame(1, CategoryRepository::countFollowers(201));
        $this->assertSame(
            $this->legacyOne(
                'SELECT id FROM category_follows WHERE user_id = ? AND category_id = ? LIMIT 1',
                [11, 201]
            ),
            CategoryRepository::findFollowEdge(11, 201)
        );

        $edge = CategoryRepository::findFollowEdge(11, 201);
        $this->assertFalse(CategoryRepository::toggleFollow(201, 11, '2026-06-02 00:00:00', $edge));
        $this->assertSame([], $this->rows('category_follows'), '再次切换应删除原关注行');
        $this->assertSame(0, CategoryRepository::countFollowers(201));

        $this->assertFalse(CategoryRepository::toggleFollow(0, 11, '2026-06-03 00:00:00'));
        $this->assertFalse(CategoryRepository::toggleFollow(201, 0, '2026-06-03 00:00:00'));
        $this->assertFalse(CategoryRepository::toggleFollow(202, 11, '2026-06-03 00:00:00'));
        $this->assertSame([], $this->rows('category_follows'), '入口守卫被绕过时不得写库');

        // SQLite trigger 模拟写入异常：必须回滚且不留悬挂事务
        $this->pdo()->exec(
            'CREATE TRIGGER fail_follow BEFORE INSERT ON category_follows '
            . "BEGIN SELECT RAISE(ABORT, 'boom'); END;"
        );
        try {
            CategoryRepository::toggleFollow(201, 12, '2026-06-04 00:00:00');
            $this->fail('预期 trigger 抛异常');
        } catch (PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame([], $this->rows('category_follows'));
        $this->assertFalse($this->pdo()->inTransaction(), '异常后必须已 rollBack');
    }

    public function testCategoryWritePathsAndWhitelistGuards(): void
    {
        $this->seed('categories', ['id' => 301, 'name' => '父', 'parent_id' => null, 'sort_order' => 1, 'status' => 1]);
        $this->seed('categories', ['id' => 302, 'name' => '子', 'parent_id' => 301, 'sort_order' => 1, 'status' => 1]);

        $newId = CategoryRepository::insertCategory([
            'name' => '新分类', 'parent_id' => 301, 'sort_order' => 2, 'status' => 1,
            'updated_at' => '2026-07-01 00:00:00', 'created_at' => '2026-07-01 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        CategoryRepository::updateCategoryFields($newId, ['name' => '改后', 'sort_order' => 9]);
        $this->assertSame(
            ['name' => '改后', 'sort_order' => 9],
            $this->legacyOne('SELECT name, sort_order FROM categories WHERE id = ?', [$newId])
        );

        $this->assertSame(1, CategoryRepository::deleteCategoryById($newId));
        $this->assertSame(0, CategoryRepository::deleteCategoryById($newId));

        try {
            CategoryRepository::updateCategoryFields(301, ['name; DROP' => 'x']);
            $this->fail('非法列名应被拒绝');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('非法字段名', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        CategoryRepository::insertCategory([]);
    }

    // ==================== 搜索：ID 检索 / 分页 / 批量加载 / 全文分支 ====================

    public function testSearchIdsCountAndCursorMatchLegacy(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'u1', 'nickname' => 'n1', 'role' => 'user']);
        foreach ([
            [401, 7, 'alpha keyword', '2026-08-01 00:00:00', 1, 'public'],
            [402, 7, 'beta keyword', '2026-08-02 00:00:00', 1, 'public'],
            [403, 8, 'gamma keyword', '2026-08-03 00:00:00', 1, 'public'],
            [404, 7, 'keyword hidden', '2026-08-04 00:00:00', 0, 'public'],
            [406, 7, 'keyword private', '2026-08-06 00:00:00', 1, 'private'],
        ] as [$id, $categoryId, $title, $created, $status, $visibility]) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => 1, 'category_id' => $categoryId, 'title' => $title,
                'content' => "body {$id}", 'post_type' => 'normal', 'status' => $status,
                'visibility' => $visibility, 'created_at' => $created,
            ]);
        }

        [$visWhere, $visParams] = $this->guestWhere();

        foreach ([[0, 2], [2, 2]] as [$offset, $limit]) {
            $actual = SearchRepository::fetchPostIds(
                'keyword', 0, '', '', 0, $offset, $limit, false, $visWhere, $visParams
            );
            $legacy = $this->legacySearchIds('keyword', 0, '', 0, $offset, $limit, $visWhere, $visParams);
            $this->assertSame(array_column($legacy, 'id'), array_column($actual, 'id'));
            $this->assertArrayHasKey('post_type', $actual[0] ?? [], 'posts 行查询必须带 post_type');
            $this->assertNotContains(406, array_column($actual, 'id'), '游客搜索不得返回 private 帖');
        }
        $this->assertSame(
            $this->legacySearchCount('keyword', 0, '', 0, $visWhere, $visParams),
            SearchRepository::countPosts('keyword', 0, '', '', 0, false, $visWhere, $visParams)
        );
        $this->assertSame(3, SearchRepository::countPosts('keyword', 0, '', '', 0, false, $visWhere, $visParams));
        $this->assertSame(2, SearchRepository::countPosts('keyword', 7, '', '', 0, false, $visWhere, $visParams));

        // 游标翻页：before_id > 0 时参数顺序为 limit，SQL 形态 LIMIT ?
        $actual = SearchRepository::fetchPostIds('keyword', 0, '', '', 402, 0, 2, false, $visWhere, $visParams);
        $this->assertSame([401], array_column($actual, 'id'));
        $this->assertSame(
            [401],
            array_column($this->legacySearchIds('keyword', 0, '', 402, 0, 2, $visWhere, $visParams), 'id')
        );

        // 媒体筛选 + 空结果 + 停用负例
        $this->seed('posts', [
            'id' => 405, 'user_id' => 1, 'title' => 'keyword image', 'post_type' => 'normal',
            'images' => '["a.png"]', 'status' => 1, 'visibility' => 'public',
            'created_at' => '2026-08-05 00:00:00',
        ]);
        $this->assertSame(
            array_column($this->legacySearchIds('keyword', 0, 'image', 0, 0, 10, $visWhere, $visParams), 'id'),
            array_column(
                SearchRepository::fetchPostIds('keyword', 0, '', 'image', 0, 0, 10, false, $visWhere, $visParams),
                'id'
            )
        );
        $this->assertSame(
            [],
            SearchRepository::fetchPostIds('不存在', 0, '', '', 0, 0, 10, false, $visWhere, $visParams)
        );
        $this->assertSame(0, SearchRepository::countPosts('不存在', 0, '', '', 0, false, $visWhere, $visParams));
    }

    /** 游客可见性片段与 VisibilityPolicy::where(0) 同口径 */
    private function guestWhere(): array
    {
        return ["(p.visibility = 'public')", []];
    }

    public function testSearchBatchLoadersAndFulltextProbeMatchLegacy(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'author', 'nickname' => '作者', 'avatar' => 'a', 'role' => 'user']);
        foreach ([501, 502] as $id) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => 1, 'category_id' => 9, 'title' => "post{$id}",
                'content' => "c{$id}", 'post_type' => $id === 501 ? 'normal' : 'poll',
                'images' => '[]', 'videos' => '[]', 'attachments' => '[]',
                'is_pinned' => 0, 'is_anonymous' => 0, 'status' => 1, 'visibility' => 'public',
                'created_at' => '2026-09-0' . ($id % 10) . ' 00:00:00', 'updated_at' => '2026-09-10 00:00:00',
            ]);
        }
        $this->seed('likes', ['id' => 1, 'post_id' => 501, 'user_id' => 1, 'created_at' => '2026-09-02 00:00:00']);
        $this->seed('likes', ['id' => 2, 'post_id' => 501, 'user_id' => 2, 'created_at' => '2026-09-01 00:00:00']);
        $this->seed('users', ['id' => 2, 'username' => 'liker', 'nickname' => '点赞者', 'role' => 'user']);
        $this->seed('comments', [
            'id' => 1, 'post_id' => 501, 'user_id' => 1, 'parent_id' => 0, 'content' => 'c',
            'image' => null, 'is_ai' => 0, 'created_at' => '2026-09-03 00:00:00',
        ]);
        $this->seed('uploads', ['id' => 1, 'file_hash' => 'h1', 'url' => '/blocked.png', 'post_id' => 501, 'status' => 0]);
        $this->seed('uploads', ['id' => 2, 'file_hash' => 'h2', 'url' => '/ok.png', 'post_id' => 501, 'status' => 1]);

        [$visWhere, $visParams] = $this->guestWhere();
        $legacyPosts = $this->legacyPostsByIds([501, 502], $visWhere, $visParams);
        $actualPosts = SearchRepository::fetchPostsByIds([501, 502], $visWhere, $visParams);
        $this->assertSame(array_column($legacyPosts, 'id'), array_column($actualPosts, 'id'));
        $legacyKeys = array_keys($legacyPosts[0]);
        $actualKeys = array_keys($actualPosts[0]);
        $this->assertSame('post_type', array_pop($actualKeys));
        $this->assertSame($legacyKeys, $actualKeys, 'post_type 必须追加在末尾，原列顺序不变');
        $this->assertSame(
            $this->legacyLikesByIds([501, 502]),
            SearchRepository::fetchLikesByPostIds([501, 502])
        );
        $this->assertSame(
            $this->legacyCommentsByIds([501, 502]),
            SearchRepository::fetchCommentsByPostIds([501, 502])
        );
        $this->assertSame(
            $this->legacyBlockedUploads([501, 502]),
            SearchRepository::fetchBlockedUploadUrls([501, 502])
        );
        $this->assertSame([], SearchRepository::fetchPostsByIds([], $visWhere, $visParams));
        $this->assertSame([], SearchRepository::fetchLikesByPostIds([]));
        $this->assertSame([], SearchRepository::fetchCommentsByPostIds([]));
        $this->assertSame([], SearchRepository::fetchBlockedUploadUrls([]));

        $this->assertSame(0, SearchRepository::countFulltextIndexes());
        $this->pdo()->exec(
            "INSERT INTO information_schema.STATISTICS (TABLE_SCHEMA, TABLE_NAME, INDEX_NAME) VALUES ('main', 'posts', 'idx_posts_fulltext')"
        );
        $this->assertSame(1, SearchRepository::countFulltextIndexes());
    }

    public function testFulltextWhereBranchBuildsMatchClauseAndBoundParams(): void
    {
        $method = new ReflectionMethod(SearchRepository::class, 'searchWhere');
        $method->setAccessible(true);

        [$visWhere, $visParams] = $this->guestWhere();
        [$where, $params] = $method->invoke(null, '关键词', 12, '7d', 'image', 44, true, $visWhere, $visParams);
        $this->assertStringContainsString('p.status = 1', $where);
        $this->assertStringContainsString("(p.visibility = 'public')", $where);
        $this->assertStringContainsString('(MATCH(p.title, p.content) AGAINST(? IN BOOLEAN MODE))', $where);
        $this->assertStringContainsString('p.category_id = ?', $where);
        $this->assertStringContainsString('p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', $where);
        $this->assertStringContainsString("p.images IS NOT NULL AND p.images != '[]'", $where);
        $this->assertStringContainsString('p.id < ?', $where);
        $this->assertSame(['"关键词"', 12, 44], $params);

        [$likeWhere, $likeParams] = $method->invoke(null, '关键词', 0, '30d', '', 0, false, $visWhere, $visParams);
        $this->assertStringContainsString('(p.title LIKE ? OR p.content LIKE ?)', $likeWhere);
        $this->assertStringNotContainsString('MATCH(', $likeWhere);
        $this->assertStringContainsString('p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', $likeWhere);
        $this->assertSame(['%关键词%', '%关键词%'], $likeParams);

        // 原文 !empty($q) 语义：'0' 不生成关键词条件
        [$zeroWhere, $zeroParams] = $method->invoke(null, '0', 0, '', '', 0, true, $visWhere, $visParams);
        $this->assertStringNotContainsString('MATCH(', $zeroWhere);
        $this->assertSame([], $zeroParams);
    }
}
