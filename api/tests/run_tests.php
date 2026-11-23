<?php
/**
 * 自包含回归测试运行器
 *
 * 为什么不直接用 PHPUnit：本机没装 composer / phpunit.phar，也不保证能联网装。
 * 这个运行器用最小 TestCase stub 跑 api/tests 下的真实测试类，
 * 不依赖数据库（只调不依赖 DB 的静态方法 + 反射私有常量 + 解析 routes.php）。
 *
 * 用法：php api/tests/run_tests.php
 * 发现范围：License*Test.php（授权兼容矩阵）+ Phase0*Test.php（安全止血护栏）
 *           + ChatBatchATest.php（私信批次 A 回归护栏）
 *           + ChatBatchBTest.php（私信批次 B：幂等键 + 事务回归护栏）。
 *           + ChatBatchCTest.php（私信批次 C：V99 软删除语义 + 七 Trait 拆分）。
 * 其余 *Test.php 依赖真实 PHPUnit 生命周期 / 数据库 / 完整 autoloader，
 * 在这个 runner 里硬跑会产生与改动无关的假失败，装好 vendor 后用 phpunit 跑。
 */

// 1) 最小 PHPUnit\Framework\TestCase stub
namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

class TestCase
{
    private static array $results = [];

    protected function fail(string $msg): void
    {
        throw new AssertionFailedError($msg);
    }

    protected function assertSame($expected, $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailedError(
                $msg !== '' ? $msg : sprintf(
                    "assertSame failed\n  expected: %s\n  actual:   %s",
                    var_export($expected, true),
                    var_export($actual, true)
                )
            );
        }
    }

    protected function assertTrue($cond, string $msg = ''): void
    {
        if ($cond !== true) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'assertTrue failed: value is not true');
        }
    }

    protected function assertFalse($cond, string $msg = ''): void
    {
        if ($cond !== false) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'assertFalse failed: value is not false');
        }
    }

    protected function assertNotFalse($cond, string $msg = ''): void
    {
        if ($cond === false) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'assertNotFalse failed');
        }
    }

    protected function assertNotNull($cond, string $msg = ''): void
    {
        if ($cond === null) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'assertNotNull failed');
        }
    }

    protected function assertNull($cond, string $msg = ''): void
    {
        if ($cond !== null) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'assertNull failed');
        }
    }

    protected function assertGreaterThan($expected, $actual, string $msg = ''): void
    {
        if (!($actual > $expected)) {
            throw new AssertionFailedError($msg !== '' ? $msg : "assertGreaterThan failed: $actual <= $expected");
        }
    }

    protected function assertArrayHasKey($key, array $arr, string $msg = ''): void
    {
        if (!array_key_exists($key, $arr)) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'array has no key: ' . var_export($key, true));
        }
    }

    protected function assertNotContains($needle, iterable $haystack, string $msg = ''): void
    {
        foreach ($haystack as $v) {
            if ($v === $needle) {
                throw new AssertionFailedError($msg !== '' ? $msg : 'unexpectedly contains: ' . var_export($needle, true));
            }
        }
    }

    protected function assertContains($needle, iterable $haystack, string $msg = ''): void
    {
        foreach ($haystack as $v) {
            if ($v === $needle) {
                return;
            }
        }
        throw new AssertionFailedError($msg !== '' ? $msg : 'does not contain: ' . var_export($needle, true));
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $msg = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'string does not contain: ' . $needle);
        }
    }

    protected function assertFileExists(string $path, string $msg = ''): void
    {
        if (!is_file($path)) {
            throw new AssertionFailedError($msg !== '' ? $msg : 'file does not exist: ' . $path);
        }
    }

    public static function record(string $name, bool $ok, string $detail = ''): void
    {
        self::$results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    public static function summary(): int
    {
        $fail = 0;
        foreach (self::$results as $r) {
            printf("%s %s%s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['name'], $r['ok'] ? '' : '  -> ' . $r['detail']);
            if (!$r['ok']) {
                $fail++;
            }
        }
        printf("\n%d passed, %d failed\n", count(self::$results) - $fail, $fail);
        return $fail;
    }
}

// 2) 载入被测核心类（真实文件，含各自 namespace）
require_once __DIR__ . '/../core/License.php';
require_once __DIR__ . '/../middleware/LicenseMiddleware.php';

// 3) 自动发现可在自包含环境运行的测试文件并逐个执行 test* 方法
$files = array_merge(
    glob(__DIR__ . '/License*Test.php') ?: [],
    glob(__DIR__ . '/HeartbeatGate*Test.php') ?: [], // H-1：心跳上报门禁护栏（本地确定性断言）
    glob(__DIR__ . '/Phase0*Test.php') ?: [],
    glob(__DIR__ . '/ChatBatchATest.php') ?: [],
    glob(__DIR__ . '/ChatBatchBTest.php') ?: [],
    glob(__DIR__ . '/ChatBatchCTest.php') ?: [],
    glob(__DIR__ . '/FreshInstallSchemaTest.php') ?: []
);
sort($files);

$total = 0;
foreach ($files as $file) {
    require_once $file;
    $class = 'Hublog\\Tests\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    echo "--- " . basename($file) . " ---\n";
    $ref = new \ReflectionClass($class);
    $inst = $ref->newInstanceWithoutConstructor();
    foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $name = $m->getName();
        if (!str_starts_with($name, 'test')) {
            continue;
        }
        $total++;
        try {
            $m->invoke($inst);
            \PHPUnit\Framework\TestCase::record($name, true);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            \PHPUnit\Framework\TestCase::record($name, false, $e->getMessage());
        } catch (\Throwable $e) {
            \PHPUnit\Framework\TestCase::record($name, false, 'THROW: ' . $e->getMessage());
        }
    }
}

if ($total === 0) {
    fwrite(STDERR, "no test methods found\n");
    exit(2);
}
exit(\PHPUnit\Framework\TestCase::summary() > 0 ? 1 : 0);
