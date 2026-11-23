<?php
/**
 * 我圈 (woquan) - 轻量多用户朋友圈社区
 * Copyright 2026 woquan
 * Licensed under the Apache License, Version 2.0
 */

declare(strict_types=1);

$hublogStartTime = microtime(true);

// S6/S7：启动引导与 api/index.php 共用同一份实现。
// 原实现无条件回显 FATAL 消息 + 绝对路径 + 行号，且用公开的
// 'change-me-in-production' 兜底 JWT_SECRET —— 两处都属于「加固只落一半」。
require_once __DIR__ . '/bootstrap.php';
\Hublog\Bootstrap\registerFatalHandler(false);

require_once __DIR__ . '/config.php';

// P3 四入口初始化收敛：公共默认常量统一由 bootstrap 定义（config.php 优先）。
\Hublog\Bootstrap\defineDefaults();
\Hublog\Bootstrap\assertJwtSecret(false);

/**
 * 把 Markdown 正文转成纯文本，供 SEO 的 title / description 使用
 *
 * 直接用原文会出现 ![](url)、## 、** 等标记，搜索结果摘要里很难看，
 * emoji 还会挤占标题长度。正文渲染仍走主题自己的 Markdown 管线，这里只服务 SEO。
 */
function seo_plain_text(string $markdown, int $limit = 0): string
{
    $text = strip_tags((string)$markdown);
    // 正文里可能混入非法 UTF-8 字节（历史上被截断的多字节字符）：
    // 带 /u 修饰符的正则遇到非法字节会直接返回 null，进而让整页 500。
    // 这里先丢弃非法字节，再做正则替换；后面的 null 判断是第二道保险。
    if (!mb_check_encoding($text, 'UTF-8')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($clean)) {
            $text = $clean;
        }
    }
    $patterns = [
        '/!\[[^\]]*\]\([^)]*\)/',   // 图片
        '/\[([^\]]*)\]\([^)]*\)/',   // 链接保留文字
        '/`{1,3}[^`]*`{1,3}/',        // 行内代码
        '/^#{1,6}\s*/m',              // 标题标记
        '/^\s*[-*+]\s+/m',            // 列表符号
        '/^\s*>\s?/m',                // 引用
        '/\*{1,2}|_{1,2}/',           // 强调
    ];
    foreach ($patterns as $re) {
        // preg_replace 遇到非法 UTF-8 会返回 null，必须保留原文而不是让后续调用崩掉
        $out = preg_replace($re, '', $text);
        if ($out !== null) {
            $text = $out;
        }
    }
    // emoji
    $out = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text);
    if ($out !== null) {
        $text = $out;
    }
    $out = preg_replace('/\s+/u', ' ', $text);
    if ($out !== null) {
        $text = $out;
    }
    $text = trim($text);
    if ($limit > 0 && mb_strlen($text) > $limit) {
        $text = mb_substr($text, 0, $limit);
    }
    return $text;
}

/**
 * 把 Markdown 正文转成段落化纯文本 HTML（服务端首屏用）
 *
 * 段落之间以空行分隔，逐段折叠空白；图片/链接标记/emoji 会被清除。
 * 主题可在骨架里放 {{POST_CONTENT_TEXT}} 消费它，实现「不执行 JS 也能读到正文」。
 */
function seo_paragraphs(string $markdown, int $maxBlocks = 0): string
{
    $blocks = preg_split('/\R{2,}/', (string)$markdown);
    $out = [];
    foreach ($blocks as $block) {
        $p = seo_plain_text($block);
        if ($p === '') {
            continue;
        }
        $out[] = '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($maxBlocks > 0 && count($out) >= $maxBlocks) {
            break;
        }
    }
    return implode('', $out);
}

// 自动适配根目录/子目录部署
// Nginx 与 Apache 在 SCRIPT_NAME/PHP_SELF 上表现可能不同，优先用 SCRIPT_NAME，fallback 到 PHP_SELF
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/';
if ($scriptName === '' || $scriptName === '/api/index.php') {
    // 安全兜底：脚本名缺失或被错误替换时，使用请求路径估算
    $scriptName = '/index.php';
}
// 部分 Nginx 配置（如宝塔 php/81.conf）会将 SCRIPT_NAME 设为完整 URI（含 PATH_INFO），
// 例如 /index.php/u/admin，需要剥离 PATH_INFO 部分以获得真实的脚本路径
if (!empty($_SERVER['PATH_INFO'])) {
    $pathInfo = $_SERVER['PATH_INFO'];
    if ($pathInfo !== '' && str_ends_with($scriptName, $pathInfo)) {
        $scriptName = substr($scriptName, 0, strlen($scriptName) - strlen($pathInfo));
    }
}
// 兜底：如果 SCRIPT_NAME 不以 .php 结尾，说明可能仍含 PATH_INFO，用 SCRIPT_FILENAME 推导
if (!preg_match('/\.php$/', $scriptName)) {
    $scriptFile = basename($_SERVER['SCRIPT_FILENAME'] ?? 'index.php');
    $scriptName = ($scriptName === '/' ? '' : dirname($scriptName)) . '/' . $scriptFile;
    $scriptName = '/' . ltrim($scriptName, '/');
}
$dir = dirname($scriptName);
$base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');

// 未安装则跳转安装页面（S8：锁文件在站点根目录，兼容老部署的 cache/install.lock）
if (!\Hublog\Bootstrap\isInstalled(__DIR__)) {
    header('Location: ' . $base . '/install.php');
    exit;
}

// 自动加载并执行数据库迁移（无感升级）
require_once __DIR__ . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
\Hublog\Core\Migration::run();

