<?php
/**
 * 行为级测试：cron 鉴权唯一真源（api/core/CronAuth.php）
 *
 * unit 套件（api/tests/CronAuthTest.php）覆盖的是「常量优先级」与「空 secret 拒绝」，
 * 都在不连库的环境里完成。本文件补上走数据库的那一半 —— 真的建表、真的读写
 * settings、真的跑 ensureSecret，验证副作用：
 *
 *   1) 未配置时 secret() 为空、verify() 一律拒绝（外部入口 = 关闭）
 *   2) 配置在 settings 表里时，secret() 能读到，verify() 只认正确的 token
 *   3) ensureSecret() 在没有配置时生成一个 32 位随机十六进制串并落库
 *   4) ensureSecret() 在已有配置时保持原值（不会把运维配好的 secret 冲掉）
 *   5) ensureSecret() 在「行存在但值为空」时补写（后台被清空后能自愈）
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\CronAuth;

final class CronAuthBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CronAuth 内部有进程级缓存，换库前必须先失效，否则会读到上一个用例的 secret
        CronAuth::reset();
    }

    protected function tearDown(): void
    {
        CronAuth::reset();
        parent::tearDown();
    }

    /** 读 settings 表里某个 key 的当前值 */
    private function settingValue(string $key): ?string
    {
        $rows = $this->rows('settings');
        foreach ($rows as $row) {
            if ((string)($row['key'] ?? '') === $key) {
                return (string)($row['value'] ?? '');
            }
        }
        return null;
    }

    public function testUnconfiguredSecretIsEmptyAndRejectsEverything(): void
    {
        $this->assertSame('', CronAuth::secret());
        $this->assertFalse(CronAuth::isConfigured());
        $this->assertSame('none', CronAuth::source());

        $this->assertFalse(CronAuth::verify(''));
        $this->assertFalse(CronAuth::verify(null));
        $this->assertFalse(CronAuth::verify('any-token'));
    }

    public function testSecretIsReadFromSettingsTable(): void
    {
        $this->seed('settings', ['key' => 'cron_secret', 'value' => 'db-secret-token']);
        CronAuth::reset();

        $this->assertSame('db-secret-token', CronAuth::secret());
        $this->assertTrue(CronAuth::isConfigured());
        $this->assertSame('database', CronAuth::source());

        $this->assertTrue(CronAuth::verify('db-secret-token'));
        $this->assertFalse(CronAuth::verify('wrong-token'));
        $this->assertFalse(CronAuth::verify(''));
        $this->assertFalse(CronAuth::verify(null));
    }

    public function testEnsureSecretGeneratesAndPersistsWhenMissing(): void
    {
        $secret = CronAuth::ensureSecret();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret, '必须是 32 位随机十六进制串');
        $this->assertSame($secret, $this->settingValue('cron_secret'), '生成的 secret 必须落库');
        $this->assertTrue(CronAuth::verify($secret), '生成后应立刻可用');
    }

    public function testEnsureSecretKeepsExistingValue(): void
    {
        $this->seed('settings', ['key' => 'cron_secret', 'value' => 'existing-token']);
        CronAuth::reset();

        $this->assertSame('existing-token', CronAuth::ensureSecret(), '已有配置时不得被覆盖');
        $this->assertSame('existing-token', $this->settingValue('cron_secret'));
    }

    public function testEnsureSecretFillsEmptyExistingRow(): void
    {
        $this->seed('settings', ['key' => 'cron_secret', 'value' => '']);
        CronAuth::reset();

        $secret = CronAuth::ensureSecret();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret);
        $this->assertSame($secret, $this->settingValue('cron_secret'), '值为空时应当补写');
    }

    public function testEnsureSecretIsIdempotent(): void
    {
        $first = CronAuth::ensureSecret();
        CronAuth::reset();

        $second = CronAuth::ensureSecret();

        $this->assertSame($first, $second, '重复调用不得换掉已生成的 secret');
        $this->assertSame($first, $this->settingValue('cron_secret'));
    }
}
