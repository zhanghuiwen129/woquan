<?php
/**
 * Hublog 授权系统中间件
 * 在路由分发前检查授权状态，并根据模块门禁进行拦截。
 *
 * 【模块制：单一声明式拦截】
 * 原先功能门禁散落在多套规则里，新增功能要改多处极易漏拦。
 * 现统一为一份 MODULE_ROUTES：module 名 => 规则列表，每条规则一组命中路由正则。
 * 新增可授权模块时，只需在此表登记一次，并同步 shouquan/license_config.php 的
 * LS_ALL_MODULES（签发端表单）。License::KNOWN_MODULES 与之保持一致。
 *
 * 【路由覆盖必须完整】
 * 每个付费模块的用户侧入口 + 后台入口都要在本表登记，否则等于「一边收费一边白送」：
 * 用户侧走 /api/xxx（登录后即可调用），后台侧走 /api/admin/xxx。
 * api/tests/LicenseRouteCoverageTest.php 会拿 routes.php 的真实路由清单做自检，
 * 新增路由却忘了登记门禁时该测试会失败。
 *
 * 【路径必须先归一化 —— 2026-11-08 修复的真实绕过漏洞】
 * 本表 pattern 一律写 /api/xxx 形式，但 getPath() 返回的是原始 REQUEST_URI。
 * 同一接口有两种访问形式：/api/shop/products 与 /api/index.php/shop/products，
 * 后者（App 端与后台 SPA 实际使用的形式）原本匹配不上 pattern → 门禁裸奔。
 * 现统一由 normalizePath() 把 /api/index.php 前缀还原成 /api 再匹配。
 * 新增 pattern 时只需写 /api/xxx 一种形式，不要自己再补 index.php 变体。
 */

// 修改记录（Apache-2.0 要求标注变更）：本文件由 woquan（我圈）在 Apache-2.0 许可下修改。
// 变更：从 MODULE_ROUTES 移除 badges / points / moderation 的付费门禁（改为核心免费模块）。
// Modified by woquan on 2026-11-13 under Apache License 2.0.

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\License;
use Hublog\Core\Exception\HublogException;

class LicenseMiddleware
{
    /**
     * 把请求路径归一化成本表 pattern 唯一使用的形式
     *
     * 【为什么必须归一化 —— 这是个真实存在的门禁绕过漏洞】
     * 本表的 pattern 一律写成 /api/xxx（如 '#^/api/shop#'），
     * 但 Request::getPath() 取的是 $_SERVER['REQUEST_URI']，即浏览器实际请求的路径。
     * 同一个接口有两种访问形式：
     *   /api/shop/products          → REQUEST_URI 含 /api/shop/...  → pattern 命中 → 正确拦截
     *   /api/index.php/shop/products → REQUEST_URI 含 /api/index.php/shop/... → pattern 不命中 → 裸奔
     * 而 App 端与后台 SPA 走的恰恰是 /api/index.php/... 形式，
     * 结果就是「收费模块用 index.php 形式可以完全绕过门禁」。
     *
     * 归一化规则：把 /api/index.php 前缀还原成 /api，其余部分原样保留。
     * 这样 pattern 只需维护一种形式，新增模块时不会因为 URL 形式不同而漏拦。
     *
     * 【为什么不改 Request::getPath()】
     * Request::getPath() 是全局基础设施，返回原始 REQUEST_URI 才对路由分发有意义
     * （Router 也依赖它定位路由）。改它会影响全站路由行为，风险远大于收益。
     * 只在本中间件内部归一化，影响面可控。
     */
    private static function normalizePath(string $path): string
    {
        // /api/index.php/xxx → /api/xxx
        if (preg_match('#^/api/index\.php(/.*)?$#', $path, $m)) {
            return '/api' . ($m[1] ?? '');
        }
        // 少数站点把入口放在子目录：/sub/api/index.php/xxx → /sub/api/xxx
        if (preg_match('#^(/[^/]+/api)/index\.php(/.*)$#', $path, $m)) {
            return $m[1] . $m[2];
        }
        // /index.php/xxx（入口在根，路径无 /api 前缀）→ 去掉 index.php
        if (preg_match('#^/index\.php(/.*)$#', $path, $m)) {
            return $m[1];
        }
        return $path;
    }

