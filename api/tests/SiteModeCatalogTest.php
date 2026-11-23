<?php
/**
 * 站点模式 · 模块注册表回归护栏（2026-11-14）
 *
 * 这些断言锚定的是最容易「改一处漏一处」的约定：
 * 缺省全开、依赖硬边、预设反查、长前缀优先、中间件短路、专用保存分支。
 * 运行：php -d disable_functions="" vendor/bin/phpunit -c api/phpunit.xml
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/ModuleCatalog.php';

use Hublog\Core\ModuleCatalog as M;
use PHPUnit\Framework\TestCase;

final class SiteModeCatalogTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function src(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    public function testCatalogShape(): void
    {
        $this->assertCount(15, M::MODULES);
        $this->assertCount(13, M::ALWAYS_ON_PAGES);
        $this->assertCount(29, M::pageMap(M::defaults()));
        $this->assertCount(29, array_filter(M::pageMap(M::defaults())));
    }

    public function testDefaultIsAllOn(): void
    {
        $this->assertSame(array_fill_keys(array_keys(M::MODULES), 1), M::normalize(null));
        $this->assertSame(M::normalize(null), M::normalize(''));
    }

    public function testNormalizeDropsUnknownAndFillsMissing(): void
    {
        $flags = M::normalize(['topics' => 0, 'not_a_module' => 1]);
        $this->assertSame(0, $flags['topics']);
        $this->assertSame(1, $flags['circles']);
        $this->assertArrayNotHasKey('not_a_module', $flags);
        $this->assertCount(15, $flags);
    }

    public function testBadJsonIsRejected(): void
    {
        $this->assertNotNull(M::parse('{oops')['error']);
        $this->assertNull(M::parse('{"topics":0}')['error']);
        $this->assertNull(M::parse(['topics' => 0])['error']);
    }

    public function testPresetsRoundTrip(): void
    {
        foreach (array_keys(M::PRESETS) as $mode) {
            $flags = M::presetFlags($mode);
            $this->assertSame($mode, M::detectMode($flags), $mode . ' 预设应能反查自身');
            $this->assertNull(M::validate($flags), $mode . ' 预设不应违反依赖');
        }
        $custom = M::presetFlags('blog');
        $custom['topics'] = 1;
        $this->assertSame('custom', M::detectMode($custom));
    }

    public function testDependenciesAreHardEdges(): void
    {
        $withoutFinance = M::presetFlags('social');
        $withoutFinance['finance'] = 0;
        $this->assertStringContainsString('财务会员', (string)M::validate($withoutFinance));

        $withoutReports = M::presetFlags('blog');
        $withoutReports['reports'] = 0;
        $this->assertStringContainsString('举报管理', (string)M::validate($withoutReports));

        // 关闭下游后可以关依赖
        $withoutFinance['shop'] = 0;
        $withoutReports['appeals'] = 0;
        $this->assertNull(M::validate($withoutFinance));
        $this->assertNull(M::validate($withoutReports));
    }

    public function testPageMapFollowsFlags(): void
    {
        $blog = M::pageMap(M::presetFlags('blog'));
        $this->assertTrue($blog['dashboard']);
        $this->assertTrue($blog['comments']);
        $this->assertTrue($blog['sensitive-words']);
        $this->assertTrue($blog['filter-logs']);
        $this->assertFalse($blog['topics']);
        $this->assertFalse($blog['shop']);
        $this->assertCount(19, array_filter($blog));
    }

    public function testLongestRoutePrefixWins(): void
    {
        $prefixes = M::routePrefixes();
        $this->assertSame('shop', $prefixes['/api/points/wheel']);
        $this->assertSame('finance', $prefixes['/api/points/']);
        $this->assertGreaterThan(
            strlen('/api/points/'),
            strlen((string)array_key_first($prefixes))
        );
    }

    /** FeatureGuard 必须返回 Response 短路，不能抛 4230 异常（会被当非法 HTTP 状态） */
    public function testGuardNeverThrows4230(): void
    {
        $guard = self::src('api/middleware/FeatureGuardMiddleware.php');
        $this->assertStringContainsString('Response::error(\'该功能未开启\', 4230)', $guard);
        $this->assertStringNotContainsString('throw new HublogException', $guard);
        $this->assertStringContainsString('/api/pay/notify/', $guard);
        $this->assertStringContainsString('/api/admin/', $guard);

        $router = self::src('api/core/Router.php');
        $this->assertStringContainsString('$shortCircuit instanceof Response', $router);
    }

    /** 三键必须走专用分支，不能进 Security::purify 的 (string) 强转循环 */
    public function testSettingsHasDedicatedSaveBranch(): void
    {
        $controller = self::src('api/controllers/AdminSettingsController.php');
        $this->assertStringContainsString('saveSiteMode', $controller);
        $this->assertStringContainsString('module_catalog', $controller);
        $this->assertStringContainsString('FeatureFlags::publicPayload()', $controller);
    }

    /** cron 跳过逻辑必须能真正到达控制器：CSRF 白名单要放行 /cron/badges */
    public function testCronSkipAndCsrfWhitelist(): void
    {
        $badge = self::src('api/controllers/BadgeController.php');
        $this->assertStringContainsString("FeatureFlags::enabled('badges')", $badge);
        $this->assertStringContainsString("'skipped' => true", $badge);
        $this->assertStringContainsString("'reason' => 'module_disabled'", $badge);

        $csrf = self::src('api/middleware/CsrfMiddleware.php');
        $this->assertStringContainsString("'/api/cron/badges'", $csrf);
        $this->assertStringContainsString("'/api/cron/jobs'", $csrf);
    }

    /** 缓存指纹 6 处必须同套同值 */
    public function testCacheFingerprintsAreInSync(): void
    {
        $index = self::src('admin/index.html');
        $main = self::src('admin/js/main.js');
        $nav = self::src('admin/js/nav-ui.js');
        preg_match_all('/\?v=(\d+)/', $index, $a);
        preg_match_all('/\?v=(\d+)/', $main, $b);
        preg_match("/PAGE_VER = '(\d+)'/", $nav, $c);
        $versions = array_merge($a[1], $b[1], [$c[1] ?? '']);
        $this->assertCount(6, $versions);
        $this->assertCount(1, array_unique($versions), '6 处版本号必须完全一致');
    }
}
