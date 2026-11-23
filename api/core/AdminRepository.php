<?php
/**
 * Hublog 后台管理域数据仓储
 *
 * 承载 AdminController / AdminToolsController 下沉的全部 SQL：
 *   - 仪表盘统计、内容管理（posts 列表 / 状态统计 / 置顶）、精华推荐位、评论管理；
 *   - 导出、运营统计、话题管理。
 *
 * 【口径隔离 —— 相似 SQL 刻意不合并】
 *  - 导出四组：带 ORDER BY 的 users/posts/comments 与 all 分支的无序版本字段清单不同，
 *    其中 comments 的有序版含 is_ai、无序版不含；八条 SQL 各建独立方法，绝不互相复用。
 *  - 后台 posts 列表（含 category_name、置顶排序、可选筛选）与前台任何 posts 查询不同；
 *    后台评论列表 INNER JOIN posts + post_content 也与公开评论查询不同。
 *  - posts 的聚合查询（COUNT(*)/GROUP BY status/SUM/DATE GROUP）不做行查询，
 *    按铁律不追加 post_type；凡返回 posts 行的 SELECT 一律带 p.post_type。
 *  - 与 AdminController 的 countActiveUsers/countActivePosts/countAllComments 同 SQL 的
 *    运营统计项直接复用这三个方法（逐字一致，不存在字段清单差异）。
 *
 * 控制器保留权限、参数校验、缓存失效、错误文案与响应包装；本类不做权限判断。
 */

declare(strict_types=1);

namespace Hublog\Core;

class AdminRepository
{
    // ==================== 仪表盘统计 ====================

    /** dashboard() —— 用户总数 */
    public static function countAllUsers(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM users");
    }

    /** dashboard() —— 今日新增用户 */
    public static function countTodayUsers(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
    }

    /** dashboard() / stats() —— 正常状态用户数（两处 SQL 逐字一致） */
    public static function countActiveUsers(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1");
    }

    /** dashboard() —— 动态总数 */
    public static function countAllPosts(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts");
    }

    /** dashboard() —— 今日新增动态 */
    public static function countTodayPosts(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE DATE(created_at) = CURDATE()");
    }

    /** dashboard() / stats() —— 正常状态动态数（两处 SQL 逐字一致） */
    public static function countActivePosts(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts WHERE status = 1");
    }

    /** dashboard() / stats() —— 评论总数（两处 SQL 逐字一致） */
    public static function countAllComments(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM comments");
    }

    /** dashboard() —— 今日新增评论 */
    public static function countTodayComments(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()");
    }

    /** dashboard() —— 点赞总数 */
    public static function countAllLikes(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM likes");
    }

    /** dashboard() —— 今日新增点赞 */
    public static function countTodayLikes(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM likes WHERE DATE(created_at) = CURDATE()");
    }

    /** dashboard() —— MySQL 版本串（原 getInstance()->query(...)->fetchColumn()） */
    public static function fetchMysqlVersion(): mixed
    {
        return Database::fetchColumn("SELECT VERSION() AS v");
    }

    // ==================== 内容管理：posts ====================

    /**
     * posts() —— 后台列表 / 计数共用的 WHERE 助手。
     *
     * 状态 -1 表示不过滤；keyword 用 PHP 真值判断（原代码 `if ($keyword)`，
     * 字符串 '0' 为假，改成 !== '' 会改变行为，必须保持一致）。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function adminPostsWhere(int $status, string $keyword, int $categoryId): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status >= 0) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        if ($keyword) {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)';
            $kw = "%{$keyword}%";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        return [$where, $params];
    }

    /**
     * posts() —— 后台动态列表。
     * 字段清单/顺序、JOIN、ORDER BY、LIMIT ?, ? 与 HEAD 原文一致；
     * 末尾追加 p.post_type 仅用于遵守 posts 行查询铁律，不改动原有列。
     */
    public static function fetchAdminPosts(
        int $status,
        string $keyword,
        int $categoryId,
        int $offset,
        int $limit
    ): array {
        [$where, $params] = self::adminPostsWhere($status, $keyword, $categoryId);
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.status, p.is_pinned, p.pinned_at, p.created_at, u.username, u.nickname, u.avatar, c.name AS category_name, p.post_type
             FROM posts p INNER JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id {$where}
             ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** posts() —— 与列表共用 WHERE 的分页计数 */
    public static function countAdminPosts(int $status, string $keyword, int $categoryId): int
    {
        [$where, $params] = self::adminPostsWhere($status, $keyword, $categoryId);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts p INNER JOIN users u ON p.user_id = u.id LEFT JOIN categories c ON p.category_id = c.id {$where}",
            $params
        );
    }

