<?php
/**
 * Hublog 社交互动域「徽章 / 神评标记」数据仓储
 *
 * 承载 BadgeController 下沉的全部 SQL、参数绑定与字段结构。
 * 所有值一律走 ? 绑定（含分页 LIMIT/OFFSET 与 LIKE 的 %）；字段清单、顺序、
 * 别名、JOIN、子查询、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - fetchCountRuleBadges()（6 列、rule_type='count'）≠ fetchActiveBadgeOptions()（5 列）；
 *  - findBadgeIdById() / findBadgeIdByKey() / findBadgeBriefById() 字段与 WHERE 均不同；
 *  - fetchAdminBadgePage() / fetchUserBadgePage() / fetchBadgeLogPage() /
 *    fetchGodMarks() 各自拥有独立字段清单，绝不因"看着像"复用；
 *  - findGodMarkForDeleteById() 与 findGodMarkForDeleteByComment() 的 WHERE 不同。
 *
 * 神评台账涉及 posts 行查询，按规范 4.3 追加无害列 p.post_type（放在原字段末尾）。
 */

declare(strict_types=1);

namespace Hublog\Core;

class BadgeRepository
{
    private const BADGE_WRITE_FIELDS = [
        'badge_key', 'name', 'desc', 'category', 'icon', 'color', 'rule_type',
        'threshold', 'sort_weight', 'hideable', 'display', 'status',
        'updated_at', 'created_at',
    ];

    private const LOG_WRITE_FIELDS = [
        'operator_id', 'operator_name', 'user_id', 'badge_id',
        'action', 'remark', 'ip', 'created_at',
    ];

    // ==================== 用户端 ====================

    /** my() —— 未解锁进度用的 count 类徽章清单（6 列，按 sort_weight DESC） */
    public static function fetchCountRuleBadges(): array
    {
        return Database::fetchAll(
            "SELECT badge_key, name, `desc`, icon, color, sort_weight, rule_type, threshold
             FROM badges WHERE status = 1 AND rule_type = 'count' ORDER BY sort_weight DESC"
        );
    }

    // ==================== 管理端：徽章配置 ====================

    /** adminList() —— 列表 WHERE 助手，与计数共用同一套条件 */
    private static function adminBadgeWhere(?string $category, int $status, string $keyword): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($category !== null && $category !== '') {
            $where[] = 'b.category = ?';
            $params[] = $category;
        }
        if ($status >= 0) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where[] = '(b.name LIKE ? OR b.badge_key LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** adminList() —— 徽章配置列表（含三种持有人数子查询，LIMIT ?, ? = offset, limit） */
    public static function fetchAdminBadgePage(
        ?string $category,
        int $status,
        string $keyword,
        int $offset,
        int $limit
    ): array {
        [$whereSql, $params] = self::adminBadgeWhere($category, $status, $keyword);
        return Database::fetchAll(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 1) AS unlock_count,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 2) AS freeze_count,
                    (SELECT COUNT(*) FROM user_badges ub WHERE ub.badge_id = b.id AND ub.state = 3) AS revoke_count
             FROM badges b
             WHERE {$whereSql}
             ORDER BY b.sort_weight DESC, b.id ASC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** adminList() —— 与列表共用 WHERE 的总数 */
    public static function countAdminBadges(?string $category, int $status, string $keyword): int
    {
        [$whereSql, $params] = self::adminBadgeWhere($category, $status, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM badges b WHERE {$whereSql}",
            $params
        );
    }

    /** adminSave() —— 编辑态存在性探测（只取 id） */
    public static function findBadgeIdById(int $id): ?array
    {
        return Database::fetchOne("SELECT id FROM badges WHERE id = ? LIMIT 1", [$id]);
    }

    /** adminSave() —— 新增态标识占用探测（只取 id） */
    public static function findBadgeIdByKey(string $badgeKey): ?array
    {
        return Database::fetchOne("SELECT id FROM badges WHERE badge_key = ? LIMIT 1", [$badgeKey]);
    }

    /** adminToggle() —— 取 id + name，供操作日志文案使用 */
    public static function findBadgeBriefById(int $id): ?array
    {
        return Database::fetchOne("SELECT id, name FROM badges WHERE id = ? LIMIT 1", [$id]);
    }

