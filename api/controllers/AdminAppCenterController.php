<?php
declare(strict_types=1);
/**
 * Hublog 后台「应用中心」控制器（站点端 agent）
 *
 * 让开源站点在自身后台浏览应用中心市场、激活购买码、在线安装/更新/卸载主题插件、
 * 检查更新与校验源码篡改。所有远端能力经 AppCenterClient 完成，安装经 AppCenterInstaller。
 * 商业逻辑（商品/支付/发码）全部留在中心，本站点只做客户端。
 */

namespace Hublog\Controllers;

use Hublog\Core\AppCenterClient;
use Hublog\Core\AppCenterClientException;
use Hublog\Core\AppCenterInstaller;
use Hublog\Core\AppCenterStore;
use Hublog\Core\SettingsRepository;
use Hublog\Core\Plugin;
use Hublog\Core\Request;
use Hublog\Core\Response;

class AdminAppCenterController extends AdminController
{
    // ==================== 配置与注册 ====================

    /** 读取本地配置与注册状态。 */
    public function config(Request $request): Response
    {
        $this->requireAdmin($request);
        return Response::success(AppCenterStore::config());
    }

    /** 保存应用中心 API 地址。 */
    public function saveConfig(Request $request): Response
    {
        $this->requireAdmin($request);
        $api = $request->getString('api');
        try {
            AppCenterStore::setApi($api);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 400);
        }
        return Response::success(AppCenterStore::config(), '已保存');
    }

    /**
     * 注册新站点（相对中心是公开接口，但本站后台仅管理员可触发）。
     * 传 api 时先保存地址再注册；成功后 site_key/secret 落本地。
     */
    public function register(Request $request): Response
    {
        $this->requireAdmin($request);
        $api = $request->getString('api');
        $name = $request->getString('name');
        $url = $request->getString('url');
        if ($name === '') {
            return Response::error('站点名称不能为空', 400);
        }
        if ($api !== '') {
            try {
                AppCenterStore::setApi($api);
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            }
        }
        // 注册不需要签名，用空凭据的客户端即可
        $client = new AppCenterClient(
            $this->apiBase(),
            '',
            ''
        );
        try {
            $result = $client->register($name, $url);
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
        $siteKey = (string)($result['site_key'] ?? '');
        $siteSecret = (string)($result['site_secret'] ?? '');
        if ($siteKey === '' || $siteSecret === '') {
            return Response::error('应用中心未返回有效凭据', 502);
        }
        AppCenterStore::saveRegistration($siteKey, $siteSecret, $name);
        return Response::success([
            'site_key' => $siteKey,
            'status' => (string)($result['status'] ?? 'active'),
            'config' => AppCenterStore::config(),
        ], '注册成功，凭据已保存到本机');
    }

    /** 连通性探针。 */
    public function ping(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            return Response::success(AppCenterStore::client()->ping());
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    // ==================== 市场与授权 ====================

    /** 应用市场列表。 */
    public function apps(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            return Response::success(['apps' => AppCenterStore::client()->apps()]);
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    /** 应用详情。 */
    public function appDetail(Request $request): Response
    {
        $this->requireAdmin($request);
        $appId = $request->getInt('app_id');
        if ($appId <= 0) {
            return Response::error('应用 ID 不合法', 400);
        }
        try {
            return Response::success(AppCenterStore::client()->appDetail($appId));
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    /** 激活购买码。 */
    public function activate(Request $request): Response
    {
        $this->requireAdmin($request);
        $key = trim($request->getString('license_key'));
        if ($key === '') {
            return Response::error('请输入购买码', 400);
        }
        try {
            $result = AppCenterStore::client()->activate($key);
            return Response::success($result, '购买码激活成功');
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    /** 本站已激活授权。 */
    public function licenses(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            return Response::success(['licenses' => AppCenterStore::client()->licenses()]);
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    // ==================== 安装 / 更新 / 卸载 ====================

    /**
     * 在线安装/更新：中心下载 → 校验 → 适配部署 → 建基线。
     */
    public function install(Request $request): Response
    {
        $this->requireAdmin($request);
        $appId = $request->getInt('app_id');
        if ($appId <= 0) {
            return Response::error('应用 ID 不合法', 400);
        }
        try {
            $client = AppCenterStore::client();
            $detail = $client->appDetail($appId);
            $app = is_array($detail['app'] ?? null) ? $detail['app'] : $detail;
            $version = $request->getString('version');
            $downloaded = $client->download($appId, $version);
            $result = AppCenterInstaller::install(
                $app,
                $downloaded['body'],
                $downloaded['hash']
            );
            // 上报已安装版本（version_id 用详情里的当前版本 id，取不到用 0=最新）
            try {
                $versionId = (int)($app['current_version_id'] ?? $app['version_id'] ?? 0);
                $client->markInstalled($appId, $versionId);
            } catch (AppCenterClientException $ignore) {
                // 上报失败不影响本地安装成功
            }
            return Response::success($result, '安装成功');
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        } catch (\RuntimeException $e) {
            return Response::error('安装失败：' . $e->getMessage(), 500);
        }
    }

    /** 已安装扩展：本地记录 + 本机目录扫描合并。 */
    public function installed(Request $request): Response
    {
        $this->requireAdmin($request);
        $installs = AppCenterStore::installs();
        $out = [];

        // 插件：复用 Plugin::scanAll()（读 docblock 元信息）
        foreach (Plugin::scanAll() as $p) {
            $name = (string)$p['name'];
            $rec = $installs['plugin:' . $name] ?? null;
            $out[] = [
                'type' => 'plugin',
                'slug' => $name,
                'title' => (string)$p['title'],
                'version' => (string)$p['version'],
                'enabled' => (bool)$p['enabled'],
                'managed' => $rec !== null,
                'app_id' => $rec['app_id'] ?? null,
                'installed_version' => $rec['version'] ?? null,
                'installed_at' => $rec['installed_at'] ?? null,
            ];
        }

        // 主题：扫描 templates/ 读 theme.json
        $themeDir = rtrim(defined('TEMPLATE_DIR') ? TEMPLATE_DIR : dirname(__DIR__, 2) . '/templates/', '/');
        foreach (scandir($themeDir, SCANDIR_SORT_ASCENDING) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($themeDir . '/' . $entry)) {
                continue;
            }
            $meta = [];
            $tf = $themeDir . '/' . $entry . '/theme.json';
            if (is_file($tf)) {
                $decoded = json_decode((string)@file_get_contents($tf), true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $rec = $installs['theme:' . $entry] ?? null;
            $out[] = [
                'type' => 'theme',
                'slug' => $entry,
                'title' => (string)($meta['title'] ?? $meta['name'] ?? $entry),
                'version' => (string)($meta['version'] ?? ''),
                'enabled' => false, // 主题启用状态由现有扩展管理页负责，这里不重复判断
                'managed' => $rec !== null,
                'app_id' => $rec['app_id'] ?? null,
                'installed_version' => $rec['version'] ?? null,
                'installed_at' => $rec['installed_at'] ?? null,
            ];
        }

        // 已记录但目录已不存在的（异常残留）
        foreach ($installs as $key => $rec) {
            $exists = false;
            foreach ($out as $row) {
                if ($row['type'] === ($rec['type'] ?? '') && $row['slug'] === ($rec['slug'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $out[] = [
                    'type' => (string)($rec['type'] ?? ''),
                    'slug' => (string)($rec['slug'] ?? ''),
                    'title' => (string)($rec['slug'] ?? ''),
                    'version' => (string)($rec['version'] ?? ''),
                    'enabled' => false,
                    'managed' => true,
                    'app_id' => $rec['app_id'] ?? null,
                    'installed_version' => $rec['version'] ?? null,
                    'installed_at' => $rec['installed_at'] ?? null,
                    'missing' => true,
                ];
            }
        }

        return Response::success(['apps' => $out]);
    }

    /** 检查更新：用本机已装版本问中心。 */
    public function checkUpdates(Request $request): Response
    {
        $this->requireAdmin($request);
        $installs = AppCenterStore::installs();
        $installed = [];
        foreach ($installs as $rec) {
            if (isset($rec['app_id'], $rec['version'])) {
                $installed[] = ['app_id' => (int)$rec['app_id'], 'version' => (string)$rec['version']];
            }
        }
        try {
            $updates = AppCenterStore::client()->checkUpdates($installed);
            return Response::success(['updates' => $updates]);
        } catch (AppCenterClientException $e) {
            return $this->clientError($e);
        }
    }

    /** 卸载（删除前自动备份）。 */
    public function uninstall(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type');
        $slug = $request->getString('slug');
        if (!in_array($type, ['plugin', 'theme'], true) || $slug === '') {
            return Response::error('参数不合法', 400);
        }
        try {
            return Response::success(AppCenterInstaller::uninstall($type, $slug), '已卸载');
        } catch (AppCenterClientException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\RuntimeException $e) {
            return Response::error('卸载失败：' . $e->getMessage(), 500);
        }
    }

    // ==================== 完整性校验 ====================

    /** 建立基线。 */
    public function baseline(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type');
        $slug = $request->getString('slug');
        $dir = $this->extensionDir($type, $slug);
        if ($dir === null) {
            return Response::error('扩展目录不存在或参数不合法', 400);
        }
        try {
            return Response::success(AppCenterStore::buildBaseline($type, $slug, $dir), '基线已建立');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    /** 校验篡改。 */
    public function verify(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type');
        $slug = $request->getString('slug');
        if ($type === '' || $slug === '') {
            return Response::error('参数不合法', 400);
        }
        return Response::success(AppCenterStore::verifyBaseline($type, $slug));
    }

    /** 基线列表。 */
    public function baselineList(Request $request): Response
    {
        $this->requireAdmin($request);
        return Response::success(['baselines' => AppCenterStore::baselineList()]);
    }

    /** 删除基线。 */
    public function deleteBaseline(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type');
        $slug = $request->getString('slug');
        if ($type === '' || $slug === '') {
            return Response::error('参数不合法', 400);
        }
        AppCenterStore::deleteBaseline($type, $slug);
        return Response::success(null, '基线已删除');
    }

    // ==================== 私有辅助 ====================

    private function apiBase(): string
    {
        $api = (string)SettingsRepository::get('app_center_api', '');
        if ($api === '' && defined('APP_CENTER_URL')) {
            $api = preg_replace('#/admin\.php$#', '/api.php', APP_CENTER_URL) ?? APP_CENTER_URL;
        }
        return $api;
    }

    private function clientError(AppCenterClientException $e): Response
    {
        $code = $e->getCode();
        $httpCode = in_array($code, [401, 402, 403, 404], true) ? $code : 502;
        $msg = $code === 402
            ? '该应用需要先购买并激活授权'
            : $e->getMessage();
        return Response::error($msg, $httpCode);
    }

    private function extensionDir(string $type, string $slug): ?string
    {
        if (!in_array($type, ['plugin', 'theme'], true) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $slug)) {
            return null;
        }
        $base = $type === 'plugin'
            ? dirname(__DIR__, 2) . '/plugins'
            : rtrim(defined('TEMPLATE_DIR') ? TEMPLATE_DIR : dirname(__DIR__, 2) . '/templates/', '/');
        $dir = $base . '/' . $slug;
        return is_dir($dir) ? $dir : null;
    }
}
