<?php
/**
 * 批次8 P0-2：插件路由中间件基线的反射审计 + canary。
 *
 * 根因：routes_register 钩子在所有 group 闭包执行完后触发，groupStack 为空，
 * 插件直接 $router->get() 得到 middleware=[]。这里同时守住三层：
 *   1. index.php 把钩子包进空前缀 group，基线取公开组同款且不含 Auth；
 *   2. 两个 AI 插件的真实路由经过框架基线后 middleware 非空且含 RateLimit/License；
 *   3. canary：临时注册一个不传 middleware 的假插件路由，框架基线仍使其非空。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Router;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PluginRouteMiddlewareBehaviorTest extends TestCase
{
    private const BASELINE = ['RateLimitMiddleware', 'CsrfMiddleware', 'LicenseMiddleware', 'FeatureGuardMiddleware'];

    /** @return array<string,list<string>> method+path => middleware */
    private function routes(Router $router): array
    {
        $ref = new ReflectionProperty(Router::class, 'routes');
        $ref->setAccessible(true);
        $out = [];
        foreach ($ref->getValue($router) as $method => $paths) {
            foreach ($paths as $path => $route) {
                $out[$method . ' ' . $path] = $route['middleware'];
            }
        }
        return $out;
    }

    private function loadPlugins(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['SmartSearch', 'SmartRecommend'] as $name) {
            $file = $root . '/plugins/' . $name . '/Plugin.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    public function testIndexWrapsRouteHookInPublicBaselineGroup(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $this->assertStringContainsString("\$router->group('', function (\$router) {", $src);
        $this->assertStringContainsString("Hook::doAction('routes_register', \$router);", $src);
        $this->assertStringContainsString(implode("', '", self::BASELINE), $src);

        // group 基线不能含 Auth：公开检索允许游客，可见性由 VisibilityPolicy 保证。
        $start = strpos($src, "\$router->group('', function (\$router) {");
        $this->assertNotFalse($start);
        $segment = substr($src, $start, 600);
        $this->assertStringNotContainsString('AuthMiddleware', $segment);
    }

    public function testAllPluginRoutesHaveBaselineMiddleware(): void
    {
        $this->loadPlugins();
        $router = new Router();
        $router->group('', function (Router $router): void {
            \Hublog\Plugin\SmartSearch\Plugin::registerRoutes($router);
            \Hublog\Plugin\SmartRecommend\Plugin::registerRoutes($router);
        }, self::BASELINE);

        $routes = $this->routes($router);
        $this->assertArrayHasKey('GET /api/search/semantic', $routes);
        $this->assertArrayHasKey('GET /api/posts/recommend', $routes);

        foreach ($routes as $key => $middleware) {
            $this->assertNotEmpty($middleware, "{$key} 中间件不得为空（插件路由零中间件回归）");
            foreach (['RateLimitMiddleware', 'LicenseMiddleware'] as $required) {
                $this->assertContains($required, $middleware, "{$key} 必须挂载 {$required}");
            }
            $this->assertNotContains('AuthMiddleware', $middleware, '公开插件路由不应强制登录');
        }
    }

    public function testCanaryPluginRouteInheritsBaselineWithoutDeclaringIt(): void
    {
        $router = new Router();
        $router->group('', function (Router $router): void {
            // 故意不传第三参，模拟任何未来插件的最简注册写法。
            $router->get('/api/plugin/canary', [\Hublog\Core\Response::class, 'success']);
        }, self::BASELINE);

        $routes = $this->routes($router);
        $this->assertSame(self::BASELINE, $routes['GET /api/plugin/canary']);
        $this->assertSame('', array_reduce(
            array_keys($routes),
            static fn(string $carry, string $path): string => str_contains($path, '//') ? $path : $carry,
            ''
        ), "group('') 不得改变路径");
    }
}
