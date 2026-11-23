<?php
/**
 * woquan 幂等唯一键核验脚本（P0-1）
 *
 * 只读检查契约登记的幂等唯一键是否真实存在于数据库中，用于核实「迁移报告成功、
 * 但幂等键可能没落地」的风险（migrate92 等迁移的 DDL 在 catch 中被静默吞掉，
 * 站点显示已是最终态，唯一键却可能根本没建成 —— 重复支付回调可重复入账）。
 *
 * 本脚本：
 *   - 只读，不修改任何数据或结构；
 *   - 不改迁移行为、不抛异常阻断、不写任何标记（对存量站点零破坏）；
 *   - 缺失的键、以及「索引存在但列与契约不符」都会打印告警 + 去重/补键的参考 SQL，
 *     由运维人工确认后执行（只验索引名会让被改错列的索引蒙混过关）。
 *
 * 清单来自 api/core/MigrationContract.php 的 REQUIRED_UNIQUE_INDEXES ——
 * 与运行时契约自检共用同一份真源，这里不再另抄一份（避免两边漂移）。
 *
 * 用法：
 *   php scripts/check-idempotency.php
 *
 * 退出码：
 *   0 = 全部幂等键存在
 *   2 = 存在缺失的幂等键（供监控/cron 判级）
 *   1 = 环境错误（找不到 config.php / 连不上库）
 *
 * 建议：升级后、以及每次涉及支付/商城的发布后手动跑一次；或纳入巡检 cron
 *   （退出码 2 表示需要人工介入）。
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// 1) 加载生产配置（定义 DB_* 与 APP_* 常量）
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[idem] 找不到 config.php（{$configFile}）。请先在线上完成安装。\n");
    exit(1);
}
require_once $configFile;

// 2) 引导自动加载与数据库
require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
require_once $root . '/api/core/Database.php';

/**
 * 补键前的去重提示 SQL（按「表.索引」索引）。
 * 只写「怎么查重复、怎么保最小 id」的参考语句，不自动执行。
 * 键的表名 / 索引名 / 列清单不在这里 —— 那部分由契约提供，避免双份维护。
 */
$dedupeHints = [
    'post_tags.uk_post_tag' =>
        "DELETE t FROM post_tags t\n"
        . "  JOIN post_tags d ON d.post_id = t.post_id AND d.tag = t.tag AND d.id < t.id\n"
        . "  WHERE 1=1;  -- 同 (post_id,tag) 只保最小 id，先备份再执行",
    'wallet_logs.uk_wallet_biz_key' =>
        "DELETE w FROM wallet_logs w\n"
        . "  JOIN wallet_logs d ON d.biz_key = w.biz_key AND d.id < w.id\n"
        . "  WHERE w.biz_key IS NOT NULL;  -- biz_key 重复只保最小 id，先核对流水",
    'recharge_orders.uk_trade_no' =>
        "-- 先找出重复 trade_no 并人工核对，不要盲目删单：\n"
        . "SELECT trade_no, COUNT(*) c FROM recharge_orders\n"
        . "  WHERE trade_no IS NOT NULL GROUP BY trade_no HAVING c > 1;",
    'recharge_orders.uk_order_no' =>
        "-- 本站订单号必须唯一，出现重复说明订单号生成逻辑曾撞号，需人工核对：\n"
        . "SELECT order_no, COUNT(*) c FROM recharge_orders\n"
        . "  GROUP BY order_no HAVING c > 1;",
    'user_coupons.uk_coupon_order_slot' =>
        "-- slot 由回填保证唯一，缺失说明 V92 回填可能被吞：\n"
        . "SELECT order_id, slot, COUNT(*) c FROM user_coupons\n"
        . "  GROUP BY order_id, slot HAVING c > 1;",
    'shop_orders.uk_order_no' =>
        "-- 商城订单号重复需人工核对（可能已被重复下单）：\n"
        . "SELECT order_no, COUNT(*) c FROM shop_orders\n"
        . "  GROUP BY order_no HAVING c > 1;",
    'vip_subscriptions.uk_order_no' =>
        "-- 会员订单号重复需人工核对（可能已被重复开通）：\n"
        . "SELECT order_no, COUNT(*) c FROM vip_subscriptions\n"
        . "  GROUP BY order_no HAVING c > 1;",
    'jobs.uk_job_dedupe' =>
        "-- 任务去重键重复说明同一任务被重复排队：\n"
        . "SELECT dedupe_key, COUNT(*) c FROM jobs\n"
        . "  WHERE dedupe_key IS NOT NULL GROUP BY dedupe_key HAVING c > 1;",
    'conversations.uk_dm_key' =>
        "-- 私信去重键重复说明同一会话被重复创建：\n"
        . "SELECT dm_key, COUNT(*) c FROM conversations\n"
        . "  WHERE dm_key IS NOT NULL GROUP BY dm_key HAVING c > 1;",
    'feed_feedback.uk_user_action_target' =>
        "-- 负反馈重复会让同一目标被反复隐藏，保留最小 id：\n"
        . "DELETE f FROM feed_feedback f\n"
        . "  JOIN feed_feedback d ON d.user_id = f.user_id AND d.action = f.action\n"
        . "    AND d.target_type = f.target_type AND d.target_value = f.target_value\n"
        . "    AND d.id < f.id;",
    'comment_marks.uk_comment_mark' =>
        "-- 评论标记重复，保留最小 id：\n"
        . "DELETE m FROM comment_marks m\n"
        . "  JOIN comment_marks d ON d.comment_id = m.comment_id\n"
        . "    AND d.mark_type = m.mark_type AND d.id < m.id;",
];

