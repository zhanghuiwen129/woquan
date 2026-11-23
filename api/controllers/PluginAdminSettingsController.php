<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\Plugin;
use Hublog\Core\Request;
use Hublog\Core\Response;

/**
 * 插件设置页静态 HTML 读取（E2-4）
 *
 * 从 AdminPluginController 拆出的单一职责：settings.html 必须真实存在于插件目录内，
 * 且 realpath 解析后的终点仍在该插件目录之内；后缀白名单只允许 .html。
 * 插件设置页的配置读写统一走核心 /api/admin/plugin-config，本类不提供任何写接口。
 */
final class PluginAdminSettingsController extends AdminController
{
    /** GET /api/admin/plugin-settings-page：直接输出 text/html（与既有导出接口同款裸响应） */
    public function settingsPage(Request $request): Response
    {
        $this->requireAdmin($request);
        $plugin = trim($request->getString('plugin_name'));
        if ($plugin === '') {
            return Response::error('plugin_name 必填', 400);
        }
        $html = self::readSettingsHtml($plugin);
        if ($html === null) {
            return Response::error('插件设置页不存在或路径非法', 404);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $html;
        exit;
    }

    /**
     * 读取插件 settings.html 内容；路径非法 / 越界 / 后缀不符一律返回 null。
     */
    public static function readSettingsHtml(string $pluginName): ?string
    {
        $path = self::resolveSettingsFile($pluginName);
        if ($path === null) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    /**
     * 解析并校验插件设置页绝对路径，供控制器与测试共用。
     */
    public static function resolveSettingsFile(string $pluginName): ?string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $pluginName) !== 1) {
            return null;
        }
        $root = realpath(rtrim(Plugin::pluginDir(), '/\\'));
        if ($root === false) {
            return null;
        }
        $dir = realpath($root . DIRECTORY_SEPARATOR . $pluginName);
        if ($dir === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        // 候选文件名固定为 settings.html（后缀白名单第一道）
        $candidate = $dir . DIRECTORY_SEPARATOR . 'settings.html';
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }
        // realpath 会跟随符号链接：链接终点必须仍在本插件目录内，且后缀必须是 .html
        if (!str_starts_with($real, $dir . DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'html'
        ) {
            return null;
        }
        return $real;
    }
}
