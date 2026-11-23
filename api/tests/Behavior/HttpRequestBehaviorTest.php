<?php
/**
 * 请求层行为护栏：S9 的 Request::query()、S5 相关的可信代理 IP 判定、
 * 以及 P1-7「JWT 只认 Authorization 头」。全部用真实 new Request() 构造，不 mock。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Request;

final class HttpRequestBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup    = $_GET;
        $this->postBackup   = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET    = $this->getBackup;
        $_POST   = $this->postBackup;
        parent::tearDown();
    }

    private function make(
        string $method = 'GET',
        array $get = [],
        array $post = [],
        array $server = []
    ): Request {
        $_SERVER = $this->serverBackup;
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_AUTHORIZATION',
                  'HTTP_CLIENT_IP', 'CONTENT_TYPE'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/api/probe';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.7';
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
        $_GET  = $get;
        $_POST = $post;
        return new Request();
    }

    public function testQueryReadsUrlParameterAndIsNotOverriddenByBody(): void
    {
        // S9：在线升级三个接口读的是 URL 里的 ref；body 同名参数不得覆盖它
        $request = $this->make('POST', ['ref' => 'v2.9.0'], ['ref' => 'malicious']);

        $this->assertSame('v2.9.0', $request->query('ref'));
        $this->assertSame('malicious', $request->get('ref'), 'get() 仍按 body 优先，两者语义不同');
        $this->assertNull($request->query('missing'));
        $this->assertSame('fallback', $request->query('missing', 'fallback'));
    }

    public function testQueryRejectsArrayValuesInsteadOfReturningGarbage(): void
    {
        $request = $this->make('GET', ['ref' => ['a', 'b']]);
        $this->assertSame('safe', $request->query('ref', 'safe'));
    }

    public function testScalarAccessorsHaveSaneDefaults(): void
    {
        $request = $this->make('POST', [], [
            'page'    => '3',
            'keyword' => 'hublog',
            'flag'    => 'on',
            'tags'    => ['a', 'b'],
            'price'   => '12.5',
        ]);

        $this->assertSame(3, $request->getInt('page'));
        $this->assertSame(99, $request->getInt('missing', 99));
        $this->assertSame('hublog', $request->getString('keyword'));
        $this->assertSame('', $request->getString('missing'));
        $this->assertSame('', $request->getString('missing', null), 'null 默认值不得 TypeError');
        $this->assertTrue($request->getBool('flag'));
        $this->assertFalse($request->getBool('missing'));
        $this->assertSame(['a', 'b'], $request->getArray('tags'));
        $this->assertSame([], $request->getArray('missing'));
        $this->assertSame(12.5, $request->getFloat('price'));
        $this->assertTrue($request->has('keyword'));
        $this->assertFalse($request->has('missing'));
    }

    public function testForgedForwardedHeadersAreIgnoredFromUntrustedPeer(): void
    {
        // 攻击者伪造 XFF 私网地址不应影响真实 IP，更不该借此命中限流豁免
        $request = $this->make('GET', [], [], [
            'REMOTE_ADDR'          => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
            'HTTP_X_REAL_IP'       => '192.168.1.1',
            'HTTP_CLIENT_IP'       => '127.0.0.1',
        ]);
        $this->assertSame('203.0.113.7', $request->getIp());
    }

    public function testTrustedProxyUsesLeftmostForwardedClient(): void
    {
        $request = $this->make('GET', [], [], [
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.1',
        ]);
        $this->assertSame('198.51.100.9', $request->getIp());
    }

    public function testBearerTokenNeverFallsBackToQueryParameter(): void
    {
        $fromQuery = $this->make('GET', ['token' => 'leaked-in-url']);
        $this->assertNull($fromQuery->getBearerToken(), '?token= 不得作为 JWT 来源');

        $fromHeader = $this->make('GET', ['token' => 'leaked-in-url'], [], [
            'HTTP_AUTHORIZATION' => 'Bearer real.jwt.value',
        ]);
        $this->assertSame('real.jwt.value', $fromHeader->getBearerToken());
    }
}
