<?php

declare(strict_types=1);

/**
 * 列表页服务端渲染（SSR）装载器
 *
 * 背景：前台是 SPA，列表内容由 JS 渲染 —— 爬虫抓到的首屏 HTML 里没有文章链接，
 *      锚文本与权重无法传递。sitemap 只解决「URL 发现」，解决不了「页面里到底有没有链接」。
 *
 * 方案：主题可选地在自己的目录下放一个 ssr.php，返回一个渲染器（callable）。
 *      框架在首屏 HTML 里注入服务端渲染好的列表，JS 接管后原地替换（两边结构一致，用户无感）。
 *      框架层与主题解耦：没有 ssr.php 的主题行为完全不变（零回归）。
 *
 * 约定：
 *   1. 渲染器返回数组，键名固定：
 *      route  路由标识（home/category/topic/user/search/ranking/video），用于日志与结构化数据
 *      key    路由参数（分类 id / 话题名 / 用户名 / 关键词），用于缓存区分
 *      feed   主列表容器 HTML
 *      hero   首页轮播 HTML（可选）
 *      side   侧栏 HTML（可选）
 *      chips  侧栏话题 chips HTML（可选）
 *      items  结构化数据条目 [{id,title,url}]，框架据此生成 ItemList JSON-LD
 *   2. 主题骨架里用 {{SSR_FEED}} / {{SSR_HERO}} / {{SSR_SIDE}} / {{SSR_CHIPS}} 占位；
 *      没有对应内容时占位被替换成空串，不会残留在页面上。
 */

namespace Hublog\Core;

class ThemeSsr
{
    /** 主题目录下的 SSR 渲染脚本文件名 */
    public const SCRIPT = 'ssr.php';

    /** 框架占位符 → 渲染结果字段 */
    public const SLOTS = [
        'SSR_FEED'     => 'feed',
        'SSR_HERO'     => 'hero',
        'SSR_SIDE'     => 'side',
        'SSR_CHIPS'    => 'chips',
        'SSR_CHANNELS' => 'channels',
        'SSR_FLASH'    => 'flash',
        'SSR_CATHEAD'  => 'cathead',
        'SSR_HOTWORDS' => 'hotwords',
        'SSR_META'     => 'meta',
    ];

    /** @var array<string, callable|null> 已载入的主题渲染器 */
    private static array $renderers = [];

    /**
     * 渲染当前请求的列表页首屏 HTML
     *
     * @param string $theme 主题名
     * @param string $path  去掉部署前缀后的请求路径（/、/c/3、/topic/xxx …）
     * @param array  $ctx   渲染上下文（pageBase / query / origin / themeOpts …）
     * @return array|null|null 表示该主题不支持 SSR 或渲染失败
     */
    public static function render(string $theme, string $path, array $ctx = []): ?array
    {
        $renderer = self::renderer($theme);
        if ($renderer === null) {
            return null;
        }

        try {
            $out = $renderer($ctx);
        } catch (\Throwable $e) {
            // SSR 失败绝不能影响页面本身：吞掉异常，页面退回纯 JS 渲染
            error_log('[Hublog ThemeSsr] 渲染异常: ' . $e->getMessage()); // @allow-error-log
            return null;
        }

        if (!is_array($out)) {
            return null;
        }

        return [
            'route'    => (string)($out['route'] ?? ''),
            'key'      => (string)($out['key'] ?? ''),
            'feed'     => (string)($out['feed'] ?? ''),
            'hero'     => (string)($out['hero'] ?? ''),
            'side'     => (string)($out['side'] ?? ''),
            'chips'    => (string)($out['chips'] ?? ''),
            'channels' => (string)($out['channels'] ?? ''),
            'flash'    => (string)($out['flash'] ?? ''),
            'cathead'  => (string)($out['cathead'] ?? ''),
            'hotwords' => (string)($out['hotwords'] ?? ''),
            'meta'     => (string)($out['meta'] ?? ''),
            'items'    => is_array($out['items'] ?? null) ? array_values($out['items']) : [],
        ];
    }

    /**
     * 把渲染结果写进 HTML：替换占位符 + 追加 ItemList 结构化数据
     *
     * ItemList 让搜索引擎明确知道「这个页面上有哪些条目、按什么顺序」，
     * 与页面里真实的 <a href> 互相印证，权重传递更稳。
     */
    public static function inject(string $html, array $ssr): string
    {
        foreach (self::SLOTS as $placeholder => $field) {
            $html = str_replace('{{' . $placeholder . '}}', (string)($ssr[$field] ?? ''), $html);
        }

        $elements = [];
        $position = 0;
        foreach (($ssr['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['url'])) {
                continue;
            }
            $position++;
            $element = [
                '@type'    => 'ListItem',
                'position' => $position,
                'url'      => (string)$item['url'],
            ];
            $name = (string)($item['title'] ?? '');
            if ($name !== '') {
                $element['name'] = $name;
            }
            $elements[] = $element;
        }

        if ($elements) {
            $ld = [
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'numberOfItems'   => count($elements),
                'itemListElement' => $elements,
            ];
            $json = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                // < 转成 \u003c，防止内容里的尖括号提前闭合 script
                $json = str_replace('<', '\u003c', $json);
                $html = str_replace(
                    '</head>',
                    '<script type="application/ld+json">' . $json . "</script>\n</head>",
                    $html
                );
            }
        }

        return $html;
    }

    /**
     * 载入主题渲染器：templates/{theme}/ssr.php 必须返回一个 callable
     *
     * @return callable|null
     */
    private static function renderer(string $theme): ?callable
    {
        if (array_key_exists($theme, self::$renderers)) {
            return self::$renderers[$theme];
        }

        self::$renderers[$theme] = null;

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $theme)) {
            return null;
        }

        $file = Theme::rootPath() . $theme . DIRECTORY_SEPARATOR . self::SCRIPT;
        if (!is_file($file)) {
            return null;
        }

        try {
            // require_once：同请求内多次调用只载入一次（脚本里定义的类不会被重复声明）
            $renderer = require_once $file;
        } catch (\Throwable $e) {
            error_log('[Hublog ThemeSsr] 渲染脚本载入失败: ' . $e->getMessage()); // @allow-error-log
            return null;
        }

        if (!is_callable($renderer)) {
            error_log('[Hublog ThemeSsr] ' . self::SCRIPT . ' 未返回可调用对象'); // @allow-error-log
            return null;
        }

        self::$renderers[$theme] = $renderer;
        return $renderer;
    }
}
