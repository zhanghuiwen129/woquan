<?php
/**
 * SSR head 注入器行为回归（架构评审 P1-6 / R12）
 *
 * 验证的是真实调用结果，不是源码文本：
 *   - 多次 add() 后 apply() 的片段顺序与换行；
 *   - 含 </head>（含大小写变体）的污染片段被拒绝且不影响其余片段；
 *   - 文档无 </head> / 无占位符时不崩溃、不篡改文档；
 *   - apply() 后全文只剩一个 </head>；
 *   - 与改造前的 7 次 str_replace 管线逐字节等价。
 *
 * Logger::error() 会写 logs/ 并走 error_log()，这里把两者重定向到系统临时目录，
 * 测试不碰工作区 logs/，也不污染 PHPUnit 输出。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\HeadInjector;
use Hublog\Core\Logger;
use ReflectionProperty;

final class HeadInjectorBehaviorTest extends BehaviorTestCase
{
    private string $logDir = '';
    private string $previousLogDir = '';
    private string $previousErrorLog = '';
    private string $previousErrorLogIni = '';

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new ReflectionProperty(Logger::class, 'logDir');
        $ref->setAccessible(true);
        $this->previousLogDir = (string)$ref->getValue();
        $this->logDir = sys_get_temp_dir() . '/hublog_headinjector_' . bin2hex(random_bytes(6));
        @mkdir($this->logDir, 0777, true);
        $ref->setValue(null, $this->logDir);

        $this->previousErrorLogIni = (string)ini_get('error_log');
        $this->previousErrorLog = $this->logDir . '/php-error.log';
        ini_set('error_log', $this->previousErrorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLogIni);

        $ref = new ReflectionProperty(Logger::class, 'logDir');
        $ref->setAccessible(true);
        $ref->setValue(null, $this->previousLogDir);

        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->logDir);

        parent::tearDown();
    }

    public function testAddThenApplyPreservesOrderAndNewlines(): void
    {
        $injector = new HeadInjector();
        $injector->add('<meta name="a" content="1">');
        $injector->add('<link rel="canonical" href="https://example.com/">');
        $injector->add('<script>window.x=1;</script>');

        // 模拟 index.php：占位符替换掉了原来的 </head>，故此处只剩一个占位符
        $document = '<html><head>' . HeadInjector::PLACEHOLDER . '</html>';
        $this->assertSame(
            '<html><head><meta name="a" content="1">' . "\n"
            . '<link rel="canonical" href="https://example.com/">' . "\n"
            . '<script>window.x=1;</script>' . "\n</head></html>",
            $injector->apply($document)
        );
    }

    public function testSnippetContainingHeadCloseIsRejectedWithoutBreakingOthers(): void
    {
        $injector = new HeadInjector();
        $injector->add('<meta name="ok-before" content="1">');
        $injector->add('<meta name="evil" content="x</head><script>alert(1)</script>">');
        $injector->add('<meta name="evil-upper" content="x</HEAD>">');
        $injector->add('<meta name="ok-after" content="2">');

        $result = $injector->apply('<html>' . HeadInjector::PLACEHOLDER . '</html>');

        $this->assertSame(
            '<html><meta name="ok-before" content="1">' . "\n"
            . '<meta name="ok-after" content="2">' . "\n</head></html>",
            $result
        );
        $this->assertStringNotContainsString('alert(1)', $result);
        $this->assertStringNotContainsString('evil-upper', $result);

        // 拒绝必须留下可审计的 error 日志；这里读的是临时目录里的文件，不是工作区 logs/
        $logs = '';
        foreach (glob($this->logDir . '/hublog-*.log') ?: [] as $file) {
            $logs .= (string)file_get_contents($file);
        }
        $this->assertStringContainsString('HeadInjector', $logs);
        $this->assertSame(2, substr_count($logs, 'HeadInjector'));
    }

    public function testDocumentWithoutHeadCloseOrPlaceholderIsUntouched(): void
    {
        $injector = new HeadInjector();
        $injector->add('<meta name="a" content="1">');

        $noHead = '<html><body>骨架缺少 head 闭合标签</body></html>';
        $this->assertSame($noHead, $injector->apply($noHead));

        // 有 </head> 但没有占位符（apply 重复调用）也必须原样返回
        $alreadyApplied = '<html><head><meta name="a" content="1">' . "\n</head></html>";
        $this->assertSame($alreadyApplied, $injector->apply($alreadyApplied));

        // 没有累积片段时不写占位符，文档保持不变
        $empty = new HeadInjector();
        $pending = '<html>' . HeadInjector::PLACEHOLDER . '</html>';
        $this->assertSame($pending, $empty->apply($pending));
    }

    public function testApplyLeavesExactlyOneHeadClose(): void
    {
        $injector = new HeadInjector();
        $injector->add('<meta property="og:title" content="t">');
        $injector->add('<script type="application/ld+json">{}</script>');

        $template = '<html><head><title>t</title></head><body>b</body></html>';
        $document = str_replace('</head>', HeadInjector::PLACEHOLDER, $template);
        $result = $injector->apply($document);

        $this->assertSame(1, substr_count($result, '</head>'));
        $this->assertSame(0, substr_count($result, HeadInjector::PLACEHOLDER));
        $this->assertSame(
            '<html><head><title>t</title><meta property="og:title" content="t">' . "\n"
            . '<script type="application/ld+json">{}</script>' . "\n</head><body>b</body></html>",
            $result
        );
    }

    public function testOutputMatchesLegacySevenSequentialReplacementsByteForByte(): void
    {
        $snippets = [
            '<script>window.HUBLOG_BASE_URL="/";</script>',
            '<meta property="og:title" content="t">',
            '<script type="application/ld+json">{"@type":"WebSite"}</script>',
            '<link rel="canonical" href="https://example.com/">',
            '<meta name="robots" content="noindex,follow">',
            '<script>window.__HUBLOG_POST__={"id":1};</script>',
            '<script type="application/ld+json">{"@type":"BreadcrumbList"}</script>',
        ];

        // 改造前：7 次 str_replace，每次把片段插到当前 </head> 前
        $legacy = '<html><head></head><body></body></html>';
        foreach ($snippets as $snippet) {
            $legacy = str_replace('</head>', $snippet . "\n</head>", $legacy);
        }

        // 改造后：一次占位符替换
        $injector = new HeadInjector();
        foreach ($snippets as $snippet) {
            $injector->add($snippet);
        }
        $document = str_replace('</head>', HeadInjector::PLACEHOLDER, '<html><head></head><body></body></html>');
        $this->assertSame($legacy, $injector->apply($document));
        $this->assertSame(hash('sha256', $legacy), hash('sha256', $injector->apply($document)));
    }
}
