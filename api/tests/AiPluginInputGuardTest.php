<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\AiService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 批次8 P2 输入判定回归：
 *   1. AI API 的 isLocal 必须按 host 精确判定，path/query 含 "ollama"/"localhost" 不算本地；
 *   2. AI 违规判定只认判定词整体，"不违规" 不得被误判。
 */
final class AiPluginInputGuardTest extends TestCase
{
    public function testPathKeywordDoesNotTurnRemoteUrlIntoLocal(): void
    {
        // 修复前 isLocal=true → allowHttp/allowLocalAi=true → 明文 HTTP 放行且关闭 TLS 校验。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SSRF');
        AiService::chatCompletion('sys', 'msg', [
            'api_url'    => 'http://8.8.8.8/ollama/v1/chat/completions',
            'api_key'    => 'sk-leak-canary',
        ]);
    }

    public function testIsLocalUsesParsedHostNotSubstring(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__) . '/core/AiService.php');
        $this->assertStringContainsString('parse_url($apiUrl, PHP_URL_HOST)', $src);
        $this->assertStringNotContainsString("str_contains(\$apiUrl, 'ollama')", $src);
        $this->assertStringNotContainsString("str_contains(\$apiUrl, 'localhost')", $src);

        $isLocal = static function (string $url): bool {
            $host = (string)parse_url($url, PHP_URL_HOST);
            return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || (bool)preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
        };
        $this->assertTrue($isLocal('http://127.0.0.1:11434/api/chat'));
        $this->assertTrue($isLocal('http://localhost/v1'));
        $this->assertFalse($isLocal('https://attacker.tld/ollama/v1'));
        $this->assertFalse($isLocal('https://attacker.tld/?next=localhost'));
        $this->assertFalse($isLocal('https://127.0.0.1.attacker.tld/v1'));
    }

    public function testAuditVerdictRequiresExactToken(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/plugins/ContentAudit/Plugin.php');
        $this->assertStringContainsString("preg_match('/^\\s*(违规|violation|non-compliant)\\s*$/iu'", $src);
        $this->assertStringNotContainsString("str_contains(\$reply, '违规')", $src);

        $pattern = '/^\s*(违规|violation|non-compliant)\s*$/iu';
        $this->assertSame(1, preg_match($pattern, trim('违规')));
        $this->assertSame(1, preg_match($pattern, trim(' VIOLATION ')));
        $this->assertSame(0, preg_match($pattern, trim('不违规')));
        $this->assertSame(0, preg_match($pattern, trim('内容不违规，请放行')));
    }
}
