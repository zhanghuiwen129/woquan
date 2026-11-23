<?php
/**
 * UserRepository（UserController / AuthController 内联 SQL 收敛）迁移回归测试
 *
 * 核心断言方式：在内存 SQLite 里同时执行「迁移前的原始内联 SQL」与
 * 「迁移后的 Repository 方法」，逐字段比对返回结构，确保下沉数据访问
 * 不改字段清单/顺序、排序、分页、可见性、软删条件与 COUNT 口径。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\UserRepository;
use PDO;

final class UserAuthRepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();

        // 基础 BehaviorTestCase 的 users/uploads 列不足以覆盖真实查询，按真实列重建
        $pdo->exec(<<<'SQL'
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`                 INTEGER PRIMARY KEY AUTOINCREMENT,
    `username`           TEXT    DEFAULT NULL,
    `nickname`           TEXT    DEFAULT NULL,
    `avatar`             TEXT    DEFAULT NULL,
    `cover`              TEXT    DEFAULT NULL,
    `email`              TEXT    DEFAULT NULL,
    `bio`                TEXT    DEFAULT NULL,
    `role`               TEXT    DEFAULT 'user',
    `status`             INTEGER NOT NULL DEFAULT 1,
    `token_version`      INTEGER NOT NULL DEFAULT 1,
    `created_at`         TEXT    DEFAULT NULL,
    `updated_at`         TEXT    DEFAULT NULL,
    `last_login`         TEXT    DEFAULT NULL,
    `last_active_at`     TEXT    DEFAULT NULL,
    `password`           TEXT    DEFAULT NULL,
    `vip_level`          INTEGER NOT NULL DEFAULT 0,
    `vip_expire_at`      TEXT    DEFAULT NULL,
    `avatar_frame`       TEXT    DEFAULT NULL,
    `phone`              TEXT    DEFAULT NULL,
    `phone_verified_at`  TEXT    DEFAULT NULL,
    `email_verified`     INTEGER NOT NULL DEFAULT 0,
    `deleted_at`         TEXT    DEFAULT NULL,
    `realname_verified`  INTEGER NOT NULL DEFAULT 0,
    `realname_verified_at` TEXT DEFAULT NULL,
    `privacy_home`       TEXT    DEFAULT 'public',
    `allow_search`       INTEGER NOT NULL DEFAULT 1,
    `allow_mention`      INTEGER NOT NULL DEFAULT 1,
    `allow_stranger_msg` INTEGER NOT NULL DEFAULT 1,
    `hide_online`        INTEGER NOT NULL DEFAULT 0,
    `font_scale`         TEXT    DEFAULT 'normal',
    `msg_sound`          INTEGER NOT NULL DEFAULT 1,
    `gender`             INTEGER NOT NULL DEFAULT 0,
    `birthday`           TEXT    DEFAULT NULL,
    `region`             TEXT    DEFAULT NULL,
    `user_tags`          TEXT    DEFAULT NULL,
    `oauth_provider`     TEXT    DEFAULT NULL,
    `oauth_id`           TEXT    DEFAULT NULL
);
CREATE TABLE `circles` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `owner_id` INTEGER NOT NULL DEFAULT 0,
    `name` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1,
    `visibility` TEXT DEFAULT 'public'
);
CREATE TABLE `posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `circle_id` INTEGER DEFAULT NULL,
    `post_type` TEXT DEFAULT 'normal',
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL,
    `video_url` TEXT DEFAULT NULL,
    `view_count` INTEGER NOT NULL DEFAULT 0,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `is_pinned` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `visibility` TEXT DEFAULT 'public',
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `parent_id` INTEGER DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `image` TEXT DEFAULT NULL,
    `is_ai` INTEGER NOT NULL DEFAULT 0,
    `status` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `post_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id` INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `favorites` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `post_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `notification_prefs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `all_enabled` INTEGER NOT NULL DEFAULT 1,
    `post_like` INTEGER NOT NULL DEFAULT 1,
    `post_comment` INTEGER NOT NULL DEFAULT 1,
    `follow` INTEGER NOT NULL DEFAULT 1,
    `message` INTEGER NOT NULL DEFAULT 1,
    `circle_post` INTEGER NOT NULL DEFAULT 1,
    `system` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `drafts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `type` TEXT DEFAULT 'post',
    `circle_id` INTEGER DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `title` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `visibility` TEXT DEFAULT 'public',
    `extra` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `appeals` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `target_type` TEXT DEFAULT NULL,
    `target_id` INTEGER DEFAULT NULL,
    `category` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL,
    `status` TEXT DEFAULT 'pending',
    `admin_id` INTEGER DEFAULT NULL,
    `admin_note` TEXT DEFAULT NULL,
    `handled_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `sender_id` INTEGER DEFAULT NULL,
    `type` TEXT DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `oauth_accounts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `provider` TEXT DEFAULT NULL,
    `oauth_id` TEXT DEFAULT NULL,
    `access_token` TEXT DEFAULT NULL,
    `raw_data` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `email_verifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `email` TEXT DEFAULT NULL,
    `code` TEXT DEFAULT NULL,
    `type` TEXT DEFAULT NULL,
    `expires_at` TEXT DEFAULT NULL,
    `used` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
DROP TABLE IF EXISTS `uploads`;
CREATE TABLE `uploads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `type` TEXT DEFAULT 'attachment',
    `filename` TEXT DEFAULT NULL,
    `url` TEXT DEFAULT NULL,
    `size` INTEGER DEFAULT 0,
    `file_hash` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL
);
SQL);

        // CURDATE() 是 MySQL 函数；给 SQLite 注册等价实现，验证申诉计数口径
        $pdo->sqliteCreateFunction('CURDATE', static fn(...$a): string => date('Y-m-d'), -1);
    }

    // ===== 用户单行查询：字段清单 / 过滤口径逐字段对照 =====

    public function testUserSingleRowQueriesMatchOriginalSql(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => 'Alice', 'avatar' => '/a.png',
            'cover' => '/c.png', 'email' => 'alice@example.com', 'bio' => 'hello', 'password' => 'hash1',
            'role' => 'user', 'status' => 1, 'token_version' => 3, 'created_at' => '2026-01-01 00:00:00',
            'phone' => '13800138000', 'phone_verified_at' => '2026-01-02 00:00:00',
        ]);
        $this->seed('users', [
            'id' => 2, 'username' => 'bob', 'nickname' => 'Bob', 'email' => 'bob@example.com',
            'status' => 0, 'password' => 'hash2', 'phone' => '13900139000',
        ]);
        $this->seed('users', [
            'id' => 3, 'username' => 'gone', 'nickname' => '已注销', 'email' => 'gone@example.com',
            'status' => 0, 'deleted_at' => '2026-03-01 00:00:00',
        ]);

        // 登录：username OR email，含 password
        $this->assertSame(
            $this->originalLogin('alice'),
            UserRepository::findByUsernameOrEmailWithPassword('alice'),
            '登录查询字段/顺序必须逐字一致'
        );
        $this->assertSame(
            $this->originalLogin('alice@example.com'),
            UserRepository::findByUsernameOrEmailWithPassword('alice@example.com'),
            '邮箱入口命中同一行'
        );
        $this->assertNull(UserRepository::findByUsernameOrEmailWithPassword('nobody'));

        // 手机号登录：13 列
        $this->assertSame($this->originalPhoneLogin('13800138000'), UserRepository::findByPhoneWithPassword('13800138000'));
        $this->assertNull(UserRepository::findByPhoneWithPassword('00000000000'), '未注册手机号应为空');

        // 本人资料 / 续期 / 更新回显 / 导出 / 后台回显
        $this->assertSame($this->originalProfile(1), UserRepository::findProfileById(1));
        $this->assertSame($this->originalRefreshUser(1), UserRepository::findRefreshUserById(1));
        $this->assertSame($this->originalUpdatedProfile(1), UserRepository::findUpdatedProfileById(1));
        $this->assertSame($this->originalExportProfile(1), UserRepository::findExportProfileById(1));
        $this->assertSame($this->originalIdRoleStatus(1), UserRepository::findIdRoleStatusById(1));
        $this->assertSame($this->originalIdUsernameAvatar(1), UserRepository::findIdUsernameAvatarById(1));
        $this->assertNull(UserRepository::findProfileById(999), '负例：不存在 id');

        // status=1 过滤（OAuth 已关联分支、评论/点赞入口）
        $this->assertSame($this->originalActiveBasic(1), UserRepository::findActiveBasicById(1));
        $this->assertNull(UserRepository::findActiveBasicById(2), 'status=0 不得命中 OAuth 登录');
        $this->assertSame($this->originalActiveIdByUsername('alice'), UserRepository::findActiveUserIdByUsername('alice'));
        $this->assertNull(UserRepository::findActiveUserIdByUsername('bob'), 'status=0 不得命中评论入口');

        // 公开主页：status=1 或 已注销（被封禁 status=0 且未注销 → 404）
        $this->assertSame($this->originalProfileByUsername('alice'), UserRepository::findProfileByUsername('alice'));
        $this->assertNotNull(UserRepository::findProfileByUsername('gone'), '已注销账号主页仍可访问');
        $this->assertNull(UserRepository::findProfileByUsername('bob'), '被封禁账号不可访问');

        // 隐私 / 设置页
        $this->assertSame($this->originalPrivacyState(1), UserRepository::findPrivacyState(1));
        $this->assertSame($this->originalPrivacySettings(1), UserRepository::findPrivacySettings(1));

        // 查重与存在性（fetchColumn 口径，含排除自身）
        $this->assertSame(1, (int)UserRepository::userIdByUsername('alice'));
        $this->assertSame(0, (int)UserRepository::userIdByUsername('nobody'));
        $this->assertSame(2, (int)UserRepository::userIdByEmail('bob@example.com'));
        $this->assertSame(2, (int)UserRepository::userIdByNickname('Bob'));
        $this->assertSame(1, (int)UserRepository::nicknameExistsElsewhere('Alice', 2));
        $this->assertSame(0, (int)UserRepository::nicknameExistsElsewhere('Alice', 1), '排除自身视为可用');
        $this->assertSame(1, (int)UserRepository::emailExistsElsewhere('alice@example.com', 2));
        $this->assertSame(0, (int)UserRepository::emailExistsElsewhere('alice@example.com', 1));
        $this->assertSame(1, (int)UserRepository::phoneTakenByOther('13800138000', 2));
        $this->assertSame(0, (int)UserRepository::phoneTakenByOther('13800138000', 1));

        // 单列读取
        $this->assertSame('hash1', UserRepository::findPasswordById(1)['password']);
        $this->assertSame(3, (int)UserRepository::findTokenVersionById(1));
        $this->assertSame('13800138000', UserRepository::findPhoneById(1));
        $this->assertSame('alice', UserRepository::findUsernameById(1));
        $this->assertSame($this->originalEmailVerifyState('alice@example.com'), UserRepository::findEmailVerificationState('alice@example.com'));
        $this->assertSame($this->originalIdUsernameByEmail('alice@example.com'), UserRepository::findIdUsernameByEmail('alice@example.com'));

        // 圈主计数（任意状态）
        $this->seed('circles', ['id' => 1, 'owner_id' => 1, 'status' => 1]);
        $this->seed('circles', ['id' => 2, 'owner_id' => 1, 'status' => 0]);
        $this->assertSame(2, UserRepository::countOwnedCircles(1), 'is_circle_owner 计数含下架圈');
        $this->assertSame(0, UserRepository::countOwnedCircles(999));
    }

    // ===== 搜索 / 在线状态：LIKE 绑定、IN 绑定、排除自己、分页 =====

    public function testSearchAndOnlineStatusMatchOriginalSql(): void
    {
        foreach ([
            [1, 'alice', '艾丽丝', 1, 1],
            [2, 'alicia', '爱丽丝', 1, 1],
            [3, 'bob', '鲍勃', 1, 1],
            [4, 'hidden', '隐身', 0, 1],       // allow_search=0
            [5, 'disabled', '禁用', 1, 0],     // status=0
            [6, 'alicetest', '测试', 1, 1],
        ] as [$id, $username, $nickname, $allowSearch, $status]) {
            $this->seed('users', [
                'id' => $id, 'username' => $username, 'nickname' => $nickname,
                'allow_search' => $allowSearch, 'status' => $status, 'avatar' => '/a.png',
            ]);
        }

        // 当前用户=1，关键词 ali → 命中 2、6；隐藏/禁用/自己均排除；id DESC
        $this->assertSame($this->originalSearch(1, 'ali', 20), UserRepository::searchUsers(1, 'ali', 20));
        $this->assertSame([6, 2], array_column(UserRepository::searchUsers(1, 'ali', 20), 'id'));
        $this->assertSame($this->originalSearch(1, 'ali', 1), UserRepository::searchUsers(1, 'ali', 1), 'LIMIT 绑定一致');
        $this->assertSame([6], array_column(UserRepository::searchUsers(1, 'ali', 1), 'id'), '多页第一页');
        $this->assertSame([6, 1], array_column(UserRepository::searchUsers(2, 'ali', 20), 'id'), '排除自己后命中他人，id DESC');
        $this->assertSame([], UserRepository::searchUsers(1, '不存在', 20), '负例：无命中');
        $this->assertSame($this->originalSearch(1, '', 20), UserRepository::searchUsers(1, '', 20), '空关键词 LIKE %%');

        // 在线状态 IN 查询：字段顺序与 hide_online/status 原样返回，PHP 侧判定仍留控制器
        $this->seed('users', [
            'id' => 10, 'last_active_at' => date('Y-m-d H:i:s', time() - 60), 'hide_online' => 0, 'status' => 1,
        ]);
        $this->seed('users', [
            'id' => 11, 'last_active_at' => date('Y-m-d H:i:s', time() - 60), 'hide_online' => 1, 'status' => 1,
        ]);
        $this->seed('users', ['id' => 12, 'last_active_at' => null, 'hide_online' => 0, 'status' => 1]);
        $this->assertSame($this->originalOnlineStatus([10, 11, 12]), UserRepository::fetchOnlineStatuses([10, 11, 12]));
        $this->assertSame([], UserRepository::fetchOnlineStatuses([]), '空 id 集合直接返回空');
        $this->assertCount(0, UserRepository::fetchOnlineStatuses([999]), '不存在的 id 不返回行');
    }

    // ===== 个人主页统计：COUNT(*) 口径、UNION 获评数、关注方向 =====

    public function testProfileStatsCountMatchOriginalSql(): void
    {
        $uid = 5;
        $this->seed('users', ['id' => $uid, 'username' => 'me', 'status' => 1]);
        foreach ([1, 2, 3] as $pid) {
            $this->seed('posts', ['id' => $pid, 'user_id' => $pid === 3 ? 99 : $uid, 'status' => $pid === 3 ? 0 : 1]);
        }
        // 我给别人点赞 2 次（其中一条落在 status=0 的 post 上，不计入 likes_by_user）
        $this->seed('likes', ['id' => 1, 'user_id' => $uid, 'post_id' => 2, 'created_at' => '2026-01-03']);
        $this->seed('likes', ['id' => 2, 'user_id' => $uid, 'post_id' => 3, 'created_at' => '2026-01-04']);
        // 别人给我的 post 点赞
        $this->seed('likes', ['id' => 3, 'user_id' => 99, 'post_id' => 1, 'created_at' => '2026-01-05']);
        // 我写的评论（含落在 status=0 post 上的一条）
        $this->seed('comments', ['id' => 1, 'user_id' => $uid, 'post_id' => 1, 'created_at' => '2026-01-06']);
        $this->seed('comments', ['id' => 2, 'user_id' => $uid, 'post_id' => 3, 'created_at' => '2026-01-07']);
        // 别人对我的评论的回复（UNION 第二支）
        $this->seed('comments', ['id' => 3, 'user_id' => 99, 'parent_id' => 1, 'created_at' => '2026-01-08']);
        // 额外一条孤立评论用于验证 comments 总数口径（无 posts 过滤）
        $this->seed('comments', ['id' => 4, 'user_id' => $uid, 'post_id' => 999, 'created_at' => '2026-01-09']);
        // 关注 / 粉丝
        $this->seed('follows', ['id' => 1, 'follower_id' => $uid, 'following_id' => 99]);
        $this->seed('follows', ['id' => 2, 'follower_id' => 99, 'following_id' => $uid]);
        $this->seed('follows', ['id' => 3, 'follower_id' => 100, 'following_id' => $uid]);

        $this->assertSame($this->originalCountUserPosts($uid), UserRepository::countUserPosts($uid));
        $this->assertSame(2, UserRepository::countUserPosts($uid));
        $this->assertSame((int)$this->col($this->originalLikesByUserSql(), [$uid]), UserRepository::countLikesByUser($uid));
        $this->assertSame(1, UserRepository::countLikesByUser($uid), 'status=0 的 post 点赞不计入');
        $this->assertSame((int)$this->col($this->originalCommentsByUserSql(), [$uid]), UserRepository::countCommentsByUser($uid));
        $this->assertSame(1, UserRepository::countCommentsByUser($uid));
        $this->assertSame((int)$this->col($this->originalLikesReceivedSql(), [$uid]), UserRepository::countLikesReceived($uid));
        $this->assertSame(2, UserRepository::countLikesReceived($uid), '别人对 TA 动态点赞：post1 + post2 各 1');
        $this->assertSame((int)$this->col($this->originalCommentsReceivedSql(), [$uid, $uid]), UserRepository::countCommentsReceived($uid));
        $this->assertSame(2, UserRepository::countCommentsReceived($uid), '我的评论 + 别人对我评论的回复');
        $this->assertSame(1, UserRepository::countFollowing($uid));
        $this->assertSame(2, UserRepository::countFollowers($uid));
        $this->assertSame(3, UserRepository::countUserCommentsAll($uid), '评论总数 SQL 无 posts 过滤，保持原口径');
        $this->assertTrue(UserRepository::isFollowing($uid, 99));
        $this->assertFalse(UserRepository::isFollowing($uid, 100), '负例：未关注');
        $this->assertSame(0, UserRepository::countUserPosts(999), '负例：不存在用户');
    }

    // ===== 列表 / 导出：字段顺序、post_type 铁律、分页正负例 =====

    public function testCommentLikeAndExportListsMatchOriginalSql(): void
    {
        $uid = 7;
        $this->seed('users', ['id' => $uid, 'username' => 'exp', 'nickname' => '导出用户', 'status' => 1]);
        foreach ([1, 2, 3] as $pid) {
            $this->seed('posts', [
                'id' => $pid, 'user_id' => $uid, 'post_type' => 'normal', 'title' => "标题{$pid}",
                'content' => "内容{$pid}", 'images' => '["/i.jpg"]', 'status' => $pid === 3 ? 0 : 1,
                'created_at' => "2026-01-0{$pid} 00:00:00",
            ]);
        }
        foreach ([1, 2, 3] as $cid) {
            $this->seed('comments', [
                'id' => $cid, 'user_id' => $uid, 'post_id' => $cid, 'parent_id' => null,
                'content' => "评论{$cid}", 'created_at' => "2026-02-0{$cid} 00:00:00",
            ]);
        }
        foreach ([1, 2] as $lid) {
            $this->seed('likes', ['id' => $lid, 'user_id' => $uid, 'post_id' => $lid, 'created_at' => "2026-03-0{$lid} 00:00:00"]);
        }
        $this->seed('favorites', ['id' => 1, 'user_id' => $uid, 'post_id' => 2, 'created_at' => '2026-04-01 00:00:00']);
        $this->seed('follows', ['id' => 1, 'follower_id' => $uid, 'following_id' => 99, 'created_at' => '2026-05-01']);
        $this->seed('follows', ['id' => 2, 'follower_id' => 100, 'following_id' => $uid, 'created_at' => '2026-05-02']);
        $this->seed('users', ['id' => 99, 'username' => 'followed', 'nickname' => '被关注', 'status' => 1]);
        $this->seed('users', ['id' => 100, 'username' => 'fan', 'nickname' => '粉丝', 'status' => 1]);

        // 评论列表：按 created_at DESC + LIMIT/OFFSET；post_type 为铁律新增无害列
        foreach ([[0, 2], [1, 2], [5, 2]] as [$offset, $limit]) {
            $repo = UserRepository::fetchUserComments($uid, $limit, $offset);
            $original = $this->originalUserComments($uid, $limit, $offset);
            $this->assertSame(array_column($original, 'id'), array_column($repo, 'id'), '评论列表 ID/排序/分页');
            $this->assertSame(array_column($original, 'content'), array_column($repo, 'content'), '评论内容逐行对照');
        }
        $this->assertArrayHasKey('post_type', UserRepository::fetchUserComments($uid, 10, 0)[0], 'posts 行查询必须带 p.post_type');
        $this->assertSame([], UserRepository::fetchUserComments($uid, 2, 99), '负例：越界页');

        // 点赞列表：列表/计数同一 WHERE，post_type 无害列
        $this->assertSame(
            array_column($this->originalLikedPostIds($uid, 10, 0), 'post_id'),
            array_column(UserRepository::fetchLikedPostIds($uid, 10, 0), 'post_id')
        );
        $this->assertSame(
            array_column($this->originalLikedPostIds($uid, 1, 1), 'post_id'),
            array_column(UserRepository::fetchLikedPostIds($uid, 1, 1), 'post_id'),
            '点赞列表分页'
        );
        $this->assertArrayHasKey('post_type', UserRepository::fetchLikedPostIds($uid, 10, 0)[0]);

        // 导出：7 条路径 + post_type 铁律
        $this->assertSame($this->originalExportPosts($uid), array_map(static function ($r) {
            unset($r['post_type']);
            return $r;
        }, UserRepository::fetchUserPostsForExport($uid)), '导出动态字段/顺序');
        $this->assertArrayHasKey('post_type', UserRepository::fetchUserPostsForExport($uid)[0]);
        $this->assertSame($this->originalExportComments($uid), array_map(static function ($r) {
            unset($r['post_type']);
            return $r;
        }, UserRepository::fetchUserCommentsForExport($uid)));
        $this->assertSame($this->originalExportLikes($uid), array_map(static function ($r) {
            unset($r['post_type']);
            return $r;
        }, UserRepository::fetchLikesForExport($uid)));
        $this->assertSame($this->originalExportFavorites($uid), array_map(static function ($r) {
            unset($r['post_type']);
            return $r;
        }, UserRepository::fetchFavoritesForExport($uid)));
        $this->assertSame($this->originalExportFollowing($uid), UserRepository::fetchFollowingForExport($uid));
        $this->assertSame($this->originalExportFollowers($uid), UserRepository::fetchFollowersForExport($uid));
        $this->assertSame([], UserRepository::fetchUserPostsForExport(999), '负例：空导出');

        // 删号预收集图片（post_type 无害列）
        $images = UserRepository::fetchUserPostImages($uid);
        $this->assertSame([1, 2, 3], array_column($images, 'id'));
        $this->assertArrayHasKey('post_type', $images[0]);

        // 申诉对象摘要
        $appealPost = UserRepository::findAppealPostTarget(1);
        unset($appealPost['post_type']);
        $this->assertSame($this->originalAppealPost(1), $appealPost);
        $this->assertArrayHasKey('post_type', UserRepository::findAppealPostTarget(1));
        $this->assertSame($this->originalAppealComment(1), UserRepository::findAppealCommentTarget(1));
        $this->assertNull(UserRepository::findAppealCommentTarget(999), '负例：不存在的评论');
    }

    // ===== 配置查询：IN 绑定 + 单值读取 =====

    public function testSettingsQueriesMatchOriginalSql(): void
    {
        $this->seed('settings', ['key' => 'forbidden_nicknames', 'value' => 'admin,root']);
        $this->seed('settings', ['key' => 'allowed_email_domains', 'value' => 'example.com']);
        $this->seed('settings', ['key' => 'allow_register', 'value' => '1']);
        $this->seed('settings', ['key' => 'upload_max_avatar_size', 'value' => '5']);

        $this->assertSame('1', UserRepository::fetchSetting('allow_register'));
        $this->assertSame('admin,root', UserRepository::fetchSetting('forbidden_nicknames'));
        $this->assertFalse(UserRepository::fetchSetting('not_exist'), '负例：未配置返回 false（与原 fetchColumn 一致）');

        $expectedKeys = $this->all("SELECT `key`, `value` FROM settings WHERE `key` IN ('forbidden_nicknames', 'allowed_email_domains')");
        $this->assertSame($expectedKeys, UserRepository::fetchSettingsByKeys(['forbidden_nicknames', 'allowed_email_domains']));
        $this->assertSame([], UserRepository::fetchSettingsByKeys([]));
        $this->assertSame(
            $this->all("SELECT `key`, `value` FROM settings"),
            UserRepository::fetchAllSettings()
        );
    }

    // ===== 写方法：字段更新 / 新 ID 返回 / 事务原子性 =====

    public function testWriteMethodsPersistExpectedRows(): void
    {
        $newId = UserRepository::insertUser([
            'username' => 'new', 'nickname' => 'New', 'email' => 'new@example.com',
            'password' => 'h', 'status' => 1, 'token_version' => 1, 'created_at' => '2026-01-01',
        ]);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame('new', $this->rows('users')[0]['username']);

        UserRepository::updateUserFields($newId, ['nickname' => '改名', 'status' => 0, 'updated_at' => '2026-02-01']);
        $this->assertSame('改名', UserRepository::findBasicById($newId)['nickname']);
        $this->assertSame(0, (int)$this->rows('users')[0]['status']);

        UserRepository::updatePasswordAndTokenVersion($newId, 'newhash', 9, '2026-03-01');
        $this->assertSame('newhash', UserRepository::findPasswordById($newId)['password']);
        $this->assertSame(9, (int)UserRepository::findTokenVersionById($newId));

        UserRepository::setPasswordAndBumpToken($newId, 'txhash', 10, '2026-03-02');
        $this->assertSame('txhash', UserRepository::findPasswordById($newId)['password']);
        $this->assertSame(10, (int)UserRepository::findTokenVersionById($newId));

        UserRepository::updateAvatarFrame($newId, 'gold');
        UserRepository::updateUserStatus($newId, 1, '2026-03-03');
        UserRepository::updateLastActiveAt($newId, '2026-03-04');
        UserRepository::updateLastLogin($newId, '2026-03-05');
        UserRepository::updateLastLoginAndActive($newId, '2026-03-06');
        UserRepository::updatePhoneVerifiedAt($newId, '2026-03-07');
        UserRepository::updateTokenVersion($newId, 11, '2026-03-08');
        UserRepository::markEmailVerified('new@example.com', '2026-03-09');
        $this->assertSame('gold', UserRepository::findById($newId)['avatar_frame']);
        $this->assertSame(1, (int)UserRepository::findById($newId)['status']);
        $this->assertSame(1, (int)UserRepository::findEmailVerificationState('new@example.com')['email_verified']);
        $this->assertSame(11, (int)UserRepository::findTokenVersionById($newId));

        // 上传记录存在性
        $this->assertFalse(UserRepository::uploadRecordExists('/u.png', $newId));
        UserRepository::insertUpload(['user_id' => $newId, 'url' => '/u.png', 'type' => 'image', 'status' => 1]);
        $this->assertTrue(UserRepository::uploadRecordExists('/u.png', $newId));

        // 邮箱验证码写方法
        $vid = UserRepository::insertEmailVerification([
            'user_id' => $newId, 'email' => 'new@example.com', 'code' => 'c', 'type' => 'register',
            'expires_at' => '2026-04-01 00:00:00', 'used' => 0,
        ]);
        $this->assertNotNull(UserRepository::findLatestUnusedVerification('new@example.com', 'register'));
        UserRepository::markVerificationUsed($vid);
        $this->assertNull(UserRepository::findLatestUnusedVerification('new@example.com', 'register'), '已使用不再返回');

        $rid = UserRepository::insertEmailVerification([
            'user_id' => $newId, 'email' => 'new@example.com', 'code' => 'r', 'type' => 'reset',
            'expires_at' => '2026-04-01 00:00:00', 'used' => 0,
        ]);
        UserRepository::invalidateResetVerifications('new@example.com', '2026-03-10');
        $this->assertSame(2, (int)$this->pdo()->query("SELECT used FROM email_verifications WHERE id = {$rid}")->fetchColumn(), '旧 reset 记录置 used=2');

        // OAuth 写方法
        $oid = UserRepository::insertOauthAccount([
            'user_id' => $newId, 'provider' => 'github', 'oauth_id' => 'g1', 'access_token' => 't1',
        ]);
        $this->assertSame($newId, (int)UserRepository::findOauthAccount('github', 'g1')['user_id']);
        UserRepository::updateOauthAccountTokens($oid, 't2', '{}', '2026-03-11');
        $this->assertSame('t2', $this->pdo()->query("SELECT access_token FROM oauth_accounts WHERE id = {$oid}")->fetchColumn());

        // 通知偏好写方法
        $this->assertFalse(UserRepository::notificationPrefsExist($newId));
        UserRepository::insertNotificationPrefs(['user_id' => $newId, 'all_enabled' => 1, 'post_like' => 1]);
        $this->assertTrue(UserRepository::notificationPrefsExist($newId));
        UserRepository::updateNotificationPrefs($newId, ['post_like' => 0]);
        $this->assertSame(0, (int)UserRepository::findNotificationPrefs($newId)['post_like']);

        // 申诉写方法
        $aid = UserRepository::insertAppeal([
            'user_id' => $newId, 'category' => 'other', 'content' => '内容', 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, UserRepository::countTodayAppeals($newId));
        $this->assertSame($aid, UserRepository::findAppealById($aid)['id']);
        UserRepository::updateAppeal($aid, ['status' => 'resolved', 'admin_note' => 'ok']);
        $this->assertSame('resolved', UserRepository::findAppealById($aid)['status']);
        UserRepository::insertNotification(['user_id' => $newId, 'type' => 'system', 'content' => 'done', 'is_read' => 0]);
        $this->assertCount(1, $this->rows('notifications'));
    }

    // ===== 事务：草稿保存事务 + 绑定手机号事务 + 失败回滚 =====

    public function testDraftSaveTransactionCoversNewUpdateAndNotFound(): void
    {
        $uid = 20;
        $common = ['type' => 'post', 'content' => '草稿正文', 'images' => '/a,/b,', 'visibility' => 'public'];

        // 新建
        $newId = UserRepository::saveDraft($uid, 0, 'post', 0, $common);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame('草稿正文', UserRepository::findDraftById($newId)['content']);

        // 同类型同目标再存：先删旧再插新，只保留一条
        $newId2 = UserRepository::saveDraft($uid, 0, 'post', 0, $common);
        $this->assertGreaterThan($newId, $newId2);
        $this->assertCount(1, $this->rows('drafts'));

        // 更新已有草稿
        $updated = UserRepository::saveDraft($uid, $newId2, 'post', 0, ['type' => 'post', 'content' => '已更新', 'images' => null]);
        $this->assertSame($newId2, $updated);
        $this->assertSame('已更新', UserRepository::findDraftById($newId2)['content']);

        // 负例：别人的草稿 id → 返回 null 且不写入
        $this->assertNull(UserRepository::saveDraft($uid, 99999, 'post', 0, $common), '草稿不存在应回滚并返回 null');
        $this->assertCount(1, $this->rows('drafts'));

        // 删除草稿：清空内容后物理删除
        $this->assertSame(1, UserRepository::clearDraftContent($newId2, $uid));
        $this->assertNull(UserRepository::findDraftById($newId2)['content']);
        $this->assertSame(1, UserRepository::deleteDraft($newId2, $uid));
        $this->assertSame([], $this->rows('drafts'));
    }

    public function testBindPhoneTransactionIsAtomicAndRollsBackOnFailure(): void
    {
        $this->seed('users', ['id' => 30, 'username' => 'owner', 'status' => 1, 'phone' => '13700137000']);
        $this->seed('users', ['id' => 31, 'username' => 'taken', 'status' => 1, 'phone' => '13600136000']);

        // 成功：占用者解绑 + 目标写入
        UserRepository::bindPhone(30, '13600136000', '2026-01-01 00:00:00');
        $this->assertNull($this->pdo()->query('SELECT phone FROM users WHERE id = 31')->fetchColumn(), '占用者号码被清空');
        $this->assertSame('13600136000', $this->pdo()->query('SELECT phone FROM users WHERE id = 30')->fetchColumn());
        $this->assertSame('2026-01-01 00:00:00', $this->pdo()->query('SELECT phone_verified_at FROM users WHERE id = 30')->fetchColumn());

        // 失败回滚：先埋一个占用者（phone=FORCE_FAIL），再建触发器让「写入目标号码」失败，
        // 验证前一步「解绑占用者」被事务回滚。
        $this->seed('users', ['id' => 32, 'username' => 'forceowner', 'status' => 1, 'phone' => 'FORCE_FAIL']);
        $this->pdo()->exec(<<<'SQL'
CREATE TRIGGER bind_guard BEFORE UPDATE ON users
WHEN NEW.phone = 'FORCE_FAIL'
BEGIN
    SELECT RAISE(ABORT, 'forced failure');
END;
SQL);
        $thrown = false;
        try {
            UserRepository::bindPhone(30, 'FORCE_FAIL', '2026-02-01 00:00:00');
        } catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown, '事务内异常必须抛出，由控制器转错误响应');
        $this->assertSame('13600136000', $this->pdo()->query('SELECT phone FROM users WHERE id = 30')->fetchColumn(), '回滚后目标号码保持原值');
        $this->assertSame('FORCE_FAIL', $this->pdo()->query('SELECT phone FROM users WHERE id = 32')->fetchColumn(), '前一步解绑被回滚，占用者号码未变');
    }

    public function testDeleteUserTransactionRemovesRow(): void
    {
        $id = UserRepository::insertUser(['username' => 'todelete', 'status' => 1]);
        $this->assertCount(1, $this->rows('users'));
        UserRepository::deleteUser($id);
        $this->assertSame([], $this->rows('users'));
    }

    // ==================== 迁移前原始 SQL 快照（用于逐字段对照） ====================

    private function originalLogin(string $value): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, email, bio, password, role, status, token_version, created_at FROM users WHERE username = ? OR email = ? LIMIT 1",
            [$value, $value]
        );
    }

    private function originalPhoneLogin(string $phone): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, email, bio, password, role, status, token_version, phone_verified_at, created_at FROM users WHERE phone = ? LIMIT 1",
            [$phone]
        );
    }

    private function originalProfile(int $id): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, email, bio,
                    gender, birthday, region, user_tags,
                    privacy_home, allow_search, allow_mention, allow_stranger_msg,
                    hide_online, font_scale, msg_sound, created_at
             FROM users WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    private function originalRefreshUser(int $id): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, email, bio, role, status, token_version, created_at, vip_level, vip_expire_at, avatar_frame FROM users WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    private function originalUpdatedProfile(int $id): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, email, role, bio, gender, birthday, region, user_tags,
                    privacy_home, allow_search, allow_mention, allow_stranger_msg, hide_online, font_scale, msg_sound,
                    created_at
             FROM users WHERE id = ?",
            [$id]
        );
    }

    private function originalExportProfile(int $id): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, email, avatar, bio, role, status, created_at FROM users WHERE id = ?",
            [$id]
        );
    }

    private function originalIdRoleStatus(int $id): ?array
    {
        return $this->one("SELECT id, role, status FROM users WHERE id = ? LIMIT 1", [$id]);
    }

    private function originalIdUsernameAvatar(int $id): ?array
    {
        return $this->one("SELECT id, username, avatar FROM users WHERE id = ? LIMIT 1", [$id]);
    }

    private function originalActiveBasic(int $id): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, role, email, token_version, created_at FROM users WHERE id = ? AND status = 1",
            [$id]
        );
    }

    private function originalActiveIdByUsername(string $username): ?array
    {
        return $this->one("SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1", [$username]);
    }

    private function originalProfileByUsername(string $username): ?array
    {
        return $this->one(
            "SELECT id, username, nickname, avatar, cover, bio, user_tags, created_at,
                    realname_verified, realname_verified_at, deleted_at,
                    vip_level, vip_expire_at, avatar_frame
             FROM users
             WHERE username = ? AND (status = 1 OR deleted_at IS NOT NULL) LIMIT 1",
            [$username]
        );
    }

    private function originalPrivacyState(int $id): ?array
    {
        return $this->one("SELECT privacy_home, hide_online, last_active_at FROM users WHERE id = ? LIMIT 1", [$id]);
    }

    private function originalPrivacySettings(int $id): ?array
    {
        return $this->one(
            "SELECT privacy_home, allow_search, allow_mention, allow_stranger_msg, hide_online,
                    font_scale, msg_sound, gender, birthday, region, user_tags, phone, phone_verified_at
             FROM users WHERE id = ?",
            [$id]
        );
    }

    private function originalEmailVerifyState(string $email): ?array
    {
        return $this->one("SELECT id, email_verified FROM users WHERE email = ? LIMIT 1", [$email]);
    }

    private function originalIdUsernameByEmail(string $email): ?array
    {
        return $this->one("SELECT id, username FROM users WHERE email = ? LIMIT 1", [$email]);
    }

    private function originalSearch(int $excludeId, string $keyword, int $limit): array
    {
        return $this->all(
            "SELECT id, username, nickname, avatar, vip_level, avatar_frame, realname_verified FROM users
             WHERE status = 1 AND id != ? AND allow_search = 1
             AND (username LIKE ? OR nickname LIKE ?)
             ORDER BY id DESC LIMIT ?",
            [$excludeId, "%{$keyword}%", "%{$keyword}%", $limit]
        );
    }

    private function originalOnlineStatus(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->all(
            "SELECT id, last_active_at, hide_online, status FROM users WHERE id IN ({$ph})",
            array_values($ids)
        );
    }

    private function originalCountUserPosts(int $userId): int
    {
        return (int)$this->col("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 1", [$userId]);
    }

    private function originalLikesByUserSql(): string
    {
        return "SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE l.user_id = ? AND p.status = 1";
    }

    private function originalCommentsByUserSql(): string
    {
        return "SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE c.user_id = ? AND p.status = 1";
    }

    private function originalLikesReceivedSql(): string
    {
        return "SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = ?";
    }

    private function originalCommentsReceivedSql(): string
    {
        return "SELECT COUNT(*) FROM (
                    SELECT c.id FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = ?
                    UNION
                    SELECT c.id FROM comments c INNER JOIN comments pc ON c.parent_id = pc.id WHERE pc.user_id = ?
                ) t";
    }

    private function originalUserComments(int $userId, int $limit, int $offset): array
    {
        return $this->all(
            "SELECT c.id, c.post_id, c.content, c.created_at
             FROM comments c
             INNER JOIN posts p ON c.post_id = p.id
             WHERE c.user_id = ? AND p.status = 1
             ORDER BY c.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    private function originalLikedPostIds(int $userId, int $limit, int $offset): array
    {
        return $this->all(
            "SELECT l.post_id FROM likes l
             INNER JOIN posts p ON l.post_id = p.id
             WHERE l.user_id = ? AND p.status = 1
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    private function originalExportPosts(int $userId): array
    {
        return $this->all(
            "SELECT id, category_id, title, content, images, attachments, video_url,
                    view_count, like_count, comment_count, status, is_pinned, created_at
             FROM posts WHERE user_id = ? ORDER BY id",
            [$userId]
        );
    }

    private function originalExportComments(int $userId): array
    {
        return $this->all(
            "SELECT c.id, c.post_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                    p.title AS post_title
             FROM comments c LEFT JOIN posts p ON c.post_id = p.id
             WHERE c.user_id = ? ORDER BY c.id",
            [$userId]
        );
    }

    private function originalExportLikes(int $userId): array
    {
        return $this->all(
            "SELECT l.post_id, l.created_at, p.title AS post_title
             FROM likes l LEFT JOIN posts p ON l.post_id = p.id
             WHERE l.user_id = ? ORDER BY l.id",
            [$userId]
        );
    }

    private function originalExportFavorites(int $userId): array
    {
        return $this->all(
            "SELECT f.post_id, f.created_at, p.title AS post_title
             FROM favorites f LEFT JOIN posts p ON f.post_id = p.id
             WHERE f.user_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    private function originalExportFollowing(int $userId): array
    {
        return $this->all(
            "SELECT f.following_id AS user_id, f.created_at, u.username, u.nickname
             FROM follows f INNER JOIN users u ON f.following_id = u.id
             WHERE f.follower_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    private function originalExportFollowers(int $userId): array
    {
        return $this->all(
            "SELECT f.follower_id AS user_id, f.created_at, u.username, u.nickname
             FROM follows f INNER JOIN users u ON f.follower_id = u.id
             WHERE f.following_id = ? ORDER BY f.id",
            [$userId]
        );
    }

    private function originalAppealPost(int $id): ?array
    {
        return $this->one("SELECT id, title, content, status FROM posts WHERE id = ?", [$id]);
    }

    private function originalAppealComment(int $id): ?array
    {
        return $this->one("SELECT c.id, c.content, c.status, c.post_id FROM comments c WHERE c.id = ?", [$id]);
    }

    // ===== 原始 SQL 执行小工具（fetchOne / fetchAll / fetchColumn 语义） =====

    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function col(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
