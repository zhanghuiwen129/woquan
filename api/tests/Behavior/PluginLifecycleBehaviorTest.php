<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Cache;
use Hublog\Core\Plugin;
use Hublog\Core\PluginLifecycle;
use Hublog\Core\PluginManifest;
use Hublog\Core\PluginSchemaMigrator;
use Hublog\Core\PluginStateRepository;

/**
 * 插件生命周期 / 状态落库行为测试（E1-2 / E1-3 / E2-1 / E2-2）
 *
 * 真实调用业务类 + SQLite 内存库：建表幂等、失败状态落库、未实现钩子不报错、
 * disabled_plugins 迁移幂等、清缓存后状态仍以 DB 为准。
 */
final class PluginLifecycleBehaviorTest extends BehaviorTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // migrate101 的三表在行为库里按 SQLite 口径建最小结构
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `plugin_states` (
    `plugin_name`       TEXT PRIMARY KEY,
    `enabled`           INTEGER NOT NULL DEFAULT 1,
    `install_status`    TEXT NOT NULL DEFAULT 'installed',
    `installed_version` TEXT NOT NULL DEFAULT '',
    `last_error`        TEXT NOT NULL DEFAULT '',
    `installed_at`      TEXT DEFAULT NULL,
    `updated_at`        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE `plugin_configs` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `plugin_name`  TEXT NOT NULL,
    `config_key`   TEXT NOT NULL,
    `config_value` TEXT,
    `updated_at`   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
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

    /** 5 个内置插件：字段集合与取值逐字不变 */
    public function testBuiltinScanAllOutputUnchanged(): void
    {
        $expected = [
            [
                'name' => 'AiComment',
                'title' => 'AI 自动评论',
                'description' => '新动态发布后异步调用 AI 自动生成一条评论，标记为 AI 评论',
                'version' => '1.0.0',
                'author' => 'woquan',
                'link' => '',
                'enabled' => true,
                'path' => 'AiComment/Plugin.php',
            ],
            [
                'name' => 'ContentAudit',
                'title' => '智能内容审核',
                'description' => '双层审核：本地 DFA 敏感词匹配 + AI 语义判定，命中自动置为待审核',
                'version' => '1.0.0',
                'author' => 'woquan',
                'link' => '',
                'enabled' => true,
                'path' => 'ContentAudit/Plugin.php',
            ],
            [
                'name' => 'SmartRecommend',
                'title' => '个性化推荐（规则）',
                'description' => '基于用户行为协同过滤 + 标签内容 + 时间衰减热度，返回个性化推荐动态（纯规则，无 AI）',
                'version' => '1.0.0',
                'author' => 'woquan',
                'link' => '',
                'enabled' => true,
                'path' => 'SmartRecommend/Plugin.php',
            ],
            [
                'name' => 'SmartSearch',
                'title' => '智能搜索',
                'description' => 'MySQL LIKE 全文搜索 + Embedding 向量语义搜索，理解关键词背后的含义',
                'version' => '1.0.0',
                'author' => 'woquan',
                'link' => '',
                'enabled' => true,
                'path' => 'SmartSearch/Plugin.php',
            ],
            [
                'name' => 'SmartSummary',
                'title' => '智能摘要与标签',
                'description' => '发帖后调用 AI 自动生成 50 字摘要 + 3 个标签，提升内容可发现性',
                'version' => '1.0.0',
                'author' => 'woquan',
                'link' => '',
                'enabled' => true,
                'path' => 'SmartSummary/Plugin.php',
            ],
        ];
        // T05 新增示例插件 DemoExtension（唯一允许新增的插件目录）。兼容红线是
        // 「5 个内置插件的 scanAll 输出逐字不变」，故仅剔除示例插件后再严格比对。
        $builtins = array_values(array_filter(
            Plugin::scanAll(),
            static fn(array $item): bool => ($item['name'] ?? '') !== 'DemoExtension'
        ));
        $this->assertSame($expected, $builtins, '5 个内置插件的 scanAll 输出必须零差异');
    }

    /** setEnabled 写 DB + 清缓存后仍从 DB 重建 */
    public function testToggleStateSurvivesCacheClear(): void
    {
        PluginStateRepository::setEnabled('AiComment', false);
        PluginStateRepository::isEnabled('AiComment'); // 触发一次回源，填充读缓存
        $this->assertIsArray(Cache::get('plugin_states'));
        Cache::delete('plugin_states');
        $this->assertFalse(PluginStateRepository::isEnabled('AiComment'));
        $this->assertSame(0, (int)$this->rows('plugin_states')[0]['enabled']);

        PluginStateRepository::setEnabled('AiComment', true);
        Cache::delete('plugin_states');
        $this->assertTrue(PluginStateRepository::isEnabled('AiComment'));
        $this->assertCount(1, $this->rows('plugin_states'), '重复 toggle 不得产生多行');
    }

    /** disabled_plugins 一次性迁移：两次执行无脏数据 */
    public function testLegacyBlacklistMigrationIsIdempotent(): void
    {
        Cache::set('disabled_plugins', ['AiComment']);
        $this->assertSame(1, PluginStateRepository::migrateLegacyBlacklist());
        $this->assertSame(0, PluginStateRepository::migrateLegacyBlacklist());
        $this->assertCount(1, $this->rows('plugin_states'));
        $this->assertSame(0, (int)$this->rows('plugin_states')[0]['enabled']);
        $this->assertFalse((bool)Cache::get('disabled_plugins'));
    }

    /** 声明式建表重复执行幂等 */
    public function testApplySchemaTwiceIsIdempotent(): void
    {
        $dir = $this->writePlugin('ProbeSchema', [
            'name' => 'ProbeSchema',
            'version' => '1.0.0',
            'required_tables' => [[
                'name' => 'plugin_probe_schema',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'auto_increment' => true],
                    ['name' => 'note', 'type' => 'varchar', 'length' => 128],
                ],
                'primary_key' => 'id',
            ]],
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertTrue($manifest->valid());

        $first = PluginSchemaMigrator::apply($manifest);
        $this->assertContains('table:plugin_probe_schema', $first);
        $this->assertSame([], PluginSchemaMigrator::apply($manifest), '第二次执行应全部幂等跳过');
        $this->assertTrue($this->tableExistsInDb('plugin_probe_schema'));
        $this->assertContains('note', $this->tableColumns('plugin_probe_schema'));
    }

    /** required_columns 必须真的给目标表追加列（QA Round 1 bug 回归） */
    public function testRequiredColumnsAddsColumnToExistingTable(): void
    {
        $dir = $this->writePlugin('ProbeAddColumn', [
            'name' => 'ProbeAddColumn',
            'required_columns' => [[
                'table' => 'users',
                'column' => ['name' => 'qa_extra', 'type' => 'varchar', 'length' => 16],
            ]],
        ]);
        $result = PluginLifecycle::installFrom($dir);
        $this->assertSame('installed', $result['install_status'], (string)($result['reason'] ?? ''));
        $this->assertContains('column:users.qa_extra', $result['applied']);
        $this->assertContains('qa_extra', $this->tableColumns('users'));

        // 重复安装幂等：列已存在时不再 ALTER
        $again = PluginLifecycle::installFrom($dir);
        $this->assertSame('installed', $again['install_status']);
        $this->assertSame([], $again['applied']);
    }

    /** 半残库自愈：表已存在但缺列时必须逐列补齐（QA Round 1 bug 回归） */
    public function testApplyHealsTableWithMissingColumn(): void
    {
        $this->pdo()->exec('CREATE TABLE `plugin_probe_heal` (`id` INTEGER NOT NULL)');
        $dir = $this->writePlugin('ProbeHeal', [
            'name' => 'ProbeHeal',
            'required_tables' => [[
                'name' => 'plugin_probe_heal',
                'columns' => [
                    ['name' => 'id', 'type' => 'int'],
                    ['name' => 'note', 'type' => 'varchar', 'length' => 64],
                ],
            ]],
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertTrue($manifest->valid(), implode('；', $manifest->errors()));

        $first = PluginSchemaMigrator::apply($manifest);
        $this->assertContains('column:plugin_probe_heal.note', $first);
        $this->assertContains('note', $this->tableColumns('plugin_probe_heal'));
        $this->assertSame([], PluginSchemaMigrator::apply($manifest), '第二次应幂等跳过');
    }

    /** 非法 type：安装失败且 last_error 可见 */
    public function testIllegalTypeMarksInstallFailedWithLastError(): void
    {
        $dir = $this->writePlugin('ProbeInvalid', [
            'name' => 'ProbeInvalid',
            'required_tables' => [[
                'name' => 'plugin_probe_invalid',
                'columns' => [['name' => 'weird', 'type' => 'enum']],
            ]],
        ]);
        $result = PluginLifecycle::installFrom($dir);
        $this->assertSame('failed', $result['install_status']);
        $this->assertStringContainsString('白名单', $result['reason']);
        $state = PluginStateRepository::get('ProbeInvalid');
        $this->assertNotNull($state);
        $this->assertSame('failed', $state['install_status']);
        $this->assertStringContainsString('白名单', (string)$state['last_error']);
    }

    /** 未实现任何钩子的插件安装 / 卸载均不报错 */
    public function testNoHookPluginInstallAndUninstall(): void
    {
        $dir = $this->writePlugin('ProbeNoHook', [
            'name' => 'ProbeNoHook',
            'version' => '2.3.4',
        ]);
        $installed = PluginLifecycle::installFrom($dir);
        $this->assertSame('installed', $installed['install_status']);
        $this->assertSame('2.3.4', $installed['installed_version']);

        $uninstalled = PluginLifecycle::uninstallFrom('ProbeNoHook', 'ProbeNoHook', false, '');
        $this->assertSame('not_installed', $uninstalled['install_status']);
        $this->assertFalse($uninstalled['enabled']);
        $this->assertSame([], $uninstalled['dropped']);
    }

    /** Q3：默认保留数据；删表必须显式授权，且只删声明内 plugin_* 表 */
    public function testUninstallDropRequiresExplicitAuthorization(): void
    {
        $dir = $this->writePlugin('ProbeDrop', [
            'name' => 'ProbeDrop',
            'drop_tables_on_uninstall' => true,
            'required_tables' => [[
                'name' => 'plugin_probe_drop',
                'columns' => [['name' => 'note', 'type' => 'text']],
            ]],
        ]);
        $this->assertSame('installed', PluginLifecycle::installFrom($dir)['install_status']);
        $this->assertTrue($this->tableExistsInDb('plugin_probe_drop'));

        $denied = PluginLifecycle::uninstallFrom($dir, 'ProbeDrop', true, '');
        $this->assertSame('failed', $denied['install_status']);
        $this->assertTrue($this->tableExistsInDb('plugin_probe_drop'), '未授权时数据必须保留');

        $dropped = PluginLifecycle::uninstallFrom($dir, 'ProbeDrop', true, 'controller-verified');
        $this->assertSame('not_installed', $dropped['install_status']);
        $this->assertSame(['plugin_probe_drop'], $dropped['dropped']);
        $this->assertFalse($this->tableExistsInDb('plugin_probe_drop'), '声明内的 plugin_* 表应已删除');
        $this->assertCount(1, $this->rows('plugin_states'), 'plugin_states 自身不得被删');
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        $rows = $this->pdo()->query('PRAGMA table_info(`' . $table . '`)')->fetchAll();
        return array_map(static fn(array $row): string => (string)$row['name'], $rows);
    }

    private function tableExistsInDb(string $table): bool
    {
        $count = (int)$this->pdo()->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'"
        )->fetchColumn();
        return $count > 0;
    }

    /** @param array<string,mixed> $json */
    private function writePlugin(string $dirName, array $json): string
    {
        $base = sys_get_temp_dir() . '/plugin_lifecycle_' . bin2hex(random_bytes(6));
        @mkdir($base, 0775, true);
        $dir = $base . '/' . $dirName;
        @mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/plugin.json',
            (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->tempDirs[] = $dir;
        $this->tempDirs[] = $base;
        return $dir;
    }
}
