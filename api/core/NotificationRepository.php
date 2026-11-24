<?php
/**
 * Hublog 社交互动域「消息通知」数据仓储
 *
 * 承载 NotificationController 下沉的全部 SQL、参数绑定与写操作。
 * 所有值一律走 ? 绑定（含 IN 元素、LIMIT/OFFSET）；字段清单、别名、ORDER BY、
 * GROUP BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似 SQL 不合并】
 *  - fetchCursorPage()（ORDER BY n.id DESC LIMIT ?）≠ fetchOffsetPage()
 *    （ORDER BY n.is_read ASC, n.created_at DESC LIMIT ? OFFSET ?）；
 *  - 列表 WHERE 含别名 n. 与游标 id < ?；计数 WHERE 无别名、且刻意不含游标条件
 *    —— 与 HEAD 行为一致，不能共用同一个 where 助手；
 *  - countAllUnread() 与 countUnreadNotifications() 虽然 SQL 相同，仍各自保留入口语义。
 * 涉及 posts 的摘要查询追加无害列 p.post_type（规范 4.3）。
 */

declare(strict_types=1);

namespace Hublog\Core;

class NotificationRepository
{
    private const ALLOWED_TYPES = [
        'comment', 'like', 'comment_like', 'follow', 'mention', 'system', 'message', 'category',
    ];

