<?php
/**
 * 内联 SQL 收敛批次二迁移回归测试
 *
 * 覆盖 PostTypeTrait / PostCommentTrait → PostRepository、
 * AdminCircleController → CircleRepository、AppController → AppRepository
 * 四个文件的下沉路径。对照法：把迁移前原始 SQL 原样放在测试私有方法里执行，
 * 与 Repository 结果 assertSame 逐字段比对；事务路径覆盖成功与异常回滚。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\AppRepository;
use Hublog\Core\CircleRepository;
use Hublog\Core\PostRepository;
use PDO;

final class Batch2RepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();

        // MySQL 函数在 SQLite 下的等价实现，使原始 SQL 快照可真实执行
        $pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
            $needle = array_shift($args);
            $pos = array_search($needle, $args, true);
            return $pos === false ? 0 : $pos + 1;
        });
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        // SQLite PDO 回调把 4294967295 截成 -1，这里按 MySQL 语义还原该上限常量
        $pdo->sqliteCreateFunction('LEAST', static function (...$args) {
            $args = array_map(static fn($v) => $v === -1 ? 4294967295 : $v, $args);
            return min($args);
        });
        $pdo->sqliteCreateFunction('GREATEST', static fn(...$args) => max($args));
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($v) => $v, 1);

        foreach ([
            'last_login TEXT DEFAULT NULL',
            'oauth_provider TEXT DEFAULT NULL',
            'oauth_id TEXT DEFAULT NULL',
            'deleted_at TEXT DEFAULT NULL',
            'realname_verified INTEGER NOT NULL DEFAULT 0',
            'realname_verified_at TEXT DEFAULT NULL',
            'updated_at TEXT DEFAULT NULL',
        ] as $column) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column);
        }
        $pdo->exec('ALTER TABLE uploads ADD COLUMN user_id INTEGER DEFAULT NULL');

        $pdo->exec(<<<'SQL'
CREATE TABLE `posts` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `post_type`     TEXT    DEFAULT 'normal',
    `status`        INTEGER NOT NULL DEFAULT 1,
    `audit_state`   INTEGER DEFAULT NULL,
    `visibility`    TEXT    DEFAULT 'public',
    `circle_id`     INTEGER DEFAULT NULL,
    `is_anonymous`  INTEGER NOT NULL DEFAULT 0,
    `content`       TEXT    DEFAULT NULL,
    `like_count`    INTEGER NOT NULL DEFAULT 0,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `created_at`    TEXT    DEFAULT NULL
);
CREATE TABLE `circles` (
    `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`            TEXT    DEFAULT NULL,
    `slug`            TEXT    DEFAULT NULL,
    `description`     TEXT    DEFAULT NULL,
    `cover`           TEXT    DEFAULT NULL,
    `owner_id`        INTEGER NOT NULL DEFAULT 0,
    `member_count`    INTEGER NOT NULL DEFAULT 0,
    `post_count`      INTEGER NOT NULL DEFAULT 0,
    `join_mode`       TEXT    DEFAULT 'free',
    `post_permission` TEXT    DEFAULT 'all',
    `visibility`      TEXT    DEFAULT 'public',
    `limited`         INTEGER NOT NULL DEFAULT 0,
    `removed_at`      TEXT    DEFAULT NULL,
    `status`          INTEGER NOT NULL DEFAULT 1,
    `created_at`      TEXT    DEFAULT NULL,
    `updated_at`      TEXT    DEFAULT NULL
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `joined_at` TEXT    DEFAULT NULL,
    `status`    INTEGER NOT NULL DEFAULT 1
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
CREATE TABLE `polls` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL,
    `question` TEXT DEFAULT NULL, `multi` INTEGER DEFAULT 0, `max_choice` INTEGER DEFAULT 1,
    `expires_at` TEXT DEFAULT NULL, `is_anonymous` INTEGER DEFAULT 0, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `poll_options` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `poll_id` INTEGER NOT NULL, `content` TEXT DEFAULT NULL,
    `sort_order` INTEGER NOT NULL DEFAULT 0, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `poll_votes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `poll_id` INTEGER NOT NULL, `option_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL, `post_id` INTEGER NOT NULL, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `questions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `question` TEXT DEFAULT NULL,
    `status` INTEGER NOT NULL DEFAULT 0, `answer` TEXT DEFAULT NULL, `answered_by` INTEGER DEFAULT NULL,
    `answered_at` TEXT DEFAULT NULL, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `question_answers` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
    `content` TEXT DEFAULT NULL, `is_accepted` INTEGER NOT NULL DEFAULT 0,
    `accepted_at` TEXT DEFAULT NULL, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `teams` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `headline` TEXT DEFAULT NULL,
    `need_count` INTEGER NOT NULL DEFAULT 0, `joined_count` INTEGER NOT NULL DEFAULT 0,
    `deadline` TEXT DEFAULT NULL, `city` TEXT DEFAULT NULL, `contact` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `team_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `team_id` INTEGER NOT NULL, `post_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL, `joined_at` TEXT DEFAULT NULL
);
CREATE TABLE `checkin_posts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL,
    `goal` TEXT DEFAULT NULL, `target_days` INTEGER NOT NULL DEFAULT 0, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `checkin_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
    `content` TEXT DEFAULT NULL, `checkin_date` TEXT DEFAULT NULL, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
    `parent_id` INTEGER DEFAULT NULL, `content` TEXT DEFAULT NULL, `image` TEXT DEFAULT NULL,
    `images` TEXT DEFAULT NULL, `is_ai` INTEGER DEFAULT 0, `is_pinned` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `post_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `comment_likes` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `comment_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL, `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `sender_id` INTEGER DEFAULT NULL,
    `type` TEXT DEFAULT NULL, `title` TEXT DEFAULT NULL, `post_id` INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL, `content` TEXT DEFAULT NULL, `is_read` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `reports` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `target_type` TEXT DEFAULT NULL, `target_id` INTEGER NOT NULL
);
CREATE TABLE `follows` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `follower_id` INTEGER NOT NULL, `following_id` INTEGER NOT NULL
);
CREATE TABLE `app_versions` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `platform` TEXT DEFAULT NULL, `version_code` INTEGER DEFAULT 0,
    `version_name` TEXT DEFAULT NULL, `update_type` INTEGER DEFAULT 0, `changelog` TEXT DEFAULT NULL,
    `download_url` TEXT DEFAULT NULL, `status` INTEGER DEFAULT 1
);
CREATE TABLE `categories` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `parent_id` INTEGER DEFAULT NULL,
    `name` TEXT DEFAULT NULL, `sort_order` INTEGER DEFAULT 0, `status` INTEGER DEFAULT 1
);
CREATE TABLE `push_tokens` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `provider` TEXT DEFAULT NULL,
    `token` TEXT DEFAULT NULL, `device_id` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL, `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `oauth_accounts` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `provider` TEXT DEFAULT NULL,
    `oauth_id` TEXT DEFAULT NULL, `access_token` TEXT DEFAULT NULL, `raw_data` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL, `updated_at` TEXT DEFAULT NULL
);
CREATE TABLE `refresh_tokens` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `device_id` TEXT DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL, `ip` TEXT DEFAULT NULL, `revoked` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT NULL, `last_used_at` TEXT DEFAULT NULL, `expires_at` TEXT DEFAULT NULL
);
CREATE TABLE `app_crash_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INTEGER NOT NULL, `event` TEXT DEFAULT NULL,
    `platform` TEXT DEFAULT NULL, `client` TEXT DEFAULT NULL, `route` TEXT DEFAULT NULL,
    `api_url` TEXT DEFAULT NULL, `api_code` TEXT DEFAULT NULL, `message` TEXT DEFAULT NULL,
    `stack` TEXT DEFAULT NULL, `context` TEXT DEFAULT NULL, `ip` TEXT DEFAULT NULL
);
CREATE TABLE `conversations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `circle_id` INTEGER DEFAULT NULL, `type` TEXT DEFAULT NULL
);
CREATE TABLE `conversation_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT, `conversation_id` INTEGER NOT NULL, `user_id` INTEGER NOT NULL
);
SQL);
    }

    // ==================== PostTypeTrait → PostRepository ====================

    public function testPollReadPathsMatchOriginalSqlForEmptySingleAndMultiResults(): void
    {
        $this->assertNull(PostRepository::findPollByPostId(1), '空库时无投票');
        $this->assertSame([], PostRepository::fetchPollOptions(1));
        $this->assertSame([], PostRepository::countPollVotesByOption(1));
        $this->assertSame([], PostRepository::fetchPollMyVotedOptions(1, 7));
        $this->assertSame([], PostRepository::fetchExistingPollVotes(1, 7, [1, 2]));
        $this->assertSame(0, PostRepository::countUserPollVotes(1, 7));

        $this->seed('posts', ['id' => 10, 'user_id' => 3, 'post_type' => 'poll', 'status' => 1]);
        $this->seed('posts', ['id' => 11, 'user_id' => 3, 'post_type' => 'normal', 'status' => 0]);
        $this->seed('polls', [
            'id' => 5, 'post_id' => 10, 'question' => '去哪儿', 'multi' => 1,
            'max_choice' => 2, 'expires_at' => null,
        ]);
        $this->seed('poll_options', ['id' => 1, 'poll_id' => 5, 'content' => 'A', 'sort_order' => 2]);
        $this->seed('poll_options', ['id' => 2, 'poll_id' => 5, 'content' => 'B', 'sort_order' => 1]);
        $this->seed('poll_votes', ['poll_id' => 5, 'option_id' => 1, 'user_id' => 7, 'post_id' => 10]);
        $this->seed('poll_votes', ['poll_id' => 5, 'option_id' => 1, 'user_id' => 8, 'post_id' => 10]);
        $this->seed('poll_votes', ['poll_id' => 5, 'option_id' => 2, 'user_id' => 7, 'post_id' => 10]);

        $this->assertSame($this->originalFetch('SELECT id, question, multi, max_choice, expires_at FROM polls WHERE post_id = 10 LIMIT 1'), PostRepository::findPollByPostId(10));
        $this->assertSame($this->originalFetch('SELECT id, question, multi, max_choice, expires_at FROM polls WHERE id = 5 LIMIT 1'), PostRepository::findPollById(5));
        $this->assertSame($this->originalFetch('SELECT id, multi, max_choice, expires_at FROM polls WHERE post_id = 10 LIMIT 1'), PostRepository::findPollSummaryByPostId(10));
        $this->assertSame($this->originalFetch('SELECT id, multi, max_choice, expires_at FROM polls WHERE id = 5 LIMIT 1'), PostRepository::findPollSummaryById(5));
        $this->assertSame($this->originalAll('SELECT id, content FROM poll_options WHERE poll_id = 5 ORDER BY sort_order ASC, id ASC'), PostRepository::fetchPollOptions(5));
        $this->assertSame($this->originalAll('SELECT option_id, COUNT(*) AS cnt FROM poll_votes WHERE poll_id = 5 GROUP BY option_id'), PostRepository::countPollVotesByOption(5));
        $this->assertSame($this->originalAll('SELECT option_id FROM poll_votes WHERE poll_id = 5 AND user_id = 7'), PostRepository::fetchPollMyVotedOptions(5, 7));
        $this->assertSame($this->originalAll('SELECT id FROM poll_options WHERE poll_id = 5'), PostRepository::fetchPollOptionIds(5));
        $this->assertSame($this->originalAll('SELECT option_id FROM poll_votes WHERE poll_id = 5 AND user_id = 7 AND option_id IN (1, 2)'), PostRepository::fetchExistingPollVotes(5, 7, [1, 2]));
        $this->assertSame(2, PostRepository::countUserPollVotes(5, 7));
        $this->assertSame($this->originalFetch('SELECT poll_id FROM poll_votes WHERE post_id = 10 ORDER BY id DESC LIMIT 1'), PostRepository::findPollIdByVotePost(10));
        $this->assertNotNull(PostRepository::findActivePostId(10, 0), 'status=1 的 public 帖子可投票');
        $this->assertNull(PostRepository::findActivePostId(11, 0), 'status=0 的帖子不可投票');
        $this->assertArrayHasKey('post_type', PostRepository::findActivePostId(10, 0), 'posts 行查询必须带 p.post_type');
        $this->assertSame([2, 1], array_column(PostRepository::fetchPollOptions(5), 'id'), 'sort_order ASC, id ASC 排序不变');
    }

    public function testPollWritePathsAndTypePostTableWhitelist(): void
    {
        $pollId = PostRepository::insertPoll(10, '问题', 0, 1, null, '2026-01-01 00:00:00');
        $this->assertGreaterThan(0, $pollId);
        $optionA = PostRepository::insertPollOption($pollId, 'A', 0, '2026-01-01 00:00:00');
        $optionB = PostRepository::insertPollOption($pollId, 'B', 1, '2026-01-01 00:00:00');
        PostRepository::insertPollVote($pollId, $optionA, 7, 10, '2026-01-01 00:00:00');
        $pollRow = $this->pdo()->query('SELECT * FROM polls WHERE id = ' . $pollId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('问题', $pollRow['question']);
        $this->assertSame(0, (int)$pollRow['is_anonymous'], 'is_anonymous 固定写 0');
        $this->assertSame([$optionA, $optionB], array_column($this->rows('poll_options'), 'id'));
        PostRepository::deletePollVote($pollId, 7, $optionA);
        $this->assertSame([], $this->rows('poll_votes'), '撤回投票按 poll_id+user_id+option_id 精确删除');

        // 帖型白名单：三种合法 type 均可查，未知 type 返回 null 且不拼任意表名
        foreach ([[1, 'question', 'questions'], [2, 'team', 'teams'], [3, 'checkin', 'checkin_posts']] as [$postId, $type, $table]) {
            $this->seed('posts', ['id' => $postId, 'user_id' => 9, 'post_type' => $type, 'status' => 1]);
            $extra = ['post_id' => $postId];
            if ($table === 'questions') {
                $extra['question'] = 'Q';
            } elseif ($table === 'teams') {
                $extra['headline'] = 'T';
                $extra['need_count'] = 1;
                $extra['joined_count'] = 0;
            } else {
                $extra['goal'] = 'G';
                $extra['target_days'] = 7;
            }
            $this->seed($table, $extra);
        }
        $this->assertNull(PostRepository::findTypePostRow(1, 'unknown', 0));
        foreach ([[1, 'question'], [2, 'team'], [3, 'checkin']] as [$postId, $type]) {
            $row = PostRepository::findTypePostRow($postId, $type, 0);
            $this->assertNotNull($row);
            $this->assertSame($postId, (int)$row['id']);
            $this->assertArrayHasKey('post_type', $row);
        }
        $this->seed('posts', ['id' => 4, 'user_id' => 9, 'post_type' => 'question', 'status' => 0]);
        $this->seed('questions', ['post_id' => 4, 'question' => 'hidden']);
        $this->assertNull(PostRepository::findTypePostRow(4, 'question', 0), 'status=0 的未发布帖不得进入写接口守卫');
    }

    public function testQuestionPathsAndAcceptTransactionRollback(): void
    {
        $this->seed('posts', ['id' => 1, 'user_id' => 3, 'post_type' => 'question', 'status' => 1]);
        $this->seed('users', ['id' => 3, 'username' => 'q3', 'role' => 'user']);
        $this->seed('users', ['id' => 8, 'username' => 'q8', 'role' => 'user']);
        $this->seed('questions', ['id' => 1, 'post_id' => 1, 'question' => 'Q?', 'status' => 0, 'answer' => null]);
        $this->seed('question_answers', ['id' => 1, 'post_id' => 1, 'user_id' => 8, 'content' => 'A1', 'created_at' => '2026-01-01']);
        $this->seed('question_answers', ['id' => 2, 'post_id' => 1, 'user_id' => 3, 'content' => 'A2', 'created_at' => '2026-01-02']);

        $this->assertSame($this->originalFetch('SELECT question, status, answer, answered_by, answered_at FROM questions WHERE post_id = 1 LIMIT 1'), PostRepository::findQuestionByPostId(1));
        $this->assertSame($this->originalAll("SELECT a.id, a.user_id, a.content, a.is_accepted, a.accepted_at, a.created_at,
                    u.nickname, u.username, u.avatar, u.vip_level
             FROM question_answers a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.post_id = 1
             ORDER BY a.is_accepted DESC, a.id ASC
             LIMIT 50"), PostRepository::fetchQuestionAnswers(1));
        $this->assertSame($this->originalFetch('SELECT id FROM question_answers WHERE post_id = 1 AND user_id = 8 LIMIT 1'), PostRepository::findQuestionAnswerByUser(1, 8));
        $this->assertSame($this->originalFetch('SELECT id, user_id, content FROM question_answers WHERE id = 2 AND post_id = 1 LIMIT 1'), PostRepository::findQuestionAnswer(2, 1));
        $this->assertSame($this->originalFetch('SELECT status FROM questions WHERE post_id = 1 LIMIT 1'), PostRepository::findQuestionStatus(1));

        $newId = PostRepository::insertQuestionAnswer(1, 8, 'A3', '2026-01-03 00:00:00');
        $this->assertGreaterThan(2, $newId);
        PostRepository::updateQuestionLegacyAnswer(1, 'A3', 8, '2026-01-03 00:00:00');
        $this->assertSame('A3', $this->pdo()->query('SELECT answer FROM questions WHERE id = 1')->fetchColumn());

        PostRepository::acceptQuestionAnswer(1, 2, 'A2', 3, '2026-02-01 00:00:00', '2026-02-01 00:00:01');
        $this->assertSame('A2', $this->pdo()->query('SELECT answer FROM questions WHERE id = 1')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query('SELECT is_accepted FROM question_answers WHERE id = 2')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query('SELECT is_accepted FROM question_answers WHERE id = 1')->fetchColumn());

        // 异常回滚：第二条 UPDATE 触发 ABORT，三条写入全部撤销并原样抛错
        $this->seed('question_answers', ['id' => 999, 'post_id' => 1, 'user_id' => 8, 'content' => 'boom']);
        $this->pdo()->exec("CREATE TRIGGER fail_accept BEFORE UPDATE ON question_answers WHEN NEW.id = 999 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = $this->pdo()->query('SELECT answer FROM questions WHERE id = 1')->fetchColumn();
        try {
            PostRepository::acceptQuestionAnswer(1, 999, 'boom', 8, 'x', 'y');
            $this->fail('事务内异常应原样重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame($before, $this->pdo()->query('SELECT answer FROM questions WHERE id = 1')->fetchColumn(), '回滚后 questions 未被改动');
        $this->assertSame(0, (int)$this->pdo()->query('SELECT is_accepted FROM question_answers WHERE id = 1')->fetchColumn(), '回滚后旧采纳标记恢复');
    }

    public function testTeamAndCheckinPathsMatchOriginalSqlAndRollback(): void
    {
        $this->seed('posts', ['id' => 20, 'user_id' => 3, 'post_type' => 'team', 'status' => 1]);
        $this->seed('users', ['id' => 3, 'username' => 'u3', 'role' => 'user']);
        $this->seed('users', ['id' => 4, 'username' => 'u4', 'role' => 'user']);
        $this->seed('teams', ['id' => 9, 'post_id' => 20, 'headline' => '组队', 'need_count' => 3, 'joined_count' => 1]);
        $this->seed('team_members', ['id' => 1, 'team_id' => 9, 'post_id' => 20, 'user_id' => 3, 'joined_at' => '2026-01-01']);

        $this->assertSame($this->originalFetch('SELECT headline, need_count, joined_count, deadline, city, contact FROM teams WHERE post_id = 20 LIMIT 1'), PostRepository::findTeamByPostId(20));
        $this->assertSame($this->originalFetch('SELECT id, need_count, joined_count FROM teams WHERE post_id = 20 LIMIT 1'), PostRepository::findTeamForJoin(20));
        $this->assertSame($this->originalFetch('SELECT id, joined_count FROM teams WHERE post_id = 20 LIMIT 1'), PostRepository::findTeamForLeave(20));
        $this->assertSame($this->originalFetch('SELECT id FROM team_members WHERE post_id = 20 AND user_id = 3 LIMIT 1'), PostRepository::findTeamMember(20, 3));
        $this->assertNull(PostRepository::findTeamMember(20, 99));
        $this->assertSame($this->originalAll("SELECT m.user_id, m.joined_at, u.nickname, u.username, u.avatar
             FROM team_members m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.post_id = 20 ORDER BY m.id ASC LIMIT 30"), PostRepository::fetchTeamMembers(20));
        $this->assertSame(1, PostRepository::countTeamMembersForUser(20, 3));

        PostRepository::joinTeam(9, 20, 4, 1, '2026-02-01 00:00:00');
        $this->assertSame(2, (int)$this->pdo()->query('SELECT joined_count FROM teams WHERE id = 9')->fetchColumn());
        $this->assertCount(2, $this->rows('team_members'));

        PostRepository::leaveTeam(2, 9, 2);
        $this->assertSame(1, (int)$this->pdo()->query('SELECT joined_count FROM teams WHERE id = 9')->fetchColumn());
        PostRepository::leaveTeam(1, 0, 0);
        $this->assertSame([], $this->rows('team_members'), '队伍不存在时只删成员行');

        // 回滚：UPDATE teams 触发 ABORT，成员插入一并撤销
        $this->pdo()->exec("CREATE TRIGGER fail_join BEFORE UPDATE ON teams WHEN NEW.id = 9 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = count($this->rows('team_members'));
        try {
            PostRepository::joinTeam(9, 20, 5, 0, 'x');
            $this->fail('报名事务异常应重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertCount($before, $this->rows('team_members'), '回滚后无成员行残留');

        // 打卡
        $this->assertNull(PostRepository::findCheckinConfig(30));
        $this->seed('posts', ['id' => 30, 'user_id' => 3, 'post_type' => 'checkin', 'status' => 1]);
        $this->seed('checkin_posts', ['id' => 2, 'post_id' => 30, 'goal' => '跑步', 'target_days' => 30]);
        $this->assertSame($this->originalFetch('SELECT goal, target_days FROM checkin_posts WHERE post_id = 30 LIMIT 1'), PostRepository::findCheckinConfig(30));
        $this->assertSame(0, PostRepository::countMyCheckins(30, 3));
        $this->assertSame(0, PostRepository::countTodayCheckins(30, 3, '2026-01-01'));
        PostRepository::insertCheckinLog(30, 3, '跑了', '2026-01-01', '2026-01-01 08:00:00');
        PostRepository::insertCheckinLog(30, 3, null, '2026-01-01', '2026-01-01 09:00:00');
        $this->assertSame(2, PostRepository::countMyCheckins(30, 3));
        $this->assertSame(2, PostRepository::countTodayCheckins(30, 3, '2026-01-01'));
        $this->assertSame(0, PostRepository::countTodayCheckins(30, 3, '2026-01-02'));
    }

    // ==================== PostCommentTrait → PostRepository ====================

    public function testLikeAndPostLikesPathsMatchOriginalSqlAndPagination(): void
    {
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'post_type' => 'normal', 'status' => 1]);
        $this->seed('users', ['id' => 10, 'username' => 'u10', 'nickname' => 'n10', 'role' => 'user']);
        $this->seed('users', ['id' => 11, 'username' => 'u11', 'nickname' => 'n11', 'role' => 'user']);
        $this->seed('users', ['id' => 12, 'username' => 'u12', 'nickname' => 'n12', 'role' => 'user']);
        $this->assertSame(0, PostRepository::countPostLikers(1));
        $this->assertSame([], PostRepository::fetchPostLikerUsers(1));

        foreach ([[11, '2026-01-01'], [12, '2026-01-02'], [10, '2026-01-03']] as [$uid, $at]) {
            $this->seed('likes', ['post_id' => 1, 'user_id' => $uid, 'created_at' => $at]);
        }
        $this->assertSame($this->originalAll("SELECT u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id = 1 ORDER BY l.created_at ASC"), PostRepository::fetchPostLikerUsers(1));
        $this->assertSame(3, PostRepository::countPostLikers(1), 'COUNT(*) 与列表共用 l.post_id');
        foreach ([[0, 2], [1, 2], [0, 50]] as [$offset, $limit]) {
            $this->assertSame($this->originalLikerPage(1, $offset, $limit), PostRepository::fetchPostLikerPage(1, $offset, $limit));
        }
        $this->assertSame([10, 12, 11], array_column(PostRepository::fetchPostLikerPage(1, 0, 20), 'id'), 'created_at DESC 排序不变');
        $this->assertSame([], PostRepository::fetchPostLikerPage(999, 0, 20), '无点赞时分页为空');
    }

    public function testPinAndCommentLikePathsWithTransactionRollback(): void
    {
        $this->seed('users', ['id' => 11, 'username' => 'u11', 'role' => 'user']);
        $this->seed('users', ['id' => 12, 'username' => 'u12', 'role' => 'user']);
        $this->seed('users', ['id' => 13, 'username' => 'u13', 'status' => 0, 'role' => 'user']);
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'post_type' => 'normal', 'status' => 1]);
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 11, 'content' => 'c1', 'is_pinned' => 1]);
        $this->seed('comments', ['id' => 2, 'post_id' => 1, 'user_id' => 12, 'content' => 'c2', 'is_pinned' => 0]);

        $this->assertSame($this->originalFetch('SELECT id, user_id FROM posts WHERE id = 1 LIMIT 1'), array_intersect_key(PostRepository::findPostOwnerForPin(1), array_flip(['id', 'user_id'])));
        $this->assertArrayHasKey('post_type', PostRepository::findPostOwnerForPin(1));
        $this->assertSame($this->originalFetch('SELECT id, is_pinned FROM comments WHERE id = 2 AND post_id = 1 LIMIT 1'), PostRepository::findCommentForPin(2, 1));
        $this->assertSame($this->originalFetch('SELECT id, user_id FROM comments WHERE id = 2 AND post_id = 1 LIMIT 1'), PostRepository::findParentComment(2, 1));

        PostRepository::setPinnedComment(1, 2, true);
        $this->assertSame([0, 1], array_map('intval', array_column($this->pdo()->query('SELECT is_pinned FROM comments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'is_pinned')));
        PostRepository::setPinnedComment(1, 2, false);
        $this->assertSame([0, 0], array_map('intval', array_column($this->pdo()->query('SELECT is_pinned FROM comments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'is_pinned')));

        $this->pdo()->exec("CREATE TRIGGER fail_pin BEFORE UPDATE ON comments WHEN NEW.id = 2 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            PostRepository::setPinnedComment(1, 2, true);
            $this->fail('置顶事务异常应重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame(0, (int)$this->pdo()->query('SELECT is_pinned FROM comments WHERE id = 1')->fetchColumn(), '回滚后旧置顶状态恢复');

        // commentLike 路径
        $this->assertSame($this->originalFetch('SELECT id, post_id, user_id FROM comments WHERE id = 2 LIMIT 1'), PostRepository::findCommentBasic(2));
        $this->assertFalse(PostRepository::commentLikesTableExists(), 'SHOW TABLES 不受 SQLite 支持时按原 catch 语义降级');
        $this->assertNull(PostRepository::findCommentLike(2, 11));
        $liked = PostRepository::toggleCommentLike(2, 11, null, '2026-01-01 00:00:00');
        $this->assertTrue($liked);
        $likeRow = PostRepository::findCommentLike(2, 11);
        $this->assertSame(1, PostRepository::countCommentLikes(2));
        $likedAgain = PostRepository::toggleCommentLike(2, 11, (int)$likeRow['id'], '2026-01-02 00:00:00');
        $this->assertFalse($likedAgain);
        $this->assertSame(0, PostRepository::countCommentLikes(2));
        $this->assertSame($this->originalFetch('SELECT user_id FROM posts WHERE id = 1 LIMIT 1')['user_id'], PostRepository::findPostUserIdForNotify(1)['user_id']);
        $this->assertArrayHasKey('post_type', PostRepository::findPostUserIdForNotify(1));

        PostRepository::insertNotification([
            'user_id' => 11, 'sender_id' => 12, 'type' => 'comment_like',
            'post_id' => 1, 'comment_id' => 2, 'is_read' => 0, 'created_at' => '2026-01-01',
        ]);
        $this->assertSame('comment_like', $this->rows('notifications')[0]['type']);
        $this->assertSame($this->originalFetch('SELECT id FROM users WHERE username = "u11" AND status = 1 LIMIT 1'), PostRepository::findActiveUserByUsername('u11'));
        $this->assertNull(PostRepository::findActiveUserByUsername('nobody'));
        $this->assertSame(12, PostRepository::findActiveUserId(12));
        $this->assertSame(11, PostRepository::findActiveUserId(11));
        $this->assertNull(PostRepository::findActiveUserId(13), 'status=0 用户不参与 @提及');
    }

    public function testCommentCrudPathsAndCascadeRollback(): void
    {
        $this->seed('posts', ['id' => 1, 'user_id' => 10, 'post_type' => 'normal', 'status' => 1, 'comment_count' => 5]);
        $this->seed('users', ['id' => 10, 'username' => 'u10', 'nickname' => 'n10', 'role' => 'user']);
        $this->seed('users', ['id' => 11, 'username' => 'u11', 'nickname' => 'n11', 'role' => 'user']);
        $this->seed('comments', ['id' => 1, 'post_id' => 1, 'user_id' => 11, 'content' => 'root', 'created_at' => '2026-01-01']);
        $this->seed('comments', ['id' => 2, 'post_id' => 1, 'user_id' => 10, 'parent_id' => 1, 'content' => 'child']);
        $this->seed('comments', ['id' => 3, 'post_id' => 1, 'user_id' => 11, 'parent_id' => 2, 'content' => 'grandchild']);
        $this->seed('comment_likes', ['comment_id' => 3, 'user_id' => 10]);
        $this->seed('notifications', ['user_id' => 11, 'comment_id' => 3, 'type' => 'comment']);
        $this->seed('reports', ['target_type' => 'comment', 'target_id' => 3]);

        $this->assertSame($this->originalFetch('SELECT id, user_id, post_id FROM comments WHERE id = 1 LIMIT 1'), PostRepository::findCommentForDelete(1));
        $this->assertSame($this->originalAll('SELECT id FROM comments WHERE parent_id IN (1)'), PostRepository::fetchCommentChildIds([1]));
        $this->assertSame($this->originalAll('SELECT id FROM comments WHERE parent_id IN (2, 3)'), PostRepository::fetchCommentChildIds([2, 3]));
        $this->assertSame([], PostRepository::fetchCommentChildIds([]));

        $newId = PostRepository::insertComment([
            'post_id' => 1, 'user_id' => 11, 'parent_id' => null, 'content' => 'new', 'created_at' => '2026-02-01',
        ]);
        PostRepository::incrementPostCommentCount(1);
        $this->assertSame(6, (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 1')->fetchColumn());
        $this->assertSame($this->originalCommentWithUser($newId), PostRepository::findCommentWithUser($newId));
        $this->assertSame($this->originalFetch('SELECT p.user_id, u.username, u.nickname, u.avatar
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id = 1 AND p.status = 1 LIMIT 1'), array_intersect_key(
                PostRepository::findPostAuthorWithUser(1),
                array_flip(['user_id', 'username', 'nickname', 'avatar'])
            ));

        PostRepository::deleteCommentCascade(1, [1, 2, 3]);
        $this->assertSame([$newId], array_map('intval', array_column($this->rows('comments'), 'id')), '仅删除 BFS 收集到的子树，新评论保留');
        $this->assertSame([], $this->rows('comment_likes'));
        $this->assertSame([], $this->rows('notifications'));
        $this->assertSame([], $this->rows('reports'), 'target_type=comment 的报告随评论清理');
        $this->assertSame(3, (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 1')->fetchColumn(), '6 - 3 条评论，GREATEST 保底');

        // 回滚：DELETE comments 触发 ABORT，前面的计数与关联删除一并撤销
        $this->seed('comments', ['id' => 999, 'post_id' => 1, 'user_id' => 11, 'content' => 'boom']);
        $this->seed('comment_likes', ['comment_id' => 999, 'user_id' => 10]);
        $this->pdo()->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON comments WHEN OLD.id = 999 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $beforeCount = (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 1')->fetchColumn();
        try {
            PostRepository::deleteCommentCascade(1, [999]);
            $this->fail('删除事务异常应重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame($beforeCount, (int)$this->pdo()->query('SELECT comment_count FROM posts WHERE id = 1')->fetchColumn());
        $this->assertCount(1, $this->rows('comment_likes'), '回滚后关联行恢复');
    }

    // ==================== AdminCircleController → CircleRepository ====================

    public function testAdminCircleListAndMemberListMatchOriginalSql(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'owner', 'nickname' => '圈主', 'role' => 'user']);
        foreach ([
            [1, '运动圈', 'public'], [2, '摄影圈', 'public'],
            [3, '私密圈', 'private'], [4, '下架圈', 'public'],
        ] as [$id, $name, $visibility]) {
            $this->seed('circles', [
                'id' => $id, 'name' => $name, 'slug' => 'c' . $id, 'description' => 'desc ' . $name,
                'owner_id' => 1, 'visibility' => $visibility, 'status' => $id === 4 ? 0 : 1,
                'created_at' => '2026-01-0' . $id,
            ]);
        }
        foreach ([['', 0, 20], ['摄影', 0, 20], ['', 1, 1], ['无匹配', 0, 20]] as [$kw, $offset, $limit]) {
            $this->assertSame($this->originalAdminCircleList($kw, $offset, $limit), CircleRepository::fetchAdminCircleList($kw, $offset, $limit));
        }
        $this->assertSame(4, CircleRepository::countAdminCircles(''), '后台列表取全部状态，不套公开可见性');
        $this->assertSame(1, CircleRepository::countAdminCircles('摄影'));
        $this->assertSame(0, CircleRepository::countAdminCircles('无匹配'));

        foreach ([[1, 'owner'], [2, 'member'], [3, 'admin']] as [$uid, $role]) {
            $this->seed('circle_members', ['circle_id' => 1, 'user_id' => $uid, 'role' => $role, 'joined_at' => '2026-01-0' . $uid]);
            if ($uid !== 1) {
                $this->seed('users', ['id' => $uid, 'username' => 'm' . $uid, 'nickname' => 'n' . $uid, 'role' => 'user']);
            }
        }
        $this->seed('users', ['id' => 9, 'username' => 'm9', 'nickname' => 'n9', 'role' => 'user']);
        $this->seed('circle_members', ['circle_id' => 1, 'user_id' => 9, 'role' => 'member', 'joined_at' => '2026-01-09', 'status' => 2]);
        foreach ([[0, 50], [1, 2]] as [$offset, $limit]) {
            $this->assertSame($this->originalAdminMembers(1, $offset, $limit), CircleRepository::fetchAdminMembers(1, $offset, $limit));
        }
        $this->assertSame([1, 3, 2, 9], array_column(CircleRepository::fetchAdminMembers(1, 0, 50), 'user_id'), 'FIELD(owner,admin,member) 排序');
        $this->assertSame(4, CircleRepository::countAdminMembers(1), '后台成员不分 status，与公开列表口径不同');
        $this->assertSame(1, CircleRepository::countUsersById(1));
        $this->assertSame(0, CircleRepository::countUsersById(99));
        $this->assertNotNull(CircleRepository::findCircleIdForAdmin(1));
    }

    public function testAdminPendingRequestsKeepReviewerIdAndDoNotChangeFrontendShape(): void
    {
        foreach ([30, 31] as $uid) {
            $this->seed('users', ['id' => $uid, 'username' => 'applicant' . $uid, 'nickname' => 'n' . $uid, 'avatar' => '/a.jpg', 'role' => 'user']);
        }
        $this->seed('users', ['id' => 99, 'username' => 'reviewer', 'nickname' => 'r', 'role' => 'admin']);
        $this->seed('circle_join_requests', [
            'id' => 1, 'circle_id' => 1, 'user_id' => 30, 'status' => 'pending', 'message' => '想加入',
            'created_at' => '2026-01-01 00:00:00', 'reviewed_at' => '2026-01-02 00:00:00', 'reviewer_id' => 99,
        ]);
        $this->seed('circle_join_requests', [
            'id' => 2, 'circle_id' => 1, 'user_id' => 31, 'status' => 'pending', 'message' => '你好',
            'created_at' => '2026-01-03 00:00:00',
        ]);
        $this->seed('circle_join_requests', [
            'id' => 3, 'circle_id' => 1, 'user_id' => 30, 'status' => 'approved', 'reviewer_id' => 99,
        ]);

        // 与 HEAD:460 原始 SQL 逐字段对照（含 reviewer_id、字段顺序、排序、分页）
        foreach ([[0, 20], [1, 1], [0, 1]] as [$offset, $limit]) {
            $this->assertSame(
                $this->originalAdminPendingRequests(1, $offset, $limit),
                CircleRepository::fetchAdminPendingRequests(1, $offset, $limit)
            );
        }
        $rows = CircleRepository::fetchAdminPendingRequests(1, 0, 20);
        $this->assertSame(['id', 'user_id', 'status', 'message', 'created_at', 'reviewed_at', 'reviewer_id', 'username', 'nickname', 'avatar'], array_keys($rows[0]));
        $this->assertNull($rows[0]['reviewer_id'], '未审核申请的 reviewer_id 保持 NULL');
        $this->assertSame(99, (int)$rows[1]['reviewer_id'], '后台响应必须带 reviewer_id 原值');
        $this->assertSame([2, 1], array_column($rows, 'id'), 'ORDER BY r.id DESC 不变');
        $this->assertSame(2, CircleRepository::countPendingRequests(1));
        $this->assertSame([], CircleRepository::fetchAdminPendingRequests(999, 0, 20), '无匹配圈子返回空列表');

        // 前台示范批次的查询形状不得被反向污染
        $frontendRows = CircleRepository::fetchPendingRequests(1, 0, 20);
        $this->assertArrayNotHasKey('reviewer_id', $frontendRows[0], 'CircleController 的待审列表仍不含 reviewer_id');
        $this->assertSame($this->originalAll(
            "SELECT r.id, r.user_id, r.status, r.message, r.created_at, r.reviewed_at,
                    u.username, u.nickname, u.avatar
             FROM circle_join_requests r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.circle_id = 1 AND r.status = 'pending'
             ORDER BY r.id DESC
             LIMIT 0, 20"
        ), $frontendRows);
    }

    public function testAdminCircleWriteTransactionsSuccessAndRollback(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'u1', 'role' => 'user']);
        $this->seed('users', ['id' => 2, 'username' => 'u2', 'role' => 'user']);
        $this->seed('circles', ['id' => 10, 'name' => '旧圈', 'owner_id' => 1, 'member_count' => 1, 'status' => 1]);
        $this->seed('circle_members', ['circle_id' => 10, 'user_id' => 1, 'role' => 'owner', 'joined_at' => '2026-01-01']);
        $this->seed('circle_members', ['circle_id' => 10, 'user_id' => 2, 'role' => 'member', 'joined_at' => '2026-01-02']);

        $this->assertSame(0, CircleRepository::saveAdminCircle(999, '不存在', '', '', 1, 1, 'free', 'all', 'public', 'slug', 'now'));

        $newId = CircleRepository::saveAdminCircle(0, '新圈', 'd', '/c.jpg', 1, 1, 'approval', 'owner', 'public', 'slug-x', '2026-03-01 00:00:00');
        $this->assertGreaterThan(10, $newId);
        $this->assertSame('owner', $this->pdo()->query('SELECT role FROM circle_members WHERE circle_id = ' . $newId)->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query('SELECT member_count FROM circles WHERE id = ' . $newId)->fetchColumn());

        CircleRepository::saveAdminCircle(10, '改名', 'd', '', 2, 1, 'free', 'all', 'public', 'unused', '2026-03-02 00:00:00');
        $this->assertSame('改名', $this->pdo()->query('SELECT name FROM circles WHERE id = 10')->fetchColumn());
        $this->assertSame('owner', $this->pdo()->query('SELECT role FROM circle_members WHERE circle_id = 10 AND user_id = 2')->fetchColumn());
        $this->assertSame('member', $this->pdo()->query('SELECT role FROM circle_members WHERE circle_id = 10 AND user_id = 1')->fetchColumn());

        CircleRepository::removeAdminMember(10, 2, '2026-03-03 00:00:00');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT member_count FROM circles WHERE id = 10')->fetchColumn());

        CircleRepository::transferAdminCircleOwner(10, 1, 1, 2, '2026-03-04 00:00:00');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT owner_id FROM circles WHERE id = 10')->fetchColumn());
        $this->assertSame('owner', $this->pdo()->query('SELECT role FROM circle_members WHERE circle_id = 10 AND user_id = 1')->fetchColumn());

        CircleRepository::setCircleLimited(10, 1, '2026-03-05 00:00:00');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT limited FROM circles WHERE id = 10')->fetchColumn());
        CircleRepository::setCircleRemoved(10, '2026-03-05 00:00:00', '2026-03-05 00:00:00');
        $this->assertNotNull($this->pdo()->query('SELECT removed_at FROM circles WHERE id = 10')->fetchColumn());
        CircleRepository::setCircleRemoved(10, null, '2026-03-06 00:00:00');
        $this->assertNull($this->pdo()->query('SELECT removed_at FROM circles WHERE id = 10')->fetchColumn());

        $this->seed('circle_join_requests', ['id' => 1, 'circle_id' => 10, 'user_id' => 3, 'status' => 'pending']);
        CircleRepository::approveAdminJoinRequest(10, 3, 1, 1, '2026-03-07 00:00:00');
        $this->assertSame('approved', $this->pdo()->query('SELECT status FROM circle_join_requests WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int)$this->pdo()->query('SELECT member_count FROM circles WHERE id = 10')->fetchColumn());
        CircleRepository::markRequestRejected(1, 1);
        $this->assertSame('rejected', $this->pdo()->query('SELECT status FROM circle_join_requests WHERE id = 1')->fetchColumn());

        CircleRepository::deleteAdminCircle(10);
        $this->assertSame([], $this->pdo()->query('SELECT * FROM circles WHERE id = 10')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([], $this->pdo()->query('SELECT * FROM circle_members WHERE circle_id = 10')->fetchAll(PDO::FETCH_ASSOC));

        // 回滚：UPDATE circles 触发 ABORT，成员写入一并撤销
        $this->seed('circles', ['id' => 10, 'name' => '回滚圈', 'owner_id' => 1, 'member_count' => 0, 'status' => 1]);
        $this->pdo()->exec("CREATE TRIGGER fail_circle BEFORE UPDATE ON circles WHEN NEW.id = 10 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = count($this->rows('circle_members'));
        try {
            CircleRepository::approveAdminJoinRequest(10, 8, 1, 99, 'x');
            $this->fail('审批事务异常应重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertCount($before, $this->rows('circle_members'), '回滚后成员行未落库');
    }

    // ==================== AppController → AppRepository ====================

    public function testAppSettingsVersionPushAndCategoryPaths(): void
    {
        $this->seed('settings', ['key' => 'site_name', 'value' => '我圈']);
        $settingsRows = AppRepository::fetchSettings();
        $this->assertSame($this->originalAll('SELECT `key`, `value` FROM settings'), $settingsRows);
        $map = [];
        foreach ($settingsRows as $row) {
            $map[$row['key']] = $row['value'];
        }
        $this->assertSame('我圈', $map['site_name']);

        $this->seed('app_versions', [
            'platform' => 'android', 'version_code' => 10, 'version_name' => '1.0',
            'update_type' => 1, 'status' => 1, 'changelog' => 'a',
        ]);
        $this->seed('app_versions', [
            'platform' => 'android', 'version_code' => 20, 'version_name' => '2.0',
            'update_type' => 0, 'status' => 1, 'changelog' => 'b',
        ]);
        $this->seed('app_versions', [
            'platform' => 'android', 'version_code' => 99, 'version_name' => 'x', 'status' => 0,
        ]);
        $this->assertSame($this->originalFetch("SELECT platform, version_code, version_name, update_type, changelog, download_url
                 FROM app_versions
                 WHERE platform = 'android' AND status = 1
                 ORDER BY version_code DESC LIMIT 1"), AppRepository::findLatestVersion('android'));
        $this->assertSame(20, (int)AppRepository::findLatestVersion('android')['version_code']);
        $this->assertNull(AppRepository::findLatestVersion('ios'));

        $this->assertNull(AppRepository::findPushToken(7, 'unipush', 't'));
        $newId = AppRepository::insertPushToken([
            'user_id' => 7, 'provider' => 'unipush', 'token' => 'token-1234567890',
            'device_id' => 'dev-1', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame($this->originalFetch("SELECT id FROM push_tokens WHERE user_id = 7 AND provider = 'unipush' AND token = 'token-1234567890' LIMIT 1"), AppRepository::findPushToken(7, 'unipush', 'token-1234567890'));
        AppRepository::updatePushToken($newId, '2026-02-01', 'dev-2');
        $this->assertSame('dev-2', $this->pdo()->query('SELECT device_id FROM push_tokens WHERE id = ' . $newId)->fetchColumn());

        $this->seed('categories', ['id' => 2, 'name' => '摄影', 'sort_order' => 2, 'status' => 1]);
        $this->seed('categories', ['id' => 1, 'name' => '运动', 'sort_order' => 1, 'status' => 1]);
        $this->seed('categories', ['id' => 3, 'name' => '隐藏', 'sort_order' => 0, 'status' => 0]);
        $this->assertSame($this->originalAll('SELECT id, parent_id, name, sort_order
                 FROM categories WHERE status = 1
                 ORDER BY sort_order ASC, id ASC'), AppRepository::fetchActiveCategories());
        $this->assertSame([1, 2], array_column(AppRepository::fetchActiveCategories(), 'id'));
    }

    public function testWxLoginPathsMatchOriginalSql(): void
    {
        $this->seed('users', [
            'id' => 1, 'username' => 'wx_a', 'nickname' => 'A', 'status' => 1,
            'token_version' => 1, 'oauth_provider' => 'wechat_mini', 'oauth_id' => 'union-1',
        ]);
        $this->seed('users', ['id' => 2, 'username' => 'wx_b', 'status' => 0]);
        $this->seed('oauth_accounts', [
            'id' => 1, 'user_id' => 1, 'provider' => 'wechat_app', 'oauth_id' => 'open-1',
            'raw_data' => '{"unionid":"union-1"}', 'access_token' => 'old',
        ]);

        $this->assertSame($this->originalFetch("SELECT id, user_id FROM oauth_accounts WHERE provider = 'wechat_app' AND oauth_id = 'open-1' LIMIT 1"), AppRepository::findOauthByProviderId('wechat_app', 'open-1'));
        $this->assertNull(AppRepository::findOauthByProviderId('wechat_app', 'not-exist'));
        $this->assertSame($this->originalFetch("SELECT id, user_id FROM oauth_accounts
                 WHERE provider IN ('wechat_app','wechat_mini')
                   AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.unionid')) = 'union-1'
                 ORDER BY id ASC LIMIT 1"), AppRepository::findOauthByUnionid('union-1'));
        $this->assertNotNull(AppRepository::findActiveUserById(1));
        $this->assertNull(AppRepository::findActiveUserById(2), 'status=0 用户不可登录');

        AppRepository::updateOauthAccount(1, 'new-token', '{"unionid":"union-1"}', '2026-01-01');
        $this->assertSame('new-token', $this->pdo()->query('SELECT access_token FROM oauth_accounts WHERE id = 1')->fetchColumn());
        AppRepository::touchUserLogin(1, '2026-01-01 10:00:00');
        $this->assertSame('2026-01-01 10:00:00', $this->pdo()->query('SELECT last_login FROM users WHERE id = 1')->fetchColumn());

        $this->assertSame($this->originalFetch("SELECT id FROM users WHERE oauth_provider IN ('wechat_app','wechat_mini') AND oauth_id = 'union-1' LIMIT 1"), AppRepository::findUserByOauthId('union-1'));
        $this->assertSame($this->originalFetch('SELECT id, username, nickname, avatar, cover, role, email, bio, token_version, created_at
                 FROM users WHERE id = 1'), AppRepository::findUserById(1));
        $this->assertTrue(AppRepository::usernameExists('wx_a'));
        $this->assertFalse(AppRepository::usernameExists('nobody'));

        $userId = AppRepository::insertUser([
            'username' => 'wx_new', 'status' => 1, 'role' => 'user',
            'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);
        $this->assertGreaterThan(2, $userId);
        $oauthId = AppRepository::insertOauthAccount([
            'user_id' => $userId, 'provider' => 'wechat_app', 'oauth_id' => 'open-2',
            'access_token' => 't', 'raw_data' => '{}',
            'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);
        $this->assertGreaterThan(1, $oauthId);
        $this->assertSame($userId, (int)$this->pdo()->query('SELECT user_id FROM oauth_accounts WHERE id = ' . $oauthId)->fetchColumn());
    }

    public function testDevicePathsAndCrashLogWithTransactionRollback(): void
    {
        $this->seed('users', ['id' => 7, 'username' => 'u7', 'token_version' => 3]);
        $this->assertSame([], AppRepository::fetchActiveRefreshTokens(7));
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->seed('refresh_tokens', [
            'id' => 1, 'user_id' => 7, 'device_id' => 'dev-a', 'revoked' => 0,
            'last_used_at' => '2026-01-01', 'expires_at' => $future,
        ]);
        $this->seed('refresh_tokens', [
            'id' => 2, 'user_id' => 7, 'device_id' => 'dev-b', 'revoked' => 1,
            'last_used_at' => '2026-01-02', 'expires_at' => $future,
        ]);
        $rows = AppRepository::fetchActiveRefreshTokens(7);
        $this->assertSame($this->originalAll("SELECT id, device_id, user_agent, ip, created_at, last_used_at, expires_at
             FROM refresh_tokens
             WHERE user_id = 7 AND revoked = 0 AND expires_at > NOW()
             ORDER BY last_used_at DESC, id DESC
             LIMIT 20"), $rows);
        $this->assertSame([1], array_column($rows, 'id'), 'revoked=1 的设备不返回');

        AppRepository::revokeDevices(7, 'device', 'dev-a', 3, '2026-02-01 00:00:00');
        $this->assertSame(1, (int)$this->pdo()->query('SELECT revoked FROM refresh_tokens WHERE id = 1')->fetchColumn());
        $this->assertSame(4, (int)$this->pdo()->query('SELECT token_version FROM users WHERE id = 7')->fetchColumn());

        $this->seed('refresh_tokens', ['id' => 3, 'user_id' => 7, 'device_id' => 'dev-c', 'revoked' => 0, 'expires_at' => $future]);
        $this->seed('refresh_tokens', ['id' => 4, 'user_id' => 7, 'device_id' => 'dev-a', 'revoked' => 0, 'expires_at' => $future]);
        AppRepository::revokeDevices(7, 'all_with_current', 'dev-c', 4, '2026-02-02 00:00:00');
        $this->assertSame([1, 1, 0, 1], array_map('intval', array_column(
            $this->pdo()->query('SELECT revoked FROM refresh_tokens ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'revoked'
        )));

        $this->pdo()->exec("CREATE TRIGGER fail_revoke BEFORE UPDATE ON users WHEN NEW.id = 7 BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = (int)$this->pdo()->query('SELECT token_version FROM users WHERE id = 7')->fetchColumn();
        $revokedBefore = implode(',', array_column(
            $this->pdo()->query('SELECT revoked FROM refresh_tokens ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'revoked'
        ));
        try {
            AppRepository::revokeDevices(7, 'all', '', 1, 'x');
            $this->fail('kick 事务异常应重抛');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame($before, (int)$this->pdo()->query('SELECT token_version FROM users WHERE id = 7')->fetchColumn());
        $this->assertSame($revokedBefore, implode(',', array_column(
            $this->pdo()->query('SELECT revoked FROM refresh_tokens ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'revoked'
        )), '回滚后 revoked 标记恢复');

        $crashId = AppRepository::insertCrashLog([
            'user_id' => 7, 'event' => 'error', 'platform' => 'android', 'client' => 'app',
            'route' => '/pages/index', 'message' => 'boom', 'ip' => '127.0.0.1',
        ]);
        $this->assertGreaterThan(0, $crashId);
        $this->assertSame('error', $this->rows('app_crash_logs')[0]['event']);
    }

    // ==================== 迁移前原始 SQL 快照 ====================

    private function originalFetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function originalAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalLikerPage(int $postId, int $offset, int $limit): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.vip_level, u.avatar_frame,
                    (SELECT COUNT(*) FROM follows f WHERE f.following_id = u.id) AS follower_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1) AS post_count
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id = ?
             ORDER BY l.created_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCommentWithUser(int $commentId): ?array
    {
        return $this->originalFetch(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.images, c.is_ai, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.id = ?",
            [$commentId]
        );
    }

    private function originalAdminCircleList(string $keyword, int $offset, int $limit): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR description LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        $stmt = $this->pdo()->prepare(
            "SELECT c.*, u.username AS owner_username, u.nickname AS owner_nickname
             FROM circles c
             LEFT JOIN users u ON c.owner_id = u.id
             {$where}
             ORDER BY c.id DESC
             LIMIT ?, ?"
        );
        $stmt->execute(array_merge($params, [$offset, $limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalAdminMembers(int $circleId, int $offset, int $limit): array
    {
        return $this->originalAll(
            "SELECT cm.user_id, cm.role, cm.joined_at,
                    u.username, u.nickname, u.avatar, u.role AS user_role
             FROM circle_members cm
             INNER JOIN users u ON cm.user_id = u.id
             WHERE cm.circle_id = ?
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.joined_at ASC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }

    /** HEAD:460 后台待审列表原始 SQL（逐字保留 r.reviewer_id） */
    private function originalAdminPendingRequests(int $circleId, int $offset, int $limit): array
    {
        return $this->originalAll(
            "SELECT r.id, r.user_id, r.status, r.message, r.created_at, r.reviewed_at, r.reviewer_id,
                    u.username, u.nickname, u.avatar
             FROM circle_join_requests r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.circle_id = ? AND r.status = 'pending'
             ORDER BY r.id DESC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }
}