    /**
     * 模块 → 路由拦截规则（单一事实来源）
     *
     * 结构：module => 规则列表[]，每条规则：
     *   'patterns' => [正则...]   命中任一即视为该模块请求
     *   'param'    => 'xxx'       可选：仅当请求携带该参数时才算命中（区分普通发帖 vs 定时发布）
     *   'exclude'  => [正则...]   可选：命中排除项则不算该模块请求（如第三方支付回调）
     *
     * 同一模块可有多条规则（例如「定时发布」既有带 scheduled_at 的发帖，
     * 也有不带参数的定时任务接口 /api/cron/scheduled）。
     *
     * 注意：发布 / 私信 / 会话是「基础社交」，不作模块，由 License::checkQuota() 限量，
     * 不在此表内；它们对所有站点（含未授权）开放试用配额。
     */
    private const MODULE_ROUTES = [
        'ai' => [
            // 用户侧 /api/ai/*（客户端 /api/ai/assist）+ 后台连通性自检
            ['patterns' => ['#^/api/ai/#', '#^/api/admin/ai/#']],
        ],
        'scheduled' => [
            // 仅当请求携带 scheduled_at 才视为定时发布；普通发帖（不带该参数）走配额，不拦
            ['patterns' => ['#^/api/posts#'], 'param' => 'scheduled_at'],
            // 定时任务触发器（无 scheduled_at 参数）
            ['patterns' => ['#^/api/cron/scheduled#']],
        ],
        'dataops' => [
            // 导出 / 备份 / 统计 / 升级 / 版本管理
            // 插件管理（/api/admin/plugins）不挂授权：插件运行时（Plugin::loadAll）本就不做
            // 授权检查，各插件的能力入口（如 /api/ai/*）由各自模块单独拦；拦列表只会
            // 让未授权站的后台「功能插件」标签页空白（402），保护不了任何付费能力。
            ['patterns' => [
                '#^/api/admin/export#',
                '#^/api/admin/backup#',
                '#^/api/admin/stats#',
                '#^/api/admin/upgrade#',
                '#^/api/admin/rollback#',
                '#^/api/admin/version-check#',
                '#^/api/admin/app-versions#',
            ]],
        ],
        'circles' => [
            // 用户侧圈子全部入口 + 后台圈子管理 + 我创建的圈子
            ['patterns' => [
                '#^/api/circles#',
                '#^/api/admin/circles#',
                '#^/api/admin/my-circles#',
            ]],
        ],
        'shop' => [
            // 用户侧商城（/api/shop/buy 等）+ 后台商品/订单管理
            ['patterns' => ['#^/api/shop#', '#^/api/admin/shop#']],
        ],
        'finance' => [
            // 用户侧钱包/VIP/充值/圈子付费 + 后台财务
            // 排除 /api/pay/notify/*：第三方支付异步回调，锁模块也不该打断已付款订单的结算
            ['patterns' => [
                '#^/api/pay#',
                '#^/api/admin/pay#',
                '#^/api/admin/vip-orders#',
                '#^/api/admin/wallet-#',
                '#^/api/admin/recharge-orders#',
                '#^/api/admin/grant-vip#',
                // V96：付费圈到期生命周期入口属于财务模块
                '#^/api/cron/circle-subscribes#',
                // V97：内容付费（单篇/专栏）归财务模块门禁，不单独进 KNOWN_MODULES，
                //      以免已签发的授权码（不含 contentpay 键）被误判为未授权。
                '#^/api/content-pay#',
                '#^/api/admin/content-pay#',
            ], 'exclude' => ['#^/api/pay/notify/#']],
        ],
        'activities' => [
            // 用户侧活动（列表 / 详情 / 报名 / 我的）+ 后台活动管理
            ['patterns' => [
                '#^/api/activities#',
                '#^/api/activity#',
                '#^/api/admin/activities#',
            ]],
        ],
        'realname' => [
            // 用户侧实名认证（状态查询 / 提交认证）+ 后台审核
            ['patterns' => ['#^/api/realname#', '#^/api/admin/realname#']],
        ],
    ];

    /**
     * 走「未授权每日额度」的 AI 路由：命中即 checkQuota()，超了才 402。
     * 仅列终端用户侧（/api/ai/*）。后台 /api/admin/ai/* 是站点主配置 AI 的自检口
     * （连通性测试、ollama 装状态），由上面 MODULE_ROUTES 的 canUseModule('ai') 兜底，
     * 不额外消耗每日额度。
     */
    private const AI_QUOTA_ROUTES = [
        '#^/api/ai/#',
    ];

    /**
     * 需要检查资源限制的写操作（注册 / 发布动态 / 上传）
     * 发布/私信/会话属基础社交，不作模块，仅按配额与全站上限限制。
     */
    private const RESOURCE_WRITE_ROUTES = [
        'POST:/api/auth/register',
        'POST:/api/posts',
        'PUT:/api/posts',
        'POST:/api/posts/upload',
        'POST:/api/posts/upload-video',
        'POST:/api/posts/upload-attachment',
    ];

