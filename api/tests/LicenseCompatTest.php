<?php
/**
 * 授权码跨版本兼容矩阵自检（回归测试）
 *
 * 【背景】2026-11-08 授权系统从「功能制(features)」改为「模块制(modules)」。
 * 客户已用旧源码搭建的站点，其 License.php 只认 features 字段；新站点认 modules。
 * 三种组合都必须能正常工作，否则会出现「升级后付费功能全锁死」或「付费功能白送」。
 *
 * 本测试用【真实旧版 License.php】（fixtures 里原样保留，未改一字）跑旧客户端逻辑，
 * 验证四种组合：
 *   A. 旧客户端 + 新码（features 已写 legacy 投影）→ 旧站点功能正常
 *   B. 旧客户端 + 新码（features 缺失，模拟修复前）→ 暴露全放行/全锁死两种故障
 *   C. 新客户端 + 旧码（只有 features）→ legacy 映射到新模块
 *   D. 新客户端 + 新码（modules 为主）→ 模块级精确放行
 *
 * 运行：php api/tests/run_tests.php
 */
declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\License;

require_once __DIR__ . '/fixtures/LicenseLegacy.php';

class LicenseCompatTest extends \PHPUnit\Framework\TestCase
{
    /** 签发端：模块 → 旧功能名投影（与 shouquan/license_config.php 同源逻辑） */
    private static function legacyFeaturesFor(array $modules): array
    {
        $modules = array_values(array_filter(array_map('strval', $modules), static fn($m) => $m !== ''));
        if (empty($modules) || in_array('*', $modules, true)) {
            return ['*'];
        }
        $map = [
            'ai'        => ['ai'],
            'scheduled' => ['scheduled'],
            'dataops'   => ['export', 'stats', 'plugins'],
        ];
        $out = [];
        $hasModernOnly = false;
        foreach ($modules as $m) {
            if (isset($map[$m])) {
                foreach ($map[$m] as $legacy) {
                    if (!in_array($legacy, $out, true)) {
                        $out[] = $legacy;
                    }
                }
            } else {
                $hasModernOnly = true;
            }
        }
        if ($hasModernOnly) {
            return ['*'];
        }
        return empty($out) ? ['*'] : $out;
    }

    /**
     * 模拟旧客户端（功能制）的最终能力计算
     * 旧 License.php: verifyLocal/verifyRemote → normalizeFeatures() → applyTierFeatures()
     */
    private static function oldClientResolve(array $payload, string $type): array
    {
        // 旧客户端本地验签：只认 payload['features']
        $features = $payload['features'] ?? ['*'];
        $norm = \Hublog\Legacy\License::normalizeFeatures($features);
        return \Hublog\Legacy\License::applyTierFeatures($type, $norm);
    }

    /**
     * 模拟新客户端（模块制）的最终能力计算
     * 新 License.php: payloadModules() → normalizeModules() → applyTierModules()
     */
    private static function newClientResolve(array $payload, string $type): array
    {
        $rc = new \ReflectionClass(License::class);
        $m = $rc->getMethod('payloadModules');
        $m->setAccessible(true);
        $modules = $m->invoke(null, $payload);
        $norm = License::normalizeModules($modules);
        return License::applyTierModules($type, $norm);
    }

    // ==================== A. 旧客户端 + 新码（已写 legacy 投影）====================

    public function testOldClientWithNewTrialKeyStillWorks(): void
    {
        // 【2026-11-08 说明】新语义下 trial 码是全功能（modules:['*']）。
        // 旧客户端用自己的 TIER_FEATURES['trial'] = ['chat','ai'] 收窄，
        // 所以旧站点上 trial 码仍只拿到 chat+ai —— 这是旧代码的固有行为，
        // 不代表新站点。旧站点的完整模块体验需要升级 License.php + LicenseMiddleware.php。
        // 本测试锁住的是「旧站点拿到 trial 码不会崩、不会全锁死」这条底线。
        $payload = ['type' => 'trial', 'modules' => ['*'], 'features' => self::legacyFeaturesFor(['*'])];
        $resolved = self::oldClientResolve($payload, 'trial');
        $this->assertTrue(in_array('ai', $resolved, true), '旧站点应保留 AI 功能（不崩、不全锁死）');
        $this->assertFalse(in_array('export', $resolved, true), '旧站点不应获得导出功能');
    }

    public function testOldClientWithNewBusinessKeyKeepsEverything(): void
    {
        $payload = ['type' => 'business', 'modules' => ['*'], 'features' => self::legacyFeaturesFor(['*'])];
        $resolved = self::oldClientResolve($payload, 'business');
        $this->assertTrue(in_array('*', $resolved, true), '商业版旧站点应全功能可用');
    }

