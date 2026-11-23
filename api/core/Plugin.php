<?php
/**
 * Hublog 插件加载器
 * 自动扫描 plugins/ 目录，加载合法插件初始化文件
 * 插件目录结构：plugins/{PluginName}/Plugin.php
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Plugin
{
    private static string $pluginDir = __DIR__ . '/../../plugins/';
    private static array $loaded = [];
    private static array $deferred = [];
    /** disabled_plugins → plugin_states 的一次性迁移，每个 PHP 进程只引导一次 */
    private static bool $legacyMigrated = false;

    /**
     * 登记延迟任务：等响应完整输出给客户端之后才执行。
     *
     * 【为什么必须有这个】插件只能在钩子回调里介入，而钩子是在 Controller 内部触发的，
     * 此时响应体还没 echo。如果插件为了「不阻塞」直接调用 fastcgi_finish_request()，
     * PHP-FPM 会立刻把当前（还是空的）响应 flush 给客户端并关闭连接，
     * 后面 Controller 的 return Response 和 api/index.php 的 echo 全部失效 ——
     * 客户端收到一个空 body，表现为「开启 AI 评论后发帖接口返回空/解析失败」。
     *
     * 正确顺序：Controller 返回 → index.php echo 完整响应 → runDeferred() 先
     * fastcgi_finish_request() 断开连接 → 再跑耗时任务。用户不等待，响应也不丢。
     */
    public static function defer(callable $callback): void
    {
        self::$deferred[] = $callback;
    }

    /**
     * 执行全部延迟任务（由 api/index.php 在 echo 响应之后调用）
     */
    public static function runDeferred(): void
    {
        if (empty(self::$deferred)) {
            return;
        }
        // 先复制再清空，避免任务内部又 defer 造成死循环
        $tasks = self::$deferred;
        self::$deferred = [];

        // 响应已经 echo，这里可以安全断开连接，后续耗时逻辑客户端无感知
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($tasks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Logger::error('[Hublog Plugin] 延迟任务执行失败: ' . $e->getMessage());
            }
        }
    }

    /**
     * 插件根目录（PluginLifecycle 等按名定位插件目录时共用）
     */
    public static function pluginDir(): string
    {
        return self::$pluginDir;
    }

    /**
     * 扫描并加载全部插件（跳过被禁用的插件）
     *
     * E2-1/E2-2：启停以 plugin_states 为唯一可信源；Cache('plugin_states') 只是读缓存。
     * 无 plugin.json 的内置插件完全走 docblock 回退，清单校验失败只跳过该插件。
     */
    public static function loadAll(): void
    {
        if (!is_dir(self::$pluginDir)) {
            return;
        }
        self::ensureLegacyMigrated();

        $coreVersion = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';

        foreach (glob(self::$pluginDir . '*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $file = $dir . '/Plugin.php';
            if (!file_exists($file)) {
                continue;
            }
            $manifest = PluginManifest::fromDir($dir);
            if (!$manifest->valid()) {
                continue; // 非法清单已在 PluginManifest 里记日志，不影响其他插件
            }
            if (!$manifest->supportsCore($coreVersion)) {
                Logger::error('[Hublog Plugin] 插件 ' . $name . ' 被拒绝激活: '
                    . (string)$manifest->coreVersionError($coreVersion));
                continue;
            }
            if (!PluginStateRepository::isEnabled($name)) {
                continue;
            }
            self::load($name, $file);
        }
    }

    /**
     * 后台受控加载（E2-3 / Q8）：只汇总后台声明，绝不调用插件 init()。
     *
     * 五步严格对齐架构 §3.6：
     *   1) 遍历 plugins/*，先查 PluginStateRepository::isEnabled()，未启用直接跳过；
     *   2) 再查 PluginManifest::adminMenu()/adminSettings()，两者皆空 → 连 Plugin.php 都不 require；
     *   3) 仅对「已启用 + 有后台声明」者做 realpath 边界校验后 require_once + 实例化；
     *   4) 定义 HUBLOG_ADMIN_SCOPE 供插件自检；若实现 registerAdmin(PluginAdminContext $ctx)
     *      则调用（method_exists 守卫）——绝不调 init()，业务钩子/路由在后台上下文不注册；
     *   5) 收集只读上下文的 menus()/settings() 返回。
     *
     * 调用时机由路由组 AdminMiddleware + requireAdmin() 保证在鉴权之后；前台/CLI 的
     * loadAll() 完全不感知此方法，5 个内置插件（无后台声明）在本路径零加载零执行。
     *
     * @return array{menus:list<array<string,mixed>>,settings:list<array<string,mixed>>}
     */
    public static function loadAdminScoped(): array
    {
        $result = ['menus' => [], 'settings' => []];
        if (!is_dir(self::$pluginDir)) {
            return $result;
        }
        self::ensureLegacyMigrated();
        if (!defined('HUBLOG_ADMIN_SCOPE')) {
            define('HUBLOG_ADMIN_SCOPE', 'admin');
        }

        $coreVersion = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
        $root = realpath(self::$pluginDir);
        foreach (glob(self::$pluginDir . '*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name) !== 1) {
                continue;
            }
            // 1) 启停判定先于任何文件读取
            if (!PluginStateRepository::isEnabled($name)) {
                continue;
            }
            $manifest = PluginManifest::fromDir($dir);
            if (!$manifest->valid()) {
                continue;
            }
            if (!$manifest->supportsCore($coreVersion)) {
                Logger::error('[Hublog Plugin] 后台作用域拒绝插件 ' . $name . ': '
                    . (string)$manifest->coreVersionError($coreVersion));
                continue;
            }
            // 2) 无后台声明 → 不 require Plugin.php（行为与现状完全一致）
            $menus = $manifest->adminMenu();
            $settings = $manifest->adminSettings();
            if ($menus === [] && $settings === null) {
                continue;
            }
            $file = $dir . '/Plugin.php';
            if (!is_file($file)) {
                continue;
            }
            // 3) 只对入选插件实例化
            $realPath = realpath($file);
            if ($realPath === false || $root === false || !str_starts_with($realPath, $root)) {
                Logger::error('[Hublog Plugin] 后台作用域插件路径非法: ' . $name);
                continue;
            }
            try {
                require_once $file;
                $class = "Hublog\\Plugin\\{$name}\\Plugin";
                if (!class_exists($class)) {
                    continue;
                }
                $instance = new $class();
                // 清单声明先注入上下文，未实现 registerAdmin() 的插件也能生效
                $ctx = new PluginAdminContext($name, $menus, $settings);
                // 4) 只调 registerAdmin()，绝不调 init()
                if (method_exists($instance, 'registerAdmin')) {
                    $instance->registerAdmin($ctx);
                }
                // 5) 收集只读上下文
                foreach ($ctx->menus() as $menu) {
                    $result['menus'][] = $menu;
                }
                foreach ($ctx->settings() as $item) {
                    $result['settings'][] = $item;
                }
            } catch (\Throwable $e) {
                Logger::error('[Hublog Plugin] 后台作用域加载插件 ' . $name . ' 失败: ' . $e->getMessage());
            }
        }
        return $result;
    }

    /** 升级期引导一次：把旧版 disabled_plugins 黑名单幂等迁入 plugin_states */
    private static function ensureLegacyMigrated(): void
    {
        if (self::$legacyMigrated) {
            return;
        }
        self::$legacyMigrated = true;
        PluginStateRepository::migrateLegacyBlacklist();
    }

    /**
     * 加载单个插件
     */
    private static function load(string $name, string $file): void
    {
        try {
            $realPath = realpath($file);
            $pluginDir = realpath(self::$pluginDir);
            if ($realPath === false || $pluginDir === false || !str_starts_with($realPath, $pluginDir)) {
                Logger::error("[Hublog Plugin] 插件路径非法: {$name}");
                return;
            }

            // P1-2 修复：原代码 basename($realPath) 取到的是 "Plugin.php"（文件名），
            // 与下方正则 /^[a-zA-Z][a-zA-Z0-9]*$/ 不匹配（含 "."）→ 所有插件在此静默返回。
            // 改为取所在目录名，与 loadAll() 的 basename($dir) 保持一致。
            $name = basename(dirname($realPath));
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $name)) {
                Logger::error("[Hublog Plugin] 插件名非法: {$name}");
                return;
            }

            require_once $file;
            $class = "Hublog\\Plugin\\{$name}\\Plugin";
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'init')) {
                    $instance->init();
                }
                self::$loaded[$name] = $class;
            }
        } catch (\Throwable $e) {
            Logger::error("[Hublog Plugin] 加载插件 {$name} 失败: " . $e->getMessage());
        }
    }

    /**
     * 获取已加载插件列表
     */
    public static function loaded(): array
    {
        return self::$loaded;
    }

    /**
     * 扫描 plugins/ 目录，返回所有插件的元信息（不实际加载）
     * 元信息来自每个 Plugin.php 文件顶部的 docblock：
     *   @name        插件中文名称
     *   @description 插件功能描述
     *   @version     版本号
     *   @author      作者
     *   @link        项目链接
     */
    public static function scanAll(): array
    {
        $plugins = [];
        if (!is_dir(self::$pluginDir)) {
            return $plugins;
        }
        self::ensureLegacyMigrated();

        foreach (glob(self::$pluginDir . '/*/Plugin.php') as $path) {
            $dir = dirname($path);
            $name = basename($dir);
            $manifest = PluginManifest::fromDir($dir);
            if (!$manifest->valid()) {
                continue; // 非法清单跳过该插件，不影响列表其余项
            }
            // 原 8 个字段的键名、顺序与取值口径逐字保持；清单元信息只做可选追加。
            $item = [
                'name'        => $name,
                'title'       => $manifest->title(),
                'description' => $manifest->description(),
                'version'     => $manifest->version(),
                'author'      => (string)$manifest->field('author', ''),
                'link'        => (string)$manifest->field('link', ''),
                'enabled'     => PluginStateRepository::isEnabled($name),
                'path'        => $name . '/Plugin.php',
            ];
            if ($manifest->source() === 'manifest') {
                $state = PluginStateRepository::get($name) ?? [];
                $item['core_version'] = $manifest->coreVersion();
                $item['install_status'] = (string)($state['install_status'] ?? 'not_installed');
                $item['installed_version'] = (string)($state['installed_version'] ?? '');
                $item['admin_menu'] = $manifest->adminMenu();
            }
            $plugins[] = $item;
        }

        // 按 name 排序
        usort($plugins, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $plugins;
    }

    /**
     * 从 Plugin.php 顶部 docblock 解析 @tag 值（清单回退数据源，公开供 PluginManifest 复用）
     */
    public static function readMeta(string $pluginFile): array
    {
        $content = file_get_contents($pluginFile);
        if ($content === false) return [];

        // 取前 4KB，避免大文件扫描过慢
        $head = substr($content, 0, 4096);

        // 匹配 docblock 里的 @tag 值（支持单行、多行续行、* 前缀）
        // 格式： * @tag  value  或  * @tag\n * value（多行续行）
        $tags = ['name', 'description', 'version', 'author', 'link'];
        $meta = [];

        // 先处理简单的单行 @tag：  * @name  Hublog AI 评论
        foreach ($tags as $tag) {
            if (preg_match('/^\s*\*\s*@' . preg_quote($tag, '/') . '\s+(.+)$/m', $head, $m)) {
                $meta['@' . $tag] = trim($m[1]);
            }
        }

        // description 可能跨多行（直到遇到下一个 @tag 或 */）
        if (!isset($meta['@description']) && preg_match(
            '/\*\s*@description\s+(.+?)(?=\n\s*\*\s*@|\n\s*\*\/|\Z)/s',
            $head,
            $m
        )) {
            // 把多行 description 里的 " * " 前缀都去掉，拼起来
            $desc = preg_replace('/\n\s*\*\s*/', ' ', trim($m[1]));
            $desc = preg_replace('/\s+/', ' ', $desc); // 多余空白折叠
            $meta['@description'] = $desc;
        }

        // 去掉尾部句号/省略号，让后台列表更清爽
        foreach ($meta as $k => &$v) {
            if ($k === '@description') {
                $v = rtrim($v, '。.…');
            }
        }
        unset($v);

        return $meta;
    }
}
