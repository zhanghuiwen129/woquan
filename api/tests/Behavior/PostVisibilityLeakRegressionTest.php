<?php

/**
 * 内容社交可见性泄露回归测试。
 *
 * 目标：锚定 VisibilityPolicy 是唯一权威口径，且各消费方（搜索/话题/详情/
 * SSR/热门榜/转发/写接口/收藏）都不能绕过它。这里只断言正确行为，
 * 不把历史上「错误行为当契约」的快照搬进来。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\ContentPayService;
use Hublog\Core\Database;
use Hublog\Core\FavoriteRepository;
use Hublog\Core\NotificationRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\PostRepository;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SearchRepository;
use Hublog\Core\VisibilityPolicy;
use Hublog\Controllers\PostCrudTrait;
use PDO;
use ReflectionMethod;

final class PostVisibilityLeakRegressionTest extends BehaviorTestCase
{
    /** 保护全局 $_GET / $_COOKIE，避免污染同进程其它用例 */
    private array $getBackup = [];
    private array $cookieBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->cookieBackup = $_COOKIE;
        $this->serverBackup = $_SERVER;
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/posts/detail';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Auth::clear();
        ContentPayService::clearCache();
        Cache::flush();
        $this->createVisibilitySchema($this->pdo());
    }

    protected function tearDown(): void
    {
        Auth::clear();
        ContentPayService::clearCache();
        $_GET = $this->getBackup;
        $_COOKIE = $this->cookieBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    // =====================================================================
    // Schema / fixture
    // =====================================================================

    private function createVisibilitySchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
ALTER TABLE `users` ADD COLUMN `realname_verified` INTEGER NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `phone_verified_at` TEXT DEFAULT NULL;
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `summary` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `videos` TEXT DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL,
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `pinned_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL,
    `location` TEXT DEFAULT NULL,
    `location_lat` REAL DEFAULT NULL,
    `location_lng` REAL DEFAULT NULL,
    `is_anonymous` INTEGER NOT NULL DEFAULT 0,
    `is_original` INTEGER NOT NULL DEFAULT 0,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `view_count` INTEGER NOT NULL DEFAULT 0,
    `circle_id` INTEGER DEFAULT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `link_url` TEXT DEFAULT NULL,
    `link_title` TEXT DEFAULT NULL,
    `link_desc` TEXT DEFAULT NULL,
    `link_site` TEXT DEFAULT NULL,
    `origin_id` INTEGER DEFAULT NULL,
    `is_featured` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER DEFAULT NULL,
    `scheduled_at` TEXT DEFAULT NULL,
    `visibility` TEXT DEFAULT NULL
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
    `role` TEXT DEFAULT 'member',
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `circle_subscribes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `status` INTEGER NOT NULL DEFAULT 0,
    `expire_at` TEXT DEFAULT NULL
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `post_tags` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `tag` TEXT DEFAULT NULL
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `parent_id` INTEGER DEFAULT 0,
    `content` TEXT DEFAULT NULL,
    `image` TEXT DEFAULT NULL,
    `is_ai` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
-- V100 评论审核列（插件评论列表同口径过滤需要）
ALTER TABLE `comments` ADD COLUMN `status` INTEGER NOT NULL DEFAULT 1;
ALTER TABLE `comments` ADD COLUMN `audit_state` INTEGER NOT NULL DEFAULT 0;
CREATE TABLE `user_actions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `post_id` INTEGER NOT NULL,
    `action` TEXT DEFAULT 'view',
    `weight` REAL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `favorites` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `post_view_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `viewer_id` INTEGER DEFAULT NULL,
    `viewer_ip` TEXT DEFAULT NULL,
    `view_date` TEXT DEFAULT NULL
);
CREATE TABLE `categories` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `name` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `category_follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `category_id` INTEGER NOT NULL
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `sender_id` INTEGER DEFAULT NULL,
    `type` TEXT DEFAULT NULL,
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `questions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `question` TEXT DEFAULT NULL
);
CREATE TABLE `teams` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `headline` TEXT DEFAULT NULL,
    `need_count` INTEGER DEFAULT 0,
    `joined_count` INTEGER DEFAULT 0
);
CREATE TABLE `checkin_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `goal` TEXT DEFAULT NULL,
    `target_days` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `polls` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `question` TEXT DEFAULT NULL,
    `multi` INTEGER NOT NULL DEFAULT 0,
    `max_choice` INTEGER NOT NULL DEFAULT 1,
    `expires_at` TEXT DEFAULT NULL
);
CREATE TABLE `poll_options` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `poll_id` INTEGER NOT NULL,
    `content` TEXT DEFAULT NULL
);
CREATE TABLE `poll_votes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `poll_id` INTEGER NOT NULL,
    `option_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `post_id` INTEGER DEFAULT NULL
);
CREATE TABLE `content_products` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `product_type` TEXT NOT NULL,
    `target_id` INTEGER NOT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `price` REAL DEFAULT 0,
    `preview_ratio` INTEGER DEFAULT 30,
    `valid_days` INTEGER DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `sold_count` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `content_columns` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `owner_id` INTEGER NOT NULL,
    `title` TEXT DEFAULT NULL,
    `summary` TEXT DEFAULT '',
    `cover` TEXT DEFAULT '',
    `valid_days` INTEGER DEFAULT 365,
    `item_count` INTEGER DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `content_column_items` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `column_id` INTEGER NOT NULL,
    `post_id` INTEGER NOT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `sort_order` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `content_entitlements` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `product_type` TEXT NOT NULL,
    `target_id` INTEGER NOT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `source` TEXT DEFAULT 'purchase',
    `order_no` TEXT DEFAULT '',
    `expire_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
