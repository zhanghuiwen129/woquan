<?php
/**
 * 数据层行为护栏：真实执行 SQL，验证参数绑定、标识符白名单、无条件删除拦截、事务。
 *
 * 报告里「PDO 真预处理 + 字段名白名单」原先只有源码文本断言；
 * 这里用 SQLite 内存库把每条声明都变成一次真实查询。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Database;
use Hublog\Core\Exception\HublogException;

final class DatabaseBehaviorTest extends BehaviorTestCase
{
    public function testInsertReallyPersistsAndReturnsLastInsertId(): void
    {
        $id = Database::insert('phone_codes', [
            'phone'      => '13800138000',
            'code'       => '654321',
            'scene'      => 'login',
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
            'sent_ip'    => '203.0.113.7',
        ]);

        $this->assertGreaterThan(0, $id);
        $row = Database::fetchOne('SELECT * FROM `phone_codes` WHERE `id` = ?', [$id]);
        $this->assertSame('654321', $row['code']);
        $this->assertSame('203.0.113.7', $row['sent_ip']);
    }

    public function testPreparedStatementsTreatInjectionPayloadAsLiteralData(): void
    {
        $payload = "x'; DROP TABLE users; --";
        Database::insert('users', ['username' => $payload, 'role' => 'user']);

        $row = Database::fetchOne('SELECT `username` FROM `users` WHERE `username` = ?', [$payload]);
        $this->assertSame($payload, $row['username'], '注入串必须作为普通字符串存下来');

        $stillThere = Database::fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
        );
        $this->assertNotNull($stillThere, 'users 表不得被删');
    }

    public function testIdentifierWhitelistRejectsIllegalTableAndColumnNames(): void
    {
        $this->assertFalse(Database::validateName('users; DROP'));
        $this->assertFalse(Database::validateName('a-b'));
        $this->assertTrue(Database::validateName('good_name_1'));

        try {
            Database::insert('users; DROP', ['username' => 'x']);
            $this->fail('非法表名必须被拒');
        } catch (HublogException $e) {
            $this->assertStringContainsString('非法表名', $e->getMessage());
        }

        try {
            Database::insert('users', ['bad-field' => 'x']);
            $this->fail('非法字段名必须被拒');
        } catch (HublogException $e) {
            $this->assertStringContainsString('非法字段名', $e->getMessage());
        }
    }

    public function testUnconditionalDeleteIsBlocked(): void
    {
        Database::insert('users', ['username' => 'keep_me']);
        Database::insert('users', ['username' => 'keep_me_too']);

        foreach (['', '1=1', '  1 = 1  '] as $where) {
            try {
                Database::delete('users', $where);
                $this->fail("无条件删除条件 '{$where}' 必须被拒");
            } catch (HublogException $e) {
                $this->assertStringContainsString('禁止无条件删除', $e->getMessage());
            }
        }

        $this->assertCount(2, $this->rows('users'), '被拦截的删除不得影响数据');
    }

    public function testConditionalDeleteAndUpdateOnlyTouchTargetRows(): void
    {
        Database::insert('users', ['username' => 'alice']);
        Database::insert('users', ['username' => 'bob']);

        $changed = Database::update('users', ['role' => 'vip'], '`username` = ?', ['alice']);
        $this->assertSame(1, $changed);

        $deleted = Database::delete('users', '`username` = ?', ['bob']);
        $this->assertSame(1, $deleted);

        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame('vip', $rows[0]['role']);
    }

    public function testTransactionRollbackReallyRevertsWrites(): void
    {
        Database::beginTransaction();
        Database::insert('users', ['username' => 'rolled_back']);
        Database::rollBack();

        $this->assertSame([], $this->rows('users'));

        Database::beginTransaction();
        Database::insert('users', ['username' => 'committed']);
        Database::commit();

        $this->assertCount(1, $this->rows('users'));
    }

    /**
     * Database::rollBack() 的无事务防御：catch 块里重复/过期回滚不得掩盖真实异常。
     *
     * 旧实现直接调 PDO::rollBack()，在无活动事务时抛 "There is no active transaction"
     * （SQLite / MySQL 均如此）；这条用例在旧实现上必然失败，是一条真正的回归护栏。
     */
    public function testRollBackWithoutActiveTransactionIsSilentNoOp(): void
    {
        // Arrange：内存库中没有活动事务，先写入一条已生效数据
        $this->assertFalse($this->pdo()->inTransaction());
        Database::insert('users', ['username' => 'no_active_tx']);

        // Act：无事务时回滚
        Database::rollBack();

        // Assert：静默返回，且不误伤任何数据
        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertCount(1, $this->rows('users'));
        $this->assertSame('no_active_tx', $this->rows('users')[0]['username']);
    }

    /** 上游已 commit 后再 rollBack：不得抛异常，也不能撤销已提交数据 */
    public function testRollBackAfterCommitLeavesCommittedDataIntact(): void
    {
        // Arrange：完成一笔真实提交
        Database::beginTransaction();
        Database::insert('users', ['username' => 'already_committed']);
        Database::commit();
        $this->assertFalse($this->pdo()->inTransaction());

        // Act：提交后再次回滚（旧实现会在此抛 PDOException）
        Database::rollBack();

        // Assert：已提交数据仍在
        $this->assertCount(1, $this->rows('users'));
        $this->assertSame('already_committed', $this->rows('users')[0]['username']);
    }

    /** 正常路径不能被短路保护破坏：活动事务内回滚必须真的撤销写入，且可重复调用 */
    public function testRollBackInActiveTransactionStillRevertsWritesAndIsIdempotent(): void
    {
        Database::beginTransaction();
        Database::insert('users', ['username' => 'must_be_reverted']);
        Database::rollBack();

        $this->assertSame([], $this->rows('users'));

        // 第二次回滚走短路分支，不应抛异常，也不应改变结果
        Database::rollBack();
        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertSame([], $this->rows('users'));
    }

    public function testQueryStatsGrowWithRealQueries(): void
    {
        $before = Database::getStats()['query_count'];
        Database::fetchAll('SELECT * FROM `settings`');
        Database::fetchOne('SELECT * FROM `settings` WHERE `key` = ?', ['sms_provider']);
        Database::fetchColumn('SELECT COUNT(*) FROM `users`');

        $after = Database::getStats()['query_count'];
        $this->assertGreaterThanOrEqual($before + 3, $after);
    }
}