    /** postStatusCounts() —— 状态页签统计原始分组行（聚合，不加 post_type） */
    public static function fetchPostStatusCountsRows(): array
    {
        return Database::fetchAll("SELECT status, COUNT(*) AS cnt FROM posts GROUP BY status");
    }

    /**
     * updatePostStatus() —— 更新动态状态。
     * 控制器入口的 id/状态校验一并下沉，任何调用方都绕不过。
     */
    public static function updatePostStatus(int $id, int $status, string $now): void
    {
        if ($id <= 0 || !in_array($status, [0, 1], true)) {
            throw new \InvalidArgumentException('参数错误');
        }
        Database::update('posts', ['status' => $status, 'updated_at' => $now], 'id = ?', [$id]);
    }

    /** pinPost() / featuredToggle() —— 后台按主键探测动态存在性（posts 行带 post_type） */
    public static function findPostIdRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne("SELECT p.id, p.post_type FROM posts p WHERE p.id = ? LIMIT 1", [$id]);
    }

    /**
     * featuredToggle() 新增推荐用：只有 status=1 且 visibility=public 的帖子才允许上精选。
     *
     * 与 findPostIdRow() 刻意分开：后者供 pinPost 判断存在性，不能因可见性变严而误伤。
     * 圈子软删/状态过滤不在本批范围（另列 P2，需与后台守卫统一口径）。
     */
    public static function findFeatureablePostRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT p.id, p.post_type, p.visibility, p.circle_id FROM posts p
