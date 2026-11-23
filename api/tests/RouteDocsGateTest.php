<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Docs\RouteDocChecker;
use Hublog\Docs\RouteParser;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/parse_routes.php';
require_once dirname(__DIR__, 2) . '/scripts/check_route_docs.php';

/**
 * G5 文档门禁测试（批次 0）
 *
 * 覆盖四件事：
 *   1. 真实 routes.php 能解析出全量路由，且 group 前缀 / 中间件还原正确；
 *   2. 合成的嵌套 group 源码能逐条还原 method/path/middlewares；
 *   3. 基线外新增缺失路由 → 对账退出码 1，并定位到具体 METHOD /path；
 *   4. baseline_count 只减不增：缩小基线即红灯，允许差异被冻结则绿灯。
 */
final class RouteDocsGateTest extends TestCase
{
    /** 仓库根目录 */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** 真实路由解析：数量与已知路由（含 group 前缀 / 中间件） */
    public function testParsesRealRoutesAndKnownEndpoints(): void
    {
        $routes = RouteParser::parseFile(self::root() . '/api/routes.php');
        $this->assertGreaterThanOrEqual(400, count($routes), '当前 routes.php 应能解析出 400 条以上路由');

        $index = [];
        foreach ($routes as $route) {
            $index[$route['method'] . ' ' . $route['path']] = $route['middlewares'];
        }
        $this->assertArrayHasKey('GET /api/csrf', $index, '顶层路由应被解析');
        $this->assertArrayHasKey('GET /health', $index, 'group 外路由应被解析');
        $this->assertArrayHasKey('POST /api/auth/login', $index, '/api group 前缀应还原');
        $this->assertArrayHasKey('GET /api/admin/dashboard', $index, '/api/admin group 前缀应还原');
        $this->assertContains('RateLimitMiddleware', $index['GET /api/csrf']);
        $this->assertContains('AdminMiddleware', $index['GET /api/admin/dashboard']);
    }

    /** 合成源码：嵌套 group 前缀叠加、组级与路由级中间件合并 */
    public function testRestoresNestedGroupPrefixAndMiddlewares(): void
    {
        $source = implode("\n", [
            '<?php',
            "\$router->get('/top', [Ctrl::class, 'top']);",
            "\$router->group('/api', function (\$router) {",
            "    \$router->post('/login', [Ctrl::class, 'login'], ['RouteLevel']);",
            "    \$router->group('/admin', function (\$router) {",
            "        \$router->delete('/users', [Ctrl::class, 'del']);",
            "    }, ['AdminMiddleware']);",
            "}, ['RateLimitMiddleware', 'AuthMiddleware']);",
        ]);

        $routes = RouteParser::parse($source);
        $this->assertCount(3, $routes);
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/top', $routes[0]['path']);
        $this->assertSame([], $routes[0]['middlewares']);

        $this->assertSame('POST', $routes[1]['method']);
        $this->assertSame('/api/login', $routes[1]['path']);
        $this->assertSame(
            ['RateLimitMiddleware', 'AuthMiddleware', 'RouteLevel'],
            $routes[1]['middlewares']
        );

        $this->assertSame('DELETE', $routes[2]['method']);
        $this->assertSame('/api/admin/users', $routes[2]['path']);
        $this->assertSame(
            ['RateLimitMiddleware', 'AuthMiddleware', 'AdminMiddleware'],
            $routes[2]['middlewares']
        );
    }

    /** OpenAPI 逐行解析：path 与其下的 method */
    public function testExtractsOpenApiPathsAndMethods(): void
    {
        $yaml = (string)file_get_contents(self::root() . '/docs/openapi.yaml');
        $documented = RouteDocChecker::openApiMethods($yaml);
        $this->assertGreaterThanOrEqual(300, count($documented), '应解析出当前 openapi.yaml 的 path 集合');
        $this->assertArrayHasKey('/api/csrf', $documented);
        $this->assertContains('get', $documented['/api/csrf']);
        $this->assertArrayHasKey('/api/auth/login', $documented);
        $this->assertContains('post', $documented['/api/auth/login']);
    }

