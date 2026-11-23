<?php
/**
 * Logger 错误通道行为测试
 *
 * 背景：Logger::write() 曾按小写 'error' 判断，而三个公开入口传入大写级别，
 * 导致 error 级额外写一份 PHP error_log 的分支成为死代码。静态字符串断言
 * 无法发现这类「代码存在但从不到达」的缺陷，这里改为真实行为验证：
 * 把 error_log 重定向到临时文件，断言 error 级确实写入、info/warning 不写。
 *
 * 运行：phpunit --testsuite unit --filter LoggerErrorChannelTest
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Hublog\Core\Logger;
use PHPUnit\Framework\TestCase;

class LoggerErrorChannelTest extends TestCase
{
    /** 把 error_log 重定向到临时文件执行回调，结束后恢复 ini 并清理文件 */
    private function capturePhpErrorLog(callable $fn): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hublog_errlog_');
        if ($tmp === false) {
            self::markTestSkipped('无法创建临时文件用于 error_log 捕获');
        }
        $previous = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            $fn();
            clearstatcache(true, $tmp);
            return (string)@file_get_contents($tmp);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($tmp);
        }
    }

    public function testErrorLevelAlsoWritesPhpErrorLog(): void
    {
        $probe = 'HUBLOG_F1_ERR_PROBE_' . bin2hex(random_bytes(6));
        $captured = $this->capturePhpErrorLog(static function () use ($probe): void {
            Logger::error($probe);
        });
        $this->assertStringContainsString(
            $probe,
            $captured,
            'Logger::error 必须额外写一份 PHP error_log（细节留服务端，供运维监控）'
        );
        $this->assertStringContainsString('[Hublog]', $captured, 'error_log 行应带 [Hublog] 前缀');
    }

    public function testInfoAndWarningDoNotWritePhpErrorLog(): void
    {
        $info = 'HUBLOG_F1_INFO_PROBE_' . bin2hex(random_bytes(6));
        $warn = 'HUBLOG_F1_WARN_PROBE_' . bin2hex(random_bytes(6));
        $captured = $this->capturePhpErrorLog(static function () use ($info, $warn): void {
            Logger::info($info);
            Logger::warning($warn);
        });
        $this->assertStringNotContainsString($info, $captured, 'info 级不应写 PHP error_log');
        $this->assertStringNotContainsString($warn, $captured, 'warning 级不应写 PHP error_log');
    }

    public function testErrorLogContentStaysOnServerSideNotInMessage(): void
    {
        // 护栏语义：写入 error_log 的是完整消息；业务层不得把细节拼进回客户端的文案。
        // 这里只验证 Logger 自身写出的是原始消息（含上下文 JSON），不回显通道由调用方保证。
        $probe = 'HUBLOG_F1_CTX_PROBE_' . bin2hex(random_bytes(6));
        $captured = $this->capturePhpErrorLog(static function () use ($probe): void {
            Logger::error($probe, ['k' => 'v']);
        });
        $this->assertStringContainsString($probe, $captured);
        // error_log 行只含 msg + [Hublog] 前缀，不含上下文 JSON（上下文只进结构化日志）
        $this->assertStringNotContainsString('"k":"v"', $captured);
    }
}