    /** adminSave() —— 新增徽章配置，返回新 ID */
    public static function insertBadgeConfig(array $data): int
    {
        self::assertWritableColumns($data, self::BADGE_WRITE_FIELDS, '非法徽章配置字段');
        return Database::insert('badges', $data);
    }

    /** adminSave() —— 编辑徽章配置；动态 SET 列来自白名单并在仓储内二次正则校验 */
    public static function updateBadgeConfig(int $id, array $data): int
    {
        self::assertWritableColumns($data, self::BADGE_WRITE_FIELDS, '非法徽章配置字段');
        return Database::update('badges', $data, 'id = ?', [$id]);
    }

    /** adminToggle() —— 仅更新 status/updated_at */
    public static function setBadgeStatus(int $id, int $status, string $now): int
    {
        return Database::update(
            'badges',
            ['status' => $status, 'updated_at' => $now],
            'id = ?',
            [$id]
        );
    }

    // ==================== 管理端：用户徽章 ====================

    /** adminUserBadges() —— 列表 WHERE 助手（JOIN users + badges） */
    private static function userBadgeWhere(
        int $userId,
        string $keyword,
        int $badgeId,
        int $state,
        string $grantType
    ): array {
        $where = ['1 = 1'];
        $params = [];
        if ($userId > 0) {
            $where[] = 'ub.user_id = ?';
            $params[] = $userId;
        }
        if ($keyword !== '') {
            $where[] = '(u.nickname LIKE ? OR u.username LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        if ($badgeId > 0) {
            $where[] = 'ub.badge_id = ?';
            $params[] = $badgeId;
        }
        if ($state > 0) {
            $where[] = 'ub.state = ?';
            $params[] = $state;
        }
        if ($grantType !== '') {
            $where[] = 'ub.grant_type = ?';
            $params[] = $grantType;
        }
        return [implode(' AND ', $where), $params];
    }

    /** adminUserBadges() —— 持有记录列表（ub.* + 5 列关联信息） */
    public static function fetchUserBadgePage(
        int $userId,
        string $keyword,
        int $badgeId,
        int $state,
        string $grantType,
        int $offset,
        int $limit
    ): array {
        [$whereSql, $params] = self::userBadgeWhere($userId, $keyword, $badgeId, $state, $grantType);
        return Database::fetchAll(
            "SELECT ub.*, u.nickname, u.username, b.name AS badge_name, b.badge_key, b.icon, b.color
             FROM user_badges ub
             JOIN users u ON u.id = ub.user_id
             JOIN badges b ON b.id = ub.badge_id
             WHERE {$whereSql}
             ORDER BY ub.updated_at DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** adminUserBadges() —— 与列表共用 WHERE 的总数 */
    public static function countUserBadges(
        int $userId,
        string $keyword,
        int $badgeId,
        int $state,
        string $grantType
    ): int {
        [$whereSql, $params] = self::userBadgeWhere($userId, $keyword, $badgeId, $state, $grantType);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*)
             FROM user_badges ub
             JOIN users u ON u.id = ub.user_id
             JOIN badges b ON b.id = ub.badge_id
             WHERE {$whereSql}",
            $params
        );
    }

    /** adminUserBadges() —— 授予弹窗下拉（仅启用中，5 列，与进度查询列集不同） */
    public static function fetchActiveBadgeOptions(): array
    {
        return Database::fetchAll(
            "SELECT id, badge_key, name, category, rule_type FROM badges WHERE status = 1 ORDER BY sort_weight DESC, id ASC"
        );
    }

    // ==================== 管理端：操作日志 ====================

