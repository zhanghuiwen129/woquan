<?php
/**
 * 2026-11-24 圈子分析文档核查后的修复回归测试
 *
 * 覆盖五处代码级缺陷：
 *   1) findPostInCircle 把 circleId 误绑到 p.user_id，圈外动态可被圈主管理；
 *   2) joinFree 非事务非幂等，重复入圈会让 member_count 漂移；
 *   3) approval 撞唯一键直接 500，历史申请无法复位；
 *   4) 后台删圈不清理群会话，留下孤儿 conversation；
 *   5) post_review 圈内发帖审核：待审态流式流转 + ContentAudit 不得误放行。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\CircleRepository;
use PDO;
use ReflectionMethod;

final class CircleFixBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // MySQL 的 NOW() 在 SQLite 里不存在，注册等价函数让 Repository 的真实 SQL 能跑
        $this->pdo()->sqliteCreateFunction('NOW', static function (): string {
            return date('Y-m-d H:i:s');
        });

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `circles` (
    `id`             INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`           TEXT    DEFAULT NULL,
    `owner_id`       INTEGER NOT NULL DEFAULT 0,
    `member_count`   INTEGER NOT NULL DEFAULT 0,
    `post_count`     INTEGER NOT NULL DEFAULT 0,
    `post_review`    INTEGER NOT NULL DEFAULT 0,
    `status`         INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    NOT NULL DEFAULT 'member',
    `joined_at` TEXT    DEFAULT NULL,
    `status`    INTEGER NOT NULL DEFAULT 1,
    UNIQUE (circle_id, user_id)
);
CREATE TABLE `circle_join_requests` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`   INTEGER NOT NULL,
    `user_id`     INTEGER NOT NULL,
    `status`      TEXT    NOT NULL DEFAULT 'pending',
    `message`     TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `reviewer_id` INTEGER DEFAULT NULL,
    `reviewed_at` TEXT    DEFAULT NULL,
    UNIQUE (circle_id, user_id, status)
);
CREATE TABLE `conversations` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER DEFAULT NULL,
    `type`      TEXT    NOT NULL DEFAULT 'private'
);
CREATE TABLE `conversation_members` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `user_id`         INTEGER NOT NULL
);
CREATE TABLE `posts` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `circle_id`  INTEGER DEFAULT NULL,
    `post_type`  TEXT    DEFAULT 'normal',
    `content`    TEXT    DEFAULT NULL,
    `visibility` TEXT    DEFAULT 'public',
    `status`     INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `sender_id`  INTEGER NOT NULL,
    `type`       TEXT    DEFAULT 'system',
    `post_id`    INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `is_read`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
SQL);
    }

    /** #1：动态必须真的属于目标圈子；旧 SQL 会把 user_id==circleId 的圈外帖当成圈内帖 */
    public function testFindPostInCircleRejectsForeignPost(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 1]);
        $this->seed('circles', ['id' => 2, 'name' => '圈B', 'owner_id' => 2]);
        // 作者 id=2 恰等于圈 B 的 id；动态实际发在圈 A
        $this->seed('posts', [
            'id' => 50, 'user_id' => 2, 'circle_id' => 1, 'status' => 1, 'content' => '圈内动态',
        ]);

        $this->assertNotNull(CircleRepository::findPostInCircle(50, 1), '本圈动态必须能查到');
        $this->assertNull(CircleRepository::findPostInCircle(50, 2), '圈外动态不得被误判为圈内动态');
    }

    /** #3：free 入圈重复调用不重复计数，成员表也只有一行 */
    public function testJoinFreeIsIdempotentAndKeepsCountInSync(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '测试圈', 'member_count' => 0]);

        CircleRepository::joinFree(1, 10);
        CircleRepository::joinFree(1, 10);

        $this->assertSame(1, (int)$this->pdo()->query(
            'SELECT COUNT(*) FROM circle_members WHERE circle_id = 1 AND user_id = 10'
        )->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query(
            'SELECT member_count FROM circles WHERE id = 1'
        )->fetchColumn(), 'member_count 不得重复累加');
    }

    /** #4：历史申请复位为 pending，而不是把 500 抛给用户 */
    public function testReactivateJoinRequestReusesLatestRecord(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '审核圈']);
        $this->seed('circle_join_requests', [
            'id' => 7, 'circle_id' => 1, 'user_id' => 10,
            'status' => 'rejected', 'message' => '旧申请', 'reviewer_id' => 99,
        ]);
        $this->seed('circle_join_requests', [
            'id' => 8, 'circle_id' => 1, 'user_id' => 10,
            'status' => 'pending', 'message' => '待处理',
        ]);

        CircleRepository::reactivateJoinRequest(1, 10, '重新申请');

        $pending = $this->pdo()->query(
            "SELECT id, message, reviewer_id FROM circle_join_requests
             WHERE circle_id = 1 AND user_id = 10 AND status = 'pending'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $pending, '复位后只能有一条 pending');
        $this->assertSame('重新申请', $pending[0]['message']);
        $this->assertNull($pending[0]['reviewer_id']);
    }

    /** #5：后台删圈与前台级联对齐，清理群会话与群成员 */
    public function testDeleteAdminCircleRemovesGroupConversation(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '有群圈', 'member_count' => 2]);
        $this->seed('circle_members', ['id' => 1, 'circle_id' => 1, 'user_id' => 10, 'role' => 'owner']);
        $this->seed('conversations', ['id' => 60, 'circle_id' => 1, 'type' => 'group']);
        $this->seed('conversation_members', ['id' => 1, 'conversation_id' => 60, 'user_id' => 10]);
        $this->seed('posts', ['id' => 50, 'user_id' => 10, 'circle_id' => 1, 'content' => '圈内帖']);

        CircleRepository::deleteAdminCircle(1);

        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM conversations WHERE id = 60')->fetchColumn(), '孤儿群必须被删除');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = 60')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM circles WHERE id = 1')->fetchColumn());
        $this->assertNull($this->pdo()->query('SELECT circle_id FROM posts WHERE id = 50')->fetchColumn(), '动态应回到全站流而不是被删除');
    }

    /** #2：圈内审核的状态流转 + 待审列表口径 */
    public function testCircleReviewFlowAndPendingList(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '审核圈', 'post_review' => 1]);
        $this->seed('users', ['id' => 10, 'username' => 'alice']);
        $this->seed('posts', [
            'id' => 50, 'user_id' => 10, 'circle_id' => 1,
            'status' => 0, 'audit_state' => 1, 'content' => '待审动态', 'created_at' => '2026-11-24 10:00:00',
        ]);
        $this->seed('posts', [
            'id' => 51, 'user_id' => 10, 'circle_id' => 1,
            'status' => 1, 'audit_state' => 0, 'content' => '已公开动态',
        ]);

        $rows = CircleRepository::fetchPendingCirclePosts(1, 0, 20);
        $this->assertCount(1, $rows, '只有待审动态进入圈主审核列表');
        $this->assertSame(50, (int)$rows[0]['id']);
        $this->assertSame(1, CircleRepository::countPendingCirclePosts(1));

        $this->assertTrue(CircleRepository::reviewCirclePost(50, 1, 'approve'));
        $after = $this->pdo()->query('SELECT status, audit_state FROM posts WHERE id = 50')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$after['status']);
        $this->assertSame(2, (int)$after['audit_state']);

        // 幂等：已放行的动态不能被重复审核或反向改回拒绝
        $this->assertFalse(CircleRepository::reviewCirclePost(50, 1, 'reject'));
        $after2 = $this->pdo()->query('SELECT status, audit_state FROM posts WHERE id = 50')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($after, $after2);
    }

    /** #2 安全底线：内容审核插件不得把「圈内待审」自动放行，否则圈内审核形同虚设 */
    public function testContentAuditDoesNotAutoPassCircleReviewPost(): void
    {
        $pluginFile = dirname(__DIR__, 3) . '/plugins/ContentAudit/Plugin.php';
        require_once $pluginFile;

        $this->seed('circles', ['id' => 1, 'name' => '审核圈', 'post_review' => 1]);
        $this->seed('posts', [
            'id' => 50, 'user_id' => 10, 'circle_id' => 1,
            'status' => 0, 'audit_state' => 1, 'content' => '待圈主审核',
        ]);

        $method = new ReflectionMethod(\Hublog\Plugin\ContentAudit\Plugin::class, 'markAsPassed');
        $method->setAccessible(true);
        $method->invoke(null, 50, 'post');

        $row = $this->pdo()->query('SELECT status, audit_state FROM posts WHERE id = 50')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$row['status'], '圈内待审不得被内容审核插件自动放行');
        $this->assertSame(1, (int)$row['audit_state']);
    }
}
