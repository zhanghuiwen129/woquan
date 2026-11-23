<?php
/**
 * 幂等唯一键 DDL 静态门禁行为回归（批次 7 / P1-4）
 *
 * 变异目标：门禁只检查索引名、不检查列清单时会漏掉「索引存在但列写错」的 mutant。
 * 这里直接向纯比对函数喂假 DDL，验证列不符被抓、列顺序/大小写差异被兼容，
 * 并顺带验证真实契约与迁移源码 DDL 当前是一致的。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\MigrationContract;

require_once dirname(__DIR__, 3) . '/scripts/UniqueIndexDdlChecker.php';
require_once dirname(__DIR__, 3) . '/scripts/MigrationContractScanner.php';

final class UniqueIndexDdlBehaviorTest extends BehaviorTestCase
{
    /** @param list<string> $cols @return list<array{table:string,index:string,cols:list<string>}> */
    private function spec(array $cols): array
    {
        return [['table' => 'recharge_orders', 'index' => 'uk_trade_no', 'cols' => $cols]];
    }

    public function testMatchingDdlPasses(): void
    {
        $sql = 'ALTER TABLE `recharge_orders` ADD UNIQUE KEY `uk_trade_no` (`trade_no`)';
        $this->assertSame([], \UniqueIndexDdlChecker::checkSql($sql, $this->spec(['trade_no'])));
    }

    public function testIndexNameMatchesButWrongColumnsIsReported(): void
    {
        $sql = 'ALTER TABLE `recharge_orders` ADD UNIQUE KEY `uk_trade_no` (`order_no`)';
        $issues = \UniqueIndexDdlChecker::checkSql($sql, $this->spec(['trade_no']));

        $this->assertCount(1, $issues, '索引名对但列不符必须被静态门禁抓到');
        $this->assertSame('columns_mismatch', $issues[0]['reason']);
        $this->assertSame('uk_trade_no', $issues[0]['index']);
        $this->assertSame(['trade_no'], $issues[0]['expected']);
        $this->assertSame(['order_no'], $issues[0]['actual']);
    }

    public function testMissingIndexIsReported(): void
    {
        $sql = 'ALTER TABLE `recharge_orders` ADD UNIQUE KEY `uk_order_no` (`order_no`)';
        $issues = \UniqueIndexDdlChecker::checkSql($sql, $this->spec(['trade_no']));
        $this->assertCount(1, $issues);
        $this->assertSame('index_missing', $issues[0]['reason']);
    }

    public function testColumnOrderAndCaseDoNotMatter(): void
    {
        $sql = 'CREATE TABLE `x` (`id` INT, UNIQUE KEY `uk_msg_client` (`CLIENT_MSG_ID`, `sender_id`))';
        $issues = \UniqueIndexDdlChecker::checkSql($sql, [[
            'table' => 'messages',
            'index' => 'uk_msg_client',
            'cols' => ['sender_id', 'client_msg_id'],
        ]]);
        $this->assertSame([], $issues, '集合一致时顺序与大小写差异应兼容');
    }

    public function testRealContractMatchesMigrationSources(): void
    {
        $root = self::root();
        $sql = '';
        foreach (\MigrationContractScanner::sources() as $rel) {
            $sql .= "\n" . \UniqueIndexDdlChecker::sqlLiterals((string)file_get_contents($root . '/' . $rel));
        }
        $issues = \UniqueIndexDdlChecker::checkSql($sql, MigrationContract::REQUIRED_UNIQUE_INDEXES);
        $this->assertSame([], $issues, '契约与迁移 DDL 漂移会阻断 CI');
    }
}
