<?php
/**
 * 隔离子进程 fixture：真实触发 FATAL / 弱密钥失败关闭，观察两个入口的最终输出。
 *
 * APP_DEBUG 是常量且只能定义一次，生产与 DEBUG 分支必须分进程验证；
 * 前端入口输出 HTML 而非 JSON，因此调用方使用 runIsolatedRaw() 取原始输出。
 *
 * 场景由环境变量 HUBLOG_CASE 选择：
 *   fatal_json_prod   生产 API 入口 FATAL —— 只应有通用文案 + error_id
 *   fatal_json_debug  DEBUG API 入口 FATAL —— 允许回显消息与 file/line
 *   fatal_html_prod   生产前端入口 FATAL —— HTML 中不得出现绝对路径
 *   jwt_weak          生产 + 公开示例密钥 —— 必须 500 并终止
 *   jwt_weak_html     同上，前端 HTML 形态
 *   jwt_strong        生产 + 随机强密钥 —— 必须放行
 */

declare(strict_types=1);

$case = getenv('HUBLOG_CASE') ?: '';
$isDebug = $case === 'fatal_json_debug';

define('APP_DEBUG', $isDebug);
require_once dirname(__DIR__, 4) . '/bootstrap.php';

if (str_starts_with($case, 'fatal_')) {
    // 抑制 PHP 自身的错误回显，只留下 shutdown handler 的输出
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    \Hublog\Bootstrap\registerFatalHandler($case !== 'fatal_html_prod');
    trigger_error('SECRET_MARKER D:/woquan/internal/path.php:999', E_USER_ERROR);
    exit;
}

if (str_starts_with($case, 'jwt_')) {
    $secret = $case === 'jwt_strong'
        ? bin2hex(random_bytes(32))
        : 'your-random-jwt-secret-key';
    define('JWT_SECRET', $secret);
    \Hublog\Bootstrap\assertJwtSecret($case !== 'jwt_weak_html');

    // 只有强密钥或 DEBUG 放行时才会走到这里
    echo json_encode(['reached' => true], JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

fwrite(STDERR, "未知 HUBLOG_CASE: {$case}\n");
exit(2);