// 解析请求路径
// 支持两种访问模式（与伪静态开关解耦，前端页面路由始终可用）：
// 1. PATH_INFO 模式（默认，不依赖 Web 服务器重写）：
//    请求 /index.php/search → PATH_INFO=/search → 补 $base 前缀 → /search
//    请求 /sub/index.php/u/admin → PATH_INFO=/u/admin → 补 $base → /sub/u/admin
// 2. 重写模式（伪静态开启时，需 .htaccess/nginx 配置）：
//    请求 /search 被 Web 服务器重写到 index.php，REQUEST_URI=/search
if (!empty($_SERVER['PATH_INFO'])) {
    // PATH_INFO 模式：补上 $base 前缀以匹配后续正则
    $path = $base . $_SERVER['PATH_INFO'];
} else {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    // PATH_INFO 缺失兜底：部分 Web 服务器（如未开启 cgi.fix_pathinfo 的 Nginx / 某些内置服务器）
    // 不会填充 $_SERVER['PATH_INFO']，此时 /index.php/category 会带着脚本名进入路由匹配而回退首页。
    // 这里按 SCRIPT_NAME 精确剥离前缀（含子目录部署），与上面的 PATH_INFO 分支得到同一 $path。
    if ($scriptName !== '' && ($path === $scriptName || strpos($path, $scriptName . '/') === 0)) {
        $suffix = substr($path, strlen($scriptName));
        $path = $base . ($suffix === '' ? '/' : $suffix);
    }
}

// 后台管理是独立目录（admin/index.html），不走前端路由。
// 当用户通过 /index.php/admin 访问时，重定向到 /admin/ 直接访问独立目录。
if (preg_match('#^' . preg_quote($base, '#') . '/admin/?$#', $path)) {
    header('Location: ' . $base . '/admin/');
    exit;
}

// 加载站点设置用于注入 SEO/统计信息
$siteSettings = [];
try {
    $siteSettings = \Hublog\Core\Cache::remember('site_settings', function () {
        $rows = \Hublog\Core\Database::fetchAll("SELECT `key`, `value` FROM settings");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }, 3600);
} catch (\Throwable $e) {
    // settings 表不存在时触发迁移重试
    \Hublog\Core\Cache::delete('site_settings');
    try {
        $rows = \Hublog\Core\Database::fetchAll("SELECT `key`, `value` FROM settings");
        foreach ($rows as $row) {
            $siteSettings[$row['key']] = $row['value'];
        }
    } catch (\Throwable $e2) {
        // 迁移仍未完成，使用默认值
    }
}

// SEO 资源：根路径 /robots.txt 与 /sitemap.xml
// 站点根的 .xml/.txt 请求会被 Web 服务器的静态规则截走（实测返回前台 HTML 而非 XML），
// 走不到 /api/index.php/... 形式，只能由前台入口直接输出。
if (preg_match('#^' . preg_quote($base, '#') . '/(robots\.txt|sitemap\.xml)$#', $path, $seoMatches)) {
    if ($seoMatches[1] === 'robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo \Hublog\Core\Sitemap::robots($siteSettings, $base);
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        echo \Hublog\Core\Sitemap::xml($siteSettings, $base);
    }
    exit;
}

// 前台主题：从 settings.active_theme 解析，三重校验（格式白名单 + 目录/骨架存在 + realpath 未越界），
// 非法值静默回退 DEFAULT_TEMPLATE（管理员手滑写错不能让整站 500）。
// 这里直接复用上面已读到的 $siteSettings，不产生任何额外读库/缓存成本。
$theme = \Hublog\Core\Theme::resolve(isset($siteSettings['active_theme']) ? (string)$siteSettings['active_theme'] : null);
$themeDir = __DIR__ . '/templates/' . $theme . '/';

$siteName = $siteSettings['site_name'] ?? '我圈';
$siteDesc = $siteSettings['site_desc'] ?? '轻量级多用户社区博客系统';
$siteKeywords = $siteSettings['site_keywords'] ?? '我圈,博客,社区,朋友圈';
$siteDescription = $siteSettings['site_description'] ?? $siteDesc;
$siteIcp = $siteSettings['site_icp'] ?? '';
$statsCode = $siteSettings['site_stats_code'] ?? '';
$executionTime = round((microtime(true) - $hublogStartTime) * 1000, 2);

