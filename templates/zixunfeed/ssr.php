<?php

declare(strict_types=1);

/**
 * zixunfeed 列表页服务端渲染器（SSR）
 *
 * 作用：把首页 / 分类 / 话题 / 用户 / 搜索 / 热榜 / 视频 七个列表页的条目，
 *      在服务端渲染成带真实 <a href> 的 HTML 写进首屏 —— 爬虫不执行 JS 也能读到
 *      文章链接与锚文本，权重得以传递；JS 接管后用同一批数据原地替换，用户无感。
 *
 * 约定：
 *   1. 本文件返回的渲染器接收 ['path' => ..., 'query' => ..., 'pageBase' => ...,
 *      'origin' => ..., 'themeOpts' => ...]，返回框架约定的数组。
 *   2. 渲染出的 HTML 结构必须与前端模块（feed/topic/search/user/ranking/video.js）
 *      保持一致，否则 JS 接管瞬间会出现视觉跳变。
 *   3. 服务端只输出公开动态（visibility = 'public'），与游客视角一致。
 */

namespace Hublog\Themes\Zixunfeed;

use Hublog\Core\Cache;
use Hublog\Core\Database;
use Hublog\Core\PostFormatter;
use Hublog\Core\PostRepository;

if (!class_exists(SsrRenderer::class, false)) {

    class SsrRenderer
    {
        /**
         * 渲染器版本：改动渲染逻辑后手动 +1，缓存键随之变化，旧结果自然过期
         * （否则改了版式但缓存里还是旧 HTML，首屏会闪一下旧样式）
         */
        private const VERSION = '20261115.5';

        /** 页面前缀（伪静态关闭时为 /index.php，首页为空串） */
        private string $pageBase = '';

        /** 站点来源，用于绝对 URL（结构化数据 / canonical） */
        private string $origin = '';

        /** 站点根地址（origin + 部署路径），用于首页链接 */
        private string $baseUrl = '';

        /** 主题外观选项（show_hero / show_sidebar 等） */
        private array $opts = [];

        /** 分类 id => 名称 */
        private array $catNames = [];

        /** 父分类 id => 子分类 id 列表 */
        private array $catChildren = [];

        /** 内部图标（与前端 feed.js 保持一致） */
        private const ICON_LIKE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>';

        private const ICON_COMMENT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>';

        private const ICON_STAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

        private const ICON_PLAY = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';

        private const ICON_EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';

        private const ICON_CLOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

        public function __invoke(array $ctx = []): array
        {
            $this->pageBase = rtrim((string)($ctx['pageBase'] ?? ''), '/');
            $this->origin   = rtrim((string)($ctx['origin'] ?? ''), '/');
            $this->baseUrl  = rtrim((string)($ctx['baseUrl'] ?? ''), '/');
            $this->opts     = is_array($ctx['themeOpts'] ?? null) ? $ctx['themeOpts'] : [];

            $path  = (string)($ctx['path'] ?? '/');
            $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];

            $route = $this->matchRoute($path, $query);
            if ($route === null) {
                return $this->blankResult();
            }

            // 渲染结果按路由缓存 60 秒：爬虫高频访问不致把 DB 打穿，
            // 新内容最迟 60 秒后进入 SSR 首屏（JS 接管后始终是最新数据）。
            $cacheKey = 'ssr_zixunfeed_v' . self::VERSION . '_'
                . md5($route['name'] . '|' . $route['key'])
                . '_t' . Cache::nsVersion('timeline')
                . '_p' . Cache::nsVersion('popular');

            $result = Cache::remember($cacheKey, function () use ($route) {
                return $this->renderRoute($route);
            }, 60);

            return is_array($result) ? $result : $this->blankResult();
        }

        /* ==================== 路由 ==================== */

        /**
         * 把请求路径映射到渲染分支
         *
         * @return array{name:string,key:string,...}|null
         */
        private function matchRoute(string $path, array $query): ?array
        {
            $path = '/' . trim($path, '/');
            if ($path === '/' ) {
                $path = '/';
            }

            if ($path === '/') {
                return ['name' => 'home', 'key' => ''];
            }
            if (preg_match('#^/c/(\d+)$#', $path, $m)) {
                return ['name' => 'category', 'key' => 'c' . (int)$m[1], 'category_id' => (int)$m[1]];
            }
            if (preg_match('#^/topic/(.+)$#', $path, $m)) {
                $name = urldecode($m[1]);
                return $name === '' ? null : ['name' => 'topic', 'key' => 't' . $name, 'topic' => $name];
            }
            if (preg_match('#^/u/([a-zA-Z0-9_-]+)$#', $path, $m)) {
                return ['name' => 'user', 'key' => 'u' . $m[1], 'username' => $m[1]];
            }
            if ($path === '/ranking') {
                return ['name' => 'ranking', 'key' => ''];
            }
            if ($path === '/video') {
                return ['name' => 'video', 'key' => ''];
            }
            if ($path === '/search') {
                $q = trim((string)($query['q'] ?? ''));
                if ($q === '') {
                    return null; // 空搜索页是落地页，没有结果可渲染
                }
                return ['name' => 'search', 'key' => 'q' . $q, 'q' => $q];
            }
            return null;
        }

        /* ==================== 各路由渲染 ==================== */

        private function renderRoute(array $route): array
        {
            switch ($route['name']) {
                case 'home':
                    return $this->renderHome();
                case 'category':
                    return $this->renderCategory((int)$route['category_id']);
                case 'topic':
                    return $this->renderTopic((string)$route['topic']);
                case 'user':
                    return $this->renderUser((string)$route['username']);
                case 'ranking':
                    return $this->renderRanking();
                case 'video':
                    return $this->renderVideo();
                case 'search':
                    return $this->renderSearch((string)$route['q']);
            }
            return $this->blankResult();
        }

        /** 首页：轮播 + 信息流 + 侧栏热榜 + 侧栏话题 */
        private function renderHome(): array
        {
            $list = $this->loadPosts($this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT ?",
                [10]
            ));

            // 轮播：取前 3 条有封面的动态（与前端 buildHero 规则一致）
            $slides = [];
            $heroIds = [];
            foreach ($list as $post) {
                $cover = $this->coverOf($post);
                if ($cover === '') {
                    continue;
                }
                $slides[] = ['post' => $post, 'cover' => $cover];
                $heroIds[(int)$post['id']] = true;
                if (count($slides) >= 3) {
                    break;
                }
            }

            // 信息流：剔除轮播用掉的动态（与前端一致）
            $feedList = [];
            foreach ($list as $post) {
                if (!isset($heroIds[(int)$post['id']])) {
                    $feedList[] = $post;
                }
            }

            $hero = '';
            if ($slides && $this->optEnabled('show_hero', true)) {
                $hero = $this->renderHero($slides);
            }

            $cards = '';
            foreach ($feedList as $i => $post) {
                $cards .= $this->renderFeedCard($post, $i);
            }

            return [
                'route'    => 'home',
                'key'      => '',
                'feed'     => $cards,
                'hero'     => $hero,
                'side'     => $this->renderRankList(),
                'chips'    => $this->renderTopicChips(),
                'channels' => $this->renderChannels(),
                'flash'    => $this->optEnabled('show_flash', true) ? $this->renderFlash($list) : '',
                'cathead'  => '',
                'items'    => $this->itemsOf(array_merge($slides ? array_column($slides, 'post') : [], $feedList)),
            ];
        }

        /** 分类聚合页 /c/{id} */
        private function renderCategory(int $categoryId): array
        {
            if ($categoryId <= 0) {
                return $this->blankResult('category');
            }

            $catIds = [$categoryId];
            $parent = Database::fetchOne(
                "SELECT id, parent_id FROM categories WHERE id = ? AND status = 1 LIMIT 1",
                [$categoryId]
            );
            if (!$parent) {
                return $this->blankResult('category');
            }
            if ($parent['parent_id'] === null) {
                $children = Database::fetchAll(
                    "SELECT id FROM categories WHERE parent_id = ? AND status = 1",
                    [$categoryId]
                );
                foreach ($children as $child) {
                    $catIds[] = (int)$child['id'];
                }
            }

            $ph = implode(',', array_fill(0, count($catIds), '?'));
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public' AND p.category_id IN ({$ph})
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT ?",
                array_merge($catIds, [10])
            );
            $list = $this->loadPosts($ids);

            $cards = '';
            foreach ($list as $post) {
                // 分类页前端不传 index（renderCard 默认 0），全部走「大图」版式，保持一致
                $cards .= $this->renderFeedCard($post, 0);
            }

            return [
                'route'    => 'category',
                'key'      => 'c' . $categoryId,
                'feed'     => $cards,
                'side'     => $this->renderRankList(),
                'channels' => $this->renderChannels(),
                'cathead'  => $this->renderCategoryHead((string)$parent['name']),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 话题聚合页 /topic/{name} */
        private function renderTopic(string $topic): array
        {
            $tagTotal = (int)Database::fetchColumn(
                "SELECT COUNT(*)
                 FROM post_tags pt
                 INNER JOIN posts p ON p.id = pt.post_id
                 WHERE pt.tag = ? AND p.status = 1 AND p.visibility = 'public'",
                [$topic]
            );

            if ($tagTotal > 0) {
                $ids = $this->queryIds(
                    "SELECT pt.post_id AS id
                     FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND p.visibility = 'public'
                     ORDER BY pt.post_id DESC
                     LIMIT ?",
                    [$topic, 10]
                );
            } else {
                // 旧数据 hashtag 未迁移时回退正文模糊匹配（与接口一致）
                $pattern = '%#' . str_replace(['%', '_'], ['\%', '\_'], $topic) . '#%';
                $ids = $this->queryIds(
                    "SELECT p.id FROM posts p
                     WHERE p.status = 1 AND p.visibility = 'public' AND p.content LIKE ?
                     ORDER BY p.id DESC
                     LIMIT ?",
                    [$pattern, 10]
                );
            }

            $list = $this->loadPosts($ids);

            $cards = '';
            foreach ($list as $post) {
                $cards .= $this->renderTopicCard($post);
            }

            return [
                'route'    => 'topic',
                'key'      => 't' . $topic,
                'feed'     => $cards,
                'side'     => $this->renderTopicRows($topic),
                'channels' => $this->renderChannels(),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 个人主页 /u/{username}（作品 tab） */
        private function renderUser(string $username): array
        {
            $user = Database::fetchOne(
                "SELECT id FROM users WHERE username = ? AND (status = 1 OR deleted_at IS NOT NULL) LIMIT 1",
                [$username]
            );
            if (!$user) {
                return $this->blankResult('user');
            }

            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public' AND p.user_id = ?
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT ?",
                [(int)$user['id'], 12]
            );
            $list = $this->loadPosts($ids);

            $cards = '';
            foreach ($list as $post) {
                $cards .= $this->renderWorkCard($post);
            }

            return [
                'route'    => 'user',
                'key'      => 'u' . $username,
                'feed'     => $cards,
                'channels' => $this->renderChannels(),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 热榜页 /ranking */
        private function renderRanking(): array
        {
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                 ORDER BY (SELECT COUNT(*) FROM likes WHERE post_id = p.id) DESC, p.created_at DESC
                 LIMIT ?",
                [20]
            );
            $list = $this->loadPosts($ids);

            // 前端按「赞 + 评论*2 + 阅读」重排，服务端保持同一顺序
            usort($list, function ($a, $b) {
                $ha = $this->hotScore($a);
                $hb = $this->hotScore($b);
                return $hb <=> $ha;
            });

            $cards = '';
            foreach ($list as $i => $post) {
                $cards .= $this->renderRankItem($post, $i);
            }

            return [
                'route'    => 'ranking',
                'key'      => '',
                'feed'     => $cards,
                'side'     => $this->renderRiseList(),
                'chips'    => $this->renderTopicChips(),
                'channels' => $this->renderChannels(),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 视频专区 /video */
        private function renderVideo(): array
        {
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                   AND p.videos IS NOT NULL AND JSON_LENGTH(p.videos) > 0
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT ?",
                [12]
            );
            $list = $this->loadPosts($ids);

            $cards = '';
            foreach ($list as $post) {
                $cards .= $this->renderVideoCard($post);
            }

            return [
                'route'    => 'video',
                'key'      => '',
                'feed'     => $cards,
                'channels' => $this->renderChannels(),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 搜索结果页 /search?q= */
        private function renderSearch(string $q): array
        {
            $ids = $this->searchIds($q, 10);
            $list = $this->loadPosts($ids);

            $cards = '';
            foreach ($list as $post) {
                $cards .= $this->renderSearchCard($post, $q);
            }

            return [
                'route'    => 'search',
                'key'      => 'q' . $q,
                'feed'     => $cards,
                'side'     => $this->renderRankList(),
                'channels' => $this->renderChannels(),
                'hotwords' => $this->renderHotWords(),
                'meta'     => $this->renderResultMeta($q, $this->searchTotal($q)),
                'items'    => $this->itemsOf($list),
            ];
        }

        /* ==================== 数据查询 ==================== */

        /** 执行查询并取 id 列 */
        private function queryIds(string $sql, array $params): array
        {
            try {
                $rows = Database::fetchAll($sql, $params);
            } catch (\Throwable $e) {
                error_log('[zixunfeed ssr] 查询失败: ' . $e->getMessage());
                return [];
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
            }
            return $ids;
        }

        /**
         * 按 id 列表取帖子并格式化成前端结构（保持传入顺序）
         */
        private function loadPosts(array $ids): array
        {
            if (!$ids) {
                return [];
            }
            try {
                $posts = PostRepository::fetchByIds($ids, 0);
            } catch (\Throwable $e) {
                error_log('[zixunfeed ssr] 帖子读取失败: ' . $e->getMessage());
                return [];
            }

            $map = [];
            foreach ($posts as $post) {
                $map[(int)$post['id']] = $post;
            }

            $list = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if (!isset($map[$id])) {
                    continue;
                }
                $list[] = PostFormatter::formatPostBatch($map[$id], 0, [], [], []);
            }
            return $list;
        }

        /** 搜索 id：优先全文索引，失败或不可用时降级 LIKE（与接口一致） */
        private function searchIds(string $q, int $limit): array
        {
            [$where, $params] = $this->searchWhere($q);
            if ($where === '') {
                return [];
            }
            try {
                return $this->queryIds(
                    "SELECT p.id FROM posts p
                     WHERE {$where}
                     ORDER BY p.created_at DESC
                     LIMIT ?",
                    array_merge($params, [$limit])
                );
            } catch (\Throwable $e) {
                error_log('[zixunfeed ssr] 全文检索失败，降级 LIKE: ' . $e->getMessage());
                [$where2, $params2] = $this->searchWhere($q, true);
                if ($where2 === '') {
                    return [];
                }
                return $this->queryIds(
                    "SELECT p.id FROM posts p
                     WHERE {$where2}
                     ORDER BY p.created_at DESC
                     LIMIT ?",
                    array_merge($params2, [$limit])
                );
            }
        }

        /** 搜索结果总数（与搜索接口同条件） */
        private function searchTotal(string $q): int
        {
            [$where, $params] = $this->searchWhere($q);
            if ($where === '') {
                return 0;
            }
            try {
                return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
            } catch (\Throwable $e) {
                [$where2, $params2] = $this->searchWhere($q, true);
                if ($where2 === '') {
                    return 0;
                }
                try {
                    return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts p WHERE {$where2}", $params2);
                } catch (\Throwable $e2) {
                    return 0;
                }
            }
        }

        /**
         * 搜索条件：全文索引可用时用 MATCH...AGINST，否则降级 LIKE
         *
         * @return array{0:string,1:array<int,mixed>} [where 子句, 绑定参数]
         */
        private function searchWhere(string $q, bool $forceLike = false): array
        {
            if (mb_strlen($q) > 100) {
                $q = mb_substr($q, 0, 100);
            }
            $q = preg_replace('/[\x00-\x1F\x7F]/u', '', $q) ?? '';
            if ($q === '') {
                return ['', []];
            }

            $base = "p.status = 1 AND p.visibility = 'public'";

            if (!$forceLike && mb_strlen($q) >= 2 && $this->fulltextAvailable()) {
                $ftQuery = '"' . str_replace(['"', '\\'], [' ', ' '], $q) . '"';
                return [$base . " AND (MATCH(p.title, p.content) AGAINST(? IN BOOLEAN MODE))", [$ftQuery]];
            }

            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            return [$base . ' AND (p.title LIKE ? OR p.content LIKE ?)', [$like, $like]];
        }

        /** posts 全文索引是否可用（10 分钟判一次） */
        private function fulltextAvailable(): bool
        {
            static $cached = null;
            static $cachedAt = 0;
            if ($cached !== null && (time() - $cachedAt) < 600) {
                return $cached;
            }
            try {
                $count = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_posts_fulltext'"
                );
                $cached = $count > 0;
            } catch (\Throwable $e) {
                $cached = false;
            }
            $cachedAt = time();
            return $cached;
        }

        /* ==================== 卡片渲染 ==================== */

        /** 信息流卡片（首页 / 分类页，与 feed.js renderCard 一致） */
        private function renderFeedCard(array $post, int $index): string
        {
            $user = is_array($post['user'] ?? null) ? $post['user'] : [];
            $name = trim((string)($user['nickname'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: '匿名');
            $avatar = $user['avatar'] ?? '';
            $initial = $name !== '' ? mb_substr($name, 0, 1, 'UTF-8') : '?';
            $color = $avatar ? 'transparent' : $this->colorFrom((string)($user['username'] ?? ''));

            $head = '<div class="card-head">'
                . '<span class="avatar" style="background:' . $color . '">'
                . ($avatar
                    ? '<img src="' . $this->esc($this->resolveAvatar((string)$avatar)) . '" alt="">'
                    : $this->esc($initial))
                . '</span>'
                . '<div><div class="author">' . $this->esc($name) . '</div>'
                . '<div class="meta">' . $this->esc((string)($post['time_ago'] ?? '')) . '</div></div>'
                . '<button class="follow" data-follow="' . (int)($user['id'] ?? 0) . '">关注</button>'
                . '</div>';

            $target = $this->postUrl($post);
            $titleText = $this->esc($this->titleOf($post));
            $linkText = $titleText !== '' ? $titleText : '查看详情';
            $title = '<div class="card-title"><span class="tag">' . $this->esc($this->tagOf($post))
                . '</span>' . $this->linkTag($target, $linkText, 'card-title-link') . '</div>';

            $descText = $this->descOf($post);
            $desc = $descText !== '' ? '<div class="card-desc">' . $this->esc($descText) . '</div>' : '';
            $foot = $this->renderCardFoot($post);

            $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
            if (count($videos) >= 1) {
                $video = is_array($videos[0]) ? $videos[0] : [];
                $duration = !empty($video['duration']) ? $this->fmtDur((int)$video['duration']) : '';
                $cover = $this->esc($this->coverOf($post));
                $body = '<div class="video-thumb">'
                    . $this->linkTag(
                        $target,
                        '<img src="' . $cover . '" alt="' . $linkText . '" loading="lazy">',
                        'card-cover-link'
                    )
                    . '<span class="play">' . self::ICON_PLAY . '</span>'
                    . ($duration !== '' ? '<span class="duration">' . $duration . '</span>' : '')
                    . '</div>' . $title . $desc . $foot;
            } else {
                $cover = $this->esc($this->coverOf($post));
                $img = '<img src="' . $cover . '" alt="' . $linkText . '" loading="lazy">';
                if ($index % 2 === 0) {
                    $body = '<div class="big-thumb">'
                        . $this->linkTag($target, $img, 'card-cover-link')
                        . '</div>' . $title . $desc . $foot;
                } else {
                    $body = '<div class="side-body"><div class="side-thumb">'
                        . $this->linkTag($target, $img, 'card-cover-link')
                        . '</div><div class="side-content">' . $title . $desc . $foot . '</div></div>';
                }
            }

            return '<article class="card" data-id="' . (int)($post['id'] ?? 0) . '">' . $head . $body . '</article>';
        }

        /** 信息流卡片底部互动条 */
        private function renderCardFoot(array $post): string
        {
            $timeText = (string)($post['time_ago'] ?? '');
            if ($timeText === '' && !empty($post['created_at'])) {
                $timeText = substr(str_replace('T', ' ', (string)$post['created_at']), 0, 16);
            }
            return '<div class="card-foot">'
                . '<span class="act read" title="阅读量">' . self::ICON_EYE . '<span>' . (int)($post['view_count'] ?? 0) . '</span></span>'
                . ($timeText !== '' ? '<span class="act time" title="发布时间">' . self::ICON_CLOCK . '<span>' . $this->esc($timeText) . '</span></span>' : '')
                . '<span class="act" data-like>' . self::ICON_LIKE . '<span>' . (int)($post['like_count'] ?? 0) . '</span></span>'
                . '<span class="act" data-comment>' . self::ICON_COMMENT . (int)($post['comment_count'] ?? 0) . '</span>'
                . '<span class="act" data-collect>' . self::ICON_STAR . '收藏</span>'
                . '</div>';
        }

        /** 话题页卡片（与 topic.js buildCard 一致） */
        private function renderTopicCard(array $post): string
        {
            $user = is_array($post['user'] ?? null) ? $post['user'] : [];
            $author = trim((string)($user['nickname'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: '匿名');
            $target = $this->postUrl($post);
            $title = $this->esc($this->titleOf($post));
            $cover = $this->coverOf($post);
            $tag = $this->catName((int)($post['category_id'] ?? 0)) ?: '资讯';

            $thumb = $cover === ''
                ? ''
                : '<img src="' . $this->esc($cover) . '" alt="' . $title . '" loading="lazy">';

            return '<article class="card" data-id="' . (int)($post['id'] ?? 0) . '">'
                . '<div class="side-body">'
                . '<div class="thumb">' . $this->linkTag($target, $thumb, 'card-cover-link') . '</div>'
                . '<div class="side-content">'
                . '<div class="card-title"><span class="tag">' . $this->esc($tag) . '</span>'
                . $this->linkTag($target, $title, 'card-title-link') . '</div>'
                . '<div class="card-desc">' . $this->esc($this->descOf($post)) . '</div>'
                . '<div class="card-foot">'
                . '<span>' . $this->esc($author) . '</span>'
                . '<span>' . $this->esc((string)($post['time_ago'] ?? '')) . '</span>'
                . '<span>评论 ' . (int)($post['comment_count'] ?? 0) . '</span>'
                . '<span>赞 ' . (int)($post['like_count'] ?? 0) . '</span>'
                . '</div>'
                . '</div></div></article>';
        }

        /** 搜索结果卡片（与 search.js buildCard 一致，标题带关键词高亮） */
        private function renderSearchCard(array $post, string $q): string
        {
            $user = is_array($post['user'] ?? null) ? $post['user'] : [];
            $author = trim((string)($user['nickname'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: '匿名');
            $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
            $isVideo = count($videos) >= 1;
            $video = $isVideo && is_array($videos[0]) ? $videos[0] : [];
            $thumb = ($isVideo && !empty($video['cover']))
                ? (string)$video['cover']
                : $this->coverOf($post);
            $duration = $isVideo && !empty($video['duration']) ? $this->fmtDur((int)$video['duration']) : '';

            $target = $this->postUrl($post);
            $tag = $this->catName((int)($post['category_id'] ?? 0)) ?: ($isVideo ? '视频' : '资讯');
            $title = $this->highlight($this->titleOf($post), $q);
            $desc = $this->highlight($this->descOf($post), $q);

            $foot = '<div class="card-foot">'
                . '<span>' . $this->esc($author) . '</span>'
                . '<span>' . $this->esc((string)($post['time_ago'] ?? '')) . '</span>'
                . ($isVideo
                    ? '<span class="type-video">' . self::ICON_PLAY . ($duration !== '' ? $duration : '视频') . '</span>'
                    : '<span>评论 ' . (int)($post['comment_count'] ?? 0) . '</span>')
                . '<span>赞 ' . (int)($post['like_count'] ?? 0) . '</span>'
                . '</div>';

            $thumbHtml = $thumb === ''
                ? ''
                : '<div class="thumb">' . $this->linkTag(
                    $target,
                    '<img src="' . $this->esc($thumb) . '" alt="' . $this->esc((string)($post['title'] ?? '内容')) . '" loading="lazy">',
                    'card-cover-link'
                ) . '</div>';

            return '<article class="card" data-id="' . (int)($post['id'] ?? 0) . '">'
                . '<div class="side-body' . ($thumb === '' ? ' no-thumb' : '') . '">'
                . $thumbHtml
                . '<div class="side-content">'
                . '<div class="card-title"><span class="tag">' . $this->esc($tag) . '</span>'
                . $this->linkTag($target, $title, 'card-title-link') . '</div>'
                . '<div class="card-desc">' . $desc . '</div>'
                . $foot
                . '</div></div></article>';
        }

        /** 个人主页作品卡（与 user.js buildWork 一致） */
        private function renderWorkCard(array $post): string
        {
            $target = $this->postUrl($post);
            $title = $this->esc($this->titleOf($post));
            $cover = $this->coverOf($post);
            $thumb = $cover === ''
                ? ''
                : '<img src="' . $this->esc($cover) . '" alt="' . $title . '" loading="lazy">';

            return '<article class="work" data-id="' . (int)($post['id'] ?? 0) . '">'
                . '<div class="work-thumb">' . $this->linkTag($target, $thumb, 'card-cover-link') . '</div>'
                . '<div class="work-info">'
                . '<div class="work-title">' . $this->linkTag($target, $title) . '</div>'
                . '<div class="work-meta">'
                . '<span>赞 ' . (int)($post['like_count'] ?? 0) . '</span>'
                . '<span>评论 ' . (int)($post['comment_count'] ?? 0) . '</span>'
                . '</div></div></article>';
        }

        /** 热榜条目（与 ranking.js 一致） */
        private function renderRankItem(array $post, int $index): string
        {
            $target = $this->postUrl($post);
            $title = $this->esc($this->titleOf($post));
            $cat = $this->catName((int)($post['category_id'] ?? 0)) ?: '资讯';
            $likes = (int)($post['like_count'] ?? 0);
            $comments = (int)($post['comment_count'] ?? 0);
            $up = $likes >= $comments;
            $cover = $this->coverOf($post);

            return '<a class="rank-item' . ($index < 3 ? ' top top-' . ($index + 1) : '') . '" data-id="' . (int)($post['id'] ?? 0) . '" href="' . $this->href($target) . '" data-spa="1">'
                . '<span class="rank-no">' . ($index + 1) . '</span>'
                . '<div class="rank-main">'
                . '<div class="rank-title">' . $title . '</div>'
                . '<div class="rank-sub">'
                . '<span>' . $this->esc($cat) . ' · ' . $comments . ' 条讨论</span>'
                . '<span class="trend ' . ($up ? '' : 'down') . '">' . ($up ? '▲ 上升' : '▼ 下降') . '</span>'
                . '<span>热度 ' . $this->fmtNum($this->hotScore($post)) . '</span>'
                . '</div></div>'
                . '<div class="rank-thumb">'
                . ($cover === ''
                    ? ''
                    : '<img src="' . $this->esc($cover) . '" alt="' . $title . '" loading="lazy">')
                . '</div></a>';
        }

        /** 视频页卡片（与 video.js buildCard 一致） */
        private function renderVideoCard(array $post): string
        {
            $user = is_array($post['user'] ?? null) ? $post['user'] : [];
            $name = trim((string)($user['nickname'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: '匿名');
            $avatar = (string)($user['avatar'] ?? '');
            $initial = $name !== '' ? mb_substr($name, 0, 1, 'UTF-8') : '?';
            $bg = $avatar ? 'transparent' : $this->colorFrom((string)($user['username'] ?? ''));
            $cover = $this->videoCover($post);
            $duration = $this->videoDuration($post);
            $target = $this->postUrl($post);

            return '<article class="v-card" data-id="' . (int)($post['id'] ?? 0) . '">'
                . '<div class="v-thumb">'
                . ($cover === '' ? '' : '<img src="' . $this->esc($cover) . '" alt="" loading="lazy">')
                . '<span class="v-play">' . self::ICON_PLAY . '</span>'
                . '<span class="duration">' . $this->esc($duration) . '</span>'
                . '</div>'
                . '<div class="v-info">'
                . '<div class="v-title">' . $this->linkTag($target, $this->esc($this->titleOf($post))) . '</div>'
                . '<div class="v-meta">'
                . '<span class="v-avatar" style="background:' . $bg . '">'
                . ($avatar
                    ? '<img src="' . $this->esc($this->resolveAvatar($avatar)) . '" alt="">'
                    : $this->esc($initial))
                . '</span>'
                . '<span class="name">' . $this->esc($name) . '</span>'
                . '<span class="stat">' . self::ICON_LIKE . (int)($post['like_count'] ?? 0) . '</span>'
                . '<span class="stat">' . self::ICON_COMMENT . (int)($post['comment_count'] ?? 0) . '</span>'
                . '</div></div></article>';
        }

        /* ==================== 侧栏区块 ==================== */

        /** 搜索结果计数行（与 search.js setMeta 一致） */
        private function renderResultMeta(string $q, int $total): string
        {
            if ($total <= 0) {
                return '';
            }
            return '共找到 <b>' . $total . '</b> 篇与「<b>' . $this->esc($q) . '</b>」相关的内容';
        }

        /** 热门搜索词（与 search.js hotWordsOf 一致：优先分类名） */
        private function renderHotWords(): string
        {
            $names = [];
            foreach ($this->categories() as $cat) {
                $n = trim((string)$cat['name']);
                if ($n !== '' && !in_array($n, $names, true)) {
                    $names[] = $n;
                }
            }
            if (!$names) {
                $names = ['钓鱼技巧', '装备评测', '野钓', '路亚'];
            }
            $names = array_slice($names, 0, 8);

            $html = '';
            foreach ($names as $name) {
                $html .= '<button type="button" class="search-hot-word">' . $this->esc($name) . '</button>';
            }
            return $html;
        }

        /** 顶栏频道 Tab（与 nav.js renderChannelTabs 一致）
         *
         * 「推荐 / 热点」指向站点首页，其余指向分类页 —— 爬虫靠它发现全部分类。
         */
        private function renderChannels(): string
        {
            $home = $this->baseUrl !== '' ? $this->baseUrl . '/' : '/';
            $html = '<a class="channel-tab active" data-channel="all" href="' . $this->href($home) . '">推荐</a>'
                . '<a class="channel-tab" data-channel="hot" href="' . $this->href($home) . '">热点</a>';

            foreach ($this->categories() as $cat) {
                $id = (int)$cat['id'];
                $html .= '<a class="channel-tab" data-channel="cat:' . $id . '" href="'
                    . $this->href($this->pageBase . '/c/' . $id) . '">' . $this->esc((string)$cat['name']) . '</a>';
            }
            return $html;
        }

        /** 快讯条文案（与 buildFlash 一致：最新 6 条标题） */
        private function renderFlash(array $list): string
        {
            $titles = [];
            foreach ($list as $post) {
                $t = $this->titleOf($post);
                if ($t !== '') {
                    $titles[] = $t;
                }
                if (count($titles) >= 6) {
                    break;
                }
            }
            if (!$titles) {
                return '';
            }
            return $this->esc(implode('　·　', $titles));
        }

        /** 分类页频道头（与 category.js fillHead 一致） */
        private function renderCategoryHead(string $name): string
        {
            $name = trim($name);
            $label = $name !== '' ? $name : '全部资讯';
            return '<section class="chan-hero chan-head" id="chanHead">'
                . '<div class="chan-cover" id="chanCover"></div>'
                . '<div class="chan-info">'
                . '<div class="chan-eyebrow">频道</div>'
                . '<h2 class="chan-name" id="chanName">' . $this->esc($label) . '</h2>'
                . '<div class="chan-desc" id="chanDesc">聚合该频道下的全部内容</div>'
                . '<div class="chan-actions">'
                . '<div class="chan-stats" id="chanStats"><b>—</b> 篇文章</div>'
                . '<button class="follow chan-follow" id="chanFollow" data-follow>关注</button>'
                . '</div></div></section>';
        }

        /** 首页轮播（与 buildHero 一致） */
        private function renderHero(array $slides): string
        {
            $html = '';
            foreach ($slides as $i => $slide) {
                $post = $slide['post'];
                $html .= '<a class="hero-slide' . ($i === 0 ? ' active' : '') . '" data-id="' . (int)($post['id'] ?? 0)
                    . '" href="' . $this->href($this->postUrl($post)) . '" data-spa="1">'
                    . '<img src="' . $this->esc((string)$slide['cover']) . '" alt="'
                    . $this->esc($this->titleOf($post)) . '" loading="eager">'
                    . '<div class="hero-mask"></div>'
                    . '<div class="hero-text"><span class="hero-tag">' . $this->esc($this->tagOf($post)) . '</span>'
                    . '<div class="hero-title">' . $this->esc($this->titleOf($post)) . '</div></div>'
                    . '</a>';
            }
            return $html;
        }

        /** 侧栏热榜（首页 / 分类页 / 搜索页，与 buildSidebar 的热榜一致） */
        private function renderRankList(): string
        {
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                 ORDER BY (SELECT COUNT(*) FROM likes WHERE post_id = p.id) DESC, p.created_at DESC
                 LIMIT ?",
                [8]
            );
            $list = $this->loadPosts($ids);
            if (!$list) {
                return '';
            }

            $html = '';
            foreach ($list as $i => $post) {
                $html .= '<a class="rank-item" data-id="' . (int)($post['id'] ?? 0) . '" href="'
                    . $this->href($this->postUrl($post)) . '" data-spa="1">'
                    . '<span class="rank-no">' . ($i + 1) . '</span>'
                    . '<div><div class="rank-text">' . $this->esc($this->titleOf($post)) . '</div>'
                    . '<div class="rank-hot">' . (int)($post['like_count'] ?? 0) . ' 赞</div></div>'
                    . '</a>';
            }
            return $html;
        }

        /** 热榜页「24 小时飙升」（与 renderAside 一致） */
        private function renderRiseList(): string
        {
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                 ORDER BY (SELECT COUNT(*) FROM comments WHERE post_id = p.id) DESC, p.created_at DESC
                 LIMIT ?",
                [5]
            );
            $list = $this->loadPosts($ids);
            if (!$list) {
                return '';
            }

            $html = '';
            foreach ($list as $i => $post) {
                $html .= '<a class="rise-item" data-id="' . (int)($post['id'] ?? 0) . '" href="'
                    . $this->href($this->postUrl($post)) . '" data-spa="1">'
                    . '<span class="rise-no">' . ($i + 1) . '</span>'
                    . '<span class="rise-text">' . $this->esc($this->titleOf($post)) . '</span>'
                    . '<span class="rise-num">+' . (int)($post['comment_count'] ?? 0) . '</span></a>';
            }
            return $html;
        }

        /** 侧栏话题 chips（分类入口） */
        private function renderTopicChips(): string
        {
            $cats = $this->categories();
            if (!$cats) {
                return '<a class="chip" href="' . $this->href($this->pageBase . '/search') . '" data-spa="1">#热门</a>';
            }

            $html = '';
            $i = 0;
            foreach ($cats as $cat) {
                if ($i >= 10) {
                    break;
                }
                $i++;
                $html .= '<a class="chip" data-cat="' . (int)$cat['id'] . '" href="'
                    . $this->href($this->pageBase . '/c/' . (int)$cat['id']) . '" data-spa="1">#'
                    . $this->esc((string)$cat['name']) . '</a>';
            }
            return $html;
        }

        /** 话题页「相关话题」（与 topic.js renderAside 一致） */
        private function renderTopicRows(string $current): string
        {
            try {
                $rows = Database::fetchAll(
                    "SELECT name, post_count FROM topics WHERE status = 1 ORDER BY post_count DESC, id ASC LIMIT 8"
                );
                // 后台没维护话题时回退到 post_tags 的真实使用量（与接口一致）
                if (!$rows) {
                    $rows = Database::fetchAll(
                        "SELECT pt.tag AS name, COUNT(*) AS post_count
                         FROM post_tags pt
                         INNER JOIN posts p ON p.id = pt.post_id AND p.status = 1
                         GROUP BY pt.tag
                         ORDER BY post_count DESC, pt.tag ASC
                         LIMIT 8"
                    );
                }
            } catch (\Throwable $e) {
                return '';
            }

            $html = '';
            foreach ($rows as $row) {
                $name = (string)$row['name'];
                if ($name === '' || $name === $current) {
                    continue;
                }
                $html .= '<a class="topic-row" data-name="' . $this->esc($name) . '" href="'
                    . $this->href($this->pageBase . '/topic/' . rawurlencode($name)) . '" data-spa="1">'
                    . '<div class="t-name"><b>#' . $this->esc($name) . '</b><span>'
                    . (int)($row['post_count'] ?? 0) . ' 篇讨论</span></div>'
                    . '<span class="join">进入</span></a>';
                if (substr_count($html, 'topic-row') >= 6) {
                    break;
                }
            }
            return $html;
        }

        /* ==================== 字段派生 ==================== */

        /** 文章链接 */
        private function postUrl(array $post): string
        {
            return $this->pageBase . '/p/' . (int)($post['id'] ?? 0);
        }

        /** 标题：没有标题时取正文前 40 字 */
        private function titleOf(array $post): string
        {
            $title = trim((string)($post['title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
            $plain = $this->plainText((string)($post['content'] ?? ''));
            if ($plain === '') {
                return '动态';
            }
            return mb_substr($plain, 0, 40, 'UTF-8');
        }

        /** 摘要：正文纯文本前 60 字 */
        private function descOf(array $post): string
        {
            $plain = $this->plainText((string)($post['content'] ?? ''));
            if ($plain === '') {
                return '';
            }
            return mb_strlen($plain, 'UTF-8') > 60 ? mb_substr($plain, 0, 60, 'UTF-8') . '…' : $plain;
        }

        /** 正文转纯文本（与 utils.js plainText 一致） */
        private function plainText(string $content): string
        {
            $text = strip_tags($content);
            $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;
            $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;
            $text = preg_replace('/[*_`>#]/', '', $text) ?? $text;
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            return trim($text);
        }

        /** 卡片标签：分类名 > 置顶 > 资讯 */
        private function tagOf(array $post): string
        {
            $name = $this->catName((int)($post['category_id'] ?? 0));
            if ($name !== '') {
                return $name;
            }
            return !empty($post['is_pinned']) ? '置顶' : '资讯';
        }

        /** 分类 id => 名称 */
        private function catName(int $id): string
        {
            if ($id <= 0) {
                return '';
            }
            $this->loadCategories();
            return $this->catNames[$id] ?? '';
        }

        /** 分类列表（缓存 10 分钟，与接口 /app/categories 同源） */
        private function categories(): array
        {
            $this->loadCategories();
            return array_values($this->catNames === [] ? [] : $this->catList);
        }

        /** @var array 分类原始列表（保持接口的排序） */
        private array $catList = [];

        private function loadCategories(): void
        {
            if ($this->catList) {
                return;
            }
            try {
                $rows = Cache::remember('app_categories', function () {
                    return Database::fetchAll(
                        "SELECT id, parent_id, name, sort_order
                         FROM categories WHERE status = 1
                         ORDER BY sort_order ASC, id ASC"
                    );
                }, 600);
            } catch (\Throwable $e) {
                return;
            }
            foreach ($rows as $row) {
                $cid = (int)$row['id'];
                $this->catList[] = $row;
                $this->catNames[$cid] = (string)$row['name'];
                $parent = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
                if ($parent > 0) {
                    $this->catChildren[$parent][] = $cid;
                }
            }
        }

        /* ==================== 图片 / 封面 ==================== */

        /**
         * 收集可用的图片地址（与 utils.js collectPostImages 一致）
         *
         * @return string[]
         */
        private function collectImages(array $post, int $limit = 9): array
        {
            $out = [];
            $seen = [];

            $push = function (string $raw) use (&$out, &$seen): void {
                $url = $this->safeUrl(trim($raw));
                if ($url !== '' && !in_array($url, $seen, true)) {
                    $seen[] = $url;
                    $out[] = $url;
                }
            };

            $images = is_array($post['images'] ?? null) ? $post['images'] : [];
            foreach ($images as $img) {
                if (is_string($img)) {
                    $push($img);
                } elseif (is_array($img)) {
                    $push((string)($img['url'] ?? $img['src'] ?? ''));
                }
            }

            $content = (string)($post['content'] ?? '');
            if (preg_match_all('/!\[[^\]]*\]\(\s*<?([^)\s>]+)>?/u', $content, $m)) {
                foreach ($m[1] as $url) {
                    $push(str_replace('&amp;', '&', (string)$url));
                }
            }
            if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/iu', $content, $m)) {
                foreach ($m[1] as $url) {
                    $push(str_replace('&amp;', '&', (string)$url));
                }
            }

            if (!$out) {
                $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
                foreach ($videos as $video) {
                    if (is_array($video) && !empty($video['cover'])) {
                        $push((string)$video['cover']);
                    }
                }
            }

            return $limit > 0 ? array_slice($out, 0, $limit) : $out;
        }

        /** 封面图：无图时生成渐变 SVG 兜底（与 feed.js coverFor 一致） */
        private function coverOf(array $post): string
        {
            $imgs = $this->collectImages($post);
            if ($imgs) {
                return $imgs[0];
            }

            $label = $this->escXml($this->tagOf($post) ?: '资讯');
            $hue = $this->hueOf((string)($post['title'] ?? $post['id'] ?? ''));
            $hue2 = ($hue + 28) % 360;
            $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='800' height='450'>"
                . "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>"
                . "<stop offset='0' stop-color='hsl({$hue},45%,46%)'/>"
                . "<stop offset='1' stop-color='hsl({$hue2},45%,32%)'/>"
                . "</linearGradient></defs>"
                . "<rect width='800' height='450' fill='url(#g)'/>"
                . "<text x='400' y='228' fill='rgba(255,255,255,.92)' font-size='46' "
                . "font-family='PingFang SC,Microsoft YaHei,sans-serif' font-weight='700' "
                . "text-anchor='middle' dominant-baseline='middle'>{$label}</text>"
                . "</svg>";
            return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
        }

        /** 视频封面 */
        private function videoCover(array $post): string
        {
            $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
            if ($videos && is_array($videos[0]) && !empty($videos[0]['cover'])) {
                return (string)$videos[0]['cover'];
            }
            $imgs = $this->collectImages($post, 1);
            return $imgs ? $imgs[0] : '';
        }

        /** 视频时长文案 */
        private function videoDuration(array $post): string
        {
            $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
            $duration = 0;
            if ($videos && is_array($videos[0])) {
                $duration = (int)($videos[0]['duration'] ?? 0);
            }
            if (!$duration) {
                return '视频';
            }
            return sprintf('%02d:%02d', intdiv($duration, 60), $duration % 60);
        }

        /* ==================== 小工具 ==================== */

        /** 站内链接（真实 href + data-spa） */
        private function linkTag(string $url, string $inner, string $cls = ''): string
        {
            $clsAttr = $cls !== '' ? ' class="' . $cls . '"' : '';
            return '<a' . $clsAttr . ' href="' . $this->href($url) . '" data-spa="1">' . $inner . '</a>';
        }

        /** 属性值转义 */
        private function href(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        /** 文本转义（先剔除非法 UTF-8，避免 htmlspecialchars 返回空串） */
        private function esc(string $text): string
        {
            $text = $this->cleanUtf8($text);
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        /** XML 文本转义（用于 SVG data URI） */
        private function escXml(string $text): string
        {
            $text = $this->cleanUtf8($text);
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        private function cleanUtf8(string $text): string
        {
            if (mb_check_encoding($text, 'UTF-8')) {
                return $text;
            }
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            return is_string($clean) ? $clean : '';
        }

        /** URL 安全化：只允许站内路径与 http/https */
        private function safeUrl(string $url): string
        {
            $url = trim($url);
            if ($url === '') {
                return '';
            }
            if (preg_match('#^(\/|\./|\.\./)#', $url)) {
                return $url;
            }
            if (preg_match('#^https?://#i', $url)) {
                return $url;
            }
            if (str_starts_with($url, '//')) {
                return $url;
            }
            return '';
        }

        /** 头像地址（与 auth.js resolveAvatar 一致） */
        private function resolveAvatar(string $avatar): string
        {
            $avatar = trim($avatar);
            if ($avatar === '' || $avatar === 'null' || $avatar === 'undefined') {
                return 'assets/images/default-avatar.svg';
            }
            if (str_ends_with($avatar, '/uploads/avatars/default.svg')
                || $avatar === 'templates/moments/assets/images/default-avatar.svg') {
                return 'assets/images/default-avatar.svg';
            }
            return $avatar;
        }

        /** 无头像时的底色（与 feed.js colorFrom 一致） */
        private function colorFrom(string $str): string
        {
            return 'hsl(' . $this->hueOf($str) . ' 38% 42%)';
        }

        /** 字符串 → 色相（与 feed.js hueOf 一致） */
        private function hueOf(string $str): int
        {
            $h = 0;
            $len = strlen($str);
            for ($i = 0; $i < $len; $i++) {
                $h = ($h * 31 + ord($str[$i])) % 360;
            }
            return $h;
        }

        /** 热度分（与 ranking.js hot 一致） */
        private function hotScore(array $post): int
        {
            return (int)($post['like_count'] ?? 0)
                + (int)($post['comment_count'] ?? 0) * 2
                + (int)($post['view_count'] ?? 0);
        }

        /** 数字千分位（万为单位） */
        private function fmtNum(int $n): string
        {
            if ($n >= 10000) {
                return rtrim(rtrim(number_format($n / 10000, 1, '.', ''), '0'), '.') . '万';
            }
            return (string)$n;
        }

        /** 秒 → mm:ss */
        private function fmtDur(int $seconds): string
        {
            return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        /** 关键词高亮（与 search.js hl 一致） */
        private function highlight(string $text, string $q): string
        {
            $safe = $this->esc($text);
            if ($q === '') {
                return $safe;
            }
            $needle = $this->esc($q);
            if ($needle === '') {
                return $safe;
            }
            return preg_replace(
                '/' . preg_quote($needle, '/') . '/iu',
                '<span class="hl">$0</span>',
                $safe
            ) ?? $safe;
        }

        /** 结构化数据条目 */
        private function itemsOf(array $list): array
        {
            $items = [];
            foreach ($list as $post) {
                $items[] = [
                    'id'    => (int)($post['id'] ?? 0),
                    'title' => $this->titleOf($post),
                    'url'   => $this->origin . $this->postUrl($post),
                ];
            }
            return $items;
        }

        /** 主题选项 */
        private function optEnabled(string $key, bool $default): bool
        {
            if (!array_key_exists($key, $this->opts)) {
                return $default;
            }
            return $this->opts[$key] !== false;
        }

        /** 空结果 */
        private function blankResult(string $route = ''): array
        {
            return [
                'route'    => $route,
                'key'      => '',
                'feed'     => '',
                'hero'     => '',
                'side'     => '',
                'chips'    => '',
                'channels' => '',
                'flash'    => '',
                'cathead'  => '',
                'hotwords' => '',
                'meta'     => '',
                'items'    => [],
            ];
        }
    }
}

return new SsrRenderer();
