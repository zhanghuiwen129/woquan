<?php
/**
 * 前台 SEO 文本工具与 head/元信息注入（架构评审 P1-5）
 *
 * plainText() / paragraphs() 由 index.php 原全局函数逐行搬移而来：
 * 输出被 SEO title/description、OG、JSON-LD 与 SSR 首屏共同消费，
 * 任何字节差异都会改变渲染结果，因此保留全部 null 守卫与默认参数。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class SeoInjector
{
    /**
     * @param array<string, mixed> $env 只读依赖袋（站点变量在入口已全部就绪）
     * @param HeadInjector $head 由入口构造并独占传入，保证 add()/apply() 顺序不被打乱
     */
    public function __construct(private array $env, private HeadInjector $head)
    {
    }

    /**
     * 把 Markdown 正文转成纯文本，供 SEO 的 title / description 使用
     *
     * 直接用原文会出现 ![](url)、## 、** 等标记，搜索结果摘要里很难看，
     * emoji 还会挤占标题长度。正文渲染仍走主题自己的 Markdown 管线，这里只服务 SEO。
     */
    public static function plainText(string $markdown, int $limit = 0): string
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
    public static function paragraphs(string $markdown, int $maxBlocks = 0): string
    {
        $blocks = preg_split('/\R{2,}/', (string)$markdown);
        $out = [];
        foreach ($blocks as $block) {
            $p = self::plainText($block);
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

    /**
     * 以只读依赖袋 + 路由结果完成全部 head/元信息注入。
     *
     * $env 键：base, path, theme, themeDir, siteSettings, siteName, siteDesc,
     * siteKeywords, siteDescription, siteIcp, statsCode, executionTime。
     * $route 键：postDetail, categoryDetail, isUnknownPath, searchQuery。
     *
     * @param array<string, mixed> $env
     * @param array<string, mixed> $route
     * @return array{html: string, derived: array{pageBase: string, themeOpts: ?array}}
     */
    public function inject(string $html, array $route): array
    {
        $base = (string)$this->env['base'];
        $path = (string)$this->env['path'];
        $theme = (string)$this->env['theme'];
        $themeDir = (string)$this->env['themeDir'];
        $siteSettings = $this->env['siteSettings'];
        $siteName = $this->env['siteName'];
        $siteDesc = $this->env['siteDesc'];
        $siteKeywords = $this->env['siteKeywords'];
        $siteDescription = $this->env['siteDescription'];
        $siteIcp = $this->env['siteIcp'];
        $statsCode = $this->env['statsCode'];
        $executionTime = $this->env['executionTime'];
        $postDetail = $route['postDetail'] ?? null;
        $categoryDetail = $route['categoryDetail'] ?? null;
        $isUnknownPath = $route['isUnknownPath'] ?? null;
        $searchQuery = $route['searchQuery'] ?? null;
        $themeOpts = null;
        $headInjector = $this->head;

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

        // P1-6：把原先散落的 7 处 </head> 字符串注入收敛为一次占位符替换。
        // 骨架里没有 </head> 时（异常模板）不写占位符，apply() 会原样返回文档。
        // HeadInjector 实例由入口构造并独占传入，入口无法再触碰 add()/apply() 顺序。
        if (strpos($html, '</head>') !== false) {
            $html = str_replace('</head>', \Hublog\Core\HeadInjector::PLACEHOLDER, $html);
        }
        $headInjector->add(
            "<script>window.HUBLOG_BASE_URL='" . htmlspecialchars($baseJs, ENT_QUOTES, 'UTF-8') . "';"
            . "window.HUBLOG_URL_REWRITE=" . ($urlRewrite ? 'true' : 'false') . ";"
            . "window.HUBLOG_PAGE_BASE='" . htmlspecialchars($pageBase, ENT_QUOTES, 'UTF-8') . "';</script>"
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
            $plainContent = self::plainText((string)$postDetail['content']);
            if (!empty($postDetail['title'])) {
                $pageTitle = $postDetail['title'] . ' - ' . $siteName;
            } else {
                $pageTitle = self::plainText((string)$postDetail['content'], 30) . ' - ' . $siteName;
            }
            $pageDescription = self::plainText((string)$postDetail['content'], 300);
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
        $headInjector->add($ogTags);

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
            $articleBody = self::plainText((string)$postDetail['content'], 3000);
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
        $headInjector->add('<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>');

        // V36 新增：canonical 标签
        $headInjector->add('<link rel="canonical" href="' . $currentUrl . '">');

        // 未知路径（软 404）输出 noindex：状态码与回退首页行为不变，
        // 但不让无限 URL 空间进入索引。canonical 仍指向自身，避免与首页争权重。
        // 搜索结果页（带关键词）同样 noindex：任意关键词组合都能生成一个页面，
        // 收录只会制造重复内容并稀释权重。
        $isSearchResult = preg_match('#^' . preg_quote($base, '#') . '/search/?$#', $path)
            && trim((string)($_GET['q'] ?? '')) !== '';
        if (!empty($isUnknownPath) || $isSearchResult) {
            $headInjector->add('<meta name="robots" content="noindex,follow">');
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
            $headInjector->add('<script>window.__HUBLOG_POST__=' . $postJson . ';</script>');
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
                $postTitleText = self::plainText((string)$postDetail['content'], 40);
            }
            $postAuthorText = trim((string)($postDetail['nickname'] ?? '')) ?: trim((string)($postDetail['username'] ?? ''));
            $postTimeText = !empty($postDetail['created_at'])
                ? date('Y-m-d H:i', strtotime((string)$postDetail['created_at']))
                : '';
            $postContentHtml = self::paragraphs((string)$postDetail['content'], 20);
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
            $headInjector->add('<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>');
        }

        // 所有片段累积完成后一次性落盘：implode("\n", ...) + "\n</head>" 与原 7 次注入的字节序完全一致。
        $html = $headInjector->apply($html);

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

        return [
            'html' => $html,
            'derived' => [
                'pageBase' => $pageBase,
                'themeOpts' => $themeOpts,
            ],
        ];
    }
}
