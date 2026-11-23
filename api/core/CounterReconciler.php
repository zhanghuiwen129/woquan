<?php
/**
 * Hublog 冗余计数列对账器（V94）
 *
 * 【为什么需要】
 * 项目大量使用「应用层维护的冗余计数」—— posts.comment_count / like_count、
 * circles.member_count / post_count 等。这些计数在写入路径上 +1/-1，
 * 但有几类场景会绕过业务层直接改库：
 *   - 插件（如 AiComment）直接 INSERT comments，漏记 comment_count；
 *   - 批量删除 / 软删 / 数据修复脚本；
 *   - 早期代码路径不一致（计数漂移是必然，不是偶发）。
 * 漂移后前端展示的「评论数 / 点赞数 / 成员数」和实际不符，且无法自愈。
 *
 * 【设计】
 *   - 纯 SQL + PHP 对账，DB 无关（MySQL / SQLite 都能跑，便于单测）；
 *   - 只对「真实计数 ≠ 存储计数」的行做 UPDATE，避免全表重写（减少 binlog / 锁）；
 *   - 每项对账先用 try/catch 探测目标列 / 来源表是否存在，老库缺列/缺表就跳过，不报错；
 *   - 返回结构化报告，方便 cron 输出与监控。
 *
 * 【用法】
 *   命令行：php bin/reconcile-counters.php
 *   建议频率：每小时一次（crontab），或纳入现有 cron 调度。
 */

declare(strict_types=1);

namespace Hublog\Core;

class CounterReconciler
{
    /**
     * 对账映射表。
     * targetTable/targetColumn = 冗余计数列；sourceTable/sourceKey = 真实计数的来源。
     * 规则：sourceKey 指向 targetTable 的主键（默认 id）。
     *
     * sourceWhere（可选）：来源表的过滤条件。V100 后 comments.status=0 的待审评论
     * 不再算进公开评论数，对账必须同口径，否则每小时一次的对账会把待审评论刷回计数。
     * 老库没有 status 列时自动降级为不过滤，保证对账不会因缺列整体跳过。
     *
     * @var array<int,array{targetTable:string,targetColumn:string,sourceTable:string,sourceKey:string,note:string,sourceWhere?:string}>
     */
    private const MAPPINGS = [
        [
            'targetTable'  => 'posts',
            'targetColumn' => 'comment_count',
            'sourceTable'  => 'comments',
            'sourceKey'    => 'post_id',
            'sourceWhere'  => 'status = 1',
            'note'         => '评论数（AI 评论/批量删除易漏记；待审评论不计入）',
        ],
        [
            'targetTable'  => 'posts',
            'targetColumn' => 'like_count',
            'sourceTable'  => 'likes',
            'sourceKey'    => 'post_id',
            'note'         => '点赞数',
        ],
        [
            'targetTable'  => 'circles',
            'targetColumn' => 'member_count',
            'sourceTable'  => 'circle_members',
            'sourceKey'    => 'circle_id',
            'note'         => '圈子成员数',
        ],
        [
            'targetTable'  => 'circles',
            'targetColumn' => 'post_count',
            'sourceTable'  => 'posts',
            'sourceKey'    => 'circle_id',
            'note'         => '圈子动态数（仅统计已加入圈子的动态）',
        ],
    ];

    /**
     * 对全部映射做对账。
     *
     * @param \PDO $pdo 数据库连接
     * @return array{total_fixed:int,per_table:array,skipped:array}
     */
    public static function reconcileAll(\PDO $pdo): array
    {
        $totalFixed = 0;
        $perTable = [];
        $skipped = [];

        foreach (self::MAPPINGS as $m) {
            try {
                $fixed = self::reconcileOne($pdo, $m);
                $perTable[] = [
                    'target' => "{$m['targetTable']}.{$m['targetColumn']}",
                    'note'   => $m['note'],
                    'fixed'  => $fixed,
                ];
                $totalFixed += $fixed;
            } catch (\Throwable $e) {
                // 老库缺列/缺表：跳过，不阻断其余对账
                $skipped[] = [
                    'target' => "{$m['targetTable']}.{$m['targetColumn']}",
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_fixed' => $totalFixed,
            'per_table'   => $perTable,
            'skipped'     => $skipped,
        ];
    }

    /**
     * 对单条映射对账：找出存储计数 ≠ 真实计数的行，UPDATE 回真实值。
     *
     * 用 LEFT JOIN + COALESCE 同时覆盖「有来源记录」和「来源为 0」两种情况，
     * 且只在 t.col <> 真实值 时才 UPDATE，避免对一致行写空操作。
     *
     * @return int 修正的行数
     */
    private static function reconcileOne(\PDO $pdo, array $m): int
    {
        $t = $m['targetTable'];
        $c = $m['targetColumn'];
        $s = $m['sourceTable'];
        $k = $m['sourceKey'];

        // 先探测目标列 / 来源表是否真实存在（DB 无关地用 try/catch 兜底）
        self::probeColumn($pdo, $t, $c);
        self::probeTable($pdo, $s);

        // 来源过滤列存在才加 WHERE：未升级到 V100 的老库没有 comments.status，
        // 此时保持旧口径，避免整个映射被 skipped。
        $sourceWhere = '';
        if (!empty($m['sourceWhere'])) {
            $filterColumn = strtok($m['sourceWhere'], ' ');
            try {
                self::probeColumn($pdo, $s, (string)$filterColumn);
                $sourceWhere = ' WHERE ' . $m['sourceWhere'];
            } catch (\Throwable $e) {
                $sourceWhere = '';
            }
        }

        $findSql = "SELECT t.id AS id, COALESCE(s.cnt, 0) AS real_count, t.{$c} AS stored
                    FROM `{$t}` t
                    LEFT JOIN (SELECT `{$k}`, COUNT(*) AS cnt FROM `{$s}`{$sourceWhere} GROUP BY `{$k}`) s
                           ON s.`{$k}` = t.id
                    WHERE t.{$c} <> COALESCE(s.cnt, 0)";

        $rows = $pdo->query($findSql)->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return 0;
        }

        $updateSql = "UPDATE `{$t}` SET `{$c}` = ? WHERE id = ?";
        $stmt = $pdo->prepare($updateSql);
        $fixed = 0;
        foreach ($rows as $row) {
            $stmt->execute([(int)$row['real_count'], (int)$row['id']]);
            $fixed += $stmt->rowCount();
        }
        return $fixed;
    }

    /**
     * 探测表是否存在：不存在抛异常，由调用方 catch 后跳过该项。
     */
    private static function probeTable(\PDO $pdo, string $table): void
    {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
    }

    /**
     * 探测列是否存在：不存在抛异常，由调用方 catch 后跳过该项。
     * 用 `SELECT col FROM table LIMIT 0` 触发「unknown column」错误。
     */
    private static function probeColumn(\PDO $pdo, string $table, string $column): void
    {
        $pdo->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
    }
}
