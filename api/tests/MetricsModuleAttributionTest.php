<?php
/**
 * /metrics 模块归因（module= 标签）回归测试
 *
 * 覆盖三层：
 *  1) ModuleCatalog::moduleForUri() —— 复用长前缀优先规则，query / 子目录部署不影响归因；
 *  2) Logger::access() —— access 日志确实落了 module 字段，且 token 值仍被脱敏；
 *  3) MetricsController::aggregateByModule() —— 新日志读字段、旧日志按 uri 推算，
 *     脏 module 值归一到 core，不撑爆 Prometheus 标签基数。
 *
 * 本文件不纳入 api/tests/run_tests.php（那个 runner 只跑 License 与 Phase0 开头的测试），
 * 仅由 phpunit -c api/phpunit.xml 发现。
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Hublog\Controllers\MetricsController;
use Hublog\Core\Logger;
use Hublog\Core\ModuleCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class MetricsModuleAttributionTest extends TestCase
{
    public function testModuleForUriUsesLongestPrefixAndStripsQuery(): void
    {
        self::assertSame('comments', ModuleCatalog::moduleForUri('/api/site/comments/42?token=abc'));
        self::assertSame('shop', ModuleCatalog::moduleForUri('/api/points/wheel?page=1'));
        self::assertSame('topics', ModuleCatalog::moduleForUri('/hublog/api/posts/topic/7'));
        self::assertSame('core', ModuleCatalog::moduleForUri('/api/metrics?token=abc'));
        self::assertSame('core', ModuleCatalog::moduleForUri('/health'));

        // 生产真实形态：PATH_INFO 部署下 REQUEST_URI 带 /index.php 段，必须先剥除，
        // 否则 module 恒为 core，四个按模块指标全部塌缩成单一标签。
        self::assertSame('friend_links', ModuleCatalog::moduleForUri('/api/index.php/site/friend-links'));
        self::assertSame('shop', ModuleCatalog::moduleForUri('/api/index.php/points/wheel?page=1'));
        self::assertSame('topics', ModuleCatalog::moduleForUri('/hublog/api/index.php/topics/7'));
        self::assertSame('comments', ModuleCatalog::moduleForUri('/api/INDEX.PHP/site/comments/9'));
        self::assertSame('reports', ModuleCatalog::moduleForUri('/index.php/reports/3'));
    }

    public function testAccessLogLineCarriesModuleField(): void
    {
        $dir = sys_get_temp_dir() . '/hublog_module_log_' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::markTestSkipped('无法创建临时日志目录');
        }

        $prop = new ReflectionProperty(Logger::class, 'logDir');
        $prop->setAccessible(true);
        $previous = $prop->getValue();
        try {
            $prop->setValue(null, $dir);
            Logger::access('GET', '/api/site/comments/1?token=abc', 200, 12.5, '7', '127.0.0.1', null, null);

            $file = $dir . '/access-' . date('Y-m-d') . '.log';
            self::assertFileExists($file);
            $lines = array_values(array_filter(explode("\n", (string)@file_get_contents($file)), 'strlen'));
            self::assertNotEmpty($lines);
            $record = json_decode((string)end($lines), true);
            self::assertIsArray($record);
            self::assertSame('comments', $record['module'] ?? null);
            self::assertStringNotContainsString('abc', (string)($record['uri'] ?? ''), 'token 值仍须脱敏');
        } finally {
            $prop->setValue(null, $previous);
            self::removeDir($dir);
        }
    }

    public function testAggregateByModuleGroupsStatusAndDuration(): void
    {
        $records = [
            ['uri' => '/api/site/comments/1', 'status' => 200, 'duration_ms' => 12.5, 'module' => 'comments'],
            ['uri' => '/api/site/comments/2', 'status' => 500, 'duration_ms' => 30.0, 'module' => 'comments'],
            ['uri' => '/api/chat/list',       'status' => 404, 'duration_ms' => 5.5,  'module' => 'chat'],
            // 旧日志：没有 module 字段，按 uri 现场推算
            ['uri' => '/api/reports/3',       'status' => 200, 'duration_ms' => 2.0],
            // 脏 module：归一到 core，防止标签基数被撑爆
            ['uri' => '/api/metrics',         'status' => 200, 'duration_ms' => 1.0, 'module' => 'not-a-module'],
        ];
        $content = implode("\n", array_map(
            static fn(array $r): string => (string)json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $records
        ));
        // 追加一行截断的半行 JSON，应被静默跳过
        $content .= "\n{\"uri\":\"/api/site/comm";

        $method = new ReflectionMethod(MetricsController::class, 'aggregateByModule');
        $method->setAccessible(true);
        /** @var array<string,array{total:int,client_errors:int,server_errors:int,duration_sum_ms:float}> $result */
        $result = $method->invoke(null, $content);

        self::assertSame(['chat', 'comments', 'core', 'reports'], array_keys($result));
        self::assertSame(2, $result['comments']['total']);
        self::assertSame(0, $result['comments']['client_errors']);
        self::assertSame(1, $result['comments']['server_errors']);
        self::assertSame(42.5, $result['comments']['duration_sum_ms']);
        self::assertSame(1, $result['chat']['client_errors']);
        self::assertSame(2.0, $result['reports']['duration_sum_ms']);
        self::assertSame(1, $result['core']['total']);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
