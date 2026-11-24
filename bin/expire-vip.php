<?php
/**
 * Hublog VIP 到期批量降级脚本（CLI 兜底，V102）
 *
 * 与 HTTP 入口 POST /api/cron/vip-expire 同一套逻辑（VipExpiryService）。
 * 二选一即可：有服务器 cron 的站点可用 HTTP 入口（带 X-Cron-Token），
 * 只有 SSH/计划任务、或共享主机不便打 HTTPS 的场景用本 CLI 脚本。
 *
 * 读时兜底 PaymentController::syncVip() 始终保留：即使这个脚本完全不跑，
 * 过期用户也拿不到权益；本脚本只是让落库值及时准确并清掉陈旧头像框缓存。
 *
 * 用法：
 *   php bin/expire-vip.php            # 默认每轮最多 200 行
 *   php bin/expire-vip.php 500        # 指定 limit（上限 1000）
 *
 * 退出码：0 = 执行完成（含 0 降级）；1 = 环境错误（找不到 config / 连不上库）。
 *
 * 建议 crontab（每天一次）：
 *   23 3 * * * php /path/to/hublog/bin/expire-vip.php >> /var/log/hublog-vip-expire.log 2>&1
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[vip-expire] 找不到 config.php（{$configFile}）。请先在线上完成安装。\n");
    exit(1);
}
require_once $configFile;

require_once $root . '/bootstrap.php';
\Hublog\Bootstrap\defineDefaults();
require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();
require_once $root . '/api/core/Database.php';
require_once $root . '/api/core/VipExpiryService.php';

$limit = isset($argv[1]) ? (int)$argv[1] : 200;

try {
    $result = \Hublog\Core\VipExpiryService::sweepExpired(\Hublog\Core\Database::getInstance(), $limit);
} catch (\Throwable $e) {
    fwrite(STDERR, '[vip-expire] 执行失败: ' . $e->getMessage() . "\n");
    exit(1);
}

echo '[vip-expire] ' . date('c') . "\n";
echo sprintf(
    "[vip-expire] 扫描=%d 降级=%d 失败=%d\n",
    $result['scanned'], $result['downgraded'], $result['failed']
);
exit(0);
