<?php
/**
 * 批次五 · 社交互动域（Badge / Favorite / Notification / Call / Report）迁移回归测试
 *
 * 对照法：每条被测查询都在本文件保留一份「迁移前 HEAD 原文 SQL」私有方法，
 * 在 SQLite 内存库中同时执行原始 SQL 与新 Repository，assertSame 逐字段比对。
 * 覆盖空结果 / 单行 / 多页 / 软删与状态负例 / 相似 SQL 字段集差异交叉断言；
 * 收藏事务覆盖成功与异常回滚（SQLite trigger RAISE(ABORT)）。
 *
 * MySQL 的 DATE_SUB(NOW(), INTERVAL n SECOND) 无法被 SQLite 解析，测试专用
 * CompatPdo 仅在 prepare 阶段做等价改写（占位符数量不变），HEAD 快照 SQL 保持原文。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\BadgeRepository;
use Hublog\Core\CallRepository;
use Hublog\Core\Database;
use Hublog\Core\FavoriteRepository;
use Hublog\Core\NotificationRepository;
use Hublog\Core\ReportRepository;
use Hublog\Core\VisibilityPolicy;
use PDO;
use PDOStatement;
use ReflectionProperty;

final class Batch5SocialInteractiveBehaviorTest extends BehaviorTestCase
{
    private ?CompatPdo $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new CompatPdo('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('DATE_SUB', static function (string $date, mixed $seconds): string {
            return date('Y-m-d H:i:s', strtotime($date) - (int)$seconds);
        }, 2);
        $pdo->exec(self::SCHEMA);
        $this->db = $pdo;

        // Database::getInstance() 必须拿到同一连接（ BehaviorTestCase 注入的是另一条 */
        $ref = new ReflectionProperty(Database::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, $pdo);
    }

    private const SCHEMA = <<<'SQL'
