<?php
/**
 * 限流行为护栏：S5「反代私网回源导致全局限流整体失效」的回归测试。
 *
 * 判定方式不是读代码，而是观察真实副作用 —— Security 是否往 Cache 写了计数键。
 * 旧实现在 REMOTE_ADDR 为私网时直接 return，计数键永远不存在，这组用例必然失败。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\Request;
use Hublog\Core\Security;
use Hublog\Middleware\RateLimitMiddleware;

final class RateLimitBehaviorTest extends BehaviorTestCase
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

    private function request(
        string $remoteIp,
        string $method = 'POST',
        array $extra = [],
        string $uri = '/api/posts/create'
    ): Request {
        $_SERVER = $this->serverBackup;
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_AUTHORIZATION', 'CONTENT_TYPE'] as $k) {
            unset($_SERVER[$k]);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['REMOTE_ADDR']    = $remoteIp;
        foreach ($extra as $k => $v) {
            $_SERVER[$k] = $v;
        }
        $_GET = [];
        return new Request();
    }

    private function counter(string $action, string $ip): mixed
    {
        return Cache::get("rate_limit_{$action}_{$ip}");
    }

    // ── Security::rateLimit 本身 ──────────────────────────────────────────

    public function testRateLimitAccumulatesThenRejects(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue(Security::rateLimit('203.0.113.20', 'probe', null, 3, 60));
            $this->assertSame($i, $this->counter('probe', '203.0.113.20'));
        }
        $this->assertFalse(Security::rateLimit('203.0.113.20', 'probe', null, 3, 60));
    }

    public function testCountersAreIsolatedByActionAndIdentity(): void
    {
        Security::rateLimit('203.0.113.21', 'action_a', null, 2, 60);
        Security::rateLimit('203.0.113.21', 'action_b', null, 2, 60);
        Security::rateLimit('203.0.113.22', 'action_a', null, 2, 60);

        $this->assertSame(1, $this->counter('action_a', '203.0.113.21'));
        $this->assertSame(1, $this->counter('action_b', '203.0.113.21'));
        $this->assertSame(1, $this->counter('action_a', '203.0.113.22'));
    }

    // ── S5 中间件 ─────────────────────────────────────────────────────────

    public function testPrivateReverseProxySourceStillGetsRateLimited(): void
    {
        // 反代部署下 REMOTE_ADDR 是内网 IP；旧实现在这里无条件豁免，限流完全不执行。
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.3.4'] as $privateIp) {
            (new RateLimitMiddleware())->handle($this->request($privateIp));
            $this->assertSame(
                1,
                $this->counter('api_write', $privateIp),
                "{$privateIp} 的写请求必须真的计入限流，不能被私网豁免"
            );
        }
    }

    public function testPrivateSourceRequestCanEventuallyBeRejected(): void
    {
        $ip = '192.168.1.77';
        Cache::set('rate_limit_api_write_' . $ip, 600, 60);

        try {
            (new RateLimitMiddleware())->handle($this->request($ip));
            $this->fail('达到阈值后私网来源也必须被 429 拦截');
        } catch (HublogException $e) {
            $this->assertStringContainsString('请求过于频繁', $e->getMessage());
        }
    }

    public function testForgedPrivateForwardedHeaderDoesNotBypassCounter(): void
    {
        // 客户端伪造 XFF 私网地址；不可信来源下应仍按 REMOTE_ADDR 计数
        (new RateLimitMiddleware())->handle($this->request('203.0.113.30', 'POST', [
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ]));

        $this->assertSame(1, $this->counter('api_write', '203.0.113.30'));
        $this->assertNull($this->counter('api_write', '10.0.0.1'));
    }

    public function testPollingAndNormalReadsUseDifferentBuckets(): void
    {
        $mw = new RateLimitMiddleware();
        $mw->handle($this->request('203.0.113.31', 'GET'));
        $this->assertSame(1, $this->counter('api_read', '203.0.113.31'));

        $polling = $this->request('203.0.113.31', 'GET', [], '/api/notifications/unread-count');
        $mw->handle($polling);
        $this->assertSame(1, $this->counter('api_read_polling', '203.0.113.31'));
    }

    public function testAdminRoleIsStillExempt(): void
    {
        Cache::set('auth_user_9_v1', [
            'id'            => 9,
            'username'      => 'admin',
            'role'          => 'admin',
            'status'        => 1,
            'token_version' => 1,
        ]);
        $token = Auth::generateToken([
            'id'            => 9,
            'username'      => 'admin',
            'role'          => 'admin',
            'token_version' => 1,
        ]);

        $request = $this->request('192.168.1.99');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        // Auth::user 在中间件内部读请求头，重新构造以带上令牌
        $authed = new Request();
        (new RateLimitMiddleware())->handle($authed);

        $this->assertNull($this->counter('api_write', '192.168.1.99'), 'admin 豁免应仍然生效');
    }
}
