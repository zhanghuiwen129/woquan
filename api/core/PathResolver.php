<?php
/**
 * Hublog 路径解析器
 * 自动适配根目录/子目录部署，统一生成 URL 和路径
 */

namespace Hublog\Core;

class PathResolver
{
    private static ?string $baseUrl = null;
    private static ?string $basePath = null;

    /**
     * 获取站点根 URL 路径（如 / 或 /blog/）
     */
    public static function baseUrl(): string
    {
        if (self::$baseUrl === null) {
            // 优先使用 SCRIPT_FILENAME + DOCUMENT_ROOT 计算，避免 SCRIPT_NAME 在某些
            // Nginx 配置下包含 PATH_INFO（如 /api/index.php/auth/cover）导致推导错误
            $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot && $scriptFilename && str_starts_with($scriptFilename, $docRoot)) {
                $relativeScript = substr($scriptFilename, strlen($docRoot));
                // $relativeScript 形如 /api/index.php，向上退两级得到项目根
                $dir = dirname(dirname($relativeScript));
                self::$baseUrl = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/\\');
            } else {
                // 回退：使用 SCRIPT_NAME 向上退两级
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
                $dir = dirname(dirname($scriptName));
                self::$baseUrl = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/\\');
            }
        }
        return self::$baseUrl;
    }

    /**
     * 获取站点根物理路径
     */
    public static function basePath(): string
    {
        if (self::$basePath === null) {
            self::$basePath = dirname(__DIR__, 2);
        }
        return self::$basePath;
    }

    /**
     * 站点源地址（scheme://host），不含子目录部署路径
     *
     * 优先使用后台配置的 site_url；未配置或格式非法时，从请求头推导
     * （兼容反向代理的 X-Forwarded-Proto / X-Forwarded-Host）。
     *
     * canonical、og:url、sitemap 的 <loc> 等必须使用绝对地址的场景依赖此方法：
     * 相对地址会被搜索引擎直接忽略，分享卡片的链接也会失效。
     *
     * @param array $settings 站点设置（取其中的 site_url）
     * @return string 形如 https://example.com；无法推导时返回空串
     */
    public static function origin(array $settings = []): string
    {
        $configured = trim((string)($settings['site_url'] ?? ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            $parts = parse_url($configured);
            if (!empty($parts['host'])) {
                $scheme = strtolower($parts['scheme'] ?? 'https');
                $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                return $scheme . '://' . $parts['host'] . $port;
            }
        }

        // 反向代理优先读 X-Forwarded-Proto，其次看 HTTPS
        $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto === '') {
            $https = (string)($_SERVER['HTTPS'] ?? '');
            $proto = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        }
        $proto = strtolower(trim(explode(',', $proto)[0]));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = 'https';
        }

        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '') {
            $host = (string)($_SERVER['SERVER_NAME'] ?? '');
            $port = (string)($_SERVER['SERVER_PORT'] ?? '');
            if ($host !== '' && $port !== '' && !in_array($port, ['80', '443'], true) && strpos($host, ':') === false) {
                $host .= ':' . $port;
            }
        }
        // 只保留合法主机字符，防止 Host 头注入到 canonical / sitemap
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
        if ($host === '') {
            return '';
        }
        return $proto . '://' . $host;
    }

    /**
     * 生成带基路径的 URL
     */
    public static function url(string $path): string
    {
        $base = self::baseUrl();
        $path = '/' . ltrim($path, '/');
        return $base . $path;
    }

    /**
     * 生成 API URL
     */
    public static function apiUrl(string $path = ''): string
    {
        return self::url('/api' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    /**
     * 生成上传文件 URL
     */
    public static function uploadUrl(string $relativePath): string
    {
        return self::url('/uploads/' . ltrim($relativePath, '/'));
    }

    /**
     * 生成模板资源 URL
     */
    public static function assetUrl(string $relativePath): string
    {
        return self::url('/' . ltrim($relativePath, '/'));
    }

    /**
     * 解析物理路径为 URL 相对路径
     */
    public static function toUrl(string $absolutePath): string
    {
        $base = self::basePath();
        if (str_starts_with($absolutePath, $base)) {
            return self::url(substr($absolutePath, strlen($base)));
        }
        return $absolutePath;
    }
}
