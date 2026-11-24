<?php
/**
 * 认证授权组加固（批次 1+2）行为级回归护栏。
 *
 * 与 Phase0SecurityGuardTest 的源码级断言互补：这里用 SQLite 内存库真实执行
 * refresh 轮换、改密、重置令牌核销、CSRF 校验、Cookie 续期等写序列，
 * 断言「实际落库副作用」与「并发/重放下的失败语义」。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AuthController;
use Hublog\Controllers\UserController;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Csrf;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\MediaHelper;
use Hublog\Core\RefreshToken;
use Hublog\Core\Request;
use Hublog\Core\UserRepository;

final class AuthHardeningBehaviorTest extends BehaviorTestCase
{
    private const IP = '203.0.113.41';

    /** @var array<string,mixed> */
    private array $serverBackup = [];
    /** @var array<string,mixed> */
    private array $postBackup = [];
    /** @var array<string,mixed> */
    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 本套件专属夹具：只在此处补齐认证加固相关列 / 表，不改公共底座，
        // 避免与自行重建 schema 的其它行为测试（UserAuthRepository / Batch5）冲突。
        $this->pdo()->exec(<<<'SQL'
ALTER TABLE `users` ADD COLUMN `password` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `email_verified` INTEGER NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `realname_verified` INTEGER NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `realname_verified_at` TEXT DEFAULT NULL;
CREATE TABLE `refresh_tokens` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`      INTEGER NOT NULL,
    `token_hash`   TEXT    NOT NULL,
    `device_id`    TEXT    DEFAULT NULL,
    `user_agent`   TEXT    DEFAULT NULL,
    `ip`           TEXT    DEFAULT NULL,
    `tv`           INTEGER NOT NULL DEFAULT 0,
    `created_at`   TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    `expires_at`   TEXT    NOT NULL,
    `last_used_at` TEXT    DEFAULT NULL,
    `revoked`      INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE `email_verifications` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL DEFAULT 0,
    `email`      TEXT    DEFAULT NULL,
    `code`       TEXT    DEFAULT NULL,
    `type`       TEXT    DEFAULT NULL,
    `expires_at` TEXT    DEFAULT NULL,
    `used`       INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT    DEFAULT NULL,
    `updated_at` TEXT    DEFAULT NULL
);
SQL);

        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $_SERVER['REMOTE_ADDR'] = self::IP;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'example.test';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_POST = [];
        $_COOKIE = [];
        Auth::clearUserCache(1);
        Auth::clearUserCache(2);
        Auth::clearUserCache(3);
        Auth::clearUserCache(5);
        Auth::clearUserCache(7);
        Auth::clearUserCache(9);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        parent::tearDown();
    }

    /** 建一个字段齐备的用户（覆盖 Auth::user 的真实 SELECT 列） */
    private function seedUser(int $id, int $tokenVersion = 1): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seed('users', [
            'id'            => $id,
            'username'      => 'user' . $id,
            'nickname'      => 'user' . $id,
            'role'          => 'user',
            'status'        => 1,
            'token_version' => $tokenVersion,
            'created_at'    => $now,
            'password'      => password_hash('old-pass', PASSWORD_DEFAULT),
            'email'         => "user{$id}@example.com",
            'email_verified' => 0,
            'realname_verified' => 0,
        ]);
    }

    /** 建一枚 refresh_token 记录 */
    private function seedRefresh(int $id, int $userId, string $raw, int $tv, int $revoked = 0): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seed('refresh_tokens', [
            'id'           => $id,
            'user_id'      => $userId,
            'token_hash'   => hash('sha256', $raw),
            'device_id'    => 'dev' . $id,
            'user_agent'   => 'phpunit',
            'ip'           => self::IP,
            'tv'           => $tv,
            'created_at'   => $now,
            'expires_at'   => date('Y-m-d H:i:s', time() + 3600),
            'last_used_at' => $now,
            'revoked'      => $revoked,
        ]);
    }

    private function revokedFlag(int $id): int
    {
        return (int)$this->pdo()->query("SELECT revoked FROM refresh_tokens WHERE id = {$id}")->fetchColumn();
    }

    // ---------------- P1-1：轮换原子化 + 无连锁撤销 ----------------

    public function testReplayedRefreshTokenFailsWithoutRevokingOtherSessions(): void
    {
        $this->seedUser(1);
        $this->seedUser(2);
        $rawA = str_repeat('a', 64);
        $rawB = str_repeat('b', 64);
        $this->seedRefresh(1, 1, $rawA, 1);
        $this->seedRefresh(2, 2, $rawB, 1);

        $first = RefreshToken::rotate($rawA);
        $this->assertSame(1, (int)$first['user']['id']);
        $this->assertSame(1, $this->revokedFlag(1), '轮换成功后旧令牌必须已撤销');

        // 旧令牌二次提交：401，且绝不撤销他人设备的令牌
        try {
            RefreshToken::rotate($rawA);
            $this->fail('重放已撤销令牌必须抛 401');
        } catch (HublogException $e) {
            $this->assertSame(401, $e->getCode());
        }
        $this->assertSame(0, $this->revokedFlag(2), '重放不得连锁撤销其它用户的令牌');

        // 他人令牌仍能正常轮换，证明上一步没有全局撤销
        $second = RefreshToken::rotate($rawB);
        $this->assertSame(2, (int)$second['user']['id']);
        $this->assertSame(1, $this->revokedFlag(2));
    }

    // ---------------- S1-a / P1-3：统一改密入口 ----------------

    public function testPasswordChangeBumpsVersionAndRevokesAllRefreshTokens(): void
    {
        $this->seedUser(7, 3);
        $this->seedRefresh(1, 7, str_repeat('c', 64), 3);
        $this->seedRefresh(2, 7, str_repeat('d', 64), 3);
        $now = date('Y-m-d H:i:s');

        $newVersion = UserRepository::changePasswordAndInvalidateSessions(
            7,
            'newhash',
            $now,
            ['nickname' => 'admin_set']
        );
        $this->assertSame(4, $newVersion);
        $this->assertSame(4, (int)UserRepository::findTokenVersionById(7));
        $this->assertSame('newhash', UserRepository::findPasswordById(7)['password']);
        $this->assertSame('admin_set', UserRepository::findBasicById(7)['nickname']);
        $this->assertSame(1, $this->revokedFlag(1), '改密必须撤销该用户全部 refresh_token');
        $this->assertSame(1, $this->revokedFlag(2), '改密必须撤销该用户全部 refresh_token');

        // extraFields 不能覆盖 id / password / token_version（SQL 侧自行 +1）
        UserRepository::changePasswordAndInvalidateSessions(7, 'h2', $now, [
            'id' => 8, 'password' => 'leak', 'token_version' => 999,
        ]);
        $this->assertSame(5, (int)UserRepository::findTokenVersionById(7));
        $this->assertSame('h2', UserRepository::findPasswordById(7)['password']);
    }

    // ---------------- P2-9：重置令牌原子核销 ----------------

    public function testResetTokenCanBeClaimedOnlyOnce(): void
    {
        $this->seedUser(9, 1);
        $this->seedRefresh(1, 9, str_repeat('e', 64), 1);
        $now = date('Y-m-d H:i:s');
        $this->seed('email_verifications', [
            'id'         => 1,
            'user_id'    => 9,
            'email'      => 'user9@example.com',
            'code'       => password_hash('reset-token', PASSWORD_DEFAULT),
            'type'       => 'reset',
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
            'used'       => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hash = password_hash('brand-new-pass', PASSWORD_DEFAULT);
        $this->assertTrue(UserRepository::resetPasswordWithToken(1, 9, $hash, $now));
        $this->assertFalse(UserRepository::resetPasswordWithToken(1, 9, $hash, $now), '并发第二次核销必须失败');

        $this->assertSame(1, (int)$this->pdo()->query('SELECT used FROM email_verifications WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int)UserRepository::findTokenVersionById(9));
        $this->assertTrue(password_verify('brand-new-pass', UserRepository::findPasswordById(9)['password']));
        $this->assertSame(1, $this->revokedFlag(1), '重置密码同样必须撤销全部会话');
    }

    // ---------------- P2-6：CSRF fail-open 移除 ----------------

    public function testCsrfEmptySubjectFailsClosed(): void
    {
        $token = bin2hex(random_bytes(16));
        Cache::set('csrf_' . $token, json_encode(['t' => time(), 's' => '']), 1800);
        $this->assertFalse(Csrf::verify($token), "s='' 的遗留 token 不得放行");

        // 严格绑定本身仍可用：合法 guest token 能通过
        $_COOKIE['csrf_guest_subject'] = str_repeat('f', 64);
        $valid = bin2hex(random_bytes(16));
        Cache::set('csrf_' . $valid, json_encode(['t' => time(), 's' => 'g:' . str_repeat('f', 64)]), 1800);
        $this->assertTrue(Csrf::verify($valid));
    }

    // ---------------- P2-11：Web refresh 接受 httpOnly Cookie ----------------

    public function testWebRefreshAcceptsHttpOnlyCookie(): void
    {
        $this->seedUser(3, 2);
        $jwt = Auth::generateToken([
            'id' => 3, 'username' => 'user3', 'role' => 'user', 'token_version' => 2,
        ]);
        $_COOKIE[Auth::AUTH_COOKIE] = $jwt;

        $response = (new AuthController())->refresh(new Request());
        $this->assertSame(200, $response->getCode());
        $body = json_decode($response->toJson(), true);
        $this->assertSame(3, (int)($body['data']['user']['id'] ?? 0));
        $this->assertNotEmpty($body['data']['token'] ?? '');
    }

    // ---------------- P2-13：绑机验证码 scene 服务端写死 ----------------

    public function testBindPhoneCodeSceneIsServerSideForced(): void
    {
        $this->seedUser(5, 1);
        $_COOKIE[Auth::AUTH_COOKIE] = Auth::generateToken([
            'id' => 5, 'username' => 'user5', 'role' => 'user', 'token_version' => 1,
        ]);

        // 客户端传 login 不得被用来在登录态端点签发登录码（公开场景只能走 /api/auth/phone-code）
        $_POST = ['scene' => 'login', 'phone' => '13800138000'];
        $response = (new UserController())->sendPhoneCode(new Request());
        $this->assertSame(400, $response->getCode());

        // 不传 scene 时保持历史默认 bind
        $_POST = ['phone' => '13800138000'];
        (new UserController())->sendPhoneCode(new Request());

        $scene = $this->pdo()->query('SELECT scene FROM phone_codes ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertSame('bind', $scene);
    }

    // ---------------- P2-8：第三方头像 CDN 白名单 ----------------

    public function testSafeMediaUrlAllowsProviderCdnButNotArbitraryHosts(): void
    {
        $this->assertTrue(MediaHelper::isSafeMediaUrl(
            'https://avatars.githubusercontent.com/u/1?v=4',
            0,
            MediaHelper::OAUTH_CDN['github']
        ));
        $this->assertTrue(MediaHelper::isSafeMediaUrl(
            'http://thirdwx.qlogo.cn/mmopen/abc',
            0,
            MediaHelper::OAUTH_CDN['wechat']
        ));
        $this->assertTrue(MediaHelper::isSafeMediaUrl('/templates/moments/assets/images/default-avatar.svg', 0));
        $this->assertFalse(MediaHelper::isSafeMediaUrl('//evil.example.com/a.png', 0));
        $this->assertFalse(MediaHelper::isSafeMediaUrl('https://evil.example.com/a.png', 0));
        $this->assertFalse(MediaHelper::isSafeMediaUrl(
            'https://evil.example.com/a.png',
            0,
            MediaHelper::OAUTH_CDN['github']
        ));
    }

    // ---------------- QA 回归：set_password 发码/校验链路 ----------------

    public function testSetPasswordChainFromUserPhoneCodeEndpointSucceeds(): void
    {
        $phone = '13800138001';
        $this->seedUser(11, 1);
        $this->pdo()->exec("UPDATE `users` SET `phone` = '{$phone}' WHERE id = 11");
        $_COOKIE[Auth::AUTH_COOKIE] = Auth::generateToken([
            'id' => 11, 'username' => 'user11', 'role' => 'user', 'token_version' => 1,
        ]);

        // ① 前端 set-password 页面调用 /api/user/phone-code 传 scene=set_password
        $_POST = ['phone' => $phone, 'scene' => 'set_password'];
        (new UserController())->sendPhoneCode(new Request());
        $code = $this->pdo()->query(
            "SELECT `code` FROM `phone_codes` WHERE `phone` = '{$phone}' AND `scene` = 'set_password' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->assertNotEmpty($code, 'set_password 场景必须真实落码');
        // V102：库里现在是 bcrypt 哈希，测试拿不到明文。用一条已知明文覆盖该记录，
        // 模拟「用户从短信读到码」——既保留跨端点 scene 链路验证，又兼容哈希存储。
        $code = '135790';
        $this->pdo()->exec(
            "UPDATE `phone_codes` SET `code` = '{$code}' WHERE `phone` = '{$phone}' AND `scene` = 'set_password'"
        );

        // ② 用该码走 /api/auth/set-password（校验端 scene 同为 set_password）
        $_POST = [
            'phone' => $phone,
            'code' => $code,
            'new_password' => 'NewStrongPass123',
            'new_password_confirm' => 'NewStrongPass123',
        ];
        $response = (new AuthController())->setPassword(new Request());
        $this->assertSame(200, $response->getCode(), $response->toJson());
        $this->assertTrue(password_verify('NewStrongPass123', UserRepository::findPasswordById(11)['password']));
        $this->assertSame(2, (int)UserRepository::findTokenVersionById(11));
    }

    public function testPhoneCodeEndpointRejectsPublicScenesAndForeignPhone(): void
    {
        $phone = '13900139000';
        $this->seedUser(12, 1);
        $this->pdo()->exec("UPDATE `users` SET `phone` = '{$phone}' WHERE id = 12");
        $_COOKIE[Auth::AUTH_COOKIE] = Auth::generateToken([
            'id' => 12, 'username' => 'user12', 'role' => 'user', 'token_version' => 1,
        ]);

        // login / reset 是公开端点 /api/auth/phone-code 的场景，不得经本端点签发
        foreach (['login', 'reset'] as $scene) {
            $_POST = ['phone' => $phone, 'scene' => $scene];
            $response = (new UserController())->sendPhoneCode(new Request());
            $this->assertSame(400, $response->getCode(), "scene={$scene} 必须 400");
        }

        // set_password 不得为他人手机号发码
        $_POST = ['phone' => '13700137000', 'scene' => 'set_password'];
        $response = (new UserController())->sendPhoneCode(new Request());
        $this->assertSame(403, $response->getCode());
        $this->assertSame(
            0,
            (int)$this->pdo()->query('SELECT COUNT(*) FROM phone_codes')->fetchColumn(),
            '所有拒绝分支都不得落码'
        );
    }

    // ---------------- QA 回归：邮箱大小写归一 ----------------

    public function testEmailRateLimitAndCooldownUseLowercaseIdentity(): void
    {
        // 首次请求：验证码行已落库（未配邮件服务时响应为 500，不影响限流判定）
        $_POST = ['email' => 'Victim@Example.com'];
        (new AuthController())->sendVerification(new Request());
        $this->assertSame(
            1,
            (int)$this->pdo()->query("SELECT COUNT(*) FROM email_verifications WHERE LOWER(email) = 'victim@example.com'")->fetchColumn()
        );

        // 大小写变体必须命中同一 60 秒冷却
        $_POST = ['email' => 'victim@example.com'];
        $response = (new AuthController())->sendVerification(new Request());
        $this->assertSame(429, $response->getCode(), $response->toJson());
    }

    // ---------------- QA 回归：验证码归属强制登录 ----------------

    public function testVerifyEmailRequiresMatchingLoginForRegisteredEmailCodes(): void
    {
        $email = 'Victim@Example.com';
        $this->seedUser(13, 1);
        $this->pdo()->exec("UPDATE `users` SET `email` = '{$email}' WHERE id = 13");
        $now = date('Y-m-d H:i:s');
        $this->seed('email_verifications', [
            'id' => 1,
            'user_id' => 13,
            'email' => $email,
            'code' => password_hash('654321', PASSWORD_DEFAULT),
            'type' => 'register',
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'used' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 未登录攻击者即便拿到/猜到验证码，也不得为他人邮箱置 email_verified
        $_POST = ['email' => 'VICTIM@EXAMPLE.COM', 'code' => '654321'];
        $response = (new AuthController())->verifyEmail(new Request());
        $this->assertSame(403, $response->getCode(), $response->toJson());
        $this->assertSame(
            0,
            (int)$this->pdo()->query('SELECT email_verified FROM users WHERE id = 13')->fetchColumn()
        );

        // 同 uid 登录态可正常完成核销（邮箱大小写变体也可命中）
        $_COOKIE[Auth::AUTH_COOKIE] = Auth::generateToken([
            'id' => 13, 'username' => 'user13', 'role' => 'user', 'token_version' => 1,
        ]);
        $response = (new AuthController())->verifyEmail(new Request());
        $this->assertSame(200, $response->getCode(), $response->toJson());
        $this->assertSame(
            1,
            (int)$this->pdo()->query('SELECT email_verified FROM users WHERE id = 13')->fetchColumn()
        );
        $this->assertSame(
            1,
            (int)$this->pdo()->query('SELECT used FROM email_verifications WHERE id = 1')->fetchColumn()
        );
    }

    // ---------------- P2-5：OAuth state 浏览器 Cookie 绑定 ----------------

    public function testOauthCallbackRequiresBrowserCookieBinding(): void
    {
        $_POST = ['provider' => 'github', 'code' => 'code-1', 'state' => 'state-1'];
        Cache::set('oauth_state_state-1', [
            'provider' => 'github', 'ip' => '1.2.3.4', 'browser_id' => 'bid-1',
        ], 300);

        // 无浏览器 Cookie：即便 state 与 provider 正确也必须拒绝（抗登录 CSRF 主力）
        $response = (new AuthController())->oauthCallback(new Request());
        $this->assertSame(400, $response->getCode());
        $this->assertStringContainsString('状态验证错误', $response->toJson());

        // Cookie 命中但 provider 不符：仍拒绝
        $_COOKIE['oauth_state_browser'] = 'bid-1';
        $_POST['provider'] = 'google';
        $response = (new AuthController())->oauthCallback(new Request());
        $this->assertSame(400, $response->getCode());
        $this->assertStringContainsString('状态验证错误', $response->toJson());

        // Cookie 绑定正确：通过校验后会继续走 provider 换票（网络结果不稳定，
        // 这里只断言不再报状态错误，且 state 已核销——足以证明校验已通过）
        $_POST['provider'] = 'github';
        $response = (new AuthController())->oauthCallback(new Request());
        $this->assertSame(400, $response->getCode());
        $this->assertFalse(str_contains($response->toJson(), '状态验证错误'));
        $this->assertEmpty(Cache::get('oauth_state_state-1'), '校验通过后 state 必须已核销');
    }

    /** V102 阶段1：state 强制校验，缓存未命中/缺失一律拒绝（不再兼容放行） */
    public function testOauthExchangeStateStrictMode(): void
    {
        // App 带 state 且 provider 不符 → 400
        $_POST = ['provider' => 'github', 'code' => 'code-2', 'state' => 'state-2'];
        Cache::set('oauth_state_state-2', [
            'provider' => 'google', 'ip' => '1.2.3.4', 'browser_id' => 'bid-2',
        ], 300);
        $response = (new AuthController())->oauthExchange(new Request());
        $this->assertSame(400, $response->getCode());
        $this->assertStringContainsString('状态验证错误', $response->toJson());

        // 缓存未命中：V102 起一律拒绝（原兼容放行是 CSRF 漏洞）
        $_POST['state'] = 'state-miss';
        $response = (new AuthController())->oauthExchange(new Request());
        $this->assertSame(400, $response->getCode());
        $this->assertStringContainsString('状态验证错误', $response->toJson());

        // 缓存命中且 provider 一致：核销后继续原流程
        Cache::set('oauth_state_state-3', [
            'provider' => 'github', 'ip' => '1.2.3.4', 'browser_id' => 'bid-3',
        ], 300);
        $_POST['state'] = 'state-3';
        $response = (new AuthController())->oauthExchange(new Request());
        $this->assertStringContainsString('登录未配置', $response->toJson());
        $this->assertEmpty(Cache::get('oauth_state_state-3'), 'exchange 命中 state 也必须核销');
    }
}