CREATE TABLE `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `username` TEXT DEFAULT NULL,
    `nickname` TEXT DEFAULT NULL,
    `avatar` TEXT DEFAULT NULL,
    `role` TEXT DEFAULT 'user',
    `status` INTEGER NOT NULL DEFAULT 1,
    `vip_level` INTEGER NOT NULL DEFAULT 0,
    `avatar_frame` TEXT DEFAULT NULL,
    `deleted_at` TEXT DEFAULT NULL
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `category_id` INTEGER NOT NULL DEFAULT 0,
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `videos` TEXT DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL,
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `pinned_at` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1,
    `post_type` TEXT DEFAULT 'normal',
    `visibility` TEXT DEFAULT 'public',
    `circle_id` INTEGER DEFAULT NULL,
    `is_anonymous` INTEGER NOT NULL DEFAULT 0,
    `location` TEXT DEFAULT NULL,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `view_count` INTEGER NOT NULL DEFAULT 0,
    `audit_state` INTEGER DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `badges` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `badge_key` TEXT DEFAULT NULL,
    `name` TEXT DEFAULT NULL,
    `desc` TEXT DEFAULT NULL,
    `category` TEXT DEFAULT 'creation',
    `icon` TEXT DEFAULT NULL,
    `color` TEXT DEFAULT NULL,
    `rule_type` TEXT DEFAULT 'count',
    `threshold` TEXT DEFAULT NULL,
    `sort_weight` INTEGER NOT NULL DEFAULT 0,
    `hideable` INTEGER NOT NULL DEFAULT 0,
    `display` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `user_badges` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `badge_id` INTEGER NOT NULL,
    `state` INTEGER NOT NULL DEFAULT 1,
    `grant_type` TEXT DEFAULT 'auto',
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `badge_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `operator_id` INTEGER DEFAULT NULL,
    `operator_name` TEXT DEFAULT NULL,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `badge_id` INTEGER NOT NULL DEFAULT 0,
    `action` TEXT DEFAULT NULL,
    `remark` TEXT DEFAULT NULL,
    `ip` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `parent_id` INTEGER NOT NULL DEFAULT 0,
    `content` TEXT DEFAULT NULL,
    `image` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comment_marks` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `comment_id` INTEGER NOT NULL,
    `mark_type` TEXT DEFAULT NULL,
    `operator_id` INTEGER DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `favorites` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `uploads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `url` TEXT DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `sender_id` INTEGER NOT NULL DEFAULT 0,
    `type` TEXT DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `call_records` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `room_id` TEXT DEFAULT NULL,
    `caller_id` INTEGER NOT NULL,
    `callee_id` INTEGER NOT NULL,
    `type` TEXT DEFAULT 'audio',
    `status` TEXT DEFAULT 'calling',
    `duration` INTEGER NOT NULL DEFAULT 0,
    `started_at` TEXT DEFAULT NULL,
    `ended_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `blocked_users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `blocked_user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `reports` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `reporter_id` INTEGER NOT NULL,
    `target_type` TEXT DEFAULT NULL,
    `target_id` INTEGER NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT DEFAULT NULL,
    `owner_id` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `pay_type` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `circle_subscribes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `status` INTEGER NOT NULL DEFAULT 0,
    `expire_at` TEXT
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `messages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `content` TEXT DEFAULT NULL,
    `deleted_at` TEXT DEFAULT NULL
);
SQL;

    private function db(): CompatPdo
    {
        $this->assertNotNull($this->db);
        return $this->db;
    }

    /** 与本测试专用连接对应的 seed（BehaviorTestCase::seed 写的是另一条连接） */
    private function seedRow(string $table, array $row): void
    {
        $cols = array_keys($row);
        foreach ($cols as $col) {
            $this->assertSame(1, preg_match('/^[a-zA-Z0-9_]+$/', (string)$col));
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(array_values($row));
    }

    private function allRows(string $table): array
    {
        $this->assertSame(1, preg_match('/^[a-zA-Z0-9_]+$/', $table));
        return $this->db()->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD 新增无害列 post_type 后，逐字段比对时先剔除该列 */
    private function assertRowsIgnoringPostType(array $headRows, array $repoRows, string $message = ''): void
    {
        $trimmed = array_map(static function (array $row): array {
            unset($row['post_type']);
            return $row;
        }, $repoRows);
        $this->assertSame($headRows, $trimmed, $message);
        foreach ($repoRows as $row) {
            $this->assertArrayHasKey('post_type', $row, '规范 4.3：posts 行查询必须携带 post_type');
        }
    }

    // =====================================================================
    // HEAD 原始 SQL 快照（迁移前原文，禁止"顺手优化"）
    // =====================================================================

    private function headCountRuleBadges(): array
    {
        $stmt = $this->db()->prepare(
            "SELECT badge_key, name, `desc`, icon, color, sort_weight, rule_type, threshold
             FROM badges WHERE status = 1 AND rule_type = 'count' ORDER BY sort_weight DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headAdminBadgeWhere(?string $category, int $status, string $keyword): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($category !== '') {
            $where[] = 'b.category = ?';
            $params[] = $category;
        }
        if ($status >= 0) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where[] = '(b.name LIKE ? OR b.badge_key LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    private function headAdminBadgeList(?string $category, int $status, string $keyword, int $offset, int $limit): array
    {
        [$whereSql, $params] = $this->headAdminBadgeWhere($category, $status, $keyword);
        $stmt = $this->db()->prepare(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 1) AS unlock_count,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 2) AS freeze_count,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 3) AS revoke_count
             FROM badges b
             WHERE {$whereSql}
             ORDER BY b.sort_weight DESC, b.id ASC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headAdminBadgeCount(?string $category, int $status, string $keyword): int
    {
        [$whereSql, $params] = $this->headAdminBadgeWhere($category, $status, $keyword);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM badges b WHERE {$whereSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headBadgeIdById(int $id): ?array
    {
        $stmt = $this->db()->prepare("SELECT id FROM badges WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headBadgeIdByKey(string $key): ?array
    {
        $stmt = $this->db()->prepare("SELECT id FROM badges WHERE badge_key = ? LIMIT 1");
        $stmt->execute([$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headBadgeBriefById(int $id): ?array
    {
        $stmt = $this->db()->prepare("SELECT id, name FROM badges WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headUserBadgeWhere(int $userId, string $keyword, int $badgeId, int $state, string $grantType): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($userId > 0) {
            $where[] = 'ub.user_id = ?';
            $params[] = $userId;
        }
        if ($keyword !== '') {
            $where[] = '(u.nickname LIKE ? OR u.username LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        if ($badgeId > 0) {
            $where[] = 'ub.badge_id = ?';
            $params[] = $badgeId;
        }
        if ($state > 0) {
            $where[] = 'ub.state = ?';
            $params[] = $state;
        }
        if ($grantType !== '') {
            $where[] = 'ub.grant_type = ?';
            $params[] = $grantType;
        }
        return [implode(' AND ', $where), $params];
    }

    private function headUserBadgeList(
        int $userId,
        string $keyword,
        int $badgeId,
        int $state,
        string $grantType,
        int $offset,
        int $limit
    ): array {
        [$whereSql, $params] = $this->headUserBadgeWhere($userId, $keyword, $badgeId, $state, $grantType);
        $stmt = $this->db()->prepare(
            "SELECT ub.*, u.nickname, u.username, b.name AS badge_name, b.badge_key, b.icon, b.color
             FROM user_badges ub
             JOIN users u ON u.id = ub.user_id
             JOIN badges b ON b.id = ub.badge_id
             WHERE {$whereSql}
             ORDER BY ub.updated_at DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headUserBadgeCount(int $userId, string $keyword, int $badgeId, int $state, string $grantType): int
    {
        [$whereSql, $params] = $this->headUserBadgeWhere($userId, $keyword, $badgeId, $state, $grantType);
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*)
             FROM user_badges ub
             JOIN users u ON u.id = ub.user_id
             JOIN badges b ON b.id = ub.badge_id
             WHERE {$whereSql}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headBadgeOptions(): array
    {
        $stmt = $this->db()->prepare(
            "SELECT id, badge_key, name, category, rule_type FROM badges WHERE status = 1 ORDER BY sort_weight DESC, id ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBadgeLogWhere(string $action, int $userId, int $badgeId, string $dateFrom, string $dateTo): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($action !== '') {
            $where[] = 'l.action = ?';
            $params[] = $action;
        }
        if ($userId > 0) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }
        if ($badgeId > 0) {
            $where[] = 'l.badge_id = ?';
            $params[] = $badgeId;
        }
        if ($dateFrom !== '') {
            $where[] = 'l.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'l.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }

    private function headBadgeLogList(
        string $action,
        int $userId,
        int $badgeId,
        string $dateFrom,
        string $dateTo,
        int $offset,
        int $limit
    ): array {
        [$whereSql, $params] = $this->headBadgeLogWhere($action, $userId, $badgeId, $dateFrom, $dateTo);
        $stmt = $this->db()->prepare(
            "SELECT l.*, u.nickname AS user_name, b.name AS badge_name, b.badge_key
             FROM badge_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN badges b ON b.id = l.badge_id
             WHERE {$whereSql}
             ORDER BY l.id DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBadgeLogCount(string $action, int $userId, int $badgeId, string $dateFrom, string $dateTo): int
    {
        [$whereSql, $params] = $this->headBadgeLogWhere($action, $userId, $badgeId, $dateFrom, $dateTo);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM badge_logs l WHERE {$whereSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headGodMarkWhere(string $keyword): array
    {
        $where = "cm.mark_type = 'god'";
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (cu.nickname LIKE ? OR ou.nickname LIKE ? OR c.content LIKE ?)';
            $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }
        return [$where, $params];
    }

    private function headGodMarkCount(string $keyword): int
    {
        [$where, $params] = $this->headGodMarkWhere($keyword);
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*)
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             INNER JOIN users cu ON cu.id = c.user_id
             LEFT JOIN users ou ON ou.id = cm.operator_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headGodMarkRows(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = $this->headGodMarkWhere($keyword);
        $stmt = $this->db()->prepare(
            "SELECT cm.id, cm.comment_id, cm.mark_type, cm.operator_id, cm.created_at,
                    c.user_id AS comment_user_id, c.content, c.post_id,
                    cu.nickname AS comment_author, cu.avatar AS comment_avatar,
                    ou.nickname AS operator_name,
                    p.content AS post_content, pu.nickname AS post_author
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             INNER JOIN users cu ON cu.id = c.user_id
             LEFT JOIN users ou ON ou.id = cm.operator_id
             LEFT JOIN posts p ON p.id = c.post_id
             LEFT JOIN users pu ON pu.id = p.user_id
             WHERE {$where}
             ORDER BY cm.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headGodMarkForId(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT cm.id, cm.comment_id, c.user_id
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             WHERE cm.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headGodMarkForComment(int $commentId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT cm.id, cm.comment_id, c.user_id
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             WHERE cm.comment_id = ? AND mark_type = ? LIMIT 1"
        );
        $stmt->execute([$commentId, 'god']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headVisiblePost(int $postId): ?array
    {
        $stmt = $this->db()->prepare("SELECT id FROM posts WHERE id = ? AND status = 1 LIMIT 1");
        $stmt->execute([$postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headFavoriteRow(int $postId, int $userId): ?array
    {
        $stmt = $this->db()->prepare("SELECT id FROM favorites WHERE post_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$postId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headFavoritePage(
        int $userId,
        int $offset,
        int $limit,
        string $visWhere,
        array $visParams
    ): array {
        $stmt = $this->db()->prepare(
            "SELECT f.post_id, f.created_at AS favorited_at
             FROM favorites f
             INNER JOIN posts p ON f.post_id = p.id AND p.status = 1 AND {$visWhere}
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($visParams, [$userId, $offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headFavoriteCount(int $userId, string $visWhere, array $visParams): int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM favorites f
             INNER JOIN posts p ON f.post_id = p.id AND p.status = 1 AND {$visWhere}
             WHERE f.user_id = ?"
        );
        $stmt->execute(array_merge($visParams, [$userId]));
        return (int)$stmt->fetchColumn();
    }

    private function headFavoritePosts(array $ids, string $visWhere, array $visParams): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.created_at, p.updated_at,
                    p.is_anonymous, p.visibility, p.circle_id, p.location, p.like_count, p.comment_count, p.view_count,
                    u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id IN ({$placeholders}) AND {$visWhere}"
        );
        $stmt->execute(array_merge(array_values($ids), $visParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headLikeRows(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT l.post_id, u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id IN ({$placeholders})
             ORDER BY l.created_at ASC"
        );
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headCommentRows(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$placeholders})
             ORDER BY c.created_at ASC"
        );
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBlockedUrls(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT url FROM uploads WHERE post_id IN ({$placeholders}) AND status = 0"
        );
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headListWhere(int $userId, array $types, int $beforeId): array
    {
        $where = 'WHERE n.user_id = ?';
        $params = [$userId];
        if ($types) {
            $where .= ' AND n.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params = array_merge($params, $types);
        }
        if ($beforeId > 0) {
            $where .= ' AND n.id < ?';
            $params[] = $beforeId;
        }
        return [$where, $params];
    }

    private function headCursorNotifications(int $userId, array $types, int $beforeId, int $limit): array
    {
        [$where, $params] = $this->headListWhere($userId, $types, $beforeId);
        $stmt = $this->db()->prepare(
            "SELECT n.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM notifications n
             LEFT JOIN users u ON n.sender_id = u.id
             {$where}
             ORDER BY n.id DESC
             LIMIT ?"
        );
        $stmt->execute(array_merge($params, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headOffsetNotifications(int $userId, array $types, int $offset, int $limit): array
    {
        [$where, $params] = $this->headListWhere($userId, $types, 0);
        $stmt = $this->db()->prepare(
            "SELECT n.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM notifications n
             LEFT JOIN users u ON n.sender_id = u.id
             {$where}
             ORDER BY n.is_read ASC, n.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headPostSummaries(int $viewerId, array $ids): array
    {
        [$w, $p] = VisibilityPolicy::where($viewerId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db()->prepare(
            "SELECT p.id, p.content, p.images FROM posts p
             WHERE p.status = 1 AND p.id IN ({$ph}) AND {$w}"
        );
        $stmt->execute(array_merge(array_values($ids), $p));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headCountWhere(int $userId, array $types): array
    {
        $where = 'WHERE user_id = ?';
        $params = [$userId];
        if ($types) {
            $where .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params = array_merge($params, $types);
        }
        return [$where, $params];
    }

    private function headCountNotifications(int $userId, array $types): int
    {
        [$where, $params] = $this->headCountWhere($userId, $types);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM notifications {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headCountUnreadNotifications(int $userId, array $types): int
    {
        [$where, $params] = $this->headCountWhere($userId, $types);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM notifications {$where} AND is_read = 0");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headUnreadByType(int $userId): array
    {
        $stmt = $this->db()->prepare(
            "SELECT type, COUNT(*) AS cnt FROM notifications
             WHERE user_id = ? AND is_read = 0 GROUP BY type"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headAllUnread(int $userId): int
    {
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function headCallee(int $userId): ?array
    {
        $stmt = $this->db()->prepare("SELECT id, status, deleted_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headBlockRow(int $userId, int $otherId): mixed
    {
        $stmt = $this->db()->prepare("SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1");
        $stmt->execute([$userId, $otherId]);
        return $stmt->fetchColumn();
    }

    private function headActiveCall(int $callerId, int $calleeId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT room_id, status, type, created_at
             FROM call_records
             WHERE caller_id = ? AND callee_id = ? AND status IN ('calling', 'accepted')
               AND created_at > DATE_SUB(NOW(), INTERVAL 300 SECOND)
             LIMIT 1"
        );
        $stmt->execute([$callerId, $calleeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headCallByRoom(string $roomId): ?array
    {
        $stmt = $this->db()->prepare("SELECT * FROM call_records WHERE room_id = ? LIMIT 1");
        $stmt->execute([$roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headPendingCall(int $calleeId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT * FROM call_records
             WHERE callee_id = ? AND status = 'calling'
               AND created_at > DATE_SUB(NOW(), INTERVAL 90 SECOND)
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$calleeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headCallWhere(int $me, int $peerId): array
    {
        $where = '(caller_id = ? OR callee_id = ?)';
        $params = [$me, $me];
        if ($peerId > 0) {
            $where .= ' AND ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?))';
            $params[] = $me;
            $params[] = $peerId;
            $params[] = $peerId;
            $params[] = $me;
        }
        return [$where, $params];
    }

    private function headCallCount(int $me, int $peerId): int
    {
        [$where, $params] = $this->headCallWhere($me, $peerId);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM call_records WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headCallRows(int $me, int $peerId, int $limit, int $offset): array
    {
        [$where, $params] = $this->headCallWhere($me, $peerId);
        $stmt = $this->db()->prepare(
            "SELECT * FROM call_records WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBriefUser(int $userId): ?array
    {
        $stmt = $this->db()->prepare("SELECT id, nickname, username, avatar FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headPostTarget(int $targetId): mixed
    {
        $stmt = $this->db()->prepare("SELECT id FROM posts WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        return $stmt->fetchColumn();
    }

    private function headTargetInTable(string $table, int $targetId): mixed
    {
        $stmt = $this->db()->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$targetId]);
        return $stmt->fetchColumn();
    }

    private function headUndeletedMessage(int $targetId): mixed
    {
        $stmt = $this->db()->prepare("SELECT id FROM messages WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$targetId]);
        return $stmt->fetchColumn();
    }

    private function headPendingReport(int $reporterId, string $targetType, int $targetId): mixed
    {
        $stmt = $this->db()->prepare(
            "SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 0 LIMIT 1"
        );
        $stmt->execute([$reporterId, $targetType, $targetId]);
        return $stmt->fetchColumn();
    }

    private function headReportWhere(int $status): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status >= 0) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        return [$where, $params];
    }

    private function headReportRows(int $status, int $offset, int $limit): array
    {
        [$where, $params] = $this->headReportWhere($status);
        $stmt = $this->db()->prepare(
            "SELECT r.id, r.reporter_id, r.target_type, r.target_id, r.reason, r.description, r.status, r.created_at,
                    u.username AS reporter_username, u.nickname AS reporter_nickname, u.avatar AS reporter_avatar
             FROM reports r
             INNER JOIN users u ON r.reporter_id = u.id
             {$where} ORDER BY r.status ASC, r.created_at DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headReportCount(int $status): int
    {
        [$where, $params] = $this->headReportWhere($status);
        $stmt = $this->db()->prepare("SELECT COUNT(*) FROM reports r {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // =====================================================================
    // Badge 域
    // =====================================================================

    private function seedBadgeFixture(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'status' => 1],
            ['id' => 2, 'username' => 'bob', 'nickname' => '鲍勃', 'status' => 1],
            ['id' => 3, 'username' => 'carol', 'nickname' => '卡罗', 'status' => 0],
        ] as $user) {
            $this->seedRow('users', $user);
        }
        foreach ([
            ['id' => 1, 'badge_key' => 'first_post', 'name' => '初出茅庐', 'desc' => '发首帖', 'category' => 'creation', 'sort_weight' => 10, 'status' => 1],
            ['id' => 2, 'badge_key' => 'god_commenter', 'name' => '神评选手', 'desc' => '神评三次', 'category' => 'social', 'sort_weight' => 30, 'status' => 1],
            ['id' => 3, 'badge_key' => 'disabled', 'name' => '停用', 'desc' => '已禁用', 'category' => 'creation', 'sort_weight' => 50, 'status' => 0],
            ['id' => 4, 'badge_key' => 'timed', 'name' => '限时', 'desc' => '限时', 'category' => 'social', 'rule_type' => 'timed', 'sort_weight' => 20, 'status' => 1],
        ] as $badge) {
            $this->seedRow('badges', $badge);
        }
        foreach ([
            ['id' => 1, 'user_id' => 1, 'badge_id' => 1, 'state' => 1, 'grant_type' => 'auto', 'updated_at' => '2024-02-01 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'badge_id' => 2, 'state' => 2, 'grant_type' => 'manual', 'updated_at' => '2024-01-01 00:00:00'],
            ['id' => 3, 'user_id' => 2, 'badge_id' => 1, 'state' => 3, 'grant_type' => 'manual', 'updated_at' => '2024-03-01 00:00:00'],
        ] as $ub) {
            $this->seedRow('user_badges', $ub);
        }
        foreach ([
            ['id' => 1, 'operator_id' => 2, 'user_id' => 1, 'badge_id' => 1, 'action' => 'config_create', 'remark' => '新增', 'created_at' => '2024-01-01 10:00:00'],
            ['id' => 2, 'operator_id' => 2, 'user_id' => 1, 'badge_id' => 2, 'action' => 'config_toggle', 'remark' => '禁用', 'created_at' => '2024-02-01 10:00:00'],
            ['id' => 3, 'operator_id' => 2, 'user_id' => 2, 'badge_id' => 0, 'action' => 'admin_god_unmark', 'remark' => '撤标', 'created_at' => '2024-03-01 10:00:00'],
        ] as $log) {
            $this->seedRow('badge_logs', $log);
        }
        $this->seedRow('posts', ['id' => 10, 'user_id' => 1, 'content' => '主帖内容', 'status' => 1, 'post_type' => 'normal']);
        foreach ([
            ['id' => 100, 'post_id' => 10, 'user_id' => 1, 'content' => '这条评论很神', 'created_at' => '2024-01-01 00:00:00'],
            ['id' => 101, 'post_id' => 10, 'user_id' => 2, 'content' => '普通评论', 'created_at' => '2024-01-02 00:00:00'],
        ] as $comment) {
            $this->seedRow('comments', $comment);
        }
        foreach ([
            ['id' => 1, 'comment_id' => 100, 'mark_type' => 'god', 'operator_id' => 2, 'created_at' => '2024-01-03 00:00:00'],
            ['id' => 2, 'comment_id' => 101, 'mark_type' => 'hot', 'operator_id' => 2, 'created_at' => '2024-01-04 00:00:00'],
        ] as $mark) {
            $this->seedRow('comment_marks', $mark);
        }
    }

    public function testBadgeQueriesMatchHeadSnapshots(): void
    {
        $this->seedBadgeFixture();

        $this->assertSame($this->headCountRuleBadges(), BadgeRepository::fetchCountRuleBadges());
        $this->assertSame(
            ['god_commenter', 'first_post'],
            array_column(BadgeRepository::fetchCountRuleBadges(), 'badge_key'),
            'sort_weight DESC 且 status=1 / rule_type=count'
        );

        foreach ([['', -1, '', 0, 20], ['creation', -1, '', 0, 20], ['', 1, '', 0, 2], ['', -1, '', 2, 2], ['', -1, '神', 0, 20], ['social', 1, 'god', 0, 20]] as [$cat, $status, $kw, $off, $lim]) {
            $this->assertSame(
                $this->headAdminBadgeList($cat, $status, $kw, $off, $lim),
                BadgeRepository::fetchAdminBadgePage($cat, $status, $kw, $off, $lim),
                "徽章配置列表 {$cat}/{$status}/{$kw}/{$off}+{$lim}"
            );
            $this->assertSame(
                $this->headAdminBadgeCount($cat, $status, $kw),
                BadgeRepository::countAdminBadges($cat, $status, $kw)
            );
        }
        $this->assertSame([], BadgeRepository::fetchAdminBadgePage('', 0, '不存在', 0, 20), '空结果负例');

        // 三个相似探测：字段集 / WHERE 都不同，交叉断言不可合并
        $this->assertSame($this->headBadgeIdById(1), BadgeRepository::findBadgeIdById(1));
        $this->assertSame($this->headBadgeIdByKey('god_commenter'), BadgeRepository::findBadgeIdByKey('god_commenter'));
        $this->assertSame($this->headBadgeBriefById(1), BadgeRepository::findBadgeBriefById(1));
        $this->assertSame(['id'], array_keys(BadgeRepository::findBadgeIdById(1)));
        $this->assertSame(['id', 'name'], array_keys(BadgeRepository::findBadgeBriefById(1)));
        $this->assertNotSame(
            array_keys(BadgeRepository::findBadgeIdById(1)),
            array_keys(BadgeRepository::findBadgeBriefById(1))
        );
        $this->assertNull(BadgeRepository::findBadgeIdById(999), '不存在的 id');
        $this->assertNotNull(BadgeRepository::findBadgeBriefById(3), 'status=0 仍可探测（与下拉不同口径）');

        foreach ([[1, '', 0, -1, '', 0, 20], [0, '爱丽丝', 0, -1, '', 0, 20], [0, '', 1, 2, 'manual', 0, 2], [0, '', 0, -1, '', 2, 2], [2, '', 0, 3, '', 0, 20]] as [$uid, $kw, $bid, $state, $grant, $off, $lim]) {
            $this->assertSame(
                $this->headUserBadgeList($uid, $kw, $bid, $state, $grant, $off, $lim),
                BadgeRepository::fetchUserBadgePage($uid, $kw, $bid, $state, $grant, $off, $lim),
                "持有列表 {$uid}/{$kw}/{$bid}/{$state}/{$grant}"
            );
            $this->assertSame(
                $this->headUserBadgeCount($uid, $kw, $bid, $state, $grant),
                BadgeRepository::countUserBadges($uid, $kw, $bid, $state, $grant)
            );
        }
        $this->assertSame([], BadgeRepository::fetchUserBadgePage(0, '无人', 0, -1, '', 0, 20));
        $this->assertSame($this->headBadgeOptions(), BadgeRepository::fetchActiveBadgeOptions());
        $this->assertSame([2, 4, 1], array_column(BadgeRepository::fetchActiveBadgeOptions(), 'id'), '下拉仅启用徽章且按权重倒序');
        $this->assertNotSame(
            array_keys(BadgeRepository::fetchCountRuleBadges()[0]),
            array_keys(BadgeRepository::fetchActiveBadgeOptions()[0]),
            '进度查询 8 列 ≠ 下拉 5 列'
        );

        foreach ([['', 0, 0, '', '', 0, 20], ['config_toggle', 0, 0, '', '', 0, 20], ['', 1, 0, '2024-02-01', '', 0, 20], ['', 0, 2, '', '2024-02-28', 0, 2], ['', 0, 0, '', '', 2, 2]] as [$act, $uid, $bid, $from, $to, $off, $lim]) {
            $this->assertSame(
                $this->headBadgeLogList($act, $uid, $bid, $from, $to, $off, $lim),
                BadgeRepository::fetchBadgeLogPage($act, $uid, $bid, $from, $to, $off, $lim),
                "日志列表 {$act}/{$uid}/{$bid}/{$from}/{$to}"
            );
            $this->assertSame(
                $this->headBadgeLogCount($act, $uid, $bid, $from, $to),
                BadgeRepository::countBadgeLogs($act, $uid, $bid, $from, $to)
            );
        }
        $this->assertSame([], BadgeRepository::fetchBadgeLogPage('不存在', 0, 0, '', '', 0, 20));

        foreach ([[0, 20], [0, 1], [1, 1]] as [$off, $lim]) {
            $this->assertRowsIgnoringPostType(
                $this->headGodMarkRows('', $off, $lim),
                BadgeRepository::fetchGodMarks('', $off, $lim),
                "神评台账分页 {$off}+{$lim}"
            );
        }
        $this->assertSame($this->headGodMarkCount(''), BadgeRepository::countGodMarks(''));
        $this->assertRowsIgnoringPostType(
            $this->headGodMarkRows('神', 0, 20),
            BadgeRepository::fetchGodMarks('神', 0, 20),
            '关键字三列 LIKE'
        );
        $this->assertSame(1, BadgeRepository::countGodMarks('神'));
        $this->assertSame([], BadgeRepository::fetchGodMarks('不存在', 0, 20));
        $this->assertSame(['id', 'comment_id', 'user_id'], array_keys(BadgeRepository::findGodMarkForDeleteById(1)));
        $this->assertSame($this->headGodMarkForId(1), BadgeRepository::findGodMarkForDeleteById(1));
        $this->assertSame($this->headGodMarkForComment(100), BadgeRepository::findGodMarkForDeleteByComment(100));
        $this->assertNull(BadgeRepository::findGodMarkForDeleteByComment(101), 'mark_type=hot 不算神评，负例');
        $this->assertNull(BadgeRepository::findGodMarkForDeleteById(0), '空参数守卫');
    }

    public function testBadgeWritesAndFieldWhitelist(): void
    {
        $this->seedBadgeFixture();
        $now = '2024-06-01 00:00:00';

        $newId = BadgeRepository::insertBadgeConfig([
            'badge_key' => 'new_badge', 'name' => '新徽章', 'desc' => '', 'category' => 'creation',
            'icon' => 'award', 'color' => '#fff', 'rule_type' => 'count', 'threshold' => null,
            'sort_weight' => 5, 'hideable' => 0, 'display' => 1, 'status' => 1,
            'updated_at' => $now, 'created_at' => $now,
        ]);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame('new_badge', $this->headBadgeIdByKey('new_badge') ? 'new_badge' : '');

        BadgeRepository::updateBadgeConfig($newId, ['name' => '改后', 'updated_at' => $now]);
        $this->assertSame('改后', $this->headBadgeBriefById($newId)['name']);
        $this->assertSame(1, BadgeRepository::setBadgeStatus($newId, 0, $now));
        $this->assertSame(3, count(BadgeRepository::fetchActiveBadgeOptions()), '禁用后下拉少一枚');

        $threw = false;
        try {
            BadgeRepository::updateBadgeConfig($newId, ['id = ?' => 'x']);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertSame('非法徽章配置字段', $e->getMessage());
        }
        $this->assertTrue($threw, '动态 SET 列名白名单 + 正则校验');

        $logId = BadgeRepository::insertConfigLog([
            'operator_id' => 2, 'operator_name' => 'bob', 'user_id' => 0, 'badge_id' => 0,
            'action' => 'admin_god_unmark', 'remark' => '撤销神评标记 comment_id=100',
            'ip' => '127.0.0.1', 'created_at' => $now,
        ]);
        $this->assertGreaterThan(0, $logId);
        $logs = $this->allRows('badge_logs');
        $this->assertSame('admin_god_unmark', end($logs)['action']);
        $this->assertSame(1, BadgeRepository::deleteGodMark(1));
        $this->assertSame(0, BadgeRepository::deleteGodMark(999), '重复删除返回 0 行');
    }

    // =====================================================================
    // Favorite 域
    // =====================================================================

    /** @return array{0:string,1:array} 游客可见性片段 */
    private function guestWhere(): array
    {
        return ["(p.visibility = 'public')", []];
    }

    private function seedFavoriteFixture(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'status' => 1],
            ['id' => 2, 'username' => 'bob', 'nickname' => '鲍勃', 'status' => 1],
            ['id' => 3, 'username' => 'carol', 'nickname' => '卡罗', 'status' => 1],
        ] as $user) {
            $this->seedRow('users', $user);
        }
        foreach ([
            ['id' => 1, 'user_id' => 1, 'title' => '帖子一', 'content' => '内容一', 'images' => '["a.png"]', 'status' => 1, 'post_type' => 'normal', 'visibility' => 'public', 'is_anonymous' => 1, 'created_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'user_id' => 2, 'title' => '帖子二', 'content' => '内容二', 'status' => 1, 'post_type' => 'image', 'visibility' => 'public', 'is_anonymous' => 0, 'created_at' => '2024-01-02 00:00:00'],
            ['id' => 3, 'user_id' => 2, 'title' => '下架帖', 'content' => '隐藏', 'status' => 0, 'post_type' => 'normal', 'visibility' => 'public', 'is_anonymous' => 0, 'created_at' => '2024-01-03 00:00:00'],
            ['id' => 4, 'user_id' => 2, 'title' => '私密帖', 'content' => '不可见', 'status' => 1, 'post_type' => 'normal', 'visibility' => 'private', 'is_anonymous' => 0, 'created_at' => '2024-01-04 00:00:00'],
        ] as $post) {
            $this->seedRow('posts', $post);
        }
        foreach ([
            ['id' => 1, 'post_id' => 1, 'user_id' => 1, 'created_at' => '2024-02-01 00:00:00'],
            ['id' => 2, 'post_id' => 2, 'user_id' => 1, 'created_at' => '2024-02-02 00:00:00'],
            ['id' => 3, 'post_id' => 3, 'user_id' => 1, 'created_at' => '2024-02-03 00:00:00'],
            ['id' => 4, 'post_id' => 1, 'user_id' => 2, 'created_at' => '2024-02-04 00:00:00'],
        ] as $fav) {
            $this->seedRow('favorites', $fav);
        }
        foreach ([
            ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'created_at' => '2024-03-01 00:00:00'],
            ['id' => 2, 'post_id' => 1, 'user_id' => 3, 'created_at' => '2024-03-02 00:00:00'],
        ] as $like) {
            $this->seedRow('likes', $like);
        }
        foreach ([
            ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'content' => '评论一', 'created_at' => '2024-04-01 00:00:00'],
            ['id' => 2, 'post_id' => 1, 'user_id' => 3, 'content' => '评论二', 'created_at' => '2024-04-02 00:00:00'],
            ['id' => 3, 'post_id' => 2, 'user_id' => 3, 'content' => '评论三', 'created_at' => '2024-04-03 00:00:00'],
        ] as $comment) {
            $this->seedRow('comments', $comment);
        }
        $this->seedRow('uploads', ['id' => 1, 'url' => 'blocked.png', 'post_id' => 1, 'status' => 0]);
        $this->seedRow('uploads', ['id' => 2, 'url' => 'ok.png', 'post_id' => 1, 'status' => 1]);
    }

    public function testFavoriteQueriesMatchHeadSnapshots(): void
    {
        $this->seedFavoriteFixture();

        [$visWhere, $visParams] = $this->guestWhere();
        $visible = FavoriteRepository::findVisiblePost(1, 0);
        $this->assertSame($this->headVisiblePost(1), ['id' => $visible['id']]);
        $this->assertSame('normal', $visible['post_type']);
        $this->assertNull(FavoriteRepository::findVisiblePost(3, 0), 'status=0 动态不可收藏');
        $this->assertNull(FavoriteRepository::findVisiblePost(999, 0), '空结果负例');
        $this->assertNull(FavoriteRepository::findVisiblePost(4, 0), '他人 private 动态不可收藏');
        $this->assertNotNull(FavoriteRepository::findVisiblePost(4, 2), '作者本人可收藏自己的 private 动态');
        $this->assertSame($this->headFavoriteRow(1, 1), FavoriteRepository::findFavoriteRow(1, 1));
        $this->assertNull(FavoriteRepository::findFavoriteRow(2, 2), '非本人/未收藏负例');

        foreach ([[0, 2], [2, 2], [0, 20]] as [$offset, $limit]) {
            $this->assertRowsIgnoringPostType(
                $this->headFavoritePage(1, $offset, $limit, $visWhere, $visParams),
                FavoriteRepository::fetchVisibleFavoritePage(1, $offset, $limit, $visWhere, $visParams),
                "收藏列表分页 {$offset}+{$limit}"
            );
        }
        $this->assertSame(
            $this->headFavoriteCount(1, $visWhere, $visParams),
            FavoriteRepository::countVisibleFavorites(1, $visWhere, $visParams)
        );
        $this->assertSame(2, FavoriteRepository::countVisibleFavorites(1, $visWhere, $visParams), '下架帖不计入');
        $this->assertSame([], FavoriteRepository::fetchVisibleFavoritePage(999, 0, 20, $visWhere, $visParams));

        $this->assertRowsIgnoringPostType(
            $this->headFavoritePosts([2, 1], $visWhere, $visParams),
            FavoriteRepository::fetchFavoritePosts([2, 1], $visWhere, $visParams),
            '批量详情按传入 id 顺序不使用 ORDER BY，由上层重排'
        );
        // 可见性二次过滤：private 帖即使被传入 id 列表也不返回
        $this->assertSame([], FavoriteRepository::fetchFavoritePosts([4], $visWhere, $visParams));
        $this->assertSame($this->headLikeRows([1, 2]), FavoriteRepository::fetchLikeRows([1, 2]));
        $this->assertSame($this->headCommentRows([1, 2]), FavoriteRepository::fetchCommentRows([1, 2]));
        $this->assertSame($this->headBlockedUrls([1, 2]), FavoriteRepository::fetchBlockedUploadUrls([1, 2]));
        $this->assertSame([], FavoriteRepository::fetchFavoritePosts([], $visWhere, $visParams));
        $this->assertSame([], FavoriteRepository::fetchLikeRows([]));
        $this->assertSame([], FavoriteRepository::fetchCommentRows([]));
        $this->assertSame([], FavoriteRepository::fetchBlockedUploadUrls([]));

        // 相似 SQL 字段集差异交叉断言（4 个批量查询列数 26/4/10/1）
        $this->assertCount(26, FavoriteRepository::fetchFavoritePosts([1], $visWhere, $visParams)[0]);
        $this->assertSame(1, (int)FavoriteRepository::fetchFavoritePosts([1], $visWhere, $visParams)[0]['is_anonymous']);
        $this->assertSame(['post_id', 'id', 'username', 'nickname'], array_keys(FavoriteRepository::fetchLikeRows([1])[0]));
        $this->assertCount(10, FavoriteRepository::fetchCommentRows([1])[0]);
        $this->assertSame(['url'], array_keys(FavoriteRepository::fetchBlockedUploadUrls([1])[0]));
        $this->assertNotSame(
            array_keys(FavoriteRepository::fetchFavoritePosts([1], $visWhere, $visParams)[0]),
            array_keys(FavoriteRepository::fetchCommentRows([1])[0])
        );
    }

    public function testFavoriteToggleTransactionSuccessAndRollback(): void
    {
        $this->seedFavoriteFixture();
        $now = '2024-06-01 00:00:00';

        // 成功插入
        $this->assertTrue(FavoriteRepository::toggleFavorite(2, 3, $now));
        $this->assertSame($this->headFavoriteRow(2, 3), FavoriteRepository::findFavoriteRow(2, 3));
        $this->assertCount(5, $this->allRows('favorites'));

        // 成功删除（复用外部已读到的行）
        $row = FavoriteRepository::findFavoriteRow(2, 3);
        $this->assertFalse(FavoriteRepository::toggleFavorite(2, 3, $now, $row));
        $this->assertNull(FavoriteRepository::findFavoriteRow(2, 3));
        $this->assertCount(4, $this->allRows('favorites'));

        // 入口守卫下沉：不存在 / 已下架 / 非法 id 一律返回 null 且不写库
        $this->assertNull(FavoriteRepository::toggleFavorite(3, 1, $now), 'status=0 不可收藏');
        $this->assertNull(FavoriteRepository::toggleFavorite(999, 1, $now), '不存在的动态');
        $this->assertNull(FavoriteRepository::toggleFavorite(0, 1, $now), '非法参数守卫');
        $this->assertCount(4, $this->allRows('favorites'));

        // 异常回滚：trigger 阻断插入，事务内不得残留任何写
        $this->db()->exec(<<<'SQL'
CREATE TRIGGER fail_favorite BEFORE INSERT ON favorites
WHEN NEW.user_id = 666 BEGIN SELECT RAISE(ABORT, 'favorite insert failed'); END;
SQL);
        try {
            FavoriteRepository::toggleFavorite(1, 666, $now);
            $this->fail('应当抛出 PDOException');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('favorite insert failed', $e->getMessage());
        }
        $this->assertFalse($this->db()->inTransaction(), '异常路径已 rollBack');
        $this->assertSame([], array_filter($this->allRows('favorites'), static fn(array $r): bool => (int)$r['user_id'] === 666));
        $this->assertCount(4, $this->allRows('favorites'), '事务外原有数据不受影响');
    }

    // =====================================================================
    // Notification 域
    // =====================================================================

    private function seedNotificationFixture(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'status' => 1],
            ['id' => 2, 'username' => 'bob', 'nickname' => '鲍勃', 'status' => 1],
        ] as $user) {
            $this->seedRow('users', $user);
        }
        foreach ([
            ['id' => 1, 'user_id' => 1, 'content' => '友邻帖', 'images' => '["one.png"]', 'status' => 1, 'post_type' => 'normal', 'visibility' => 'public'],
            ['id' => 2, 'user_id' => 2, 'content' => '下架帖', 'images' => null, 'status' => 0, 'post_type' => 'image', 'visibility' => 'private'],
        ] as $post) {
            $this->seedRow('posts', $post);
        }
        foreach ([
            ['id' => 1, 'user_id' => 1, 'sender_id' => 2, 'type' => 'comment', 'post_id' => 1, 'content' => '评论了你', 'is_read' => 0, 'created_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'sender_id' => 2, 'type' => 'like', 'post_id' => 1, 'content' => '赞了你', 'is_read' => 0, 'created_at' => '2024-01-02 00:00:00'],
            ['id' => 3, 'user_id' => 1, 'sender_id' => 2, 'type' => 'comment_like', 'post_id' => 2, 'content' => '赞了评论', 'is_read' => 1, 'created_at' => '2024-01-03 00:00:00'],
            ['id' => 4, 'user_id' => 1, 'sender_id' => 0, 'type' => 'system', 'post_id' => null, 'content' => '系统通知', 'is_read' => 0, 'created_at' => '2024-01-04 00:00:00'],
            ['id' => 5, 'user_id' => 2, 'sender_id' => 1, 'type' => 'comment', 'post_id' => 1, 'content' => '他人通知', 'is_read' => 0, 'created_at' => '2024-01-05 00:00:00'],
        ] as $n) {
            $this->seedRow('notifications', $n);
        }
    }

    public function testNotificationQueriesMatchHeadSnapshots(): void
    {
        $this->seedNotificationFixture();

        $this->assertSame($this->headCursorNotifications(1, [], 4, 2), NotificationRepository::fetchCursorPage(1, [], 4, 2));
        $this->assertSame($this->headCursorNotifications(1, ['comment', 'like'], 5, 2), NotificationRepository::fetchCursorPage(1, ['comment', 'like'], 5, 2));
        $this->assertSame([3, 2], array_column(NotificationRepository::fetchCursorPage(1, [], 4, 2), 'id'), '游标 ORDER BY n.id DESC');
        $this->assertSame([], NotificationRepository::fetchCursorPage(1, [], 1, 2), '游标空结果负例');

        foreach ([[0, 2], [2, 2], [0, 20]] as [$offset, $limit]) {
            $this->assertSame(
                $this->headOffsetNotifications(1, [], $offset, $limit),
                NotificationRepository::fetchOffsetPage(1, [], $offset, $limit),
                "通知分页 {$offset}+{$limit}"
            );
        }
        $this->assertSame(
            $this->headOffsetNotifications(1, ['comment', 'comment_like'], 0, 20),
            NotificationRepository::fetchOffsetPage(1, ['comment', 'comment_like'], 0, 20)
        );
        $this->assertSame([4, 2, 1, 3], array_column(NotificationRepository::fetchOffsetPage(1, [], 0, 20), 'id'), '未读优先 + 同状态内时间倒序');
        $this->assertSame([], NotificationRepository::fetchOffsetPage(999, [], 0, 20));

        $this->assertRowsIgnoringPostType(
            $this->headPostSummaries(1, [1, 2]),
            NotificationRepository::fetchPostSummaries(1, [1, 2]),
            '下架/私密帖不得进通知摘要映射'
        );
        $this->assertSame([1], array_column(NotificationRepository::fetchPostSummaries(1, [1, 2]), 'id'));
        $this->assertSame([], NotificationRepository::fetchPostSummaries(1, []));

        // 计数 WHERE 不含游标条件：传入 beforeId 只影响列表，不影响页数
        foreach ([[], ['comment'], ['like', 'system']] as $types) {
            $this->assertSame($this->headCountNotifications(1, $types), NotificationRepository::countNotifications(1, $types));
            $this->assertSame($this->headCountUnreadNotifications(1, $types), NotificationRepository::countUnreadNotifications(1, $types));
        }
        $this->assertSame(4, NotificationRepository::countNotifications(1, []));
        $this->assertSame(3, NotificationRepository::countUnreadNotifications(1, []));
        $this->assertSame($this->headUnreadByType(1), NotificationRepository::fetchUnreadCountsByType(1));
        $this->assertSame($this->headAllUnread(1), NotificationRepository::countAllUnread(1));
        $this->assertSame(0, NotificationRepository::countAllUnread(999));
        $this->assertSame('comment', NotificationRepository::fetchUnreadCountsByType(1)[0]['type'], 'GROUP BY type');

        // 类型白名单在仓储内二次校验
        $threw = false;
        try {
            NotificationRepository::fetchOffsetPage(1, ['bogus'], 0, 20);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertSame('非法通知类型', $e->getMessage());
        }
        $this->assertTrue($threw);
    }

    public function testNotificationWritesRespectOwnerScope(): void
    {
        $this->seedNotificationFixture();

        $this->assertSame(1, NotificationRepository::markRead(1, 1));
        $this->assertSame(1, (int)$this->allRows('notifications')[0]['is_read']);
        $this->assertSame(0, NotificationRepository::markRead(5, 1), '他人通知不可标记');
        $this->assertSame(0, NotificationRepository::markRead(0, 1), 'id=0 守卫');
        $this->assertSame(2, NotificationRepository::markAllRead(1), '前面已把 id=1 标为已读');
        $this->assertSame(0, NotificationRepository::countAllUnread(1));
        $this->assertSame(0, NotificationRepository::deleteNotification(5, 1), '他人通知不可删除');
        $this->assertSame(1, NotificationRepository::deleteNotification(2, 1));
        $this->assertCount(4, $this->allRows('notifications'));
        $this->assertSame(3, NotificationRepository::deleteAllNotifications(1));
        $this->assertSame([5], array_column($this->allRows('notifications'), 'id'), '只清空本人');
        $this->assertFalse($this->db()->inTransaction());
    }

    // =====================================================================
    // Call 域
    // =====================================================================

    private function seedCallFixture(): void
    {
        $fresh = date('Y-m-d H:i:s', time() - 10);
        $stale = date('Y-m-d H:i:s', time() - 1000);
        foreach ([
            ['id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'avatar' => 'a.png', 'status' => 1, 'deleted_at' => null],
            ['id' => 2, 'username' => 'bob', 'nickname' => '鲍勃', 'avatar' => 'b.png', 'status' => 1, 'deleted_at' => null],
            ['id' => 3, 'username' => 'gone', 'nickname' => '已注销', 'avatar' => '', 'status' => 1, 'deleted_at' => '2024-01-01 00:00:00'],
            ['id' => 4, 'username' => 'banned', 'nickname' => '禁用', 'avatar' => '', 'status' => 0, 'deleted_at' => null],
        ] as $user) {
            $this->seedRow('users', $user);
        }
        foreach ([
            ['id' => 1, 'user_id' => 1, 'blocked_user_id' => 2, 'created_at' => $fresh],
            ['id' => 2, 'user_id' => 3, 'blocked_user_id' => 1, 'created_at' => $fresh],
        ] as $block) {
            $this->seedRow('blocked_users', $block);
        }
        foreach ([
            ['id' => 1, 'room_id' => '10000001', 'caller_id' => 1, 'callee_id' => 2, 'type' => 'audio', 'status' => 'calling', 'created_at' => $fresh],
            ['id' => 2, 'room_id' => '10000002', 'caller_id' => 1, 'callee_id' => 2, 'type' => 'video', 'status' => 'ended', 'duration' => 12, 'created_at' => $stale, 'ended_at' => $stale],
            ['id' => 3, 'room_id' => '10000003', 'caller_id' => 2, 'callee_id' => 1, 'type' => 'audio', 'status' => 'calling', 'created_at' => $stale],
            ['id' => 4, 'room_id' => '10000004', 'caller_id' => 1, 'callee_id' => 3, 'type' => 'video', 'status' => 'rejected', 'created_at' => $fresh],
            ['id' => 5, 'room_id' => '10000005', 'caller_id' => 2, 'callee_id' => 1, 'type' => 'audio', 'status' => 'calling', 'created_at' => $fresh],
        ] as $call) {
            $this->seedRow('call_records', $call);
        }
    }

    public function testCallQueriesMatchHeadSnapshots(): void
    {
        $this->seedCallFixture();

        $this->assertSame($this->headCallee(2), CallRepository::findCallee(2));
        $this->assertSame('2024-01-01 00:00:00', CallRepository::findCallee(3)['deleted_at'], '软删用户由控制器拒绝');
        $this->assertSame(0, (int)CallRepository::findCallee(4)['status'], '禁用用户由控制器拒绝');
        $this->assertNull(CallRepository::findCallee(999));
        $this->assertSame($this->headBlockRow(1, 2), CallRepository::existsBlockRow(1, 2));
        $this->assertSame($this->headBlockRow(2, 1), CallRepository::existsBlockRow(2, 1));

        $this->assertSame($this->headActiveCall(1, 2), CallRepository::findActiveCall(1, 2, 300));
        $this->assertSame('10000001', CallRepository::findActiveCall(1, 2, 300)['room_id']);
        $this->assertSame($this->headActiveCall(2, 3), CallRepository::findActiveCall(2, 3, 300));
        $this->assertNull(CallRepository::findActiveCall(1, 3, 300), '非 calling/accepted 不复用');

        $this->assertSame($this->headCallByRoom('10000002'), CallRepository::findCallByRoom('10000002'));
        $this->assertNull(CallRepository::findCallByRoom('不存在'));
        $this->assertSame($this->headPendingCall(1), CallRepository::findPendingCall(1, 90));
        $this->assertSame(5, (int)CallRepository::findPendingCall(1, 90)['id'], '只返回 90 秒内仍在响铃的最新来电');
        $this->assertSame(1, (int)CallRepository::findPendingCall(2, 90)['id'], '被叫 2 的响铃仍在有效期内');
        $this->assertNull(CallRepository::findPendingCall(999, 90), '空结果负例');

        foreach ([[0, 2], [2, 2], [0, 20]] as [$offset, $limit]) {
            $this->assertSame($this->headCallRows(1, 0, $limit, $offset), CallRepository::fetchRecords(1, 0, $limit, $offset), "通话分页 {$offset}+{$limit}");
            $this->assertSame($this->headCallRows(1, 2, $limit, $offset), CallRepository::fetchRecords(1, 2, $limit, $offset), "按对象筛选 {$offset}+{$limit}");
            $this->assertSame($this->headCallCount(1, 0), CallRepository::countRecords(1, 0));
            $this->assertSame($this->headCallCount(1, 2), CallRepository::countRecords(1, 2));
        }
        $this->assertSame([], CallRepository::fetchRecords(999, 0, 20, 0));
        $this->assertSame(0, CallRepository::countRecords(999, 0));

        $this->assertSame($this->headBriefUser(1), CallRepository::findBriefUser(1));
        $this->assertSame(['id', 'nickname', 'username', 'avatar'], array_keys(CallRepository::findBriefUser(1)));
        $this->assertSame(['id', 'status', 'deleted_at'], array_keys(CallRepository::findCallee(1)));
        $this->assertNotSame(
            array_keys(CallRepository::findBriefUser(1)),
            array_keys(CallRepository::findCallee(1)),
            '相似用户查询字段集不同，交叉断言'
        );
        $this->assertNotSame(
            array_keys(CallRepository::findPendingCall(1, 90)),
            array_keys(CallRepository::findCallee(1)),
            'SELECT * 与 3 列探测不可合并'
        );
    }

    public function testCallWritesAndStaleExpiry(): void
    {
        $this->seedCallFixture();
        $now = date('Y-m-d H:i:s');

        $callId = CallRepository::insertCall('20000001', 2, 1, 'video');
        $this->assertGreaterThan(0, $callId);
        $this->assertSame('20000001', CallRepository::findCallByRoom('20000001')['room_id']);
        $this->assertSame('calling', CallRepository::findCallByRoom('20000001')['status']);

        $this->assertSame(1, CallRepository::markAccepted($callId, $now));
        $this->assertSame('accepted', CallRepository::findCallByRoom('20000001')['status']);
        $this->assertSame($now, CallRepository::findCallByRoom('20000001')['started_at']);
        $this->assertSame(1, CallRepository::finishCall($callId, 'ended', 42, $now));
        $ended = CallRepository::findCallByRoom('20000001');
        $this->assertSame('ended', $ended['status']);
        $this->assertSame(42, (int)$ended['duration']);
        $this->assertSame($now, $ended['ended_at']);

        // 超时回收：仅 status=calling 且 created_at 超过 90 秒的记录变 missed
        $this->assertSame(1, CallRepository::expireStaleCalls(90, $now));
        $this->assertSame('missed', CallRepository::findCallByRoom('10000003')['status']);
        $this->assertSame('calling', CallRepository::findCallByRoom('10000001')['status'], '10 秒前的响铃不回收');
        $this->assertSame($now, CallRepository::findCallByRoom('10000003')['ended_at']);
        $this->assertFalse($this->db()->inTransaction());
    }

    // =====================================================================
    // Report 域
    // =====================================================================

    private function seedReportFixture(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'reporter', 'nickname' => '举报人', 'avatar' => 'r.png', 'status' => 1],
            ['id' => 2, 'username' => 'target', 'nickname' => '被举报', 'avatar' => 't.png', 'status' => 1],
        ] as $user) {
            $this->seedRow('users', $user);
        }
        $this->seedRow('posts', ['id' => 1, 'user_id' => 2, 'content' => '违规帖', 'status' => 1, 'post_type' => 'normal']);
        $this->seedRow('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 2, 'content' => '违规评论', 'created_at' => '2024-01-01 00:00:00']);
        $this->seedRow('circles', ['id' => 1, 'name' => '违规圈子']);
        $this->seedRow('messages', ['id' => 1, 'content' => '骚扰', 'deleted_at' => null]);
        $this->seedRow('messages', ['id' => 2, 'content' => '已撤回', 'deleted_at' => '2024-01-02 00:00:00']);
        foreach ([
            ['id' => 1, 'reporter_id' => 1, 'target_type' => 'post', 'target_id' => 1, 'reason' => 'spam', 'status' => 0, 'created_at' => '2024-01-01 10:00:00'],
            ['id' => 2, 'reporter_id' => 1, 'target_type' => 'comment', 'target_id' => 1, 'reason' => 'abuse', 'status' => 1, 'created_at' => '2024-01-02 10:00:00'],
            ['id' => 3, 'reporter_id' => 2, 'target_type' => 'user', 'target_id' => 1, 'reason' => 'other', 'status' => 0, 'created_at' => '2024-01-03 10:00:00'],
            ['id' => 4, 'reporter_id' => 1, 'target_type' => 'circle', 'target_id' => 1, 'reason' => 'porn', 'status' => 2, 'created_at' => '2024-01-04 10:00:00'],
        ] as $report) {
            $this->seedRow('reports', $report);
        }
    }

    public function testReportQueriesMatchHeadSnapshots(): void
    {
        $this->seedReportFixture();

        $this->assertSame($this->headPostTarget(1), ReportRepository::findPostTarget(1));
        $this->assertSame($this->headTargetInTable('comments', 1), ReportRepository::findTargetInTable('comments', 1));
        $this->assertSame($this->headTargetInTable('users', 1), ReportRepository::findTargetInTable('users', 1));
        $this->assertSame($this->headTargetInTable('circles', 1), ReportRepository::findTargetInTable('circles', 1));
        $this->assertSame($this->headUndeletedMessage(1), ReportRepository::findUndeletedMessage(1));
        $this->assertFalse((bool)ReportRepository::findUndeletedMessage(2), '已撤回消息不可举报');
        $this->assertFalse((bool)ReportRepository::findPostTarget(999));
        $threw = false;
        try {
            ReportRepository::findTargetInTable('reports; DROP TABLE reports', 1);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertSame('非法举报对象表', $e->getMessage());
        }
        $this->assertTrue($threw, '动态表名白名单');

        $this->assertSame($this->headPendingReport(1, 'post', 1), ReportRepository::findPendingReport(1, 'post', 1));
        $this->assertFalse((bool)ReportRepository::findPendingReport(1, 'comment', 1), '已处理的不算重复');
        $this->assertFalse((bool)ReportRepository::findPendingReport(2, 'post', 1));

        foreach ([[-1, 0, 2], [0, 0, 2], [0, 2, 2], [1, 0, 20], [2, 0, 20]] as [$status, $offset, $limit]) {
            $this->assertSame(
                $this->headReportRows($status, $offset, $limit),
                ReportRepository::fetchAdminReportPage($status, $offset, $limit),
                "举报列表 {$status}/{$offset}+{$limit}"
            );
            $this->assertSame($this->headReportCount($status), ReportRepository::countAdminReports($status));
        }
        $this->assertSame([], ReportRepository::fetchAdminReportPage(99, 0, 20));
        $this->assertSame(11, count(ReportRepository::fetchAdminReportPage(-1, 0, 1)[0]), '列表 11 列不可增减');
    }

    public function testReportWritesKeepFieldOrder(): void
    {
        $this->seedReportFixture();
        $now = '2024-06-01 00:00:00';

        $id = ReportRepository::insertReport(1, 'message', 1, 'spam', '骚扰内容', $now);
        $this->assertGreaterThan(0, $id);
        $rows = $this->allRows('reports');
        $inserted = end($rows);
        $this->assertSame('message', $inserted['target_type']);
        $this->assertSame('骚扰内容', $inserted['description']);
        $this->assertSame(0, (int)$inserted['status']);
        $this->assertSame($now, $inserted['created_at']);
        $this->assertSame(1, ReportRepository::updateReportStatus($id, 2));
        $rows = $this->allRows('reports');
        $this->assertSame(2, (int)end($rows)['status']);
        $this->assertSame(0, ReportRepository::updateReportStatus(999, 1), '不存在的 id 更新 0 行');
        $this->assertFalse($this->db()->inTransaction());
    }

}

/**
 * 测试专用 PDO：把 MySQL 的 DATE_SUB(NOW(), INTERVAL n SECOND) 等价改写为
 * SQLite 可解析的 DATE_SUB(NOW(), n)（占位符数量不变，注册函数接收秒数）。
 * 产品仓储 SQL 与 HEAD 快照 SQL 均保持原文，不做任何改写。
 */
final class CompatPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = (string)preg_replace(
            '/DATE_SUB\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+|\?)\s+SECOND\s*\)/i',
            'DATE_SUB(NOW(), $1)',
            $query
        );
        return parent::prepare($query, $options);
    }
}
