<?php
/**
 * P0-3 httpOnly Cookie 鉴权回退 · 行为测试（2026-11-12）
 *
 * 背景：Web 端令牌不再持久化进 localStorage（XSS 无法窃取持久化令牌），
 * 改由后端 Auth::setAuthCookie() 下发 httponly Cookie；Auth::check()/user()
 * 在 Authorization: Bearer 缺失时回退读取 Cookie。本测试把回退行为锚定：
 *   1. 无 Bearer 头 + 有效 Cookie → 鉴权通过（刷新页面后的 Web 会话场景）
 *   2. Bearer 头存在时优先于 Cookie（App 端 / 显式声明不受影响）
 *   3. Cookie 内容无效（伪造/过期）→ 401，不静默放行
 *   4. ?token= 查询参数回退保持禁用（P1-7 不回退）
 *
 * 全部真实调用 Auth::check()，不 mock。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Auth;
use Hublog\Core\Database;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\Request;

final class CookieAuthBehaviorTest extends BehaviorTestCase
{
    private array $cookieBackup = [];
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookieBackup = $_COOKIE;
        $this->serverBackup = $_SERVER;
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/auth/me';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.7';
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function makeUser(string $username = 'cookie_admin', string $role = 'admin'): array
    {
        Database::insert('users', [
            'username' => $username,
            'nickname' => $username,
            'email'    => $username . '@test.local',
            'role'     => $role,
            'status'   => 1,
            'token_version' => 1,
        ]);
        return Database::fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
    }

    public function testAuthCheckFallsBackToHttpOnlyCookie(): void
    {
        $user = $this->makeUser();
        $jwt  = Auth::generateToken($user);

        $_COOKIE['hublog_token'] = $jwt;
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER, '前置条件：无 Bearer 头');

        $authUser = Auth::check(new Request());
        $this->assertSame((int)$user['id'], (int)$authUser['id'], '无 Bearer 头时应凭 httpOnly Cookie 通过鉴权');
        $this->assertSame('admin', $authUser['role']);
    }

    public function testBearerHeaderTakesPrecedenceOverCookie(): void
    {
        $user = $this->makeUser('dual_user');
        $jwt  = Auth::generateToken($user);

        // Cookie 里放一个垃圾值：若实现错误地优先读了 Cookie，鉴权会失败
        $_COOKIE['hublog_token'] = 'forged.invalid.jwt';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;

        $authUser = Auth::check(new Request());
        $this->assertSame((int)$user['id'], (int)$authUser['id'], 'Bearer 头应优先于 Cookie');
    }

    public function testForgedCookieTokenIsRejected(): void
    {
        $this->makeUser('victim');
        $_COOKIE['hublog_token'] = 'forged.invalid.jwt';

        $this->expectException(HublogException::class);
        $this->expectExceptionCode(401);
        Auth::check(new Request());
    }

    public function testEmptyCookieFallsBackToReject(): void
    {
        $this->makeUser('no_cookie_user');
        // 既无 Bearer 也无 Cookie：必须 401（不能因回退逻辑放行匿名）
        $this->expectException(HublogException::class);
        $this->expectExceptionCode(401);
        Auth::check(new Request());
    }

    public function testQueryTokenStillRejected(): void
    {
        $user = $this->makeUser('query_user');
        $jwt  = Auth::generateToken($user);

        // P1-7 不回退：?token= 不得作为凭证来源，Cookie 缺失时即使 query 带 token 也要 401
        $_SERVER['REQUEST_URI'] = '/api/auth/me?token=' . urlencode($jwt);
        $_GET = ['token' => $jwt];

        $this->expectException(HublogException::class);
        $this->expectExceptionCode(401);
        Auth::check(new Request());
    }
}
