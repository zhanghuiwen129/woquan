<?php
/**
 * Hublog 共享启动引导（S6 / S7 修复）
 *
 * 背景：根 index.php 与 api/index.php 各自重复实现 FATAL 兜底和 JWT_SECRET 兜底，
 * P1-6「生产错误脱敏」只落地在 api 入口，前端入口仍无条件回显消息 + 绝对路径；
 * P2-12「弱密钥失败关闭」也只在 api 入口，且黑名单漏了 config.example.php 的
 * 公开示例值，前端入口甚至仍允许 'change-me-in-production'。
 *
 * 现在两个入口共用这里的唯一实现。本文件必须早于 config.php 加载，
 * 不依赖任何项目类、自动加载器或配置常量。
 */

declare(strict_types=1);

namespace Hublog\Bootstrap;

/**
 * S8：安装锁放在站点根目录，不再放 cache/（清缓存会连锁一起删掉）。
 * 旧路径仅用于「已安装」判定，保证老部署升级后不会突然跳安装向导。
 */
const INSTALL_LOCK_FILE = '.installed.lock';
const INSTALL_LOCK_LEGACY = 'cache/install.lock';

function installLockPath(string $root): string
{
    return rtrim($root, '/\\') . '/' . INSTALL_LOCK_FILE;
}

function isInstalled(string $root): bool
{
    $root = rtrim($root, '/\\');
    return file_exists($root . '/' . INSTALL_LOCK_FILE)
        || file_exists($root . '/' . INSTALL_LOCK_LEGACY);
}

/** 已知/公开的弱 JWT 密钥，生产环境一律拒绝启动 */
const WEAK_JWT_SECRETS = [
    '',
    'change-me-in-production',
    'your-random-jwt-secret-key',
    'dev-only-insecure-secret-do-not-use-in-prod',
    'please-change-this-to-a-64-char-random-hex-string-change-me',
    'secret',
    'jwt_secret',
];

function isDebug(): bool
{
    return defined('APP_DEBUG') && APP_DEBUG === true;
}

/**
 * 公共默认常量（四个入口共用一份定义）
 *
 * P3「四入口初始化收敛」：
 *   原先 index.php（前台）与 api/index.php（API）各自抄了一遍这 5 个 define()，
 *   install.php / bin/reconcile-counters.php 则一个都没定义 ——
 *   于是「某个常量到底在哪个入口有值」全靠记忆，改默认值必然漏改一处。
 *
 * 语义与原先**逐字一致**（同样的默认值、同样的「已定义则不覆盖」规则）：
 *   config.php 里定义过的值优先（config 是唯一真实来源），这里只兜底。
 *
 * 调用时机：require config.php 之后、任何业务代码之前。
 */
function defineDefaults(): void
{
    defined('APP_DEBUG') || define('APP_DEBUG', false);
    defined('JWT_EXPIRE') || define('JWT_EXPIRE', 86400);
    defined('CACHE_TTL') || define('CACHE_TTL', 3600);
    defined('APP_NAME') || define('APP_NAME', '我圈');
    defined('TRUSTED_PROXIES') || define('TRUSTED_PROXIES', '127.0.0.1,::1');
}

/**
 * 注册统一的 FATAL 兜底：生产只给通用文案 + error_id，完整信息写服务端日志。
 *
 * @param bool $asJson true = API 入口返回 JSON；false = 站点前端返回 HTML
 */
function registerFatalHandler(bool $asJson): void
{
    register_shutdown_function(static function () use ($asJson): void {
        $error = error_get_last();
        if (!$error || !in_array(
            $error['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true
        )) {
            return;
        }

        $errorId = bin2hex(random_bytes(4));
        error_log(sprintf(
            '[Hublog FATAL %s] %s @ %s:%d',
            $errorId,
            $error['message'],
            $error['file'],
            $error['line']
        ));

        $debug = isDebug();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: '
                . ($asJson ? 'application/json' : 'text/html') . '; charset=utf-8');
        }

        if ($asJson) {
            $payload = [
                'code'     => 500,
                'message'  => $debug
                    ? ('服务器错误: ' . $error['message'])
                    : '服务器内部错误，请稍后重试。如持续出现请联系站长并提供错误ID',
                'data'     => null,
                'error_id' => $errorId,
            ];
            if ($debug) {
                $payload['debug'] = ['file' => $error['file'], 'line' => $error['line']];
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        echo $debug
            ? '<h1>服务器错误</h1><p>' . htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>文件: ' . htmlspecialchars($error['file'], ENT_QUOTES, 'UTF-8')
                . ' | 行: ' . (int)$error['line'] . '</p>'
            : '<h1>服务器内部错误</h1><p>请稍后重试。如持续出现请联系站长并提供错误ID：'
                . $errorId . '</p>';
    });
}

/**
 * 校验 JWT_SECRET：生产缺密钥 / 公开示例值 / 过短密钥一律拒绝启动（失败关闭）。
 * 必须在 require config.php 且 APP_DEBUG 定义之后调用。
 *
 * @param bool $asJson true = API 入口返回 JSON；false = 站点前端返回 HTML
 */
function assertJwtSecret(bool $asJson): void
{
    $secret = defined('JWT_SECRET') ? (string)JWT_SECRET : '';
    $weak = in_array($secret, WEAK_JWT_SECRETS, true) || strlen($secret) < 32;

    if (!$weak) {
        return;
    }

    // 本地联调：未定义时补一个固定的开发密钥；已定义弱值就沿用（不能重复 define）。
    if (isDebug()) {
        if (!defined('JWT_SECRET')) {
            define('JWT_SECRET', 'dev-only-insecure-secret-do-not-use-in-prod');
        }
        return;
    }

    $message = '服务器配置错误：JWT_SECRET 未配置或使用了公开示例密钥，已拒绝启动。'
        . '请在 config.php 中设置至少 32 字节的随机密钥。';
    error_log('[Hublog BOOT] ' . $message);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: '
            . ($asJson ? 'application/json' : 'text/html') . '; charset=utf-8');
    }
    echo $asJson
        ? json_encode(['code' => 500, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE)
        : '<h1>服务器配置错误</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
