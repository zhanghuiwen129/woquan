<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Middleware\LicenseMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 授权路由覆盖自检（模块制）
 *
 * 【为什么需要这个测试】
 * 授权模块的真实价值在于「未授权时这个功能用不了」。而可拦的接口**只存在于
 * api/routes.php 里**——模块清单写对了、中间件表写了，但如果 routes.php 新增了一个
 * 属于付费模块的入口却没登记到 LicenseMiddleware::MODULE_ROUTES，这个入口就会
 * 对未授权站点裸奔：一边收费一边白送，而所有单元测试都是绿的。
 *
 * 这类漏拦是**静默**的：代码能跑、接口能通，只是没拦住。所以必须拿真实路由清单
 * 反过来校验门禁表，而不是只测门禁表本身。
 *
 * 本测试做两件事（双向）：
 *   A. 正向：每个属于付费模块的真实路由，都必须被 MODULE_ROUTES 命中，
 *      且命中到的模块 == 它应当归属的模块（防止归错模块导致 trial 档误放行）。
 *   B. 反向：核心路由（发布/私信/会话/上传/后台基础管理）**不得**被命中，
 *      防止把免费能力误锁成付费。
 */
final class LicenseRouteCoverageTest extends TestCase
{
    /**
     * 路由前缀 → 归属类别
     *
     *   'core'            = 基础社交 / 站点基础管理，**不得**被模块门禁拦
     *   '模块名'          = 付费模块，**必须**被该模块的门禁命中
     *
     * 匹配时取**最长前缀**命中：
     *   /api/pay/notify 比 /api/pay 长 → 支付回调归 core（不拦）
     *   /api/admin/users/mute 比 /api/admin/users 长 → 原归 moderation，2026-11 起改为免费核心（归 core，不拦）
     */
    private const ROUTE_CLASS_MAP = [
        // ==================== 付费模块：必须被拦 ====================
        '/api/ai'                    => 'ai',
        '/api/cron/scheduled'        => 'scheduled',
        '/api/circles'               => 'circles',
        '/api/admin/circles'         => 'circles',
        '/api/admin/my-circles'      => 'circles',
        '/api/shop'                  => 'shop',
        '/api/admin/shop'            => 'shop',
        '/api/pay'                   => 'finance',
        '/api/admin/pay'             => 'finance',
        '/api/admin/vip-orders'      => 'finance',
        '/api/admin/wallet-'         => 'finance',
        '/api/admin/recharge-orders' => 'finance',
        '/api/admin/grant-vip'       => 'finance',
        '/api/cron/circle-subscribes' => 'finance',
        '/api/content-pay'            => 'finance', // V97 内容付费归财务门禁（contentpay 是开关模块，非授权模块）
        '/api/admin/content-pay'      => 'finance',
        '/api/activities'            => 'activities',
        '/api/activity'              => 'activities',
        '/api/admin/activities'      => 'activities',
        '/api/realname'              => 'realname',
        '/api/admin/realname'        => 'realname',
        '/api/admin/export'          => 'dataops',
        '/api/admin/backup'          => 'dataops',
        '/api/admin/stats'           => 'dataops',
        '/api/admin/upgrade'         => 'dataops',
        '/api/admin/rollback'        => 'dataops',
        '/api/admin/version-check'   => 'dataops',
        '/api/admin/app-versions'    => 'dataops',

        // ==================== 核心：不得被拦 ====================
        '/api/pay/notify'           => 'core', // 第三方支付异步回调：不打断已付款订单的结算
        '/api/posts'                => 'core', // 基础社交（定时发布由 scheduled_at 参数单独判）
        '/api/comments'             => 'core',
        '/api/chat'                 => 'core',
        '/api/auth'                 => 'core',
        '/api/user'                 => 'core',
        '/api/users'                => 'core',
        '/api/follow'               => 'core',
        '/api/favorites'            => 'core',
        '/api/notifications'        => 'core',
        '/api/topics'               => 'core',
        '/api/call'                 => 'core',
        '/api/upload'               => 'core',
        '/api/gallery'              => 'core', // V106 独立图库：免费基础媒体能力，与 /api/upload 同类不挂门禁
        '/api/attachments'          => 'core',
        '/api/search'               => 'core',
        '/api/site'                 => 'core',
        '/api/pages'                => 'core', // B2 通用单页公开读取：免费基础能力，不进授权门禁
        '/api/app'                  => 'core',
        '/api/license'              => 'core',
        '/api/reports'              => 'core',
        '/api/admin/dashboard'      => 'core',
        '/api/admin/settings'       => 'core',
        '/api/admin/categories'     => 'core',
        '/api/admin/friend-links'   => 'core',
        '/api/admin/crash-logs'     => 'core',
        '/api/admin/posts'          => 'core',
        '/api/admin/comments'       => 'core',
        '/api/admin/users'          => 'core',
        '/api/admin/reports'        => 'core',
        '/api/admin/license'        => 'core',
        '/api/admin/chat'           => 'core',
        '/api/admin/notifications'  => 'core',
        '/api/admin/topic'          => 'core',
        '/api/admin/plugins'        => 'core', // 插件管理不挂授权：运行时（Plugin::loadAll）本就不拦，列表/开关属站点基础管理
        '/api/admin/pages'          => 'core', // B2 通用单页管理：免费基础能力，仅视觉归 dataops 展示分组
        '/api/admin/plugin'         => 'core', // T03 插件生命周期 / 配置路由预留：同样归核心，不挂授权
        // —— 以下原属付费模块，自 2026-11 起改为免费核心能力（见 License::CORE_FREE_MODULES）——
        '/api/cron/badges'           => 'core',
        '/api/cron/license-heartbeat' => 'core', // 授权统计上报兜底端点：未授权站点也必须可上报，不挂模块门禁
        '/api/points'                => 'core',
        '/api/admin/points'          => 'core',
        '/api/admin/point-task'      => 'core',
        '/api/admin/wheel-prize'     => 'core',
        '/api/badges'                => 'core',
        '/api/admin/badge'           => 'core',
        '/api/admin/user-badge'      => 'core',
        '/api/admin/sensitive-words' => 'core',
        '/api/admin/filter-logs'     => 'core',
        '/api/admin/appeal'          => 'core',
        '/api/admin/featured'        => 'core',
        '/api/admin/users/mute'      => 'core',
        '/api/admin/users/unmute'    => 'core',
    ];

