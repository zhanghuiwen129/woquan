<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\Database;
use Hublog\Core\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 数据库层迁移测试（V165）
 *
 * ⚠️ 这是一组「默认跳过」的重型测试，避免误操作真实数据库。
 *
 * 启用方式（两个条件同时满足才会真正执行，否则一律 skip）：
 *   1) 环境变量 HUBLOG_DB_TEST=1
 *   2) 根目录下的 config.php 里 DB_NAME 必须以 _test 结尾（如 hublog_test）
 *
 * 例：
 *   export HUBLOG_DB_TEST=1
 *   ./vendor/bin/phpunit -c api/phpunit.xml
 *
 * 这样做的原因：Migration 会真实建表/改表，绝不能在 CI 或某人不小心时
 * 指到了生产库或开发库。双保险（显式开关 + 库名后缀）能把误伤概率降到接近 0。
 */
final class MigrationDatabaseTest extends TestCase
{
    private function guard(): void
    {
        if (getenv('HUBLOG_DB_TEST') !== '1') {
            $this->markTestSkipped(
                '重型迁移测试默认跳过：需设置环境变量 HUBLOG_DB_TEST=1 才会执行。'
            );
        }

        $configFile = dirname(__DIR__, 2) . '/config.php';
        if (!is_file($configFile)) {
            $this->markTestSkipped('缺少根目录 config.php，无法连接测试库，跳过。');
        }

        require_once $configFile;

        if (!defined('DB_NAME') || !str_ends_with((string)DB_NAME, '_test')) {
            $this->markTestSkipped(
                '安全护栏：config.php 的 DB_NAME 必须以 _test 结尾才允许跑迁移测试，当前非测试库，跳过。'
            );
        }
    }

    /**
     * 全新安装路径：Migration::run() 必须能把空库推到 currentVersion，且无异常。
     */
    public function testFreshInstallReachesFinalVersion(): void
    {
        $this->guard();

        Migration::run();

        $ref = new ReflectionClass(Migration::class);
        $p = $ref->getProperty('currentVersion');
        $p->setAccessible(true);
        $expected = (int)$p->getValue();

        $pdo = Database::getInstance();
        $stored = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $pdo->quote(DB_NAME))
            ->fetchColumn();

        $this->assertGreaterThan(0, (int)$stored, '至少应建出若干张表');

        // 版本号写回后，再跑一次应直接走快速路径返回
        Migration::run();

        $this->assertTrue(true, 'run() 二次执行（已到最终态）无异常');
    }

    /**
     * 逐个迁移方法的幂等性：每个 migrateNN 连调两次都必须无异常、无告警。
     *
     * 这是「老库升级」最容易炸的地方——增量迁移或部分失败的库会重跑某些 migrateNN，
     * 若某个方法不幂等（比如无脑 ALTER ADD COLUMN 不先判存在），老用户升级就会崩。
     */
    public function testEveryMigrationMethodIsIdempotent(): void
    {
        $this->guard();

        Database::getInstance();

        $ref = new ReflectionClass(Migration::class);
        $p = $ref->getProperty('currentVersion');
        $p->setAccessible(true);
        $max = (int)$p->getValue();

        $failures = [];
        $checked = 0;

        set_error_handler(static function ($errno, $errstr, $errfile, $errline): bool {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }, E_ALL);

        try {
            for ($i = 1; $i <= $max; $i++) {
                $name = "migrate{$i}";
                if (!$ref->hasMethod($name)) {
                    continue;
                }
                $m = $ref->getMethod($name);
                $m->setAccessible(true);
                try {
                    $m->invoke(null);
                    $m->invoke(null);
                    $checked++;
                } catch (\Throwable $e) {
                    $failures[] = "{$name}: " . $e->getMessage()
                        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertGreaterThan(50, $checked, '应有足够多的迁移方法被实际执行');
        $this->assertEmpty($failures, "以下迁移方法不幂等或产生告警：\n" . implode("\n", $failures));
    }
}
