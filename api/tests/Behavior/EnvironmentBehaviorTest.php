<?php
/**
 * 底座自检：确保行为测试真的跑在隔离环境里，而不是连到了某个真实数据库。
 *
 * 这组断言是整套 behavior 套件的安全前提：一旦有人改了底座让 Database 回退到
 * config.php 的 MySQL DSN，CI 会立刻变红，而不是悄悄扫到开发机上的库。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Cache;
use Hublog\Core\Database;
use PDO;

final class EnvironmentBehaviorTest extends BehaviorTestCase
{
    public function testDatabaseUsesInMemorySqliteAndNeverMysql(): void
    {
        $pdo = Database::getInstance();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertSame(1, (int)$pdo->query('SELECT 1')->fetchColumn(), '内存库必须可真实查询');
    }

    public function testSchemaIsFreshPerTestCase(): void
    {
        // 每个用例一个新内存库：上一条用例写入的数据不应出现在本用例里
        $this->assertSame([], $this->rows('phone_codes'));

        $this->seed('phone_codes', [
            'phone'      => '13800138000',
            'code'       => '123456',
            'scene'      => 'login',
            'expires_at' => date('Y-m-d H:i:s', time() + 300),
        ]);

        $rows = $this->rows('phone_codes');
        $this->assertCount(1, $rows);
        $this->assertSame('13800138000', $rows[0]['phone']);
        $this->assertNotEmpty($rows[0]['created_at'], 'created_at 应有数据库默认值');
    }

    public function testCacheIsIsolatedIntoTempDirectory(): void
    {
        $dir = Cache::getCacheDir();
        $this->assertStringContainsString('hublog_behavior_', $dir);
        $this->assertStringNotContainsString(
            'woquan' . DIRECTORY_SEPARATOR . 'cache',
            $dir,
            '缓存目录不得指向项目真实 cache/，否则测试会污染站点缓存'
        );

        Cache::set('probe_key', 'probe_value', 60);
        $this->assertSame('probe_value', Cache::get('probe_key'));
        Cache::flush();
        $this->assertNull(Cache::get('probe_key'));
    }

    public function testSmsProviderIsDisabledSoNoOutboundRequest(): void
    {
        $rows = $this->rows('settings');
        $this->assertCount(1, $rows);
        $this->assertSame('sms_provider', $rows[0]['key']);
        $this->assertSame('none', $rows[0]['value']);
    }
}
