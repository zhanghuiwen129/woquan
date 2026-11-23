<?php
/**
 * 路由层行为护栏：真实 new Router() + dispatch()，覆盖阶段 0 补的
 * preg_quote / patch() / 非字符串中间件报错，以及 P2-3 的 /api/v1 重写。
 *
 * 这些断言过去靠解析 routes.php 文本或反射完成，无法证明请求真的走对了 handler；
 * 这里每个用例都执行完整 dispatch 链路并检查最终 Response 或异常。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Router;

final class RouterBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup    = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET    = $this->getBackup;
        parent::tearDown();
    }

    private function routerFor(string $method, string $uri): Router
    {
        $_SERVER = $this->serverBackup;
        unset($_SERVER['PATH_INFO'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_TYPE']);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['SCRIPT_NAME']    = '';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.7';
        $_GET = [];
        return new Router();
    }

    private function body(Response $response): array
    {
        $decoded = json_decode($response->toJson(), true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function testLiteralDotInRoutePathIsEscaped(): void
    {
        $router = $this->routerFor('GET', '/sitemap.xml');
        $router->get('/sitemap.xml', fn() => Response::success('sitemap'));
        $this->assertSame('sitemap', $this->body($router->dispatch())['data']);

        // 旧实现未 preg_quote，'.' 变通配符，这个 URL 曾错误命中 sitemap.xml 路由
        $miss = $this->routerFor('GET', '/sitemapXxml');
        $miss->get('/sitemap.xml', fn() => Response::success('sitemap'));
        try {
            $miss->dispatch();
            $this->fail('/sitemapXxml 不应命中 /sitemap.xml');
        } catch (HublogException $e) {
            $this->assertSame('路由未找到', $e->getMessage());
        }
    }

    public function testDynamicSegmentIsInjectedIntoRequest(): void
    {
        $router = $this->routerFor('GET', '/api/user/42');
        $router->get('/api/user/{id}', function (Request $request): Response {
            return Response::success(['id' => $request->get('id')]);
        });

        $this->assertSame(['id' => '42'], $this->body($router->dispatch())['data']);
    }

    public function testPatchRequestsAreRoutable(): void
    {
        $router = $this->routerFor('PATCH', '/api/profile');
        $router->patch('/api/profile', fn() => Response::success('patched'));

        $this->assertSame('patched', $this->body($router->dispatch())['data']);
    }

    public function testMiddlewareRunsBeforeHandler(): void
    {
        $order = [];
        $router = $this->routerFor('GET', '/api/ordered');
        $router->get('/api/ordered', function () use (&$order): Response {
            $order[] = 'handler';
            return Response::success('ok');
        }, [
            function () use (&$order): void { $order[] = 'mw1'; },
            function () use (&$order): void { $order[] = 'mw2'; },
        ]);

        $router->dispatch();
        $this->assertSame(['mw1', 'mw2', 'handler'], $order);
    }

    public function testInvalidMiddlewareTypeThrowsInsteadOfSilentlySkipping(): void
    {
        $router = $this->routerFor('GET', '/api/guarded');
        $router->get('/api/guarded', fn() => Response::success('should-not-run'), [123]);

        try {
            $router->dispatch();
            $this->fail('非法中间件类型必须报错，不能静默跳过');
        } catch (HublogException $e) {
            $this->assertStringContainsString('中间件配置非法', $e->getMessage());
        }
    }

    public function testAuthMiddlewareActuallyBlocksAnonymousRequest(): void
    {
        $router = $this->routerFor('GET', '/api/wallet');
        $router->get('/api/wallet', fn() => Response::success('balance'), ['AuthMiddleware']);

        try {
            $router->dispatch();
            $this->fail('无令牌请求应被 AuthMiddleware 拦截');
        } catch (HublogException $e) {
            $this->assertStringContainsString('请先登录', $e->getMessage());
        }
    }

    public function testApiV1PrefixIsTransparentlyRewritten(): void
    {
        $router = $this->routerFor('GET', '/api/v1/ping');
        $router->get('/api/ping', fn() => Response::success('pong'));

        $this->assertSame('pong', $this->body($router->dispatch())['data']);
    }

    public function testGroupMiddlewareAndPrefixAreApplied(): void
    {
        $ran = false;
        $router = $this->routerFor('GET', '/api/admin/stats');
        $router->group('/api/admin', function (Router $r) use (&$ran): void {
            $r->get('/stats', fn() => Response::success('stats'));
        }, [
            function () use (&$ran): void { $ran = true; },
        ]);

        $this->assertSame('stats', $this->body($router->dispatch())['data']);
        $this->assertTrue($ran, 'group 上的中间件必须被合并进子路由');
    }

    public function testOptionsPreflightShortCircuits(): void
    {
        $router = $this->routerFor('OPTIONS', '/api/anything');
        $this->assertSame(200, $this->body($router->dispatch())['code']);
    }
}
