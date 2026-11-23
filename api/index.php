<?php
/**
 * Hublog API 统一入口
 * 所有API请求通过此文件路由分发
 */

declare(strict_types=1);

// 全局错误捕获：config.php 会关闭错误显示，此处确保 fatal error 可见以便排查
// P0-LOG：把 PHP error_log 也指向项目 logs/ 目录（默认在 Windows 下走 Windows Event Log，
//        本地/线上排查都看不见）。必须早于任何可能产生错误的 require。
ini_set('error_log', dirname(__DIR__) . '/logs/php-error.log');
ini_set('log_errors', '1');
ini_set('log_errors_max_len', '0');

// S6：FATAL 兜底收敛到共享 bootstrap，与根 index.php 使用同一份实现，
// 避免「生产错误脱敏」只落地在 API 入口。
require_once __DIR__ . '/../bootstrap.php';
\Hublog\Bootstrap\registerFatalHandler(true);

require_once __DIR__ . '/../config.php';

// P3 四入口初始化收敛：公共默认常量统一由 bootstrap 定义（config.php 优先）。
// 原来这里与前台 index.php 各自抄了一份，改默认值必然漏改一处。
\Hublog\Bootstrap\defineDefaults();

// S7：弱密钥校验收敛到共享 bootstrap。原实现只拦 '' 与 'change-me-in-production'，
// 漏了 config.example.php 的公开示例值 'your-random-jwt-secret-key'，也没有长度下限；
// 根 index.php 更是完全没校验。现在两个入口共用同一份黑名单 + 失败关闭。
\Hublog\Bootstrap\assertJwtSecret(true);

// 以下两个是 API 入口专有（CORS / 媒体域名），不并入公共默认值。
defined('CORS_ALLOWED_ORIGINS') || define('CORS_ALLOWED_ORIGINS', '*');
defined('MEDIA_BASE_URL') || define('MEDIA_BASE_URL', '');

// 未安装时返回友好提示（S8：锁文件在站点根目录，兼容老部署的 cache/install.lock）
if (!\Hublog\Bootstrap\isInstalled(__DIR__ . '/..')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['code' => 503, 'message' => '系统尚未安装，请先访问安装页面', 'data' => null]);
    exit;
}

require_once __DIR__ . '/core/AutoLoader.php';

use Hublog\Core\Router;
use Hublog\Core\Response;
use Hublog\Core\Migration;
use Hublog\Core\AutoLoader;
use Hublog\Core\Plugin;
use Hublog\Core\Hook;
use Hublog\Core\Logger;
use Hublog\Core\Exception\HublogException;

// 注册自动加载
AutoLoader::register();

// 授权统计上报钩子（P0-04/P0-09/G3）：注册 shutdown 回调，等响应完整输出后再执行。
// - PHP-FPM：先 fastcgi_finish_request() 把响应发给客户端，再做最多 2s 的上报，页面零等待；
// - 非 FPM：shutdown 回调天然在响应后执行，直接上报。
// 回调最外层吞掉所有 Throwable，绝不向响应注入任何内容。
register_shutdown_function(static function (): void {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    try {
        \Hublog\Core\HeartbeatReporter::maybeReport();
    } catch (\Throwable $e) {
        // 上报失败静默：仅 HeartbeatReporter 内部写 debug 日志
    }
});

// P2-16：记录 API 访问日志（access-YYYY-MM-DD.log + 14 天轮转）
// - startTime_ms：请求开始精确时间（在 PHP 8.2 下 microtime(true) 足够）
// - 注册 shutdown 函数：无论 exit / HublogException / Throwable / 正常结束，都会走一次 access 日志
$GLOBALS['hublog_access_start'] = microtime(true);
$GLOBALS['hublog_access_status'] = 200;
$GLOBALS['hublog_access_user_id'] = null;
// 安全地取得客户端 IP（不要信 X-Forwarded-For 未过滤，优先第一个可信的 REMOTE_ADDR / CDN 代理头）
if (!function_exists('hublog_real_client_ip')) {
    function hublog_real_client_ip(): ?string
    {
        $ip = null;
        // REMOTE_ADDR 永远是真实的（除非在反向代理后面，但这里保守用它）
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // 如果部署在受信任反向代理后面，CF-Connecting-IP / True-Client-IP 优先级更高
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP'] as $k) {
            if (!empty($_SERVER[$k])) {
                $candidate = trim(explode(',', (string)$_SERVER[$k])[0]);
                if ($candidate !== '') {
                    $ip = $candidate;
                    break;
                }
            }
        }
        if (is_string($ip)) {
            $ip = trim($ip);
            // 脱敏：仅保留 IPv4/IPv6 合法格式（过滤掉带 \r\n 注入头）
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return $ip;
        }
        return null;
    }
}
register_shutdown_function(function (): void {
    if (!isset($GLOBALS['hublog_access_start'])) return;
    $start = (float)$GLOBALS['hublog_access_start'];
    $durationMs = (microtime(true) - $start) * 1000;
    $status = (int)($GLOBALS['hublog_access_status'] ?? http_response_code() ?: 200);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    $uid    = $GLOBALS['hublog_access_user_id'] ?? null;
    $ip     = hublog_real_client_ip();
    $ref    = $_SERVER['HTTP_REFERER'] ?? null;
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? null;
    // 对 UA/Referer 字段保留长度限制（避免误写巨量日志）
    if ($ua !== null && strlen($ua) > 512) $ua = substr($ua, 0, 512);
    if ($ref !== null && strlen($ref) > 512) $ref = substr($ref, 0, 512);
    // P0-LOG：把 int|string|null 统一转成 ?string，匹配 Logger::access() 的签名 (它要求 ?string)。
    // 否则已登录请求会触发 TypeError → 整个请求 500，且 access 日志因 Logger 自己抛错而丢失。
    $uidStr = $uid === null ? null : (string)(int)$uid;
    Logger::access($method, $uri, $status, $durationMs, $uidStr, $ip, $ref, $ua);
});