    /** @return array<string, array<int, array{patterns: array, param: ?string, exclude: array}>> */
    private function moduleRoutes(): array
    {
        $const = (new ReflectionClass(LicenseMiddleware::class))->getReflectionConstant('MODULE_ROUTES');
        $this->assertNotFalse($const, 'LicenseMiddleware::MODULE_ROUTES 常量不存在');
        $rules = [];
        foreach ($const->getValue() as $module => $list) {
            foreach ($list as $rule) {
                $rules[$module][] = [
                    'patterns' => $rule['patterns'] ?? [],
                    'param'    => $rule['param']    ?? null,
                    'exclude'  => $rule['exclude']  ?? [],
                ];
            }
        }
        return $rules;
    }

    /**
     * 解析 api/routes.php，取出所有真实注册路由的完整路径
     *
     * @return array<int, array{method: string, path: string}>
     */
    private function parseRoutes(): array
    {
        $file = __DIR__ . '/../routes.php';
        $this->assertFileExists($file, '找不到 api/routes.php');

        $routes = [];
        $stack = [];   // 当前嵌套的 group 前缀
        $depth = 0;    // 大括号深度

        foreach (file($file) as $line) {
            // group 开启
            if (preg_match('/\$router->group\(\s*\'([^\']+)\'/', $line, $m)) {
                $stack[] = rtrim($m[1], '/');
                $depth += substr_count($line, '{') - substr_count($line, '}');
                continue;
            }
            // 路由注册
            if (preg_match('/\$router->(get|post|put|delete|patch)\(\s*\'([^\']+)\'/', $line, $m)) {
                $prefix = $stack ? (string)end($stack) : '';
                $routes[] = [
                    'method' => strtoupper($m[1]),
                    'path'   => $prefix . $m[2],
                ];
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
            while ($stack && $depth < count($stack)) {
                array_pop($stack);
            }
        }
        return $routes;
    }

    /** 命中模块（返回模块名；未命中返回 null）。param 条件不满足时视为不命中。 */
    private function matchModule(array $rules, string $path): ?string
    {
        foreach ($rules as $module => $ruleList) {
            foreach ($ruleList as $rule) {
                $hit = false;
                foreach ($rule['patterns'] as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $excluded = false;
                foreach ($rule['exclude'] as $pattern) {
                    if (preg_match($pattern, $path)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    continue;
                }
                // param 条件：定时发布靠 scheduled_at 区分（普通发帖不算），
                // 这里按「未携带该参数」处理，与普通发帖的判定一致
                if ($rule['param'] !== null) {
                    continue;
                }
                return $module;
            }
        }
        return null;
    }

    /** 归属类别（最长前缀优先）；未登记的路由返回 null（不参与断言） */
    private function expectedClass(string $path): ?string
    {
        $best = null;
        $bestLen = -1;
        foreach (self::ROUTE_CLASS_MAP as $prefix => $class) {
            if (str_starts_with($path, $prefix) && strlen($prefix) > $bestLen) {
                $best = $class;
                $bestLen = strlen($prefix);
            }
        }
        return $best;
    }

    // ==================== 正向：付费模块路由必须被拦 ====================

    public function testEveryPaidModuleRouteIsGated(): void
    {
        $rules = $this->moduleRoutes();
        $routes = $this->parseRoutes();
        $this->assertGreaterThan(50, count($routes), 'routes.php 解析异常：只解析出少量路由');

        $missing = [];
        $misplaced = [];
        foreach ($routes as $r) {
            $expected = $this->expectedClass($r['path']);
            if ($expected === null || $expected === 'core') {
                continue;
            }
            $actual = $this->matchModule($rules, $r['path']);
            if ($actual === null) {
                $missing[] = $r['method'] . ' ' . $r['path'] . '  (应属模块 ' . $expected . ')';
            } elseif ($actual !== $expected) {
                $misplaced[] = $r['method'] . ' ' . $r['path'] . '  应属 ' . $expected . '，实际命中 ' . $actual;
            }
        }

        $this->assertSame([], $missing, "以下付费模块路由没有登记到 MODULE_ROUTES（未授权时会裸奔）：\n" . implode("\n", $missing));
        $this->assertSame([], $misplaced, "以下路由命中了错误的模块（trial 档可能被误放行）：\n" . implode("\n", $misplaced));
    }

    // ==================== 反向：核心路由不得被误锁 ====================

    public function testCoreRoutesAreNotGated(): void
    {
        $rules = $this->moduleRoutes();
        $routes = $this->parseRoutes();

        $wronglyGated = [];
        foreach ($routes as $r) {
            if ($this->expectedClass($r['path']) !== 'core') {
                continue;
            }
            $actual = $this->matchModule($rules, $r['path']);
            if ($actual !== null) {
                $wronglyGated[] = $r['method'] . ' ' . $r['path'] . '  被模块 ' . $actual . ' 拦了';
            }
        }

        $this->assertSame([], $wronglyGated, "以下核心路由被模块门禁误锁（基础社交/后台基础管理应始终可用）：\n" . implode("\n", $wronglyGated));
    }

    // ==================== 模块清单一致性 ====================

    public function testModuleRoutesCoverAllKnownModules(): void
    {
        $rules = $this->moduleRoutes();
        $known = (new ReflectionClass(\Hublog\Core\License::class))->getReflectionConstant('KNOWN_MODULES');
        $this->assertNotFalse($known, 'License::KNOWN_MODULES 常量不存在');

        $missing = array_diff(array_keys($known->getValue()), array_keys($rules));
        $this->assertSame([], $missing, '以下可授权模块没有在 MODULE_ROUTES 登记路由规则：' . implode(', ', $missing));
    }

    public function testPaymentCallbackIsExcludedFromFinance(): void
    {
        // 第三方支付异步回调：锁模块也不能打断已付款订单的结算
        $rules = $this->moduleRoutes();
        $this->assertNull($this->matchModule($rules, '/api/pay/notify/alipay'), '支付回调不应被 finance 模块拦');
        $this->assertNotNull($this->matchModule($rules, '/api/pay/vip-buy'), 'VIP 购买必须被 finance 模块拦');
    }

    public function testScheduledPostNeedsParamToBeGated(): void
    {
        // 普通发帖属基础社交，只有带 scheduled_at 才算「定时发布」付费模块
        $rules = $this->moduleRoutes();
        $this->assertNull($this->matchModule($rules, '/api/posts'), '普通发帖不应被 scheduled 模块拦');
        $this->assertNotNull($this->matchModule($rules, '/api/cron/scheduled'), '定时任务触发器必须被 scheduled 模块拦');
    }

    // ==================== 路径归一化（防 index.php 形式绕过门禁）====================

    /**
     * 【回归锚点】2026-11-08 发现的真实绕过漏洞
     *
     * 同一个接口有两种访问形式：
     *   /api/shop/products           → REQUEST_URI 含 /api/shop/...        → 正常拦截
     *   /api/index.php/shop/products → REQUEST_URI 含 /api/index.php/shop/... → 原本裸奔
     *
     * 而 App 端与后台 SPA 用的恰恰是 /api/index.php/... 形式，
     * 等于「付费模块换个 URL 就能白用」。修复方式：LicenseMiddleware::normalizePath()
     * 把 /api/index.php 前缀还原成 /api 再匹配。
     *
     * 本测试锁死归一化行为，防止将来有人「简化」掉它导致漏洞复发。
     */
    public function testIndexPhpPathIsNormalizedBeforeGating(): void
    {
        $rc = new ReflectionClass(LicenseMiddleware::class);
        $m  = $rc->getMethod('normalizePath');
        $m->setAccessible(true);

        $cases = [
            '/api/index.php/shop/products' => '/api/shop/products',
            '/api/index.php/admin/stats'    => '/api/admin/stats',
            '/api/index.php/ai/assist'      => '/api/ai/assist',
            '/api/index.php'                => '/api',
            '/api/index.php/'               => '/api/',
            '/api/shop/products'            => '/api/shop/products',   // 已是规范形式，不变
            '/api/index.phpfoo/x'           => '/api/index.phpfoo/x',  // 只匹配 index.php 边界，不误伤
        ];
        foreach ($cases as $in => $expected) {
            $this->assertSame($expected, $m->invoke(null, $in), "路径归一化错误: {$in}");
        }
    }

    public function testGatedModuleCannotBeBypassedByIndexPhpPrefix(): void
    {
        // 归一化后，index.php 形式与标准形式必须命中同一个模块
        $rules = $this->moduleRoutes();
        $m = (new ReflectionClass(LicenseMiddleware::class))->getMethod('normalizePath');
        $m->setAccessible(true);

        foreach (['/api/shop/products', '/api/admin/stats', '/api/circles', '/api/activities'] as $p) {
            // 拼成 index.php 形式：/api/shop/products → /api/index.php/shop/products
            $viaIndex = $m->invoke(null, '/api/index.php' . substr($p, 4));
            $direct   = $m->invoke(null, $p);
            $this->assertSame($direct, $viaIndex, "index.php 形式与标准形式归一化结果不一致: {$p}");
            $this->assertNotNull(
                $this->matchModule($rules, $viaIndex),
                "付费模块经 index.php 形式后未被门禁命中（可绕过）: {$p}"
            );
        }
    }
}
