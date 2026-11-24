<?php
/**
 * PostRepository（PostCrudTrait 收敛批次一 C）迁移回归测试
 *
 * 对照法：在内存 SQLite 里同时执行「迁移前的原始内联 SQL」与「下沉后的
 * PostRepository 方法」，逐字段 assertSame，确保字段顺序 / 别名 / 排序 /
 * 可见性 / 软删条件 / post_type 铁律任意一个变化都会被抓到。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\PostRepository;
use PDO;

final class PostCrudRepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // BehaviorTestCase 已建 users / uploads，补上本批查询需要的列
        $this->pdo()->exec('ALTER TABLE users ADD COLUMN allow_mention INTEGER NOT NULL DEFAULT 1');
        $this->pdo()->exec('ALTER TABLE uploads ADD COLUMN post_id INTEGER DEFAULT NULL');

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER NOT NULL,
    `content`     TEXT    DEFAULT NULL,
    `images`      TEXT    DEFAULT NULL,
    `videos`      TEXT    DEFAULT NULL,
    `title`       TEXT    DEFAULT NULL,
    `post_type`   TEXT    DEFAULT 'normal',
    `visibility`  TEXT    DEFAULT 'public',
    `status`      INTEGER NOT NULL DEFAULT 1,
    `audit_state` INTEGER NOT NULL DEFAULT 0,
    `removed_at`  TEXT    DEFAULT NULL,
    `category_id` INTEGER DEFAULT NULL,
    `circle_id`   INTEGER DEFAULT NULL,
    `view_count`  INTEGER NOT NULL DEFAULT 0,
    `is_pinned`   INTEGER NOT NULL DEFAULT 0,
    `pinned_at`   TEXT    DEFAULT NULL,
    `origin_id`   INTEGER DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `circles` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`            TEXT    DEFAULT NULL,
    `owner_id`        INTEGER NOT NULL DEFAULT 0,
    `status`          INTEGER NOT NULL DEFAULT 1,
    `post_permission` TEXT    DEFAULT 'all',
    `limited`         INTEGER NOT NULL DEFAULT 0,
    `pay_type`        INTEGER NOT NULL DEFAULT 0,
    `removed_at`      TEXT    DEFAULT NULL,
    `post_count`      INTEGER NOT NULL DEFAULT 0,
    `post_review`     INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `status`    INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `follows` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `follower_id`  INTEGER NOT NULL,
    `following_id` INTEGER NOT NULL
);
CREATE TABLE `categories` (
    `id`     INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`   TEXT    DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `category_follows` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`     INTEGER NOT NULL,
    `category_id` INTEGER NOT NULL
);
CREATE TABLE `comments` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL,
    `content` TEXT    DEFAULT NULL
);
CREATE TABLE `reports` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `target_type` TEXT    DEFAULT NULL,
    `target_id`   INTEGER NOT NULL
);
CREATE TABLE `comment_likes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `comment_id` INTEGER NOT NULL
);
CREATE TABLE `post_view_logs` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `viewer_id`  INTEGER DEFAULT NULL,
    `viewer_ip`  TEXT    DEFAULT NULL,
    `view_date`  TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `mentions` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`     INTEGER NOT NULL,
    `comment_id`  INTEGER DEFAULT NULL,
    `user_id`     INTEGER NOT NULL,
    `mentioned_by` INTEGER NOT NULL,
    `type`        TEXT    DEFAULT 'post',
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `sender_id`  INTEGER NOT NULL,
    `type`       TEXT    DEFAULT NULL,
    `post_id`    INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `is_read`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `questions` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `question`   TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `teams` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`     INTEGER NOT NULL,
    `headline`    TEXT    DEFAULT NULL,
    `need_count`  INTEGER NOT NULL DEFAULT 0,
    `joined_count` INTEGER NOT NULL DEFAULT 0,
    `deadline`    TEXT    DEFAULT NULL,
    `city`        TEXT    DEFAULT NULL,
    `contact`     TEXT    DEFAULT NULL,
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `team_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `team_id`   INTEGER NOT NULL,
    `post_id`   INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `joined_at` TEXT    DEFAULT NULL
);
CREATE TABLE `checkin_posts` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`     INTEGER NOT NULL,
    `goal`        TEXT    DEFAULT NULL,
    `target_days` INTEGER NOT NULL DEFAULT 0,
    `created_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `question_answers` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL
);
CREATE TABLE `checkin_logs` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL
);
CREATE TABLE `polls` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL
);
CREATE TABLE `poll_options` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `poll_id` INTEGER NOT NULL
);
CREATE TABLE `poll_votes` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `poll_id` INTEGER NOT NULL
);
CREATE TABLE `likes` (
    `id`      INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id` INTEGER NOT NULL
);
SQL);
    }

    // ===== 创建路径：读查询 =====

    public function testCreateReadPathsKeepSqlShapePostTypeAndSoftDeleteRules(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'alice', 'nickname' => 'Alice', 'avatar' => '/a.png',
            'role' => 'user', 'vip_level' => 0, 'status' => 1,
        ]);
        $this->seed('users', [
            'id' => 2, 'username' => 'bob', 'nickname' => 'Bob', 'avatar' => '/b.png',
            'role' => 'user', 'vip_level' => 3, 'allow_mention' => 0, 'status' => 1,
        ]);
        $this->seed('users', [
            'id' => 3, 'username' => 'carol', 'nickname' => 'Carol', 'avatar' => '/c.png',
            'role' => 'user', 'vip_level' => 0, 'status' => 0, // 软删/禁用：白名单应剔除
        ]);

        $today = date('Y-m-d');
        foreach ([
            [10, 1, 'normal', 'public', 1, '2026-01-01 00:00:00'],
            [11, 1, 'question', 'public', 0, '2026-01-01 00:00:00'], // status=0 且 audit_state=1 = 审核待审
            [12, 1, 'poll', 'private', 1, '2026-01-01 00:00:00'],
            [13, 1, 'normal', 'public', 1, $today . ' 12:00:00'],
            // 管理员下架态：status=0 且 audit_state=0（不是审核待审），同样是 public 内容
            [14, 1, 'normal', 'public', 0, '2026-01-01 00:00:00'],
        ] as [$id, $uid, $type, $vis, $status, $created]) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => $uid, 'content' => "正文{$id}",
                'images' => null, 'videos' => null, 'post_type' => $type,
                'visibility' => $vis, 'status' => $status,
                'audit_state' => $id === 11 ? 1 : 0,
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        // 待审(status=0 且 audit_state=1)与原管理员下架(status=0 且 audit_state=0)
        // 的原帖都不得被转发来源查询捞到，否则公开转发帖会复活已下架正文。
        $this->assertNull(PostRepository::findOriginPost(11), '审核待审的原帖不可作为转发来源');
        $this->assertNull(PostRepository::findOriginPost(14), '已下架的 public 原帖不可作为转发来源');

        // 转发来源：与权威 SQL 逐字段比对（Repository 额外补 post_type）
        foreach ([10, 11, 12, 14, 999] as $id) {
            $expected = $this->originalOriginPost($id);
            $actual = PostRepository::findOriginPost($id);
            if ($expected === null) {
                $this->assertNull($actual, '不存在的原动态应返回 null');
                continue;
            }
            $this->assertSame($expected, array_diff_key($actual, ['post_type' => true]));
            $this->assertArrayHasKey('post_type', $actual, 'posts 行查询必须带 p.post_type');
            $this->assertSame([10 => 'normal', 11 => 'question', 12 => 'poll'][$id], $actual['post_type']);
        }

        // 发帖回显：不过滤 status，SELECT p.* 逐字对照（p.* 自带 post_type）
        $actual = PostRepository::findAnyWithAuthorById(11);
        $this->assertSame($this->originalAnyWithAuthor(11), $actual);
        $this->assertSame(0, (int)$actual['status'], 'status=0 的待审帖也必须能回显');
        $this->assertArrayHasKey('post_type', $actual);
        $this->assertNull(PostRepository::findAnyWithAuthorById(999));

        // 配额：VIP 等级 + 当日已发数（COUNT(*) 聚合口径不变）
        $vip = PostRepository::findVipLevel(2);
        $this->assertSame($this->originalVipLevel(2), $vip);
        $this->assertSame(1, PostRepository::countTodayPosts(1, $today));
        $this->assertSame($this->originalCountToday(1, $today), PostRepository::countTodayPosts(1, $today));

        // 分类存在性：status=1 才可选（软删负例）
        $this->seed('categories', ['id' => 20, 'name' => '科技', 'status' => 1]);
        $this->seed('categories', ['id' => 21, 'name' => '下架分类', 'status' => 0]);
        $this->assertSame($this->originalActiveCategory(20), PostRepository::findActiveCategory(20));
        $this->assertNull(PostRepository::findActiveCategory(21), 'status=0 分类不可选');
        $this->assertNull(PostRepository::findActiveCategory(999));

        // @提及白名单：IN 占位符 + 绑定，allow_mention/status 条件留在 SQL
        $names = ['alice', 'bob', 'carol', 'nobody'];
        $allowed = PostRepository::fetchMentionableUsernames($names);
        $this->assertSame($this->originalMentionable($names), $allowed);
        $this->assertSame(['alice'], array_column($allowed, 'username'), 'bob 关闭提及、carol 已禁用');
        $this->assertSame([], PostRepository::fetchMentionableUsernames([]));
    }

    // ===== 创建路径：写查询 =====

    public function testCreateWritePathsMatchOriginalSqlAndTransactionRollsBack(): void
    {
        $postData = [
            'user_id' => 1, 'content' => '新帖', 'post_type' => 'normal',
            'visibility' => 'public', 'status' => 1,
            'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
        ];

        // 事务内写 posts，提交后行内容与预期一致
        $postId = PostRepository::transaction(fn() => PostRepository::insertPost($postData));
        $this->assertGreaterThan(0, $postId);
        $row = $this->pdo()->query("SELECT * FROM posts WHERE id = {$postId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertRowEquals($postData, array_intersect_key($row, $postData), 'insertPost 与原 INSERT 落库等价');

        // 事务回滚 + 异常原样重抛，且不留脏数据
        $before = count($this->rows('posts'));
        try {
            PostRepository::transaction(function () use ($postData) {
                PostRepository::insertPost($postData);
                throw new \RuntimeException('boom');
            });
            $this->fail('事务内异常应原样重抛');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertCount($before, $this->rows('posts'), 'rollBack 后不得留下写入');

        // 帖型附加表：问答 / 组队 / 打卡，逐字段对照迁移前 INSERT
        PostRepository::insertPostTypeExtras(101, 'question', ['question' => 'Q?'], 1);
        $this->assertRowEquals(
            ['id' => 1, 'post_id' => 101, 'question' => 'Q?', 'created_at' => $this->pdo()
                ->query('SELECT created_at FROM questions WHERE post_id = 101')->fetchColumn()],
            $this->pdo()->query('SELECT * FROM questions WHERE post_id = 101')->fetch(PDO::FETCH_ASSOC)
        );

        PostRepository::insertPostTypeExtras(102, 'team', [
            'headline' => '组队', 'need_count' => 3, 'deadline' => '2026-12-31',
            'city' => '深圳', 'contact' => 'c',
        ], 1);
        $team = $this->pdo()->query('SELECT * FROM teams WHERE post_id = 102')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(3, (int)$team['need_count']);
        $this->assertSame(1, (int)$team['joined_count'], '发起人占一个位置，joined_count=1');
        $this->assertSame('深圳', $team['city']);
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM team_members WHERE post_id = 102')->fetchColumn());

        PostRepository::insertPostTypeExtras(103, 'checkin', ['goal' => '打卡', 'target_days' => 7], 1);
        $checkin = $this->pdo()->query('SELECT * FROM checkin_posts WHERE post_id = 103')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('打卡', $checkin['goal']);
        $this->assertSame(7, (int)$checkin['target_days']);
        // 未知帖型不应产生任何附加数据
        PostRepository::insertPostTypeExtras(104, 'normal', null, 1);
        $this->assertSame(0, (int)$this->pdo()->query('SELECT COUNT(*) FROM questions WHERE post_id = 104')->fetchColumn());

        // 通知与 @提及写入
        $now = '2026-03-01 00:00:00';
        PostRepository::insertNotification([
            'user_id' => 9, 'sender_id' => 1, 'type' => 'mention', 'post_id' => 101,
            'comment_id' => null, 'content' => '在动态中提到了你', 'is_read' => 0, 'created_at' => $now,
        ]);
        PostRepository::insertMention(101, null, 9, 1, 'post', $now);
        $this->assertSame('mention', $this->pdo()->query('SELECT type FROM notifications WHERE user_id = 9')->fetchColumn());
        $mention = $this->pdo()->query('SELECT * FROM mentions WHERE post_id = 101')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(9, (int)$mention['user_id']);
        $this->assertSame(1, (int)$mention['mentioned_by']);
    }

    // ===== 编辑 / 详情路径：读查询 =====

    public function testEditAndDetailReadPathsMatchOriginalSql(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'alice', 'nickname' => 'A', 'avatar' => '', 'role' => 'user']);
        $this->seed('circles', [
            'id' => 30, 'name' => '运动圈', 'owner_id' => 1, 'status' => 1,
            'post_permission' => 'all', 'limited' => 0, 'removed_at' => null,
        ]);
        // status=0 / removed_at 非空的圈子：门禁在控制器侧，Repository 仅按原 SQL 返回行
        $this->seed('circles', ['id' => 31, 'name' => '下架圈', 'owner_id' => 1, 'status' => 0]);
        $this->seed('circles', ['id' => 32, 'name' => '移除圈', 'owner_id' => 1, 'status' => 1, 'removed_at' => '2026-02-01 00:00:00']);
        $this->seed('circle_members', ['id' => 1, 'circle_id' => 30, 'user_id' => 1, 'role' => 'owner', 'status' => 1]);
        $this->seed('circle_members', ['id' => 2, 'circle_id' => 30, 'user_id' => 2, 'role' => 'member', 'status' => 2]);

        $this->assertSame($this->originalCircleForPost(30), PostRepository::findCircleForPost(30));
        $this->assertSame($this->originalCircleForPost(31), PostRepository::findCircleForPost(31));
        $this->assertSame($this->originalCircleForPost(32), PostRepository::findCircleForPost(32));
        $this->assertSame($this->originalCircleMember(30, 1), PostRepository::findCircleMemberForPost(30, 1));
        $this->assertSame(2, (int)PostRepository::findCircleMemberForPost(30, 2)['status'], '黑名单成员 status=2');
        $this->assertNull(PostRepository::findCircleMemberForPost(30, 999), '非成员负例');

        // 可见性判定：无行返回 false，必须 !== false && !== null（fail-open 已修）
        $this->assertSame($this->originalCircleViewFlag(30, 1), PostRepository::isCircleMemberForView(30, 1));
        $this->assertSame($this->originalCircleViewFlag(30, 999), PostRepository::isCircleMemberForView(30, 999));
        $this->assertFalse(PostRepository::isCircleMemberForView(30, 999), '非圈子成员不得判定为可见');
        $this->seed('follows', ['id' => 1, 'follower_id' => 1, 'following_id' => 2]);
        $this->assertSame($this->originalFollowViewFlag(1, 2), PostRepository::isFollowerForView(1, 2));
        $this->assertSame($this->originalFollowViewFlag(1, 999), PostRepository::isFollowerForView(1, 999));
        $this->assertFalse(PostRepository::isFollowerForView(1, 999), '未关注者不得判定为好友');

        // 分类推送：posts 行查询带 p.post_type，status 过滤仍由控制器做
        $this->seed('categories', ['id' => 40, 'name' => '科技', 'status' => 1]);
        $this->seed('posts', [
            'id' => 104, 'user_id' => 1, 'post_type' => 'checkin', 'status' => 1,
            'category_id' => 40, 'created_at' => '2026-03-01 00:00:00',
        ]);
        $this->seed('posts', [
            'id' => 105, 'user_id' => 1, 'post_type' => 'normal', 'status' => 0,
            'category_id' => 40, 'created_at' => '2026-03-01 00:00:00',
        ]);
        $actual = PostRepository::findPostForCategoryNotify(104);
        $this->assertSame($this->originalPostForCategoryNotify(104), array_diff_key($actual, ['post_type' => true]));
        $this->assertArrayHasKey('post_type', $actual, 'posts 行查询必须带 p.post_type');
        $this->assertSame('checkin', $actual['post_type']);
        $this->assertSame(0, (int)PostRepository::findPostForCategoryNotify(105)['status'], 'status=0 仍查出，由控制器拦截');
        $this->assertSame($this->originalCategory(40), PostRepository::findCategory(40));
        $this->seed('category_follows', ['id' => 1, 'user_id' => 1, 'category_id' => 40]);
        $this->seed('category_follows', ['id' => 2, 'user_id' => 2, 'category_id' => 40]);
        $this->assertSame($this->originalCategoryFollowers(40), PostRepository::fetchCategoryFollowerIds(40));

        // 编辑态 @提及回显
        $this->seed('mentions', ['id' => 1, 'post_id' => 104, 'comment_id' => null, 'user_id' => 2, 'mentioned_by' => 1, 'type' => 'post']);
        $this->seed('mentions', ['id' => 2, 'post_id' => 104, 'comment_id' => 5, 'user_id' => 3, 'mentioned_by' => 1, 'type' => 'comment']);
        $this->seed('users', ['id' => 2, 'username' => 'bob', 'nickname' => 'B', 'role' => 'user']);
        $this->seed('users', ['id' => 3, 'username' => 'carol', 'nickname' => 'C', 'role' => 'user']);
        $this->assertSame($this->originalMentionUsernames(104), PostRepository::fetchPostMentionUsernames(104));
        $this->assertSame(['bob'], array_column(PostRepository::fetchPostMentionUsernames(104), 'username'), '只回显正文提及，不含评论提及');

        // 浏览去重 / 计数
        $this->assertFalse(PostRepository::hasGuestViewed(104, '1.2.3.4', '2026-03-01'));
        PostRepository::insertGuestView(104, '1.2.3.4', '2026-03-01', '2026-03-01 00:00:00');
        $this->assertTrue(PostRepository::hasGuestViewed(104, '1.2.3.4', '2026-03-01'));
        $this->assertSame(
            $this->originalGuestViewCheck(104, '1.2.3.4', '2026-03-01'),
            PostRepository::hasGuestViewed(104, '1.2.3.4', '2026-03-01')
        );
        PostRepository::incrementPostViewCount(104);
        $this->assertSame(1, (int)$this->pdo()->query('SELECT view_count FROM posts WHERE id = 104')->fetchColumn());
    }

    // ===== 删除路径：读查询 + 级联事务 =====

    public function testDeletePathMatchesOriginalSqlAndCascades(): void
    {
        $this->seed('posts', [
            'id' => 200, 'user_id' => 1, 'post_type' => 'team',
            'images' => '["/a.jpg"]', 'videos' => '[]', 'status' => 1,
            'created_at' => '2026-03-01 00:00:00',
        ]);
        $this->seed('posts', [
            'id' => 201, 'user_id' => 1, 'post_type' => 'normal', 'status' => 1,
            'created_at' => '2026-03-01 00:00:00',
        ]);
        $this->seed('uploads', ['id' => 1, 'file_hash' => 'h1', 'url' => '/a.jpg', 'post_id' => 200]);
        $this->seed('uploads', ['id' => 2, 'file_hash' => 'h2', 'url' => '/shared.jpg', 'post_id' => 200]);
        $this->seed('uploads', ['id' => 3, 'file_hash' => 'h3', 'url' => '/shared.jpg', 'post_id' => 201]);
        foreach ([301, 302] as $cid) {
            $this->seed('comments', ['id' => $cid, 'post_id' => 200]);
        }
        $this->seed('comments', ['id' => 303, 'post_id' => 201]);
        $this->seed('reports', ['id' => 1, 'target_type' => 'post', 'target_id' => 200]);
        $this->seed('reports', ['id' => 2, 'target_type' => 'comment', 'target_id' => 301]);
        $this->seed('comment_likes', ['id' => 1, 'comment_id' => 301]);
        $this->seed('post_view_logs', ['id' => 1, 'post_id' => 200]);
        $this->seed('questions', ['id' => 1, 'post_id' => 200]);
        $this->seed('question_answers', ['id' => 1, 'post_id' => 200]);
        $this->seed('teams', ['id' => 1, 'post_id' => 200]);
        $this->seed('team_members', ['id' => 1, 'team_id' => 1, 'post_id' => 200, 'user_id' => 1]);
        $this->seed('checkin_posts', ['id' => 1, 'post_id' => 200]);
        $this->seed('checkin_logs', ['id' => 1, 'post_id' => 200]);
        $this->seed('polls', ['id' => 1, 'post_id' => 200]);
        $this->seed('poll_options', ['id' => 1, 'poll_id' => 1]);
        $this->seed('poll_votes', ['id' => 1, 'poll_id' => 1]);
        $this->seed('likes', ['id' => 1, 'post_id' => 200]);

        // 删前回显：逐字对照 + post_type 铁律
        $postActual = PostRepository::findPostForDelete(200);
        $this->assertSame($this->originalPostForDelete(200), array_diff_key($postActual, ['post_type' => true]));
        $this->assertArrayHasKey('post_type', $postActual, 'posts 行查询必须带 p.post_type');
        $this->assertSame('team', $postActual['post_type']);

        $this->assertSame($this->originalPostUploadUrls(200), PostRepository::fetchPostUploadUrls(200));
        $this->assertSame(0, PostRepository::countOtherUploadRefs('/a.jpg', 200), '独有 URL 可删');
        $this->assertSame(1, PostRepository::countOtherUploadRefs('/shared.jpg', 200), '共享 URL 被 201 引用，不可删');
        $this->assertSame($this->originalOtherUploadRefs('/shared.jpg', 200), PostRepository::countOtherUploadRefs('/shared.jpg', 200));
        $this->assertSame($this->originalPostCommentIds(200), PostRepository::fetchPostCommentIds(200));

        $commentIds = array_map('intval', array_column(PostRepository::fetchPostCommentIds(200), 'id'));
        PostRepository::deletePostCascade(200, $commentIds);

        // post 200 及其全部关联数据被清；post 201 及共享 URL 不受影响
        $this->assertSame([], $this->pdo()->query('SELECT * FROM posts WHERE id = 200')->fetchAll(PDO::FETCH_ASSOC));
        foreach (['reports', 'comment_likes', 'post_view_logs', 'uploads', 'questions',
                  'question_answers', 'teams', 'team_members', 'checkin_posts', 'checkin_logs',
                  'polls', 'poll_options', 'poll_votes', 'comments', 'likes'] as $table) {
            $left = (int)$this->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $this->assertSame($this->expectedRemaining($table), $left, "{$table} 级联删除结果不符");
        }
        $this->assertSame(1, (int)$this->pdo()->query('SELECT COUNT(*) FROM posts WHERE id = 201')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM uploads WHERE url = '/shared.jpg'")->fetchColumn());
    }

    // ===== 辅助断言 =====

    /** 忽略数组键顺序后比较行内容（SQLite 列序与预期数组可能不同） */
    private function assertRowEquals(array $expected, array $actual, string $msg = ''): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $msg);
    }

    /** 各关联表在删除 post 200 后应剩余的行数（post 201 及评论提及保留） */
    private function expectedRemaining(string $table): int
    {
        return match ($table) {
            'uploads' => 1,           // post 201 的 /shared.jpg
            'comments' => 1,          // post 201 的评论
            default => 0,
        };
    }

    // ===== 迁移前原始 SQL 快照（逐字搬迁，不进产品代码） =====

    private function originalOriginPost(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.id, p.content, p.images, p.user_id, p.visibility, p.circle_id,
                    u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = ? AND p.status = 1 AND (p.audit_state IS NULL OR p.audit_state <> 1) LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalAnyWithAuthor(int $postId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT p.*, u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = ?"
        );
        $stmt->execute([$postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalVipLevel(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT vip_level FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalCountToday(int $userId, string $date): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 1 AND DATE(created_at) = ?'
        );
        $stmt->execute([$userId, $date]);
        return (int)$stmt->fetchColumn();
    }

    private function originalActiveCategory(int $categoryId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM categories WHERE id = ? AND status = 1 LIMIT 1');
        $stmt->execute([$categoryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalMentionable(array $usernames): array
    {
        if (empty($usernames)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT username FROM users WHERE allow_mention = 1 AND status = 1 AND username IN ({$ph})"
        );
        $stmt->execute($usernames);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCircleForPost(int $circleId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, owner_id, status, post_permission, limited, removed_at, pay_type, post_review FROM circles WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$circleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalCircleMember(int $circleId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT role, status FROM circle_members WHERE circle_id = ? AND user_id = ?');
        $stmt->execute([$circleId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalCircleViewFlag(int $circleId, int $userId): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM circle_members WHERE circle_id = ? AND user_id = ? AND status = 1 LIMIT 1'
        );
        $stmt->execute([$circleId, $userId]);
        // fetchColumn 无行返回 false，旧快照的 !== null 是 fail-open 缺陷
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    }

    private function originalFollowViewFlag(int $followerId, int $followingId): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1');
        $stmt->execute([$followerId, $followingId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null;
    }

    private function originalPostForCategoryNotify(int $postId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id, status, audit_state, user_id, category_id, visibility FROM posts WHERE id = ? LIMIT 1');
        $stmt->execute([$postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalCategory(int $categoryId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id, name FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalCategoryFollowers(int $categoryId): array
    {
        $stmt = $this->pdo()->prepare('SELECT user_id FROM category_follows WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalMentionUsernames(int $postId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT u.username FROM mentions m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.post_id = ? AND m.comment_id IS NULL"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalGuestViewCheck(int $postId, string $viewerIp, string $viewDate): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM post_view_logs WHERE post_id = ? AND viewer_ip = ? AND view_date = ? AND viewer_id IS NULL LIMIT 1'
        );
        $stmt->execute([$postId, $viewerIp, $viewDate]);
        return (bool)$stmt->fetchColumn();
    }

    private function originalPostForDelete(int $postId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id, user_id, images, videos FROM posts WHERE id = ? LIMIT 1');
        $stmt->execute([$postId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalPostUploadUrls(int $postId): array
    {
        $stmt = $this->pdo()->prepare('SELECT url FROM uploads WHERE post_id = ?');
        $stmt->execute([$postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalOtherUploadRefs(string $url, int $postId): int
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM uploads WHERE url = ? AND post_id IS NOT NULL AND post_id != ?'
        );
        $stmt->execute([$url, $postId]);
        return (int)$stmt->fetchColumn();
    }

    private function originalPostCommentIds(int $postId): array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM comments WHERE post_id = ?');
        $stmt->execute([$postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
