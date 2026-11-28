<?php

declare(strict_types=1);

/**
 * shaiboblog 赛博博客列表页服务端渲染器（SSR）
 *
 * 把首页 / 分类 / 话题 / 用户 / 搜索的首屏文章，以及详情页右栏热门阅读，
 * 在服务端渲染成带真实 <a href> 的 HTML —— 爬虫不执行 JS 也能读到文章链接。
 * 输出结构与 assets/js/modules/feed.js 的 renderCard 保持同构，JS 接管时无跳变。
 *
 * 约定（与 zixunfeed/ssr.php 一致）：
 *   - 渲染器接收 ['path','query','pageBase','origin','baseUrl','themeOpts']，返回框架约定数组
 *   - 只输出公开动态：p.status = 1 AND p.visibility = 'public'（游客视角）
 */
namespace Hublog\Themes\Shaiboblog;

use Hublog\Core\Cache;
use Hublog\Core\Database;
use Hublog\Core\PostFormatter;
use Hublog\Core\PostRepository;

if (!class_exists(SsrRenderer::class, false)) {

    class SsrRenderer
    {
        /** 渲染逻辑变更时 +1，旧缓存自然过期 */
        private const VERSION = '20261128.2';

        private string $pageBase = '';
        private string $origin = '';
        private string $baseUrl = '';
        private array $opts = [];
        private array $catNames = [];
        private array $catList = [];

        private const ICON_EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
        private const ICON_COMMENT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>';
        private const ICON_LIKE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>';
        private const ICON_STAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';

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

            $cacheKey = 'ssr_shaiboblog_v' . self::VERSION . '_'
                . md5($route['name'] . '|' . $route['key'])
                . '_t' . Cache::nsVersion('timeline');

            try {
                $result = Cache::remember($cacheKey, function () use ($route) {
                    return $this->renderRoute($route);
                }, 60);
            } catch (\Throwable $e) {
                error_log('[shaiboblog ssr] 渲染失败: ' . $e->getMessage());
                return $this->blankResult();
            }
            return is_array($result) ? $result : $this->blankResult();
        }

        /** 路径 → 渲染分支 */
        private function matchRoute(string $path, array $query): ?array
        {
            $path = '/' . trim($path, '/');
            if ($path === '/') {
                return ['name' => 'home', 'key' => ''];
            }
            if (preg_match('#^/c/(\d+)$#', $path, $m)) {
                return ['name' => 'category', 'key' => 'c' . (int)$m[1], 'category_id' => (int)$m[1]];
            }
            if (preg_match('#^/p/(\d+)$#', $path, $m)) {
                return ['name' => 'post', 'key' => 'p' . (int)$m[1], 'post_id' => (int)$m[1]];
            }
            if (preg_match('#^/topic/(.+)$#', $path, $m)) {
                $name = urldecode($m[1]);
                return $name === '' ? null : ['name' => 'topic', 'key' => 't' . $name, 'topic' => $name];
            }
            if (preg_match('#^/u/([a-zA-Z0-9_-]+)$#', $path, $m)) {
                return ['name' => 'user', 'key' => 'u' . $m[1], 'username' => $m[1]];
            }
            if ($path === '/search') {
                $q = trim((string)($query['q'] ?? ''));
                return $q === '' ? null : ['name' => 'search', 'key' => 'q' . $q, 'q' => $q];
            }
            return null;
        }

        private function renderRoute(array $route): array
        {
            switch ($route['name']) {
                case 'home':
                    return $this->renderHome();
                case 'category':
                    return $this->renderCategory((int)$route['category_id']);
                case 'post':
                    return $this->renderPostSide((int)$route['post_id']);
                case 'topic':
                    return $this->renderTopic((string)$route['topic']);
                case 'user':
                    return $this->renderUser((string)$route['username']);
                case 'search':
                    return $this->renderSearch((string)$route['q']);
            }
            return $this->blankResult();
        }

        /** 首页：公开动态时间线 */
        private function renderHome(): array
        {
            $list = $this->loadPosts($this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT 10",
                []
            ));
            return [
                'route' => 'home',
                'key'   => '',
                'feed'  => $this->cardsOf($list),
                'items' => $this->itemsOf($list),
            ];
        }

        /** 分类页：父分类带子分类 */
        private function renderCategory(int $categoryId): array
        {
            if ($categoryId <= 0) {
                return $this->blankResult('category');
            }
            $catIds = [$categoryId];
            try {
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
            } catch (\Throwable $e) {
                error_log('[shaiboblog ssr] 分类查询失败: ' . $e->getMessage());
                return $this->blankResult('category');
            }

            $ph = implode(',', array_fill(0, count($catIds), '?'));
            $list = $this->loadPosts($this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public' AND p.category_id IN ({$ph})
                 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC
                 LIMIT 10",
                $catIds
            ));
            return [
                'route' => 'category',
                'key'   => 'c' . $categoryId,
                'feed'  => $this->cardsOf($list),
                'items' => $this->itemsOf($list),
            ];
        }

        /** 话题页：post_tags 优先，回退正文 #话题# */
        private function renderTopic(string $topic): array
        {
            try {
                $total = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND p.visibility = 'public'",
                    [$topic]
                );
            } catch (\Throwable $e) {
                $total = 0;
            }
            if ($total > 0) {
                $ids = $this->queryIds(
                    "SELECT pt.post_id AS id FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = ? AND p.status = 1 AND p.visibility = 'public'
                     ORDER BY pt.post_id DESC LIMIT 10",
                    [$topic]
                );
            } else {
                $pattern = '%#' . str_replace(['%', '_'], ['\%', '\_'], $topic) . '#%';
                $ids = $this->queryIds(
                    "SELECT p.id FROM posts p
                     WHERE p.status = 1 AND p.visibility = 'public' AND p.content LIKE ?
                     ORDER BY p.id DESC LIMIT 10",
                    [$pattern]
                );
            }
            $list = $this->loadPosts($ids);
            return [
                'route' => 'topic',
                'key'   => 't' . $topic,
                'feed'  => $this->cardsOf($list),
                'items' => $this->itemsOf($list),
            ];
        }

        /** 用户主页作品 */
        private function renderUser(string $username): array
        {
            try {
                $user = Database::fetchOne(
                    "SELECT id FROM users WHERE username = ? LIMIT 1",
                    [$username]
                );
            } catch (\Throwable $e) {
                return $this->blankResult('user');
            }
            if (!$user) {
                return $this->blankResult('user');
            }
            $list = $this->loadPosts($this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public' AND p.user_id = ?
                 ORDER BY p.is_pinned DESC, p.created_at DESC LIMIT 12",
                [(int)$user['id']]
            ));
            return [
                'route' => 'user',
                'key'   => 'u' . $username,
                'feed'  => $this->cardsOf($list),
                'items' => $this->itemsOf($list),
            ];
        }

        /** 搜索结果（LIKE，与后端 /search 的降级路径一致） */
        private function renderSearch(string $q): array
        {
            $q = mb_substr($q, 0, 100, 'UTF-8');
            $q = preg_replace('/[\x00-\x1F\x7F]/u', '', $q) ?? '';
            if ($q === '') {
                return $this->blankResult('search');
            }
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $list = $this->loadPosts($this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public'
                   AND (p.title LIKE ? OR p.content LIKE ?)
                 ORDER BY p.created_at DESC LIMIT 10",
                [$like, $like]
            ));
            return [
                'route'    => 'search',
                'key'      => 'q' . $q,
                'feed'     => $this->cardsOf($list),
                'hotwords' => $this->hotWords(),
                'side'     => $this->rankList(),
                'items'    => $this->itemsOf($list),
            ];
        }

        /** 详情页右栏：热门阅读（与 article.js loadAside 同结构） */
        private function renderPostSide(int $postId): array
        {
            $ids = $this->queryIds(
                "SELECT p.id FROM posts p
                 WHERE p.status = 1 AND p.visibility = 'public' AND p.id <> ?
                 ORDER BY p.like_count DESC, p.created_at DESC LIMIT 8",
                [$postId]
            );
            $result = $this->blankResult('post');
            $result['key']  = 'p' . $postId;
            $result['side'] = $this->rankList($ids);
            return $result;
        }

        /* ==================== 数据查询 ==================== */

        private function queryIds(string $sql, array $params): array
        {
            try {
                $rows = Database::fetchAll($sql, $params);
            } catch (\Throwable $e) {
                error_log('[shaiboblog ssr] 查询失败: ' . $e->getMessage());
                return [];
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['id'];
            }
            return $ids;
        }

        /** 按 id 取帖子并格式化（保持顺序），与前端 renderCard 数据源一致 */
        private function loadPosts(array $ids): array
        {
            if (!$ids) {
                return [];
            }
            try {
                $posts = PostRepository::fetchByIds($ids, 0);
            } catch (\Throwable $e) {
                error_log('[shaiboblog ssr] 帖子读取失败: ' . $e->getMessage());
                return [];
            }
            $map = [];
            foreach ($posts as $post) {
                $map[(int)$post['id']] = $post;
            }
            $list = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if (isset($map[$id])) {
                    $list[] = PostFormatter::formatPostBatch($map[$id], 0, [], [], []);
                }
            }
            return $list;
        }

        /* ==================== 卡片渲染（对齐 feed.js renderCard） ==================== */

        private function cardsOf(array $list): string
        {
            $html = '';
            foreach ($list as $post) {
                $html .= $this->card($post);
            }
            return $html;
        }

        private function card(array $post): string
        {
            $id = (int)($post['id'] ?? 0);
            $target = $this->pageBase . '/p/' . $id;
            $user = is_array($post['user'] ?? null) ? $post['user'] : [];
            $author = trim((string)($user['nickname'] ?? '')) ?: (trim((string)($user['username'] ?? '')) ?: '匿名');
            $authorHref = !empty($user['username'])
                ? $this->pageBase . '/u/' . rawurlencode((string)$user['username'])
                : '';
            $title = $this->titleOf($post);
            $titleHtml = '<a class="post-title-link" href="' . $this->href($target) . '" data-spa="1">' . $this->esc($title) . '</a>';

            $top = '';
            $catId = (int)($post['category_id'] ?? 0);
            $cat = $this->catName($catId);
            if ($cat !== '') {
                $top .= '<a class="post-cat" href="' . $this->href($this->pageBase . '/c/' . $catId) . '" data-spa="1">' . $this->esc($cat) . '</a>';
            }
            if (!empty($post['is_pinned'])) {
                $top .= '<span class="post-flag pin">置顶</span>';
            }
            if (!empty($post['is_original'])) {
                $top .= '<span class="post-flag orig">原创</span>';
            }
            $topHtml = $top === '' ? '' : '<div class="post-top">' . $top . '</div>';

            $desc = $this->descOf($post);
            $descHtml = $desc === '' ? '' : '<p class="post-excerpt">' . $this->esc($desc) . '</p>';

            $tags = $this->tagsOf($post);
            $tagHtml = '';
            if ($tags) {
                $parts = [];
                foreach ($tags as $t) {
                    $parts[] = '<a class="post-tag" href="' . $this->href($this->pageBase . '/search?q=' . rawurlencode($t)) . '" data-spa="1">#' . $this->esc($t) . '</a>';
                }
                $tagHtml = '<div class="post-tags">' . implode('', $parts) . '</div>';
            }

            $authorHtml = $authorHref !== ''
                ? '<a class="post-author" href="' . $this->href($authorHref) . '" data-spa="1">' . $this->esc($author) . '</a>'
                : '<span class="post-author">' . $this->esc($author) . '</span>';
            $time = $this->dateOf($post);
            $meta = '<div class="post-meta">'
                . $authorHtml
                . '<span class="post-dot">·</span>'
                . '<time>' . $this->esc($time) . '</time>'
                . '<span class="post-stat">' . self::ICON_EYE . '<span>' . (int)($post['view_count'] ?? 0) . '</span></span>'
                . '<a class="post-stat" href="' . $this->href($target . '#comments') . '" data-spa="1">' . self::ICON_COMMENT . (int)($post['comment_count'] ?? 0) . '</a>'
                . '<button class="post-act" type="button" data-like aria-label="点赞">' . self::ICON_LIKE . '<span>' . (int)($post['like_count'] ?? 0) . '</span></button>'
                . '<button class="post-act" type="button" data-collect aria-label="收藏">' . self::ICON_STAR . '</button>'
                . '</div>';

            $main = '<div class="post-main">' . $topHtml
                . '<h2 class="post-title">' . $titleHtml . '</h2>'
                . $descHtml . $tagHtml . $meta . '</div>';

            $cover = $this->coverOf($post);
            $thumb = $cover === ''
                ? ''
                : '<a class="post-thumb" href="' . $this->href($target) . '" data-spa="1"><img src="' . $this->esc($cover) . '" alt="' . $this->esc($title) . '" loading="lazy"></a>';

            return '<article class="post" data-id="' . $id . '">' . $main . $thumb . '</article>';
        }

        /** 热门榜单（对齐 article.js / search.js 的 rank-item） */
        private function rankList(array $ids = []): string
        {
            if (!$ids) {
                $ids = $this->queryIds(
                    "SELECT p.id FROM posts p
                     WHERE p.status = 1 AND p.visibility = 'public'
                     ORDER BY p.like_count DESC, p.created_at DESC LIMIT 8",
                    []
                );
            }
            $list = $this->loadPosts($ids);
            $html = '';
            foreach ($list as $i => $post) {
                $html .= '<a class="rank-item" data-id="' . (int)($post['id'] ?? 0)
                    . '" href="' . $this->href($this->pageBase . '/p/' . (int)($post['id'] ?? 0)) . '" data-spa="1">'
                    . '<span class="rank-no">' . ($i + 1) . '</span>'
                    . '<div><div class="rank-text">' . $this->esc($this->titleOf($post)) . '</div>'
                    . '<div class="rank-hot">' . (int)($post['like_count'] ?? 0) . ' 赞</div></div></a>';
            }
            return $html;
        }

        /** 热门搜索词：取真实分类名（与 search.js hotWordsOf 一致） */
        private function hotWords(): string
        {
            $names = [];
            foreach ($this->categories() as $cat) {
                $n = trim((string)($cat['name'] ?? ''));
                if ($n !== '' && !in_array($n, $names, true)) {
                    $names[] = $n;
                }
            }
            $names = array_slice($names, 0, 8);
            $html = '';
            foreach ($names as $name) {
                $html .= '<button type="button" class="search-hot-word">' . $this->esc($name) . '</button>';
            }
            return $html;
        }

        /* ==================== 字段派生 ==================== */

        private function titleOf(array $post): string
        {
            $title = trim((string)($post['title'] ?? ''));
            if ($title !== '') {
                return $title;
            }
            $plain = $this->plainText((string)($post['content'] ?? ''));
            return $plain === '' ? '无题' : mb_substr($plain, 0, 40, 'UTF-8');
        }

        private function descOf(array $post): string
        {
            $plain = $this->plainText((string)($post['content'] ?? ''));
            return mb_strlen($plain, 'UTF-8') > 96 ? mb_substr($plain, 0, 96, 'UTF-8') . '…' : $plain;
        }

        private function plainText(string $content): string
        {
            $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $content) ?? $content;
            $text = preg_replace('/\[\[media:(image|video|attachment):\d+\]\]/', '', $text) ?? $text;
            $text = strip_tags($text);
            $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text) ?? $text;
            $text = preg_replace('/[*_`>#]/', '', $text) ?? $text;
            return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        private function tagsOf(array $post): array
        {
            $raw = is_array($post['tags'] ?? null) ? $post['tags'] : [];
            $out = [];
            foreach ($raw as $t) {
                if (is_string($t)) {
                    $out[] = $t;
                } elseif (is_array($t)) {
                    $name = (string)($t['name'] ?? $t['title'] ?? '');
                    if ($name !== '') {
                        $out[] = $name;
                    }
                }
                if (count($out) >= 3) {
                    break;
                }
            }
            return $out;
        }

        private function dateOf(array $post): string
        {
            $raw = (string)($post['time_ago'] ?? $post['created_at'] ?? '');
            return substr(str_replace('T', ' ', $raw), 0, 16);
        }

        /** 封面：与 collectPostImages 一致，无图则不渲染缩略图（与前端无跳变） */
        private function coverOf(array $post): string
        {
            $images = is_array($post['images'] ?? null) ? $post['images'] : [];
            foreach ($images as $img) {
                $url = is_string($img)
                    ? $img
                    : (is_array($img) ? (string)($img['url'] ?? $img['src'] ?? '') : '');
                $safe = $this->safeUrl(trim($url));
                if ($safe !== '') {
                    return $safe;
                }
            }
            $content = (string)($post['content'] ?? '');
            if (preg_match_all('/!\[[^\]]*\]\(\s*<?([^)\s>]+)>?/u', $content, $m)) {
                foreach ($m[1] as $url) {
                    $safe = $this->safeUrl(str_replace('&amp;', '&', (string)$url));
                    if ($safe !== '') {
                        return $safe;
                    }
                }
            }
            if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/iu', $content, $m)) {
                foreach ($m[1] as $url) {
                    $safe = $this->safeUrl(str_replace('&amp;', '&', (string)$url));
                    if ($safe !== '') {
                        return $safe;
                    }
                }
            }
            $videos = is_array($post['videos'] ?? null) ? $post['videos'] : [];
            foreach ($videos as $video) {
                if (is_array($video) && !empty($video['cover'])) {
                    $safe = $this->safeUrl((string)$video['cover']);
                    if ($safe !== '') {
                        return $safe;
                    }
                }
            }
            return '';
        }

        private function safeUrl(string $url): string
        {
            if ($url === '') {
                return '';
            }
            if (preg_match('#^(/|\./|\.\./)#', $url)) {
                return $url;
            }
            if (preg_match('#^https?://#i', $url)) {
                return $url;
            }
            return '';
        }

        private function catName(int $id): string
        {
            if ($id <= 0) {
                return '';
            }
            $this->loadCategories();
            return $this->catNames[$id] ?? '';
        }

        private function categories(): array
        {
            $this->loadCategories();
            return array_values($this->catList);
        }

        private function loadCategories(): void
        {
            if ($this->catList) {
                return;
            }
            try {
                $rows = Cache::remember('app_categories', function () {
                    return Database::fetchAll(
                        "SELECT id, parent_id, name, sort_order FROM categories
                         WHERE status = 1 ORDER BY sort_order ASC, id ASC"
                    );
                }, 600);
            } catch (\Throwable $e) {
                return;
            }
            foreach ($rows as $row) {
                $cid = (int)$row['id'];
                $this->catList[] = $row;
                $this->catNames[$cid] = (string)$row['name'];
            }
        }

        private function itemsOf(array $list): array
        {
            $items = [];
            foreach ($list as $post) {
                $items[] = [
                    'id'    => (int)($post['id'] ?? 0),
                    'title' => $this->titleOf($post),
                    'url'   => $this->origin . $this->pageBase . '/p/' . (int)($post['id'] ?? 0),
                ];
            }
            return $items;
        }

        private function href(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        private function esc(string $text): string
        {
            if (!mb_check_encoding($text, 'UTF-8')) {
                $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
                $text = is_string($clean) ? $clean : '';
            }
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

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
