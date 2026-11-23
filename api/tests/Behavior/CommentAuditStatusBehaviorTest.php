<?php
/**
 * 批次8 P0-1：评论审核状态全链路行为回归。
 *
 * 覆盖：
 *   1. 违规评论转待审后 status=0/audit_state=1，posts.comment_count 同事务 -1；
 *   2. 审核通过（comment 类型）恢复公开并 +1，不改变其他评论；
 *   3. 评论列表主查询/fallback 都过滤待审评论；
 *   4. CounterReconciler 不把待审评论刷回 comment_count；
 *   5. 管理员下架清 audit_state，作者编辑不会重审洗白；
 *   6. V100 迁移的幂等 / catch 内 throw / 收尾硬自检形状 + 契约精确哨兵。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AdminRepository;
use Hublog\Core\CounterReconciler;
use Hublog\Core\MigrationContract;
use Hublog\Core\PostFormatter;
use ReflectionClass;
use RuntimeException;

final class CommentAuditStatusBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();
        $pdo->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `videos` TEXT DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER NOT NULL DEFAULT 0,
    `scheduled_at` TEXT DEFAULT NULL,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `view_count` INTEGER NOT NULL DEFAULT 0,
    `circle_id` INTEGER DEFAULT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `visibility` TEXT DEFAULT 'public',
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `pinned_at` TEXT DEFAULT NULL,
    `is_anonymous` INTEGER NOT NULL DEFAULT 0,
    `is_original` INTEGER NOT NULL DEFAULT 0,
    `post_type` TEXT DEFAULT 'normal',
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `parent_id` INTEGER DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `image` TEXT DEFAULT NULL,
    `is_ai` INTEGER NOT NULL DEFAULT 0,
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comment_likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `comment_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL
);
CREATE TABLE `comment_marks` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `comment_id` INTEGER NOT NULL, `mark_type` TEXT NOT NULL
);
SQL);
        // 底座的 uploads 表没有 post_id（PostFormatter 会按 post_id 查下架媒体）。
        $this->pdo()->exec('ALTER TABLE `uploads` ADD COLUMN `post_id` INTEGER DEFAULT 0');
        $this->loadPlugin();
    }

    private function loadPlugin(): void
    {
        $file = self::root() . '/plugins/ContentAudit/Plugin.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    private function seedPost(int $id, int $commentCount): void
    {
        $this->seed('posts', [
            'id' => $id, 'user_id' => 1, 'title' => '', 'content' => '正常动态',
            'images' => null, 'videos' => null, 'attachments' => null,
            'status' => 1, 'audit_state' => 0, 'comment_count' => $commentCount,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->seed('users', ['id' => 1, 'username' => 'author', 'nickname' => 'author', 'avatar' => null]);
    }

    private function seedComment(int $id, int $postId, string $content, int $status = 1, int $auditState = 0): void
    {
        $this->seed('comments', [
            'id' => $id, 'post_id' => $postId, 'user_id' => 1, 'content' => $content,
            'status' => $status, 'audit_state' => $auditState,
            'created_at' => '2026-01-01 00:00:01',
        ]);
    }

    // ── 1) 违规评论转待审 ────────────────────────────────────────────────

    public function testViolatingCommentIsHiddenAndCountDecremented(): void
    {
        $this->seedPost(10, 3);
        $this->seedComment(101, 10, '这是一条垃圾评论');
        $this->seedComment(102, 10, '正常评论');

        \Hublog\Plugin\ContentAudit\Plugin::onPostCommented(10, 1, 101);

        $comments = $this->rows('comments');
        $this->assertSame(0, (int)$comments[0]['status'], '违规评论必须 status=0');
        $this->assertSame(1, (int)$comments[0]['audit_state'], '违规评论必须 audit_state=1');
        $this->assertSame(1, (int)$comments[1]['status'], '其他评论不受影响');
        $this->assertSame(
            2,
            (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 10')->fetchColumn(),
            '待审评论必须从公开评论数中摘除'
        );
    }

    // ── 2) 评论审核通过 ──────────────────────────────────────────────────

    public function testPassedCommentIsRestoredAndCountIncremented(): void
    {
        $this->seedPost(20, 1);
        $this->seedComment(201, 20, '修改后的评论', 0, 1);

        // 与 ContentAudit 的 AI 第三层放行同路径：runFromPayload 无 AI 配置时走 markAsPassed。
        // 这里直接反射调用私有方法，聚焦状态机本身，不引入外部模型调用。
        $ref = new \ReflectionMethod(\Hublog\Plugin\ContentAudit\Plugin::class, 'markAsPassed');
        $ref->setAccessible(true);
        $ref->invoke(null, 201, 'comment');

        $row = $this->rows('comments')[0];
        $this->assertSame(1, (int)$row['status']);
        $this->assertSame(2, (int)$row['audit_state']);
        $this->assertSame(
            2,
            (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 20')->fetchColumn(),
            'comment 类型放行必须恢复计数（原实现只处理 post）'
        );
    }

    public function testPassedPostStillWorks(): void
    {
        $this->seedPost(30, 0);
        $this->pdo()->exec('UPDATE posts SET status = 0, audit_state = 1 WHERE id = 30');
        $ref = new \ReflectionMethod(\Hublog\Plugin\ContentAudit\Plugin::class, 'markAsPassed');
        $ref->setAccessible(true);
        $ref->invoke(null, 30, 'post');

        $row = $this->rows('posts')[0];
        $this->assertSame(1, (int)$row['status']);
        $this->assertSame(2, (int)$row['audit_state']);
    }

    // ── 3) 评论列表过滤 ──────────────────────────────────────────────────

    public function testCommentListHidesPendingAndHiddenComments(): void
    {
        $this->seedPost(40, 3);
        $this->seedComment(401, 40, '公开评论');
        $this->seedComment(402, 40, '待审评论', 1, 1);
        $this->seedComment(403, 40, '隐藏评论', 0, 0);

        $post = array_merge($this->rows('posts')[0], [
            'user_id' => 1, 'username' => 'author', 'nickname' => 'author', 'avatar' => null,
            'role' => 'user', 'bio' => null,
        ]);
        $formatted = PostFormatter::formatPostSingle($post, 0);

        $this->assertSame(
            [401],
            array_column($formatted['comments'], 'id'),
            '待审/隐藏评论不得出现在详情列表'
        );

        $src = (string)file_get_contents(self::root() . '/api/core/PostFormatter.php');
        $this->assertSame(
            2,
            substr_count($src, 'AND c.status = 1 AND (c.audit_state IS NULL OR c.audit_state <> 1)'),
            '主查询与 fallback 两支都必须带过滤条件'
        );
    }

    // ── 4) 对账口径 ──────────────────────────────────────────────────────

    public function testReconcilerDoesNotResurrectPendingComments(): void
    {
        $this->seedPost(50, 1);
        $this->seedComment(501, 50, '公开评论');
        $this->seedComment(502, 50, '待审评论', 0, 1); // 状态为 0 → 不计入

        $report = CounterReconciler::reconcileAll($this->pdo());
        $this->assertSame(
            1,
            (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 50')->fetchColumn(),
            '对账必须只数 status=1 的评论'
        );
        $this->assertSame(0, $report['total_fixed']);
    }

    public function testReconcilerStillWorksOnLegacySchemaWithoutStatus(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, comment_count INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER)');
        $pdo->exec('INSERT INTO posts VALUES (1, 0)');
        $pdo->exec('INSERT INTO comments VALUES (1, 1)');

        $report = CounterReconciler::reconcileAll($pdo);
        $this->assertSame(1, (int)$pdo->query('SELECT comment_count FROM posts WHERE id = 1')->fetchColumn());
        $skippedTargets = array_column($report['skipped'], 'target');
        $this->assertNotContains(
            'posts.comment_count',
            $skippedTargets,
            '老库无 status 列时必须降级为旧口径，不能把评论对账整体跳过'
        );
    }

    // ── 5) 管理员下架防洗白 ──────────────────────────────────────────────

    public function testAdminHideClearsAuditState(): void
    {
        $this->seedPost(60, 0);
        $this->pdo()->exec('UPDATE posts SET status = 1, audit_state = 2 WHERE id = 60');
        AdminRepository::hidePostsByIds([60], '2026-02-02 00:00:00');

        $row = $this->rows('posts')[0];
        $this->assertSame(0, (int)$row['status']);
        $this->assertSame(
            0,
            (int)$row['audit_state'],
            '下架必须清 audit_state，否则作者编辑会触发重审洗白'
        );

        // 与 ContentAudit::onPostUpdated 的守卫同口径：status=0 && audit_state=0 即不下发重审。
        $this->assertFalse(
            ((int)$row['status'] === 0 && (int)($row['audit_state'] ?? 0) === 0) === false,
            '守卫条件应成立'
        );
    }

    // ── 6) V100 迁移形状 + 契约哨兵 ──────────────────────────────────────

    public function testContractHasExactCommentSentinels(): void
    {
        $ref = new ReflectionClass(MigrationContract::class);
        $critical = $ref->getReflectionConstant('CRITICAL_COLUMNS')->getValue();
        $this->assertContains(['comments', 'status'], $critical);
        $this->assertContains(['comments', 'audit_state'], $critical);

        // 列名维度允许「任意表同名即放行」，模拟只有 posts 有两列时仍必须报缺列。
        $diff = MigrationContract::diff(
            array_fill_keys(MigrationContract::REQUIRED_TABLES, true),
            array_fill_keys(MigrationContract::REQUIRED_COLUMN_NAMES, true),
            ['posts.status' => true, 'posts.audit_state' => true]
        );
        $this->assertContains('comments.status', $diff['columns']);
        $this->assertContains('comments.audit_state', $diff['columns']);
    }

    public function testMigrationV100UsesIdempotentChecksRethrowAndFinalVerify(): void
    {
        $src = (string)file_get_contents(self::root() . '/api/core/MigrationBatchC.php');
        $start = strpos($src, 'private static function migrate100(');
        $this->assertNotFalse($start, 'migrate100 必须存在');
        $body = substr($src, $start);

        $this->assertStringContainsString("COLUMN_NAME = 'status'", $body);
        $this->assertStringContainsString("COLUMN_NAME = 'audit_state'", $body);
        $this->assertStringContainsString("ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1", $body);
        $this->assertStringContainsString("ADD COLUMN `audit_state` TINYINT(1) NOT NULL DEFAULT 0", $body);
        $this->assertStringContainsString('ai_usage_logs', $body);
        $this->assertStringContainsString('idx_aiu_user_feature_status_created', $body);
        $this->assertStringContainsString('V100 自检失败', $body);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($body, 'throw $e;'),
            '每条 DDL 的 catch 都必须重抛，禁止静默吞错'
        );

        $main = (string)file_get_contents(self::root() . '/api/core/Migration.php');
        $this->assertStringContainsString('private static int $currentVersion = 101;', $main);
        $this->assertStringContainsString('migrate66 ~ migrate101', $main);
    }
}