    /** adminLogs() —— 日志 WHERE 助手，与计数共用 */
    private static function badgeLogWhere(
        string $action,
        int $userId,
        int $badgeId,
        string $dateFrom,
        string $dateTo
    ): array {
        $where = ['1 = 1'];
        $params = [];
        if ($action !== '') {
            $where[] = 'l.action = ?';
            $params[] = $action;
        }
        if ($userId > 0) {
            $where[] = 'l.user_id = ?';
            $params[] = $userId;
        }
        if ($badgeId > 0) {
            $where[] = 'l.badge_id = ?';
            $params[] = $badgeId;
        }
        if ($dateFrom !== '') {
            $where[] = 'l.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'l.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }

    /** adminLogs() —— 日志列表（LEFT JOIN 两个别名） */
    public static function fetchBadgeLogPage(
        string $action,
        int $userId,
        int $badgeId,
        string $dateFrom,
        string $dateTo,
        int $offset,
        int $limit
    ): array {
        [$whereSql, $params] = self::badgeLogWhere($action, $userId, $badgeId, $dateFrom, $dateTo);
        return Database::fetchAll(
            "SELECT l.*, u.nickname AS user_name, b.name AS badge_name, b.badge_key
             FROM badge_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN badges b ON b.id = l.badge_id
             WHERE {$whereSql}
             ORDER BY l.id DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** adminLogs() —— 与列表共用 WHERE 的总数（注意原 SQL 不 JOIN） */
    public static function countBadgeLogs(
        string $action,
        int $userId,
        int $badgeId,
        string $dateFrom,
        string $dateTo
    ): int {
        [$whereSql, $params] = self::badgeLogWhere($action, $userId, $badgeId, $dateFrom, $dateTo);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM badge_logs l WHERE {$whereSql}",
            $params
        );
    }

    // ==================== 神评标记 ====================

    /** adminGodMarks() —— 关键字条件与 three-way LIKE 参数 */
    private static function godMarkWhere(string $keyword): array
    {
        $where = "cm.mark_type = 'god'";
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (cu.nickname LIKE ? OR ou.nickname LIKE ? OR c.content LIKE ?)';
            $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }
        return [$where, $params];
    }

    /** adminGodMarks() —— 总数（4 表 JOIN，列表则 6 表 JOIN，不可合并） */
    public static function countGodMarks(string $keyword): int
    {
        [$where, $params] = self::godMarkWhere($keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*)
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             INNER JOIN users cu ON cu.id = c.user_id
             LEFT JOIN users ou ON ou.id = cm.operator_id
             WHERE {$where}",
            $params
        );
    }

    /** adminGodMarks() —— 神评台账列表；末尾 p.post_type 为规范 4.3 追加的无害列 */
    public static function fetchGodMarks(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::godMarkWhere($keyword);
        return Database::fetchAll(
            "SELECT cm.id, cm.comment_id, cm.mark_type, cm.operator_id, cm.created_at,
                    c.user_id AS comment_user_id, c.content, c.post_id,
                    cu.nickname AS comment_author, cu.avatar AS comment_avatar,
                    ou.nickname AS operator_name,
                    p.content AS post_content, p.post_type, pu.nickname AS post_author
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             INNER JOIN users cu ON cu.id = c.user_id
             LEFT JOIN users ou ON ou.id = cm.operator_id
             LEFT JOIN posts p ON p.id = c.post_id
             LEFT JOIN users pu ON pu.id = p.user_id
             WHERE {$where}
             ORDER BY cm.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** adminDeleteGodMark() —— 按标记 id 定位（入口二选一守卫已下沉） */
    public static function findGodMarkForDeleteById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT cm.id, cm.comment_id, c.user_id
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             WHERE cm.id = ? LIMIT 1",
            [$id]
        );
    }

    /** adminDeleteGodMark() —— 按 comment_id + mark_type 定位（与 id 分支不同 WHERE） */
    public static function findGodMarkForDeleteByComment(int $commentId): ?array
    {
        if ($commentId <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT cm.id, cm.comment_id, c.user_id
             FROM comment_marks cm
             INNER JOIN comments c ON c.id = cm.comment_id
             WHERE cm.comment_id = ? AND mark_type = ? LIMIT 1",
            [$commentId, 'god']
        );
    }

    /** adminDeleteGodMark() —— 返回真实删除行数，0 由控制器转成 500 */
    public static function deleteGodMark(int $markId): int
    {
        return Database::delete('comment_marks', 'id = ?', [$markId]);
    }

    // ==================== 配置日志 ====================

    /** writeConfigLog() —— 操作日志写入，字段清单与控制器原数组逐字一致 */
    public static function insertConfigLog(array $log): int
    {
        self::assertWritableColumns($log, self::LOG_WRITE_FIELDS, '非法徽章日志字段');
        return Database::insert('badge_logs', $log);
    }

    /**
     * 动态列白名单 + 正则双重校验：列名不进参数数组，且只能来自仓储硬编码白名单。
     */
    private static function assertWritableColumns(array $data, array $allowed, string $error): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException($error);
        }
        foreach (array_keys($data) as $column) {
            $fragment = (string)$column . ' = ?';
            if (!in_array($column, $allowed, true) || preg_match('/^[a-z_]+ = \?$/', $fragment) !== 1) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