// 动态详情页 /p/{id}
$postDetail = null;
$categoryDetail = null;
// SSR 固定游客视角：首屏/Meta/JSON-LD 全部按游客口径渲染，不引入登录态。
// 片段在主查询与下面的首屏辅助查询中共用。
[$ssrVisWhere, $ssrVisParams] = \Hublog\Core\VisibilityPolicy::where(0);
if (preg_match('#^' . preg_quote($base, '#') . '/p/(\d+)/?$#', $path, $matches)) {
    $templatePath = $themeDir . 'post.html';
    try {
        $stmt = \Hublog\Core\Database::getInstance()->prepare(
            "SELECT p.id, p.title, p.content, p.images, p.created_at, p.updated_at, p.user_id, p.post_type,"
            . " p.visibility, p.circle_id,"
            . " u.nickname, u.username, u.avatar"
            . " FROM posts p LEFT JOIN users u ON u.id = p.user_id"
            . " WHERE p.id = ? AND p.status = 1 AND {$ssrVisWhere} LIMIT 1"
        );
        $stmt->execute(array_merge([(int)$matches[1]], $ssrVisParams));
        $postDetail = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        $postDetail = null;
    }
    // 二次内存判定：SQL 片段与权威策略同口径，防止未来 SELECT/WHERE 漂移造成绕过。
    if ($postDetail && !\Hublog\Core\VisibilityPolicy::canView($postDetail, 0)) {
        $postDetail = null;
    }
    if (!$postDetail) {
        http_response_code(404);
        exit('动态不存在');
    }
    // V97：内容付费墙 —— 首屏是游客视角，付费内容只输出试读。
    // 在源头截断 $postDetail['content']，下游 seo_paragraphs / og / JSON-LD / POST_CONTENT_TEXT
    // 全部派生自它，一处裁剪全覆盖，杜绝付费全文从 SEO 元信息泄露。
    $paywallInfo = ['locked' => false];
    $paywallHtml = '';
    try {
        $paywallInfo = \Hublog\Core\ContentPayService::paywallForSeo($postDetail);
        if (!empty($paywallInfo['locked'])) {
            $postDetail['content'] = \Hublog\Core\ContentPayService::preview(
                (string)$postDetail['content'],
                (int)($paywallInfo['preview_ratio'] ?? 30)
            );
            $postDetail['images'] = ''; // 首屏只输出试读，不暴露媒体原 URL
            $pLabel = ($paywallInfo['product_type'] ?? 'post') === 'column' ? '专栏' : '单篇';
            $pPrice = number_format((float)($paywallInfo['price'] ?? 0), 2);
            $postPathBase = $base === '' ? '' : $base;
            $pUrl = $postPathBase . '/p/' . (int)$postDetail['id'];
            $paywallHtml = '<div class="paywall-card" data-paywall="1"'
                . ' data-product-type="' . htmlspecialchars((string)($paywallInfo['product_type'] ?? 'post'), ENT_QUOTES, 'UTF-8') . '"'
                . ' data-target-id="' . (int)($paywallInfo['target_id'] ?? 0) . '"'
                . ' data-price="' . htmlspecialchars($pPrice, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-post-id="' . (int)$postDetail['id'] . '"'
                . ' data-login-url="' . htmlspecialchars($postPathBase . '/login', ENT_QUOTES, 'UTF-8') . '"'
                . ' data-post-url="' . htmlspecialchars($pUrl, ENT_QUOTES, 'UTF-8') . '">'
                . '<p class="paywall-title">剩余内容需付费解锁</p>'
                . '<p class="paywall-price">¥' . htmlspecialchars($pPrice, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<button type="button" class="paywall-unlock" data-action="content-unlock">解锁' . $pLabel . '全文</button>'
                . '</div>';
        }
    } catch (\Throwable $e) {
        // 付费判定失败一律按「未锁」降级，绝不因付费链路让详情页 500
        $paywallInfo = ['locked' => false];
        $paywallHtml = '';
    }
} elseif (preg_match('#^' . preg_quote($base, '#') . '/u/([a-zA-Z0-9_-]+)/followers/?$#', $path, $matches)) {
    // 用户粉丝列表页 /u/{username}/followers
    $templatePath = $themeDir . 'followers.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/u/([a-zA-Z0-9_-]+)/following/?$#', $path, $matches)) {
    // 用户关注列表页 /u/{username}/following
    $templatePath = $themeDir . 'following.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/u/([a-zA-Z0-9_-]+)/?$#', $path, $matches)) {
    // 用户主页 /u/{username}
    $templatePath = $themeDir . 'user.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/followers/?$#', $path)) {
    // 我的粉丝列表页 /followers
    $templatePath = $themeDir . 'followers.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/following/?$#', $path)) {
    // 我的关注列表页 /following
    $templatePath = $themeDir . 'following.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/pinned/?$#', $path)) {
    // 置顶动态列表页 /pinned
    $templatePath = $themeDir . 'pinned.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/favorites/?$#', $path)) {
    // 我的收藏列表页 /favorites
    $templatePath = $themeDir . 'favorites.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/go/?$#', $path)) {
    // 外站跳转提示页 /go
    $templatePath = $themeDir . 'go.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/c/(\d+)/?$#', $path, $matches)) {
    // 分类聚合页 /c/{id}
    $templatePath = $themeDir . 'category.html';
    try {
        $stmt = \Hublog\Core\Database::getInstance()->prepare(
            "SELECT id, name FROM categories WHERE id = ? LIMIT 1"
        );
        $stmt->execute([(int)$matches[1]]);
        $categoryDetail = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        $categoryDetail = null;
    }
} elseif (preg_match('#^' . preg_quote($base, '#') . '/topic/(.+?)/?$#', $path, $matches)) {
    // 话题聚合页 /topic/{name}
    $templatePath = $themeDir . 'topic.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/circles/?$#', $path)) {
    // V49：圈子列表页 /circles
    $templatePath = $themeDir . 'circles.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/circle/(\d+)/?$#', $path, $matches)) {
    // V49：圈子详情页 /circle/{id}
    $templatePath = $themeDir . 'circle.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/search/?$#', $path)) {
    // 搜索页 /search
    $templatePath = $themeDir . 'search.html';
    $searchQuery = $_GET['q'] ?? '';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/chat/?$#', $path)) {
    // 聊天页 /chat
    $templatePath = $themeDir . 'chat.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/messages/?$#', $path)) {
    // 消息中心页 /messages
    $templatePath = $themeDir . 'messages.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/reset-password/?$#', $path)) {
    // V36 新增：密码重置页 /reset-password
    $templatePath = $themeDir . 'reset-password.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/wallet/?$#', $path)) {
    // V67：会员中心 / 钱包（余额 / 会员档位 / 积分抵扣 / 订单）
    $templatePath = $themeDir . 'wallet.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/points/?$#', $path)) {
    // V68：积分中心（月签到 / 积分任务 / 积分流水）
    $templatePath = $themeDir . 'points.html';
} elseif (preg_match('#^' . preg_quote($base, '#') . '/([a-z0-9_-]+)/?$#', $path, $matches)
    && !in_array($matches[1], ['index', 'install', 'health', 'api', 'admin', 'cron'], true)
    && file_exists($themeDir . $matches[1] . '.html')) {
    // 主题扩展页：由主题目录自动发现。主题作者只需在 templates/{主题}/ 下放一个同名 .html，
    // 即可获得 /{name} 路由，无需改动本文件（框架侧零改动）。
    // URL 段限 [a-z0-9_-] + 排除保留字，且拼接后必须落在当前主题目录内，天然防路径穿越；
    // 主题未提供该页时自动回退首页，行为不变。
    $templatePath = $themeDir . $matches[1] . '.html';
} else {
    $templatePath = $themeDir . 'index.html';
    // 未命中任何路由：可能是首页，也可能是未知路径（软 404）。
    // 未知路径统一输出 noindex，避免无限 URL 空间被索引；
    // 状态码与「回退首页」行为保持不变，不影响主题扩展页自动发现。
    $normalizePath = static function ($p) {
        $p = rtrim((string)$p, '/');
        return $p === '' ? '/' : $p;
    };
    $homeCandidates = [
        $base === '' ? '/' : $base . '/',
        $base === '' ? '' : $base,
        $base === '' ? '/index.php' : $base . '/index.php',
        $base === '' ? '/index.php/' : $base . '/index.php/',
    ];
    $isUnknownPath = !in_array($normalizePath($path), array_map($normalizePath, $homeCandidates), true);
}

// S6：生产不回显模板绝对路径，只写服务端日志；DEBUG 下才显示具体路径。
if (!file_exists($templatePath)) {
    error_log('[Hublog TEMPLATE] 模板文件缺失: ' . $templatePath);
    http_response_code(500);
    exit(defined('APP_DEBUG') && APP_DEBUG
        ? ('模板文件缺失: ' . $templatePath)
        : '页面暂时不可用，请稍后重试');
}

