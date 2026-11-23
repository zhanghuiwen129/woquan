<?php
/**
 * P2-1 路由表断言：防止再次出现 V142 同类的「路由 handler 拼写错误」。
 *
 * 做法：
 *   1. 静态解析 routes.php 中所有 [Controller::class, 'method'] handler（不执行框架，避免运行时依赖）；
 *   2. 软反射校验类存在、方法存在——类文件若因缺少运行时依赖无法加载则跳过该条，不误报；
 *   3. 粗略检测同文件内字面重复的路由 path（group 前缀未拼接）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class RoutesAssertTest extends TestCase
{
    private function routesFile(): string
    {
        return dirname(__DIR__) . '/routes.php';
    }

    /**
     * V165 重写：按 group 前缀拼出【完整路径】，返回 [method, fullPath, class, method] 列表。
     *
     * 原因：原实现只比对「文件内字面 path」，而 routes.php 里有 5 个 group 分组，
     * 同一个字面路径可能合法地出现在不同分组下（如 /api/user/privacy 与 /api/admin/user/privacy），
     * 旧逻辑会把它误报成重复路由（假失败）。只有「HTTP 方法 + 完整路径」完全相同才是真重复。
     *
     * @return array<int,array{verb:string,path:string,class:string,method:string}>
     */
    private function parseRoutesWithPrefix(): array
    {
        $src = (string)file_get_contents($this->routesFile());
        $lines = explode("\n", $src);

        /** @var array<int,array{prefix:string,depth:int}> $stack */
        $stack = [];
        $depth = 0;
        $routes = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $currentPrefix = implode('', array_column($stack, 'prefix'));

            // 1) 路由注册（使用进入该行时的前缀；group 首行自身的 '{' 尚未计入）
            if (preg_match(
                '/\$router->(get|post|put|delete|patch|any)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[\s*([A-Za-z_\\\\]+)::class\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\]/',
                $line,
                $m
            )) {
                $routes[] = [
                    'verb'   => strtoupper($m[1]),
                    'path'   => $currentPrefix . $m[2],
                    'class'  => $m[3],
                    'method' => $m[4],
                ];
            }

            // 2) group 起始：记录打开时的括号深度
            if (preg_match('/^\s*\$router->group\(\s*[\'"]([^\'"]+)[\'"]\s*,/', $trimmed, $gm)) {
                $stack[] = ['prefix' => $gm[1], 'depth' => $depth];
            }

            // 3) 括号平衡推进，并弹出已闭合的分组
            $delta = substr_count($line, '{') - substr_count($line, '}');
            $depth += $delta;
            while (!empty($stack) && $stack[count($stack) - 1]['depth'] >= $depth) {
                array_pop($stack);
            }
        }

        return $routes;
    }

    /** 保持向后兼容：供 handler 断言继续使用 */
    private function parseHandlers(): array
    {
        $routes = $this->parseRoutesWithPrefix();
        return array_map(
            static fn(array $r): array => ['class' => $r['class'], 'method' => $r['method']],
            $routes
        );
    }

    public function testNoDuplicateLiteralPaths(): void
    {
        $routes = $this->parseRoutesWithPrefix();
        $this->assertNotEmpty($routes, 'routes.php 应至少解析到一条路由');

        $seen = [];
        $duplicates = [];
        foreach ($routes as $r) {
            $key = $r['verb'] . ' ' . $r['path'];
            if (isset($seen[$key])) {
                $duplicates[] = $key . "  ({$r['class']}::{$r['method']})";
            }
            $seen[$key] = true;
        }

        $this->assertEmpty(
            $duplicates,
            "检测到「HTTP 方法 + 完整路径」完全相同的重复路由（已拼接 group 前缀）:\n"
            . implode("\n", array_unique($duplicates))
        );
    }

    public function testAllRouteHandlersResolve(): void
    {
        $handlers = $this->parseHandlers();
        $this->assertNotEmpty($handlers, 'routes.php 应至少解析到一个 handler');

        $realFailures = [];
        $skipped = [];
        foreach ($handlers as $h) {
            $class = $h['class'];
            $method = $h['method'];
            try {
                if (!class_exists($class, true)) {
                    $realFailures[] = "类不存在: {$class}";
                    continue;
                }
                if (!method_exists($class, $method)) {
                    $realFailures[] = "方法不存在: {$class}::{$method}";
                }
            } catch (\Throwable $e) {
                // 类文件因缺少运行时依赖无法加载：跳过（不误报），仅记录
                $skipped[] = "加载跳过: {$class} -> " . $e->getMessage();
            }
        }

        // 路由 handler 拼写错误必须失败；仅"加载失败"类警告不阻断
        $this->assertEmpty(
            $realFailures,
            "路由 handler 校验失败:\n" . implode("\n", $realFailures)
            . (empty($skipped) ? '' : "\n(已跳过 " . count($skipped) . " 个无法加载的类)")
        );
    }

    /**
     * P2-3 守卫：/api/v1 前缀被 Router 自动重写为 /api（版本外壳），
     * 若有人在 routes.php 显式注册 /api/v1/... 路由，会被自动剥离前缀导致 404 或遮蔽。
     * 该前缀为保留前缀，禁止手动注册；真正的版本分叉应走 /api/v2。
     *
     * V165：改为基于「拼接 group 前缀后的完整路径」判定，比字面 path 更准确。
     */
    public function testNoExplicitV1Routes(): void
    {
        $routes = $this->parseRoutesWithPrefix();
        $violations = [];
        foreach ($routes as $r) {
            if (str_starts_with($r['path'], '/api/v1')) {
                $violations[] = $r['verb'] . ' ' . $r['path'];
            }
        }
        $this->assertEmpty(
            $violations,
            "/api/v1 为保留前缀（Router 自动重写为 /api），禁止显式注册，改走 /api/v2:\n"
            . implode("\n", array_unique($violations))
        );
    }

    /**
     * 运维探针路由必须两种形态都注册：
     *   - /health、/metrics：nginx `location = /xxx` 直转 api/index.php 时 REQUEST_URI 不变，
     *     Router 解析出的路径不带 /api 前缀；
     *   - /api/health、/api/metrics：PATH_INFO 或已配伪静态重写时带 /api 前缀。
     *
     * 历史 bug：只注册了 /health，导致监控按 /api/health 打时拿到前台首页 HTML（404 fallback）。
     */
    public function testHealthAndMetricsRoutesRegisterBothForms(): void
    {
        $routes = $this->parseRoutesWithPrefix();
        $indexed = [];
        foreach ($routes as $r) {
            if ($r['verb'] === 'GET') {
                $indexed[$r['path']] = $r['class'] . '::' . $r['method'];
            }
        }
        $expected = [
            '/health'      => 'HealthController::check',
            '/api/health'  => 'HealthController::check',
            '/metrics'     => 'MetricsController::metrics',
            '/api/metrics' => 'MetricsController::metrics',
        ];
        foreach ($expected as $path => $handler) {
            $this->assertArrayHasKey($path, $indexed, "缺少运维探针路由 GET {$path}");
            $this->assertStringEndsWith($handler, $indexed[$path], "GET {$path} 的 handler 错误");
        }
    }
}