// 执行数据库迁移（无感升级）
Migration::run();

// P1-3：维护任务（过期上传清理 + 定时发布）移出请求热路径。
// 这里只做一次廉价的到期判断（读本地状态文件，不查库）；到期后把重活登记成延迟任务，
// 响应先发出去、重活在 fastcgi_finish_request() 之后才跑 —— 用户请求的延迟不再随机变慢。
// 定时发布原本是「每次有人访问就顺手检查」，夜间无人访问就整批堆到白天；
// 现在 cron 请求同样走这条路径，站主原有的 cron 打到 /api/cron/* 就能准点触发。
\Hublog\Core\Maintenance::tick();

// V96：付费圈子到期清退 / 提醒。仅做本地文件 TTL 判断（6h），到期后入队，
// 重活在 /api/cron/jobs worker 里执行；dedupe_key 用小时桶，避免固定键命中旧任务后永远不再排队。
try {
    $circleSubSweepState = __DIR__ . '/../cache/.circle_sub_sweep_state';
    $lastSweep = is_file($circleSubSweepState) ? (int)@file_get_contents($circleSubSweepState) : 0;
    if ($lastSweep <= 0 || time() - $lastSweep >= 6 * 3600) {
        @file_put_contents($circleSubSweepState, (string)time(), LOCK_EX);
        \Hublog\Core\JobQueue::enqueue(
            'plugin_task',
            ['handler' => 'circle_subscription_lifecycle', 'action' => 'all'],
            'circle-sub-sweep-' . date('YmdH')
        );
    }
} catch (\Throwable $e) {
    // 核心链路：入队失败不能影响任何业务请求
}

