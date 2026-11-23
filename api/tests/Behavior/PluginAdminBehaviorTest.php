<?php

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Plugin;
use Hublog\Core\PluginConfigRepository;
use Hublog\Core\PluginStateRepository;
use Hublog\Controllers\AdminPluginController;
use Hublog\Controllers\PluginAdminSettingsController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use ReflectionProperty;

/**
 * 插件后台闭环行为测试（E2-3 / E2-4 / E2-5 + Q3 / Q8）
 *
 * 用临时插件目录 + SQLite 内存库真实调用受控加载、配置仓储与控制器：
 *  - 无后台声明插件零 require，已启用且有声明者按需加载且不调 init()；
 *  - confirm_token HMAC 正误与「未声明删表不校验」；
 *  - plugin_configs 保留键 _schema_tables 写入/删除被拒；
 *  - settings.html realpath 越界与后缀白名单。
 */
final class PluginAdminBehaviorTest extends BehaviorTestCase
{
    private string $tempRoot = '';
    private string $originalPluginDir = '';

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

        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_t03_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0777, true);

        $ref = new ReflectionProperty(Plugin::class, 'pluginDir');
        $ref->setAccessible(true);
        $this->originalPluginDir = (string)$ref->getValue();
        $ref->setValue(null, $this->tempRoot . DIRECTORY_SEPARATOR);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionProperty(Plugin::class, 'pluginDir');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->originalPluginDir);
        $this->removeDir($this->tempRoot);
        parent::tearDown();
    }

    /** Q8 五步：零加载 / 按需加载 / 不调 init / 只读上下文 / HUBLOG_ADMIN_SCOPE */
    public function testLoadAdminScopedSkipsSilentPluginsAndNeverCallsInit(): void
    {
        $this->makePlugin('T03Quiet', $this->quietPluginPhp('T03Quiet'), null);
        $this->makePlugin('T03Off', $this->loudPluginPhp('T03Off'), $this->manifest('T03Off', false));
        $this->makePlugin('T03Decl', $this->quietPluginPhp('T03Decl'), $this->manifest('T03Decl', false));
        $this->makePlugin('T03Loud', $this->loudPluginPhp('T03Loud'), $this->manifest('T03Loud', false));
        PluginStateRepository::setEnabled('T03Off', false);
        PluginStateRepository::setEnabled('T03Decl', true);
        PluginStateRepository::setEnabled('T03Loud', true);

        $config = Plugin::loadAdminScoped();

        $this->assertTrue(defined('HUBLOG_ADMIN_SCOPE'), '加载期必须定义 HUBLOG_ADMIN_SCOPE 常量');
        $this->assertFileDoesNotExist($this->tempRoot . '/T03Quiet/loaded.marker', '无后台声明插件不得 require');
        $this->assertFileDoesNotExist($this->tempRoot . '/T03Off/loaded.marker', '禁用插件不得加载');
        $this->assertFileExists($this->tempRoot . '/T03Loud/loaded.marker', '已启用且有声明者必须加载');
        $this->assertFileDoesNotExist($this->tempRoot . '/T03Loud/init.marker', '后台作用域绝不调用 init()');
        $this->assertSame('yes', (string)file_get_contents($this->tempRoot . '/T03Loud/scope.marker'));

        $pages = array_column($config['menus'], 'page');
        $this->assertContains('plugin-t03loud', $pages, '清单声明的菜单应生效');
        $this->assertContains('plugin-t03loud-extra', $pages, 'registerAdmin() 追加的菜单应生效');
        $this->assertNotContains('plugin-t03off', $pages, '禁用插件的菜单不得出现');
        $byPlugin = [];
        foreach ($config['settings'] as $item) {
            $byPlugin[(string)$item['plugin_name']] = $item;
        }
        $this->assertArrayHasKey('T03Loud', $byPlugin);
        $this->assertArrayHasKey('T03Decl', $byPlugin, '未实现 registerAdmin() 的插件清单声明也应生效');
        $this->assertSame('t03_settings', (string)$byPlugin['T03Decl']['config_scope']);
        $this->assertSame('t03_extra', (string)$byPlugin['T03Loud']['config_scope'], '上下文声明覆盖清单声明');
    }

    /** Q3：confirm_token HMAC 正误 + 未声明删表不校验 */
    public function testUninstallConfirmTokenHmacAndDataKept(): void
    {
        $this->pdo()->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('cron_secret', 'unit-cron-secret')");
        $this->makePlugin('T03Drop', $this->quietPluginPhp('T03Drop'), $this->manifest('T03Drop', true));
        $this->makePlugin('T03Keep', $this->quietPluginPhp('T03Keep'), $this->manifest('T03Keep', false));

        $expected = hash_hmac('sha256', 'plugin:T03Drop:drop', 'unit-cron-secret');
        $this->assertSame($expected, AdminPluginController::dropConfirmToken('T03Drop'));
        $this->assertTrue(AdminPluginController::verifyDropConfirmToken('T03Drop', $expected));
        $this->assertFalse(AdminPluginController::verifyDropConfirmToken('T03Drop', 'bad-token'));
        $this->assertFalse(AdminPluginController::verifyDropConfirmToken('T03Drop', ''));

        $controller = new TestableAdminPluginController();
        $missing = $controller->uninstall($this->request(['name' => 'T03Drop']));
        $this->assertSame(403, $missing->getCode(), '声明删表但无令牌必须 403');
        $wrong = $controller->uninstall($this->request(['name' => 'T03Drop', 'confirm_token' => 'bad-token']));
        $this->assertSame(403, $wrong->getCode(), '令牌不匹配必须 403');

        $dropped = $controller->uninstall($this->request(['name' => 'T03Drop', 'confirm_token' => $expected]));
        $this->assertSame(200, $dropped->getCode());
        $payload = json_decode($dropped->toJson(), true);
        $this->assertFalse($payload['data']['data_kept'], '有令牌删表时 data_kept=false');
        $this->assertFalse((bool)PluginStateRepository::isEnabled('T03Drop'));

        $kept = $controller->uninstall($this->request(['name' => 'T03Keep']));
        $this->assertSame(200, $kept->getCode(), '未声明删表无需令牌');
        $payload = json_decode($kept->toJson(), true);
        $this->assertTrue($payload['data']['data_kept'], '未声明删表必须保留数据');
    }

    /**
     * P3 加固：cron_secret 未配置时删表 fail-closed。
     *
     * 攻击者可离线算出 hash_hmac('sha256', 'plugin:<name>:drop', '') 作为令牌；
     * 修复后空密钥不下发令牌、也不接受任何令牌，uninstall 必须返回 403。
     */
    public function testEmptyCronSecretRejectsDropEvenWithForgedToken(): void
    {
        // BehaviorTestCase 只注入 sms_provider，settings.cron_secret 保持未配置。
        $this->pdo()->exec("DELETE FROM `settings` WHERE `key` = 'cron_secret'");
        $this->makePlugin('T03NoSecret', $this->quietPluginPhp('T03NoSecret'), $this->manifest('T03NoSecret', true));

        $forged = hash_hmac('sha256', 'plugin:T03NoSecret:drop', '');
        $this->assertSame('', AdminPluginController::dropConfirmToken('T03NoSecret'), '空密钥不得下发令牌');
        $this->assertFalse(
            AdminPluginController::verifyDropConfirmToken('T03NoSecret', $forged),
            '离线伪造的空密钥令牌必须被拒绝'
        );

        $controller = new TestableAdminPluginController();
        $denied = $controller->uninstall($this->request([
            'name' => 'T03NoSecret',
            'confirm_token' => $forged,
        ]));
        $this->assertSame(403, $denied->getCode(), '空 cron_secret 时删表必须被 403 拒绝');
        $this->assertTrue((bool)PluginStateRepository::isEnabled('T03NoSecret'), '被拒后插件仍保持启用');

        // 配置有效密钥后，同一令牌算法恢复正常（证明不是无条件拒绝）。
        $this->pdo()->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('cron_secret', 'restored-secret')");
        $valid = AdminPluginController::dropConfirmToken('T03NoSecret');
        $this->assertSame(
            hash_hmac('sha256', 'plugin:T03NoSecret:drop', 'restored-secret'),
            $valid
        );
        $this->assertTrue(AdminPluginController::verifyDropConfirmToken('T03NoSecret', $valid));
    }

    /** Q4：plugin_configs 按插件+键隔离幂等，保留键拒绝 */
    public function testConfigRepositoryUpsertAndReservedKeyGuard(): void
    {
        $this->assertTrue(PluginConfigRepository::set('T03Loud', 'enabled_flag', '1'));
        $this->assertTrue(PluginConfigRepository::set('T03Loud', 'enabled_flag', '2'));
        $this->assertSame('2', PluginConfigRepository::get('T03Loud', 'enabled_flag'));
        $this->assertNull(PluginConfigRepository::get('T03Other', 'enabled_flag'), '插件之间配置必须隔离');
        $this->assertSame(1, count($this->rows('plugin_configs')), 'uk_plugin_config 幂等：同键只一行');

        // T02 用该键登记跨插件同名表归属：插件若能写即可伪造归属绕过拦截
        $this->assertTrue(PluginConfigRepository::isReservedKey('_schema_tables'));
        try {
            PluginConfigRepository::set('T03Loud', '_schema_tables', '["plugin_fake"]');
            $this->fail('写入 _schema_tables 必须被拒绝');
        } catch (HublogException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('保留键', $e->getMessage());
        }
        try {
            PluginConfigRepository::delete('T03Loud', '_schema_tables');
            $this->fail('删除 _schema_tables 必须被拒绝');
        } catch (HublogException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->seed('plugin_configs', [
            'plugin_name' => 'T03Loud',
            'config_key' => '_schema_tables',
            'config_value' => '["plugin_t03loud_notes"]',
        ]);
        $this->assertArrayNotHasKey('_schema_tables', PluginConfigRepository::all('T03Loud'), '保留键不得回显');
        $this->assertTrue(PluginConfigRepository::delete('T03Loud', 'enabled_flag'));
        $this->assertNull(PluginConfigRepository::get('T03Loud', 'enabled_flag'));
    }

    /** E2-4：settings.html realpath 越界与后缀白名单 */
    public function testSettingsPagePathGuard(): void
    {
        $dir = $this->tempRoot . '/T03Loud';
        $this->makePlugin('T03Loud', $this->quietPluginPhp('T03Loud'), null);
        file_put_contents($dir . '/settings.html', '<div>t03 settings</div>');

        $this->assertSame(
            realpath($dir . '/settings.html'),
            PluginAdminSettingsController::resolveSettingsFile('T03Loud')
        );
        $this->assertStringContainsString('t03 settings', (string)PluginAdminSettingsController::readSettingsHtml('T03Loud'));
        $this->assertNull(PluginAdminSettingsController::resolveSettingsFile('../T03Loud'));
        $this->assertNull(PluginAdminSettingsController::resolveSettingsFile('T03Loud/../../outside'));

        // 目录伪装成 settings.html：存在但非文件，必须拒绝
        @unlink($dir . '/settings.html');
        @mkdir($dir . '/settings.html');
        $this->assertNull(PluginAdminSettingsController::readSettingsHtml('T03Loud'));
        @rmdir($dir . '/settings.html');

        // 软链接指向插件目录外的 .php：realpath 越界 + 后缀白名单双重拒绝（系统不支持链接时跳过）
        $outside = $this->tempRoot . '/outside.php';
        file_put_contents($outside, '<?php echo "secret";');
        if (function_exists('symlink') && @symlink($outside, $dir . '/settings.html')) {
            $this->assertNull(PluginAdminSettingsController::resolveSettingsFile('T03Loud'));
            @unlink($dir . '/settings.html');
        }
        file_put_contents($dir . '/settings.html', '<div>t03 settings</div>');
    }

    /** @param array<string,mixed> $params */
    private function request(array $params): Request
    {
        $request = new Request();
        foreach ($params as $key => $value) {
            $request->setParam((string)$key, $value);
        }
        return $request;
    }

    /** @param array<string,mixed>|null $manifest */
    private function makePlugin(string $name, string $pluginPhp, ?array $manifest): string
    {
        $dir = $this->tempRoot . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/Plugin.php', $pluginPhp);
        if ($manifest !== null) {
            file_put_contents($dir . '/plugin.json', (string)json_encode($manifest, JSON_UNESCAPED_UNICODE));
        }
        return $dir;
    }

    /** @return array<string,mixed> */
    private function manifest(string $name, bool $drop): array
    {
        return [
            'name' => $name,
            'title' => 'T03 测试插件',
            'description' => 'behavior fixture',
            'version' => '1.0.0',
            'author' => 'qa',
            'link' => '',
            'core_version' => '*',
            'permissions' => [],
            'hooks' => [],
            'required_tables' => [],
            'required_columns' => [],
            'drop_tables_on_uninstall' => $drop,
            'admin_menu' => [['group' => 'system', 'title' => 'T03 设置', 'icon' => 'puzzle', 'page' => 'plugin-' . strtolower($name)]],
            'admin_settings' => ['page' => 'settings.html', 'config_scope' => 't03_settings', 'title' => 'T03 设置'],
            'install_hook' => false,
            'uninstall_hook' => false,
            'upgrade_hook' => false,
        ];
    }

    private function quietPluginPhp(string $name): string
    {
        return <<<PHP
<?php
namespace Hublog\\Plugin\\{$name};
file_put_contents(__DIR__ . '/loaded.marker', '1');
class Plugin
{
    public function init(): void
    {
        file_put_contents(__DIR__ . '/init.marker', '1');
    }
}
PHP;
    }

    private function loudPluginPhp(string $name): string
    {
        return <<<PHP
<?php
namespace Hublog\\Plugin\\{$name};
use Hublog\\Core\\PluginAdminContext;
file_put_contents(__DIR__ . '/loaded.marker', '1');
class Plugin
{
    public function init(): void
    {
        file_put_contents(__DIR__ . '/init.marker', '1');
    }

    public function registerAdmin(PluginAdminContext \$ctx): void
    {
        file_put_contents(
            __DIR__ . '/scope.marker',
            defined('HUBLOG_ADMIN_SCOPE') ? 'yes' : 'no'
        );
        \$ctx->addMenu([
            'group' => 'system',
            'title' => 'T03 扩展设置',
            'icon' => 'settings',
            'page' => 'plugin-' . strtolower('{$name}') . '-extra',
        ]);
        \$ctx->registerSettings([
            'page' => 'settings.html',
            'config_scope' => 't03_extra',
            'title' => 'T03 扩展设置',
        ]);
    }
}
PHP;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            if (is_dir($item) && !is_link($item)) {
                $this->removeDir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}

/** 测试替身：绕过 Auth::admin()，只验证控制器自身逻辑 */
final class TestableAdminPluginController extends AdminPluginController
{
    protected function requireAdmin(Request $request): array
    {
        return ['id' => 1, 'role' => 'admin'];
    }
}
