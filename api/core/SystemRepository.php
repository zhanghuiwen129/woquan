<?php

declare(strict_types=1);

namespace Hublog\Core;

/**
 * Hublog 系统设置 / 运行探针 / 运维杂域数据仓储
 *
 * 承载 MetricsController / HealthController / JobController / AdminUpgradeController
 * 下沉的只读查询，以及 Database 自身的元信息转发。
 * 所有值参数走 ? 绑定；动态表名在仓储本体做白名单校验；
 * 控制器仅保留鉴权、参数校验、缓存边界、响应包装与跨领域编排。
 * 迁移约定见 docs/内联SQL收敛规范.md。
 */
class SystemRepository
{
    /**
     * MetricsController::resolveSecret() —— 按 key 读取 settings 单值行。
     *
     * HEAD 中两条 SQL 完全相同、仅 key 不同；字段/别名/LIMIT 逐字保留，
     * 缺失时返回 null（控制器用 ?: 空串走 fail-closed 分支）。
     */
    public static function findSettingValue(string $key): ?array
    {
        return Database::fetchOne(
            "SELECT `value` FROM settings WHERE `key` = ? LIMIT 1",
            [$key]
        );
    }

    /**
     * MetricsController::render() —— 转发 Database 的请求内 SQL 统计元信息。
     *
     * 【元信息转发例外，非内联 SQL】这里转发的 getStats() 是 Database 类自身的
     * 进程内计数探针，不含任何内联 SQL；门禁 scripts/check-inline-sql.php 只扫描
     * api/controllers 目录，仓储内转发不进入调用数统计。用 FQN 明确来源。
     */
    public static function stats(): array
    {
        return \Hublog\Core\Database::getStats();
    }

    /**
     * MetricsController::render() —— 转发 Database 的跨请求慢查询统计元信息。
     *
     * 【元信息转发例外，非内联 SQL】同 stats()，slowQueryStats() 读取落盘的
     * 统计文件，不是 SQL 查询。用 FQN 明确来源。
     */
    public static function slowQueryStats(): array
    {
        return \Hublog\Core\Database::slowQueryStats();
    }

    /**
     * HealthController::checkDatabase() —— DB 连通性探针。
     *
     * 原 SQL 为常量 'SELECT 1'，无用户输入、无绑定值；逐字保留。
     */
    public static function ping(): mixed
    {
        return Database::fetchColumn('SELECT 1');
    }

    /**
     * JobController::status() —— 最近 20 条任务。
     *
     * 字段清单、字段顺序、ORDER BY `id` DESC、LIMIT 20 逐字保留。
     */
    public static function recentJobs(): array
    {
        return Database::fetchAll(
            "SELECT `id`, `type`, `status`, `attempts`, `last_error`, `created_at`, `finished_at`
             FROM `jobs` ORDER BY `id` DESC LIMIT 20"
        );
    }

    /**
     * AdminUpgradeController::versionCheck() —— 升级仓库配置读取。
     *
     * HEAD 是 key IN ('...','...','...') 白名单 + FETCH_KEY_PAIR。
     * 白名单 key 列表留在仓储本体，占位符按数量生成，值仍走参数数组；
     * 返回形态与 FETCH_KEY_PAIR 一致（key => value 关联数组），调用方逐字沿用。
     * 外层 try/catch 的「失败用默认值」降级策略仍由控制器负责。
     *
     * @return array<string,string>
     */
    public static function fetchUpgradeRepoSettings(): array
    {
        $keys = ['upgrade_repo_provider', 'upgrade_repo_owner', 'upgrade_repo_name'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = Database::query(
            "SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})",
            $keys
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }

    /**
     * AdminUpgradeController::backup() —— 动态表名全表备份。
     *
     * 【入口守卫下沉本体】表名白名单放在仓储，任何调用路径（含仓储内部自调用）
     * 都无法用非法表名拼接 SQL。白名单与控制器待备份表清单保持一致。
     *
     * @throws \InvalidArgumentException 文件名不在白名单时拒绝
     */
    public static function fetchBackupTableRows(string $table): array
    {
        $allowed = ['users', 'posts', 'comments', 'likes', 'settings', 'notifications', 'uploads'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException("非法备份表名: {$table}");
        }
        // 表名来自上方固定白名单，非请求值；SQL 文本与 HEAD 逐字一致。
        return Database::fetchAll("SELECT * FROM {$table}");
    }
}
