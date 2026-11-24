<?php

declare(strict_types=1);

/**
 * 只增日志表滚动归档
 *
 * 背景：wallet_logs / points_logs 等只增表长期只写不删，随运行时间线性膨胀，
 *       备份与迁移的耗时随之增长。需要一条可控的定期清理通道。
 *
 * 安全约束（写死在白名单里，绝不接收调用方传入的表名/列名）：
 *   - 只收纯只增、无业务状态依赖的日志表；
 *   - 排除 notifications（有 is_read 状态）、badge_logs（徽章解锁判定依赖历史）、
 *     user_task_logs / checkin_logs（任务与打卡进度）、call_records（业务查询）、
 *     jobs（已有 JobQueue::purge()）；
 *   - 每批只删有限行数，多次调用逐步收敛，避免长事务。
 *
 * 删除采用「先 SELECT id LIMIT，再 DELETE WHERE id IN (...)」两段式，
 * 兼容行为测试用的 SQLite（不支持 DELETE ... LIMIT），也便于先确认命中范围。
 */

namespace Hublog\Core;

class TableArchive
{
    /**
     * 可归档表 → 用于判定新旧的时间列。
     * 表名/列名全部来自本常量，不参与任何外部输入拼接。
     */
    public const TABLES = [
        'wallet_logs'        => 'created_at',
        'points_logs'        => 'created_at',
        'admin_audit_logs'   => 'created_at',
        'member_change_logs' => 'created_at',
        'content_filter_logs'=> 'created_at',
        'app_crash_logs'     => 'occurred_at',
        'post_view_logs'     => 'created_at',
        'wheel_records'      => 'created_at',
        'ai_usage_logs'      => 'created_at',
    ];

    /** 单表单次最多删除行数（防止一次 cron 形成长事务） */
    private const MAX_BATCH = 1000;

    /**
     * 执行一轮归档。
     *
     * @param int $keepDays   保留最近 N 天，最小 1 天（ai_usage_logs 的当日配额依赖近 1 天数据）
     * @param int $batchLimit 每张表本轮最多删除行数，内部夹逼到 [1, MAX_BATCH]
     * @return array{keep_days:int, deleted:array<string,int>, errors:array<string,string>}
     */
    public static function run(int $keepDays = 30, int $batchLimit = 500): array
    {
        $keepDays = max(1, min(3650, $keepDays));
        $batchLimit = max(1, min(self::MAX_BATCH, $batchLimit));
        // 阈值在 PHP 侧算，避免依赖数据库时区；与 JobQueue::purge() 口径一致
        $before = date('Y-m-d H:i:s', time() - $keepDays * 86400);

        $deleted = [];
        $errors = [];
        foreach (self::TABLES as $table => $timeCol) {
            // 表名与列名都来自白名单常量，此处断言用于防止后续维护时误写成外部输入
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string)$table)
                || !preg_match('/^[a-z][a-z0-9_]*$/', (string)$timeCol)) {
                continue;
            }
            try {
                $deleted[$table] = self::purgeTable((string)$table, (string)$timeCol, $before, $batchLimit);
            } catch (\Throwable $e) {
                // 单表失败不影响其他表；错误原文返回给调用方，便于运维定位
                $errors[$table] = $e->getMessage();
                error_log('[Hublog TableArchive] ' . $table . ' 归档失败: ' . $e->getMessage()); // @allow-error-log
            }
        }

        return [
            'keep_days' => $keepDays,
            'deleted'   => $deleted,
            'errors'    => $errors,
        ];
    }

    /**
     * 分批删除单张表的过期行。
     *
     * @return int 本轮实际删除行数
     */
    private static function purgeTable(string $table, string $timeCol, string $before, int $limit): int
    {
        $rows = Database::fetchAll(
            "SELECT `id` FROM `{$table}` WHERE `{$timeCol}` < ? ORDER BY `id` ASC LIMIT " . $limit,
            [$before]
        );
        if ($rows === []) {
            return 0;
        }

        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::query("DELETE FROM `{$table}` WHERE `id` IN ({$placeholders})", $ids);
        return count($ids);
    }
}