// 加载 plugins/ 目录下的插件（注册 Hook 监听器）
Plugin::loadAll();

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// CORS 跨域响应头（多端适配：H5 跨域访问、App WebView、小程序请求）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
if ($allowedOrigins === '*') {
    // P2-12：本系统使用 Bearer Token 鉴权（非 Cookie 会话），不发送 Access-Control-Allow-Credentials，
    // 故 '*' 仅允许「任意源读取响应」，不会泄露需要凭据的 Cookie；生产环境仍建议配置具体域名白名单。
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '') {
    $originList = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $originList, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Client-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Max-Age: 86400');

// OPTIONS 预检请求直接返回（CORS 预检不需要经过路由）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router = new Router();
    
    // 加载路由定义
    require_once __DIR__ . '/routes.php';

    // 允许插件通过 routes_register 钩子注册自定义路由。
    // P0-2：插件注册发生在 routes.php 全部 group 闭包执行完毕之后，groupStack 已空，
    // 插件直接 $router->get() 的 middleware 会是 []（限流/CSRF/授权/功能门禁全绕过）。
    // 用空前缀 group 给钩子里的路由套上「公开组同款基线」；group('') 的 rtrim 保证路径不变。
    // 刻意不含 AuthMiddleware：/search 等公开检索允许游客，可见性由 VisibilityPolicy 保证。
    $router->group('', function ($router) {
        Hook::doAction('routes_register', $router);
    }, ['RateLimitMiddleware', 'CsrfMiddleware', 'LicenseMiddleware', 'FeatureGuardMiddleware']);

    $response = $router->dispatch();
    // P2-16：正常分支记录 HTTP status 给 access 日志
    $httpStatus = $response->getCode() ?: (http_response_code() ?: 200);
    $GLOBALS['hublog_access_status'] = (int)$httpStatus;
    // P1-10：把业务码同步为真实 HTTP 状态码。原实现 798 处 Response::error
    // 全部返回 HTTP 200（只有 throw HublogException 的 117 处有真状态），
    // 客户端无法用状态码分流、监控/日志也看不出 4xx/5xx 比例。
    // 仅接受合法 HTTP 状态（100~599），非法值回落 200，避免 FastCGI 异常。
    // 兼容性已核验：App（uni.request success 不分辨状态码，按 body.code 分流）
    // 与 H5（fetch 不检查 res.ok）两端均不受影响。
    if ($httpStatus >= 100 && $httpStatus <= 599) {
        http_response_code((int)$httpStatus);
    }
    // 如果 Auth 校验过，user_id 已写入全局（在 Auth::check / user 中设置）
    echo $response->toJson();
} catch (HublogException $e) {
    $code = $e->getCode() ?: 400;
    http_response_code($code);
    $GLOBALS['hublog_access_status'] = (int)$code;
    echo json_encode([
        'code' => $code,
        'message' => $e->getMessage(),
        'data' => null
    ]);
} catch (Throwable $e) {
    // 未捕获异常一律按 500 处理。
    // 旧实现在 APP_DEBUG=false 时返回 400 + "服务器内部错误"：服务器端的致命错误
    // 被伪装成客户端错误，且错误信息被完全吞掉，导致像「从类外部调用 private 方法」
    // 这类问题只能靠猜（/chat/send 全量 400 即由此引起）。
    $code = 500;
    http_response_code($code);
    $GLOBALS['hublog_access_status'] = (int)$code;

    // 始终落日志（PHP error_log 兜底 + 文件日志双写）
    // P1-6：error_id 随响应下发，用户/客服可凭它精确对应到这条日志
    $errorId = bin2hex(random_bytes(4));
    $logMsg = '[Uncaught ' . $errorId . '] ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine()
        . ' [' . get_class($e) . ']';
    try {
        Logger::error($logMsg, [
            'trace_top' => collectTraceTop($e, 8),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
        ]);
    } catch (Throwable $ignored) {
        error_log('[Hublog Uncaught] ' . $logMsg);
    }

    // S6：非 DEBUG 不再回显异常类名、简化根因或栈顶文件:行号 —— 这些都可能
    // 带出物理路径与内部实现细节。完整信息已在上面写进日志，凭 error_id 可查；
    // 仅 APP_DEBUG=true 时把诊断字段放进响应。
    $response = [
        'code'     => $code,
        'message'  => APP_DEBUG
            ? ('服务器内部错误: ' . sanitizeErrorText(shortError($e)))
            : '服务器内部错误，请稍后重试。如持续出现请联系站长并提供错误ID',
        'data'     => null,
        'error_id' => $errorId,
    ];
    if (APP_DEBUG) {
        $response['error_class'] = get_class($e);
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// 走到这里，响应体已经完整输出给客户端（成功/业务异常/500 三条路径都覆盖）。
// 此刻再执行插件登记的延迟任务（典型：AI 自动评论要调外部大模型，耗时数秒到数十秒），
// runDeferred() 内部会先 fastcgi_finish_request() 断开连接，所以用户完全不用等。
Plugin::runDeferred();

/**
 * 把异常裁成短摘要（去掉绝对路径前缀，只保留相对路径）
 */
function shortError(\Throwable $e): string
{
    $msg = $e->getMessage();
    if (strlen($msg) > 240) {
        $msg = substr($msg, 0, 240) . '...';
    }
    return $msg;
}

/**
 * P1-6：剥离错误消息里的绝对路径（项目根 + 常见系统根 + Windows 盘符），
 * 换成 <app>/<path> 占位符。生产响应体调用它，防止目录结构泄露；
 * URL 形态的文本（如 SSRF 报错里的 http://host/path）也会被保守替换。
 */
function sanitizeErrorText(string $msg): string
{
    $root = dirname(__DIR__);
    if ($root !== '' && $root !== '/') {
        $msg = str_replace([$root . '/', $root], '<app>', $msg);
    }
    $msg = preg_replace('#[A-Za-z]:[\\\\/][^\s\'\"(),;{}\[\]]*#', '<path>', $msg);
    $msg = preg_replace('#/(?:www|home|var|usr|opt|srv|Users|data|app)/[^\s\'\"(),;{}\[\]]*#', '<path>', $msg);
    return $msg;
}

/**
 * 收集异常栈顶部 N 帧：每帧只保留 文件 basename + 行号 + 调用函数名
 */
function collectTraceTop(\Throwable $e, int $n = 3): array
{
    $frames = [];
    foreach (array_slice($e->getTrace(), 0, $n) as $f) {
        $frames[] = [
            'file' => isset($f['file']) ? basename($f['file']) : '?',
            'line' => $f['line'] ?? 0,
            'call' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?'),
        ];
    }
    return $frames;
}
