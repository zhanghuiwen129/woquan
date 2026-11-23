<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Plugin;
use Hublog\Core\PluginConfigRepository;
use Hublog\Core\PluginLifecycle;
use Hublog\Core\PluginManifest;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SystemRepository;

/**
 * 插件后台闭环（E2-3 / E2-4 / E2-5 + Q3 / Q8）
 *
 * 全部方法挂在 /api/admin 中间件组，方法内再以 requireAdmin() 二次确认；
 * 后台受控加载只在 adminConfig() 里经 Plugin::loadAdminScoped() 发生，前台 loadAll() 不感知。
 *
 * Q3 分工：领域层 PluginLifecycle::uninstall() 只接收「已授权」的确认令牌（非空字符串），
 * HMAC 计算与比对在本控制器完成；清单未声明 drop_tables_on_uninstall 时不校验令牌，
 * 领域层只停用插件、保留数据。
 */
class AdminPluginController extends AdminController
{
    /**
     * POST /api/admin/plugins/install
     *
     * confirm 是前端「我已知晓将按清单建表」的二次确认标记；真正的边界校验由
     * PluginLifecycle + PluginSchemaMigrator 在领域层完成。
     */
    public function install(Request $request): Response
    {
        $this->requireAdmin($request);
        $name = $this->validatedName($request);
        if ($name === null) {
            return Response::error('无效的插件名称', 400);
        }
        $result = PluginLifecycle::install($name);
        if (($result['install_status'] ?? '') === 'failed') {
            return self::lifecycleError($result);
        }
        return Response::success([
            'name'             => $name,
            'install_status'   => (string)($result['install_status'] ?? 'installed'),
            'installed_version' => (string)($result['installed_version'] ?? ''),
        ], '插件安装成功');
    }

    /** POST /api/admin/plugins/upgrade */
    public function upgrade(Request $request): Response
    {
        $this->requireAdmin($request);
        $name = $this->validatedName($request);
        if ($name === null) {
            return Response::error('无效的插件名称', 400);
        }
        $from = $request->getString('from');
        $to = $request->getString('to');
        if ($from === '' || $to === '') {
            return Response::error('升级版本号 from / to 必填', 400);
        }
        $result = PluginLifecycle::upgrade($name, $from, $to);
        if (($result['install_status'] ?? '') === 'failed') {
            return self::lifecycleError($result);
        }
        return Response::success([
            'name'           => $name,
            'from'           => $from,
            'to'             => $to,
            'install_status' => (string)($result['install_status'] ?? 'installed'),
        ], '插件升级成功');
    }

    /**
     * POST /api/admin/plugins/uninstall
     *
     * 清单 drop_tables_on_uninstall=true 时强制校验 confirm_token：
     * hash_hmac('sha256', "plugin:<name>:drop", settings.cron_secret)。
     * 缺失或比对失败一律 403；未声明删表则不需要令牌，数据原样保留。
     */
    public function uninstall(Request $request): Response
    {
        $this->requireAdmin($request);
        $name = $this->validatedName($request);
        if ($name === null) {
            return Response::error('无效的插件名称', 400);
        }

        $drop = self::declaresDropTables($name);
        $token = $drop ? $request->getString('confirm_token') : '';
        if ($drop && ($token === '' || !self::verifyDropConfirmToken($name, $token))) {
            return Response::error('删表确认令牌无效或缺失', 403);
        }

        $result = PluginLifecycle::uninstall($name, $drop, $token);
        if (($result['install_status'] ?? '') === 'failed') {
            return self::lifecycleError($result);
        }
        return Response::success([
            'name'           => $name,
            'enabled'        => false,
            'data_kept'      => !$drop,
            'install_status' => (string)($result['install_status'] ?? 'not_installed'),
            'dropped'        => (array)($result['dropped'] ?? []),
        ], $drop ? '插件已卸载并删除其声明的表' : '插件已停用，数据已保留');
    }

