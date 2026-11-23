<?php
/**
 * 公开精选位可见性 / 后台推荐准入的行为回归测试。
 *
 * 缺陷原貌：前台精选只过滤 status=1，private / circle / friend 帖都会出现在公开精选位；
 * 后台 featuredToggle 也允许把任意非公开帖推上精选。
 *
 * 本批只修可见性核心（VisibilityPolicy::where(0) + visibility='public' 准入），
 * 圈子软删/状态过滤另列 P2，本测试不覆盖该口径。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AdminController;
use Hublog\Core\PointRepository;
use Hublog\Core\Request;

/** 仅绕过 requireAdmin() 的身份校验，其余推荐开关逻辑与线上控制器一致。 */
final class StubFeaturedAdminController extends AdminController
{
    protected function requireAdmin(Request $request): array
    {
        return ['id' => 9, 'role' => 'admin', 'username' => 'ops'];
    }
}

final class FeaturedVisibilityBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;

        // SQLite 无内建 NOW()，注册与 MySQL 同语义的函数供时间窗 SQL 使用
        $this->pdo()->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        // users 由 BehaviorTestCase 底座提供（含 id/username/nickname/avatar）
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `featured_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER, `user_id` INTEGER DEFAULT 0,
    `slot` TEXT DEFAULT 'home', `sort_order` INTEGER DEFAULT 0, `remark` TEXT,
    `start_at` TEXT DEFAULT NULL, `end_at` TEXT DEFAULT NULL
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER, `circle_id` INTEGER,
    `post_type` TEXT DEFAULT 'normal', `visibility` TEXT DEFAULT 'public',
    `status` INTEGER DEFAULT 1, `content` TEXT, `images` TEXT,
    `like_count` INTEGER DEFAULT 0, `comment_count` INTEGER DEFAULT 0, `created_at` TEXT
);
SQL);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    private function seedPost(int $id, string $visibility, int $status = 1): void
    {
        $this->seed('posts', [
            'id' => $id, 'user_id' => 10 + $id, 'circle_id' => null,
            'post_type' => 'normal', 'visibility' => $visibility, 'status' => $status,
            'content' => "content{$id}", 'images' => '', 'like_count' => $id,
            'comment_count' => $id, 'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function feature(int $id, int $postId, string $slot = 'home'): void
    {
        $this->seed('featured_posts', [
            'id' => $id, 'post_id' => $postId, 'user_id' => 9, 'slot' => $slot,
            'sort_order' => $id, 'remark' => null, 'start_at' => null, 'end_at' => null,
        ]);
    }

    /** @return array<string,mixed> */
    private function toggle(int $postId, string $slot = 'home'): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = ['post_id' => $postId, 'slot' => $slot];
        $response = (new StubFeaturedAdminController())->featuredToggle(new Request());
        $decoded = json_decode($response->toJson(), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function testGuestFeaturedExcludesPrivateCircleAndFriend(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->seed('users', [
                'id' => 10 + $id, 'username' => "u{$id}", 'nickname' => "n{$id}", 'avatar' => '',
            ]);
        }
        foreach (['private', 'circle', 'friend', 'public'] as $index => $visibility) {
            $id = $index + 1;
            $this->seedPost($id, $visibility);
            $this->feature($id, $id);
        }

        $rows = PointRepository::fetchFeaturedPosts('home', 10);
        $this->assertSame([4], array_column($rows, 'id'), '公开精选只能返回 public 帖');
    }

    public function testGuestFeaturedStillExcludesStatusZero(): void
    {
        $this->seed('users', ['id' => 11, 'username' => 'u1', 'nickname' => 'n1', 'avatar' => '']);
        $this->seedPost(1, 'public', 0);
        $this->feature(1, 1);

        $this->assertSame([], PointRepository::fetchFeaturedPosts('home', 10), 'status=0 的帖不得进公开精选');

        $this->pdo()->exec('UPDATE posts SET status = 1 WHERE id = 1');
        $this->assertSame([1], array_column(PointRepository::fetchFeaturedPosts('home', 10), 'id'));
    }

    public function testFeatureToggleRejectsNonPublicPost(): void
    {
        foreach (['private', 'circle', 'friend'] as $index => $visibility) {
            $this->seedPost($index + 1, $visibility);
        }

        foreach ([1, 2, 3] as $id) {
            $result = $this->toggle($id);
            $this->assertSame(400, $result['code'], (string)$result['message']);
        }
        $this->assertSame([], $this->rows('featured_posts'), '非公开帖一律不得写入精选');
    }

    public function testFeatureToggleCanCancelStaleFeaturedRow(): void
    {
        $this->seedPost(7, 'private');
        $this->feature(1, 7);

        $result = $this->toggle(7);
        $this->assertSame(200, $result['code'], (string)$result['message']);
        $this->assertFalse($result['data']['featured'], '取消推荐分支必须先于准入校验');
        $this->assertSame([], $this->rows('featured_posts'), '存量违规记录必须能被清掉');
    }
}
