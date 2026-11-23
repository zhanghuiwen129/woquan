<?php
/**
 * Hublog 冗余计数列对账脚本（V94）
 *
 * 校准 posts.comment_count / like_count、circles.member_count / post_count 等
 * 应用层维护、但会漂移的冗余计数。详见 api/core/CounterReconciler.php。
 *
 * 用法：
 *   php bin/reconcile-counters.php
 *
 * 退出码：0 = 执行完成（无论是否修正了数据，便于 cron 静默运行）；
 *         1 = 环境错误（找不到 config.php / 连不上库）。
 *
 * 建议 crontab（每小时）：
 *   7 * * * * php /path/to/hublog/bin/reconcile-counters.php >> /var/log/hublog-reconcile.log 2>&1
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// 1) 加载生产配置（定义 DB_* 与 APP_* 常量）
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[reconcile] 找不到 config.php（{$configFile}）。请先在线上完成安装。\n");
    exit(1);
}
require_once $configFile;

// 2) 引导自动加载与数据库
// P3 四入口初始化收敛：CLI 入口此前一个公共默认常量都没定义，
// 一旦某个被调用方用到 APP_NAME / CACHE_TTL 就会直接报「Undefined constant」。
// 现在与前台 / API 入口共用同一份 bootstrap 定义。
require_once $root . '/bootstrap.php';
\Hublog\Bootstrap\defineDefaults();
require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
require_once $root . '/api/core/Database.php';
require_once $root . '/api/core/CounterReconciler.php';

// 3) 执行对账
try {
    $report = \Hublog\Core\CounterReconciler::reconcileAll(\Hublog\Core\Database::getInstance());
} catch (\Throwable $e) {
    fwrite(STDERR, '[reconcile] 执行失败: ' . $e->getMessage() . "\n");
    exit(1);
}

// 4) 输出报告
echo '[reconcile] ' . date('c') . "\n";
echo '[reconcile] 修正总数: ' . $report['total_fixed'] . "\n";
foreach ($report['per_table'] as $t) {
    echo sprintf(
        "[reconcile]   %-28s %s（%s）\n",
        $t['target'],
        $t['fixed'] > 0 ? "修正 {$t['fixed']} 行" : '一致',
        $t['note']
    );
}
foreach ($report['skipped'] as $s) {
    echo sprintf("[reconcile]   跳过 %-28s 原因: %s\n", $s['target'], $s['reason']);
}

exit(0);