    /** 基线外新增缺失 → 退出码 1，且报告定位到 METHOD /path */
    public function testUndocumentedRouteFailsGate(): void
    {
        $dir = $this->makeFixtureDir();
        try {
            [$routes, $openapi, $baseline] = $this->writeFixtures($dir, '10', []);
            $result = RouteDocChecker::evaluate($routes, $openapi, $baseline);

            $this->assertSame(1, $result['exit_code']);
            $this->assertSame(['POST /api/undocumented'], $result['unexpected']);

            $output = '';
            $code = $this->runCli([
                self::root() . '/scripts/check_route_docs.php',
                '--routes=' . $routes,
                '--openapi=' . $openapi,
                '--baseline=' . $baseline,
                '--json',
            ], $output);
            $this->assertSame(1, $code, 'CLI 在基线外缺失时须退出 1');
            $this->assertStringContainsString('POST /api/undocumented', $output);
        } finally {
            $this->removeFixtureDir($dir);
        }
    }

    /** baseline_count 只减不增 */
    public function testBaselineCountNeverGrows(): void
    {
        $dir = $this->makeFixtureDir();
        try {
            [$routes, $openapi, $baseline] = $this->writeFixtures(
                $dir,
                '1',
                ['POST /api/undocumented']
            );
            $this->assertSame(
                0,
                RouteDocChecker::evaluate($routes, $openapi, $baseline)['exit_code'],
                '差异已被基线允许且未增长时应通过'
            );

            [$routes, $openapi, $baseline] = $this->writeFixtures(
                $dir,
                '0',
                ['POST /api/undocumented']
            );
            $result = RouteDocChecker::evaluate($routes, $openapi, $baseline);
            $this->assertSame(1, $result['exit_code'], '基线记录值调小后应拒绝（差异只减不增）');
            $this->assertSame([], $result['unexpected']);
            $this->assertNotEmpty($result['reasons']);
        } finally {
            $this->removeFixtureDir($dir);
        }
    }

    /** --write-baseline 必须用真实差异生成，不得手写数字 */
    public function testWriteBaselineUsesRealDiff(): void
    {
        $dir = $this->makeFixtureDir();
        try {
            [$routes, $openapi, $baseline] = $this->writeFixtures($dir, '99', []);
            $output = '';
            $code = $this->runCli([
                self::root() . '/scripts/check_route_docs.php',
                '--write-baseline',
                '--routes=' . $routes,
                '--openapi=' . $openapi,
                '--baseline=' . $baseline,
            ], $output);
            $this->assertSame(0, $code);

            $written = json_decode((string)file_get_contents($baseline), true);
            $this->assertIsArray($written);
            $this->assertSame(1, $written['baseline_count']);
            $this->assertSame(['POST /api/undocumented'], $written['allowed_missing']);
            $this->assertSame(2, $written['route_count']);
            $this->assertSame(1, $written['openapi_path_count']);

            $this->assertSame(0, RouteDocChecker::evaluate($routes, $openapi, $baseline)['exit_code']);
        } finally {
            $this->removeFixtureDir($dir);
        }
    }

    /** 创建隔离的临时目录 */
    private function makeFixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/route_docs_' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('无法创建临时目录: ' . $dir);
        }
        return $dir;
    }

    /** 写一份最小的 routes / openapi / baseline 三件套 */
    private function writeFixtures(string $dir, string $baselineCount, array $allowed): array
    {
        $routes = $dir . '/routes.php';
        $openapi = $dir . '/openapi.yaml';
        $baseline = $dir . '/baseline.json';

        file_put_contents($routes, implode("\n", [
            '<?php',
            "\$router->get('/api/known', [Ctrl::class, 'known']);",
            "\$router->post('/api/undocumented', [Ctrl::class, 'missing']);",
            '',
        ]));
        file_put_contents($openapi, implode("\n", [
            'openapi: 3.0.3',
            'paths:',
            '  /api/known:',
            '    get:',
            '      responses: {}',
            'components:',
            '  schemas: {}',
            '',
        ]));
        file_put_contents($baseline, (string)json_encode([
            'baseline_count' => (int)$baselineCount,
            'allowed_missing' => $allowed,
        ]));

        return [$routes, $openapi, $baseline];
    }

    /** 清理临时目录 */
    private function removeFixtureDir(string $dir): void
    {
        foreach (['routes.php', 'openapi.yaml', 'baseline.json'] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** 调 CLI 并拿退出码（同时用于验证退出码语义） */
    private function runCli(array $args, string &$output): int
    {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $lines = [];
        $code = 0;
        exec($command, $lines, $code);
        $output = implode("\n", $lines);
        return $code;
    }
}
