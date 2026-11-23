<?php
/**
 * 插件生命周期编排（E1-2 / E1-3，Q3 卸载语义）
 *
 * install / upgrade / uninstall 三钩子全部可选：method_exists 守卫，未实现时静默跳过；
 * 任何异常都被核心捕获，写 plugin_states.last_error + Logger::error，
 * install_status='failed'，不向上抛、不中断请求（返回值里带 reason 供后台展示）。
 *
 * 领域层不做鉴权：uninstall 的 confirmToken 由控制器（T03）用
 * settings.cron_secret 校验 HMAC 后传入，这里只要求「非空 = 已授权」；
 * 真正删表再转成 bool 交给 PluginSchemaMigrator::dropTables()。
 * AppCenterInstaller 是应用包文件部署，与本类互不调用（E1-4）。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class PluginLifecycle
{
    /** 安装插件（目录由插件名推导） */
    public static function install(string $name): array
    {
        $dir = self::dirForName($name);
        if ($dir === null) {
            return self::fail($name, '插件目录不存在: ' . $name);
        }
        return self::installFrom($dir);
    }

    /** 升级插件：先按清单补齐结构，再调用 upgrade($from, $to) */
    public static function upgrade(string $name, string $from, string $to): array
    {
        $dir = self::dirForName($name);
        if ($dir === null) {
            return self::fail($name, '插件目录不存在: ' . $name);
        }
        return self::upgradeFrom($dir, $from, $to);
    }

    /**
     * 卸载插件：默认只 enabled=0 保留数据；删表需「声明 + 控制器已授权」。
     */
    public static function uninstall(string $name, bool $drop, string $confirmToken): array
    {
        $dir = self::dirForName($name);
        if ($dir === null) {
            return self::fail($name, '插件目录不存在: ' . $name);
        }
        return self::uninstallFrom($dir, $name, $drop, $confirmToken);
    }

    /** 从显式目录安装（测试与未来「上传即安装」入口共用） */
    public static function installFrom(string $dir): array
    {
        $manifest = PluginManifest::fromDir($dir);
        $name = $manifest->name();
        if (!$manifest->valid()) {
            return self::fail($name, implode('；', $manifest->errors()));
        }
        $current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
        $coreError = $manifest->coreVersionError($current);
        if ($coreError !== null) {
            return self::fail($name, $coreError);
        }

        try {
            $violations = PluginSchemaMigrator::validateAgainstContract($manifest);
            if ($violations !== []) {
                throw new \RuntimeException(implode('；', $violations));
            }
            $applied = PluginSchemaMigrator::apply($manifest);
            $instance = self::instanceFor($dir, $name);
            if ($instance !== null) {
                self::invokeHook($instance, 'install', []);
            }
        } catch (\Throwable $e) {
            return self::fail($name, $e->getMessage());
        }

        self::writeState($name, [
            'enabled' => 1,
            'install_status' => 'installed',
            'installed_version' => $manifest->version(),
            'last_error' => '',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
        return [
            'install_status' => 'installed',
            'installed_version' => $manifest->version(),
            'applied' => $applied,
        ];
    }

    /** 从显式目录升级 */
    public static function upgradeFrom(string $dir, string $from, string $to): array
    {
        $manifest = PluginManifest::fromDir($dir);
        $name = $manifest->name();
        if (!$manifest->valid()) {
            return self::fail($name, implode('；', $manifest->errors()));
        }
        $current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
        $coreError = $manifest->coreVersionError($current);
        if ($coreError !== null) {
            return self::fail($name, $coreError);
        }

        try {
            $violations = PluginSchemaMigrator::validateAgainstContract($manifest);
            if ($violations !== []) {
                throw new \RuntimeException(implode('；', $violations));
            }
            $applied = PluginSchemaMigrator::apply($manifest);
            $instance = self::instanceFor($dir, $name);
            if ($instance !== null) {
                self::invokeHook($instance, 'upgrade', [$from, $to]);
            }
        } catch (\Throwable $e) {
            return self::fail($name, $e->getMessage());
        }

        self::writeState($name, [
            'install_status' => 'installed',
            'installed_version' => $to,
            'last_error' => '',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
        return [
            'install_status' => 'installed',
            'installed_version' => $to,
            'from' => $from,
            'to' => $to,
            'applied' => $applied,
        ];
    }

    /** 从显式目录卸载 */
    public static function uninstallFrom(string $dir, string $name, bool $drop, string $confirmToken): array
    {
        $manifest = PluginManifest::fromDir($dir);
        if (!$manifest->valid()) {
            return self::fail($name, implode('；', $manifest->errors()));
        }
        // 领域层不做 HMAC 计算：非空 token = 控制器已校验授权（T03 负责）。
        if ($drop && $confirmToken === '') {
            return self::fail($name, '卸载删表缺少已授权确认（confirm_token）');
        }

        $dropped = [];
        try {
            if ($drop) {
                $dropped = PluginSchemaMigrator::dropTables($manifest, true);
            }
            $instance = self::instanceFor($dir, $name);
            if ($instance !== null) {
                self::invokeHook($instance, 'uninstall', []);
            }
        } catch (\Throwable $e) {
            return self::fail($name, $e->getMessage());
        }

        self::writeState($name, [
            'enabled' => 0,
            'install_status' => 'not_installed',
            'last_error' => '',
        ]);
        return [
            'install_status' => 'not_installed',
            'enabled' => false,
            'dropped' => $dropped,
        ];
    }

    /** 可选钩子调用：没实现就跳过；异常记录后上抛给 install/upgrade/uninstall 统一落 failed */
    private static function invokeHook(object $instance, string $hook, array $args): mixed
    {
        if (!method_exists($instance, $hook)) {
            return null;
        }
        try {
            return $instance->{$hook}(...$args);
        } catch (\Throwable $e) {
            Logger::error("[Hublog PluginLifecycle] 插件钩子 {$hook} 执行失败: " . $e->getMessage());
            throw $e;
        }
    }

    /** 状态回写（含缓存失效），失败只记日志，不让钩子异常掩盖真正的失败原因 */
    private static function writeState(string $name, array $patch): void
    {
        try {
            PluginStateRepository::upsert($name, $patch);
        } catch (\Throwable $e) {
            Logger::error('[Hublog PluginLifecycle] 状态回写失败 ' . $name . ': ' . $e->getMessage());
        }
    }

    /** @return array{install_status:string,reason:string} */
    private static function fail(string $name, string $reason): array
    {
        Logger::error('[Hublog PluginLifecycle] 插件 ' . $name . ' 生命周期失败: ' . $reason);
        self::writeState($name, [
            'install_status' => 'failed',
            'last_error' => mb_substr($reason, 0, 500),
        ]);
        return ['install_status' => 'failed', 'reason' => $reason];
    }

    private static function dirForName(string $name): ?string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name) !== 1) {
            return null;
        }
        $dir = rtrim(Plugin::pluginDir(), '/\\') . '/' . $name;
        return is_dir($dir) ? $dir : null;
    }

    private static function instanceFor(string $dir, string $name): ?object
    {
        $file = rtrim($dir, '/\\') . '/Plugin.php';
        if (!is_file($file)) {
            return null;
        }
        $realPath = realpath($file);
        $realDir = realpath(rtrim($dir, '/\\'));
        if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir)) {
            Logger::error('[Hublog PluginLifecycle] 插件路径非法: ' . $name);
            return null;
        }
        require_once $file;
        $class = 'Hublog\\Plugin\\' . $name . '\\Plugin';
        if (!class_exists($class)) {
            return null;
        }
        return new $class();
    }
}