$html = file_get_contents($templatePath);
if ($html === false) {
    error_log('[Hublog TEMPLATE] 模板文件读取失败: ' . $templatePath);
    http_response_code(500);
    exit(defined('APP_DEBUG') && APP_DEBUG
        ? ('模板文件读取失败（权限不足）: ' . $templatePath)
        : '页面暂时不可用，请稍后重试');
}

// 注入 <base> 标签确保相对资源路径基于模板目录正确解析（避免重复注入）
// $base 为空表示根目录部署，base href 必须以 / 开头才能正确指向站点根
// $theme 决定相对资源（CSS/JS/图片）从哪个主题目录解析——切主题后全套资源随之切换
$baseHref = $base === '' ? '/templates/' . $theme . '/' : $base . '/templates/' . $theme . '/';
$baseTag = '<base href="' . $baseHref . '">';
// 只在剥离 HTML 注释后检测真实的 <base> 标签，避免注释里出现 "<base" 字样时误判为已注入。
// 历史缺陷：post.html 的注释里写了 <base>，令 strpos 命中并跳过注入，详情页 CSS/JS 相对路径全部解析失败。
$htmlWithoutComments = preg_replace('/<!--.*?-->/s', '', $html);
if (!preg_match('/<base\b/i', $htmlWithoutComments)) {
    $html = preg_replace('/(<head[^>]*>)/i', "$1\n    " . $baseTag, $html, 1);
}

// 给前端暴露部署路径，JS 优先读取该变量，避免从 script src 推导失败
$baseJs = $base === '' ? '/' : $base;
// 伪静态开关：控制前端页面 URL 格式
//   开启：/search、/u/admin（需 Web 服务器重写规则）
//   关闭：/index.php/search、/index.php/u/admin（PATH_INFO 模式，开箱即用）
$urlRewrite = ($siteSettings['url_rewrite'] ?? '0') === '1';
// HUBLOG_PAGE_BASE：页面前缀（不含末尾斜杠，根目录为空字符串）
//   伪静态开启：根目录=''，子目录='/sub'
//   伪静态关闭：根目录='/index.php'，子目录='/sub/index.php'
if ($urlRewrite) {
    $pageBase = $base === '' ? '' : $base;
} else {
    $pageBase = $base === '' ? '/index.php' : $base . '/index.php';
}
$html = str_replace(
    '</head>',
    "<script>window.HUBLOG_BASE_URL='" . htmlspecialchars($baseJs, ENT_QUOTES, 'UTF-8') . "';"
    . "window.HUBLOG_URL_REWRITE=" . ($urlRewrite ? 'true' : 'false') . ";"
    . "window.HUBLOG_PAGE_BASE='" . htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') . "';</script>\n</head>",
    $html
);
// 主题局部模板（<!-- @include partials/xxx.html -->）服务端展开。
// 主题原本靠前端 includes.js 拉取展开 —— 但那样爬虫看到的首屏 HTML 里没有顶栏 / 频道栏 /
// 底栏，站内导航链接全部缺失（只剩正文里的链接）。这里在服务端先展开，
// 前端再跑 expandIncludes() 时找不到 @include 注释，不会重复插入。
// 只认 partials/ 目录下的 [A-Za-z0-9_-]+\.html，路径固定在当前主题目录内，无穿越风险。
$htmlExpanded = preg_replace_callback(
    '/<!--\s*@include\s+partials\/([A-Za-z0-9_-]+\.html)\s*-->/',
    static function ($m) use ($themeDir) {
        $file = $themeDir . 'partials/' . $m[1];
        if (!is_file($file)) {
            return '';
        }
        $content = @file_get_contents($file);
        return is_string($content) ? $content : '';
    },
    $html
);
// preg_replace 失败（正则异常等）时保留原 HTML，绝不能把已注入的内容弄丢
if (is_string($htmlExpanded)) {
    $html = $htmlExpanded;
}

// 页面前缀占位符：主题骨架里可用 {{PAGE_BASE}}/link 写站内绝对路径，
// 伪静态开启时为空串（/xxx），关闭时为 /index.php（/index.php/xxx），两种部署都可用。
$html = str_replace('{{PAGE_BASE}}', htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'), $html);

// 注入页面标题与 SEO 信息
if ($postDetail) {
    $plainContent = seo_plain_text((string)$postDetail['content']);
    if (!empty($postDetail['title'])) {
        $pageTitle = $postDetail['title'] . ' - ' . $siteName;
    } else {
        $pageTitle = seo_plain_text((string)$postDetail['content'], 30) . ' - ' . $siteName;
    }
    $pageDescription = seo_plain_text((string)$postDetail['content'], 300);
    $html = str_replace('{{PAGE_TITLE}}', htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_DESCRIPTION}}', htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_KEYWORDS}}', htmlspecialchars($siteKeywords, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_ID}}', (string)$postDetail['id'], $html);
} elseif ($categoryDetail) {
    $pageTitle = $categoryDetail['name'] . ' - ' . $siteName;
    $pageDescription = $siteDescription;
    $html = str_replace('{{PAGE_TITLE}}', htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_DESCRIPTION}}', htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_KEYWORDS}}', htmlspecialchars($siteKeywords, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_ID}}', '', $html);
} else {
    $indexTitle = $siteName . ' - ' . $siteDesc;
    $html = str_replace('{{PAGE_TITLE}}', htmlspecialchars($indexTitle, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_DESCRIPTION}}', htmlspecialchars($siteDescription, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{PAGE_KEYWORDS}}', htmlspecialchars($siteKeywords, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_ID}}', '', $html);
}