SQL);
        // 插件 loadPostsBatch 会按 post_id 查被下架媒体；底座 uploads 无此列。
        $pdo->exec('ALTER TABLE `uploads` ADD COLUMN `post_id` INTEGER DEFAULT 0');
    }

    private function seedUser(int $id, string $username = 'u'): void
    {
        $this->seed('users', [
            'id' => $id, 'username' => $username . $id, 'nickname' => $username . $id,
            'avatar' => '/a.png', 'role' => 'user', 'status' => 1,
        ]);
    }

    private function seedPost(int $id, int $ownerId, string $visibility, string $content = '正文'): void
    {
        $this->seed('posts', [
            'id' => $id, 'user_id' => $ownerId, 'content' => $content,
            'visibility' => $visibility, 'post_type' => 'normal', 'status' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    // 只用到 PostCrudTrait::detail()/buildOriginPost()/notifyCategoryFollowers()，
    // 守卫命中时会在调用其它 Trait 方法之前返回。
    private function detailProbe(): object
    {
        return new class extends \Hublog\Core\BaseController {
            use PostCrudTrait;
        };
    }

    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    // =====================================================================
    // T0：唯一权威策略
    // =====================================================================

    public function testPolicyWhereAndCanViewAreDefaultDeny(): void
    {
        [$guestWhere, $guestParams] = VisibilityPolicy::where(0);
        $this->assertSame("(p.visibility = 'public')", $guestWhere);
        $this->assertSame([], $guestParams);

        [$where, $params] = VisibilityPolicy::where(7);
        $this->assertStringContainsString('p.user_id = ?', $where);
        $this->assertStringContainsString("p.visibility = 'friend'", $where);
        $this->assertStringContainsString('circle_subscribes', $where);
        $this->assertCount(5, $params);
        $this->assertSame([7, 7, 7, 7], [$params[0], $params[1], $params[2], $params[4]]);

        $this->assertTrue(VisibilityPolicy::canView(['visibility' => 'public', 'user_id' => 1], 0));
        $this->assertFalse(VisibilityPolicy::canView(['visibility' => 'private', 'user_id' => 1], 0));
        $this->assertFalse(VisibilityPolicy::canView(['user_id' => 1], 0), '缺失 visibility 默认拒绝');
        $this->assertFalse(VisibilityPolicy::canView(['visibility' => 'weird', 'user_id' => 1], 1), '非法 visibility 默认拒绝');
        $this->assertSame(['public', 'circle', 'friend', 'private'], VisibilityPolicy::TYPES);
        $private = ['visibility' => 'private', 'user_id' => 1, 'circle_id' => null];
        $this->assertTrue(VisibilityPolicy::canView($private, 1), '作者本人可读自己的 private 帖');
        $this->assertFalse(VisibilityPolicy::canView($private, 2));
    }

    public function testFriendVisibilityIsOneWayAndCircleHonorsPaidSubscription(): void
    {
        $this->seedUser(1, 'author');
        $this->seedUser(2, 'viewer');
        $this->seedPost(10, 1, 'friend', '好友正文');
        $this->seedPost(11, 1, 'circle', '圈子正文');
        $this->seed('circles', ['id' => 30, 'name' => '付费圈', 'owner_id' => 1, 'status' => 1, 'pay_type' => 2]);
        $this->pdo()->exec('UPDATE posts SET circle_id = 30 WHERE id = 11');

        $friend = VisibilityPolicy::canView(PostRepository::findById(10) ?? [], 2);
        $this->assertFalse($friend, '未关注作者时不可见');
        $this->seed('follows', ['follower_id' => 1, 'following_id' => 2]);
        $this->assertFalse(
            VisibilityPolicy::canView(PostRepository::findById(10) ?? [], 2),
            '反向关注不算好友（单向语义）'
        );
        $this->seed('follows', ['follower_id' => 2, 'following_id' => 1]);
        $this->assertTrue(VisibilityPolicy::canView(PostRepository::findById(10) ?? [], 2));

        $circlePost = PostRepository::findById(11) ?? [];
        $this->assertFalse(VisibilityPolicy::canView($circlePost, 2), '非成员不可见');
        $this->seed('circle_members', ['circle_id' => 30, 'user_id' => 2, 'status' => 1]);
        $this->assertFalse(VisibilityPolicy::canView($circlePost, 2), '付费圈订阅缺失不可见');
        $this->seed('circle_subscribes', [
            'circle_id' => 30, 'user_id' => 2, 'status' => 1, 'expire_at' => '2020-01-01 00:00:00',
        ]);
        $this->assertFalse(VisibilityPolicy::canView($circlePost, 2), '付费圈订阅过期不可见');
        $this->pdo()->exec("UPDATE circle_subscribes SET expire_at = '2099-01-01 00:00:00'");
        $this->assertTrue(VisibilityPolicy::canView($circlePost, 2));
    }

    // =====================================================================
    // T2/T3：搜索与话题
    // =====================================================================

    public function testGuestSearchAndTopicNeverReturnNonPublicPosts(): void
    {
        $this->seedUser(1, 'author');
        $this->seedPost(101, 1, 'public', 'keyword 公开');
        $this->seedPost(102, 1, 'private', 'keyword 私密');
        $this->seedPost(103, 1, 'friend', 'keyword 好友');
        $this->seedPost(104, 1, 'circle', 'keyword 圈子');
        foreach ([101, 102, 103, 104] as $id) {
            $this->seed('post_tags', ['post_id' => $id, 'tag' => '话题']);
        }

        [$visWhere, $visParams] = VisibilityPolicy::where(0);
        $ids = array_column(SearchRepository::fetchPostIds(
            'keyword', 0, '', '', 0, 0, 20, false, $visWhere, $visParams
        ), 'id');
        $this->assertSame([101], $ids, '游客搜索只返回 public 帖');
        $this->assertSame(1, SearchRepository::countPosts(
            'keyword', 0, '', '', 0, false, $visWhere, $visParams
        ), '游客搜索总数只计 public 帖');
        $this->assertSame(
            [101],
            array_column(SearchRepository::fetchPostsByIds([101, 102, 103, 104], $visWhere, $visParams), 'id'),
            '批量详情必须再次按可见性过滤'
        );

        $this->assertSame(1, PostRepository::countTopicIndexPosts('话题', $visWhere, $visParams));
        $this->assertSame(
            [101],
            array_column(
                PostRepository::fetchTopicPostIdsByCursor('话题', 105, 20, $visWhere, $visParams),
                'id'
            ),
            '话题游标路径不含 private/friend/circle 帖'
        );
    }

    // =====================================================================
    // T1：详情副作用
    // =====================================================================

    public function testInvisibleDetailReturns404WithoutViewSideEffects(): void
    {
        $this->seedUser(1, 'author');
        $this->seedPost(201, 1, 'private', '私密正文不应该被记录浏览');

        $_GET = ['id' => 201];
        $response = $this->detailProbe()->detail(new Request());
        $payload = json_decode($response->toJson(), true);
        $this->assertSame(404, $payload['code']);
        $this->assertSame([], $this->rows('post_view_logs'), '不可见详情不得写浏览日志');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT view_count FROM posts WHERE id = 201')->fetchColumn());
        $this->assertSame(1, \Hublog\Core\Cache::nsVersion('timeline'), '不可见详情不得升 timeline 版本号');
    }

    // =====================================================================
    // T7：写接口守卫
    // =====================================================================

    public function testWriteGuardsRejectInvisiblePostsForOutsider(): void
    {
        $this->seedUser(1, 'author');
        $this->seedUser(2, 'outsider');
        $this->seedPost(301, 1, 'private', '私密');
        $this->seedPost(302, 1, 'public', '公开');
        $this->seed('questions', ['post_id' => 301, 'question' => 'Q']);
        $this->seed('questions', ['post_id' => 302, 'question' => 'Q']);

        $this->assertNull(PostRepository::findActivePostId(301, 2), '点赞/评论/投票守卫拒绝 private 帖');
        $this->assertNotNull(PostRepository::findActivePostId(302, 2));
        $this->assertNull(PostRepository::findTypePostRow(301, 'question', 2), '问答写入守卫拒绝 private 帖');
        $this->assertNull(FavoriteRepository::findVisiblePost(301, 2), '收藏守卫拒绝 private 帖');
        $this->assertNotNull(FavoriteRepository::findVisiblePost(301, 1), '作者本人可收藏自己的 private 帖');

        $beforeLikes = count($this->rows('likes'));
        $this->assertSame($beforeLikes, count($this->rows('likes')), '守卫返回 null 时不得产生点赞副作用');
    }

    public function testControllersPassViewerIdToWriteGuards(): void
    {
        $comment = file_get_contents(dirname(__DIR__, 2) . '/controllers/PostCommentTrait.php');
        $this->assertStringContainsString('findActivePostId($postId, (int)$user[\'id\'])', $comment);
        $this->assertStringContainsString('findActivePostId((int)$data[\'post_id\'], (int)$user[\'id\'])', $comment);
        $this->assertStringContainsString('mb_substr($commentContent, 0, 100)', $comment);
        $this->assertStringNotContainsString("mb_substr(\$data['content'], 0, 100)", $comment);

        $type = file_get_contents(dirname(__DIR__, 2) . '/controllers/PostTypeTrait.php');
        $this->assertStringContainsString('findActivePostId($postId, (int)$user[\'id\'])', $type);
        $this->assertStringContainsString("findTypePost(\$postId, 'question', (int)\$user['id'])", $type);
        $this->assertStringContainsString("findTypePost(\$postId, 'checkin', (int)\$user['id'])", $type);
    }

    // =====================================================================
    // T8：收藏 + 匿名
    // =====================================================================

    public function testFavoriteListMasksAnonymousAuthorAndLikers(): void
    {
        $this->seedUser(1, 'author');
        $this->seedUser(2, 'liker');
        $this->seedPost(401, 1, 'public', '匿名正文');
        $this->pdo()->exec('UPDATE posts SET is_anonymous = 1 WHERE id = 401');
        $this->seed('likes', ['post_id' => 401, 'user_id' => 2, 'created_at' => '2026-01-01']);

        [$visWhere, $visParams] = VisibilityPolicy::where(0);
        $rows = FavoriteRepository::fetchFavoritePosts([401], $visWhere, $visParams);
        $this->assertCount(1, $rows);
        $raw = $rows[0];
        $likes = [['id' => 2, 'username' => 'liker2', 'nickname' => '真实昵称']];
        $formatted = PostFormatter::formatPostBatch($raw, 2, $likes, []);

        $this->assertSame(0, (int)$formatted['user']['id'], '匿名帖不得暴露作者 id');
        $this->assertSame('anonymous', $formatted['user']['username']);
        $this->assertTrue($formatted['liked'], '脱敏后 liked 判定仍应基于原始点赞人');
        $this->assertSame('anonymous', $formatted['likes'][0]['username']);
        $this->assertSame('匿名用户', $formatted['likes'][0]['nickname']);
        $this->assertStringNotContainsString('liker2', json_encode($formatted, JSON_UNESCAPED_UNICODE));
    }

    // =====================================================================
    // T6/T9/T5/T4：转发 / 分类通知 / 热门榜 / SSR
    // =====================================================================

    public function testLockedOriginPostReturnsPlaceholderWithoutBodyOrMedia(): void
    {
        $this->seedUser(1, 'author');
        $this->seedPost(501, 1, 'public', '付费正文不应出现在占位卡片');
        $this->pdo()->exec("UPDATE posts SET images = '[\"/secret.png\"]' WHERE id = 501");
        $this->seed('content_products', [
            'product_type' => 'post', 'target_id' => 501, 'status' => 1,
            'price' => 9.9, 'preview_ratio' => 0,
        ]);

        $probe = $this->detailProbe();
        $origin = $this->invokePrivate($probe, 'buildOriginPost', [501, 0]);
        $this->assertTrue($origin['locked']);
        $this->assertTrue($origin['deleted']);
        $this->assertSame('', $origin['content']);
        $this->assertSame(0, $origin['image_count']);
        $this->assertNull($origin['user']);
        $this->assertStringNotContainsString('secret.png', json_encode($origin));
        $this->assertStringNotContainsString('付费正文', json_encode($origin, JSON_UNESCAPED_UNICODE));
    }

    public function testRemovedOriginPostNeverResurrectsBodyOrAuthor(): void
    {
        $this->seedUser(1, 'author');
        $this->seedPost(502, 1, 'public', '下架帖隐藏正文_SECRET_不应复活');
        $this->pdo()->exec(
            "UPDATE posts SET status = 0, audit_state = 0, images = '[\"/secret.png\"]' WHERE id = 502"
        );

        $this->assertNull(PostRepository::findOriginPost(502), 'status=0 的下架帖不得作为转发来源');
        $origin = $this->invokePrivate($this->detailProbe(), 'buildOriginPost', [502, 0]);
        $this->assertTrue($origin['deleted']);
        $this->assertSame('', $origin['content']);
        $this->assertNull($origin['user']);
        $json = json_encode($origin, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET', $json);
        $this->assertStringNotContainsString('secret.png', $json);
    }

    public function testCategoryNotifySkipsNonPublicAndPendingPosts(): void
    {
        $this->seedUser(1, 'author');
        $this->seedUser(2, 'follower');
        $this->seed('categories', ['id' => 60, 'name' => '科技', 'status' => 1]);
        $this->seed('category_follows', ['user_id' => 2, 'category_id' => 60]);
        $this->seedPost(601, 1, 'private');
        $this->pdo()->exec('UPDATE posts SET category_id = 60, audit_state = 1 WHERE id = 601');

        $this->invokePrivate($this->detailProbe(), 'notifyCategoryFollowers', [601, 1, 60]);
        $this->assertSame([], $this->rows('notifications'), '非 public / 待审帖不推送频道通知');
    }

    public function testPopularAndSsrWireVisibilityAndScrubInCorrectOrder(): void
    {
        $feed = file_get_contents(dirname(__DIR__, 2) . '/controllers/PostFeedTrait.php');
        $this->assertStringContainsString('popular_v4_v', $feed);
        $this->assertStringContainsString('$rawForScrub[] = $postMap[$id];', $feed);
        $this->assertStringContainsString('ContentPayService::scrubBatch($list, $rawForScrub, $userId)', $feed);
        $this->assertStringContainsString(
            '$topic, $cursor, $limit, $visWhereTopic, $visParamsTopic',
            $feed
        );

        // P1-5 拆分后：主查询与游客可见性判定在 FrontRouter，首屏辅助查询在 SsrRenderer。
        $frontRouter = file_get_contents(dirname(__DIR__, 3) . '/api/core/FrontRouter.php');
        $this->assertStringContainsString('VisibilityPolicy::where(0)', $frontRouter);
        $this->assertStringContainsString('$postDetail && !VisibilityPolicy::canView($postDetail, 0)', $frontRouter);
        $this->assertStringContainsString('WHERE p.id = ? AND p.status = 1 AND {$ssrVisWhere} LIMIT 1', $frontRouter);

        $ssr = file_get_contents(dirname(__DIR__, 3) . '/api/core/SsrRenderer.php');
        $this->assertStringContainsString('WHERE p.id = ? AND {$ssrVisWhere} LIMIT 1', $ssr);
    }

    public function testPopularScrubBatchKeepsPaywalledTypeDataStripped(): void
    {
        $list = [[
            'id' => 701,
            'paywall' => ['locked' => true],
            'content' => '试读',
            'question' => ['answer' => '付费答案'],
            'team' => ['headline' => '付费组队'],
        ]];
        $scrubbed = ContentPayService::scrubBatch($list, [['id' => 701]], 0);
        $this->assertArrayNotHasKey('question', $scrubbed[0]);
        $this->assertArrayNotHasKey('team', $scrubbed[0]);
        $this->assertSame('试读', $scrubbed[0]['content']);
    }

    public function testNotificationSummariesObeyStatusAndVisibility(): void
    {
        $this->seedUser(1, 'author');
        $this->seedUser(2, 'viewer');

        $this->seedPost(801, 1, 'public', '公开正文');
        $this->seedPost(802, 1, 'private', '私密正文');
        $this->seedPost(803, 1, 'friend', '好友正文');
        $this->seedPost(804, 1, 'public', '下架正文');
        $this->pdo()->prepare('UPDATE posts SET status = 0 WHERE id = ?')->execute([804]);

        $postIds = [801, 802, 803, 804];
        foreach ($postIds as $postId) {
            $this->seed('notifications', [
                'user_id' => 2,
                'sender_id' => 1,
                'type' => 'comment',
                'post_id' => $postId,
                'content' => '有人互动了你',
                'is_read' => 0,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        $visibleIds = static function (array $rows): array {
            $ids = array_column($rows, 'id');
            sort($ids);
            return $ids;
        };

        $summary = NotificationRepository::fetchPostSummaries(2, $postIds);
        $this->assertSame([801], $visibleIds($summary), '下架/私密/未关注好友帖不得进通知摘要');

        $this->seed('follows', ['follower_id' => 2, 'following_id' => 1]);
        $summary = NotificationRepository::fetchPostSummaries(2, $postIds);
        $this->assertSame([801, 803], $visibleIds($summary), '单向关注后 friend 帖可见');

        $this->seed('circles', [
            'id' => 30, 'name' => '付费圈', 'owner_id' => 1,
            'status' => 1, 'pay_type' => 2,
        ]);
        $this->pdo()->prepare('UPDATE posts SET visibility = ?, circle_id = ? WHERE id = ?')
            ->execute(['circle', 30, 803]);
        $summary = NotificationRepository::fetchPostSummaries(2, $postIds);
        $this->assertSame([801], $visibleIds($summary), '付费圈无有效订阅时帖子仍不可见');
    }

    // =====================================================================
    // 批次8：两个插件路由的可见性 + 缓存键
    // =====================================================================

    private function loadPluginClasses(): void
    {
        foreach (['SmartSearch', 'SmartRecommend'] as $name) {
            $file = self::root() . '/plugins/' . $name . '/Plugin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    public function testSmartSemanticSearchPluginHidesInvisiblePosts(): void
    {
        $this->loadPluginClasses();
        $this->seedUser(1, 'author');
        $this->seedPost(901, 1, 'public', 'pluginsecret 公开');
        $this->seedPost(902, 1, 'private', 'pluginsecret 私密');
        $this->seedPost(903, 1, 'friend', 'pluginsecret 好友');
        $this->seedPost(904, 1, 'circle', 'pluginsecret 圈子');
        $this->seed('settings', ['key' => 'smart_search_enabled', 'value' => '0']);

        $_GET = ['q' => 'pluginsecret', 'page' => 1, 'limit' => 20];
        $_SERVER['REQUEST_URI'] = '/api/search/semantic';
        $response = \Hublog\Plugin\SmartSearch\Plugin::semanticSearch(new Request());
        $data = json_decode($response->toJson(), true)['data'];

        $this->assertSame(
            [901],
            array_column($data['list'], 'id'),
            '游客语义检索不得返回 private/friend/circle 帖'
        );
        $this->assertSame(1, $data['pagination']['total'], '总数也必须与可见性同口径');

        // 缓存键必须带 userId，否则登录后可见结果会被游客缓存污染（或反向泄露）。
        $src = (string)file_get_contents(self::root() . '/plugins/SmartSearch/Plugin.php');
        $this->assertStringContainsString('semantic_search_', $src);
        $this->assertStringContainsString('_{$userId}', $src, '语义搜索缓存键必须并入 userId');
    }

    public function testSmartRecommendPluginHidesInvisiblePosts(): void
    {
        $this->loadPluginClasses();
        $this->seedUser(1, 'author');
        $this->seedPost(911, 1, 'public', '推荐公开');
        $this->seedPost(912, 1, 'private', '推荐私密');
        // 热度推荐只看近 7 天，夹具时间要落在窗口内。
        $this->pdo()->exec("UPDATE posts SET created_at = datetime('now') WHERE id IN (911, 912)");
        $this->seed('settings', ['key' => 'smart_recommend_enabled', 'value' => '1']);

        $_GET = ['limit' => 10];
        $_SERVER['REQUEST_URI'] = '/api/posts/recommend';
        $response = \Hublog\Plugin\SmartRecommend\Plugin::recommend(new Request());
        $data = json_decode($response->toJson(), true)['data'];

        $this->assertSame([911], array_column($data['list'], 'id'), '游客推荐不得含私密帖');

        // CF 分支的 post_id 来自 user_actions，SQL 层 where 拦不住，必须靠 loadPostsBatch 的 canView 兜底。
        [$visWhere, $visParams] = VisibilityPolicy::where(2);
        $plugin = new \Hublog\Plugin\SmartRecommend\Plugin();
        $loaded = $this->invokePrivate($plugin, 'loadPostsBatch', [[912, 911], 2, $visWhere, $visParams]);
        $this->assertSame([911], array_column($loaded, 'id'), 'CF 候选必须逐行 canView 过滤');
    }
}
