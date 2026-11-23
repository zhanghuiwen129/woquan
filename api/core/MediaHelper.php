<?php
/**
 * Hublog 媒体 URL 处理工具
 * 多端适配：将相对路径（如 assets/images/xxx.svg、/uploads/xxx.jpg）补全为绝对 URL
 * App / 小程序无法依赖 <base href>，必须在 API 响应中返回完整 URL
 */

namespace Hublog\Core;

class MediaHelper
{
    /**
     * OAuth 提供方头像 CDN 白名单（只用于 provider 返回的头像，客户端自报头像仍走严格白名单）。
     * key 与 oauth provider 字符串一一对应。
     */
    public const OAUTH_CDN = [
        'github' => ['avatars.githubusercontent.com', 'githubusercontent.com'],
        'google' => ['lh3.googleusercontent.com', 'googleusercontent.com'],
        'wechat' => ['thirdwx.qlogo.cn', 'wx.qlogo.cn'],
    ];

    /**
     * 头像 / 封面 URL 是否可信。
     *
     * 规则（与历史 AuthController::isSafeMediaUrl 完全一致，此处成为唯一实现）：
     *  1) 相对路径（含 /xxx，但排除 //evil.com 协议相对）放行；
     *  2) http(s) 绝对 URL 的 host 命中本站 / MEDIA_BASE_URL / APP_URL（含子域）放行；
     *  3) $extraHosts 提供的额外白名单（OAuth provider CDN）放行；
     *  4) 本人上传记录中登记过的 URL 放行（userId > 0 时）。
     *
     * @param array<int,string> $extraHosts 额外可信 host（小写化后合并，不去重前不做后缀匹配）
     */
    public static function isSafeMediaUrl(string $url, int $userId = 0, array $extraHosts = []): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            return false;
        }
        // 相对路径：/uploads/xxx.png；排除 //evil.com 这类协议相对 URL
        if ($url[0] === '/') {
            return !isset($url[1]) || $url[1] !== '/';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($parts['host']);

        $allowed = [];
        foreach (['MEDIA_BASE_URL', 'APP_URL'] as $const) {
            if (defined($const) && constant($const) !== '') {
                $h = parse_url((string)constant($const), PHP_URL_HOST);
                if ($h) {
                    $allowed[] = strtolower($h);
                }
            }
        }
        $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($httpHost !== '') {
            $allowed[] = strtolower((string)preg_replace('#:\d+$#', '', $httpHost));
        }
        // OAuth 提供方 CDN 域名（由服务端按 provider 选取，客户端不可控）
        $extraHosts = array_map(
            static fn($h): string => strtolower(trim((string)$h)),
            $extraHosts
        );
        $allowed = array_merge($allowed, $extraHosts);
        $allowed = array_values(array_unique(array_filter($allowed)));
        if (empty($allowed)) {
            return false;
        }

        foreach ($allowed as $a) {
            if ($host === $a || substr($host, -strlen('.' . $a)) === '.' . $a) {
                return true;
            }
        }

        // 兜底：云存储直传会产生第三方 CDN 域名，只要确实通过本站登记过即视为可信
        if ($userId > 0) {
            try {
                if (UserRepository::uploadRecordExists($url, $userId)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // uploads 表异常时不放行，走拒绝
            }
        }
        return false;
    }

    /**
     * 预计算的站点根前缀（协议 + 域名 + 子目录）。
     *
     * 为什么需要：absoluteUrl() 每调一次都要重算一遍前缀（读 $_SERVER、rtrim/ltrim 拼接），
     * 而一条列表响应里往往有几十个媒体路径要转换，重复计算纯属浪费。
     * 这里把前缀算一次存起来，后续只做「前缀 + 路径」一次字符串拼接。
     *
     * 缓存失效点：$_SERVER['HTTP_HOST'] / HTTPS 在单次请求内不会变，
     * 所以按请求内缓存是安全的（PHP-FPM 每请求新进程，静态属性自然重置）。
     */
    private static ?string $basePrefix = null;

    /**
     * 取站点根前缀（形如 https://example.com 或 https://example.com/subdir）。
     */
    public static function basePrefix(): string
    {
        if (self::$basePrefix !== null) {
            return self::$basePrefix;
        }

        $basePath = PathResolver::baseUrl();

        if (defined('MEDIA_BASE_URL') && MEDIA_BASE_URL !== '') {
            $base = rtrim(MEDIA_BASE_URL, '/');
            self::$basePrefix = $base . $basePath;
            return self::$basePrefix;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        self::$basePrefix = "{$scheme}://{$host}" . $basePath;

        return self::$basePrefix;
    }

    /**
     * 将单个相对路径转为绝对 URL
     * 已是绝对 URL（http/https 开头）或 data URI 则原样返回
     */
    public static function absoluteUrl(?string $path): ?string
    {
        if (empty($path)) {
            return $path;
        }
        // 已是完整 URL 或 data URI
        if (preg_match('#^(https?://|data:|//)#', $path)) {
            return $path;
        }

        // 【兼容历史数据】早期版本往 DB 里写的是裸 'assets/images/xxx.svg'（相对站点根），
        // 前端会把它拼成 /assets/... → 站点根并没有 assets/images 目录（仅模板目录下有），
        // 所以这里补 templates/{模板}/ 前缀兜底。
        // 新数据已统一写成 '/templates/xxx' 站点根绝对路径（不进本分支，走 buildAbsoluteUrl 转绝对 URL），
        // 存量裸值靠数据迁移 UPDATE 掉；本分支仅作兜底，迁移后即可移除。
        if (str_starts_with($path, 'assets/') && !str_starts_with($path, 'templates/')) {
            // 与前台入口共用 Theme::current()，避免「入口读 active_theme、这里写死常量」两处逻辑分叉。
            // current() 无状态（每次走 site_settings 缓存），切主题后 API 响应里的裸图路径即时跟随；
            // 非法/未配置主题由 resolve() 静默回退 DEFAULT_TEMPLATE，绝不让存量旧图 404。
            $path = \Hublog\Core\Theme::dir(\Hublog\Core\Theme::current()) . $path;
        }

        // 补全为绝对 URL
        return self::buildAbsoluteUrl($path);
    }

    /**
     * 构建绝对 URL
     * 优先使用 MEDIA_BASE_URL 配置，否则从当前请求自动推导
     */
    private static function buildAbsoluteUrl(string $relativePath): string
    {
        $basePath = PathResolver::baseUrl();

        // 如果配置了 MEDIA_BASE_URL（含协议+域名），使用它
        if (defined('MEDIA_BASE_URL') && MEDIA_BASE_URL !== '') {
            $base = rtrim(MEDIA_BASE_URL, '/');
            $path = '/' . ltrim($relativePath, '/');
            // 如果 relativePath 已含 basePath 前缀，不重复添加
            if ($basePath !== '' && str_starts_with($relativePath, $basePath)) {
                return $base . $relativePath;
            }
            return $base . $basePath . $path;
        }

        // 自动推导：从当前请求获取协议和域名
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = '/' . ltrim($relativePath, '/');

        // 如果 relativePath 已含 basePath 前缀，直接拼接
        if ($basePath !== '' && str_starts_with($relativePath, $basePath)) {
            return "{$scheme}://{$host}" . $relativePath;
        }

        return "{$scheme}://{$host}" . $basePath . $path;
    }

    /**
     * 处理用户数据中的媒体字段（avatar, cover）
     * 就地修改传入的数组
     */
    public static function processUser(?array &$user): ?array
    {
        if (empty($user)) {
            return $user;
        }
        if (isset($user['avatar'])) {
            $user['avatar'] = self::absoluteUrl($user['avatar']);
        }
        if (isset($user['cover'])) {
            $user['cover'] = self::absoluteUrl($user['cover']);
        }
        return $user;
    }

    /**
     * 批量处理用户列表
     */
    public static function processUsers(array &$users): array
    {
        foreach ($users as &$user) {
            self::processUser($user);
        }
        unset($user);
        return $users;
    }

    /**
     * 处理圈子数据中的媒体字段（cover + 内嵌 owner 头像）
     * 就地修改传入的数组
     *
     * 背景：circles.cover 存的是站点根相对路径（/uploads/...），App 端 <image>
     * 无法加载相对路径，此前的 processUser/processPost/processComment 都漏了圈子链路，
     * 导致圈主页/发现页/我的圈子/编辑页封面全是裂图。
     */
    public static function processCircle(?array &$circle): ?array
    {
        if (empty($circle)) {
            return $circle;
        }
        if (isset($circle['cover'])) {
            $circle['cover'] = self::absoluteUrl($circle['cover']);
        }
        // 部分接口（如我的入圈申请）把封面别名叫 circle_cover
        if (isset($circle['circle_cover'])) {
            $circle['circle_cover'] = self::absoluteUrl($circle['circle_cover']);
        }
        // 圈子详情接口会内嵌 owner 用户信息（avatar 同样是相对路径）
        if (isset($circle['owner']) && is_array($circle['owner'])) {
            self::processUser($circle['owner']);
        }
        return $circle;
    }

    /**
     * 批量处理圈子列表
     */
    public static function processCircles(array &$circles): array
    {
        foreach ($circles as &$circle) {
            self::processCircle($circle);
        }
        unset($circle);
        return $circles;
    }

    /**
     * 处理动态数据中的媒体字段
     * 包括作者头像、动态图片、视频封面等
     */
    public static function processPost(?array &$post): ?array
    {
        if (empty($post)) {
            return $post;
        }
        // 作者头像
        if (isset($post['avatar'])) {
            $post['avatar'] = self::absoluteUrl($post['avatar']);
        }
        // 作者封面
        if (isset($post['cover'])) {
            $post['cover'] = self::absoluteUrl($post['cover']);
        }
        // 动态图片（可能是 JSON 字符串或数组）
        if (isset($post['images'])) {
            $post['images'] = self::processImagesField($post['images']);
        }
        // 动态视频
        if (isset($post['video_url'])) {
            $post['video_url'] = self::absoluteUrl($post['video_url']);
        }
        // 视频封面
        if (isset($post['video_cover'])) {
            $post['video_cover'] = self::absoluteUrl($post['video_cover']);
        }
        // 附件
        if (isset($post['attachment_url'])) {
            $post['attachment_url'] = self::absoluteUrl($post['attachment_url']);
        }
        return $post;
    }

    /**
     * 批量处理动态列表
     */
    public static function processPosts(array &$posts): array
    {
        foreach ($posts as &$post) {
            self::processPost($post);
        }
        unset($post);
        return $posts;
    }

    /**
     * 处理图片字段（兼容 JSON 字符串和数组两种存储格式）
     */
    private static function processImagesField(mixed $images): mixed
    {
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            if (is_array($decoded)) {
                $images = $decoded;
            } else {
                // 单个 URL 字符串
                return self::absoluteUrl($images);
            }
        }
        if (is_array($images)) {
            foreach ($images as &$url) {
                if (is_string($url)) {
                    $url = self::absoluteUrl($url);
                } elseif (is_array($url) && isset($url['url'])) {
                    $url['url'] = self::absoluteUrl($url['url']);
                }
            }
            unset($url);
        }
        return $images;
    }

    /**
     * 处理评论数据中的媒体字段
     */
    public static function processComment(?array &$comment): ?array
    {
        if (empty($comment)) {
            return $comment;
        }
        if (isset($comment['avatar'])) {
            $comment['avatar'] = self::absoluteUrl($comment['avatar']);
        }
        if (isset($comment['images'])) {
            $comment['images'] = self::processImagesField($comment['images']);
        }
        return $comment;
    }

    /**
     * 批量处理评论列表
     */
    public static function processComments(array &$comments): array
    {
        foreach ($comments as &$comment) {
            self::processComment($comment);
        }
        unset($comment);
        return $comments;
    }
}
