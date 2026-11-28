<?php
/**
 * 我圈 (woquan) - 轻量多用户朋友圈社区
 * Copyright 2026 woquan
 * Licensed under the Apache License, Version 2.0
 */
declare(strict_types=1);
$hublogStartTime = microtime(true);

// 引导与公共默认常量（与 api/index.php 共用同一份实现）。
require_once __DIR__ . '/bootstrap.php';
\Hublog\Bootstrap\registerFatalHandler(false);
require_once __DIR__ . '/config.php';
\Hublog\Bootstrap\defineDefaults();
\Hublog\Bootstrap\assertJwtSecret(false);

// SEO 纯函数已迁入 SeoInjector；保留全局包装兼容第三方主题。
if (!function_exists('seo_plain_text')) { function seo_plain_text(string $markdown, int $limit = 0): string { return \Hublog\Core\SeoInjector::plainText($markdown, $limit); } }
if (!function_exists('seo_paragraphs')) { function seo_paragraphs(string $markdown, int $maxBlocks = 0): string { return \Hublog\Core\SeoInjector::paragraphs($markdown, $maxBlocks); } }
// 部署前缀：SCRIPT_NAME 优先，剥离可能混入的 PATH_INFO。
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/';
if ($scriptName === '' || $scriptName === '/api/index.php') { $scriptName = '/index.php'; }
if (!empty($_SERVER['PATH_INFO'])) {
    $pathInfo = $_SERVER['PATH_INFO'];
    if ($pathInfo !== '' && str_ends_with($scriptName, $pathInfo)) { $scriptName = substr($scriptName, 0, strlen($scriptName) - strlen($pathInfo)); }
}
if (!preg_match('/\.php$/', $scriptName)) {
    $scriptFile = basename($_SERVER['SCRIPT_FILENAME'] ?? 'index.php');
    $scriptName = ($scriptName === '/' ? '' : dirname($scriptName)) . '/' . $scriptFile;
    $scriptName = '/' . ltrim($scriptName, '/');
}
$dir = dirname($scriptName);
$base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
if (!\Hublog\Bootstrap\isInstalled(__DIR__)) {
    header('Location: ' . $base . '/install.php');
    exit;
}
require_once __DIR__ . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
\Hublog\Core\Migration::run();

// 请求路径：PATH_INFO 分支补 $base；重写分支按 SCRIPT_NAME 精确剥离。
if (!empty($_SERVER['PATH_INFO'])) {
    $path = $base . $_SERVER['PATH_INFO'];
} else {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($scriptName !== '' && ($path === $scriptName || strpos($path, $scriptName . '/') === 0)) {
        $suffix = substr($path, strlen($scriptName));
        $path = $base . ($suffix === '' ? '/' : $suffix);
    }
}
if (preg_match('#^' . preg_quote($base, '#') . '/admin/?$#', $path)) {
    header('Location: ' . $base . '/admin/');
    exit;
}
$siteSettings = [];
try {
    $siteSettings = \Hublog\Core\Cache::remember('site_settings', function () {
        $rows = \Hublog\Core\Database::fetchAll("SELECT `key`, `value` FROM settings");
        $result = [];
        foreach ($rows as $row) { $result[$row['key']] = $row['value']; }
        return $result;
    }, 3600);
} catch (\Throwable $e) {
    \Hublog\Core\Cache::delete('site_settings');
    try {
        $rows = \Hublog\Core\Database::fetchAll("SELECT `key`, `value` FROM settings");
        foreach ($rows as $row) { $siteSettings[$row['key']] = $row['value']; }
    } catch (\Throwable $e2) {
    }
}
// /robots.txt 与 /sitemap.xml 由前台直接输出。
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

$theme = \Hublog\Core\Theme::resolve(isset($siteSettings['active_theme']) ? (string)$siteSettings['active_theme'] : null);
$themeDir = __DIR__ . '/templates/' . $theme . '/';
$siteName = $siteSettings['site_name'] ?? '我圈';
$siteDesc = $siteSettings['site_desc'] ?? '轻量级多用户社区博客系统';
$siteKeywords = $siteSettings['site_keywords'] ?? '我圈,博客,社区,朋友圈';
$siteDescription = $siteSettings['site_description'] ?? $siteDesc;
$siteIcp = $siteSettings['site_icp'] ?? '';
$statsCode = $siteSettings['site_stats_code'] ?? '';
$executionTime = round((microtime(true) - $hublogStartTime) * 1000, 2);

// 路由解析：404 早退由入口执行，类内不写 exit/header。
$route = (new \Hublog\Core\FrontRouter($base, $path, $themeDir))->resolve();
if ($route['earlyExit'] !== null) {
    http_response_code($route['earlyExit']['status']);
    exit($route['earlyExit']['body']);
}
$templatePath = $route['templatePath'];
$postDetail = $route['postDetail'];
$categoryDetail = $route['categoryDetail'];
$paywallHtml = $route['paywallHtml'];
$searchQuery = $route['searchQuery'];
$isUnknownPath = $route['isUnknownPath'];
$ssrVisWhere = $route['ssrVisWhere'];
$ssrVisParams = $route['ssrVisParams'];

// 模板读取失败不回显绝对路径（DEBUG 除外）。
if (!file_exists($templatePath)) {
    error_log('[Hublog TEMPLATE] 模板文件缺失: ' . $templatePath);
    http_response_code(500);
    exit(defined('APP_DEBUG') && APP_DEBUG ? ('模板文件缺失: ' . $templatePath) : '页面暂时不可用，请稍后重试');
}
$html = file_get_contents($templatePath);
if ($html === false) {
    error_log('[Hublog TEMPLATE] 模板文件读取失败: ' . $templatePath);
    http_response_code(500);
    exit(defined('APP_DEBUG') && APP_DEBUG ? ('模板文件读取失败（权限不足）: ' . $templatePath) : '页面暂时不可用，请稍后重试');
}

// HeadInjector 独占交给 SeoInjector；入口不再触碰 add()/apply() 顺序。
$headInjector = new \Hublog\Core\HeadInjector();
$seoInjector = new \Hublog\Core\SeoInjector([
    'base' => $base, 'path' => $path, 'theme' => $theme, 'themeDir' => $themeDir,
    'siteSettings' => $siteSettings, 'siteName' => $siteName, 'siteDesc' => $siteDesc,
    'siteKeywords' => $siteKeywords, 'siteDescription' => $siteDescription,
    'siteIcp' => $siteIcp, 'statsCode' => $statsCode, 'executionTime' => $executionTime,
], $headInjector);
$seoResult = $seoInjector->inject($html, $route);
$html = $seoResult['html'];
$pageBase = $seoResult['derived']['pageBase'];
$themeOpts = $seoResult['derived']['themeOpts'];

// ThemeSsr::inject 含 </head>，结构化数据注入必须最后一步。
$ssrRenderer = new \Hublog\Core\SsrRenderer($base, $path, $themeDir);
$ssrResult = $ssrRenderer->render($html, $route, ['pageBase' => $pageBase, 'themeOpts' => $themeOpts]);
$html = $ssrResult['html'];
$ssrMap = $ssrResult['ssrMap'];
$ssrData = $ssrResult['ssrData'];
$html = $ssrRenderer->injectStructuredData($html, $ssrResult);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $html;
exit;
