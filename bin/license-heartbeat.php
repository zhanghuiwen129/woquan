<?php
/**
 * Hublog 授权统计心跳上报脚本（计划任务兜底）
 *
 * 作用：以 CLI 方式主动触发一次授权统计上报，把本站的 installation_id /
 * 域名 / 硬件指纹 / 用户数 / 动态数等推送到授权中心（remote_verify.php）。
 *
 * 为什么需要它：
 *   - 主路径是 api/index.php 的 shutdown 回调（maybeReport），但那里有
 *     5 分钟前台门控 + 失败退避；站点无人访问或长期处于退避窗口时不会上报。
 *   - 本脚本直接调用 reportNow()，绕过前台门控做一次确定性补发，成功会写
 *     license_heartbeat_last_at 并清 pending，使下一次自动上报按 6 小时间隔正常走。
 *   - reportNow() 内部自带 enabled 开关、SSRF 防护、2s 超时与全吞异常。
 *
 * 用法：
 *   php bin/license-heartbeat.php
 *
 * 退出码：
 *   0 = 上报成功，或本次按间隔/开关判定无需上报（便于 cron 静默运行）；
 *   1 = 环境错误（找不到 config.php / 连不上库 / 引导失败）；
 *   2 = 已尝试上报但被拒绝或网络失败（供 cron 告警识别，可选）。
 *
 * 建议 crontab（每 6 小时，与客户端默认 license_report_interval=21600 对齐）：
 *   3 0,6,12,18 * * * php /path/to/hublog/bin/license-heartbeat.php >> /var/log/hublog-heartbeat.log 2>&1
 *
 * 也可只配一次开机/安装后补发（激活时已在 AdminLicenseController 确定性上报一次，
 * 本脚本主要用于无人值守站点的周期性兜底）。
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// 1) 加载生产配置（定义 DB_* 与 APP_* 常量）
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[heartbeat] 找不到 config.php（{$configFile}）。请先在线上完成安装。\n");
    exit(1);
}
require_once $configFile;

// 2) 引导自动加载与数据库（与 reconcile-counters.php / 前台入口共用同一份默认值）
require_once $root . '/bootstrap.php';
\Hublog\Bootstrap\defineDefaults();
require_once $root . '/api/core/AutoLoader.php';
\Hublog\Core\AutoLoader::register();

// 3) 显式上报一次（reportNow 内部 promise 不抛异常，这里仍兜底防止引导期 Error）
try {
    $ok = \Hublog\Core\HeartbeatReporter::reportNow();
} catch (\Throwable $e) {
    fwrite(STDERR, '[heartbeat] 执行异常: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($ok) {
    echo '[heartbeat] ' . date('c') . " 上报成功\n";
    exit(0);
}

// 未成功可能是「开关关闭 / 端点为空 / 被服务端拒绝 / 网络失败」。
// 这里区分不了「无需上报」与「失败」，统一按 2 退出以便 cron 侧按需告警；
// 若站点不关心告警，crontab 里忽略该退出码即可（日志仍会记录）。
echo '[heartbeat] ' . date('c') . " 本次未上报（被拒/网络失败/开关关闭，详见 settings.license_heartbeat_last_error）\n";
exit(2);