// V36 新增：注入 Open Graph 和 Twitter Card 元标签（社交分享优化）
// canonical / og:url 必须是绝对地址：相对地址会被搜索引擎忽略，分享出去的链接也无法定位。
// 优先用后台配置的 site_url，未配置时从请求头推导（兼容反向代理）。
$siteOrigin = \Hublog\Core\PathResolver::origin($siteSettings);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($requestPath, $base)) {
    $requestPath = substr($requestPath, strlen($base));
}
if ($requestPath === '') {
    $requestPath = '/';
}
$currentUrlRaw = $siteOrigin . $requestPath;
$currentUrl = htmlspecialchars($currentUrlRaw, ENT_QUOTES, 'UTF-8');
// 分享封面：文章页取首图，其余页面用站点 logo
$postImages = [];
$postImagesAbs = [];
if ($postDetail && !empty($postDetail['images'])) {
    $decodedImages = json_decode((string)$postDetail['images'], true);
    if (is_array($decodedImages)) {
        foreach ($decodedImages as $im) {
            $imgUrl = is_string($im) ? $im : (is_array($im) ? ($im['url'] ?? $im['src'] ?? '') : '');
            $imgUrl = trim((string)$imgUrl);
            if ($imgUrl === '') {
                continue;
            }
            $postImages[] = $imgUrl;
            $postImagesAbs[] = preg_match('#^https?://#i', $imgUrl)
                ? $imgUrl
                : $siteOrigin . '/' . ltrim($imgUrl, '/');
        }
    }
}
$ogImage = $siteSettings['site_logo'] ?? '';
if ($postImagesAbs) {
    $ogImage = $postImagesAbs[0];
} elseif ($ogImage !== '' && !preg_match('#^https?://#i', $ogImage) && str_starts_with($ogImage, '/')) {
    $ogImage = $siteOrigin . $ogImage;
}
$ogImage = htmlspecialchars((string)$ogImage, ENT_QUOTES, 'UTF-8');
$ogTitle = isset($pageTitle) ? $pageTitle : ($indexTitle ?? $siteName);
$ogDesc = isset($pageDescription) ? $pageDescription : ($siteDescription ?? '');
$ogTags = "<meta property=\"og:site_name\" content=\"" . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . "\">"
    . "<meta property=\"og:title\" content=\"" . htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') . "\">"
    . "<meta property=\"og:description\" content=\"" . htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8') . "\">"
    . "<meta property=\"og:url\" content=\"{$currentUrl}\">"
    . "<meta property=\"og:type\" content=\"" . ($postDetail ? 'article' : 'website') . "\">"
    . "<meta name=\"twitter:card\" content=\"summary_large_image\">"
    . "<meta name=\"twitter:title\" content=\"" . htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') . "\">"
    . "<meta name=\"twitter:description\" content=\"" . htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8') . "\">";
if ($ogImage) {
    $ogTags .= "<meta property=\"og:image\" content=\"" . htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') . "\"><meta name=\"twitter:image\" content=\"" . htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') . "\">";
}
$html = str_replace('</head>', $ogTags . "\n</head>", $html);

