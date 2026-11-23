<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Hook;
use Hublog\Core\Plugin;
use Hublog\Core\PluginConfigRepository;
use Hublog\Core\PluginLifecycle;
use Hublog\Core\PluginStateRepository;
use Hublog\Controllers\PluginAdminSettingsController;
use ReflectionProperty;

// 清单声明 core_version ">=2.16.0"，测试引导未定义 APP_VERSION；生产由 config.php 定义。
if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'v2.16.29');
}

/**
 * DemoExtension 示例插件全链路行为测试（E1-5 / E1-6，C1 机制演示）
 *
 * 真实调用 T02/T03 领域类，覆盖验收点：
 *   scanAll 识别 → install 建表 → 设置页可配 → post_created 钩子 → upgrade → uninstall（默认保留数据）
 * 同时回归 5 个内置插件 scanAll 原字段集合（兼容红线）。
 */
final class DemoExtensionBehaviorTest extends BehaviorTestCase
{
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

    /**
     * 全链路：清单识别 → 建表初始化 → 设置页配置 → 钩子记录 → 升级 → 卸载保留数据。
     */
    public function testDemoExtensionFullChain(): void
    {
        // ① scanAll 识别（清单模式追加 core_version 等字段，原有 8 字段键序不变）
        $scanned = [];
        foreach (Plugin::scanAll() as $item) {
            $scanned[(string)$item['name']] = $item;
        }
        $this->assertArrayHasKey('DemoExtension', $scanned, 'scanAll 必须识别 DemoExtension');
        $demo = $scanned['DemoExtension'];
        $this->assertSame('演示扩展', $demo['title']);
        $this->assertSame('>=2.16.0', $demo['core_version']);
        $this->assertSame('not_installed', $demo['install_status']);
        $this->assertSame('plugin-demo-notes', $demo['admin_menu'][0]['page']);

        // ② install：清单声明的表由核心生成 DDL，钩子初始化配置
        $install = PluginLifecycle::install('DemoExtension');
        $this->assertSame('installed', $install['install_status']);
        $this->assertSame('1.0.0', $install['installed_version']);
        $this->assertContains('table:plugin_demo_notes', $install['applied']);
        $tableRows = $this->rows('plugin_demo_notes');
        $this->assertSame([], $tableRows, '新建表初始为空');
        $this->assertSame('1', PluginConfigRepository::get('DemoExtension', 'post_hook_enabled'));

        // ③ 设置页：settings.html 含 data-config-key，插件配置可独立读写
        $html = (string)PluginAdminSettingsController::readSettingsHtml('DemoExtension');
        $this->assertStringContainsString('data-config-key="post_hook_enabled"', $html);
        $this->assertStringNotContainsString('<script', strtolower($html), '设置页不得含内联脚本');
        $this->assertTrue(PluginConfigRepository::set('DemoExtension', 'post_hook_enabled', '1'));
        $this->assertSame('1', PluginConfigRepository::get('DemoExtension', 'post_hook_enabled'));

        // ④ post_created 钩子：init() 注册监听，doAction 触发后写入演示记录
        $actionsRef = new ReflectionProperty(Hook::class, 'actions');
        $actionsRef->setAccessible(true);
        $savedActions = $actionsRef->getValue();
        $actionsRef->setValue(null, []);
        try {
            require_once \dirname(__DIR__, 3) . '/plugins/DemoExtension/Plugin.php';
            $instance = new \Hublog\Plugin\DemoExtension\Plugin();
            $instance->init();
            $this->assertTrue(Hook::hasAction('post_created'));
            Hook::doAction('post_created', 9001, 42);
        } finally {
            $actionsRef->setValue(null, $savedActions);
        }
        $notes = $this->rows('plugin_demo_notes');
        $this->assertCount(1, $notes);
        $this->assertSame(9001, (int)$notes[0]['post_id']);
        $this->assertSame(42, (int)$notes[0]['user_id']);

        // 关闭开关后钩子不再记录（证明设置页配置真实生效）
        PluginConfigRepository::set('DemoExtension', 'post_hook_enabled', '0');
        \Hublog\Plugin\DemoExtension\Plugin::onPostCreated(9002, 42);
        $this->assertCount(1, $this->rows('plugin_demo_notes'), '开关关闭时不应新增记录');
        PluginConfigRepository::set('DemoExtension', 'post_hook_enabled', '1');

        // ⑤ upgrade：结构再次幂等应用，版本推进到 to
        $upgrade = PluginLifecycle::upgrade('DemoExtension', '1.0.0', '1.1.0');
        $this->assertSame('installed', $upgrade['install_status']);
        $this->assertSame('1.1.0', $upgrade['installed_version']);
        $this->assertSame([], $upgrade['applied'], '重复安装不得产生新 DDL');

        // ⑥ uninstall：默认只停用、保留数据
        $uninstall = PluginLifecycle::uninstall('DemoExtension', false, '');
        $this->assertSame('not_installed', $uninstall['install_status']);
        $this->assertFalse($uninstall['enabled']);
        $this->assertFalse((bool)PluginStateRepository::isEnabled('DemoExtension'));
        $this->assertSame([], $uninstall['dropped']);
        $this->assertCount(1, $this->rows('plugin_demo_notes'), '默认卸载后演示数据必须保留');
    }

    /**
     * 兼容红线：5 个内置插件无清单，scanAll 输出仍为原 8 字段集合。
     */
    public function testBuiltinPluginsKeepOriginalScanFields(): void
    {
        $expectedKeys = ['name', 'title', 'description', 'version', 'author', 'link', 'enabled', 'path'];
        $scanned = [];
        foreach (Plugin::scanAll() as $item) {
            $scanned[(string)$item['name']] = $item;
        }
        foreach (['AiComment', 'ContentAudit', 'SmartRecommend', 'SmartSearch', 'SmartSummary'] as $name) {
            $this->assertArrayHasKey($name, $scanned, $name . ' 必须仍被扫描到');
            $this->assertSame($expectedKeys, array_keys($scanned[$name]), $name . ' 字段集合发生变化');
        }
    }
}
