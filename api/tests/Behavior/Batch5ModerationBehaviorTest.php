<?php
/**
 * 批次五·审核域 Repository 迁移回归测试
 *
 * 对照法：在内存 SQLite 里同时执行「HEAD 原始内联 SQL」与「ModerationRepository 方法」，
 * 逐字段比对返回结构、排序、分页、可见性与写副作用；任何字段丢失 / ORDER BY 漂移 /
 * LIMIT 参数顺序颠倒 / 审核守卫被绕过都会被抓到。
 *
 * 重点防回归项：
 *   1. 批次二真实事故：后台待审与前台待审字段清单不同却复用同一方法，后台丢 reviewer_id。
 *      本批次用 testSimilarPostQueriesKeepDistinctFieldSets() 对两条相似 posts 查询做交叉断言。
 *   2. 批次三真实事故：审核状态流转守卫没有随方法一起下沉。本批次断言 audit_state=1 的
 *      待审定时动态绝不会被 publishDueScheduledPosts() 放出。
 *   3. 批次四真实事故：注释里塞 SQL 字面量骗过断言。本测试所有原始 SQL 都在私有方法里
 *      真实执行，不依赖任何源码文本断言。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\ModerationRepository;
use PDO;

final class Batch5ModerationBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `sensitive_words` (
    `id`          INTEGER PRIMARY KEY AUTOINCREMENT,
    `word`        TEXT    NOT NULL,
    `level`       TEXT    NOT NULL DEFAULT 'replace',
    `replacement` TEXT    NOT NULL DEFAULT '***',
    `scene`       TEXT    NOT NULL DEFAULT '',
    `status`      INTEGER NOT NULL DEFAULT 1,
    `hit_count`   INTEGER NOT NULL DEFAULT 0,
    `created_at`  TEXT    DEFAULT NULL,
    `updated_at`  TEXT    DEFAULT NULL
);
CREATE TABLE `content_filter_logs` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL DEFAULT 0,
    `scene`      TEXT    NOT NULL DEFAULT '',
    `target_id`  INTEGER NOT NULL DEFAULT 0,
    `action`     TEXT    NOT NULL DEFAULT '',
    `words`      TEXT    NOT NULL DEFAULT '',
    `excerpt`    TEXT    NOT NULL DEFAULT '',
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `circles` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`       TEXT    DEFAULT NULL,
    `owner_id`   INTEGER NOT NULL DEFAULT 0,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `status`    INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE `posts` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`      INTEGER NOT NULL,
    `circle_id`    INTEGER DEFAULT NULL,
    `post_type`    TEXT    DEFAULT 'normal',
    `title`        TEXT    DEFAULT NULL,
    `content`      TEXT    DEFAULT NULL,
    `images`       TEXT    DEFAULT NULL,
    `videos`       TEXT    DEFAULT NULL,
    `location`     TEXT    DEFAULT NULL,
    `is_anonymous` INTEGER NOT NULL DEFAULT 0,
    `status`       INTEGER NOT NULL DEFAULT 1,
    `audit_state`  INTEGER NOT NULL DEFAULT 0,
    `scheduled_at` TEXT    DEFAULT NULL,
    `created_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `comments` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `post_id`    INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `content`    TEXT    DEFAULT NULL,
    `is_ai`      INTEGER NOT NULL DEFAULT 0,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `comment_marks` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `comment_id` INTEGER NOT NULL,
    `mark_type`  TEXT    NOT NULL DEFAULT 'god',
    `operator_id` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL,
    UNIQUE (`comment_id`, `mark_type`)
);
CREATE TABLE `notifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL DEFAULT 0,
    `type`       TEXT    DEFAULT NULL,
    `title`      TEXT    DEFAULT NULL,
    `content`    TEXT    DEFAULT NULL,
    `post_id`    INTEGER NOT NULL DEFAULT 0,
    `comment_id` INTEGER NOT NULL DEFAULT 0,
    `sender_id`  INTEGER NOT NULL DEFAULT 0,
    `is_read`    INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL
);
SQL);

        // BehaviorTestCase 的 users 最小 schema 没有 updated_at；本批次禁言写入需要它。
        $this->pdo()->exec('ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT NULL');
    }

    // ===== 敏感词列表：LIKE / 双字段排序 / LIMIT ?,? / 同 WHERE 计数 =====

    public function testSensitiveWordsListMatchesHeadSqlExactly(): void
    {
        $base = [
            'level' => 'block', 'replacement' => '***', 'scene' => '', 'status' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->seed('sensitive_words', array_merge($base, ['id' => 1, 'word' => '赌博', 'hit_count' => 3]));
        $this->seed('sensitive_words', array_merge($base, ['id' => 2, 'word' => '博彩', 'hit_count' => 9]));
        $this->seed('sensitive_words', array_merge($base, ['id' => 3, 'word' => '正常词', 'hit_count' => 9]));
        $this->seed('sensitive_words', array_merge($base, ['id' => 4, 'word' => '停用词', 'hit_count' => 0, 'status' => 0]));

        foreach ([['', 0, 20], ['博', 0, 20], ['', 1, 2], ['不存在', 0, 20]] as [$keyword, $offset, $limit]) {
            $this->assertSame(
                $this->originalSensitiveWords($keyword, $offset, $limit),
                ModerationRepository::fetchSensitiveWords($keyword, $offset, $limit),
                '敏感词列表字段/排序/分页必须与 HEAD 原文一致'
            );
            $this->assertSame(
                count($this->originalSensitiveWords($keyword, 0, 1000)),
                ModerationRepository::countSensitiveWords($keyword),
                '计数必须与列表共用同一 WHERE'
            );
        }

        $this->assertSame([3, 2, 1, 4], array_column(ModerationRepository::fetchSensitiveWords('', 0, 20), 'id'), 'ORDER BY hit_count DESC, id DESC（同 hit_count 按 id DESC）');
        $this->assertSame([], ModerationRepository::fetchSensitiveWords('不存在', 0, 20), '空结果负例');
        $this->assertSame(0, ModerationRepository::countSensitiveWords('不存在'));
    }

    // ===== 敏感词 CRUD：新增判重 / 改词判重（排除自身）/ 写副作用 =====

    public function testSensitiveWordCrudKeepsOriginalJudgementAndSideEffects(): void
    {
        $this->seed('sensitive_words', ['id' => 1, 'word' => '已有词', 'level' => 'replace', 'replacement' => '***', 'scene' => '', 'status' => 1, 'hit_count' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->seed('sensitive_words', ['id' => 2, 'word' => '另一词', 'level' => 'block', 'replacement' => '***', 'scene' => 'post', 'status' => 1, 'hit_count' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        // 新增/导入判重：SELECT id ... WHERE word = ?
        $this->assertNotEmpty(ModerationRepository::findSensitiveWordId('已有词'));
        $this->assertEmpty(ModerationRepository::findSensitiveWordId('新词'), 'fetchColumn 无行返回 false，控制器按真值判断');

        // 改词判重必须排除自身；这是与新增判重不同的 SQL，不得合并
        $this->assertEmpty(ModerationRepository::findOtherSensitiveWordId('已有词', 1), '排除自身后不判重');
        $this->assertNotEmpty(ModerationRepository::findOtherSensitiveWordId('已有词', 2), '撞到别人仍判重');

        $newId = ModerationRepository::insertSensitiveWord([
            'word' => '新词', 'level' => 'review', 'replacement' => '***',
            'scene' => 'post,comment', 'status' => 1,
            'created_at' => '2026-02-01 00:00:00', 'updated_at' => '2026-02-01 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        $inserted = array_values(array_filter($this->rows('sensitive_words'), static fn(array $r): bool => (int)$r['id'] === $newId));
        $this->assertSame('新词', $inserted[0]['word']);
        $this->assertSame('review', $inserted[0]['level']);
        $this->assertSame('post,comment', $inserted[0]['scene']);

        ModerationRepository::updateSensitiveWord($newId, [
            'word' => '新词2', 'level' => 'block', 'replacement' => 'X',
            'scene' => '', 'status' => 0, 'updated_at' => '2026-02-02 00:00:00',
        ]);
        $updated = array_values(array_filter($this->rows('sensitive_words'), static fn(array $r): bool => (int)$r['id'] === $newId));
        $this->assertSame('新词2', $updated[0]['word']);
        $this->assertSame('block', $updated[0]['level']);
        $this->assertSame('2026-02-02 00:00:00', $updated[0]['updated_at']);
        $this->assertSame('2026-02-01 00:00:00', $updated[0]['created_at'], 'created_at 不得被更新覆盖');

        ModerationRepository::deleteSensitiveWord($newId);
        $this->assertCount(2, $this->rows('sensitive_words'));
        ModerationRepository::deleteSensitiveWord(9999);
        $this->assertCount(2, $this->rows('sensitive_words'), '删除不存在的词无副作用');
    }

    // ===== 命中日志：LEFT JOIN 字段集 / scene 过滤 / 分页计数 =====

    public function testFilterLogsMatchesHeadSqlAndSceneFilter(): void
    {
        $this->seed('users', ['id' => 10, 'username' => 'alice', 'nickname' => '爱丽丝', 'avatar' => '/a.jpg', 'role' => 'user']);
        $this->seed('users', ['id' => 11, 'username' => 'bob', 'nickname' => '鲍勃', 'avatar' => '/b.jpg', 'role' => 'user']);
        foreach ([
            [1, 10, 'post', 'block'], [2, 11, 'comment', 'replace'],
            [3, 10, 'post', 'review'], [4, 99, 'chat', 'block'],
        ] as [$id, $uid, $scene, $action]) {
            $this->seed('content_filter_logs', [
                'id' => $id, 'user_id' => $uid, 'scene' => $scene, 'target_id' => $id,
                'action' => $action, 'words' => '词', 'excerpt' => '片段',
                'created_at' => '2026-01-0' . $id . ' 00:00:00',
            ]);
        }

        foreach ([['', 0, 20], ['post', 0, 20], ['', 1, 2], ['不存在', 0, 20]] as [$scene, $offset, $limit]) {
            $this->assertSame(
                $this->originalFilterLogs($scene, $offset, $limit),
                ModerationRepository::fetchFilterLogs($scene, $offset, $limit),
                '日志列表 l.* + u.username/nickname/avatar 与 HEAD 逐字段一致'
            );
            $this->assertSame(
                count($this->originalFilterLogs($scene, 0, 1000)),
                ModerationRepository::countFilterLogs($scene),
                '日志计数与列表共用同一 WHERE'
            );
        }

        $rows = ModerationRepository::fetchFilterLogs('', 0, 20);
        $this->assertSame(
            ['id', 'user_id', 'scene', 'target_id', 'action', 'words', 'excerpt', 'created_at', 'username', 'nickname', 'avatar'],
            array_keys($rows[0]),
            'JOIN 带出的用户列顺序不得变化'
        );
        $this->assertSame([4, 3, 2, 1], array_column($rows, 'id'), 'ORDER BY l.id DESC');
        $orphan = array_values(array_filter($rows, static fn(array $r): bool => (int)$r['user_id'] === 99));
        $this->assertNull($orphan[0]['username'], 'LEFT JOIN：用户不存在时仍保留日志行');
        $this->assertSame([], ModerationRepository::fetchFilterLogs('不存在', 0, 20));
    }

    // ===== 禁言 / 解禁：更新形状与相邻用户隔离 =====

    public function testMuteAndUnmuteKeepOriginalUpdateShape(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'role' => 'user', 'muted_until' => null]);
        $this->seed('users', ['id' => 8, 'username' => 'u8', 'role' => 'user', 'muted_until' => null]);

        ModerationRepository::updateUserMutedUntil(7, '2026-03-01 00:00:00', '2026-02-01 00:00:00');
        $seven = array_values(array_filter($this->rows('users'), static fn(array $r): bool => (int)$r['id'] === 7))[0];
        $eight = array_values(array_filter($this->rows('users'), static fn(array $r): bool => (int)$r['id'] === 8))[0];
        $this->assertSame('2026-03-01 00:00:00', $seven['muted_until']);
        $this->assertSame('2026-02-01 00:00:00', $seven['updated_at']);
        $this->assertNull($eight['muted_until'], '不得波及相邻用户');

        ModerationRepository::clearUserMutedUntil(7, '2026-02-02 00:00:00');
        $seven = array_values(array_filter($this->rows('users'), static fn(array $r): bool => (int)$r['id'] === 7))[0];
        $this->assertNull($seven['muted_until']);
        $this->assertSame('2026-02-02 00:00:00', $seven['updated_at']);
    }

    // ===== 神评：角色查询 / 评论行字段 / INSERT IGNORE 幂等 / 通知 =====

    public function testGodCommentQueriesAndMarkIdempotence(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'status' => 1]);
        $this->seed('users', ['id' => 10, 'username' => 'owner', 'role' => 'user']);
        $this->seed('users', ['id' => 20, 'username' => 'commenter', 'role' => 'user']);
        $this->seed('users', ['id' => 30, 'username' => 'operator', 'role' => 'user']);
        $this->seed('circle_members', ['id' => 1, 'circle_id' => 1, 'user_id' => 30, 'role' => 'admin', 'status' => 1]);
        $this->seed('circle_members', ['id' => 2, 'circle_id' => 1, 'user_id' => 21, 'role' => 'owner', 'status' => 2]);
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'circle_id' => 1, 'post_type' => 'normal', 'status' => 1]);
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 20, 'is_ai' => 0, 'status' => 1, 'content' => '好']);
        $this->seed('comments', ['id' => 2, 'post_id' => 1, 'user_id' => 20, 'is_ai' => 1, 'status' => 1, 'content' => 'AI']);

        // 圈子角色：status = 1 条件留在 SQL，status=2 的成员不得被当圈主
        $this->assertSame($this->originalRoleRow(1, 30), ModerationRepository::findCircleMemberRole(1, 30));
        $this->assertNull(ModerationRepository::findCircleMemberRole(1, 21), 'status=2 的成员不得返回');
        $this->assertNull(ModerationRepository::findCircleMemberRole(1, 999));
        $this->assertNull(ModerationRepository::findCircleMemberRole(0, 30));

        // 评论行：原有字段逐字段一致，末尾追加 post_type（铁律）
        $expected = $this->originalCommentForMark(1);
        $actual = ModerationRepository::findCommentForMark(1);
        $this->assertNotNull($actual);
        $this->assertSame(array_keys($expected), array_slice(array_keys($actual), 0, count($expected)), '原有字段顺序不得变化');
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $actual[$key], "字段 {$key} 不得变化");
        }
        $this->assertArrayHasKey('post_type', $actual, 'posts 行查询必须带 post_type');
        $this->assertNull(ModerationRepository::findCommentForMark(9999), '评论不存在负例');

        // 标记幂等：INSERT IGNORE 首插 true，重插 false，物理只留一行
        $now = '2026-02-01 00:00:00';
        $this->assertTrue(ModerationRepository::insertGodCommentMark(1, 30, $now));
        $this->assertFalse(ModerationRepository::insertGodCommentMark(1, 30, $now), '重复标记返回 false');
        $this->assertCount(1, $this->rows('comment_marks'));
        $this->assertSame('god', $this->rows('comment_marks')[0]['mark_type'], 'mark_type 仍为 god');

        ModerationRepository::insertGodCommentNotification([
            'user_id' => 20, 'type' => 'god_comment', 'title' => '你的评论被标记为神评',
            'content' => '内容', 'post_id' => 1, 'comment_id' => 1,
            'sender_id' => 30, 'is_read' => 0, 'created_at' => $now,
        ]);
        $this->assertCount(1, $this->rows('notifications'));
        $this->assertSame('god_comment', $this->rows('notifications')[0]['type']);

        // 取消标记同样幂等
        $this->assertTrue(ModerationRepository::deleteGodCommentMark(1));
        $this->assertFalse(ModerationRepository::deleteGodCommentMark(1));
        $this->assertCount(0, $this->rows('comment_marks'));
    }

    // ===== 定时发布：audit_state 守卫随 SQL 下沉，待审动态不得被放出 =====

    public function testScheduledPublishKeepsAuditStateGuard(): void
    {
        $now = '2026-02-01 00:00:00';
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'post_type' => 'normal', 'status' => 0, 'audit_state' => 0, 'scheduled_at' => '2026-01-01 00:00:00']);
        $this->seed('posts', ['id' => 2, 'user_id' => 10, 'post_type' => 'normal', 'status' => 0, 'audit_state' => 1, 'scheduled_at' => '2026-01-01 00:00:00']);
        $this->seed('posts', ['id' => 3, 'user_id' => 10, 'post_type' => 'normal', 'status' => 1, 'audit_state' => 0, 'scheduled_at' => '2026-01-01 00:00:00']);
        $this->seed('posts', ['id' => 4, 'user_id' => 10, 'post_type' => 'normal', 'status' => 0, 'audit_state' => 0, 'scheduled_at' => null]);
        $this->seed('posts', ['id' => 5, 'user_id' => 10, 'post_type' => 'normal', 'status' => 0, 'audit_state' => 0, 'scheduled_at' => '2026-03-01 00:00:00']);

        $this->assertSame($this->originalDueCount($now), ModerationRepository::countDueScheduledPosts($now));
        $this->assertSame(1, ModerationRepository::countDueScheduledPosts($now));
        $this->assertSame(1, ModerationRepository::publishDueScheduledPosts($now));

        $byId = [];
        foreach ($this->rows('posts') as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $this->assertSame(1, (int)$byId[1]['status'], '到期且审核通过的动态被发布');
        $this->assertNull($byId[1]['scheduled_at'], '发布后清空 scheduled_at');
        $this->assertSame(0, (int)$byId[2]['status'], 'audit_state=1 待审动态绝不能被定时器放出');
        $this->assertSame('2026-01-01 00:00:00', $byId[2]['scheduled_at'], '待审动态的 scheduled_at 不得被清空');
        $this->assertSame(0, (int)$byId[4]['status'], 'scheduled_at IS NULL 的行不参与');
        $this->assertSame(0, (int)$byId[5]['status'], '未来时间的行不参与');

        $this->assertSame(0, ModerationRepository::countDueScheduledPosts($now), '发布后再次计数为 0');
    }

    // ===== 我的定时列表：LIMIT ? OFFSET ? 参数顺序 / 字段集 / 计数一致 =====

    public function testMyScheduledListMatchesHeadSqlWithBoundLimitOffset(): void
    {
        foreach ([
            [1, 20, '2026-01-05 00:00:00'], [2, 20, '2026-01-02 00:00:00'],
            [3, 20, '2026-01-04 00:00:00'], [4, 20, '2026-01-01 00:00:00'],
        ] as [$id, $uid, $at]) {
            $this->seed('posts', [
                'id' => $id, 'user_id' => $uid, 'circle_id' => 1, 'post_type' => 'normal',
                'title' => "标题{$id}", 'content' => "内容{$id}", 'images' => null, 'videos' => null,
                'location' => '', 'is_anonymous' => $id % 2, 'status' => 0,
                'audit_state' => 0, 'scheduled_at' => $at, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }
        $this->seed('posts', ['id' => 5, 'user_id' => 20, 'post_type' => 'normal', 'status' => 1, 'scheduled_at' => '2026-01-03 00:00:00']);
        $this->seed('posts', ['id' => 6, 'user_id' => 20, 'post_type' => 'normal', 'status' => 0, 'scheduled_at' => null]);
        $this->seed('posts', ['id' => 7, 'user_id' => 21, 'post_type' => 'normal', 'status' => 0, 'scheduled_at' => '2026-01-03 00:00:00']);

        foreach ([[0, 2], [1, 2], [2, 2], [0, 20]] as [$offset, $limit]) {
            $expected = $this->originalMyScheduledList(20, $limit, $offset);
            $actual = ModerationRepository::fetchMyScheduledPosts(20, $offset, $limit);
            $this->assertSame(array_column($expected, 'id'), array_column($actual, 'id'), 'LIMIT ? OFFSET ? 的 limit/offset 顺序不得颠倒');
            foreach ($expected as $index => $expectedRow) {
                foreach ($expectedRow as $key => $value) {
                    $this->assertSame($value, $actual[$index][$key], "字段 {$key} 不得变化");
                }
            }
            $this->assertSame(array_keys($expected[0]), array_slice(array_keys($actual[0]), 0, count($expected[0])), '原有字段顺序不得变化');
            $this->assertArrayHasKey('post_type', $actual[0], 'posts 行查询必须带 post_type');
        }

        $this->assertSame(
            count($this->originalMyScheduledList(20, 1000, 0)),
            ModerationRepository::countMyScheduledPosts(20),
            '计数与列表同一 WHERE，且不随分页参数变化'
        );
        $this->assertSame([4, 2, 3, 1], array_column(ModerationRepository::fetchMyScheduledPosts(20, 0, 20), 'id'), 'ORDER BY scheduled_at ASC');
        $this->assertSame(4, ModerationRepository::countMyScheduledPosts(20));
        $this->assertSame(0, ModerationRepository::countMyScheduledPosts(999), '无匹配用户计数为 0');
        $this->assertSame([], ModerationRepository::fetchMyScheduledPosts(999, 0, 20));
    }

    // ===== 交叉断言：相似 posts 查询字段集互不污染（防 reviewer_id 缺陷重演） =====

    public function testSimilarPostQueriesKeepDistinctFieldSets(): void
    {
        $this->seed('circles', ['id' => 1, 'name' => '圈A', 'owner_id' => 10, 'status' => 1]);
        $this->seed('posts', [
            'id' => 1, 'user_id' => 10, 'circle_id' => 1, 'post_type' => 'normal',
            'title' => '标题', 'content' => '内容', 'images' => null, 'videos' => null,
            'location' => '', 'is_anonymous' => 0, 'status' => 0, 'audit_state' => 0,
            'scheduled_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 20, 'is_ai' => 0, 'status' => 1]);

        // 两条 SQL 都会 JOIN / FROM posts，形状相近但用途与字段清单不同：
        //   ① 我的定时列表：前台回显，含 scheduled_at / is_anonymous 等 10 个原有列；
        //   ② 神评标记取帖子归属：只取 p.user_id AS post_user_id / p.circle_id。
        // 批次二事故正是把这类「后台/前台」字段集合并，静默丢掉 reviewer_id。
        $scheduled = ModerationRepository::fetchMyScheduledPosts(10, 0, 1);
        $forMark = ModerationRepository::findCommentForMark(1);

        $this->assertArrayHasKey('scheduled_at', $scheduled[0]);
        $this->assertArrayHasKey('is_anonymous', $scheduled[0]);
        $this->assertArrayHasKey('title', $scheduled[0]);
        $this->assertArrayNotHasKey('post_user_id', $scheduled[0], '定时列表不得被神评查询字段污染');

        $this->assertArrayHasKey('post_user_id', $forMark, '神评查询必须保留帖子作者别名');
        $this->assertArrayHasKey('circle_id', $forMark, '神评查询必须保留圈子 ID');
        $this->assertArrayNotHasKey('scheduled_at', $forMark, '神评查询不得被定时列表字段污染');
        $this->assertArrayNotHasKey('images', $forMark);

        // 两处都按铁律带 post_type，但都不该凭空多出审核人字段
        $this->assertArrayHasKey('post_type', $scheduled[0]);
        $this->assertArrayHasKey('post_type', $forMark);
        $this->assertArrayNotHasKey('reviewer_id', $scheduled[0], '批次二 reviewer_id 缺陷不得在本域重演');
        $this->assertArrayNotHasKey('reviewer_id', $forMark, '批次二 reviewer_id 缺陷不得在本域重演');

        // 敏感词 SELECT * 与日志 SELECT l.* + 用户列也刻意保持不同字段集
        $this->seed('sensitive_words', ['id' => 1, 'word' => '词', 'level' => 'replace', 'replacement' => '***', 'scene' => '', 'status' => 1, 'hit_count' => 0]);
        $this->seed('content_filter_logs', ['id' => 1, 'user_id' => 10, 'scene' => 'post', 'target_id' => 1, 'action' => 'block', 'words' => '词', 'excerpt' => '片段']);
        $wordKeys = array_keys(ModerationRepository::fetchSensitiveWords('', 0, 1)[0]);
        $logKeys = array_keys(ModerationRepository::fetchFilterLogs('', 0, 1)[0]);
        $this->assertContains('hit_count', $wordKeys);
        $this->assertNotContains('hit_count', $logKeys);
        $this->assertContains('username', $logKeys);
        $this->assertNotContains('username', $wordKeys);
    }

    // ===== 异常路径：原代码无事务，通知写失败被吞后标记必须保留 =====

    public function testNotificationFailureDoesNotRollBackGodMark(): void
    {
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'post_type' => 'normal', 'status' => 1]);
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 20, 'is_ai' => 0, 'status' => 1]);

        $now = '2026-02-01 00:00:00';
        $this->assertTrue(ModerationRepository::insertGodCommentMark(1, 30, $now));

        // 模拟通知表缺失：控制器原有的 try/catch 会吞掉这个异常；原代码没有事务，
        // 因此标记写入不应被回滚（规范 4.4：原本没有事务的连续写不要擅自加事务）。
        $this->pdo()->exec('DROP TABLE `notifications`');
        try {
            ModerationRepository::insertGodCommentNotification([
                'user_id' => 20, 'type' => 'god_comment', 'title' => 't', 'content' => 'c',
                'post_id' => 1, 'comment_id' => 1, 'sender_id' => 30, 'is_read' => 0, 'created_at' => $now,
            ]);
            $this->fail('通知表缺失时应抛出异常');
        } catch (\Throwable $e) {
            $this->assertNotSame('通知表缺失时应抛出异常', $e->getMessage());
        }

        $this->assertCount(1, $this->rows('comment_marks'), '通知失败不得回滚已写入的神评标记');
    }

    // ===== 迁移前原始 SQL 快照（真实执行，写在测试里不进产品代码） =====

    /** HEAD AdminModerationController::sensitiveWords() 原始 SQL */
    private function originalSensitiveWords(string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND word LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM sensitive_words {$where}
             ORDER BY hit_count DESC, id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD AdminModerationController::filterLogs() 原始 SQL */
    private function originalFilterLogs(string $scene, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($scene !== '') {
            $where .= ' AND l.scene = ?';
            $params[] = $scene;
        }
        $stmt = $this->pdo()->prepare(
            "SELECT l.*, u.username, u.nickname, u.avatar
             FROM content_filter_logs l
             LEFT JOIN users u ON l.user_id = u.id
             {$where}
             ORDER BY l.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** HEAD PostModerationTrait::canMarkGodComment() 原始 SQL */
    private function originalRoleRow(int $circleId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT cm.role, c.owner_id
             FROM circle_members cm
             LEFT JOIN circles c ON c.id = cm.circle_id
             WHERE cm.circle_id = ? AND cm.user_id = ? AND cm.status = 1"
        );
        $stmt->execute([$circleId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** HEAD PostModerationTrait::markGodComment()/unmarkGodComment() 原始 SQL（无 post_type） */
    private function originalCommentForMark(int $commentId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT c.id, c.post_id, c.user_id, c.is_ai, c.status, p.user_id AS post_user_id, p.circle_id
             FROM comments c
             INNER JOIN posts p ON p.id = c.post_id
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$commentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** HEAD PostModerationTrait::publishScheduledPosts() 原始 COUNT SQL */
    private function originalDueCount(string $now): int
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM posts
             WHERE status = 0 AND scheduled_at IS NOT NULL AND scheduled_at <= ? AND audit_state = 0"
        );
        $stmt->execute([$now]);
        return (int)$stmt->fetchColumn();
    }

    /** HEAD PostModerationTrait::scheduledList() 原始 SQL（limit/offset 原文拼接） */
    private function originalMyScheduledList(int $userId, int $limit, int $offset): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, title, content, images, videos, location, circle_id,
                    is_anonymous, scheduled_at, created_at
             FROM posts
             WHERE user_id = ? AND status = 0 AND scheduled_at IS NOT NULL
             ORDER BY scheduled_at ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
