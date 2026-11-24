<?php
/**
 * Hublog CSRF防护中间件
 *
 * 【判断逻辑修正】
 *   旧实现以 "X-Client-Type: app/miniapp" 头作为跳过 CSRF 的依据——这个头是客户端能随便加的，
 *   任何跨站 fetch 设置一个 header 就能直接绕过。正确判断方式是：**只要请求使用了 Cookie 来做认证
 *   （即没有 Bearer Token / Cookie 非空）才开启 CSRF 校验**。当前 Hublog 用 Bearer JWT 而不是
 *   Cookie 会话，所以 CSRF 理论上攻击面很小，仍保留保护以防将来切 Cookie。
 *
 *   新规则：
 *   1) GET/HEAD 不校验（但对敏感 GET 路径设置 Cache-Control 私有）
 *   2) 若存在 `Authorization: Bearer <JWT>` 且 **没有任何 PHPSESSID/CSRF 认证 Cookie** → 视为纯 JWT 请求 → 无 CSRF
 *   3) App/小程序端（X-Client-Type: app/miniapp）且**无任何认证 Cookie** → 无 Cookie 则无 CSRF 攻击面 → 放行
 *      （CSRF 攻击依赖浏览器自动带 Cookie，App/小程序请求不会自动带 Cookie，天然免疫）
 *   4) 否则（Cookie 可能带会话、或默认混合鉴权）走 Csrf::verify，并且把 Request 传进 Csrf 做
 *      user_id 绑定校验——防止攻击者把"自己账号下生成的 token"喂给受害者。
 */

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\Csrf;
use Hublog\Core\Auth;
use Hublog\Core\Exception\HublogException;

class CsrfMiddleware
{
    /**
     * 判断当前请求是否**完全不依赖 Cookie 鉴权**（纯 Bearer JWT，无 CSRF 攻击面）
     */
    private static function isPureBearer(Request $request): bool
    {
        $authHeader = $request->getHeader('authorization');
        $hasBearer  = is_string($authHeader) && stripos($authHeader, 'Bearer ') === 0;
        if (!$hasBearer) {
            return false;
        }

        // 还得保证 Cookie 里**没有任何会话状态**：否则攻击者可以利用现有 Cookie 会话做 CSRF
        // 安全修复：纳入真实 JWT httpOnly Cookie（Auth::AUTH_COOKIE），用常量引用避免名单再次漂移。
        // 缺它时「带 hublog_token 的浏览器请求 + 伪造 X-Client-Type: app」会被误判为纯 Bearer 放行。
        $sensitiveCookies = ['PHPSESSID', 'user_token', 'hublog_uid', 'remember_web', 'csrf_guest_subject', Auth::AUTH_COOKIE];
        foreach ($sensitiveCookies as $k) {
            if (isset($_COOKIE[$k]) && is_string($_COOKIE[$k]) && $_COOKIE[$k] !== '') {
                // csrf_guest_subject 只是 CSRF 自己的绑定指纹，不是认证 Cookie——仍然通过
                if ($k === 'csrf_guest_subject') continue;
                return false;
            }
        }
        return true;
    }

    /**
     * 判断是否完全没有认证 Cookie（无会话状态则无 CSRF 攻击面）
     */
    private static function hasNoAuthCookies(): bool
    {
        // 安全修复：同上，必须包含真实认证 Cookie，否则 X-Client-Type: app 的跨站请求
        // 携带受害者 hublog_token 时 hasNoAuthCookies() 会误判为「无会话」而跳过 CSRF。
        $authCookies = ['PHPSESSID', 'user_token', 'hublog_uid', 'remember_web', Auth::AUTH_COOKIE];
        foreach ($authCookies as $k) {
            if (isset($_COOKIE[$k]) && is_string($_COOKIE[$k]) && $_COOKIE[$k] !== '') {
                return false;
            }
        }
        return true;
    }

    public function handle(Request $request): void
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            // 实际路由为 /auth/me（原写 /user/me 不存在，private 头从未生效）
            $privateGetPaths = ['/posts/check-new', '/notifications', '/auth/me'];
            $path = $request->getPath();
            foreach ($privateGetPaths as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    header('Cache-Control: private, no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    break;
                }
            }
            return;
        }

        // 纯 Bearer JWT 无 CSRF → 放行
        if (self::isPureBearer($request)) {
            return;
        }

        // App/小程序端 + 无任何认证 Cookie → 无 Cookie 则无 CSRF 攻击面 → 放行
        // CSRF 攻击依赖浏览器自动带 Cookie，App/小程序不会自动带 Cookie，天然免疫
        $clientType = (string)$request->getHeader('x-client-type');
        if (in_array($clientType, ['app', 'miniapp'], true) && self::hasNoAuthCookies()) {
            return;
        }

        // 公开接口白名单：登录、注册等不需要 CSRF 保护（攻击者无法用 CSRF "帮你"登录/注册）
        $publicPaths = [
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/forgot-password',
            '/api/auth/wx-mini-login',
            '/api/auth/send-code',
            '/api/auth/mobile-login',
            '/api/auth/refresh',
            '/api/auth/token-refresh',
            '/api/app/auth/wx-app-login',
            '/api/app/version',
            '/api/app/config',
            '/api/csrf',
            // V65/V82/V94：定时调度入口 —— 外部 cron 从服务器侧打，没有浏览器/用户会话可带，
            // 鉴权交给各控制器自己的 cron_secret token 校验（未配 token 时一律 403）。
            // 站点模式二期在 /cron/badges 控制器内做了「模块关闭则 skipped」判断，
            // 这里若不放行，请求会在到达控制器前就 403 CSRF，跳过逻辑形同虚设。
            '/api/cron/scheduled',
            '/api/cron/badges',
            '/api/cron/jobs',
            '/api/cron/archive',
            '/api/cron/circle-subscribes',
            '/api/cron/license-heartbeat',
            '/api/cron/vip-expire',
        ];
        $path = $request->getPath();
        foreach ($publicPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $token = $request->getHeader('x-csrf-token') ?? $request->get('csrf_token');
        if (!Csrf::verify($token, $request)) {
            throw new HublogException('CSRF验证失败', 403);
        }
    }
}
