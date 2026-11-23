<?php
/**
 * 日志 URI 脱敏行为回归（批次 7 / P1-2）
 *
 * access 日志保留 14 天；cron 的 ?secret=、后台的 ?api_key=、实名的 ?id_card=、
 * 登录的 ?phone= 此前会明文落盘。未捕获异常的 ctx 里塞入原始 REQUEST_URI 时，
 * Logger::write() 只 json_encode 上下文，不过 sanitizeUri，同样会泄露。
 * 这里用临时日志目录真实调用 Logger，验证落盘内容而不是只看源码字符串。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Logger;
use ReflectionClass;

final class LoggerUriSanitizationBehaviorTest extends BehaviorTestCase
{
    private ?string $originalLogDir = null;
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new ReflectionClass(Logger::class);
        $prop = $ref->getProperty('logDir');
        $prop->setAccessible(true);
        $this->originalLogDir = $prop->getValue();
        $this->tempDir = sys_get_temp_dir() . '/hublog_logger_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0777, true);
        $prop->setValue(null, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Logger::class);
        $prop = $ref->getProperty('logDir');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->originalLogDir);

        if ($this->tempDir !== '') {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
            $this->tempDir = '';
        }
        parent::tearDown();
    }

    private function loggedContent(): string
    {
        $files = glob($this->tempDir . '/hublog-*.log') ?: [];
        $this->assertNotSame([], $files, '日志文件应已写入');
        return (string)file_get_contents($files[0]);
    }

    public function testSensitiveQueryParamsAreMaskedAndPlainParamsSurvive(): void
    {
        $this->assertSame(
            '/api/cron/jobs/status?secret=***&token=***&page=1',
            Logger::sanitizeUri('/api/cron/jobs/status?secret=abcd1234&token=xyz&page=1')
        );

        $masked = Logger::sanitizeUri(
            '/api/admin/ai?api_key=AK_RAW&id_card=110101199001011234'
            . '&phone=13800138000&mobile=13900139000&signature=SIG_RAW&page=2'
        );
        foreach (['AK_RAW', '110101199001011234', '13800138000', '13900139000', 'SIG_RAW'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
        $this->assertStringContainsString('api_key=***', $masked);
        $this->assertStringContainsString('id_card=***', $masked);
        $this->assertStringContainsString('phone=***', $masked);
        $this->assertStringContainsString('page=2', $masked, '普通参数不得被误伤');

        // 锚定 [?&]，路径段里的同名单词不能被改。
        $this->assertSame(
            '/api/secret/phone/id_card?page=1',
            Logger::sanitizeUri('/api/secret/phone/id_card?page=1')
        );
    }

    public function testContextUriFieldsAreSanitizedBeforeJsonEncode(): void
    {
        Logger::error('uri_ctx_probe', [
            'request_uri' => '/api/auth/phone?phone=13800138000&id_card=110101199001011234&secret=RAWSECRET&page=1',
            'url'         => '/api/admin/ai?api_key=RAWKEY',
            'referer'     => '/from?phone=RAWPHONE',
            'keep'        => 'secret=not-a-uri-field',
        ]);

        $content = $this->loggedContent();
        $this->assertStringContainsString(
            '"request_uri":"/api/auth/phone?phone=***&id_card=***&secret=***&page=1"',
            $content
        );
        $this->assertStringContainsString('"url":"/api/admin/ai?api_key=***"', $content);
        $this->assertStringContainsString('"referer":"/from?phone=***"', $content);
        foreach (['13800138000', '110101199001011234', 'RAWSECRET', 'RAWKEY', 'RAWPHONE'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $this->assertStringContainsString(
            '"keep":"secret=not-a-uri-field"',
            $content,
            '非 URI 字段不应被脱敏'
        );
    }
}
