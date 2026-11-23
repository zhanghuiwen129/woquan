<?php
/**
 * S2 上传白名单缺口的行为级护栏。
 *
 * Phase0SecurityGuardTest 只能证明「代码里写了 FORBIDDEN_EXTS」；这里真实造一个
 * 临时文件走完 validateFile → 扩展名校验 → 秒传查询 → 落盘，确认危险后缀确实被拒、
 * 合法文件确实落盘且文件名被收敛。ChatController 当初漏传 $allowedExts 的路径
 * 在这里用「不传白名单参数」原样复现。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\Upload;
use ReflectionClass;

final class UploadBehaviorTest extends BehaviorTestCase
{
    private string $uploadDir = '';
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_upload_behavior_' . bin2hex(random_bytes(6));
        @mkdir($this->uploadDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->removeTree($this->uploadDir);
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * 造一个「服务端自产临时文件」——validateFile 允许 hublog_vid_ 前缀的临时目录文件，
     * 这正是 VideoCompress 压缩产物的真实路径形态，避免在 CLI 下被 is_uploaded_file 拦掉。
     */
    private function makeFile(string $originalName, string $content = 'hublog behavior test payload'): array
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hublog_vid_behavior_' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;

        return [
            'name'     => $originalName,
            'tmp_name' => $tmp,
            'size'     => strlen($content),
            'error'    => UPLOAD_ERR_OK,
        ];
    }

    /** 显式传 maxSize，避免读取生产 config 里才有的 UPLOAD_MAX_SIZE */
    private function upload(array $file, ?array $allowedExts = null): array
    {
        return Upload::file($file, $this->uploadDir, 1024 * 1024, null, $allowedExts);
    }

    private function assertRejected(array $file, ?array $allowedExts = null): void
    {
        try {
            $this->upload($file, $allowedExts);
            $this->fail('危险文件不应被接受: ' . $file['name']);
        } catch (HublogException $e) {
            $this->assertSame('不支持的文件格式', $e->getMessage(), '必须死在扩展名白名单上');
        }
    }

    public function testDefaultAllowedExtsNeverIntersectForbiddenExts(): void
    {
        $ref = new ReflectionClass(Upload::class);
        $forbidden = $ref->getReflectionConstant('FORBIDDEN_EXTS')->getValue();
        $allowed = Upload::defaultAllowedExts();

        $this->assertNotEmpty($allowed);
        $this->assertSame([], array_intersect($allowed, $forbidden), '默认白名单不得含任何危险后缀');
        foreach (['html', 'svg', 'xml', 'php', 'phtml', 'js', 'exe'] as $danger) {
            $this->assertContains($danger, $forbidden);
            $this->assertNotContains($danger, $allowed);
        }
    }

    public function testDangerousExtensionsAreRejectedWhenWhitelistIsOmitted(): void
    {
        // 复现 ChatController::type=file 的调用形态：$allowedExts 为 null
        foreach (['evil.html', 'evil.svg', 'evil.xml', 'shell.php', 'evil.phtml', 'evil.js'] as $name) {
            $this->assertRejected($this->makeFile($name));
        }
        $this->assertSame([], $this->rows('uploads'), '被拒文件不得入库');
    }

    public function testForbiddenExtensionsWinEvenIfCallerExplicitlyAllowsThem(): void
    {
        // 调用方误把 html 写进白名单时，FORBIDDEN_EXTS 仍必须兜住
        $this->assertRejected($this->makeFile('evil.html'), ['html', 'svg', 'txt']);
        $this->assertRejected($this->makeFile('evil.svg'), ['html', 'svg', 'txt']);
    }

    public function testEmptyExtensionIsRejected(): void
    {
        $this->assertRejected($this->makeFile('noextension'));
    }

    public function testAllowedAttachmentIsReallySavedAndReturnsServerComputedHash(): void
    {
        $content = 'plain text attachment ' . bin2hex(random_bytes(8));
        $result = $this->upload($this->makeFile('report.txt', $content));

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertStringContainsString('report.txt', $result[0]);
        $this->assertSame(hash('sha256', $content), $result[1], 'hash 必须服务端重算');

        $saved = $this->findSavedFiles();
        $this->assertCount(1, $saved, '应有且仅有一个物理文件落盘');
        $this->assertSame($content, file_get_contents($saved[0]));
    }

    public function testMultiDotFilenameIsCollapsedToSingleSafeExtension(): void
    {
        // Apache mod_mime 会把 evil.php.txt 的中间 .php 当脚本解析；文件名必须收敛
        $this->upload($this->makeFile('evil.php.txt'));

        $saved = $this->findSavedFiles();
        $this->assertCount(1, $saved);
        $basename = basename($saved[0]);
        $this->assertStringEndsWith('.txt', $basename);
        $this->assertStringContainsString('evil_php.txt', $basename, '中间的点必须替换为下划线');
        $this->assertSame(1, substr_count($basename, '.'), '落盘文件名只允许一个点');
    }

    private function findSavedFiles(): array
    {
        if (!is_dir($this->uploadDir)) {
            return [];
        }
        $out = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $out[] = $item->getPathname();
            }
        }
        return $out;
    }
}
