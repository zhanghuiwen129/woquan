<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\PluginManifest;
use Hublog\Core\PluginSchemaMigrator;
use PHPUnit\Framework\TestCase;

/**
 * 声明式迁移安全边界测试（E1-3 / Q2 / C8）
 *
 * 全部是纯逻辑校验，不连库：白名单类型、plugin_ 前缀、与 MigrationContract
 * 的表/列冲突、禁止字段与关键字。真实建表幂等在 Behavior 套件里验证。
 */
final class PluginSchemaMigratorTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $json = $dir . '/plugin.json';
            if (is_file($json)) {
                @unlink($json);
            }
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function testValidWhitelistSpecPasses(): void
    {
        $manifest = $this->manifest('ProbeOk', [
            'name' => 'ProbeOk',
            'required_tables' => [[
                'name' => 'plugin_probe_ok',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'unsigned' => true, 'auto_increment' => true],
                    ['name' => 'note', 'type' => 'varchar', 'length' => 128, 'nullable' => true],
                ],
                'primary_key' => 'id',
                'indexes' => [['name' => 'idx_note', 'columns' => ['note']]],
            ]],
            'required_columns' => [[
                'table' => 'plugin_probe_ok',
                'column' => ['name' => 'extra', 'type' => 'text'],
            ]],
        ]);
        $this->assertSame([], $manifest->validate());
        $this->assertSame([], PluginSchemaMigrator::validateAgainstContract($manifest));
    }

    /** 表名不得撞核心契约表 */
    public function testCoreTableConflictRejected(): void
    {
        $manifest = $this->manifest('ProbeCoreTable', [
            'name' => 'ProbeCoreTable',
            'required_tables' => [[
                'name' => 'users',
                'columns' => [['name' => 'note', 'type' => 'text']],
            ]],
        ]);
        $errors = implode(' ', PluginSchemaMigrator::validateAgainstContract($manifest));
        $this->assertStringContainsString('核心契约表同名', $errors);
    }

    /** 给核心表追加核心契约里已有的列 → 拒绝重定义 */
    public function testCoreColumnConflictRejected(): void
    {
        $manifest = $this->manifest('ProbeCoreColumn', [
            'name' => 'ProbeCoreColumn',
            'required_columns' => [[
                'table' => 'users',
                'column' => ['name' => 'status', 'type' => 'varchar', 'length' => 16],
            ]],
        ]);
        $errors = implode(' ', PluginSchemaMigrator::validateAgainstContract($manifest));
        $this->assertStringContainsString('核心契约列同名', $errors);
    }

    public function testTablePrefixRejected(): void
    {
        $manifest = $this->manifest('ProbePrefix', [
            'name' => 'ProbePrefix',
            'required_tables' => [[
                'name' => 'notes',
                'columns' => [['name' => 'note', 'type' => 'text']],
            ]],
        ]);
        $errors = implode(' ', $manifest->validate());
        $this->assertStringContainsString('plugin_', $errors);
        $this->assertStringContainsString('plugin_ 开头', implode(' ', PluginSchemaMigrator::validateAgainstContract($manifest)));
    }

    public function testIllegalTypeRejected(): void
    {
        $manifest = $this->manifest('ProbeType', [
            'name' => 'ProbeType',
            'required_tables' => [[
                'name' => 'plugin_probe_type',
                'columns' => [['name' => 'weird', 'type' => 'enum']],
            ]],
        ]);
        $this->assertStringContainsString(
            '不在白名单',
            implode(' ', PluginSchemaMigrator::validateAgainstContract($manifest))
        );
    }

    /** 禁止字段与 DDL 关键字双保险 */
    public function testForbiddenFieldsAndKeywordsRejected(): void
    {
        $manifest = $this->manifest('ProbeSql', [
            'name' => 'ProbeSql',
            'raw' => 'SELECT 1',
            'required_tables' => [[
                'name' => 'plugin_probe_sql',
                'columns' => [['name' => 'note', 'type' => 'varchar', 'length' => 32, 'comment' => 'TRUNCATE users']],
            ]],
        ]);
        $errors = implode(' ', $manifest->validate());
        $this->assertStringContainsString('不是允许的字段', $errors);
        $this->assertStringContainsString('禁止的 DDL 关键字', $errors);
        $this->assertStringContainsString(
            '禁止的 DDL 关键字',
            implode(' ', PluginSchemaMigrator::validateAgainstContract($manifest))
        );
    }

    /** @param array<string,mixed> $json */
    private function manifest(string $dirName, array $json): PluginManifest
    {
        $base = sys_get_temp_dir() . '/plugin_schema_' . bin2hex(random_bytes(6));
        @mkdir($base, 0775, true);
        $dir = $base . '/' . $dirName;
        @mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/plugin.json',
            (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->tempDirs[] = $dir;
        $this->tempDirs[] = $base;
        return PluginManifest::fromDir($dir);
    }
}
