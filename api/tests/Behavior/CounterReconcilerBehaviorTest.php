<?php
/**
 * CounterReconciler 行为测试：用 SQLite 内存库验证冗余计数列对账逻辑。
 * 覆盖：漂移修正、零来源修正为 0、一致行不动、缺列/缺表跳过。
 */

declare(strict_types=1);

use Hublog\Core\CounterReconciler;
use PHPUnit\Framework\TestCase;

class CounterReconcilerBehaviorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `posts` (
    `id` INTEGER PRIMARY KEY,
    `comment_count` INTEGER NOT NULL DEFAULT 0,
    `like_count` INTEGER NOT NULL DEFAULT 0,
    `circle_id` INTEGER DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS `comments` (
    `id` INTEGER PRIMARY KEY,
    `post_id` INTEGER NOT NULL,
    `status` INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS `likes` (
    `id` INTEGER PRIMARY KEY,
    `post_id` INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS `circles` (
    `id` INTEGER PRIMARY KEY,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `post_count` INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS `circle_members` (
    `id` INTEGER PRIMARY KEY,
    `circle_id` INTEGER NOT NULL
);
SQL
        );
    }

    private function seedDrift(): void
    {
        // posts:
        //   id=1: comment_count 存储 5，实际 3 条评论；like_count 存储 2，实际 2 → 只修 comment
        //   id=2: 全 0，实际 0 → 不动
        //   id=3: comment_count 存储 0，实际 2 条评论；circle_id=10
        $this->pdo->exec("INSERT INTO `posts` VALUES (1, 5, 2, NULL)");
        $this->pdo->exec("INSERT INTO `posts` VALUES (2, 0, 0, NULL)");
        $this->pdo->exec("INSERT INTO `posts` VALUES (3, 0, 0, 10)");
        // comments：post 1 有 3 条，post 3 有 2 条
        $this->pdo->exec("INSERT INTO `comments` (post_id) VALUES (1),(1),(1),(3),(3)");
        // likes：post 1 有 2 条
        $this->pdo->exec("INSERT INTO `likes` (post_id) VALUES (1),(1)");
        // circles id=10：member_count 存 0，实际 1 成员；post_count 存 0，实际 1 条（post 3）
        $this->pdo->exec("INSERT INTO `circles` VALUES (10, 0, 0)");
        $this->pdo->exec("INSERT INTO `circle_members` (circle_id) VALUES (10)");
    }

    private function scalarOf(string $sql): int
    {
        return (int)$this->pdo->query($sql)->fetchColumn();
    }

    public function testReconcileFixesDrift(): void
    {
        $this->seedDrift();
        $report = CounterReconciler::reconcileAll($this->pdo);

        // posts.1 comment_count 5 -> 3
        $this->assertSame(3, $this->scalarOf('SELECT comment_count FROM posts WHERE id=1'));
        // posts.1 like_count 2 -> 2（不变）
        $this->assertSame(2, $this->scalarOf('SELECT like_count FROM posts WHERE id=1'));
        // posts.2 全 0 保持一致
        $this->assertSame(0, $this->scalarOf('SELECT comment_count FROM posts WHERE id=2'));
        // posts.3 comment_count 0 -> 2
        $this->assertSame(2, $this->scalarOf('SELECT comment_count FROM posts WHERE id=3'));
        // circles.10 member_count 0 -> 1
        $this->assertSame(1, $this->scalarOf('SELECT member_count FROM circles WHERE id=10'));
        // circles.10 post_count 0 -> 1（post 3 的 circle_id=10）
        $this->assertSame(1, $this->scalarOf('SELECT post_count FROM circles WHERE id=10'));

        // 修正总数：posts(1→3, 3→2) + circles(member 0→1, post 0→1) = 4 行
        $this->assertSame(4, $report['total_fixed']);
        $this->assertEmpty($report['skipped']);
    }

    public function testConsistentRowsNotTouched(): void
    {
        // 全部一致，不应有任何 UPDATE
        $this->pdo->exec("INSERT INTO `posts` VALUES (1, 1, 1, NULL)");
        $this->pdo->exec("INSERT INTO `comments` (post_id) VALUES (1)");
        $this->pdo->exec("INSERT INTO `likes` (post_id) VALUES (1)");
        $report = CounterReconciler::reconcileAll($this->pdo);
        $this->assertSame(0, $report['total_fixed']);
        $this->assertEmpty($report['skipped']);
    }

    public function testPendingCommentsAreNotCounted(): void
    {
        $this->pdo->exec("INSERT INTO `posts` VALUES (1, 2, 0, NULL)");
        $this->pdo->exec("INSERT INTO `comments` (`post_id`, `status`) VALUES (1, 1), (1, 0)");

        $report = CounterReconciler::reconcileAll($this->pdo);
        $this->assertSame(
            1,
            $this->scalarOf('SELECT comment_count FROM posts WHERE id=1'),
            '待审评论不得被对账刷回 comment_count'
        );
        $this->assertSame(1, $report['total_fixed']);
    }

    public function testMissingColumnIsSkippedNotFatal(): void
    {
        // 只建 posts（无 comment_count/like_count 列）和 comments，模拟老库缺列
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE `posts` (`id` INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE `comments` (`id` INTEGER PRIMARY KEY, `post_id` INTEGER NOT NULL)');

        $report = CounterReconciler::reconcileAll($pdo);
        // posts.* 两项应被跳过（缺列），不抛异常
        $this->assertSame(0, $report['total_fixed']);
        $this->assertNotEmpty($report['skipped']);
    }
}