    public function testOldClientWithNewModernOnlyKeyStaysUsable(): void
    {
        // 只勾了 circles（旧站点无法表达的新模块）→ 投影为 ['*'] → 旧站点不至于全锁死
        $payload = ['type' => 'business', 'modules' => ['circles'], 'features' => self::legacyFeaturesFor(['circles'])];
        $resolved = self::oldClientResolve($payload, 'business');
        $this->assertTrue(in_array('*', $resolved, true), '旧站点不应因新模块而把功能全锁死');
    }

    public function testOldClientWithNewDataopsKeyMapsToLegacyNames(): void
    {
        $payload = ['type' => 'business', 'modules' => ['dataops'], 'features' => self::legacyFeaturesFor(['dataops'])];
        $resolved = self::oldClientResolve($payload, 'business');
        $this->assertTrue(in_array('export', $resolved, true));
        $this->assertTrue(in_array('stats', $resolved, true));
        $this->assertTrue(in_array('plugins', $resolved, true));
    }

    // ==================== B. 修复前后的差异（防止回归）====================

    public function testMissingFeaturesFieldWouldBreakOldClient(): void
    {
        // 【回归锚点】若签发端不再写 features，旧客户端本地验签会拿到 ['*']：
        //   - business 档 → 全放行（签发端只勾了 ai 也照样全开 = 收费失效）
        //   - trial 档    → 被旧版 TIER_FEATURES['trial'] 收窄成 ['chat','ai']
        // 这个测试说明 features 字段是必需的，防止有人「优化」掉它。
        //
        // 【注意】这里的 trial 行为由【旧客户端代码】决定，与新版档位表无关：
        // 新版 TIER_MODULES['trial'] = ['*']（限时全功能），但旧站点跑的是旧 License.php，
        // 它的 TIER_FEATURES['trial'] = ['chat','ai'] 依旧生效。
        $payloadWithoutFeatures = ['type' => 'business', 'modules' => ['ai']];
        $resolved = self::oldClientResolve($payloadWithoutFeatures, 'business');
        $this->assertTrue(in_array('*', $resolved, true), '缺 features 时旧客户端会全放行（这是必须避免的故障）');

        $trialResolved = self::oldClientResolve(['type' => 'trial', 'modules' => ['ai']], 'trial');
        $this->assertNotContains('*', $trialResolved, 'trial 档缺 features 时会被旧版档位裁剪，不会全放行');
    }

    public function testOldClientRemoteModeWouldLockEverythingWithoutLegacyFeatures(): void
    {
        // 【回归锚点】修复前 remote_verify 的 features 回退是 modules 原样透传，
        // 旧客户端 KNOWN_FEATURES 白名单不认识模块名 → 全部丢弃 → 付费功能全锁死
        // （旧站点直接不可用）。现在签发端写 legacy 投影后，远程验签也能正常工作。
        $before = self::oldClientResolve(['type' => 'business', 'features' => ['circles']], 'business');
        $this->assertSame([], $before, '旧客户端读到模块名会被全部丢弃（这正是要避免的故障）');

        $after = self::oldClientResolve(
            ['type' => 'business', 'modules' => ['circles'], 'features' => self::legacyFeaturesFor(['circles'])],
            'business'
        );
        $this->assertTrue(in_array('*', $after, true), '写入 legacy 投影后旧站点应可正常使用');
    }

    // ==================== C. 新客户端 + 旧码 ====================

    public function testNewClientReadsLegacyFeatureCode(): void
    {
        // 旧码只有 features：[export,stats,plugins,ai]
        $payload = ['type' => 'business', 'features' => ['export', 'stats', 'plugins', 'ai']];
        $resolved = self::newClientResolve($payload, 'business');
        $this->assertTrue(in_array('dataops', $resolved, true), 'export/stats/plugins 应映射为 dataops');
        $this->assertTrue(in_array('ai', $resolved, true), 'ai 应保留');
    }

    public function testNewClientReadsOldTrialCode(): void
    {
        // 旧版 trial 码 features: [chat, ai]（chat 已废弃 → 不作门禁）
        $payload = ['type' => 'trial', 'features' => ['chat', 'ai']];
        $resolved = self::newClientResolve($payload, 'trial');
        $this->assertTrue(in_array('ai', $resolved, true));
        $this->assertFalse(in_array('chat', $resolved, true), 'chat 不作模块，应被丢弃');
    }

    public function testNewClientRejectsUnknownModuleNames(): void
    {
        // 未知名字 fail-closed：不得静默当成全功能
        $payload = ['type' => 'business', 'modules' => ['totally_bogus_module']];
        $resolved = self::newClientResolve($payload, 'business');
        $this->assertNotContains('*', $resolved, '未知模块名不得回落成全功能');
        $this->assertSame([], $resolved, '未知模块名应被丢弃（fail-closed）');
    }

    // ==================== D. 新客户端 + 新码 ====================

