<?php
declare(strict_types=1);
/**
 * 站点模式 · 运行层功能守卫（二期，默认关闭）
 *
 * fail-open 三原则：
 *  1. feature_guard_mode 缺省 off → 第一行就 return，零行为变化；
 *  2. 只拦「明确关闭 + 明确前缀」，映射表未覆盖的路径一律放行；
 *  3. 基础路径（鉴权 / 配置 / 后台管理 / 支付回调 / cron / 健康检查）永不拦截。
 *
 * 中断方式：返回 Response::error(4230)，由 Router::dispatch 的「中间件短路」统一输出，
 * 与项目其余 Response::error 一致（HTTP 200 + body.code = 4230）。
 * 绝不能抛 HublogException(4230)：api/index.php 会把异常 code 当 HTTP 状态码。
 */

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\FeatureFlags;
use Hublog\Core\ModuleCatalog;

class FeatureGuardMiddleware
{
    /** 永不禁拦前缀：拦了会锁死管理/登录/配置，或造成支付掉单 */
    private const NEVER_BLOCK = [
        '/api/admin/', '/api/auth/', '/api/app/config', '/api/site/config',
        '/api/pay/notify/', '/api/cron/', '/api/csrf',
        '/health', '/sitemap.xml', '/robots.txt',
    ];

    public function handle(Request $request): ?Response
    {
        $mode = FeatureFlags::guardMode();
        if ($mode === 'off') return null;

        // Router::dispatch() 已把解析后的规范路径回写给 Request（见 Router.php L79）
        $path = $request->getPath();
        foreach (self::NEVER_BLOCK as $prefix) {
            if (str_starts_with($path, $prefix)) return null;
        }

        $module = null;
        foreach (ModuleCatalog::routePrefixes() as $prefix => $key) {
            if (str_starts_with($path, $prefix)) {
                $module = $key;
                break;
            }
        }
        if ($module === null) return null;

        $flags = FeatureFlags::all();
        if (($flags[$module] ?? 1) === 1) return null;

        if ($mode === 'log') {
            error_log('[FeatureGuard] would block ' . $path . ' (module=' . $module . ')');
            return null;
        }

        return Response::error('该功能未开启', 4230);
    }
}
