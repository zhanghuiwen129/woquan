<?php
/**
 * 批次四 · 社交域（Follow / Chat / RealName）迁移回归测试
 *
 * 对照法：每条被测查询都在本文件保存一份「迁移前 HEAD 原文 SQL」私有方法，
 * 在 SQLite 内存库中同时执行原始 SQL 与新 Repository 方法，assertSame 逐字段比对。
 * 覆盖空结果 / 单行 / 多页 / 软删负例 / 事务成功与异常回滚（trigger RAISE(ABORT)）；
 * 并对「相似但字段清单不同」的查询做交叉断言。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\ChatRepository;
use Hublog\Core\ChatService;
use Hublog\Core\Database;
use Hublog\Core\FollowRepository;
use Hublog\Core\RealNameRepository;
use PDO;

final class Batch4SocialBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();

        // MySQL 专有函数/结构的 SQLite 等价实现，使 HEAD 原始 SQL 快照可真实执行
        $pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
            $needle = array_shift($args);
            $pos = array_search($needle, $args, true);
            return $pos === false ? 0 : $pos + 1;
        });
        $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT)');
        $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');

        foreach ([
            'realname_verified INTEGER NOT NULL DEFAULT 0',
            'realname_verified_at TEXT DEFAULT NULL',
            'allow_stranger_msg INTEGER NOT NULL DEFAULT 1',
            // R4：suggestUsers 新增 u.allow_search = 1 条件，测试库需具备该列（默认 1 保持既有断言）
            'allow_search INTEGER NOT NULL DEFAULT 1',
        ] as $column) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column);
        }

        $pdo->exec(<<<'SQL'
CREATE TABLE `follows` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id`  INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL,
    `created_at`   TEXT DEFAULT NULL
);
CREATE TABLE `blocked_users` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`         INTEGER NOT NULL,
    `blocked_user_id` INTEGER NOT NULL,
    `created_at`      TEXT DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `sender_id`  INTEGER NOT NULL DEFAULT 0,
    `type`       TEXT DEFAULT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `content`    TEXT DEFAULT NULL,
    `is_read`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `conversations` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `type`          TEXT DEFAULT 'private',
    `title`         TEXT DEFAULT NULL,
    `creator_id`    INTEGER NOT NULL DEFAULT 0,
    `circle_id`     INTEGER DEFAULT NULL,
    `announcement`  TEXT DEFAULT NULL,
    `announcement_at` TEXT DEFAULT NULL,
    `member_count`  INTEGER NOT NULL DEFAULT 0,
    `avatar`        TEXT DEFAULT NULL,
    `deleted_at`    TEXT DEFAULT NULL,
    `last_message_id` INTEGER DEFAULT NULL,
    `updated_at`    TEXT DEFAULT NULL
);
CREATE TABLE `conversation_members` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `user_id`      INTEGER NOT NULL,
    `role`         TEXT DEFAULT 'member',
    `muted`        INTEGER NOT NULL DEFAULT 0,
    `pinned`       INTEGER NOT NULL DEFAULT 0,
    `muted_until`  TEXT DEFAULT NULL,
    `deleted_at`   TEXT DEFAULT NULL,
    `joined_at`    TEXT DEFAULT NULL
);
CREATE TABLE `messages` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `sender_id`       INTEGER NOT NULL,
    `content`         TEXT DEFAULT NULL,
    `is_read`         INTEGER NOT NULL DEFAULT 0,
    `recalled_at`     TEXT DEFAULT NULL,
    `created_at`      TEXT DEFAULT NULL,
    `deleted_at`      TEXT DEFAULT NULL,
    `deleted_by`      INTEGER DEFAULT NULL,
    `delete_scope`    TEXT DEFAULT NULL,
    `type`            TEXT DEFAULT 'text',
    `extra`           TEXT DEFAULT NULL
);
CREATE TABLE `message_deletions` (
    `id`                 INTEGER PRIMARY KEY AUTOINCREMENT,
    `message_id`         INTEGER NOT NULL,
    `conversation_id`    INTEGER NOT NULL,
    `sender_id`          INTEGER NOT NULL DEFAULT 0,
    `operator_id`        INTEGER NOT NULL DEFAULT 0,
    `operator_role`      TEXT DEFAULT NULL,
    `scope`              TEXT DEFAULT NULL,
    `content_snapshot`   TEXT DEFAULT NULL,
    `message_created_at` TEXT DEFAULT NULL,
    `ip`                 TEXT DEFAULT NULL,
    `user_agent`         TEXT DEFAULT NULL,
    `created_at`         TEXT DEFAULT NULL
);
CREATE TABLE `circles` (
    `id`    INTEGER PRIMARY KEY AUTOINCREMENT,
    `cover` TEXT DEFAULT NULL
);
CREATE TABLE `realname_records` (
    `id`               INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`          INTEGER NOT NULL,
    `real_name_masked` TEXT DEFAULT NULL,
    `id_card_masked`   TEXT DEFAULT NULL,
    `id_card_hash`     TEXT DEFAULT NULL,
    `provider`         TEXT DEFAULT NULL,
    `status`           TEXT DEFAULT 'pending',
    `fail_reason`      TEXT DEFAULT NULL,
    `provider_code`    TEXT DEFAULT NULL,
    `reviewer_id`      INTEGER DEFAULT NULL,
    `reviewed_at`      TEXT DEFAULT NULL,
    `verified_at`      TEXT DEFAULT NULL,
    `created_at`       TEXT DEFAULT NULL,
    `updated_at`       TEXT DEFAULT NULL
);
SQL);
    }

    // =====================================================================
    // HEAD 原始 SQL 快照（逐字搬迁，禁止在快照上"顺手优化"）
    // =====================================================================

    private function headActiveUserById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM users WHERE id = ? AND status = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headActiveUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1"
        );
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headFollowEdge(int $followerId, int $followingId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1"
        );
        $stmt->execute([$followerId, $followingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headCountFollowers(int $targetId): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM follows WHERE following_id = ?");
        $stmt->execute([$targetId]);
        return (int)$stmt->fetchColumn();
    }

    private function headCountFollowing(int $userId): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** follow()/followers() 列表：BaseController::paginate 原样拼接 LIMIT ?, ? */
    private function headFollowList(string $joinColumn, string $whereColumn, int $id, int $offset, int $limit): array
    {
        $sql = "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.created_at, f.created_at AS followed_at, u.vip_level, u.avatar_frame
             FROM follows f
             INNER JOIN users u ON f.{$joinColumn} = u.id
             WHERE f.{$whereColumn} = ? AND u.status = 1
             ORDER BY f.created_at DESC" . ' LIMIT ?, ?';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$id, $offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headFollowListCount(string $joinColumn, string $whereColumn, int $id): int
    {
        $sql = "SELECT COUNT(*) FROM follows f INNER JOIN users u ON f.{$joinColumn} = u.id WHERE f.{$whereColumn} = ? AND u.status = 1";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    private function headFollowedIds(int $userId): array
    {
        $stmt = $this->pdo()->prepare("SELECT following_id FROM follows WHERE follower_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headFollowedIdsIn(int $userId, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT following_id FROM follows WHERE follower_id = ? AND following_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$userId], $ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headSuggestUsers(array $excludeIds, int $limit): array
    {
        // R4 有意行为变更：allow_search=0 的用户不再进入推荐列表
        $where = 'u.status = 1 AND u.allow_search = 1';
        $params = [];
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $where .= " AND u.id NOT IN ($placeholders)";
            $params = $excludeIds;
        }
        $sql = "SELECT u.id, u.username, u.nickname, u.avatar, u.bio,
                    (SELECT COUNT(*) FROM follows f WHERE f.following_id = u.id) AS follower_count
             FROM users u
             WHERE {$where}
             ORDER BY follower_count DESC, u.id DESC
             LIMIT {$limit}";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBlockedList(int $userId, int $offset, int $limit): array
    {
        $sql = "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, b.created_at AS blocked_at
             FROM blocked_users b
             INNER JOIN users u ON b.blocked_user_id = u.id
             WHERE b.user_id = ?
             ORDER BY b.created_at DESC" . ' LIMIT ?, ?';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$userId, $offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headBlockedCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM blocked_users b INNER JOIN users u ON b.blocked_user_id = u.id WHERE b.user_id = ?"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function headExistsBlockRow(int $userId, int $targetId): mixed
    {
        $stmt = $this->pdo()->prepare(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $targetId]);
        return $stmt->fetchColumn();
    }

    private function headMemberId(int $conversationId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headMemberAllByConversation(int $conversationId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headMemberAllById(int $memberId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM conversation_members WHERE id = ? LIMIT 1");
        $stmt->execute([$memberId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headGroupRole(int $conversationId, int $userId): ?string
    {
        $stmt = $this->pdo()->prepare(
            "SELECT cm.role FROM conversation_members cm
             INNER JOIN conversations c ON c.id = cm.conversation_id
             WHERE cm.conversation_id = ? AND cm.user_id = ? AND c.type = 'group' LIMIT 1"
        );
        $stmt->execute([$conversationId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['role'] : null;
    }

    private function headGroupMembers(int $conversationId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT cm.user_id, cm.role, cm.muted_until, cm.joined_at,
                    u.username, u.nickname, u.avatar
             FROM conversation_members cm
             INNER JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = ?
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.id ASC"
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headConversationForSend(int $userId, int $conversationId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT c.id, c.type, c.creator_id,
                    (SELECT cm.muted_until FROM conversation_members cm
                      WHERE cm.conversation_id = c.id AND cm.user_id = ? LIMIT 1) AS my_muted_until,
                    (SELECT cm.user_id FROM conversation_members cm
                      WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1) AS peer_id,
                    (SELECT u.allow_stranger_msg FROM users u WHERE u.id = (
                        SELECT cm.user_id FROM conversation_members cm
                         WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1)) AS peer_allow_stranger,
                    (SELECT COUNT(*) FROM follows f WHERE f.follower_id = ? AND f.following_id = (
                        SELECT cm.user_id FROM conversation_members cm
                         WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1)) AS is_fans
             FROM conversations c WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $conversationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headGroupInfo(int $conversationId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT c.id, c.type, c.title, c.circle_id, c.announcement, c.announcement_at, c.member_count, c.avatar
             FROM conversations c WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headCircleCover(int $circleId): mixed
    {
        $stmt = $this->pdo()->prepare("SELECT cover FROM circles WHERE id = ? LIMIT 1");
        $stmt->execute([$circleId]);
        return $stmt->fetchColumn();
    }

    private function headMutedUntil(int $conversationId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT muted_until FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headOtherMembers(int $conversationId, int $userId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?"
        );
        $stmt->execute([$conversationId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headMessageForRecall(int $messageId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, conversation_id, sender_id, created_at, recalled_at FROM messages WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$messageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headUserVerification(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT realname_verified, realname_verified_at FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headVerifiedFlag(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT realname_verified FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headLatestRecord(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, status, real_name_masked, id_card_masked, provider, fail_reason, verified_at, created_at
             FROM realname_records WHERE user_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headTodayCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM realname_records
             WHERE user_id = ? AND created_at >= CURDATE()"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private function headRecordByHash(string $hash): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, user_id, status FROM realname_records WHERE id_card_hash = ? LIMIT 1"
        );
        $stmt->execute([$hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headAdminWhere(string $status, string $keyword): array
    {
        $where = '1=1';
        $params = [];
        if ($status !== '' && in_array($status, ['pending', 'verified', 'rejected', 'revoked'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }

    private function headAdminCount(string $status, string $keyword): int
    {
        [$where, $params] = $this->headAdminWhere($status, $keyword);
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM realname_records r INNER JOIN users u ON u.id = r.user_id WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function headAdminList(string $status, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = $this->headAdminWhere($status, $keyword);
        $sql = "SELECT r.id, r.user_id, r.real_name_masked, r.id_card_masked, r.provider, r.status,
                       r.fail_reason, r.provider_code, r.reviewer_id, r.reviewed_at, r.verified_at, r.created_at,
                       u.username, u.nickname, u.avatar, u.realname_verified
                FROM realname_records r
                INNER JOIN users u ON u.id = r.user_id
                WHERE {$where}
                ORDER BY r.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function headRecordById(int $id): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM realname_records WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headVerifiedConflict(string $hash, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, user_id FROM realname_records
                 WHERE id_card_hash = ? AND status = 'verified' AND user_id <> ? LIMIT 1"
        );
        $stmt->execute([$hash, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function headRecordStatus(int $id): mixed
    {
        $stmt = $this->pdo()->prepare("SELECT status FROM realname_records WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }

    // =====================================================================
    // Follow 域
    // =====================================================================

    private function seedFollowUsers(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'alice', 'status' => 1],
            ['id' => 2, 'username' => 'bob', 'status' => 1],
            ['id' => 3, 'username' => 'carol', 'status' => 1],
            ['id' => 4, 'username' => 'dave', 'status' => 0],
            ['id' => 5, 'username' => 'eve', 'status' => 1],
        ] as $user) {
            $this->seed('users', $user);
        }
    }

    public function testFollowCoreQueriesMatchHeadSnapshots(): void
    {
        $this->seedFollowUsers();
        foreach ([
            ['follower_id' => 1, 'following_id' => 2, 'created_at' => '2024-01-05 00:00:00'],
            ['follower_id' => 1, 'following_id' => 3, 'created_at' => '2024-01-04 00:00:00'],
            ['follower_id' => 2, 'following_id' => 1, 'created_at' => '2024-01-03 00:00:00'],
            ['follower_id' => 4, 'following_id' => 1, 'created_at' => '2024-01-02 00:00:00'],
        ] as $edge) {
            $this->seed('follows', $edge);
        }

        $this->assertSame($this->headActiveUserById(2), FollowRepository::findActiveUserById(2));
        $this->assertNull(FollowRepository::findActiveUserById(4), 'status=0 用户不可关注');
        $this->assertSame($this->headActiveUserByUsername('carol'), FollowRepository::findActiveUserByUsername('carol'));
        $this->assertNull(FollowRepository::findActiveUserByUsername('dave'), '用户名命中但 status=0 必须被过滤');
        $this->assertNull(FollowRepository::findActiveUserByUsername('nobody'), '空结果负例');

        $this->assertSame($this->headFollowEdge(1, 2), FollowRepository::findFollowEdge(1, 2));
        $this->assertNull(FollowRepository::findFollowEdge(2, 3), '不存在的边');
        $this->assertSame($this->headCountFollowers(1), FollowRepository::countFollowersOfTarget(1));
        $this->assertSame($this->headCountFollowing(1), FollowRepository::countFollowingOfUser(1));
        $this->assertSame($this->headFollowedIds(1), FollowRepository::fetchFollowedIds(1));
        $this->assertSame($this->headFollowedIdsIn(1, [2, 3, 5]), FollowRepository::fetchFollowedIdsIn(1, [2, 3, 5]));
        $this->assertSame([], FollowRepository::fetchFollowedIdsIn(1, []), '空 IN 白名单');

        // follows 无 user_id 列：推荐查询必须使用别名 f 的 following_id
        $this->assertSame($this->headSuggestUsers([2, 1], 6), FollowRepository::suggestUsers([2, 1], 6));
        $this->assertSame([3, 5], array_column(FollowRepository::suggestUsers([2, 1], 6), 'id'));
        $this->assertArrayHasKey('follower_count', FollowRepository::suggestUsers([], 6)[0]);

        // R4：allow_search=0 的用户必须从推荐列表消失（快照与仓储同口径，非恒真）
        $this->pdo()->exec('UPDATE users SET allow_search = 0 WHERE id = 5');
        $this->assertSame($this->headSuggestUsers([2, 1], 6), FollowRepository::suggestUsers([2, 1], 6));
        $this->assertSame([3], array_column(FollowRepository::suggestUsers([2, 1], 6), 'id'), 'allow_search=0 负例');
        $threw = false;
        try {
            $this->pdo()->query('SELECT user_id FROM follows f LIMIT 1');
        } catch (\PDOException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'follows 表确实没有 user_id 列 —— 别名歧义负例');
    }

    public function testFollowListPaginationAndBlockedListMatchHead(): void
    {
        $this->seedFollowUsers();
        for ($i = 1; $i <= 4; $i++) {
            $this->seed('follows', [
                'follower_id' => 5,
                'following_id' => $i,
                'created_at' => sprintf('2024-02-0%d 00:00:00', 5 - $i),
            ]);
        }
        $this->seed('blocked_users', ['user_id' => 5, 'blocked_user_id' => 1, 'created_at' => '2024-03-02 00:00:00']);
        $this->seed('blocked_users', ['user_id' => 5, 'blocked_user_id' => 4, 'created_at' => '2024-03-01 00:00:00']);

        // 多页：offset/limit 顺序与原文 LIMIT ?, ? 一致
        foreach ([[0, 2], [2, 2], [0, 20]] as [$offset, $limit]) {
            $this->assertSame(
                $this->headFollowList('following_id', 'follower_id', 5, $offset, $limit),
                FollowRepository::fetchFollowingPage(5, $offset, $limit),
                "关注列表分页 {$offset}+{$limit}"
            );
            $this->assertSame(
                $this->headFollowList('follower_id', 'following_id', 1, $offset, $limit),
                FollowRepository::fetchFollowersPage(1, $offset, $limit),
                "粉丝列表分页 {$offset}+{$limit}"
            );
        }
        // 软删/状态负例：users.status=0 的 dave(id=4) 不进列表，但仍在裸 COUNT 里
        $this->assertSame(3, FollowRepository::countFollowingList(5));
        $this->assertSame(4, FollowRepository::countFollowingOfUser(5), '裸 COUNT 无 users.status 条件');
        $this->assertSame($this->headFollowListCount('following_id', 'follower_id', 5), FollowRepository::countFollowingList(5));
        $this->assertSame($this->headFollowListCount('follower_id', 'following_id', 1), FollowRepository::countFollowerList(1));
        $this->assertSame([], FollowRepository::fetchFollowingPage(999, 0, 20), '空结果负例');

        $this->assertSame($this->headBlockedList(5, 0, 20), FollowRepository::fetchBlockedPage(5, 0, 20));
        $this->assertSame($this->headBlockedCount(5), FollowRepository::countBlocked(5));
        $this->assertSame(2, count(FollowRepository::fetchBlockedPage(5, 0, 20)), '黑名单不像关注列表那样过滤 users.status');
        $this->assertSame([], FollowRepository::fetchBlockedPage(999, 0, 20));

        $this->assertSame($this->headExistsBlockRow(5, 1), FollowRepository::existsBlockRow(5, 1));
        $this->assertSame($this->headExistsBlockRow(1, 5), FollowRepository::existsBlockRow(1, 5));
        $this->assertSame(
            [$this->headExistsBlockRow(5, 1), $this->headExistsBlockRow(1, 5)],
            FollowRepository::checkBlockedState(5, 1)
        );
        $this->assertSame([false, false], array_map('boolval', FollowRepository::checkBlockedState(5, 3)));
    }

    public function testFollowWritesAndTransactionRollback(): void
    {
        $this->seedFollowUsers();
        $this->assertTrue(FollowRepository::toggleFollow(1, 2, '2024-01-01 00:00:00', null));
        $this->assertFalse(FollowRepository::toggleFollow(1, 1, '2024-01-01 00:00:00', null), '自关注守卫下沉');
        $this->assertCount(1, $this->rows('follows'));

        $this->assertTrue(FollowRepository::toggleFollow(1, 3, '2024-01-01 00:00:00', null));
        $this->assertSame([2, 3], array_column($this->rows('follows'), 'following_id'));
        $edge = FollowRepository::findFollowEdge(1, 3);
        $this->assertFalse(FollowRepository::toggleFollow(1, 3, '2024-01-01 00:00:00', $edge), '再次切换为取消关注');
        $this->assertSame([2], array_column($this->rows('follows'), 'following_id'));

        // 异常回滚：触发器让插入失败，事务内不得残留任何写
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_follow BEFORE INSERT ON follows
WHEN NEW.following_id = 666 BEGIN SELECT RAISE(ABORT, 'follow insert failed'); END;
SQL);
        try {
            FollowRepository::toggleFollow(1, 666, '2024-01-01 00:00:00', null);
            $this->fail('应当抛出异常');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('follow insert failed', $e->getMessage());
        }
        $this->assertSame([2], array_column($this->rows('follows'), 'following_id'), '异常后事务已回滚');

        // 通知入口守卫：同人不发通知
        FollowRepository::insertFollowNotification(1, 1, '2024-01-01 00:00:00');
        $this->assertCount(0, $this->rows('notifications'));
        FollowRepository::insertFollowNotification(1, 2, '2024-01-01 00:00:00');
        $this->assertSame('follow', $this->rows('notifications')[0]['type']);

        // 拉黑事务：幂等插入 + 双向解除关注
        $this->seed('follows', ['follower_id' => 2, 'following_id' => 1, 'created_at' => '2024-01-01 00:00:00']);
        // SQLite 下 SHOW TABLES 不可用，仓储降级返回 false（对应 MySQL 未迁移分支）
        $this->assertFalse(FollowRepository::blockedUsersTableExists());
        $this->assertFalse(FollowRepository::blockUser(1, 2, '2024-01-01 00:00:00'), '表不可用时安全短路');
        $this->assertFalse(FollowRepository::blockUser(1, 1, '2024-01-01 00:00:00'), '自拉黑守卫');

        $this->assertCount(2, $this->rows('follows'));
        FollowRepository::unblockUser(1, 2);
        $this->assertCount(0, $this->rows('blocked_users'));
    }

    // =====================================================================
    // Chat 域
    // =====================================================================

    private function seedChatFixture(): void
    {
        foreach ([
            ['id' => 1, 'username' => 'owner', 'nickname' => '群主', 'status' => 1, 'allow_stranger_msg' => 1],
            ['id' => 2, 'username' => 'admin', 'nickname' => '管理', 'status' => 1, 'allow_stranger_msg' => 0],
            ['id' => 3, 'username' => 'member', 'nickname' => '成员', 'status' => 1, 'allow_stranger_msg' => 1],
        ] as $user) {
            $this->seed('users', $user);
        }
        $this->seed('conversations', [
            'id' => 10, 'type' => 'group', 'title' => '圈子群', 'creator_id' => 1,
            'circle_id' => 7, 'member_count' => 3, 'avatar' => '',
            'announcement' => '旧公告', 'announcement_at' => '2024-01-01 00:00:00',
        ]);
        $this->seed('conversations', ['id' => 20, 'type' => 'private', 'creator_id' => 1, 'member_count' => 2]);
        $this->seed('circles', ['id' => 7, 'cover' => 'cover7.png']);
        foreach ([
            ['id' => 1, 'conversation_id' => 10, 'user_id' => 1, 'role' => 'owner', 'joined_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'conversation_id' => 10, 'user_id' => 2, 'role' => 'admin', 'joined_at' => '2024-01-02 00:00:00'],
            ['id' => 3, 'conversation_id' => 10, 'user_id' => 3, 'role' => 'member', 'joined_at' => '2024-01-03 00:00:00'],
            ['id' => 4, 'conversation_id' => 20, 'user_id' => 1, 'role' => 'member'],
            ['id' => 5, 'conversation_id' => 20, 'user_id' => 2, 'role' => 'member'],
        ] as $member) {
            $this->seed('conversation_members', $member);
        }
        $this->seed('messages', [
            'id' => 1, 'conversation_id' => 10, 'sender_id' => 2, 'content' => 'hi',
            'is_read' => 0, 'created_at' => '2024-01-01 00:00:00',
        ]);
    }

    public function testChatReadQueriesMatchHeadSnapshots(): void
    {
        $this->seedChatFixture();
        $this->seed('follows', ['follower_id' => 1, 'following_id' => 2, 'created_at' => '2024-01-01 00:00:00']);
        $this->pdo()->exec("UPDATE conversation_members SET muted_until = '2030-01-01 00:00:00' WHERE id = 1");

        $this->assertSame($this->headMemberId(10, 2), ChatRepository::findMemberId(10, 2));
        $this->assertSame($this->headMemberAllByConversation(10, 2), ChatRepository::findMemberAllByConversationAndUser(10, 2));
        $this->assertSame($this->headMemberAllById(2), ChatRepository::findMemberAllById(2));
        // 相似 SQL 字段集差异交叉断言
        $this->assertSame(['id'], array_keys(ChatRepository::findMemberId(10, 2)));
        $this->assertNotSame(array_keys(ChatRepository::findMemberId(10, 2)), array_keys(ChatRepository::findMemberAllById(2)));
        $this->assertNull(ChatRepository::findMemberId(10, 999), '非成员负例');

        $this->assertSame('owner', ChatRepository::findGroupRole(10, 1));
        $this->assertSame($this->headGroupRole(10, 3), ChatRepository::findGroupRole(10, 3));
        $this->assertNull(ChatRepository::findGroupRole(20, 1), '私聊成员不能被识别为群成员');
        $this->assertSame($this->headGroupMembers(10), ChatRepository::fetchGroupMembers(10));
        $this->assertSame([1, 2, 3], array_column(ChatRepository::fetchGroupMembers(10), 'user_id'), 'FIELD 业务排序');
        $this->assertSame([], ChatRepository::fetchGroupMembers(999), '空结果负例');

        $this->assertSame($this->headConversationForSend(1, 10), ChatRepository::findConversationForSend(1, 10));
        $conv = ChatRepository::findConversationForSend(1, 10);
        $this->assertSame('2030-01-01 00:00:00', $conv['my_muted_until']);
        $this->assertSame(2, (int)$conv['peer_id']);
        $this->assertSame(0, (int)$conv['peer_allow_stranger']);
        $this->assertSame(1, (int)$conv['is_fans'], 'follows 子查询使用别名 f');
        $this->assertSame($this->headConversationForSend(1, 20), ChatRepository::findConversationForSend(1, 20));

        $this->assertSame($this->headGroupInfo(10), ChatRepository::findGroupInfo(10));
        $this->assertSame($this->headCircleCover(7), ChatRepository::findCircleCover(7));
        $this->assertSame(false, ChatRepository::findCircleCover(999));
        $this->assertSame($this->headMutedUntil(10, 1), ChatRepository::findMemberMutedUntil(10, 1));
        $this->assertSame($this->headOtherMembers(10, 1), ChatRepository::fetchOtherMemberUserIds(10, 1));
        $this->assertSame($this->headMessageForRecall(1), ChatRepository::findMessageForRecall(1));
        $this->assertNull(ChatRepository::findMessageForRecall(999));
    }

    public function testChatWritesAndTransactionRollback(): void
    {
        $this->seedChatFixture();

        ChatRepository::markMemberDeleted(10, 3, '2024-05-01 00:00:00');
        $this->assertSame('2024-05-01 00:00:00', $this->rows('conversation_members')[2]['deleted_at']);
        ChatRepository::clearMemberDeleted(10, 3);
        $this->assertNull($this->rows('conversation_members')[2]['deleted_at']);
        ChatRepository::deleteMember(10, 3);
        $this->assertCount(4, $this->rows('conversation_members'));

        ChatRepository::tryMarkIncomingMessagesRead(10, 1, 1);
        $this->assertSame(1, (int)$this->rows('messages')[0]['is_read']);

        ChatRepository::recallMessage(1, '2024-05-02 00:00:00');
        $this->assertSame('2024-05-02 00:00:00', $this->rows('messages')[0]['recalled_at']);

        ChatRepository::updateAnnouncement(10, '新公告', '2024-05-03 00:00:00');
        $this->assertSame('新公告', $this->rows('conversations')[0]['announcement']);
        ChatRepository::updateAnnouncement(10, '', '2024-05-03 00:00:00');
        $this->assertNull($this->rows('conversations')[0]['announcement']);

        ChatRepository::muteMember(10, 2, '2030-01-01 00:00:00');
        $this->assertSame('2030-01-01 00:00:00', $this->rows('conversation_members')[1]['muted_until']);

        // information_schema 探针：老库返回 false，补上 COLUMNS 行后返回 true
        $this->assertFalse(ChatRepository::pinnedColumnExists());
        $stmt = $this->pdo()->prepare('INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME) VALUES (?, ?, ?)');
        $stmt->execute(['main', 'conversation_members', 'pinned']);
        $this->assertTrue(ChatRepository::pinnedColumnExists());

        ChatRepository::updateMemberSettings(1, ['muted' => 1, 'pinned' => 1]);
        $this->assertSame(1, (int)$this->rows('conversation_members')[0]['pinned']);
        try {
            ChatRepository::updateMemberSettings(1, ['role' => 'owner']);
            $this->fail('动态列白名单应拒绝非法字段');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('非法会话设置字段', $e->getMessage());
        }

        // 踢人事务成功：删成员 + 重算 member_count
        $this->assertSame(1, ChatRepository::kickMember(10, 2));
        $this->assertSame(1, (int)$this->rows('conversations')[0]['member_count']);
        $this->assertCount(3, $this->rows('conversation_members'));

        // 踢人事务异常回滚：trigger RAISE(ABORT) 阻断删除，计数不得被改写
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_kick BEFORE DELETE ON conversation_members
WHEN OLD.user_id = 999 BEGIN SELECT RAISE(ABORT, 'kick failed'); END;
SQL);
        $this->seed('conversation_members', ['id' => 90, 'conversation_id' => 10, 'user_id' => 999, 'role' => 'member']);
        $this->pdo()->exec("UPDATE conversations SET member_count = 4 WHERE id = 10");
        try {
            ChatRepository::kickMember(10, 999);
            $this->fail('应当抛出异常');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('kick failed', $e->getMessage());
        }
        $this->assertSame(4, (int)$this->rows('conversations')[0]['member_count'], '计数回写已回滚');
        $this->assertSame([999], array_column(array_filter($this->rows('conversation_members'), fn($r) => (int)$r['user_id'] === 999), 'user_id'));

        // V99：给 ChatService 三套能力闸门补 information_schema 探针行
        $colStmt = $this->pdo()->prepare('INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME) VALUES (?, ?, ?)');
        foreach ([
            ['messages', 'deleted_at'], ['messages', 'deleted_by'], ['messages', 'delete_scope'],
            ['conversations', 'deleted_at'], ['conversation_members', 'deleted_at'],
        ] as $col) {
            $colStmt->execute(array_merge(['main'], $col));
        }
        $tableStmt = $this->pdo()->prepare('INSERT INTO information_schema.TABLES (TABLE_SCHEMA, TABLE_NAME) VALUES (?, ?)');
        $tableStmt->execute(['main', 'messages']);
        $tableStmt->execute(['main', 'message_deletions']);

        // 解散群：消息只软删，成员 / 会话行照常清理（先移除踢人触发器，避免误伤）
        $this->pdo()->exec("DROP TRIGGER fail_kick");
        ChatService::dissolveGroupAsOwner(10, 1, '127.0.0.1', 'behavior-test');
        $remaining = array_filter($this->rows('messages'), fn($r) => (int)$r['conversation_id'] === 10);
        $this->assertNotEmpty($remaining, '解散群后消息行必须保留');
        foreach ($remaining as $row) {
            $this->assertNotNull($row['deleted_at'], '解散群消息必须带 deleted_at 标记');
            $this->assertSame('admin', $row['delete_scope']);
        }
        $this->assertSame([], array_filter($this->rows('conversations'), fn($r) => (int)$r['id'] === 10));

        // 解散群事务异常回滚：消息软删 UPDATE 被阻断时，成员与会话都必须保留
        $this->seed('conversations', ['id' => 777, 'type' => 'group', 'member_count' => 1]);
        $this->seed('conversation_members', ['id' => 91, 'conversation_id' => 777, 'user_id' => 3, 'role' => 'owner']);
        $this->seed('messages', ['id' => 91, 'conversation_id' => 777, 'sender_id' => 3]);
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER fail_dissolve BEFORE UPDATE ON messages
WHEN OLD.conversation_id = 777 BEGIN SELECT RAISE(ABORT, 'dissolve failed'); END;
SQL);
        try {
            ChatService::dissolveGroupAsOwner(777, 3, '127.0.0.1', 'behavior-test');
            $this->fail('应当抛出异常');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('dissolve failed', $e->getMessage());
        }
        $this->assertCount(1, array_filter($this->rows('conversation_members'), fn($r) => (int)$r['conversation_id'] === 777));
        $this->assertCount(1, array_filter($this->rows('conversations'), fn($r) => (int)$r['id'] === 777));
        $this->assertCount(1, array_filter($this->rows('messages'), fn($r) => (int)$r['id'] === 91));
    }

    // =====================================================================
    // RealName 域
    // =====================================================================

    private function seedRealNameFixture(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'alice', 'nickname' => '爱丽丝', 'status' => 1, 'realname_verified' => 0]);
        $this->seed('users', ['id' => 2, 'username' => 'bob', 'nickname' => '鲍勃', 'status' => 1, 'realname_verified' => 1, 'realname_verified_at' => '2024-01-01 00:00:00']);
        $this->seed('users', ['id' => 3, 'username' => 'nobody', 'nickname' => '无名', 'status' => 1]);
        $this->seed('users', ['id' => 5, 'username' => 'frank', 'nickname' => '弗兰克', 'status' => 1]);
        $this->seed('users', ['id' => 6, 'username' => 'grace', 'nickname' => '格蕾丝', 'status' => 1]);
        $today = date('Y-m-d') . ' 10:00:00';
        $old = '2020-01-01 10:00:00';
        foreach ([
            ['id' => 1, 'user_id' => 1, 'real_name_masked' => '张*', 'id_card_masked' => '1101********1234', 'id_card_hash' => 'h1', 'provider' => 'manual', 'status' => 'rejected', 'fail_reason' => '旧失败', 'created_at' => $old, 'updated_at' => $old],
            ['id' => 2, 'user_id' => 1, 'real_name_masked' => '张*', 'id_card_masked' => '1101********1234', 'id_card_hash' => 'h1', 'provider' => 'manual', 'status' => 'pending', 'fail_reason' => '待复核', 'created_at' => $today, 'updated_at' => $today],
            ['id' => 3, 'user_id' => 2, 'real_name_masked' => '李*', 'id_card_masked' => '3101********5678', 'id_card_hash' => 'h2', 'provider' => 'auto', 'status' => 'verified', 'fail_reason' => '', 'verified_at' => $today, 'created_at' => $today, 'updated_at' => $today],
            ['id' => 4, 'user_id' => 3, 'real_name_masked' => '王*', 'id_card_masked' => '4401********9999', 'id_card_hash' => 'h3', 'provider' => 'manual', 'status' => 'revoked', 'fail_reason' => '撤销', 'created_at' => $old, 'updated_at' => $old],
        ] as $record) {
            $this->seed('realname_records', $record);
        }
    }

    public function testRealNameQueriesMatchHeadSnapshots(): void
    {
        $this->seedRealNameFixture();

        $this->assertSame($this->headUserVerification(2), RealNameRepository::findUserVerification(2));
        $this->assertSame($this->headVerifiedFlag(2), RealNameRepository::findVerifiedFlag(2));
        // 相似 SQL 字段集差异交叉断言
        $this->assertSame(['realname_verified', 'realname_verified_at'], array_keys(RealNameRepository::findUserVerification(2)));
        $this->assertSame(['realname_verified'], array_keys(RealNameRepository::findVerifiedFlag(2)));
        $this->assertNotSame(
            array_keys(RealNameRepository::findUserVerification(2)),
            array_keys(RealNameRepository::findVerifiedFlag(2))
        );
        $this->assertNull(RealNameRepository::findUserVerification(999), '空结果负例');

        $this->assertSame($this->headLatestRecord(1), RealNameRepository::findLatestRecord(1));
        $this->assertSame(2, (int)RealNameRepository::findLatestRecord(1)['id'], 'ORDER BY id DESC LIMIT 1');
        $this->assertSame($this->headTodayCount(1), RealNameRepository::countTodaySubmissions(1));
        $this->assertSame(1, RealNameRepository::countTodaySubmissions(1), 'CURDATE() 只计今日');
        $this->assertSame(0, RealNameRepository::countTodaySubmissions(999));
        $this->assertSame($this->headRecordByHash('h2'), RealNameRepository::findRecordByHash('h2'));
        $this->assertNull(RealNameRepository::findRecordByHash('none'));

        foreach ([['', ''], ['pending', ''], ['verified', ''], ['', '爱丽丝'], ['', 'bob'], ['bogus', 'zzz']] as [$status, $keyword]) {
            $this->assertSame($this->headAdminCount($status, $keyword), RealNameRepository::countAdminRecords($status, $keyword), "计数 {$status}/{$keyword}");
            $this->assertSame($this->headAdminList($status, $keyword, 20, 0), RealNameRepository::fetchAdminRecords($status, $keyword, 20, 0), "列表 {$status}/{$keyword}");
        }
        // 多页 + LIMIT ? OFFSET ? 顺序
        $this->assertSame($this->headAdminList('', '', 2, 0), RealNameRepository::fetchAdminRecords('', '', 2, 0));
        $this->assertSame($this->headAdminList('', '', 2, 2), RealNameRepository::fetchAdminRecords('', '', 2, 2));
        $this->assertSame([2, 1], array_column(RealNameRepository::fetchAdminRecords('', '', 2, 2), 'id'));
        $this->assertSame([], RealNameRepository::fetchAdminRecords('', '', 20, 100), '越界页空结果');
        $this->assertSame(16, count(RealNameRepository::fetchAdminRecords('', '', 20, 0)[0]), '列表 16 列不可增减');

        $this->assertSame($this->headRecordById(3), RealNameRepository::findRecordById(3));
        $this->assertSame($this->headVerifiedConflict('h2', 1), RealNameRepository::findVerifiedConflict('h2', 1));
        $this->assertNull(RealNameRepository::findVerifiedConflict('h2', 2), '同用户不算冲突');
        $this->assertSame($this->headRecordStatus(3), RealNameRepository::fetchRecordStatus(3));
        $this->assertFalse(RealNameRepository::fetchRecordStatus(999));
    }

    public function testRealNameWritesAndFieldSetDifferences(): void
    {
        $this->seedRealNameFixture();
        $now = '2024-06-01 00:00:00';

        // 10 列 pending 记录（与 HEAD 插入字段清单一致）
        RealNameRepository::insertRecord([
            'user_id' => 1,
            'real_name_masked' => '张*',
            'id_card_masked' => '1101********1234',
            'id_card_hash' => 'h1',
            'provider' => 'manual',
            'status' => 'pending',
            'fail_reason' => '待人工复核（未配置自动核验服务）',
            'provider_code' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inserted = $this->rows('realname_records');
        $tenKeys = array_keys(end($inserted));
        $this->assertCount(14, $tenKeys, '表 14 列：只写了 HEAD 的 10 列，其余走默认值');

        // 13 列 verified 记录（独立方法，不能与上面合并）
        RealNameRepository::insertVerifiedRecord([
            'user_id' => 3,
            'real_name_masked' => '王*',
            'id_card_masked' => '4401********9999',
            'id_card_hash' => 'h3',
            'provider' => 'auto',
            'status' => 'verified',
            'fail_reason' => '',
            'provider_code' => 'ok',
            'reviewer_id' => 0,
            'reviewed_at' => $now,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inserted = $this->rows('realname_records');
        $verifiedRow = end($inserted);
        $this->assertSame($now, $verifiedRow['verified_at'], '13 列插入独有字段');
        $this->assertSame(0, (int)$verifiedRow['reviewer_id']);
        $inserted = $this->rows('realname_records');
        $tenRow = null;
        foreach ($inserted as $row) {
            if ((int)$row['user_id'] === 1 && $row['fail_reason'] === '待人工复核（未配置自动核验服务）') {
                $tenRow = $row;
                break;
            }
        }
        $this->assertNotNull($tenRow);
        $this->assertNull($tenRow['verified_at'], '10 列插入不写 verified_at');
        $this->assertNull($tenRow['reviewer_id'], '10 列插入不写 reviewer_id');
        $this->assertSame($now, $verifiedRow['verified_at'], '13 列插入独有字段已写入');
        $this->assertSame($tenKeys, array_keys($verifiedRow), '两张方法写同一张表，靠字段值而非列数区分');
        try {
            RealNameRepository::insertRecord(['user_id' => 1, 'verified_at' => $now]);
            $this->fail('白名单应拒绝 10 列方法写入 verified_at');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('非法实名记录字段', $e->getMessage());
        }

        // 清理未通过记录：只删 pending/rejected/revoked，保留 verified
        RealNameRepository::deleteUnsuccessfulRecords(1);
        $this->assertSame([], array_filter($this->rows('realname_records'), fn($r) => (int)$r['user_id'] === 1));
        $this->assertCount(1, array_filter($this->rows('realname_records'), fn($r) => (int)$r['user_id'] === 2));

        $review = ['reviewer_id' => 9, 'now' => $now];
        // approve：6 字段（含 verified_at）
        $this->seed('realname_records', ['id' => 50, 'user_id' => 5, 'id_card_hash' => 'hz', 'status' => 'pending', 'created_at' => $now]);
        RealNameRepository::approveRecord(50, $review);
        $approved = $this->headRecordById(50);
        $this->assertSame('verified', $approved['status']);
        $this->assertSame($now, $approved['verified_at']);
        $this->assertSame('', $approved['fail_reason']);
        RealNameRepository::setUserVerified(5, 1, $now);
        $this->assertSame($this->headUserVerification(5), RealNameRepository::findUserVerification(5));

        // reject：5 字段（不含 verified_at）
        $this->seed('realname_records', ['id' => 51, 'user_id' => 6, 'id_card_hash' => 'hz2', 'status' => 'pending', 'verified_at' => $now]);
        RealNameRepository::rejectRecord(51, ['fail_reason' => '人工驳回', 'reviewer_id' => 9, 'now' => $now]);
        $rejected = $this->headRecordById(51);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('人工驳回', $rejected['fail_reason']);
        $this->assertSame($now, $rejected['verified_at'], 'reject 不覆盖 verified_at');
        RealNameRepository::setUserVerified(6, 0, null);
        $this->assertNull(RealNameRepository::findUserVerification(6)['realname_verified_at']);

        // revoke：5 字段但 status 不同
        RealNameRepository::revokeRecord(51, ['fail_reason' => '管理员撤销', 'reviewer_id' => 9, 'now' => $now]);
        $revoked = $this->headRecordById(51);
        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame('管理员撤销', $revoked['fail_reason']);
        $this->assertNotSame($approved, $revoked);
        $this->assertSame($this->headRecordStatus(51), RealNameRepository::fetchRecordStatus(51));

        // 事务归零校验：仓储事务方法不得遗留未提交事务
        $this->assertTrue($this->pdo()->inTransaction() === false);
        $this->assertSame(0, Database::query("SELECT COUNT(*) FROM realname_records WHERE user_id = 999")->fetchColumn());
    }
}
