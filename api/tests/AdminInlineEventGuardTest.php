<?php
/**
 * 后台管理内联事件委托化护栏（2026-11-12，B6 收尾）
 *
 * 背景：B1–B6 把 admin/index.html 与 admin/js/*.js 的全部内联事件
 * （onclick / onchange / oninput / onkeydown / onkeypress / onsubmit / onerror）
 * 委托到 core.js 的统一分发器（data-action / data-change / data-input / data-keydown），
 * 并拆除 main.js 的 window 全局挂载。本测试把该状态锚定为护栏，防止改回去：
 *   - 任何一处内联事件回流都会被拦截（这也是日后开启严格 CSP script-src 'self' 的前提）
 *   - 动态函数名分页（${fn.name} 拼 onclick 走 window）不得回流
 *
 * 说明：与仓库既有结构护栏同风格，纯源码文本断言，不依赖数据库。
 */

declare(strict_types=1);

namespace Hublog\Tests;

class AdminInlineEventGuardTest extends \PHPUnit\Framework\TestCase
{
    /** 全部会被严格 CSP script-src 'self' 拦截的内联事件属性 */
    private const INLINE_EVENTS =
        'onclick|onchange|oninput|onkeydown|onkeypress|onkeyup|onsubmit|onerror'
        . '|onload|onblur|onfocus|onmouseover|onmouseout|ondblclick|oncontextmenu';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    private static function inlineEventHits(string $src): array
    {
        preg_match_all('/\b(' . self::INLINE_EVENTS . ')\s*=/i', $src, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    public function testIndexHtmlHasNoInlineEventHandlers(): void
    {
        $hits = self::inlineEventHits(self::read('admin/index.html'));
        $this->assertSame([], $hits, 'admin/index.html 存在内联事件: ' . implode(', ', $hits));
    }

    public function testAdminPageFragmentsHaveNoInlineEventHandlers(): void
    {
        // P2-1：27 个 page 片段由 showPage fetch 后 innerHTML 挂载，同样不得有内联事件
        $dir = self::root() . '/admin/pages';
        $this->assertDirectoryExists($dir, 'admin/pages 片段目录缺失（拆分未完成或被误删）');
        $bad = [];
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            $hits = self::inlineEventHits((string)file_get_contents($file));
            if ($hits !== []) {
                $bad[] = basename($file) . ': ' . implode(', ', $hits);
            }
        }
        $this->assertSame([], $bad, 'admin/pages 片段存在内联事件: ' . implode('; ', $bad));
    }

    /**
     * P2-1 片段完整性：片段数/根节点/游离弹窗归属/主骨架白名单/懒加载 import 白名单/
     * showPage 映射表与片段文件集合一致性。
     */
    public function testAdminFragmentIntegrity(): void
    {
        $root = self::root();
        $pagesDir = $root . '/admin/pages';
        $expected = ['users','posts','comments','reports','appeals','attachments','friend-links',
            'shop','app-versions','crash-logs','activities','categories','settings','stats',
            'featured','finance','topics','realname','badges','sensitive-words','filter-logs',
            'circles','plugins','export','pages','plugin-settings','upgrade','license','chat','content-pay','app-center'];

        // 1. 片段文件集合与预期一致（防漏页/多页）
        $actual = array_map(fn($f) => basename($f, '.html'), glob($pagesDir . '/*.html') ?: []);
        sort($actual);
        $expectedSorted = $expected;
        sort($expectedSorted);
        $this->assertSame($expectedSorted, $actual, '片段文件集合与预期 page 清单不一致');

        // 2. 每个片段包含对应 page 根容器；两个多根片段的游离弹窗归属正确
        foreach ($expected as $page) {
            $src = self::read('admin/pages/' . $page . '.html');
            $this->assertMatchesRegularExpression('/id="page-' . $page . '"/', $src,
                "片段 {$page}.html 缺少 #page-{$page} 根容器");
        }
        $this->assertStringContainsString('id="crash-detail-mask"', self::read('admin/pages/crash-logs.html'),
            'crash-detail-mask 应归属 crash-logs 片段');
        $this->assertStringContainsString('id="point-task-form"', self::read('admin/pages/finance.html'),
            'point-task-form 应归属 finance 片段');
        foreach (['crash-detail-mask', 'point-task-form'] as $mask) {
            $this->assertStringNotContainsString('id="' . $mask . '"', self::read('admin/index.html'),
                "游离弹窗 {$mask} 不应残留在主骨架");
        }

        // 3. 主骨架：仅保留 page-dashboard（排除 page-title/page-host 两个骨架级元素）；片段无 script 标签
        $index = self::read('admin/index.html');
        preg_match_all('/id="page-([\w\-]+)"/', $index, $m);
        $pagesInIndex = array_values(array_filter($m[1], fn($p) => !in_array($p, ['title', 'host'], true)));
        $this->assertSame(['dashboard'], array_unique($pagesInIndex),
            '主骨架只应保留 page-dashboard，其余 page 容器必须拆为片段');
        $this->assertStringContainsString('id="page-host"', $index, '主骨架缺少片段挂载宿主 #page-host');
        foreach ($expected as $page) {
            // 剥离 HTML 属性值后再匹配（settings.html 的 textarea placeholder 文本
            // 含 "<script>..." 字面量，属展示示例而非真实标签）
            $stripped = (string)preg_replace('/="[^"]*"/', '=""', self::read('admin/pages/' . $page . '.html'));
            $this->assertStringNotContainsString('<script', $stripped,
                "片段 {$page}.html 不得包含真实 script 标签（严格 CSP 与挂载安全）");
        }

        // 4. 懒加载 import 白名单：main.js 只留 3 个静态模块；nav-ui.js 只留 core/dashboard
        $main = self::read('admin/js/main.js');
        $nav  = self::read('admin/js/nav-ui.js');
        $this->assertSame(3, preg_match_all('/^import /m', $main),
            'main.js 静态 import 应只有 core/nav-ui/dashboard（防全量加载回退）');
        $this->assertStringNotContainsString("from './settings.js'", $main, 'settings 初始化已迁入 initSettingsPage，main.js 不应再引用');
        $navStatic = preg_match_all('/^import .*from/m', $nav);
        $this->assertSame(2, $navStatic, 'nav-ui.js 静态 import 应只有 core/dashboard（其余必须走 pageLoaders 动态加载）');

        // 5. showPage 映射表键集合 == titles 表键集合（防漏页）
        // 只截取 const pageLoaders = {...} 块（registerActions/pageInitHooks 同缩进，须排除）
        $pStart = strpos($nav, 'const pageLoaders');
        $pEnd = strpos($nav, '};', $pStart);
        $this->assertNotFalse($pStart, 'nav-ui.js 缺少 pageLoaders 映射表');
        $block = substr($nav, $pStart, $pEnd - $pStart);
        preg_match_all("/^\s{4}(?:'([\w\-]+)'|(\w+)):\s*\(\)/m", $block, $keys);
        $mapped = array_map(fn($a, $b) => $a !== '' ? $a : $b, $keys[1], $keys[2]);
        sort($mapped);
        // pageLoaders 覆盖 27 个片段页 + dashboard（骨架首屏，无片段文件）
        $expectedPlusDash = array_merge($expectedSorted, ['dashboard']);
        sort($expectedPlusDash);
        $this->assertSame($expectedPlusDash, array_values(array_unique($mapped)),
            'nav-ui pageLoaders 映射表与片段/page 清单不一致');
    }

    public function testAdminJsModulesHaveNoInlineEventHandlers(): void
    {
        $dir = self::root() . '/admin/js';
        $this->assertDirectoryExists($dir);
        $bad = [];
        foreach (glob($dir . '/*.js') ?: [] as $file) {
            $hits = self::inlineEventHits((string)file_get_contents($file));
            if ($hits !== []) {
                $bad[] = basename($file) . ': ' . implode(', ', $hits);
            }
        }
        $this->assertSame([], $bad, 'admin/js 存在内联事件: ' . implode('; ', $bad));
    }

    public function testMainJsNoLongerMountsModulesOnWindow(): void
    {
        $main = self::read('admin/js/main.js');
        $this->assertStringNotContainsString('__mods', $main, 'main.js 不应再有模块挂载循环');
        $this->assertStringNotContainsString('window[k]', $main, 'main.js 不应再把导出挂到 window');
    }

    public function testRenderPaginationUsesFunctionReferenceOnly(): void
    {
        $core = self::read('admin/js/core.js');
        $this->assertStringNotContainsString('window[callback]', $core, 'renderPagination 的 callback 不再支持字符串（走 window）');
    }

    public function testModerationPaginationHasNoDynamicFunctionName(): void
    {
        $mod = self::read('admin/js/moderation.js');
        $this->assertStringNotContainsString('${fn.name}', $mod, '分页按钮不得再拼动态函数名');
    }

    public function testAdminStrictCspConfigured(): void
    {
        // B6 完成后后台已启用严格 CSP（script-src 'self'），三套部署配置不得回退
        $needles = ["object-src 'none'", "script-src 'self'"];
        foreach (['nginx.conf', 'Caddyfile', 'admin/.htaccess'] as $conf) {
            $src = self::read($conf);
            $this->assertStringContainsString('Content-Security-Policy', $src, $conf . ' 缺少 CSP 头');
            foreach ($needles as $n) {
                $this->assertStringContainsString($n, $src, $conf . ' CSP 缺少 ' . $n);
            }
        }
    }

    public function testAdminAssetsCacheControlAndVersionParam(): void
    {
        // Apache 部署下后台资源必须禁用强缓存（P0-1）
        $ht = self::read('admin/.htaccess');
        $this->assertStringContainsString('Cache-Control "no-cache"', $ht);
        // 入口资源引用带版本参数双保险
        $html = self::read('admin/index.html');
        $this->assertMatchesRegularExpression('/main\.js\?v=\d+/', $html, 'index.html 的 main.js 引用应带 ?v= 版本参数');
        $this->assertMatchesRegularExpression('/admin\.css\?v=\d+/', $html, 'index.html 的 admin.css 引用应带 ?v= 版本参数');
    }

    /**
     * P1-3 / P2-3：JS 静态审计（跨模块裸引用 / window 白名单 / 未转义文本插值）
     * 逻辑同 scripts/audit-admin-js.php，可直接 php scripts/audit-admin-js.php 看明细。
     */
    public function testAdminJsAuditClean(): void
    {
        require_once self::root() . '/scripts/AdminJsAuditor.php';
        $auditor = new \AdminJsAuditor(self::root() . '/admin/js');

        $bare = $auditor->bareCrossModuleRefs();
        $this->assertSame([], $bare, '存在未 import 的跨模块裸引用（拆 window 后 ReferenceError）: '
            . json_encode($bare, JSON_UNESCAPED_UNICODE));

        $win = $auditor->unsafeWindowAccess();
        $this->assertSame([], $win, '存在 window 白名单外调用: ' . json_encode($win, JSON_UNESCAPED_UNICODE));

        $esc = $auditor->unescapedTextInterpolations();
        $this->assertSame([], $esc, '存在疑似未转义的文本插值（XSS 盲区）: '
            . json_encode($esc, JSON_UNESCAPED_UNICODE));
    }
}