WHERE p.id = ? AND p.status = 1 AND p.visibility = 'public' LIMIT 1",
            [$id]
        ) ?: null;
    }

    /** pinPost() —— 置顶状态更新（pinned=0 时 pinned_at 写 null） */
    public static function updatePostPinned(int $id, int $pinned, string $now): void
    {
        if ($id <= 0 || !in_array($pinned, [0, 1], true)) {
            throw new \InvalidArgumentException('参数错误');
        }
        Database::update('posts', [
            'is_pinned' => $pinned,
            'pinned_at' => $pinned ? $now : null,
            'updated_at' => $now,
        ], 'id = ?', [$id]);
    }

    // ==================== 全站精选推荐位 ====================

    /** featuredList() —— 推荐位列表（posts 行追加 post_type，其余逐字保留） */
    public static function fetchFeaturedList(string $slot): array
    {
        return Database::fetchAll(
            "SELECT f.id, f.post_id, f.slot, f.sort_order, f.remark, f.start_at, f.end_at,
                p.content, p.created_at, p.post_type, u.username, u.nickname, u.avatar
             FROM featured_posts f
             LEFT JOIN posts p ON p.id = f.post_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE f.slot = ?
             ORDER BY f.sort_order ASC, f.id DESC",
            [$slot]
        );
    }

    /** featuredToggle() —— 同 post_id + slot 的推荐记录 */
    public static function findFeaturedByPostAndSlot(int $postId, string $slot): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM featured_posts WHERE post_id = ? AND slot = ? LIMIT 1",
            [$postId, $slot]
        );
    }

    /** featuredToggle() —— 取消推荐 */
    public static function deleteFeaturedById(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Database::delete('featured_posts', 'id = ?', [$id]);
    }

    /** featuredToggle() —— 新增推荐记录 */
    public static function insertFeaturedRow(
        int $postId,
        int $userId,
        string $slot,
        int $sortOrder,
        ?string $remark,
        string $now
    ): int {
        if ($postId <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        return Database::insert('featured_posts', [
            'post_id' => $postId,
            'user_id' => $userId,
            'slot' => $slot,
            'sort_order' => $sortOrder,
            'remark' => $remark,
            'start_at' => $now,
            'end_at' => null,
            'created_at' => $now,
        ]);
    }

    // ==================== 内容管理：comments ====================

    /**
     * comments() —— 后台评论列表。
     * 与公开评论查询不同：INNER JOIN posts 且带 u.username/u.nickname/u.avatar/post_content。
     * posts 行追加 post_type 遵守铁律。
     */
    public static function fetchAdminComments(int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.content, c.created_at, c.post_id, u.username, u.nickname, u.avatar, p.content as post_content, p.post_type
             FROM comments c INNER JOIN users u ON c.user_id = u.id INNER JOIN posts p ON c.post_id = p.id
             ORDER BY c.created_at DESC LIMIT ?, ?",
            [$offset, $limit]
        );
    }

    /** deleteComment() —— 取待删评论的 post_id 用于缓存失效 */
    public static function findCommentPostId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne("SELECT post_id FROM comments WHERE id = ? LIMIT 1", [$id]);
    }

    /** deleteComment() —— 主评论 + 子评论同删（OR 条件逐字保留） */
    public static function deleteCommentWithChildren(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Database::delete('comments', 'id = ? OR parent_id = ?', [$id, $id]);
    }

    // ==================== 导出（六种口径互不复用） ====================

    /** exportData(users) —— 有序导出，7 列 */
    public static function fetchExportUsersOrdered(): array
    {
        return Database::fetchAll("SELECT id, username, nickname, email, role, status, created_at FROM users ORDER BY id");
    }

    /** exportData(all.users) —— 无序导出，字段与有序版相同但无 ORDER BY */
    public static function fetchExportUsersAll(): array
    {
        return Database::fetchAll("SELECT id, username, nickname, email, role, status, created_at FROM users");
    }

    /** exportData(posts) —— 有序导出，7 列 + post_type（铁律） */
    public static function fetchExportPostsOrdered(): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.title, p.content, p.status, p.view_count, p.created_at, p.post_type FROM posts p ORDER BY id"
        );
    }

    /** exportData(all.posts) —— 无序导出，无 ORDER BY */
    public static function fetchExportPostsAll(): array
    {
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.title, p.content, p.status, p.view_count, p.created_at, p.post_type FROM posts p"
        );
    }

    /** exportData(comments) —— 有序导出，含 is_ai（6 列） */
    public static function fetchExportCommentsOrdered(): array
    {
        return Database::fetchAll("SELECT id, post_id, user_id, content, is_ai, created_at FROM comments ORDER BY id");
    }

    /** exportData(all.comments) —— 无序导出，不含 is_ai（5 列） */
    public static function fetchExportCommentsAll(): array
    {
        return Database::fetchAll("SELECT id, post_id, user_id, content, created_at FROM comments");
    }

    // ==================== 运营统计 ====================

    /** stats() —— 每日发布趋势（MySQL INTERVAL 语法逐字保留） */
    public static function fetchPostsTrend(string $startDate, string $endDate): array
    {
        return Database::fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as count FROM posts WHERE created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at) ORDER BY date",
            [$startDate, $endDate]
        );
    }

    /** stats() —— 每日浏览量 */
    public static function fetchViewsTrend(string $startDate, string $endDate): array
    {
        return Database::fetchAll(
            "SELECT view_date as date, COUNT(*) as count FROM post_view_logs WHERE view_date BETWEEN ? AND ? GROUP BY view_date ORDER BY date",
            [$startDate, $endDate]
        );
    }

    /** stats() —— 30 天活跃用户数（UNION 取第一条，保持 fetchColumn 口径） */
    public static function fetchActiveUsersCount(): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(DISTINCT user_id) FROM posts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) UNION SELECT COUNT(DISTINCT user_id) FROM comments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    /** stats() —— 分类发布统计（聚合，不加 post_type） */
    public static function fetchCategoryStats(): array
    {
        return Database::fetchAll(
            "SELECT c.name, COUNT(p.id) as count FROM categories c LEFT JOIN posts p ON c.id = p.category_id AND p.status = 1 GROUP BY c.id, c.name ORDER BY count DESC"
        );
    }

    /** stats() —— 话题 Top 10 */
    public static function fetchTopicStats(): array
    {
        return Database::fetchAll("SELECT name, post_count FROM topics WHERE status = 1 ORDER BY post_count DESC LIMIT 10");
    }

    /** stats() —— 用户增长趋势 */
    public static function fetchUserGrowth(string $startDate, string $endDate): array
    {
        return Database::fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as count FROM users WHERE created_at BETWEEN ? AND ? + INTERVAL 1 DAY GROUP BY DATE(created_at) ORDER BY date",
            [$startDate, $endDate]
        );
    }

    /** stats() —— 全站累计浏览量 */
    public static function countTotalViews(): int
    {
        return (int)Database::fetchColumn("SELECT SUM(view_count) FROM posts");
    }

    /** stats() —— 30 天新增用户数 */
    public static function countNewUsers30d(): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }

    // ==================== 话题管理 ====================

    /** topics() —— 后台话题全量列表（SELECT * + 双字段排序） */
    public static function fetchAllTopics(): array
    {
        return Database::fetchAll("SELECT * FROM topics ORDER BY post_count DESC, id ASC");
    }

    /**
     * saveTopic() —— 话题字段更新。
     * 列名来自控制器硬编码 $fields；此处再以白名单 + 正则双重校验，
     * 字段名只拼进标识符位置，不进参数数组。
     */
    public static function updateTopicFields(int $id, array $fields): void
    {
        if ($id <= 0 || $fields === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        $whitelist = ['name', 'slug', 'description', 'status', 'updated_at', 'cover'];
        foreach (array_keys($fields) as $column) {
            $column = (string)$column;
            $fragment = $column . ' = ?';
            if (!in_array($column, $whitelist, true) || preg_match('/^[a-z_]+ = \?$/', $fragment) !== 1) {
                throw new \InvalidArgumentException('非法字段名');
            }
        }
        Database::update('topics', $fields, 'id = ?', [$id]);
    }

    /** saveTopic() —— 新建话题 */
    public static function insertTopic(
        string $name,
        string $slug,
        string $description,
        string $cover,
        int $status,
        string $createdAt,
        string $updatedAt
    ): int {
        return Database::insert('topics', [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'cover' => $cover,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /** setTopicCover() —— 单独更新话题头图 */
    public static function setTopicCover(int $id, string $cover, string $now): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        Database::update('topics', ['cover' => $cover, 'updated_at' => $now], 'id = ?', [$id]);
    }

    /** deleteTopic() —— 删除话题 */
    public static function deleteTopic(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('参数错误');
        }
        Database::delete('topics', 'id = ?', [$id]);
    }

    // ==================== 统计与榜单（AdminStatsController，新增段落，既有方法零改动） ====================
    //
    // siteStats() 的四条 COUNT 与既有 countActivePosts()/countAllComments()/
    // countAllLikes()/countActiveUsers() SQL 逐字一致（纯聚合、无字段清单差异），
    // 直接复用，不为"看起来属于新控制器"而复制出语义相同的重复方法。
    // 其余榜单 SQL 与既有 fetchPostsTrend()/fetchCategoryStats() 等口径不同，各建独立方法。

    /** 榜单时间窗白名单：只输出 HEAD 原文中的 INTERVAL 片段，绝不接收外部 SQL 文本 */
    private const RANKING_WINDOWS = [
        'day' => 'INTERVAL 1 DAY',
        'week' => 'INTERVAL 7 DAY',
        'month' => 'INTERVAL 1 MONTH',
    ];

    /**
     * ranking() —— 与 HEAD 的 rankingWindow() 逐字相同的时间窗片段。
     * 周期由白名单映射到固定 INTERVAL 文本，未知周期返回空串（总榜口径）。
     */
    private static function rankingWindow(string $period, string $alias = 'p'): string
    {
        if ($period === 'all' || !isset(self::RANKING_WINDOWS[$period])) {
            return '';
        }
        return " AND {$alias}.created_at >= DATE_SUB(NOW(), " . self::RANKING_WINDOWS[$period] . ")";
    }

    /**
     * userRanking() —— 综合评分活跃榜（无时间窗、含 fav_count）。
     *
     * 与 rankingUsers() 不同：此榜子查询无表别名/无时间窗且多一项收藏数，
     * 是独立历史接口，绝不合并。聚合子查询不加 post_type。
     */
    public static function fetchUserRanking(int $limit): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.vip_level, u.avatar_frame,
                        (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND status = 1) AS post_count,
                        (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1) AS comment_count,
                        (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id) AS like_count,
                        (SELECT COUNT(*) FROM favorites f INNER JOIN posts p ON f.post_id = p.id WHERE p.user_id = u.id) AS fav_count,
                        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS follower_count,
                        (
                            (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND status = 1) * 3 +
                            (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1) * 2 +
                            (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id) * 4 +
                            (SELECT COUNT(*) FROM favorites f INNER JOIN posts p ON f.post_id = p.id WHERE p.user_id = u.id) * 2 +
                            (SELECT COUNT(*) FROM follows WHERE following_id = u.id) * 3
                        ) AS score
                    FROM users u
                    WHERE u.status = 1
                    HAVING score > 0
                    ORDER BY score DESC, u.created_at ASC
                    LIMIT ?",
            [$limit]
        );
    }

    /** ranking()/user —— 活跃用户榜（窗口内动态/评论/获赞/粉丝，不含收藏数） */
    public static function fetchRankingUsers(string $period, int $limit): array
    {
        $w = self::rankingWindow($period, 'p');
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.vip_level, u.avatar_frame,
                    (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1{$w}) AS post_count,
                    (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1{$w}) AS comment_count,
                    (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id{$w}) AS like_count,
                    (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS follower_count,
                    (
                        (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.status = 1{$w}) * 3 +
                        (SELECT COUNT(*) FROM comments c INNER JOIN posts p ON c.post_id = p.id WHERE p.user_id = u.id AND c.status = 1{$w}) * 2 +
                        (SELECT COUNT(*) FROM likes l INNER JOIN posts p ON l.post_id = p.id WHERE p.user_id = u.id{$w}) * 4 +
                        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) * 3
                    ) AS score
                FROM users u
                WHERE u.status = 1
                HAVING score > 0
                ORDER BY score DESC, u.created_at ASC
                LIMIT ?",
            [$limit]
        );
    }

    /** ranking()/post —— 热帖榜（posts 行查询带 p.post_type，铁律） */
    public static function fetchRankingPosts(string $period, int $limit): array
    {
        $w = self::rankingWindow($period, 'p');
        return Database::fetchAll(
            "SELECT p.id, p.content, p.images, p.post_type, p.circle_id, p.comment_count, p.created_at,
                    u.username, u.nickname, u.avatar,
                    (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS like_count,
                    (SELECT COUNT(*) FROM comments WHERE post_id = p.id AND status = 1) AS comment_count,
                    (SELECT COUNT(*) FROM favorites WHERE post_id = p.id) AS fav_count
                FROM posts p
                INNER JOIN users u ON u.id = p.user_id
                WHERE p.status = 1{$w}
                ORDER BY (like_count * 3 + comment_count * 2 + fav_count * 5) DESC, p.created_at DESC
                LIMIT ?",
            [$limit]
        );
    }

    /** ranking()/circle —— 圈子榜（聚合子查询不加 post_type） */
    public static function fetchRankingCircles(string $period, int $limit): array
    {
        $w = self::rankingWindow($period, 'p');
        return Database::fetchAll(
            "SELECT c.id, c.name, c.slug, c.cover, c.description, c.member_count,
                    (SELECT COUNT(*) FROM posts p WHERE p.circle_id = c.id AND p.status = 1{$w}) AS post_count
                FROM circles c
                WHERE c.status = 1
                ORDER BY post_count DESC, c.member_count DESC, c.id ASC
                LIMIT ?",
            [$limit]
        );
    }

    /** ranking()/topic —— 话题榜（聚合子查询不加 post_type） */
    public static function fetchRankingTopics(string $period, int $limit): array
    {
        $w = self::rankingWindow($period, 'p');
        return Database::fetchAll(
            "SELECT t.id, t.name, t.slug, t.description,
                    (SELECT COUNT(*) FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id
                     WHERE pt.tag = t.name{$w}) AS post_count
                FROM topics t
                WHERE t.status = 1
                ORDER BY post_count DESC, t.id ASC
                LIMIT ?",
            [$limit]
        );
    }

    // ==================== 批量操作（AdminBatchController，新增段落） ====================

    /** IN (...) 的占位符只由已归一化的 ID 数量生成，值仍全部走 ? 绑定 */
    private static function inPlaceholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private static function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = (int)$id;
        }
        if ($out === []) {
            throw new \InvalidArgumentException('参数错误');
        }
        return $out;
    }

    /** batchPosts()/delete —— 批量硬删动态（HEAD 无事务，逐字保留单条 DELETE） */
    public static function deletePostsByIds(array $ids): void
    {
        $ids = self::normalizeIds($ids);
        Database::query("DELETE FROM posts WHERE id IN (" . self::inPlaceholders($ids) . ")", $ids);
    }

    /**
     * batchPosts()/hide —— 批量下架（status=0 + updated_at）。
     *
     * 必须同时清 audit_state=0：ContentAudit::onPostUpdated 的守卫是
     * status=0 && audit_state=0 && 非定时，只写 status 会让下架帖在作者编辑时
     * 触发重审并被洗成待审态，管理员的下架决定被绕过。
     */
    public static function hidePostsByIds(array $ids, string $now): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE posts SET status = ?, audit_state = 0, updated_at = ? WHERE id IN (" . self::inPlaceholders($ids) . ")",
            array_merge([0, $now], $ids)
        );
    }

    /** batchPosts()/show —— 批量上架（status=1 + updated_at），与 hide 分开建方法 */
    public static function showPostsByIds(array $ids, string $now): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE posts SET status = ?, updated_at = ? WHERE id IN (" . self::inPlaceholders($ids) . ")",
            array_merge([1, $now], $ids)
        );
    }

    /** batchPosts()/pin —— 批量置顶（is_pinned=1 + pinned_at） */
    public static function pinPostsByIds(array $ids, string $now): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE posts SET is_pinned = ?, pinned_at = ? WHERE id IN (" . self::inPlaceholders($ids) . ")",
            array_merge([1, $now], $ids)
        );
    }

    /** batchPosts()/unpin —— 批量取消置顶（pinned_at = NULL 原样保留） */
    public static function unpinPostsByIds(array $ids): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE posts SET is_pinned = ?, pinned_at = NULL WHERE id IN (" . self::inPlaceholders($ids) . ")",
            array_merge([0], $ids)
        );
    }

    /** batchComments()/delete —— 批量硬删评论 */
    public static function deleteCommentsByIds(array $ids): void
    {
        $ids = self::normalizeIds($ids);
        Database::query("DELETE FROM comments WHERE id IN (" . self::inPlaceholders($ids) . ")", $ids);
    }

    /** batchUsers()/ban —— 批量封禁（id != 1 的守卫值同样走 ? 绑定） */
    public static function banUsersByIds(array $ids): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE users SET status = ? WHERE id IN (" . self::inPlaceholders($ids) . ") AND id != ? AND role != ?",
            array_merge([0], $ids, [1, 'admin'])
        );
    }

    /** batchUsers()/unban —— 批量解封（与 ban 的 WHERE 不同，独立方法） */
    public static function unbanUsersByIds(array $ids): void
    {
        $ids = self::normalizeIds($ids);
        Database::query(
            "UPDATE users SET status = ? WHERE id IN (" . self::inPlaceholders($ids) . ")",
            array_merge([1], $ids)
        );
    }

    /**
     * batchUsers()/delete —— 单个用户软删 + 匿名化（保留行避免外键悬挂）。
     *
     * 字段清单/顺序与 HEAD 的 Database::update 数据数组逐字一致；
     * WHERE `id = ? AND role != ? AND id != ?` 保证 id=1 与管理员账号都不被删除。
     */
    public static function softDeleteUser(
        int $userId,
        string $now,
        string $deadPwd,
        string $nickname,
        int $tokenVersion
    ): void {
        if ($userId <= 0) {
            return;
        }
        Database::update('users', [
            'username'      => 'deleted_' . $userId,
            'nickname'     => $nickname,
            'email'        => '',
            'avatar'       => '/templates/moments/assets/images/default-avatar.svg',
            'cover'        => '',
            'bio'          => '',
            'password'     => $deadPwd,
            'status'       => 0,
            'deleted_at'   => $now,
            'token_version'=> $tokenVersion,
            'updated_at'   => $now,
        ], 'id = ? AND role != ? AND id != ?', [$userId, 'admin', 1]);
    }
}
