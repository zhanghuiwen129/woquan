<?php
/**
 * CircleRepository 迁移回归测试
 *
 * 核心断言方式：在内存 SQLite 里同时执行「迁移前的原始内联 SQL」与
 * 「迁移后的 Repository 方法」，逐字段比对返回结构，确保下沉数据访问
 * 不改排序、分页、可见性、软删条件与字段名。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\CircleRepository;
use Hublog\Core\VisibilityPolicy;
use PDO;

final class CircleRepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // FIELD() 是 MySQL 函数；给 SQLite 注册等价实现，使成员列表排序可真实验证
        $this->pdo()->sqliteCreateFunction('FIELD', static function (...$args): int {
            $needle = array_shift($args);
            $pos = array_search($needle, $args, true);
            return $pos === false ? 0 : $pos + 1;
        });

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `circles` (
    `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`           TEXT    DEFAULT NULL,
    `slug`           TEXT    DEFAULT NULL,
    `description`    TEXT    DEFAULT NULL,
    `cover`          TEXT    DEFAULT NULL,
    `owner_id`       INTEGER NOT NULL DEFAULT 0,
    `member_count`   INTEGER NOT NULL DEFAULT 0,
    `post_count`     INTEGER NOT NULL DEFAULT 0,
    `join_mode`      TEXT    DEFAULT 'free',
    `post_permission` TEXT   DEFAULT 'all',
    `visibility`     TEXT    DEFAULT 'public',
    `created_at`     TEXT    DEFAULT NULL,
    `updated_at`     TEXT    DEFAULT NULL,
    `announcement`   TEXT    DEFAULT NULL,
    `announcement_at` TEXT   DEFAULT NULL,
    `tags`           TEXT    DEFAULT NULL,
    `post_review`    INTEGER NOT NULL DEFAULT 0,
    `status`         INTEGER NOT NULL DEFAULT 1,
    `removed_at`     TEXT    DEFAULT NULL,
    `pay_type`       INTEGER NOT NULL DEFAULT 0,
    `pay_amount`     REAL    NOT NULL DEFAULT 0,
    `pay_vip_level`  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`  INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `role`       TEXT    DEFAULT 'member',
    `joined_at`  TEXT    DEFAULT NULL,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `muted_until` TEXT   DEFAULT NULL
);
CREATE TABLE `circle_join_requests` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`   INTEGER NOT NULL,
    `user_id`     INTEGER NOT NULL,
    `status`      TEXT    DEFAULT 'pending',
    `message`     TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `reviewed_at` TEXT    DEFAULT NULL,
    `reviewer_id` INTEGER DEFAULT NULL
);
CREATE TABLE `circle_subscribes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`  INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `status`     INTEGER NOT NULL DEFAULT 0,
    `expire_at`  TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `follows` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `posts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `circle_id`  INTEGER DEFAULT NULL,
    `post_type`  TEXT    DEFAULT 'normal',
    `visibility` TEXT    DEFAULT 'public',
    `status`     INTEGER NOT NULL DEFAULT 1,
    `is_pinned`  INTEGER NOT NULL DEFAULT 0,
    `pinned_at`  TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
SQL);
    }

    // ===== 列表 / 计数：与迁移前逐字等价 =====

    public function testPublicListMatchesOriginalSqlShapeOrderAndFilters(): void
    {
        $base = [
            'slug' => 'c', 'description' => 'desc', 'cover' => '/c.jpg',
            'owner_id' => 1, 'member_count' => 0, 'post_count' => 0,
            'join_mode' => 'free', 'post_permission' => 'all', 'visibility' => 'public',
            'created_at' => '2026-01-01 00:00:00',
        ];
        $this->seed('circles', array_merge($base, ['id' => 1, 'name' => '运动圈', 'member_count' => 50]));
        $this->seed('circles', array_merge($base, ['id' => 2, 'name' => '摄影圈', 'member_count' => 90]));
        $this->seed('circles', array_merge($base, ['id' => 3, 'name' => 'protected圈', 'member_count' => 999, 'visibility' => 'protected']));
        $this->seed('circles', array_merge($base, ['id' => 4, 'name' => 'private圈', 'member_count' => 999, 'visibility' => 'private']));
        $this->seed('circles', array_merge($base, ['id' => 5, 'name' => '下架圈', 'member_count' => 999, 'status' => 0]));
        $this->seed('circles', array_merge($base, ['id' => 6, 'name' => '已移除圈', 'member_count' => 999, 'removed_at' => '2026-02-01 00:00:00']));

        foreach ([['', 0, 20], ['摄影', 0, 20], ['', 1, 1]] as [$keyword, $offset, $limit]) {
            $expected = $this->originalPublicList($keyword, $offset, $limit);
            $actual = CircleRepository::fetchPublicList($keyword, $offset, $limit);
            $this->assertSame($expected, $actual, '公开列表返回结构/排序/分页必须与迁移前一致');
        }

        $this->assertSame([2, 1], array_column(CircleRepository::fetchPublicList('', 0, 20), 'id'), '按 member_count DESC, id DESC');
        $this->assertSame([
            'id', 'name', 'slug', 'description', 'cover', 'owner_id', 'member_count', 'post_count',
            'join_mode', 'post_permission', 'visibility', 'created_at',
        ], array_keys(CircleRepository::fetchPublicList('', 0, 1)[0]), '字段顺序不得变化');

        $this->assertSame(2, CircleRepository::countPublic(''));
        $this->assertSame(1, CircleRepository::countPublic('摄影'));
        $this->assertSame(0, CircleRepository::countPublic('不存在'));
    }

    // ===== 详情：下架/软删过滤、圈主字段、黑名单成员 =====

    public function testDetailQueriesMatchOriginalAndKeepVisibilityRules(): void
    {
        $base = [
            'name' => '圈A', 'slug' => 'circle-a', 'description' => 'd', 'cover' => '/cover.jpg',
            'owner_id' => 10, 'member_count' => 1, 'post_count' => 0,
            'join_mode' => 'free', 'post_permission' => 'all', 'visibility' => 'public',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->seed('circles', array_merge($base, ['id' => 1]));
        $this->seed('circles', array_merge($base, ['id' => 2, 'slug' => 'removed', 'removed_at' => '2026-02-01 00:00:00']));
        $this->seed('circles', array_merge($base, ['id' => 3, 'slug' => 'hidden', 'status' => 0]));
        $this->seed('users', ['id' => 10, 'username' => 'owner', 'nickname' => '圈主', 'avatar' => '/a.jpg', 'role' => 'user']);
        $this->seed('circle_members', ['id' => 1, 'circle_id' => 1, 'user_id' => 20, 'role' => 'member', 'status' => 1]);
        $this->seed('circle_members', ['id' => 2, 'circle_id' => 1, 'user_id' => 30, 'role' => 'member', 'status' => 2]);

        $expected = $this->pdo()->query(
            "SELECT * FROM circles WHERE id = 1 AND status = 1 AND removed_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($expected, CircleRepository::findVisibleById(1));
        $this->assertNull(CircleRepository::findVisibleById(2), '已下架圈子不得出现在详情');
        $this->assertNull(CircleRepository::findVisibleById(3), 'status=0 不得出现在详情');

        $expectedBySlug = $this->pdo()->query(
            "SELECT * FROM circles WHERE slug = 'circle-a' AND status = 1 AND removed_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($expectedBySlug, CircleRepository::findVisibleBySlug('circle-a'));

        $owner = CircleRepository::findOwner(10);
        $this->assertSame(['id', 'username', 'nickname', 'avatar', 'role'], array_keys($owner));
        $this->assertSame('owner', $owner['username']);

        $memberRow = CircleRepository::getMemberRow(1, 20);
        $this->assertSame(['role', 'status'], array_keys($memberRow));
        $this->assertSame('member', $memberRow['role']);
        $this->assertSame(2, (int)CircleRepository::getMemberRow(1, 30)['status'], '黑名单成员 status=2');
        $this->assertNull(CircleRepository::getMemberRow(1, 99));

        // 任意状态查询（update/delete 回显用）不受 status/removed_at 影响
        $this->assertNotNull(CircleRepository::findAnyById(2));
        $this->assertNotNull(CircleRepository::findAnyById(3));

        $this->assertNull(CircleRepository::getMemberRole(1, 0), '非登录用户无角色');
        $this->assertSame('member', CircleRepository::getMemberRole(1, 20));
    }

    // ===== 成员列表：排序 / 分页 / 计数、黑名单隐身 =====

    public function testMembersListMatchesOriginalSqlAndExcludesBanned(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public', 'status' => 1]);
        foreach ([
            [10, 'owner', '2026-01-01 00:00:00', 1],
            [11, 'admin', '2026-01-02 00:00:00', 1],
            [12, 'member', '2026-01-03 00:00:00', 1],
            [13, 'member', '2026-01-04 00:00:00', 2], // 黑名单，隐身
        ] as [$uid, $role, $joined, $status]) {
            $this->seed('circle_members', [
                'circle_id' => 1, 'user_id' => $uid, 'role' => $role,
                'joined_at' => $joined, 'status' => $status,
            ]);
        }
        foreach ([10, 11, 12, 13] as $uid) {
            $this->seed('users', ['id' => $uid, 'username' => "u{$uid}", 'nickname' => "n{$uid}", 'avatar' => '', 'role' => 'user']);
        }

        foreach ([[0, 50], [1, 1]] as [$offset, $limit]) {
            $this->assertSame(
                $this->originalMembers(1, $offset, $limit),
                CircleRepository::fetchMembers(1, $offset, $limit),
                '成员列表字段/排序/分页必须与迁移前一致'
            );
        }
        $this->assertSame([10, 11, 12], array_column(CircleRepository::fetchMembers(1, 0, 50), 'user_id'), 'FIELD(owner,admin,member) 排序');
        $this->assertSame(3, CircleRepository::countMembers(1), '黑名单成员不计入成员数与列表');
        $this->assertSame(
            (int)$this->pdo()->query("SELECT COUNT(*) FROM circle_members WHERE circle_id = 1 AND status = 1")->fetchColumn(),
            CircleRepository::countMembers(1)
        );
    }

    // ===== 圈内动态：可见性参数化、post_type 铁律、计数一致 =====

    public function testCirclePostsVisibilityAndCountMatchOriginal(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public', 'status' => 1]);
        $post = static fn(int $id, int $uid, string $vis, int $status = 1, int $pin = 0, string $at = '2026-01-01 00:00:00'): array => [
            'id' => $id, 'user_id' => $uid, 'circle_id' => 1, 'post_type' => 'normal',
            'visibility' => $vis, 'status' => $status, 'is_pinned' => $pin, 'pinned_at' => null, 'created_at' => $at,
        ];
        $this->seed('posts', $post(1, 20, 'public'));
        $this->seed('posts', $post(2, 21, 'circle'));                       // 圈内可见
        $this->seed('posts', $post(3, 20, 'private'));                      // 作者本人可见
        $this->seed('posts', $post(4, 99, 'private'));                      // 他人私密，不可见
        $this->seed('posts', $post(5, 20, 'public', 0));                    // status=0，不可见
        $this->seed('posts', $post(6, 20, 'public', 1, 1, '2026-02-01 00:00:00')); // 置顶

        $userId = 20;
        $repoRows = CircleRepository::fetchCirclePostIds(1, $userId, 0, 20);
        $expectedRows = $this->originalCirclePostIds(1, $userId, 0, 20);

        $this->assertSame(
            array_column($expectedRows, 'id'),
            array_column($repoRows, 'id'),
            '圈内动态 ID 与排序必须与迁移前一致'
        );
        $this->assertSame([6, 1, 3], array_column($repoRows, 'id'), '置顶优先 + 权威可见性约束（未入圈看不到 circle 帖）');
        $this->assertArrayHasKey('post_type', $repoRows[0], 'posts 行查询必须带 p.post_type');

        $this->assertSame(count($expectedRows), CircleRepository::countCirclePosts(1, $userId));
        $this->assertSame(0, CircleRepository::countCirclePosts(999, $userId), '无匹配圈子时计数为 0');

        // 分页
        $this->assertSame(
            array_column($this->originalCirclePostIds(1, $userId, 1, 2), 'id'),
            array_column(CircleRepository::fetchCirclePostIds(1, $userId, 1, 2), 'id')
        );
    }

    public function testGuestSeesOnlyPublicCirclePosts(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public', 'status' => 1]);
        $base = ['circle_id' => 1, 'post_type' => 'normal', 'status' => 1, 'is_pinned' => 0, 'pinned_at' => null];
        $this->seed('posts', array_merge($base, [
            'id' => 1, 'user_id' => 20, 'visibility' => 'public', 'created_at' => '2026-01-01 00:00:00',
        ]));
        $this->seed('posts', array_merge($base, [
            'id' => 2, 'user_id' => 21, 'visibility' => 'circle', 'created_at' => '2026-01-02 00:00:00',
        ]));
        $this->seed('posts', array_merge($base, [
            'id' => 3, 'user_id' => 20, 'visibility' => 'private', 'created_at' => '2026-01-03 00:00:00',
        ]));
        $this->seed('posts', array_merge($base, [
            'id' => 6, 'user_id' => 20, 'visibility' => 'public', 'is_pinned' => 1,
            'created_at' => '2026-02-01 00:00:00',
        ]));

        $this->assertSame([6, 1], array_column(CircleRepository::fetchCirclePostIds(1, 0, 0, 20), 'id'));
        $this->assertSame(2, CircleRepository::countCirclePosts(1, 0));
    }

    public function testNonMemberCannotSeeCirclePostButMemberCan(): void
    {
        $this->seed('circles', [
            'id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public',
            'status' => 1, 'pay_type' => 0,
        ]);
        foreach ([1 => 'public', 2 => 'circle'] as $id => $visibility) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => 21, 'circle_id' => 1, 'post_type' => 'normal',
                'visibility' => $visibility, 'status' => 1, 'is_pinned' => 0,
                'pinned_at' => null, 'created_at' => "2026-01-0{$id} 00:00:00",
            ]);
        }

        // 非成员：circle 帖不可见
        $this->assertSame([1], array_column(CircleRepository::fetchCirclePostIds(1, 20, 0, 20), 'id'));
        $this->assertSame(1, CircleRepository::countCirclePosts(1, 20));

        // 有效成员：circle 帖可见
        $this->seed('circle_members', [
            'id' => 1, 'circle_id' => 1, 'user_id' => 20, 'role' => 'member', 'status' => 1,
        ]);
        $this->assertSame([2, 1], array_column(CircleRepository::fetchCirclePostIds(1, 20, 0, 20), 'id'));
        $this->assertSame(2, CircleRepository::countCirclePosts(1, 20));

        // 付费圈：成员但未订阅仍不可见
        $this->pdo()->exec('UPDATE circles SET pay_type = 2 WHERE id = 1');
        $this->assertSame([1], array_column(CircleRepository::fetchCirclePostIds(1, 20, 0, 20), 'id'));
        $this->assertSame(1, CircleRepository::countCirclePosts(1, 20));

        // 有效订阅后恢复可见
        $this->seed('circle_subscribes', [
            'id' => 1, 'circle_id' => 1, 'user_id' => 20, 'status' => 1,
            'expire_at' => '2099-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->assertSame([2, 1], array_column(CircleRepository::fetchCirclePostIds(1, 20, 0, 20), 'id'));
        $this->assertSame(2, CircleRepository::countCirclePosts(1, 20));
    }

    public function testAuthorStillSeesOwnPrivatePost(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public', 'status' => 1]);
        $this->seed('posts', [
            'id' => 3, 'user_id' => 20, 'circle_id' => 1, 'post_type' => 'normal',
            'visibility' => 'private', 'status' => 1, 'is_pinned' => 0,
            'pinned_at' => null, 'created_at' => '2026-01-03 00:00:00',
        ]);
        $this->assertSame([3], array_column(CircleRepository::fetchCirclePostIds(1, 20, 0, 20), 'id'));
        $this->assertSame(1, CircleRepository::countCirclePosts(1, 20));
        $this->assertSame([], CircleRepository::fetchCirclePostIds(1, 21, 0, 20));
    }

    // ===== 辅助读路径 =====

    public function testJoinedSetAndJoinRequestQueries(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'visibility' => 'public', 'status' => 1]);
        $this->seed('circles', ['id' => 2, 'name' => '圈B', 'owner_id' => 10, 'visibility' => 'public', 'status' => 0]);
        $this->seed('circle_members', ['id' => 1, 'circle_id' => 1, 'user_id' => 20, 'role' => 'member', 'status' => 1]);

        $this->assertSame([], CircleRepository::fetchJoinedSet(20, []));
        $this->assertSame([1 => true], CircleRepository::fetchJoinedSet(20, [1, 2, 3]));
        $this->assertFalse(CircleRepository::slugExists('not-exist'));

        $this->seed('users', ['id' => 30, 'username' => 'applicant', 'role' => 'user']);
        $this->seed('circle_join_requests', [
            'id' => 1, 'circle_id' => 1, 'user_id' => 30, 'status' => 'pending',
            'message' => 'hi', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->assertSame(1, CircleRepository::countPendingRequests(1));
        $this->assertSame('hi', CircleRepository::fetchPendingRequests(1, 0, 20)[0]['message']);
        $this->assertSame([], CircleRepository::fetchPendingRequests(2, 0, 20));
    }

    // ===== 迁移前原始 SQL 快照（用于逐字段对照） =====

    private function originalPublicList(string $keyword, int $offset, int $limit): array
    {
        $where = "WHERE status = 1 AND visibility = 'public' AND removed_at IS NULL";
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR description LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $stmt = $this->pdo()->prepare(
            "SELECT id, name, slug, description, cover, owner_id, member_count, post_count,
                    join_mode, post_permission, visibility, created_at
             FROM circles {$where}
             ORDER BY member_count DESC, id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalMembers(int $circleId, int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT cm.user_id, cm.role, cm.joined_at, cm.status,
                    u.username, u.nickname, u.avatar, u.role AS user_role
             FROM circle_members cm
             INNER JOIN users u ON cm.user_id = u.id
             WHERE cm.circle_id = ? AND cm.status = 1
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.joined_at ASC
             LIMIT ?, ?"
        );
        $stmt->execute([$circleId, $offset, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCirclePostIds(int $circleId, int $userId, int $offset, int $limit): array
    {
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        $stmt = $this->pdo()->prepare(
            "SELECT p.id FROM posts p WHERE p.circle_id = ? AND p.status = 1 AND {$visWhere}
 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC LIMIT ?, ?"
        );
        $stmt->execute(array_merge([$circleId], $visParams, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
