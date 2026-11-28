<?php
/**
 * 前台首屏服务端渲染管线（架构评审 P1-5）
 *
 * 从 index.php 原 756-946 行逐行搬移。两遍 partials 展开语义不同，禁止合并：
 *   - SeoInjector 用 is_file()，缺文件返回 ''；
 *   - 本类用 realpath()，缺文件保留原注释 return $m[0]。
 * 两套 origin 也禁止合并：SEO 用 PathResolver::origin($siteSettings)，
 * 本类用 $_SERVER['HTTPS'] + HTTP_HOST。
 * injectStructuredData() 必须在 render() 之后单独调用：ThemeSsr::inject 写入的
 * ItemList script 含 </head>，前移会被 head 占位符吞掉。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class SsrRenderer
{
    /**
     * @param string $base     站点部署前缀（根目录为空串）
     * @param string $path     当前请求路径
     * @param string $themeDir 当前主题模板目录（含末尾斜杠）
     */
    public function __construct(
        private string $base,
        private string $path,
        private string $themeDir
    ) {
    }

    /**
     * 展开 SSR 片段、调用主题渲染器并清空残留占位符。
     *
     * @param array<string, mixed> $route   FrontRouter::resolve() 的结果
     * @param array<string, mixed> $derived SeoInjector::inject() 的 derived（pageBase/themeOpts）
     * @return array{html: string, ssrMap: array<string, string>, ssrData: mixed}
     */
    public function render(string $html, array $route, array $derived): array
    {
        $postDetail = $route['postDetail'] ?? null;
        $ssrVisWhere = (string)($route['ssrVisWhere'] ?? '');
        $ssrVisParams = $route['ssrVisParams'] ?? [];
        $paywallHtml = $route['paywallHtml'] ?? null;
        $pageBase = (string)($derived['pageBase'] ?? '');
        $themeOpts = $derived['themeOpts'] ?? null;
        $base = $this->base;
        $path = $this->path;
        $themeDir = $this->themeDir;
        $ssrMap = [];
        $ssrData = null;

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
                error_log('[Hublog SSR] 首屏渲染失败，已降级为空首屏: ' . $ssrError->getMessage()); // @allow-error-log
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

        return ['html' => $html, 'ssrMap' => $ssrMap, 'ssrData' => $ssrData];
    }

    /**
     * 把 SSR 原始数据回灌 ThemeSsr，追加 ItemList JSON-LD。
     *
     * 必须在 render() 全部 HTML 替换完成后调用：ThemeSsr::inject 注入的 script
     * 含 </head> 字面量，若前移会被 HeadInjector 占位符吞掉。
     *
     * @param array<string, mixed> $ssrResult render() 的返回值
     */
    public function injectStructuredData(string $html, array $ssrResult): string
    {
        $ssrData = $ssrResult['ssrData'] ?? null;
        $ssrMap = $ssrResult['ssrMap'] ?? [];

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
        return $html;
    }
}
