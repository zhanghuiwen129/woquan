<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\PluginLifecycle;
use Hublog\Core\PluginManifest;
use Hublog\Core\PluginSchemaMigrator;

/**
 * QA 回归护栏：PluginSchemaMigrator 的两条 ADD COLUMN 路径曾因漏拼列名
 * 生成非法 SQL（ALTER TABLE t ADD COLUMN varchar(16)），导致 required_columns
 * 与半残库自愈 100% 失败。本文件锁定这两条路径 + Q3「追加列永不删」。
 */
final class PluginSchemaColumnAddBehaviorTest extends BehaviorTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `plugin_states` (
    `plugin_name`       TEXT PRIMARY KEY,
    `enabled`           INTEGER NOT NULL DEFAULT 1,
    `install_status`    TEXT NOT NULL DEFAULT 'installed',
    `installed_version` TEXT NOT NULL DEFAULT '',
    `last_error`        TEXT NOT NULL DEFAULT '',
    `installed_at`      TEXT DEFAULT NULL
);
CREATE TABLE `plugin_configs` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `plugin_name`  TEXT NOT NULL,
    `config_key`   TEXT NOT NULL,
    `config_value` TEXT,
    UNIQUE (`plugin_name`, `config_key`)
);
SQL);
    }

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

    /** required_columns：install 时真的把列加到目标表，并落 installed 状态 */
    public function testRequiredColumnIsAddedToExistingTable(): void
    {
        $dir = $this->writePlugin('AddColumnInstall', [
            'name' => 'AddColumnInstall',
            'required_columns' => [[
                'table' => 'users',
                'column' => ['name' => 'qa_added_col', 'type' => 'varchar', 'length' => 32],
            ]],
        ]);
        $result = PluginLifecycle::installFrom($dir);
        $this->assertSame('installed', $result['install_status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertContains('column:users.qa_added_col', $result['applied']);
        $this->assertContains('qa_added_col', $this->columnsOf('users'));
    }

    /** 半残表：表已存在但缺列时逐列补齐，且第二次 apply 全幂等 */
    public function testApplyAddsMissingColumnsToPartiallyCreatedTable(): void
    {
        // 先放一张只有 id 的半残表，触发 columnExists=false 的 ADD COLUMN 分支
        $this->pdo()->exec('CREATE TABLE `plugin_partial` (`id` INTEGER)');

        $dir = $this->writePlugin('HealPartial', [
            'name' => 'HealPartial',
            'required_tables' => [[
                'name' => 'plugin_partial',
                'columns' => [
                    ['name' => 'id', 'type' => 'int'],
                    ['name' => 'note', 'type' => 'varchar', 'length' => 64],
                ],
            ]],
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertTrue($manifest->valid());

        $first = PluginSchemaMigrator::apply($manifest);
        $this->assertContains('column:plugin_partial.note', $first);
        $this->assertContains('note', $this->columnsOf('plugin_partial'));
        $this->assertSame([], PluginSchemaMigrator::apply($manifest), '补齐后第二次应全幂等');
    }

    /** Q3：drop=true 只删声明内插件表，required_columns 追加列绝不删 */
    public function testUninstallDropKeepsAppendedColumn(): void
    {
        $dir = $this->writePlugin('KeepAppended', [
            'name' => 'KeepAppended',
            'drop_tables_on_uninstall' => true,
            'required_tables' => [[
                'name' => 'plugin_keep_appended',
                'columns' => [['name' => 'id', 'type' => 'int']],
            ]],
            'required_columns' => [[
                'table' => 'users',
                'column' => ['name' => 'qa_keep_col', 'type' => 'varchar', 'length' => 24],
            ]],
        ]);
        $this->assertSame('installed', PluginLifecycle::installFrom($dir)['install_status']);
        $this->assertContains('qa_keep_col', $this->columnsOf('users'));

        $result = PluginLifecycle::uninstallFrom($dir, 'KeepAppended', true, 'controller-verified');
        $this->assertSame('not_installed', $result['install_status']);
        $this->assertSame(['plugin_keep_appended'], $result['dropped']);
        $this->assertContains('qa_keep_col', $this->columnsOf('users'), 'required_columns 追加列永不删');
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $this->assertSame(1, preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $table));
        return array_column(
            $this->pdo()->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(),
            'name'
        );
    }

    /** @param array<string,mixed> $json */
    private function writePlugin(string $dirName, array $json): string
    {
        $base = sys_get_temp_dir() . '/plugin_coladd_' . bin2hex(random_bytes(6));
        @mkdir($base, 0775, true);
        $dir = $base . '/' . $dirName;
        @mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/plugin.json',
            (string)json_encode(array_merge(['name' => $dirName], $json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->tempDirs[] = $dir;
        $this->tempDirs[] = $base;
        return $dir;
    }
}
