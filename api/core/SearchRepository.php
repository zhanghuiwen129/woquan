<?php
declare(strict_types=1);

namespace Hublog\Core;

/**
 * 内容搜索域数据仓储。
 *
 * 承载 SearchController 下沉的全部 SQL：全文/降级 LIKE 的 ID 检索与计数、
 * 全文索引可用性探针、批量加载动态/点赞/评论/被屏蔽图片。
 * 所有值一律走 ? 绑定（包括 LIMIT/OFFSET 与 IN 占位符）；
 * 字段清单、顺序、JOIN、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - searchWhere() 同时服务首次检索与 FULLTEXT 异常后的 LIKE 降级，二者 WHERE 结构一致，
 *    仅关键词条件不同（MATCH vs LIKE），由 $useFulltext 显式区分且参数顺序不变；
 *  - fetchPostIds（只取 id + 铁律追加的 post_type）≠ fetchPostsByIds（18 列 + JOIN users），
 *    后者同样在末尾追加 p.post_type；
 *  - COUNT(*) 聚合保持 COUNT(*)，不会因为 post_type 铁律改写成 COUNT(p.post_type)。
 *
 * 控制器保留关键词归一化、索引可用性缓存、异常日志与响应包装。
 */
class SearchRepository
{
    /**
     * 搜索 WHERE：列表 ID 检索与 COUNT(*) 计数共用，保证翻页总数同口径。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function searchWhere(
        string $q,
        int $categoryId,
        string $dateRange,
        string $mediaType,
        int $beforeId,
        bool $useFulltext,
        string $visWhere,
        array $visParams,
        bool $forCount = false
    ): array {
        // 可见性片段必须排在第一位，后续所有追加参数顺序依赖这一点。
        $conditions = ['p.status = 1', $visWhere];
        $params = $visParams;

        // 保留原文 !empty($q) 语义：字符串 '0' 在原文中同样不生成关键词条件
        if (!empty($q)) {
            if ($useFulltext) {
                // BOOLEAN MODE 下 +/~/* 等是运算符；原文用引号包成短语并清掉引号与反斜杠
                $ftQuery = '"' . str_replace(['"', '\\'], [' ', ' '], $q) . '"';
                $conditions[] = '(MATCH(p.title, p.content) AGAINST(? IN BOOLEAN MODE))';
                $params[] = $ftQuery;
            } else {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $conditions[] = '(p.title LIKE ? OR p.content LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        if ($categoryId > 0) {
            $conditions[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($dateRange === '7d') {
            $conditions[] = 'p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        } elseif ($dateRange === '30d') {
            $conditions[] = 'p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($mediaType === 'image') {
            $conditions[] = "p.images IS NOT NULL AND p.images != '[]' AND p.images != ''";
        } elseif ($mediaType === 'video') {
            $conditions[] = "p.videos IS NOT NULL AND p.videos != '[]' AND p.videos != ''";
        } elseif ($mediaType === 'text') {
            $conditions[] = "(p.images IS NULL OR p.images = '[]' OR p.images = '') AND (p.videos IS NULL OR p.videos = '[]' OR p.videos = '')";
        }
        // COUNT 与分页总数同口径，不能把游标 id 条件带进来。
        if ($beforeId > 0 && !$forCount) {
            $conditions[] = 'p.id < ?';
            $params[] = $beforeId;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * search() —— 按条件检索动态 ID。
     *
     * before_id > 0 时原文用 LIMIT ?（游标翻页），否则 LIMIT ?, ?（offset, limit）。
     * p.post_type 为遵守 posts 行查询铁律追加的无害末列，调用方只取 id。
     */
    public static function fetchPostIds(
        string $q,
        int $categoryId,
        string $dateRange,
        string $mediaType,
        int $beforeId,
        int $offset,
        int $limit,
        bool $useFulltext,
        string $visWhere,
        array $visParams
    ): array {
        [$where, $params] = self::searchWhere(
            $q, $categoryId, $dateRange, $mediaType, $beforeId, $useFulltext, $visWhere, $visParams
        );
        $limitSql = $beforeId > 0 ? 'LIMIT ?' : 'LIMIT ?, ?';
        $limitParams = $beforeId > 0 ? [$limit] : [$offset, $limit];
        return Database::fetchAll(
            "SELECT p.id, p.post_type
             FROM posts p
             WHERE {$where}
             ORDER BY p.created_at DESC
             {$limitSql}",
            array_merge($params, $limitParams)
        );
    }

    /** search() —— 与 ID 检索同一 WHERE 的总数（COUNT(*) 保持不变） */
    public static function countPosts(
        string $q,
        int $categoryId,
        string $dateRange,
        string $mediaType,
        int $beforeId,
        bool $useFulltext,
        string $visWhere,
        array $visParams
    ): int {
        [$where, $params] = self::searchWhere(
            $q, $categoryId, $dateRange, $mediaType, $beforeId, $useFulltext, $visWhere, $visParams, true
        );
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
    }

    /** fulltextIndexAvailable() —— 全文索引是否存在（固定字面量也走 ? 绑定） */
    public static function countFulltextIndexes(): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            ['posts', 'idx_posts_fulltext']
        );
    }

    /** loadPostsBatch() —— 按搜索结果 ID 批量取动态详情（IN 占位符按 ID 数量生成） */
    public static function fetchPostsByIds(array $postIds, string $visWhere, array $visParams): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.is_anonymous, p.location, p.visibility, p.circle_id, p.created_at, p.updated_at,
                    u.username, u.nickname, u.avatar, u.role, p.post_type
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id IN ({$placeholders}) AND {$visWhere}
             ORDER BY p.created_at DESC",
            array_merge(array_values($postIds), $visParams)
        );
    }

    /** loadPostsBatch() —— 批量取点赞人（按 l.created_at ASC） */
    public static function fetchLikesByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT l.post_id, u.id, u.username, u.nickname
             FROM likes l
             INNER JOIN users u ON l.user_id = u.id
             WHERE l.post_id IN ({$placeholders})
             ORDER BY l.created_at ASC",
            array_values($postIds)
        );
    }

    /** loadPostsBatch() —— 批量取评论（含 c.is_ai，与 PostRepository.fetchByIds 一致） */
    public static function fetchCommentsByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.is_ai, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$placeholders})
             ORDER BY c.created_at ASC",
            array_values($postIds)
        );
    }

    /** loadPostsBatch() —— 批量取已下架图片/附件 URL（status = 0） */
    public static function fetchBlockedUploadUrls(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT url FROM uploads WHERE post_id IN ({$placeholders}) AND status = 0",
            array_values($postIds)
        );
    }
}