    public function testNewClientReadsNewModuleCode(): void
    {
        $payload = ['type' => 'business', 'modules' => ['circles', 'shop']];
        $resolved = self::newClientResolve($payload, 'business');
        $this->assertSame(['circles', 'shop'], $resolved, '模块应精确放行，不多不少');
    }

    public function testNewClientPrefersModulesOverFeatures(): void
    {
        // 同时带两字段时，modules 优先（单一来源原则）
        $payload = ['type' => 'business', 'modules' => ['shop'], 'features' => ['export', 'stats']];
        $resolved = self::newClientResolve($payload, 'business');
        $this->assertSame(['shop'], $resolved, 'modules 字段优先于 features');
    }

    public function testNewTrialCodeGrantsAllModules(): void
    {
        // 【2026-11-08 档位语义反转】试用版改为「限时全功能体验」：
        // 试用期只有 30 天 + 容量上限（20 用户 / 200 动态），本就是让客户完整体验
        // 全部模块再决定是否付费。若试用版还阉割模块，客户根本体验不到
        // 圈子/商城/积分等付费能力，30 天后拿不出续费理由。
        // 容量/有效期边界由 payload 的 max_users/max_posts/expires_at 控制，与模块维度无关。
        $payload = ['type' => 'trial', 'modules' => ['*'], 'features' => self::legacyFeaturesFor(['*'])];
        $resolved = self::newClientResolve($payload, 'trial');
        $this->assertSame(['*'], $resolved, 'trial 码应放行全部模块（限时体验）');
    }

    public function testTrialCodeKeepsItsOwnModuleList(): void
    {
        // trial 档上限是 '*' = 不限模块，所以码里签了哪些就放行哪些，不得被放大成全量，
        // 也不得被裁成空（array_intersect(['ai'], ['*']) = [] 会锁死全站）。
        $payload = ['type' => 'trial', 'modules' => ['ai', 'circles']];
        $resolved = self::newClientResolve($payload, 'trial');
        $this->assertSame(['ai', 'circles'], $resolved, 'trial 码按自身模块精确放行');
    }

    public function testPersonalTierCoreModules(): void
    {
        // personal 档上限 4 项可授权核心能力（ai / 定时发布 / 圈子 / 实名认证）。
        // 徽章 / 积分 / 内容治理自 2026-11 起改为免费核心能力（License::CORE_FREE_MODULES），
        // 不再占用 personal 档位额度、也不再出现在 KNOWN_MODULES 里。
        // 签成 '*' 的 personal 码必须被收窄 —— 这条同时是存量码下调的保障：
        // 存量 personal 码 payload 只有 features:['*']，走 payloadModules() 回退后
        // 同样命中这里，无需重签即可生效。
        $payload = ['type' => 'personal', 'modules' => ['*'], 'features' => self::legacyFeaturesFor(['*'])];
        $resolved = self::newClientResolve($payload, 'personal');
        $this->assertSame(
            ['ai', 'scheduled', 'circles', 'realname'],
            $resolved,
            'personal 码签成全量时应被收窄到 4 项可授权核心能力'
        );
    }

    public function testPersonalTierDropsPaidExtras(): void
    {
        // personal 码里混入了商城/财务/活动/数据运维 → 必须被裁掉
        $payload = ['type' => 'personal', 'modules' => ['ai', 'shop', 'finance', 'activities', 'dataops']];
        $resolved = self::newClientResolve($payload, 'personal');
        $this->assertSame(['ai'], $resolved, 'personal 码不得放行商业化模块');
    }

    public function testBusinessAndEnterpriseKeepAllModules(): void
    {
        foreach (['business', 'enterprise'] as $type) {
            $payload = ['type' => $type, 'modules' => ['*'], 'features' => self::legacyFeaturesFor(['*'])];
            $resolved = self::newClientResolve($payload, $type);
            $this->assertSame(['*'], $resolved, "{$type} 档应放行全部模块");
        }
    }

    public function testTierCapNeverLocksEverythingToEmpty(): void
    {
        // 【回归锚点】档位上限含 '*' 时若直接丢给 array_intersect，
        // array_intersect(['ai'], ['*']) = [] → 全站模块被裁成空 → 功能全锁死。
        // 这里锁死各档位「裁剪结果不得为空数组」这一不变量。
        $rc = new \ReflectionClass(License::class);
        $m = $rc->getMethod('applyTierModules');
        $m->setAccessible(true);
        foreach (['trial', 'personal', 'business', 'enterprise'] as $type) {
            foreach ([['*'], ['ai'], ['ai', 'circles']] as $mods) {
                $resolved = $m->invoke(null, $type, $mods);
                $this->assertTrue(
                    is_array($resolved) && count($resolved) > 0,
                    "{$type} 档裁剪结果不得为空（会锁死全站功能），实际: " . json_encode($resolved)
                );
            }
        }
    }
}
