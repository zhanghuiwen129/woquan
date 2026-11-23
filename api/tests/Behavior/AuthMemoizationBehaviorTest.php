<?php
/**
 * 行为级测试：鉴权请求内记忆化（P2 双重守卫收敛）
 *
 * 治理目标：路由中间件（AuthMiddleware / AdminMiddleware）已经校验过一次身份，
 * 控制器里的 requireUser() / requireAdmin() 又会再调一次 ——
 * 每个后台请求因此重复查一次 users 表（118 处 requireAdmin 全部命中这条路径）。
 *
 * 收敛方式：Auth 对**同一请求内**的同一 token 做记忆化，第二次起不再查库。
 *
 * 本测试用「删掉用户行后再调一次」来证明第二次确实没有查库：
 * 若仍然查库，第二次会因用户不存在而 401。
 *
 * 同时锚定两条不可让步的边界：
 *   ① 记忆化只在**同一请求内**有效 —— 换了 token 必须重新解析；
 *   ② Auth::clear() / clearUserCache() 必须让记忆化立即失效
 *      （退出登录、封禁、改密码后不能继续用旧身份）。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Auth;
use Hublog\Core\Database;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\Request;

final class AuthMemoizationBehaviorTest extends BehaviorTestCase
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
        $_SERVER['REMOTE_ADDR']    = '203.0.113.9';
        $_COOKIE = [];

        // 底座 users 表缺 Auth::user() 要用的两列，补上（真实表里有这两列）
        $pdo = $this->pdo();
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `realname_verified` INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `realname_verified_at` TEXT DEFAULT NULL');

        // 每个用例从干净的身份状态开始（记忆化是进程内静态状态）
        Auth::clear();
    }

    protected function tearDown(): void
    {
        Auth::clear();
        $_COOKIE = $this->cookieBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function makeUser(string $username = 'memo_user', string $role = 'user'): array
    {
        Database::insert('users', [
            'username'      => $username,
            'nickname'      => $username,
            'email'         => $username . '@test.local',
            'role'          => $role,
            'status'        => 1,
            'token_version' => 1,
        ]);
        return Database::fetchOne("SELECT * FROM users WHERE username = ?", [$username]);
    }

    private function bearer(string $jwt): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
    }

    // ── check() ──────────────────────────────────────────────────────────

    public function testSecondCheckDoesNotHitDatabaseAgain(): void
    {
        $user = $this->makeUser();
        $this->bearer(Auth::generateToken($user));

        $first = Auth::check(new Request());
        $this->assertSame((int)$user['id'], (int)$first['id']);

        // 删掉用户行：若第二次 check() 还会查库，这里必然 401
        Database::query('DELETE FROM users WHERE id = ?', [(int)$user['id']]);

        $second = Auth::check(new Request());
        $this->assertSame(
            (int)$user['id'],
            (int)$second['id'],
            '同一请求内的第二次 check() 不应再查库'
        );
    }

    public function testAdminReusesCheckedIdentity(): void
    {
        $user = $this->makeUser('memo_admin', 'admin');
        $this->bearer(Auth::generateToken($user));

        Auth::check(new Request());
        Database::query('DELETE FROM users WHERE id = ?', [(int)$user['id']]);

        $admin = Auth::admin(new Request());
        $this->assertSame(
            (int)$user['id'],
            (int)$admin['id'],
            'admin() 应复用 check() 的结果，不重复查库'
        );
    }

    /** 换了 token 必须重新解析 —— 记忆化不能跨身份复用。 */
    public function testDifferentTokenIsResolvedAgain(): void
    {
        $userA = $this->makeUser('memo_a');
        $userB = $this->makeUser('memo_b');

        $this->bearer(Auth::generateToken($userA));
        $first = Auth::check(new Request());
        $this->assertSame((int)$userA['id'], (int)$first['id']);

        $this->bearer(Auth::generateToken($userB));
        $second = Auth::check(new Request());
        $this->assertSame(
            (int)$userB['id'],
            (int)$second['id'],
            '换了 token 必须重新解析，不能复用上一个身份'
        );
    }

    /** 退出登录（Auth::clear()）后记忆化必须立刻失效。 */
    public function testClearInvalidatesMemoizedIdentity(): void
    {
        $user = $this->makeUser();
        $this->bearer(Auth::generateToken($user));

        Auth::check(new Request());
        Auth::clear();

        Database::query('DELETE FROM users WHERE id = ?', [(int)$user['id']]);

        $this->expectException(HublogException::class);
        $this->expectExceptionMessage('请先登录');
        Auth::check(new Request());
    }

    /** 封禁 / 改密码（clearUserCache）后记忆化必须立刻失效。 */
    public function testClearUserCacheInvalidatesMemoizedIdentity(): void
    {
        $user = $this->makeUser();
        $this->bearer(Auth::generateToken($user));

        Auth::check(new Request());
        Auth::clearUserCache((int)$user['id']);

        Database::query('DELETE FROM users WHERE id = ?', [(int)$user['id']]);

        $this->expectException(HublogException::class);
        $this->expectExceptionMessage('请先登录');
        Auth::check(new Request());
    }

    // ── user() ───────────────────────────────────────────────────────────

    public function testSecondUserCallDoesNotHitDatabaseAgain(): void
    {
        $user = $this->makeUser('memo_plain');
        $this->bearer(Auth::generateToken($user));

        $first = Auth::user(new Request());
        $this->assertNotNull($first);
        $this->assertSame((int)$user['id'], (int)$first['id']);

        Database::query('DELETE FROM users WHERE id = ?', [(int)$user['id']]);

        $second = Auth::user(new Request());
        $this->assertNotNull($second, '同一请求内的第二次 user() 不应再查库');
        $this->assertSame((int)$user['id'], (int)$second['id']);
    }

    /** user() 与 check() 的列集不同，记忆化不能互相串味。 */
    public function testUserAndCheckKeepTheirOwnColumnSets(): void
    {
        $user = $this->makeUser('memo_cols');
        $this->bearer(Auth::generateToken($user));

        $checked = Auth::check(new Request());
        $fetched = Auth::user(new Request());

        $this->assertArrayHasKey('cover', $checked, 'check() 的列集含 cover');
        $this->assertArrayHasKey('realname_verified', $fetched, 'user() 的列集含 realname_verified');
    }
}