    /** index() —— 列表 WHERE 助手（含 n. 别名与可选游标），与两种分页列表共用 */
    private static function listWhere(int $userId, array $types, int $beforeId): array
    {
        $where = 'WHERE n.user_id = ?';
        $params = [$userId];
        // R7：过滤自己拉黑的发送者；blocked_users 未迁移时不加条件（老库降级，避免 500）
        if (FollowRepository::blockedUsersTableExists()) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM blocked_users b WHERE b.user_id = ? AND b.blocked_user_id = n.sender_id)';
            $params[] = $userId;
        }
        if ($types) {
            $where .= ' AND n.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params = array_merge($params, $types);
        }
        if ($beforeId > 0) {
            $where .= ' AND n.id < ?';
            $params[] = $beforeId;
        }
        return [$where, $params];
    }

    /** index() —— 游标分页；值全部绑定 */
    public static function fetchCursorPage(int $userId, array $types, int $beforeId, int $limit): array
    {
        self::assertTypes($types);
        [$where, $params] = self::listWhere($userId, $types, $beforeId);
        return Database::fetchAll(
            "SELECT n.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM notifications n
             LEFT JOIN users u ON n.sender_id = u.id
             {$where}
             ORDER BY n.id DESC
             LIMIT ?",
            array_merge($params, [$limit])
        );
    }

    /** index() —— 传统分页；LIMIT ? OFFSET ? 参数顺序为 limit, offset */
    public static function fetchOffsetPage(
        int $userId,
        array $types,
        int $offset,
        int $limit
    ): array {
        self::assertTypes($types);
        [$where, $params] = self::listWhere($userId, $types, 0);
        return Database::fetchAll(
            "SELECT n.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM notifications n
             LEFT JOIN users u ON n.sender_id = u.id
             {$where}
             ORDER BY n.is_read ASC, n.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** index() —— 批量动态摘要；与列表同口径：只取在架且对当前用户可见的动态 */
    public static function fetchPostSummaries(int $viewerId, array $ids): array
    {
        [$visWhere, $visParams] = VisibilityPolicy::where($viewerId);
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT p.id, p.content, p.images, p.post_type FROM posts p
             WHERE p.status = 1 AND p.id IN ({$ph}) AND {$visWhere}",
            array_merge(array_values($ids), $visParams)
        );
    }

    /**
     * R7 收口：拉黑发送者过滤片段（裸表名查询，sender 列写裸名）。
     *
     * 供 countWhere / fetchUnreadCountsByType / countAllUnread 共用，确保列表、
     * 分类未读、全量角标三处口径完全一致。blocked_users 未迁移时返回空片段降级，
     * 老库不报 500。返回值含前导 " AND "，可直接拼到 WHERE 之后。
     *
     * @return array{0:string,1:array<int,int>} [SQL 片段, 参数]
     */
    private static function blockedSenderClause(int $userId): array
    {
        if (!FollowRepository::blockedUsersTableExists()) {
            return ['', []];
        }
        return [
            ' AND NOT EXISTS (SELECT 1 FROM blocked_users b WHERE b.user_id = ? AND b.blocked_user_id = sender_id)',
            [$userId]
        ];
    }

    /** index() —— 计数 WHERE 助手；注意与列表 WHERE 不同：无 n. 别名、无游标条件 */
    private static function countWhere(int $userId, array $types): array
    {
        $where = 'WHERE user_id = ?';
        $params = [$userId];
        // R7：与列表同口径过滤拉黑发送者（本助手无 n. 别名，sender 列写裸名）
        [$blockSql, $blockParams] = self::blockedSenderClause($userId);
        $where .= $blockSql;
        $params = array_merge($params, $blockParams);
        if ($types) {
            $where .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $params = array_merge($params, $types);
        }
        return [$where, $params];
    }

    /** index() —— 分类下的总数（不受 before_id 影响，保持 HEAD 口径） */
    public static function countNotifications(int $userId, array $types): int
    {
        self::assertTypes($types);
        [$where, $params] = self::countWhere($userId, $types);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM notifications {$where}",
            $params
        );
    }

    /** index() —— 分类下未读数（同样不带游标条件） */
    public static function countUnreadNotifications(int $userId, array $types): int
    {
        self::assertTypes($types);
        [$where, $params] = self::countWhere($userId, $types);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM notifications {$where} AND is_read = 0",
            $params
        );
    }

    /** index() —— 各分类未读数（GROUP BY type，无分类过滤；R7 收口：与 countWhere 同款过滤拉黑发送者） */
    public static function fetchUnreadCountsByType(int $userId): array
    {
        [$blockSql, $blockParams] = self::blockedSenderClause($userId);
        return Database::fetchAll(
            "SELECT type, COUNT(*) AS cnt FROM notifications
             WHERE user_id = ? AND is_read = 0{$blockSql} GROUP BY type",
            array_merge([$userId], $blockParams)
        ) ?: [];
    }

    /** unreadCount() / eventsPoll() / sseStream() —— 全量未读数（10s 缓存由控制器负责；R7 收口同款过滤） */
    public static function countAllUnread(int $userId): int
    {
        [$blockSql, $blockParams] = self::blockedSenderClause($userId);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0{$blockSql}",
            array_merge([$userId], $blockParams)
        );
    }

    /** markRead() —— 只更新本人记录；id<=0 时入口守卫下沉，返回 0 行 */
    public static function markRead(int $id, int $userId): int
    {
        if ($id <= 0) {
            return 0;
        }
        return Database::update(
            'notifications',
            ['is_read' => 1],
            'id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /** markAllRead() —— 本人未读一次性置为已读 */
    public static function markAllRead(int $userId): int
    {
        return Database::update(
            'notifications',
            ['is_read' => 1],
            'user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /** delete() —— 只删本人记录 */
    public static function deleteNotification(int $id, int $userId): int
    {
        if ($id <= 0) {
            return 0;
        }
        return Database::delete('notifications', 'id = ? AND user_id = ?', [$id, $userId]);
    }

    /** clear() —— 真删当前用户全部通知 */
    public static function deleteAllNotifications(int $userId): int
    {
        return Database::delete('notifications', 'user_id = ?', [$userId]);
    }

    /** 分类白名单在仓储内二次校验，避免内部调用绕过控制器过滤 */
    private static function assertTypes(array $types): void
    {
        foreach ($types as $type) {
            if (!in_array((string)$type, self::ALLOWED_TYPES, true)) {
                throw new \InvalidArgumentException('非法通知类型');
            }
        }
    }
}
