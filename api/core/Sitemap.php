<?php
/**
 * woquan 站点地图与爬虫策略
 *
 * 统一生成 sitemap.xml 与 robots.txt，供前台入口（根路径 /sitemap.xml、/robots.txt）
 * 与后台工具接口共用，避免两处实现产生不一致的 loc 格式。
 *
 * 关键约束：sitemap 的 <loc> 必须是绝对 URL（https://host/path），
 * 相对路径会被搜索引擎直接忽略，因此统一用 PathResolver::origin() 拼绝对地址。
 */

namespace Hublog\Core;

class Sitemap
{
    /** 单类目最大条数，避免单文件过大触发搜索引擎截断 */
    private const MAX_POSTS = 1000;
    private const MAX_USERS = 500;
    private const MAX_TOPICS = 500;

    /**
     * 站点地图 URL 列表
     *
     * @param array  $settings 站点设置（取 site_url）
     * @param string $base     子目录部署路径（根部署传空串）
     * @return array<int,array{loc:string,lastmod?:string,changefreq:string,priority:string}>
     */
    public static function urls(array $settings = [], string $base = ''): array
    {
        $origin = PathResolver::origin($settings);
        if ($origin === '') {
            return [];
        }
        $root = $origin . rtrim($base, '/');
        $urls = [];

        // 首页
        $urls[] = ['loc' => $root . '/', 'changefreq' => 'always', 'priority' => '1.0'];

        // 文章详情页：复用可见性唯一权威的游客口径，只收录 public 帖，
        // 防止 private/circle/friend 帖的存在性与 /p/{id} 被 sitemap 枚举。
        try {
            [$visWhere, $visParams] = VisibilityPolicy::where(0);
            $posts = Database::fetchAll(
                "SELECT p.id, p.created_at, p.updated_at FROM posts p
                 WHERE p.status = 1 AND {$visWhere}
                 ORDER BY p.created_at DESC LIMIT " . self::MAX_POSTS,
                $visParams
            );
            foreach ($posts as $post) {
                $lastmod = !empty($post['updated_at']) ? $post['updated_at'] : $post['created_at'];
                $urls[] = [
                    'loc' => $root . '/p/' . (int)$post['id'],
                    'lastmod' => date('Y-m-d', strtotime((string)$lastmod)),
                    'changefreq' => 'daily',
                    'priority' => '0.8',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Sitemap] posts 查询失败: ' . $e->getMessage()); // @allow-error-log
        }

        // 用户主页：只收录公开主页且允许被搜索的用户
        try {
            $users = Database::fetchAll(
                "SELECT username FROM users
                 WHERE status = 1 AND privacy_home = 'public' AND allow_search = 1
                 LIMIT " . self::MAX_USERS
            );
            foreach ($users as $user) {
                $urls[] = [
                    'loc' => $root . '/u/' . urlencode((string)$user['username']),
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Sitemap] users 查询失败: ' . $e->getMessage()); // @allow-error-log
        }

        // 分类页
        try {
            $categories = Database::fetchAll("SELECT id FROM categories WHERE status = 1");
            foreach ($categories as $cat) {
                $urls[] = [
                    'loc' => $root . '/c/' . (int)$cat['id'],
                    'changefreq' => 'weekly',
                    'priority' => '0.5',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Sitemap] categories 查询失败: ' . $e->getMessage()); // @allow-error-log
        }

        // 话题聚合页 /topic/{name}
        // 前台没有独立标签页：后台话题与 post_tags 标签共用这一个聚合页（/topics 接口
        // 在后台没维护话题时也回退到 post_tags），所以两处入口都要收录。
        // 链接编码与主题的 encodeURIComponent 对齐，用 rawurlencode（空格转 %20，不用 +）。
        $seenTopics = [];
        $addTopic = static function (string $name, string $lastmod = '') use (&$urls, &$seenTopics, $root): void {
            $name = trim($name);
            if ($name === '') return;
            $key = strtolower($name);
            if (isset($seenTopics[$key])) return;
            $seenTopics[$key] = true;
            $item = [
                'loc' => $root . '/topic/' . rawurlencode($name),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
            if ($lastmod !== '') {
                $item['lastmod'] = date('Y-m-d', strtotime($lastmod) ?: time());
            }
            $urls[] = $item;
        };

        try {
            $topics = Database::fetchAll(
                "SELECT name, updated_at FROM topics
                 WHERE status = 1 ORDER BY post_count DESC, id ASC LIMIT " . self::MAX_TOPICS
            );
            foreach ($topics as $topic) {
                $addTopic((string)$topic['name'], (string)($topic['updated_at'] ?? ''));
            }
        } catch (\Throwable $e) {
            Logger::warning('[Hublog Sitemap] topics 查询失败', ['err' => $e->getMessage()]);
        }

        if (count($seenTopics) < self::MAX_TOPICS) {
            try {
                // 至少 2 篇才收录，避免把只有一篇的薄页面塞进 sitemap。
                // 与详情页同一游客可见性口径：这里独立取一次片段（前面的 try 可能未定义
                // $visWhere），让 HAVING 只累计 public 帖，防止两篇 private/circle/friend
                // 帖共用标签时 /topic/{tag} 被枚举进 sitemap。
                [$tagVisWhere, $tagVisParams] = VisibilityPolicy::where(0);
                $tags = Database::fetchAll(
                    "SELECT pt.tag AS name, COUNT(*) AS post_count, MAX(p.updated_at) AS updated_at
                     FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id AND p.status = 1 AND {$tagVisWhere}
                     GROUP BY pt.tag
                     HAVING COUNT(*) >= 2
                     ORDER BY post_count DESC, pt.tag ASC LIMIT " . (self::MAX_TOPICS - count($seenTopics)),
                    $tagVisParams
                );
                foreach ($tags as $tag) {
                    $addTopic((string)$tag['name'], (string)($tag['updated_at'] ?? ''));
                }
            } catch (\Throwable $e) {
                Logger::warning('[Hublog Sitemap] post_tags 查询失败', ['err' => $e->getMessage()]);
            }
        }

        return $urls;
    }

    /**
     * 生成 sitemap.xml 文本
     */
    public static function xml(array $settings = [], string $base = ''): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach (self::urls($settings, $base) as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            if (!empty($url['lastmod'])) {
                $xml .= "    <lastmod>" . $url['lastmod'] . "</lastmod>\n";
            }
            $xml .= "    <changefreq>" . $url['changefreq'] . "</changefreq>\n";
            $xml .= "    <priority>" . $url['priority'] . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";
        return $xml;
    }

    /**
     * 生成 robots.txt 文本
     *
     * 后台可用 seo_robots_txt 自定义正文（未配置则用全站放行策略）。
     */
    public static function robots(array $settings = [], string $base = ''): string
    {
        $custom = trim((string)($settings['seo_robots_txt'] ?? ''));
        if ($custom === '') {
            $custom = "User-agent: *\nAllow: /";
        }
        $origin = PathResolver::origin($settings);
        $sitemapUrl = ($origin !== '' ? $origin . rtrim($base, '/') : '') . '/sitemap.xml';
        return $custom . "\n\nSitemap: " . $sitemapUrl . "\n";
    }
}
