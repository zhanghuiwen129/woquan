<?php
/**
 * S6 / S7 行为护栏：真实触发致命错误与弱密钥启动，检查「用户最终看到什么」。
 *
 * Phase0SecurityGuardTest 断言的是入口文件里出现了哪些函数名；这里在子进程里
 * 真正 registerFatalHandler() 并 trigger_error()，验证生产响应确实不含绝对路径、
 * DEBUG 才回显细节、弱密钥确实阻止后续代码执行。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use function Hublog\Bootstrap\installLockPath;
use function Hublog\Bootstrap\isInstalled;

// 根 bootstrap.php 只定义函数与常量、无启动副作用；PHPUnit 的测试引导并未加载它。
require_once dirname(__DIR__, 3) . '/bootstrap.php';

final class BootstrapBehaviorTest extends BehaviorTestCase
{
    private function probe(string $case): array
    {
        return $this->runIsolatedRaw('bootstrap_probe.php', ['HUBLOG_CASE' => $case]);
    }

    private function parseJsonLine(string $output): array
    {
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        $this->fail('子进程未输出合法 JSON 行: ' . trim($output));
        return [];
    }

    public function testProductionApiFatalNeverLeaksPathOrRawMessage(): void
    {
        [$exit, $output] = $this->probe('fatal_json_prod');
        $this->assertSame(255, $exit, 'E_USER_ERROR 应让 PHP 以致命错误退出码结束');

        $body = $this->parseJsonLine($output);
        $this->assertSame(500, $body['code']);
        $this->assertNull($body['data']);
        $this->assertStringContainsString('服务器内部错误', $body['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $body['error_id']);
        $this->assertArrayNotHasKey('debug', $body, '生产不得回显 file/line');
        $this->assertStringNotContainsString('SECRET_MARKER', $output);
        $this->assertStringNotContainsString('D:/woquan', $output);
        $this->assertStringNotContainsString('bootstrap_probe.php', $output);
    }

    public function testDebugFatalDoesRevealDetailsForLocalDevelopment(): void
    {
        [$exit, $output] = $this->probe('fatal_json_debug');
        $this->assertSame(255, $exit);

        $body = $this->parseJsonLine($output);
        $this->assertStringContainsString('SECRET_MARKER', $body['message']);
        $this->assertArrayHasKey('debug', $body);
        $this->assertStringContainsString('bootstrap_probe.php', $body['debug']['file']);
        $this->assertIsInt($body['debug']['line']);
    }

    public function testFrontendFatalIsAlsoSanitized(): void
    {
        // 前端入口曾无条件回显「消息 + 文件 + 行」，这里确认两个入口水位一致
        [$exit, $output] = $this->probe('fatal_html_prod');
        $this->assertSame(255, $exit);
        $this->assertStringContainsString('服务器内部错误', $output);
        $this->assertStringNotContainsString('SECRET_MARKER', $output);
        $this->assertStringNotContainsString('D:/woquan', $output);
        $this->assertStringNotContainsString('文件:', $output);
        $this->assertMatchesRegularExpression('/[0-9a-f]{8}/', $output, '应给出可查日志的 error_id');
    }

    public function testWeakJwtSecretBlocksStartupInBothEntrypoints(): void
    {
        [$exit, $json] = $this->probe('jwt_weak');
        $this->assertSame(0, $exit);
        $body = $this->parseJsonLine($json);
        $this->assertSame(500, $body['code']);
        $this->assertStringContainsString('JWT_SECRET', $body['message']);
        $this->assertStringNotContainsString('reached', $json, '弱密钥必须阻止后续代码执行');

        [$exit, $html] = $this->probe('jwt_weak_html');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('服务器配置错误', $html);
        $this->assertStringNotContainsString('reached', $html);
    }

    public function testStrongJwtSecretAllowsStartup(): void
    {
        [$exit, $output] = $this->probe('jwt_strong');
        $this->assertSame(0, $exit);
        $this->assertTrue($this->parseJsonLine($output)['reached']);
    }

    public function testInstallLockLivesAtRootAndLegacyLockStillCounts(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_lock_behavior_' . bin2hex(random_bytes(6));
        @mkdir($root, 0777, true);
        @mkdir($root . '/cache', 0777, true);

        try {
            $this->assertFalse(isInstalled($root));
            $lockPath = str_replace('\\', '/', installLockPath($root));
            $this->assertStringEndsWith('/.installed.lock', $lockPath);
            $this->assertStringStartsWith(
                str_replace('\\', '/', $root),
                $lockPath,
                '安装锁必须在站点根目录，而不是 cache/ 下'
            );

            file_put_contents($root . '/.installed.lock', 'installed');
            $this->assertTrue(isInstalled($root), '新锁应判定为已安装');

            unlink($root . '/.installed.lock');
            file_put_contents($root . '/cache/install.lock', 'legacy');
            $this->assertTrue(isInstalled($root), '老部署的 cache/install.lock 也必须兼容');

            unlink($root . '/cache/install.lock');
            $this->assertFalse(isInstalled($root));
        } finally {
            @unlink($root . '/.installed.lock');
            @unlink($root . '/cache/install.lock');
            @rmdir($root . '/cache');
            @rmdir($root);
        }
    }
}