$expected = \Hublog\Core\MigrationContract::REQUIRED_UNIQUE_INDEXES;

echo '[idem] ' . date('c') . " 幂等键核验开始（契约登记 " . count($expected) . " 项）\n";

try {
    $db = \Hublog\Core\Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, '[idem] 数据库连接失败: ' . $e->getMessage() . "\n");
    exit(1);
}

$missing = 0;
foreach ($expected as $item) {
    $table = $item['table'];
    $index = $item['index'];
    $key = $table . '.' . $index;

    // 只读查 information_schema：按 SEQ_IN_INDEX（索引内列序）还原索引实际列清单，
    // 与契约 cols 逐列比对（大小写不敏感）。只查 INDEX_NAME 存在性不够 ——
    // 索引在但列被改错时，重复支付/发券仍可能入账，必须一并判为缺失。
    // 注意：STATISTICS 没有 ORDINAL_POSITION 列（那是 COLUMNS 表的字段），
    // 排序只能用 SEQ_IN_INDEX，否则 MySQL 报 1054。
    $sql = "SELECT COLUMN_NAME, SEQ_IN_INDEX
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
              AND NON_UNIQUE = 0
            ORDER BY SEQ_IN_INDEX ASC";
    $indexRows = \Hublog\Core\Database::fetchAll($sql, [$table, $index]);
    $actualCols = [];
    foreach ($indexRows as $row) {
        $actualCols[] = strtolower((string)($row['COLUMN_NAME'] ?? ''));
    }
    $expectedCols = array_map(static fn($c): string => strtolower((string)$c), $item['cols']);

    if ($actualCols !== [] && $actualCols === $expectedCols) {
        echo sprintf("[idem]   [OK]      %s\n", $key);
        continue;
    }

    $missing++;
    $cols = implode(', ', array_map(static fn($c) => "`{$c}`", $item['cols']));
    if ($actualCols === []) {
        echo sprintf("[idem]   [MISSING] %s  (%s)\n", $key, $item['why']);
    } else {
        echo sprintf(
            "[idem]   [MISMATCH] %s 索引存在但列不符（契约: %s / 实际: %s）\n",
            $key,
            implode(', ', $expectedCols),
            implode(', ', $actualCols)
        );
    }
    echo "[idem]             补键参考（务必先在从库/备份验证并人工确认）：\n";
    if (isset($dedupeHints[$key])) {
        foreach (explode("\n", $dedupeHints[$key]) as $line) {
            echo "[idem]               " . $line . "\n";
        }
    }
    echo "[idem]               ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$cols});\n";
}

echo "[idem] 完成：共 " . count($expected) . " 项，缺失 {$missing} 项\n";

if ($missing > 0) {
    echo "[idem] ⚠ 存在缺失的幂等键，请人工核对后补键。本脚本不会自动修改数据库。\n";
    exit(2);
}

exit(0);
