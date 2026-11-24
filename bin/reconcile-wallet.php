<?php
/**
 * Hublog 余额 / 积分流水对账脚本（V102）
 *
 * 只读对账：核对 users.balance / users.points 与 wallet_logs / points_logs 累计，
 * 输出漂移明细，绝不自动改库（资金数据自动覆盖会掩盖真实 bug）。
 * 详见 api/core/WalletReconciler.php。
 *
 * 用法：
 *   php bin/reconcile-wallet.php
 *   php bin/reconcile-wallet.php --baseline /path/to/baseline.json
 *   php bin/reconcile-wallet.php --user 123
 *
 * baseline.json 格式（期初存量，首轮对账用）：
 *   {"balance": {"123": 100.00}, "points": {"123": 500}}
 *
 * 退出码：0 = 对账完成（有漂移也为 0，便于 cron 静默运行；漂移看输出）；
 *         1 = 环境错误（找不到 config.php / baseline 文件 / 连不上库）。
 *
 * 建议 crontab（每天一次，与计数对账错开）：
 *   17 3 * * * php /path/to/hublog/bin/reconcile-wallet.php >> /var/log/hublog-wallet-reconcile.log 2>&1
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[wallet-reconcile] 找不到 config.php（{$configFile}）。请先在线上完成安装。\n");
    exit(1);
}
require_once $configFile;

require_once $root . '/bootstrap.php';
\Hublog\Bootstrap\defineDefaults();
require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
require_once $root . '/api/core/Database.php';
require_once $root . '/api/core/WalletReconciler.php';

// 解析参数
$baselineFile = '';
$onlyUser = 0;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--baseline' && isset($argv[$i + 1])) {
        $baselineFile = $argv[++$i];
    } elseif ($argv[$i] === '--user' && isset($argv[$i + 1])) {
        $onlyUser = (int)$argv[++$i];
    }
}

$options = [];
if ($onlyUser > 0) {
    $options['only_user'] = $onlyUser;
}
if ($baselineFile !== '') {
    if (!is_file($baselineFile)) {
        fwrite(STDERR, "[wallet-reconcile] 找不到 baseline 文件：{$baselineFile}\n");
        exit(1);
    }
    $decoded = json_decode((string)file_get_contents($baselineFile), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "[wallet-reconcile] baseline 不是合法 JSON：{$baselineFile}\n");
        exit(1);
    }
    $options['balance_baseline'] = array_map('floatval', $decoded['balance'] ?? []);
    $options['points_baseline'] = array_map('intval', $decoded['points'] ?? []);
}

try {
    $report = \Hublog\Core\WalletReconciler::reconcileAll(
        \Hublog\Core\Database::getInstance(),
        $options
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[wallet-reconcile] 执行失败: ' . $e->getMessage() . "\n");
    exit(1);
}

// 输出报告（只读，不改库）
echo '[wallet-reconcile] ' . date('c') . "\n";
echo '[wallet-reconcile] 检查用户数: ' . $report['checked'] . "\n";
echo '[wallet-reconcile] 漂移项总数: ' . $report['drift_count'] . "（仅报告，未修改任何数据）\n";
foreach (['balance_drift', 'points_drift'] as $key) {
    foreach ($report[$key] as $d) {
        echo sprintf(
            "[wallet-reconcile]   漂移 uid=%d %s 存储=%.2f 流水累计=%.2f 期望=%.2f 差值=%.2f\n",
            $d['user_id'], $d['field'], $d['stored'], $d['ledger'], $d['expected'], $d['drift']
        );
    }
}
foreach ($report['skipped'] as $s) {
    echo sprintf("[wallet-reconcile]   跳过 %-32s 原因: %s\n", $s['target'], $s['reason']);
}

exit(0);
