<?php
/**
 * Hublog 接口限流中间件
 * 宽阈值设计：保障正常使用体验，仅拦截异常高频请求
 */

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\Auth;
use Hublog\Core\Security;
use Hublog\Core\Exception\HublogException;

class RateLimitMiddleware
{
    /**
     * 轮询/高频 GET 接口（这些接口需要更宽松的限流）
     */
    private const POLLING_PREFIXES = [
        '/api/posts/check-new',
        '/api/notifications/unread-count',
        '/api/follow/stats',
        '/api/chat/sse',
        '/api/events/sse',
        // P1-3 修复：实际 SSE 路由是 /api/sse，原配置完全没生效
        '/api/sse',
        '/api/chat/',
        '/api/notifications/',
    ];

    public function handle(Request $request): void
    {
        // $ip：真实客户端 IP。Request::getIp() 只在 REMOTE_ADDR 命中 TRUSTED_PROXIES
        // 时才解析 X-Forwarded-For / X-Real-IP，否则一律用 REMOTE_ADDR（不可被客户端伪造）。
        $ip = $request->getIp();
        $user = Auth::user($request);
        $method = $request->getMethod();
        $path = $request->getPath();

        // S5 修复：原实现在此对 10./172.16./192.168./127.0.0.1 私网回源无条件 return，
        // 而反代部署下 REMOTE_ADDR 恰恰常是内网 IP → 所有外部请求全部豁免，
        // Security::rateLimit() 根本不执行，全局限流形同虚设。
        // 现在不再按「是否私网」豁免：反代场景请显式配置 TRUSTED_PROXIES，
        // getIp() 会据此还原真实客户端 IP；未配置时宁可所有用户共用一个计数桶
        // （可能误伤），也绝不整体绕过限流（fail-closed）。

        // 唯一豁免：admin 角色用户（后台使用与管理员日常发布；登录爆破另有 checkLoginLock 单独限流）
        if ($user && ($user['role'] ?? '') === 'admin') {
            return;
        }

        // 判断请求类型，决定使用的限流策略
        $isPolling = $this->isPollingPath($path);

        if ($method === 'GET') {
            if ($isPolling) {
                // 轮询类 GET（check-new、unread-count、follow/stats、SSE 等）：宽阈值
                // 允许 2400 次/分钟（约 40 次/秒），覆盖多标签页 + 快速刷新 + SSE 重连
                $action = 'api_read_polling';
                $maxRequests = 2400;
                $window = 60;
            } else {
                // 普通 GET（timeline、profile、post detail 等）
                // 允许 1500 次/分钟
                $action = 'api_read';
                $maxRequests = 1500;
                $window = 60;
            }
        } else {
            // 写操作（POST/PUT/DELETE）：适当放宽
            // 允许 600 次/分钟
            $action = 'api_write';
            $maxRequests = 600;
            $window = 60;
        }

        if (!Security::rateLimit($ip, $action, $user['id'] ?? null, $maxRequests, $window)) {
            throw new HublogException('请求过于频繁，请稍后再试', 429);
        }
    }

    /**
     * 判断是否为轮询/高频读取接口
     */
    private function isPollingPath(string $path): bool
    {
        foreach (self::POLLING_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
