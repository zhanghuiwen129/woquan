<?php
/**
 * 全新安装终态 DDL 基线护栏。
 *
 * 快速路径用 FreshInstallSchema 一次建出终态库，若基线漏表 / 带数据 / 版本落后，
 * 新装站点会静默残缺。本测试不连数据库，纯静态守住基线的最关键不变量。
 */
declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\FreshInstallSchema;
use Hublog\Core\MigrationContract;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/core/MigrationContract.php';
require_once dirname(__DIR__) . '/core/FreshInstallSchema.php';

final class FreshInstallSchemaTest extends TestCase
{
    /** 基线版本必须等于 Migration::$currentVersion（从源码读，避免把类拖进测试） */
    public function testBaselineVersionMatchesMigration(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__) . '/core/Migration.php');
        $this->assertTrue(
            preg_match('/private static int \$currentVersion = (\d+);/', $src, $m) === 1,
            '无法从 Migration.php 读取 $currentVersion'
        );
        $this->assertSame((int)$m[1], FreshInstallSchema::SCHEMA_VERSION, '基线版本落后于迁移版本，请重跑 scripts/build_fresh_schema.php');
    }

    /** 基线与终态契约表集合必须完全一致，不多不少 */
    public function testBaselineCoversExactlyContractTables(): void
    {
        $tables = array_keys(FreshInstallSchema::STATEMENTS);
        sort($tables);
        $expected = MigrationContract::REQUIRED_TABLES;
        sort($expected);
        $this->assertSame($expected, $tables, '基线与契约表集合不一致，请在终态库重跑 scripts/build_fresh_schema.php');
    }

    /** 基线只能含纯 DDL，不能把开发库数据 / 自增指针 / MySQL 专有全文索引带进去 */
    public function testBaselineContainsOnlyPortableDdl(): void
    {
        foreach (FreshInstallSchema::STATEMENTS as $table => $sql) {
            $this->assertTrue(
                str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS `'),
                "{$table} 基线不是 IF NOT EXISTS 建表语句"
            );
            $this->assertFalse(
                stripos($sql, 'AUTO_INCREMENT=') !== false,
                "{$table} 基线带 AUTO_INCREMENT 指针，请把开发库自增带进去的问题修掉"
            );
            $this->assertFalse(
                stripos($sql, 'FULLTEXT') !== false,
                "{$table} 基线带 FULLTEXT KEY（MariaDB 不支持 ngram），应由 Migration::ensurePostsFulltextIndex() 创建"
            );
            $this->assertFalse(
                stripos($sql, 'INSERT INTO') !== false,
                "{$table} 基线带数据，种子必须留在迁移代码里"
            );
        }
    }
}