    public function handle(Request $request): void
    {
        // 授权系统是否启用
        if (!License::isEnabled()) {
            return;
        }

        $method = $request->getMethod();
        $path   = self::normalizePath($request->getPath());

        // 1. 模块门禁：单一声明式映射
        //
        // 语义：一条请求可能同时命中多个模块（如 /api/admin/circles/moderate
        // 既属「圈子」也属「内容治理」）。只要**任意一个**命中的模块不可用就拒绝——
        // 一个通过即可放行，但不会因为「先匹配到的恰好被允许」就把后面被禁的模块漏过去。
        foreach (self::MODULE_ROUTES as $module => $rules) {
            foreach ($rules as $rule) {
                $patterns = $rule['patterns'] ?? [];
                $param    = $rule['param']    ?? null;
                $exclude  = $rule['exclude']  ?? [];

                $pathMatched = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $pathMatched = true;
                        break;
                    }
                }
                if (!$pathMatched) {
                    continue;
                }

                // 排除项：命中即视为不属于本模块（如第三方支付回调）
                $excluded = false;
                foreach ($exclude as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }

                // 可选 param 条件：例如 /api/posts 不带 scheduled_at 时不算定时发布，跳过本模块
                if ($param !== null && $request->get($param) === null) {
                    continue;
                }

                if (!License::canUseModule($module)) {
                    throw new HublogException(
                        "该功能需要授权后才能使用（module: {$module}），请前往后台输入授权码解锁。",
                        402
                    );
                }
            }
        }

        // 2. AI 辅助的每日额度（未授权试用）：命中 /api/ai/* 就查一次配额。
        // canUseModule('ai') 只判断「这项功能开没开放」（配额 > 0），
        // 真正的「今天还能用几次」在这里拦，和发布动态的日配额是同一套 checkQuota()。
        foreach (self::AI_QUOTA_ROUTES as $pattern) {
            if (preg_match($pattern, $path)) {
                $user = \Hublog\Core\Auth::user($request);
                $uid  = (int)($user['id'] ?? 0);
                if ($uid > 0) {
                    $q = License::checkQuota('ai', $uid);
                    if (!$q['ok']) {
                        throw new HublogException($q['message'], 402);
                    }
                    // V100：check 与扣减之间原本没有原子性，并发下可超额调用。
                    // 仅在「启用授权且未授权」的试用态预扣（与 checkQuota 的 early-return 同口径）；
                    // 预扣 ID 放到框架内部属性，由控制器成功后 confirm / 失败后 release。
                    $status = License::getStatus();
                    if ($status['enabled'] && !$status['authorized']) {
                        $action = $request->getString('action');
                        $reservationId = License::tryReserveAiQuota($uid, $action);
                        if ($reservationId === null) {
                            throw new HublogException($q['message'] !== '' ? $q['message'] : '试用版 AI 额度已用完', 402);
                        }
                        $request->setAttribute('ai_quota_reservation_id', $reservationId);
                    }
                }
                break;
            }
        }

        // 3. 资源限制检查（注册 / 发布动态 / 上传）
        $routeKey = "{$method}:{$path}";
        foreach (self::RESOURCE_WRITE_ROUTES as $r) {
            if ($routeKey === $r || str_starts_with($routeKey, $r)) {
                // 注册
                if (str_contains($r, 'register')) {
                    if (!License::canRegister()) {
                        $limit = License::checkResourceLimit();
                        throw new HublogException(
                            "未授权版本最多允许 {$limit['max_users']} 个用户，当前已达 {$limit['users']} 个。请授权后解锁。",
                            402
                        );
                    }
                }
                // 发布 / 上传
                if (str_contains($r, 'posts') && ($method === 'POST' || $method === 'PUT')) {
                    if (!License::canPost()) {
                        $limit = License::checkResourceLimit();
                        throw new HublogException(
                            "试用版最多允许 {$limit['max_posts']} 条动态，当前已达 {$limit['posts']} 条。请授权后解锁。",
                            402
                        );
                    }
                    // 每日发布配额（未授权时）：总量之外再卡一层日配额，0 点自动重置
                    $user = \Hublog\Core\Auth::user($request);
                    $uid  = (int)($user['id'] ?? 0);
                    if ($uid > 0) {
                        $q = License::checkQuota('post', $uid);
                        if (!$q['ok']) {
                            throw new HublogException($q['message'], 402);
                        }
                    }
                }
                break;
            }
        }
    }
}