    /**
     * GET /api/admin/plugin-admin-config
     *
     * 汇总后台菜单与设置声明；仅已启用且有后台声明的插件被加载（Q8）。
     * 对声明删表的插件附带 drop_confirm_token，供后台二次确认弹窗使用——令牌
     * 由 settings.cron_secret 派生，不可逆且仅下发给已鉴权管理员。
     */
    public function adminConfig(Request $request): Response
    {
        $this->requireAdmin($request);
        $config = Plugin::loadAdminScoped();
        foreach ($config['menus'] as &$menu) {
            $plugin = (string)($menu['plugin'] ?? '');
            if ($plugin !== '' && self::declaresDropTables($plugin)) {
                $menu['drop_confirm_token'] = self::dropConfirmToken($plugin);
            }
        }
        unset($menu);
        foreach ($config['settings'] as &$setting) {
            $plugin = (string)($setting['plugin_name'] ?? '');
            if ($plugin !== '' && self::declaresDropTables($plugin)) {
                $setting['drop_confirm_token'] = self::dropConfirmToken($plugin);
            }
        }
        unset($setting);
        return Response::success($config);
    }

    /** GET /api/admin/plugin-config */
    public function config(Request $request): Response
    {
        $this->requireAdmin($request);
        $plugin = trim($request->getString('plugin_name'));
        $key = trim($request->getString('key'));
        if ($plugin === '' || $key === '') {
            return Response::error('plugin_name 与 key 必填', 400);
        }
        try {
            $value = PluginConfigRepository::get($plugin, $key);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        }
        return Response::success(['value' => $value ?? '']);
    }

    /** POST /api/admin/plugin-config （uk_plugin_config 幂等 upsert） */
    public function saveConfig(Request $request): Response
    {
        $this->requireAdmin($request);
        $plugin = trim($request->getString('plugin_name'));
        $key = trim($request->getString('key'));
        if ($plugin === '' || $key === '') {
            return Response::error('plugin_name 与 key 必填', 400);
        }
        try {
            PluginConfigRepository::set($plugin, $key, $request->getString('value'));
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        }
        return Response::success(['ok' => true], '配置已保存');
    }

    /**
     * GET /api/admin/plugin-settings-page
     *
     * 设置页 HTML 的路径安全读取由 PluginAdminSettingsController 统一负责，本方法只做
     * 后台鉴权与委托，避免在两处重复实现 realpath 越界校验。
     */
    public function settingsPage(Request $request): Response
    {
        $this->requireAdmin($request);
        return (new PluginAdminSettingsController())->settingsPage($request);
    }

    /**
     * 生成删表二次确认令牌（供控制器/后台测试复用）
     *
     * P3 加固：cron_secret 未配置时返回空串，绝不下发「以空密钥计算、可被离线伪造」的令牌；
     * 后台 adminConfig() 因此拿到空令牌，删表路径由 verifyDropConfirmToken() 统一拒绝。
     */
    public static function dropConfirmToken(string $pluginName): string
    {
        $secret = self::cronSecret();
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', 'plugin:' . $pluginName . ':drop', $secret);
    }

    /**
     * 使用 hash_equals 做定时攻击安全的令牌比对
     *
     * P3 加固：cron_secret 为空时任何令牌都不接受（包括离线计算的 hash_hmac(..., '')），
     * 使 uninstall() 直接返回 403，删表不可进行；口径对齐 AdminToolsController
     * 对 backup_cron_secret 空值的拒绝策略。
     */
    public static function verifyDropConfirmToken(string $pluginName, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $expected = self::dropConfirmToken($pluginName);
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /** 清单是否声明了「卸载时删表」；目录/清单异常时按不删表处理（fail safe） */
    private static function declaresDropTables(string $name): bool
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            return false;
        }
        $dir = rtrim(Plugin::pluginDir(), '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir)) {
            return false;
        }
        $manifest = PluginManifest::fromDir($dir);
        return $manifest->valid() && $manifest->dropTablesOnUninstall();
    }

    /**
     * 删表确认令牌密钥：未配置 / 读取失败一律返回空串，触发下游 fail-closed。
     *
     * 空串不是「默认密钥」——dropConfirmToken() 与 verifyDropConfirmToken() 都会因此
     * 拒绝删表，避免 hash_hmac(..., '') 被离线计算绕过（P3）。
     */
    private static function cronSecret(): string
    {
        try {
            $row = SystemRepository::findSettingValue('cron_secret');
            $secret = $row === null ? '' : (string)($row['value'] ?? '');
            return trim($secret);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @param array<string,mixed> $result */
    private static function lifecycleError(array $result): Response
    {
        $reason = (string)($result['reason'] ?? '插件生命周期执行失败');
        $code = str_contains($reason, '核心版本') || str_contains($reason, '清单') ? 422 : 500;
        return Response::error($reason, $code);
    }

    private function validatedName(Request $request): ?string
    {
        $name = trim($request->getString('name'));
        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) === 1 ? $name : null;
    }
}