// V36 新增：注入 JSON-LD 结构化数据（SEO 优化）
// 文章页必须用真实发布时间与作者：datePublished 若取服务器当前时间，会让全站文章
// 时间雷同、都被判定为「刚发布」，是结构化数据的典型错误。
if ($postDetail) {
    $authorName = trim((string)($postDetail['nickname'] ?? '')) ?: trim((string)($postDetail['username'] ?? ''));
    if ($authorName === '') {
        $authorName = $siteName;
    }
    $publishedAt = !empty($postDetail['created_at']) ? strtotime((string)$postDetail['created_at']) : time();
    $modifiedAt = !empty($postDetail['updated_at']) ? strtotime((string)$postDetail['updated_at']) : $publishedAt;
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $currentUrlRaw],
        'headline' => $postDetail['title'] ?? mb_substr(strip_tags($postDetail['content']), 0, 60),
        'description' => mb_substr(strip_tags($postDetail['content']), 0, 300),
        'url' => $currentUrlRaw,
        'datePublished' => date('c', $publishedAt),
        'dateModified' => date('c', $modifiedAt),
        'author' => ['@type' => 'Person', 'name' => $authorName],
        'publisher' => ['@type' => 'Organization', 'name' => $siteName],
    ];
    if ($postImagesAbs) {
        $jsonLd['image'] = $postImagesAbs;
    }
    $articleBody = seo_plain_text((string)$postDetail['content'], 3000);
    if ($articleBody !== '') {
        $jsonLd['articleBody'] = $articleBody;
    }
    $logoUrl = trim((string)($siteSettings['site_logo'] ?? ''));
    if ($logoUrl !== '') {
        if (!preg_match('#^https?://#i', $logoUrl) && str_starts_with($logoUrl, '/')) {
            $logoUrl = $siteOrigin . $logoUrl;
        }
        $jsonLd['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => $logoUrl];
    }
} else {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'description' => $siteDesc,
        'url' => $currentUrlRaw
    ];
}
$html = str_replace('</head>', '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n</head>", $html);

// V36 新增：canonical 标签
$html = str_replace('</head>', '<link rel="canonical" href="' . $currentUrl . '">' . "\n</head>", $html);

// 未知路径（软 404）输出 noindex：状态码与回退首页行为不变，
// 但不让无限 URL 空间进入索引。canonical 仍指向自身，避免与首页争权重。
// 搜索结果页（带关键词）同样 noindex：任意关键词组合都能生成一个页面，
// 收录只会制造重复内容并稀释权重。
$isSearchResult = preg_match('#^' . preg_quote($base, '#') . '/search/?$#', $path)
    && trim((string)($_GET['q'] ?? '')) !== '';
if (!empty($isUnknownPath) || $isSearchResult) {
    $html = str_replace('</head>', '<meta name="robots" content="noindex,follow">' . "\n</head>", $html);
}

// 文章数据注入：供主题层做 SSR 首屏使用。这里只提供数据，不触碰任何主题 DOM，
// 主题不消费 window.__HUBLOG_POST__ 时行为与此前完全一致。
if ($postDetail) {
    $postJson = json_encode([
        'id' => (int)$postDetail['id'],
        'title' => $postDetail['title'],
        'content' => $postDetail['content'],
        'created_at' => $postDetail['created_at'],
        'updated_at' => $postDetail['updated_at'],
        'author' => [
            'id' => (int)$postDetail['user_id'],
            'nickname' => $postDetail['nickname'],
            'username' => $postDetail['username'],
            'avatar' => $postDetail['avatar'],
        ],
        'images' => $postImagesAbs,
        'canonical' => $currentUrlRaw,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // 正文可能含 </script>，必须转义，否则会提前闭合脚本块
    $postJson = str_replace('</', '<\/', $postJson);
    $html = str_replace('</head>', '<script>window.__HUBLOG_POST__=' . $postJson . ';</script>' . "\n</head>", $html);
}

// 搜索页变量
$searchQuery = $searchQuery ?? '';
$html = str_replace('{{SEARCH_QUERY}}', htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'), $html);
$html = str_replace('{{SITE_NAME}}', htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'), $html);

// 文章页：把标题/作者/时间/正文纯文本/首图注入通用占位符。
// 主题自行决定是否消费（zixunfeed 用它做服务端首屏，让不执行 JS 的爬虫也能读到正文）；
// 未使用这些占位符的主题行为不变。
if ($postDetail) {
    $postTitleText = trim((string)($postDetail['title'] ?? ''));
    if ($postTitleText === '') {
        $postTitleText = seo_plain_text((string)$postDetail['content'], 40);
    }
    $postAuthorText = trim((string)($postDetail['nickname'] ?? '')) ?: trim((string)($postDetail['username'] ?? ''));
    $postTimeText = !empty($postDetail['created_at'])
        ? date('Y-m-d H:i', strtotime((string)$postDetail['created_at']))
        : '';
    $postContentHtml = seo_paragraphs((string)$postDetail['content'], 20);
    $postImageText = $postImagesAbs[0] ?? '';
    $postCoverHtml = '';
    if ($postImageText !== '') {
        $postCoverHtml = '<figure class="cover"><img src="' . htmlspecialchars($postImageText, ENT_QUOTES, 'UTF-8')
            . '" alt="' . htmlspecialchars($postTitleText, ENT_QUOTES, 'UTF-8') . '"></figure>';
    }

    $html = str_replace('{{POST_TITLE}}', htmlspecialchars($postTitleText, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_AUTHOR}}', htmlspecialchars($postAuthorText, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_TIME}}', htmlspecialchars($postTimeText, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_CONTENT_TEXT}}', $postContentHtml, $html);
    $html = str_replace('{{POST_IMAGE}}', htmlspecialchars($postImageText, ENT_QUOTES, 'UTF-8'), $html);
    $html = str_replace('{{POST_COVER}}', $postCoverHtml, $html);

    // 面包屑结构化数据（配合页面上的可见面包屑）
    $breadcrumbLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $siteOrigin . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '正文', 'item' => $currentUrlRaw],
        ],
    ];
    $html = str_replace(
        '</head>',
        '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n</head>",
        $html
    );
}

// 注入页脚信息（版权、执行时间、备案号）
$friendLinks = [];
try {
    $friendLinks = \Hublog\Core\Cache::remember('friend_links_public', function () {
        return \Hublog\Core\Database::fetchAll(
            "SELECT id, name, url, description FROM friend_links WHERE status = 1 ORDER BY sort_order ASC, id ASC LIMIT 50"
        );
    }, 3600);
} catch (\Throwable $e) {
    $friendLinks = [];
}
$footerData = [
    'site_name' => $siteName,
    'site_icp' => $siteIcp,
    'execution_time' => $executionTime,
    'year' => date('Y'),
    'friend_links' => $friendLinks
];
$html = str_replace('</body>', "<script>window.HUBLOG_FOOTER_DATA=" . json_encode($footerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";</script>\n</body>", $html);

// 注入统计代码（放在 </body> 前）
if ($statsCode) {
    $html = str_replace('</body>', $statsCode . "\n</body>", $html);
}

// 给 app.js 追加文件修改时间戳，避免前端代码更新后浏览器缓存旧版本
// 版本号取 app.js 及所有模块文件的最新修改时间，确保任意模块更新都能刷新缓存
$appJsPath = $themeDir . 'assets/js/app.js';
$modulesDir = $themeDir . 'assets/js/modules';
$appJsVersion = file_exists($appJsPath) ? filemtime($appJsPath) : time();
if (is_dir($modulesDir)) {
    foreach (glob($modulesDir . '/*.js') as $moduleFile) {
        $mtime = filemtime($moduleFile);
        if ($mtime > $appJsVersion) {
            $appJsVersion = $mtime;
        }
    }
}
// 使用正则替换，兼容模板中已有版本号的情况（如 ?v=20260802.7）
// str_replace 只能匹配 'assets/js/app.js"'，无法匹配 'assets/js/app.js?v=xxx"'
$html = preg_replace('#(assets/js/app\.js)(\?v=[^"]*)?"#', 'assets/js/app.js?v=' . $appJsVersion . '"', $html);

// 同样给 style.css 追加时间戳，避免 CSS 更新后浏览器缓存旧样式
$cssPath = $themeDir . 'assets/css/style.css';
$cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();
$html = preg_replace('#(assets/css/style\.css)(\?v=[^"]*)?"#', 'assets/css/style.css?v=' . $cssVersion . '"', $html);

// 主题外观选项：影响首屏渲染的变量类选项（配色/栏宽）直接写进 <head>，
// 同时把完整选项对象挂到 window.__THEME_OPTIONS__，供主题自己的 JS 首屏即可应用
// （配色/暗黑/显隐/品牌名），消除「先默认再跳变」的闪变，且无需等异步 /site/config。
// theme_opts_{theme} 本就是 settings 表一行，$siteSettings 里已带上，零额外查库。
$themeSchema = \Hublog\Core\Theme::optionsSchema($theme);
if (!empty($themeSchema)) {
    $themeOptsRaw = isset($siteSettings['theme_opts_' . $theme])
        ? json_decode((string)$siteSettings['theme_opts_' . $theme], true)
        : null;
    $themeOpts = \Hublog\Core\Theme::mergeOptions($themeSchema, is_array($themeOptsRaw) ? $themeOptsRaw : []);

    // 通用首屏内联：映射关系由各主题 settings.json 的每个字段自描述（cssVar/cssUnit/cssRule），
    // 这里只按 schema 遍历，新增主题只要在自己的 settings.json 声明即可，无需改动本文件。
    // 所有写入值都按字段类型做白名单校验，规则模板里的 %s 只接受净化后的值。
    $inlineVars = '';
    $inlineRules = '';
    foreach ($themeSchema as $f) {
        $optKey = $f['key'];
        $val = $themeOpts[$optKey] ?? null;
        $cssVar = $f['cssVar'];
        $cssRule = $f['cssRule'];
        $cssUnit = $f['cssUnit'];

        // 1) :root 变量：color 走 hex 白名单；数字走整数白名单（可带 px 单位）
        if ($cssVar !== '' && preg_match('/^--[a-zA-Z0-9-]+$/', $cssVar) && $val !== null) {
            if ($f['type'] === 'color' && is_string($val) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $val)) {
                $inlineVars .= $cssVar . ':' . $val . ';';
            } elseif ($f['type'] !== 'color' && preg_match('/^[0-9]+$/', (string)$val)) {
                $inlineVars .= $cssVar . ':' . (int)$val . ($cssUnit === 'px' ? 'px' : '') . ';';
            }
        }

        // 2) 整段 CSS 规则模板：image 走 URL 白名单后替换 %s（渐变/背景图等复合规则）
        if ($cssRule !== '' && $f['type'] === 'image' && is_string($val)) {
            $url = trim($val);
            if ($url !== '' && preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $url) && mb_strlen($url) <= 500) {
                $inlineRules .= str_replace('%s', $url, $cssRule);
            }
        }
    }
    $styleExtra = ($inlineVars !== '' ? ':root{' . $inlineVars . '}' : '') . $inlineRules;
    // 转义 </script> 防止选项文本（经 Security::purify 已脱敏）意外闭合脚本
    $optsJson = str_replace('<', '\u003c', json_encode($themeOpts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $headInject = ($styleExtra !== '' ? '<style>' . $styleExtra . '</style>' : '')
        . '<script>window.__THEME_OPTIONS__=' . $optsJson . ';</script>';
    $html = str_ireplace('<head>', '<head>' . $headInject, $html);
}


// ===== 前台首屏服务端渲染管线（主题约定式，新增主题无需改动本文件）=====
// 约定（主题目录自包含，index.php 只做通用调度）：
//   1. templates/<theme>/partials/ 存在时，服务端展开 <!-- @include partials/xxx.html -->；
//   2. templates/<theme>/ssr.php 存在且返回可调用渲染器时，接收
//      ['path','query','pageBase','origin','baseUrl','themeOpts']，返回首屏片段数组，
//      键名 foo 自动对应模板占位符 {{SSR_FOO}}（如 feed → {{SSR_FEED}}）；
//   3. 模板含 {{POST_*}} 时，详情页首屏由本文件通用渲染（标题/作者/时间/封面/正文）；
//   4. 任何未被填充的 {{SSR_*}} / {{POST_*}} 在输出前统一清空，绝不裸露成文本。
// 新主题只要按上述约定放文件 + 后台切 active_theme 即可，零 PHP 改动。
// 背景：zixunfeed 的 ssr.php 此前从未被调用，占位符被浏览器当纯文本画出来，
// 直到 JS 接口返回才覆盖 —— 即「先显示一堆花括号、再显示正常页面」的根因。
// 全流程异常安全：任何一步失败都降级为「空首屏」，绝不让页面 500 或再吐花括号。
$ssrFile = $themeDir . 'ssr.php';
$needsSsr = is_file($ssrFile)
    || is_dir($themeDir . 'partials')
    || strpos($html, '{{SSR_') !== false
    || strpos($html, '{{POST_') !== false;
if ($needsSsr) {
    $ssrMap = [];

    try {
        // 1) 服务端展开 @include 公共片段：顶栏 / 底栏 / 挂载点随 HTML 一起到达，
        //    首屏不再等前端 fetch partials；includes.js 找不到注释会直接返回，不重复插入。
        //    读取失败时保留注释，交给前端的 fetch + 内联兜底机制处理。
        $partialRoot = realpath($themeDir . 'partials');
        if ($partialRoot !== false) {
            $html = preg_replace_callback(
                '/<!--\s*@include\s+partials\/([A-Za-z0-9_-]+\.html)\s*-->/i',
                static function ($m) use ($partialRoot) {
                    $file = realpath($partialRoot . DIRECTORY_SEPARATOR . $m[1]);
                    if ($file === false || !str_starts_with($file, $partialRoot . DIRECTORY_SEPARATOR)) {
                        return $m[0];
                    }
                    $content = @file_get_contents($file);
                    return $content === false ? $m[0] : $content;
                },
                $html
            ) ?? $html;
        }

        // 2) 列表页：调用主题自带渲染器，按当前路由产出首屏 HTML（爬虫可读真实链接）
        if (is_file($ssrFile)) {
            $ssrRenderer = require $ssrFile;
            if (is_callable($ssrRenderer)) {
                $ssrPath = $path;
                if ($base !== '' && str_starts_with($ssrPath, $base)) {
                    $ssrPath = substr($ssrPath, strlen($base));
                }
                $ssrPath = '/' . ltrim($ssrPath ?: '/', '/');
                $ssrScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $ssrHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                $ssrOrigin = $ssrScheme . '://' . $ssrHost;
                $ssrData = $ssrRenderer([
                    'path'      => $ssrPath,
                    'query'     => is_array($_GET) ? $_GET : [],
                    'pageBase'  => $pageBase,
                    'origin'    => $ssrOrigin,
                    'baseUrl'   => $ssrOrigin . $base,
                    'themeOpts' => $themeOpts ?? [],
                ]);
                if (is_array($ssrData)) {
                    // 数据驱动：渲染器返回 feed / hero / channels 等键，自动映射为
                    // {{SSR_FEED}} / {{SSR_HERO}} / {{SSR_CHANNELS}}，新增片段无需登记。
                    foreach ($ssrData as $dataKey => $fragment) {
                        if (!is_string($dataKey) || !preg_match('/^[a-z][a-z0-9_]*$/', $dataKey)) {
                            continue;
                        }
                        $ssrMap['SSR_' . strtoupper($dataKey)] = is_string($fragment) ? $fragment : '';
                    }
                }
            }
        }

        // 3) 详情页首屏：标题 / 作者 / 时间 / 封面 / 正文纯文本。
        //    article.js 用 host.querySelector('.content') 判定已有 SSR，模板里的 .content 节点必须保留。
        if ($postDetail) {
            $ssrPostId = (int)($postDetail['id'] ?? 0);
            $ssrAuthor = '匿名';
            $ssrTime = '';
            $ssrCover = '';
            try {
                $ssrRow = \Hublog\Core\Database::fetchOne(
                    "SELECT p.created_at, u.nickname, u.username, p.images
                     FROM posts p LEFT JOIN users u ON u.id = p.user_id
                     WHERE p.id = ? AND {$ssrVisWhere} LIMIT 1",
                    array_merge([$ssrPostId], $ssrVisParams)
                );
                if ($ssrRow) {
                    $ssrAuthor = trim((string)($ssrRow['nickname'] ?? ''))
                        ?: (trim((string)($ssrRow['username'] ?? '')) ?: '匿名');
                    $ssrTs = strtotime((string)($ssrRow['created_at'] ?? ''));
                    $ssrTime = $ssrTs ? date('Y-m-d H:i', $ssrTs) : '';

                    $ssrImgs = [];
                    $rawImages = $ssrRow['images'] ?? '';
                    if (is_string($rawImages) && $rawImages !== '') {
                        $decoded = json_decode($rawImages, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $im) {
                                $ssrImgs[] = is_array($im)
                                    ? (string)($im['url'] ?? $im['src'] ?? '') : (string)$im;
                            }
                        }
                    }
                    $ssrBody = (string)($postDetail['content'] ?? '');
                    if (preg_match_all('/!\[[^\]]*\]\(\s*<?([^)\s>]+)>?/u', $ssrBody, $mm)) {
                        foreach ($mm[1] as $u) {
                            $ssrImgs[] = str_replace('&amp;', '&', (string)$u);
                        }
                    }
                    if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/iu', $ssrBody, $mm)) {
                        foreach ($mm[1] as $u) {
                            $ssrImgs[] = str_replace('&amp;', '&', (string)$u);
                        }
                    }
                    foreach ($ssrImgs as $u) {
                        $u = trim($u);
                        if ($u !== '' && preg_match('#^(/|https?://|//)[^\s"\'<>]+$#i', $u)) {
                            $ssrCover = $u;
                            break;
                        }
                    }
                }
            } catch (\Throwable $ignored) {
                // 附加字段取不到不影响首屏，标题与正文仍可用
            }

            $ssrPlain = strip_tags((string)($postDetail['content'] ?? ''));
            // 正文内媒体占位符降级为可读短文本，避免 [[media:...]] 原文进入纯文本首屏
            $ssrPlain = preg_replace_callback(
                '/\[\[media:(image|video|attachment):\d+\]\]/u',
                static function ($m) {
                    $labels = ['image' => '[图片]', 'video' => '[视频]', 'attachment' => '[附件]'];
                    return $labels[$m[1]] ?? '[媒体]';
                },
                $ssrPlain
            ) ?? $ssrPlain;
            $ssrPlain = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $ssrPlain) ?? $ssrPlain;
            $ssrPlain = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $ssrPlain) ?? $ssrPlain;
            $ssrPlain = preg_replace('/[*_`>#]/u', '', $ssrPlain) ?? $ssrPlain;
            $ssrPlain = trim(preg_replace('/\s+/u', ' ', $ssrPlain) ?? $ssrPlain);
            $ssrTitle = trim((string)($postDetail['title'] ?? ''))
                ?: (mb_substr($ssrPlain, 0, 40, 'UTF-8') ?: '动态');

            $escSsr = static function ($v) {
                return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            };
            $ssrMap['POST_TITLE'] = $escSsr($ssrTitle);
            $ssrMap['POST_AUTHOR'] = $escSsr($ssrAuthor);
            $ssrMap['POST_TIME'] = $escSsr($ssrTime);
            $ssrMap['POST_CONTENT_TEXT'] = $escSsr(mb_substr($ssrPlain, 0, 500, 'UTF-8'));
            $ssrMap['POST_COVER'] = $ssrCover !== ''
                ? '<figure class="cover"><img src="' . $escSsr($ssrCover) . '" alt="" loading="eager"></figure>'
                : '';
        }
    } catch (\Throwable $ssrError) {
        error_log('[Hublog SSR] 首屏渲染失败，已降级为空首屏: ' . $ssrError->getMessage());
        foreach ($ssrMap as $ssrKey => $ssrVal) {
            $ssrMap[$ssrKey] = '';
        }
    }

    // 顶栏频道使用「注释锚点」写法：先整体替换，避免注入的链接被注释吞掉；
    // 同时兼容尚未同步该改动的旧 partial（裸占位符由下面的循环兜住）。
    $html = str_replace('<!--{{SSR_CHANNELS}}-->', $ssrMap['SSR_CHANNELS'] ?? '', $html);
    foreach ($ssrMap as $ssrKey => $ssrVal) {
        $html = str_replace('{{' . $ssrKey . '}}', $ssrVal, $html);
    }
}

