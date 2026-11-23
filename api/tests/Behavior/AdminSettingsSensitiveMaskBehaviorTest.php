<?php
/**
 * 后台 settings 敏感键掩码 / 占位串跳过的行为回归测试。
 *
 * 缺陷原貌：掩码集合靠 stripos 模糊猜键，wxpay_private_key / alipay_private_key /
 * amap_web_key / license_key 等真实密钥明文下发；且掩码与跳过并非同一集合，
 * 前端回填的占位串可能被写回数据库，直接损坏支付配置。
 *
 * 本测试走真实控制器方法：构造 Request -> settings() -> SettingsRepository ->
 * SQLite 内存库，断言响应中没有任何明文密钥，且占位串提交不落库。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\AdminSettingsController;
use Hublog\Core\Auth;
use Hublog\Core\Database;
use Hublog\Core\Request;
use PDO;
use ReflectionProperty;

/** 仅绕过 requireAdmin() 的身份校验，其余设置读写逻辑与线上控制器完全一致。 */
final class StubAdminSettingsController extends AdminSettingsController
{
    protected function requireAdmin(Request $request): array
    {
        return ['id' => 1, 'role' => 'admin', 'username' => 'ops'];
    }
}

final class AdminSettingsSensitiveMaskBehaviorTest extends BehaviorTestCase
{
    /**
     * 与生产常量解耦的敏感键硬编码清单。
     *
     * 绝不能从 AdminSettingsController::SENSITIVE_KEYS 遍历生成 canary：那样生产漏键时
     * 测试会跟着少一个键，变异（删掉新增键）下仍绿，抓不到 GET 明文泄露。
     */
    private const MUST_BE_SENSITIVE = [
        // 本批新增的 5 个漏网键
        'wxpay_private_key', 'wxpay_api_v3_key', 'alipay_private_key',
        'amap_web_key', 'license_key',
        // 既有敏感键护栏
        'mail_password', 'sms_aliyun_access_key_secret', 'upload_oss_access_key_secret',
        'realname_appcode', 'cron_secret', 'ai_comment_api_key',
    ];

    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;

        // 底座 settings 表缺 id / created_at / updated_at，与 upsert 的先查后写口径不符
        $this->pdo()->exec('DROP TABLE `settings`');
        $this->pdo()->exec(
            'CREATE TABLE `settings` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `key`        TEXT NOT NULL UNIQUE,
                `value`      TEXT DEFAULT NULL,
                `created_at` TEXT DEFAULT NULL,
                `updated_at` TEXT DEFAULT NULL
            )'
        );
        $this->pdo()->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('sms_provider', 'none')");

        $dbProperty = new ReflectionProperty(Database::class, 'instance');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue(null, $this->pdo());

        Auth::clear();
    }

    protected function tearDown(): void
    {
        Auth::clear();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    /** @return array<string,mixed>|null PUT 成功响应 data 为 null */
    private function callSettings(string $method, array $post = []): ?array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = [];
        $_POST = $post;
        Auth::clear();

        $response = (new StubAdminSettingsController())->settings(new Request());
        $decoded = json_decode($response->toJson(), true);
        $this->assertIsArray($decoded, 'settings() 必须返回合法 JSON');
        $this->assertSame(200, $decoded['code'], (string)$decoded['message']);

        return $decoded['data'];
    }

    private function storedValue(string $key): ?string
    {
        $value = $this->pdo()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $value->execute([$key]);
        $raw = $value->fetchColumn();
        return $raw === false ? null : (string)$raw;
    }

    public function testGetResponseNeverContainsPlaintextSecrets(): void
    {
        $canaries = [];
        foreach (self::MUST_BE_SENSITIVE as $key) {
            $this->assertTrue(
                AdminSettingsController::isSensitiveKey($key),
                "直接护栏：{$key} 必须在生产敏感键清单中"
            );
            $canary = 'CANARY_' . strtoupper($key) . '_9f3b71';
            $canaries[$key] = $canary;
            $this->seed('settings', ['key' => $key, 'value' => $canary]);
        }

        $data = $this->callSettings('GET');
        foreach ($canaries as $key => $canary) {
            $this->assertSame(
                AdminSettingsController::maskedValue($key),
                $data[$key] ?? null,
                "敏感键 {$key} 必须返回掩码占位串"
            );
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        foreach ($canaries as $key => $canary) {
            $this->assertStringNotContainsString($canary, $json, "响应绝不能泄露 {$key} 明文");
        }

        // 公钥 / 普通配置不得被过度掩码
        $this->seed('settings', ['key' => 'alipay_public_key', 'value' => 'PUBLIC_CANARY']);
        $this->seed('settings', ['key' => 'site_keywords', 'value' => '关键词']);
        $data = $this->callSettings('GET');
        $this->assertSame('PUBLIC_CANARY', $data['alipay_public_key']);
        $this->assertSame('关键词', $data['site_keywords']);
    }

    public function testPostingMaskPlaceholderDoesNotOverwriteOriginal(): void
    {
        foreach (['wxpay_private_key', 'alipay_private_key', 'amap_web_key', 'license_key'] as $key) {
            $this->seed('settings', ['key' => $key, 'value' => 'ORIGINAL_' . $key]);
        }

        $this->callSettings('PUT', [
            'wxpay_private_key' => AdminSettingsController::maskedValue('wxpay_private_key'),
            'alipay_private_key' => AdminSettingsController::maskedValue('alipay_private_key'),
            'amap_web_key' => AdminSettingsController::maskedValue('amap_web_key'),
            'license_key' => AdminSettingsController::maskedValue('license_key'),
        ]);

        foreach (['wxpay_private_key', 'alipay_private_key', 'amap_web_key', 'license_key'] as $key) {
            $this->assertSame('ORIGINAL_' . $key, $this->storedValue($key), "占位串不得覆盖 {$key} 原值");
        }
    }

    public function testNonSensitiveKeyStillRoundTrips(): void
    {
        $this->seed('settings', ['key' => 'site_name', 'value' => '我圈']);
        $data = $this->callSettings('GET');
        $this->assertSame('我圈', $data['site_name']);

        $this->callSettings('PUT', ['site_name' => '新圈名']);
        $this->assertSame('新圈名', $this->storedValue('site_name'));

        $data = $this->callSettings('GET');
        $this->assertSame('新圈名', $data['site_name']);
    }
}
