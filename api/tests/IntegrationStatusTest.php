<?php
/**
 * 外部依赖状态：5 项判定 + 不泄露密钥的护栏。
 * 走 BehaviorTestCase 的 SQLite 内存库，不连真实 MySQL、不发外部请求。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\Cache;
use Hublog\Core\IntegrationStatus;
use Hublog\Tests\Behavior\BehaviorTestCase;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

final class IntegrationStatusTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(
            'CREATE TABLE `sensitive_words` ('
            . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '`word` TEXT NOT NULL, `hit_count` INTEGER NOT NULL DEFAULT 0)'
        );
    }

    /** 写设置后清掉相关缓存，避免 License / RealName 的 remember 拿到旧值 */
    private function setSetting(string $key, string $value): void
    {
        $this->pdo()
            ->prepare('INSERT OR REPLACE INTO `settings` (`key`, `value`) VALUES (?, ?)')
            ->execute([$key, $value]);
        Cache::delete('site_settings');
        Cache::delete('realname_settings');
    }

    private function item(string $key): array
    {
        foreach (IntegrationStatus::checkAll() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        $this->fail('缺少状态项: ' . $key);
    }

    public function testShapeAndNoSecretLeak(): void
    {
        $secrets = [
            'trtc_secret_key'    => 'LEAK_CANARY_TRTC_SECRET_12345',
            'ai_comment_api_key' => 'LEAK_CANARY_AI_COMMENT_12345',
            'ai_audit_api_key'   => 'LEAK_CANARY_AI_AUDIT_12345',
            'wxpay_private_key'  => 'LEAK_CANARY_WXPAY_PRIVATE_12345',
            'wxpay_api_v3_key'   => 'LEAK_CANARY_WXPAY_V3_12345',
            'alipay_private_key' => 'LEAK_CANARY_ALIPAY_PRIVATE_12345',
        ];
        foreach ($secrets as $key => $value) {
            $this->setSetting($key, $value);
        }
        $this->setSetting('trtc_sdk_app_id', '1400000123');

        $all = IntegrationStatus::checkAll();
        $this->assertCount(5, $all);
        $this->assertSame(['trtc', 'realname', 'ai', 'payment', 'moderation'], array_column($all, 'key'));
        $json = json_encode($all, JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $canary) {
            $this->assertStringNotContainsString($canary, $json, '状态卡片泄露了配置值');
        }
        $this->assertStringNotContainsString('1400000123', $json, '状态卡片泄露了 SDKAppID');
        foreach ($all as $item) {
            $this->assertSame(['key', 'label', 'ok', 'reason'], array_keys($item));
            $this->assertIsBool($item['ok']);
            $this->assertIsString($item['reason']);
        }
    }

    public function testTrtcUnconfiguredDoesNotPointToMissingAdminSetting(): void
    {
        $item = $this->item('trtc');
        $this->assertFalse($item['ok']);
        $this->assertStringContainsString('后台暂无配置入口', $item['reason']);
        $this->assertStringContainsString('联系管理员', $item['reason']);
    }

    public function testTrtcConfigured(): void
    {
        $this->setSetting('trtc_sdk_app_id', '1400000123');
        $this->setSetting('trtc_secret_key', 'some-secret');
        $this->assertTrue($this->item('trtc')['ok']);
    }

    public function testRealnameNeedsEnabledAndCredentials(): void
    {
        $this->assertFalse($this->item('realname')['ok']);
        $this->setSetting('realname_enabled', '1');
        $this->setSetting('realname_appcode', 'some-appcode');
        $this->assertTrue($this->item('realname')['ok']);
    }

    public function testAiDistinguishesUnconfiguredDefaultOffAndEnabled(): void
    {
        $this->assertStringContainsString('未配置', $this->item('ai')['reason']);

        $this->setSetting('ai_comment_api_key', 'key');
        $off = $this->item('ai');
        $this->assertFalse($off['ok']);
        $this->assertStringContainsString('默认关闭', $off['reason']);
        $this->assertStringContainsString('cron', $off['reason']);

        $this->setSetting('ai_comment_enabled', '1');
        $on = $this->item('ai');
        $this->assertTrue($on['ok']);
        $this->assertStringContainsString('cron', $on['reason']);
    }

    public function testPaymentStates(): void
    {
        $this->assertFalse($this->item('payment')['ok']);

        $this->setSetting('pay_enabled', '1');
        $this->setSetting('pay_wxpay_enabled', '1');
        $incomplete = $this->item('payment');
        $this->assertFalse($incomplete['ok']);
        $this->assertStringContainsString('不完整', $incomplete['reason']);

        foreach ([
            'wxpay_appid', 'wxpay_mchid', 'wxpay_serial_no',
            'wxpay_private_key', 'wxpay_api_v3_key',
        ] as $key) {
            $this->setSetting($key, 'value');
        }
        $ready = $this->item('payment');
        $this->assertTrue($ready['ok']);
        $this->assertStringContainsString('微信支付', $ready['reason']);
    }

    public function testModerationEmptyAndNonEmpty(): void
    {
        $empty = $this->item('moderation');
        $this->assertFalse($empty['ok']);
        $this->assertStringContainsString('词库为空，过滤不生效', $empty['reason']);

        $this->pdo()->exec("INSERT INTO `sensitive_words` (`word`) VALUES ('违禁词')");
        $this->assertTrue($this->item('moderation')['ok']);
    }
}
