<?php
/**
 * Hublog 社交互动域「收藏」数据仓储
 *
 * 承载 FavoriteController 下沉的全部 SQL、参数绑定与事务边界。
 * 所有值一律走 ? 绑定（含 IN 元素、LIMIT/OFFSET）；字段清单与顺序、JOIN、
 * ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【入口守卫下沉】toggleFavorite() 内含"动态存在且 status=1"短路校验，
 * 返回 null 表示不可收藏；任何内部调用都无法绕过它直接写 favorites。
 * 【相似 SQL 不合并】findVisiblePost() / findFavoriteRow() / 各批量查询列集不同；
 * 涉及 posts 的行查询均追加无害列 p.post_type（规范 4.3）。
 */

declare(strict_types=1);

namespace Hublog\Core;

class FavoriteRepository
{
    /** toggle() —— 动态存在且对当前用户可见（status=1 + 权威可见性策略）守卫 */
    public static function findVisiblePost(int $postId, int $viewerId = 0): ?array
    {
        $row = Database::fetchOne(
            "SELECT p.id, p.user_id, p.post_type, p.visibility, p.circle_id
             FROM posts p WHERE p.id = ? AND p.status = 1 LIMIT 1",
            [$postId]
        );
        return ($row && VisibilityPolicy::canView($row, $viewerId)) ? $row : null;
    }

    /** toggle() / check() —— 同一用户+动态的收藏记录（两处共用同一 SQL） */
    public static function findFavoriteRow(int $postId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM favorites WHERE post_id = ? AND user_id = ? LIMIT 1",
            [$postId, $userId]
        );
    }

    /**
     * toggle() —— 收藏切换完整事务（存在则删、否则插入），begin/commit/rollBack 全在仓储。
     *
     * @return bool|null null = 入口守卫未通过（动态不存在/不可见或参数非法）；
     *                   true = 本次新增收藏；false = 本次取消收藏。
     */
    public static function toggleFavorite(
        int $postId,
        int $userId,
        string $now,
        ?array $existing = null
    ): ?bool {
        if ($postId <= 0 || self::findVisiblePost($postId, $userId) === null) {
            return null;
        }
        $exists = $existing ?? self::findFavoriteRow($postId, $userId);
        $favorited = false;

        Database::beginTransaction();
        try {
            if ($exists) {
                Database::delete('favorites', 'id = ?', [$exists['id']]);
            } else {
                Database::insert('favorites', [
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'created_at' => $now,
                ]);
                $favorited = true;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $favorited;
    }

    /**
     * list() —— 用户收藏且动态仍可见的 post_id 列表（LIMIT ?, ? = offset, limit）
     * 注意参数顺序：JOIN 里的可见性参数先于 WHERE 的 user_id。
     */
    public static function fetchVisibleFavoritePage(
        int $userId,
        int $offset,
        int $limit,
        string $visWhere,
        array $visParams
    ): array {
        return Database::fetchAll(
            "SELECT f.post_id, f.created_at AS favorited_at, p.post_type
             FROM favorites f
             INNER JOIN posts p ON f.post_id = p.id AND p.status = 1 AND {$visWhere}
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC
             LIMIT ?, ?",
            array_merge($visParams, [$userId, $offset, $limit])
        );
    }

    /** list() —— 与列表同一 INNER JOIN + WHERE 的计数 */
    public static function countVisibleFavorites(int $userId, string $visWhere, array $visParams): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM favorites f
             INNER JOIN posts p ON f.post_id = p.id AND p.status = 1 AND {$visWhere}
             WHERE f.user_id = ?",
            array_merge($visParams, [$userId])
        );
    }

    /**
     * loadPostsBatch() —— 收藏动态的详情（20 个 p 列 + 6 个 u 列，共 26 列）。
     * 补齐 is_anonymous/visibility/circle_id 供格式化器脱敏，并再次按可见性过滤。
     */
    public static function fetchFavoritePosts(array $postIds, string $visWhere, array $visParams): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT p.id, p.user_id, p.category_id, p.title, p.content, p.images, p.videos, p.attachments, p.is_pinned, p.pinned_at, p.created_at, p.updated_at, p.post_type,
                    p.is_anonymous, p.visibility, p.circle_id, p.location, p.like_count, p.comment_count, p.view_count,
                    u.username, u.nickname, u.avatar, u.role, u.vip_level, u.avatar_frame
             FROM posts p
             INNER JOIN users u ON p.user_id = u.id
             WHERE p.id IN ({$placeholders}) AND {$visWhere}",
            array_merge(array_values($postIds), $visParams)
        );
    }

    /** loadPostsBatch() —— 批量点赞人（4 列，按 created_at ASC） */
    public static function fetchLikeRows(array $postIds): array
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

    /** loadPostsBatch() —— 批量评论（8 列，按 created_at ASC） */
    public static function fetchCommentRows(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        return Database::fetchAll(
            "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.image, c.created_at,
                    u.username, u.nickname, u.avatar
             FROM comments c
             INNER JOIN users u ON c.user_id = u.id
             WHERE c.post_id IN ({$placeholders})
             ORDER BY c.created_at ASC",
            array_values($postIds)
        );
    }

    /** loadPostsBatch() —— 被下架的图片/附件 URL */
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
