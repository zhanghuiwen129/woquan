<?php
/**
 * S1 账号接管漏洞的行为级回归护栏。
 *
 * 与 Phase0SecurityGuardTest 的区别：那一组读源码文本，确认「代码长什么样」；
 * 这一组真实调用 Service / 控制器，走完 DB 写入、缓存限流、JWT 校验、Response 序列化，
 * 确认「系统实际表现是什么」。任何一处把 dev_code 重新塞回生产响应，或让某个入口
 * 绕过 PhoneCodeService，这组测试都会失败，而不是靠注释或字符串匹配来兜底。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AuthController;
use Hublog\Controllers\UserController;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\PhoneCodeService;
use Hublog\Core\Request;
use Hublog\Core\Security;

final class PhoneCodeBehaviorTest extends BehaviorTestCase
{
    private const IP = '203.0.113.7';

    private array $serverBackup = [];
    private array $postBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->postBackup   = $_POST;
        $this->getBackup    = $_GET;
        $_GET  = [];
        $_POST = [];

        // 真实用户，供 UserController::sendPhoneCode 里的 Auth::check 查询
        $this->seed('users', [
            'id'            => 1,
            'username'      => 'behavior_tester',
            'role'          => 'user',
            'status'        => 1,
            'token_version' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST   = $this->postBackup;
        $_GET    = $this->getBackup;
        parent::tearDown();
    }

    /** 构造真实 Request（POST 表单 + 可选 Bearer JWT），不 mock 任何东西 */
    private function makeRequest(array $params, bool $withAuth = true): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = '/api/user/phone-code';
        $_SERVER['REMOTE_ADDR']    = self::IP;
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_AUTHORIZATION']);

        $_POST = $params;
        $_GET  = [];

        if ($withAuth) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken([
                'id'            => 1,
                'username'      => 'behavior_tester',
                'role'          => 'user',
                'token_version' => 1,
            ]);
        }
        return new Request();
    }

    private function issue(string $phone = '13800138000', string $scene = 'login', string $ip = self::IP): array
    {
        return PhoneCodeService::issue($phone, $scene, $ip);
    }

    private function storedCodes(): array
    {
        return array_column($this->rows('phone_codes'), 'code');
    }

    // ── 生产失败关闭 ──────────────────────────────────────────────────────

    public function testProductionIssueFailsClosedAndWritesValidCode(): void
    {
        $result = $this->issue();

        $this->assertFalse($result['ok']);
        $this->assertSame(503, $result['status']);
        $this->assertNull($result['data'], '生产环境 data 必须为 null，不能带 dev_code');
        $this->assertStringContainsString('短信服务暂不可用', $result['message']);
        $this->assertStringNotContainsString('dev_code', json_encode($result, JSON_UNESCAPED_UNICODE));

        $rows = $this->rows('phone_codes');
        $this->assertCount(1, $rows, '失败关闭前仍应落库一条验证码');
        // V102：库里只存 bcrypt 哈希，不再存 6 位明文
        $this->assertMatchesRegularExpression('/^\$2[aby]\$\d{2}\$/', $rows[0]['code'],
            '验证码必须哈希入库');
        $this->assertSame('login', $rows[0]['scene']);
        $this->assertSame(self::IP, $rows[0]['sent_ip']);
        $this->assertGreaterThan(time() + 200, strtotime((string)$rows[0]['expires_at']), '有效期应为 5 分钟');
        $this->assertStringNotContainsString(
            $rows[0]['code'],
            json_encode($result, JSON_UNESCAPED_UNICODE),
            '明文验证码绝不能出现在任何生产响应字段里'
        );
    }

    public function testBothControllerIssueEntrypointsBehaveIdentically(): void
    {
        // 这两个入口正是当初「同一加固只改一处」的分叉点。
        // 注意：本用例只覆盖签发侧；校验侧的三端一致性由下方 verify 组覆盖。
        $authResponse = (new AuthController())->sendPhoneLoginCode(
            $this->makeRequest(['phone' => '13800138000'])
        );
        $userResponse = (new UserController())->sendPhoneCode(
            $this->makeRequest(['phone' => '13900139000', 'scene' => 'bind'])
        );

        foreach (['auth' => $authResponse, 'user' => $userResponse] as $label => $response) {
            $json = $response->toJson();
            $body = json_decode($json, true);
            $this->assertIsArray($body, $label . ' 响应必须是合法 JSON');
            $this->assertSame(503, $body['code'], $label . ' 入口生产环境应失败关闭');
            $this->assertNull($body['data'], $label . ' 入口不得回带验证码');
            $this->assertStringNotContainsString('dev_code', $json);
        }

        $codes = $this->storedCodes();
        $this->assertCount(2, $codes, '两个入口各写一条，说明都走到了统一 Service');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString(
                (string)$code,
                $authResponse->toJson() . $userResponse->toJson(),
                '任一入口都不允许泄露库中明文码'
            );
        }
    }

    public function testUserEntrypointRequiresLogin(): void
    {
        $this->expectException(HublogException::class);
        $this->expectExceptionMessage('请先登录');
        (new UserController())->sendPhoneCode(
            $this->makeRequest(['phone' => '13800138000'], false)
        );
    }

    // ── 参数校验与频率控制 ────────────────────────────────────────────────

    public function testInvalidPhoneAndSceneAreRejectedBeforeAnyDbWrite(): void
    {
        foreach (['', '123', 'abcdefghijk', '12800138000'] as $badPhone) {
            $result = $this->issue($badPhone);
            $this->assertSame(400, $result['status'], "手机号 {$badPhone} 应被拒");
        }
        $result = $this->issue('13800138000', 'evil_scene');
        $this->assertSame(400, $result['status'], '未登记场景应被拒');

        $this->assertSame([], $this->rows('phone_codes'), '非法请求不应写库');
    }

    public function testCooldownBlocksSecondSendWithinSixtySeconds(): void
    {
        $this->seed('phone_codes', [
            'phone'      => '13800138000',
            'code'       => '111111',
            'scene'      => 'login',
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->issue();
        $this->assertSame(429, $result['status']);
        $this->assertStringContainsString('发送过于频繁', $result['message']);
        $this->assertCount(1, $this->rows('phone_codes'), '冷却期内不得再写新码');
    }

    public function testIpHourLimitAccumulatesAndBlocksAtTwenty(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $result = $this->issue(sprintf('138%08d', $i));
            $this->assertSame(503, $result['status'], "第 " . ($i + 1) . " 次应正常走到失败关闭");
        }

        $blocked = $this->issue('13899999999');
        $this->assertSame(429, $blocked['status'], '第 21 次必须被 IP 小时闸拦住');
        $this->assertStringContainsString('该网络环境', $blocked['message']);
    }

    public function testIpDayLimitBlocksWhenCounterIsAtCap(): void
    {
        // 直接预置日计数到 60，单独验证第二道闸（不与小时闸耦合）
        Cache::set('rate_limit_sms_ip_day_' . self::IP, 60, 86400);

        $result = $this->issue();
        $this->assertSame(429, $result['status']);
        $this->assertStringContainsString('今日', $result['message']);
        $this->assertSame([], $this->rows('phone_codes'), '被限流的请求不应写库');
    }

    // ── DEBUG 分支与日志脱敏 ──────────────────────────────────────────────

    public function testDebugBranchReturnsDevCodeMatchingDatabase(): void
    {
        $payload = $this->runIsolated('phone_code_debug.php');

        $this->assertTrue($payload['debug']);
        $this->assertTrue($payload['result']['ok']);
        $this->assertSame(200, $payload['result']['status']);
        $this->assertFalse($payload['result']['data']['sent']);
        $this->assertMatchesRegularExpression('/^\$2[aby]\$\d{2}\$/', $payload['db_code'],
            'V102：库里存 bcrypt 哈希，不能是明文');
        $this->assertTrue($payload['db_code_is_hash'], '库里的验证码须为哈希');
        $this->assertTrue($payload['db_code_matches_dev'],
            'DEBUG 回带的明文码必须能验证库中哈希（即两者对应同一验证码）');
    }

    public function testProductionLogsAreMaskedAndNeverContainPlainCode(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hublog_behavior_log_');
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $result = $this->issue();
        } finally {
            ini_set('error_log', $previous);
        }

        $content = (string)file_get_contents($logFile);
        @unlink($logFile);

        $this->assertSame(503, $result['status']);
        $this->assertNotSame('', $content, '应确实写出服务端日志');
        $this->assertStringContainsString('138****8000', $content, '生产日志手机号必须掩码');
        foreach ($this->storedCodes() as $code) {
            $this->assertStringNotContainsString(
                (string)$code,
                $content,
                '明文验证码不得落到日志文件（第二条泄露通道）'
            );
        }
    }

    // ── 校验收敛：三端统一走 PhoneCodeService::verify ──────────────────────

    /**
     * 直接写一条指定场景/时间的验证码，绕开短信服务，供校验用例使用。
     */
    private function seedCode(string $scene, string $code, string $phone = '13800138000'): void
    {
        $this->seed('phone_codes', [
            'phone'      => $phone,
            'code'       => $code,
            'scene'      => $scene,
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
            'sent_ip'    => self::IP,
        ]);
    }

    /**
     * 同一手机号、同一 scene 签发的码，三个语义入口（login / set_password / bind）
     * 调 verify 的结果必须一致：都成功、都按 scene 取码、都原子核销。
     * 这是原 testBothControllerEntrypointsBehaveIdentically 只测签发侧缺失的另一半。
     */
    public function testVerifyBehavesIdenticallyAcrossThreeScenes(): void
    {
        $phone = '13800138000';
        foreach (['login', 'set_password', 'bind'] as $scene) {
            $this->seedCode($scene, '654321', $phone);

            $result = PhoneCodeService::verify($phone, '654321', $scene);
            $this->assertTrue($result['ok'], "{$scene} 场景同码校验应成功");
            $this->assertSame(200, $result['status']);
            $this->assertSame('验证通过', $result['message']);

            // 成功后必须原子核销：同码二次校验一律拒绝
            $replay = PhoneCodeService::verify($phone, '654321', $scene);
            $this->assertFalse($replay['ok'], "{$scene} 场景同码不得重复使用");
            $this->assertSame(400, $replay['status']);
            $this->assertSame('验证码已失效，请重新获取', $replay['message']);
        }

        $usedCount = 0;
        foreach ($this->rows('phone_codes') as $row) {
            $usedCount += $row['used_at'] !== null ? 1 : 0;
        }
        $this->assertSame(3, $usedCount, '三条码都必须在成功后被标记 used_at');
    }

    /**
     * 为 login 签发的码不得被 bind 入口消费，反之亦然 —— 这是收敛前的真实 ATO 路径：
     * 校验 SQL 没有 scene 过滤时，任意场景的码都能在 bind/login 之间串用。
     */
    public function testWrongSceneCodeIsRejectedByEveryEntrypoint(): void
    {
        $phone = '13800138000';
        $this->seedCode('login', '111111', $phone);
        $this->seedCode('bind', '222222', $phone);

        $crossChecks = [
            ['login code at bind', '111111', 'bind'],
            ['bind code at login', '222222', 'login'],
        ];
        foreach ($crossChecks as [$label, $code, $scene]) {
            $result = PhoneCodeService::verify($phone, $code, $scene);
            $this->assertFalse($result['ok'], $label . ' 必须被拒绝');
            $this->assertSame(400, $result['status']);
            // 串用码要么因 scene 过滤查不到码、要么与该 scene 的真实码比对失败，
            // 两种情况都拒绝，文案二选一即可（不泄露该 scene 是否存在有效码）。
            $this->assertContains($result['message'], ['验证码已失效，请重新获取', '验证码不正确']);
        }

        // 串用失败不应消耗正确场景的码
        $this->assertTrue(PhoneCodeService::verify($phone, '111111', 'login')['ok']);
        $this->assertTrue(PhoneCodeService::verify($phone, '222222', 'bind')['ok']);
    }

    /**
     * 防爆破锁定在三端都生效：连续 5 次错误后，即使输入正确码也被锁定。
     * 本用例真实命中 Security::checkCodeLock / recordCodeFailure（原测试命中数为 0）。
     */
    public function testFiveFailuresLockEveryEntrypointEvenForCorrectCode(): void
    {
        $phone = '13900139000';
        foreach (['login', 'set_password', 'bind'] as $scene) {
            $this->seedCode($scene, '333333', $phone);

            for ($i = 1; $i <= 5; $i++) {
                $wrong = PhoneCodeService::verify($phone, '000000', $scene);
                $this->assertFalse($wrong['ok'], "{$scene} 第 {$i} 次错误码应失败");
                if ($i < 5) {
                    $this->assertSame('验证码不正确', $wrong['message']);
                } else {
                    $this->assertSame(429, $wrong['status'], "{$scene} 第 5 次失败应触发锁定");
                    $this->assertStringContainsString('已锁定 1 小时', $wrong['message']);
                }
            }

            // checkCodeLock 必须报告锁定（直接调用，确保锁定键按 scene 隔离落地）
            $lock = Security::checkCodeLock($phone, $scene);
            $this->assertTrue($lock['locked'], "{$scene} 场景应处于锁定态");
            $this->assertGreaterThan(0, $lock['retry_after']);

            // 锁定期间正确码也必须被拒绝
            $correct = PhoneCodeService::verify($phone, '333333', $scene);
            $this->assertFalse($correct['ok'], "{$scene} 锁定期内正确码也应被拒");
            $this->assertSame(429, $correct['status']);
            $this->assertStringContainsString('验证失败次数过多', $correct['message']);
        }
    }
}