// V97：内容付费墙首屏购买卡（仅详情页 /p/{id} 有值；其值为服务端生成的只读片段）。
// 必须在残留兜底之前替换——兜底正则只清 SSR_/POST_ 前缀，不会碰 PAYWALL_HTML。
$html = str_replace('{{PAYWALL_HTML}}', $paywallHtml ?? '', $html);

// 页面前缀与残留占位符兜底：任何未被替换的 {{SSR_*}} / {{POST_*}} 都不允许
// 以纯文本形式进入首屏（覆盖未启用 SSR 的页面、渲染降级、未来新增占位符等场景）。
$html = str_replace('{{PAGE_BASE}}', htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8'), $html);
$html = preg_replace('/\{\{(?:SSR|POST)_[A-Z0-9_]+\}\}/', '', $html) ?? $html;


// 并行合并保留：上方内联 SSR 管线已清空占位符，这里把它产出的原始数据
// 回灌框架层 ThemeSsr，仅用于追加 ItemList JSON-LD 结构化数据
// （让搜索引擎明确页面内有哪些条目及其顺序），不改变首屏 HTML 内容。
if (isset($ssrData) && is_array($ssrData) && !empty($ssrMap)) {
    $ldMap = [];
    foreach (\Hublog\Core\ThemeSsr::SLOTS as $placeholder => $field) {
        $ldMap[$field] = $ssrMap[$placeholder] ?? "";
    }
    $ldMap["items"] = is_array($ssrData["items"] ?? null) ? array_values($ssrData["items"]) : [];
    $html = \Hublog\Core\ThemeSsr::inject($html, $ldMap);
}
header('Content-Type: text/html; charset=utf-8');
// 防止浏览器缓存 HTML 页面，确保模板更新后用户立即获取最新版本
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $html;
exit;
