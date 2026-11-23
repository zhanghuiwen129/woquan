<?php
/**
 * paginate() SQL 字面量收敛对照测试
 *
 * 这些 SQL 原先以第 2 / 第 4 个实参直接写在控制器的 $this->paginate(...) 里，
 * 门禁旧口径看不到 Database:: 调用，因此漏统计。现在下沉到
 * UploadRepository / UserRepository，本测试把 HEAD 原文保留在 original*() 中，
 * 用真实 SQLite 逐字段比对列表、COUNT、筛选条件与分页顺序。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\UploadRepository;
use Hublog\Core\UserRepository;
use PDO;

final class PaginateSqlRepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();
        $pdo->exec(<<<'SQL'
DROP TABLE IF EXISTS `uploads`;
CREATE TABLE `uploads` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `type`       TEXT    DEFAULT NULL,
    `filename`   TEXT    DEFAULT NULL,
    `url`        TEXT    DEFAULT NULL,
    `size`       INTEGER DEFAULT NULL,
    `status`     INTEGER DEFAULT 1,
    `created_at` TEXT    DEFAULT NULL
);
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `username`    TEXT    DEFAULT NULL,
    `nickname`    TEXT    DEFAULT NULL,
    `avatar`      TEXT    DEFAULT NULL,
    `email`       TEXT    DEFAULT NULL,
    `role`        TEXT    DEFAULT NULL,
    `status`      INTEGER DEFAULT 1,
    `created_at`  TEXT    DEFAULT NULL,
    `last_login`  TEXT    DEFAULT NULL,
    `muted_until` TEXT    DEFAULT NULL
);
DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `content`    TEXT    DEFAULT NULL,
    `status`     INTEGER DEFAULT 1,
    `created_at` TEXT    DEFAULT NULL
);
DROP TABLE IF EXISTS `drafts`;
CREATE TABLE `drafts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `type`       TEXT    DEFAULT 'post',
    `circle_id`  INTEGER DEFAULT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `title`      TEXT    DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `images`     TEXT    DEFAULT NULL,
    `visibility` TEXT    DEFAULT 'public',
    `scheduled_at` TEXT   DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL,
    `updated_at` TEXT    DEFAULT NULL
);
DROP TABLE IF EXISTS `appeals`;
CREATE TABLE `appeals` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `target_type` TEXT   DEFAULT NULL,
    `target_id`  INTEGER DEFAULT NULL,
    `category`   TEXT    DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `images`     TEXT    DEFAULT NULL,
    `status`     TEXT    DEFAULT 'pending',
    `admin_id`   INTEGER DEFAULT NULL,
    `admin_note` TEXT    DEFAULT NULL,
    `handled_at` TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
SQL);

        $pdo->exec(<<<'SQL'
INSERT INTO `users` (`id`, `username`, `nickname`, `avatar`, `email`, `role`, `status`, `created_at`, `last_login`, `muted_until`) VALUES
(1, 'alice', '艾丽', 'a.png', 'alice@example.com', 'user', 1, '2026-01-01 00:00:00', '2026-01-02 00:00:00', NULL),
(2, 'bob', '鲍勃', 'b.png', 'bob@example.com', 'admin', 1, '2026-01-02 00:00:00', '2026-01-03 00:00:00', NULL),
(3, 'carol', ' Carol', 'c.png', 'carol@example.com', 'user', 1, '2026-01-03 00:00:00', NULL, NULL);
INSERT INTO `posts` (`id`, `user_id`, `content`, `status`, `created_at`) VALUES
(10, 1, 'hello post', 1, '2026-01-03 00:00:00'),
(11, 2, 'second post', 1, '2026-01-04 00:00:00');
INSERT INTO `uploads` (`id`, `user_id`, `post_id`, `type`, `filename`, `url`, `size`, `status`, `created_at`) VALUES
(100, 1, 10, 'image', 'a.jpg', '/uploads/a.jpg', 1024, 1, '2026-01-04 00:00:00'),
(101, 2, NULL, 'attachment', 'b.pdf', '/uploads/b.pdf', 2048, 1, '2026-01-05 00:00:00'),
(102, 1, 11, 'image', 'c.png', '/uploads/c.png', 512, 0, '2026-01-06 00:00:00');
INSERT INTO `drafts` (`id`, `user_id`, `type`, `title`, `content`, `images`, `visibility`, `created_at`, `updated_at`) VALUES
(200, 1, 'post', '草稿一', '内容一', 'img1,img2,', 'public', '2026-01-07 00:00:00', '2026-01-08 00:00:00'),
(201, 1, 'post', '草稿二', '内容二', NULL, 'private', '2026-01-08 00:00:00', '2026-01-09 00:00:00'),
(202, 1, 'comment', '评论草稿', '内容三', NULL, 'public', '2026-01-09 00:00:00', '2026-01-10 00:00:00');
INSERT INTO `appeals` (`id`, `user_id`, `target_type`, `target_id`, `category`, `content`, `status`, `created_at`) VALUES
(300, 1, 'post', 10, 'falseblock', '内容申诉', 'pending', '2026-01-09 00:00:00'),
(301, 2, 'comment', 11, 'other', '另一条申诉', 'reviewing', '2026-01-10 00:00:00'),
(302, 1, 'circle', 1, 'forbidden', '第三条申诉', 'rejected', '2026-01-11 00:00:00');
SQL);
    }

    // ── HEAD 原文快照 ───────────────────────────────────────────────────

    /** AdminAttachmentController::attachments() */
    private function originalAttachmentPage(string $type, string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($type, ['image', 'attachment'], true)) {
            $where .= ' AND u.type = ?';
            $params[] = $type;
        }
        if ($keyword) {
            $where .= ' AND (u.filename LIKE ? OR us.username LIKE ?)';
            $params = array_merge($params, ["%{$keyword}%", "%{$keyword}%"]);
        }
        $sql = "SELECT u.id, u.user_id, u.post_id, u.type, u.filename, u.url, u.size, u.status, u.created_at, us.username, us.nickname, p.content AS post_content, p.status AS post_status FROM uploads u INNER JOIN users us ON u.user_id = us.id LEFT JOIN posts p ON u.post_id = p.id {$where} ORDER BY COALESCE(p.created_at, u.created_at) DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAttachmentCount(string $type, string $keyword): int
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($type, ['image', 'attachment'], true)) {
            $where .= ' AND u.type = ?';
            $params[] = $type;
        }
        if ($keyword) {
            $where .= ' AND (u.filename LIKE ? OR us.username LIKE ?)';
            $params = array_merge($params, ["%{$keyword}%", "%{$keyword}%"]);
        }
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM uploads u INNER JOIN users us ON u.user_id = us.id {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** UserController::list() */
    private function originalAdminUserPage(string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword) {
            $where .= ' AND (username LIKE ? OR nickname LIKE ? OR email LIKE ?)';
            $params = array_fill(0, 3, "%{$keyword}%");
        }
        $sql = "SELECT id, username, nickname, avatar, email, role, status, created_at, last_login, muted_until
             FROM users {$where} ORDER BY id DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminUserCount(string $keyword): int
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword) {
            $where .= ' AND (username LIKE ? OR nickname LIKE ? OR email LIKE ?)';
            $params = array_fill(0, 3, "%{$keyword}%");
        }
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM users {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** UserController::drafts() —— SQLite 无 information_schema，extraCol 为空 */
    private function originalDraftPage(int $userId, string $type, int $offset, int $limit): array
    {
        $sql = "SELECT id, type, circle_id, post_id, title, content, images, visibility, scheduled_at, created_at, updated_at
             FROM drafts WHERE user_id = ? AND type = ? ORDER BY updated_at DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute([$userId, $type, $offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** UserController::myAppeals() */
    private function originalMyAppeals(int $userId, int $offset, int $limit): array
    {
        $sql = "SELECT id, target_type, target_id, category, content, status, admin_note, created_at, handled_at
             FROM appeals WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute([$userId, $offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** UserController::adminAppeals() */
    private function originalAdminAppeals(string $status, string $category, string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = $this->originalAdminAppealWhere($status, $category, $keyword);
        $sql = "SELECT a.id, a.user_id, a.target_type, a.target_id, a.category, a.content, a.images,
                    a.status, a.admin_id, a.admin_note, a.handled_at, a.created_at,
                    u.username, u.nickname, u.avatar
             FROM appeals a LEFT JOIN users u ON u.id = a.user_id
             {$where} ORDER BY a.created_at DESC";
        $stmt = $this->pdo()->prepare($sql . ' LIMIT ?, ?');
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminAppealsCount(string $status, string $category, string $keyword): int
    {
        [$where, $params] = $this->originalAdminAppealWhere($status, $category, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM appeals a LEFT JOIN users u ON u.id = a.user_id {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function originalAdminAppealWhere(string $status, string $category, string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($category !== '') {
            $where .= ' AND a.category = ?';
            $params[] = $category;
        }
        if ($keyword !== '') {
            $where .= ' AND (a.content LIKE ? OR u.username LIKE ? OR u.nickname LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($params, $kw, $kw, $kw);
        }
        return [$where, $params];
    }

    // ── 行为断言 ────────────────────────────────────────────────────────

    public function testAttachmentPageMatchesOriginalSql(): void
    {
        foreach ([['all', '', 0, 2], ['image', '', 0, 2], ['attachment', '', 0, 2], ['all', 'bob', 0, 2]] as $case) {
            [$type, $keyword, $offset, $limit] = $case;
            $this->assertSame(
                $this->originalAttachmentPage($type, $keyword, $offset, $limit),
                UploadRepository::fetchAttachmentPage($type, $keyword, $offset, $limit)
            );
            $this->assertSame(
                $this->originalAttachmentCount($type, $keyword),
                UploadRepository::countAttachments($type, $keyword)
            );
        }
    }

    public function testAdminUserPageMatchesOriginalSql(): void
    {
        foreach (['', 'ali', 'bob@example.com'] as $keyword) {
            $this->assertSame(
                $this->originalAdminUserPage($keyword, 0, 2),
                UserRepository::fetchAdminUserPage($keyword, 0, 2)
            );
            $this->assertSame(
                $this->originalAdminUserCount($keyword),
                UserRepository::countAdminUsers($keyword)
            );
        }
    }

    public function testDraftPageMatchesOriginalSql(): void
    {
        $this->assertSame(
            $this->originalDraftPage(1, 'post', 0, 10),
            UserRepository::fetchDraftPage(1, 'post', 0, 10)
        );
        $this->assertSame(2, UserRepository::countDrafts(1, 'post'));
        $this->assertSame([], UserRepository::fetchDraftPage(999, 'post', 0, 10));
    }

    public function testMyAppealsMatchOriginalSql(): void
    {
        $this->assertSame(
            $this->originalMyAppeals(1, 0, 10),
            UserRepository::fetchMyAppeals(1, 0, 10)
        );
        $this->assertSame(2, UserRepository::countMyAppeals(1));
    }

    public function testAdminAppealsMatchOriginalSql(): void
    {
        $cases = [
            ['', '', '', 0, 10],
            ['pending', '', '', 0, 10],
            ['', 'other', '', 0, 10],
            ['', '', '申诉', 0, 10],
            ['reviewing', 'other', 'bob', 0, 10],
        ];
        foreach ($cases as $case) {
            [$status, $category, $keyword, $offset, $limit] = $case;
            $this->assertSame(
                $this->originalAdminAppeals($status, $category, $keyword, $offset, $limit),
                UserRepository::fetchAdminAppeals($status, $category, $keyword, $offset, $limit)
            );
            $this->assertSame(
                $this->originalAdminAppealsCount($status, $category, $keyword),
                UserRepository::countAdminAppeals($status, $category, $keyword)
            );
        }
    }
}
