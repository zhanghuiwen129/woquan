<?php
/**
 * 控制器内联 SQL 门禁自测回归（批次 7 / P1-3b）
 *
 * 门禁把 Database::rollBack() 这类事务控制调用误计为内联 SQL，导致
 * RealNameController 3 > 严格上限 0 而让 CI 恒红。这里直接执行门禁自带的
 * --self-test，验证 query 仍被计数、事务控制调用被跳过。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

final class InlineSqlGateBehaviorTest extends BehaviorTestCase
{
    public function testQueryIsCountedButTransactionControlIsNot(): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::root() . '/scripts/check-inline-sql.php')
            . ' --self-test 2>&1';
        $lines = [];
        $exit = 0;
        exec($cmd, $lines, $exit);
        $output = implode("\n", $lines);

        $this->assertSame(0, $exit, '门禁自测必须退出 0：' . $output);
        $this->assertStringContainsString('query=1 transaction_controls=0 -> OK', $output);
    }
}
