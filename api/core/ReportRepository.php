<?php
/**
 * Hublog 社交互动域「举报」数据仓储
 *
 * 承载 ReportController 下沉的全部 SQL 与参数绑定。目标表的动态表名来自
 * 控制器白名单 mapping，仓储内再加一道白名单校验；值（含 status、target_id）
 * 一律走 ? 绑定。字段清单、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【入口守卫下沉】message 目标必须 deleted_at IS NULL（私信撤回后不可举报），
 * 该分支独立成方法，任何内部调用都不能绕过；posts 行查询追加 p.post_type 无害列。
 * list() 的 paginate SQL 也一并下沉，控制器不再持有任何 SQL 字符串。
 */

declare(strict_types=1);

namespace Hublog\Core;

class ReportRepository
{
    private const TARGET_TABLES = ['comments', 'users', 'circles'];

    /** create() —— post 目标探测；fetchColumn 仍取第一列 id，行为不变 */
    public static function findPostTarget(int $targetId): mixed
    {
        return Database::fetchColumn(
            "SELECT p.id, p.post_type FROM posts p WHERE p.id = ? LIMIT 1",
            [$targetId]
        );
    }

    /** create() —— comment/user/circle 目标探测；表名来自白名单，不是用户值 */
    public static function findTargetInTable(string $table, int $targetId): mixed
    {
        if (!in_array($table, self::TARGET_TABLES, true)) {
            throw new \InvalidArgumentException('非法举报对象表');
        }
        return Database::fetchColumn(
            "SELECT id FROM {$table} WHERE id = ? LIMIT 1",
            [$targetId]
        );
    }

    /** create() —— message 目标探测：已撤回（deleted_at 非空）不可举报 */
    public static function findUndeletedMessage(int $targetId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM messages WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$targetId]
        );
    }

    /** create() —— 重复举报探测（status=0 才算重复） */
    public static function findPendingReport(int $reporterId, string $targetType, int $targetId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 0 LIMIT 1",
            [$reporterId, $targetType, $targetId]
        );
    }

    /** create() —— 写入举报记录，字段清单与顺序与 HEAD 原数组逐字一致 */
    public static function insertReport(
        int $reporterId,
        string $targetType,
        int $targetId,
        string $reason,
        ?string $description,
        string $createdAt
    ): int {
        return Database::insert('reports', [
            'reporter_id' => $reporterId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'description' => $description ?: null,
            'status' => 0,
            'created_at' => $createdAt,
        ]);
    }

    /** list() —— 后台列表 WHERE 助手，与计数共用 */
    private static function reportWhere(int $status): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($status >= 0) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        return [$where, $params];
    }

    /** list() —— 后台举报列表（BaseController::paginate 原样追加 LIMIT ?, ?） */
    public static function fetchAdminReportPage(int $status, int $offset, int $limit): array
    {
        [$where, $params] = self::reportWhere($status);
        return Database::fetchAll(
            "SELECT r.id, r.reporter_id, r.target_type, r.target_id, r.reason, r.description, r.status, r.created_at,
                    u.username AS reporter_username, u.nickname AS reporter_nickname, u.avatar AS reporter_avatar
             FROM reports r
             INNER JOIN users u ON r.reporter_id = u.id
             {$where} ORDER BY r.status ASC, r.created_at DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** list() —— 与列表同一 WHERE 的总数 */
    public static function countAdminReports(int $status): int
    {
        [$where, $params] = self::reportWhere($status);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM reports r {$where}",
            $params
        );
    }

    /** resolve() —— 管理员处理举报（status 仅 1/2，控制器已校验，仓储只做绑定） */
    public static function updateReportStatus(int $id, int $status): int
    {
        return Database::update('reports', ['status' => $status], 'id = ?', [$id]);
    }
}
