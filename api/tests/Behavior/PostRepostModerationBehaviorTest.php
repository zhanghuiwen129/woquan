<?php
/**
 * 批次8 T14：转发（repost）同步敏感词过滤的行为回归。
 *
 * 根因：repost 直接 insertPost，绕开 create() 的 Moderation::filter；
 * block 级只能靠 post_created 钩子的 ContentAudit 兜底（异步且可吞错），
 * review 级则会直接以 status=1 公开。
 *
 * 本测试不 mock 静态方法：直接用 sensitive_words 词库驱动真实的 Moderation::filter，
 * 覆盖 block 硬拒（403，无新帖）与 review 待审（status=0 / audit_state=1）两条路径。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\ContentPayService;
use Hublog\Core\Moderation;
use Hublog\Core\Request;
use Hublog\Controllers\PostController;
use PDO;

final class PostRepostModerationBehaviorTest extends BehaviorTestCase
{
    private array $getBackup = [];
    private array $postBackup = [];
    private array $serverBackup = [];
    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;
        $this->cookieBackup = $_COOKIE;
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/posts/repost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Auth::clear();
        ContentPayService::clearCache();
        Cache::flush();

        $pdo = $this->pdo();
        // Moderation::words() 用 CHAR_LENGTH 排序（MySQL 语法），SQLite 需注册同名函数。
        $pdo->sqliteCreateFunction('CHAR_LENGTH', static fn(string $s): int => strlen($s), 1);
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `realname_verified` INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `realname_verified_at` TEXT DEFAULT NULL');
        $pdo->exec('ALTER TABLE `uploads` ADD COLUMN `post_id` INTEGER DEFAULT 0');
        $pdo->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `title` TEXT DEFAULT NULL,
    `summary` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `videos` TEXT DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER NOT NULL DEFAULT 0,
    `scheduled_at` TEXT DEFAULT NULL,
    `origin_id` INTEGER DEFAULT NULL,
    `visibility` TEXT DEFAULT 'public',
    `circle_id` INTEGER DEFAULT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `pinned_at` TEXT DEFAULT NULL,
    `is_anonymous` INTEGER NOT NULL DEFAULT 0,
    `is_original` INTEGER NOT NULL DEFAULT 0,
    `is_featured` INTEGER NOT NULL DEFAULT 0,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `view_count` INTEGER NOT NULL DEFAULT 0,
    `location` TEXT DEFAULT NULL,
    `location_lat` REAL DEFAULT NULL,
    `location_lng` REAL DEFAULT NULL,
    `link_url` TEXT DEFAULT NULL,
    `link_title` TEXT DEFAULT NULL,
    `link_desc` TEXT DEFAULT NULL,
    `link_site` TEXT DEFAULT NULL,
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
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `owner_id` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1, `pay_type` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL, `status` INTEGER NOT NULL DEFAULT 1, `role` TEXT DEFAULT 'member'
);
CREATE TABLE `sensitive_words` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `word` TEXT NOT NULL,
    `level` TEXT NOT NULL DEFAULT 'replace',
    `replacement` TEXT DEFAULT '***',
    `scene` TEXT DEFAULT '',
    `status` INTEGER NOT NULL DEFAULT 1,
    `hit_count` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `content_filter_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL DEFAULT 0,
    `scene` TEXT DEFAULT '',
    `target_id` INTEGER NOT NULL DEFAULT 0,
    `action` TEXT DEFAULT '',
    `words` TEXT DEFAULT '',
    `excerpt` TEXT DEFAULT '',
    `created_at` TEXT DEFAULT NULL
);
SQL);

        // 登录用户（转发者）与原帖作者：原动态对所有人可见。
        foreach ([1 => 'reposter', 2 => 'author'] as $id => $name) {
            $this->seed('users', [
                'id' => $id, 'username' => $name, 'nickname' => $name, 'avatar' => null,
                'role' => 'user', 'status' => 1, 'token_version' => 1,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->seed('posts', [
            'id' => 100, 'user_id' => 2, 'content' => '可转发原帖', 'status' => 1,
            'audit_state' => 0, 'visibility' => 'public', 'images' => null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken([
            'id' => 1, 'username' => 'reposter', 'role' => 'user', 'token_version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Auth::clear();
        ContentPayService::clearCache();
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        $_COOKIE = $this->cookieBackup;
        parent::tearDown();
    }

    private function seedWord(string $word, string $level): void
    {
        $this->seed('sensitive_words', [
            'word' => $word, 'level' => $level, 'replacement' => '***',
            'scene' => 'post', 'status' => 1, 'hit_count' => 0,
        ]);
        Moderation::clearWordsCache();
    }

    private function repost(string $content): array
    {
        $_GET = ['post_id' => 100, 'content' => $content];
        $response = (new PostController())->repost(new Request());
        return [
            'code' => $response->getCode(),
            'body' => json_decode($response->toJson(), true),
        ];
    }

    public function testBlockWordRejectsRepostBeforeInsert(): void
    {
        $this->seedWord('BLOCKME', 'block');
        $before = count($this->rows('posts'));

        $result = $this->repost('转发语 BLOCKME 不应落库');

        $this->assertSame(403, $result['code'], 'block 级敏感词必须同步硬拒');
        $this->assertStringContainsString('违规', (string)($result['body']['message'] ?? ''));
        $this->assertCount($before, $this->rows('posts'), '被 block 的转发不得落任何新帖');
        $this->assertSame(
            0,
            (int)$this->pdo()->query('SELECT COUNT(*) FROM posts WHERE origin_id = 100')->fetchColumn(),
            '被 block 的转发不得产生任何新帖（含 status=1 的公开帖）'
        );
    }

    public function testReviewWordCreatesPendingRepost(): void
    {
        $this->seedWord('REVIEWME', 'review');
        $before = count($this->rows('posts'));

        $result = $this->repost('转发语 REVIEWME 待审');

        $this->assertSame(200, $result['code']);
        $this->assertCount($before + 1, $this->rows('posts'));
        $newPost = $this->pdo()->query(
            'SELECT status, audit_state, origin_id, content FROM posts WHERE id > 100 ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(100, (int)$newPost['origin_id']);
        $this->assertSame(
            0,
            (int)$newPost['status'],
            'review 级转发必须待审，不能直接 status=1 公开'
        );
        $this->assertSame(1, (int)$newPost['audit_state'], 'review 级必须打上 audit_state=1');
    }

    public function testCleanRepostStaysPublic(): void
    {
        $this->seedWord('BLOCKME', 'block');
        $result = $this->repost('一条干净的转发语');

        $this->assertSame(200, $result['code']);
        $newPost = $this->pdo()->query(
            'SELECT status, audit_state FROM posts WHERE id > 100 ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$newPost['status']);
        $this->assertSame(0, (int)$newPost['audit_state']);
    }
}
