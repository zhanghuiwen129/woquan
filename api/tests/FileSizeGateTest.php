<?php
/**
 * 单文件行数门禁自测（P1-2，unit）
 *
 * 用临时 fixture 构造四种输入：增长 → FAIL、缩减 → PASS、新增大文件 → FAIL、
 * CRLF / LF 同一逻辑行数 → PASS。不依赖仓库当前状态，也不修改真实基线。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Docs\FileSizeBaselineChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

require_once dirname(__DIR__, 2) . '/scripts/check-file-size.php';

final class FileSizeGateTest extends TestCase
{
    private const THRESHOLD = 300;

    /** @var list<string> 本用例创建的临时根目录 */
    private static array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach (self::$tempRoots as $root) {
            self::removeTree($root);
        }
        self::$tempRoots = [];
        parent::tearDown();
    }

    public function testGrowingRecordedFileFails(): void
    {
        $root = $this->newRoot();
        $baselinePath = $root . '/baseline.json';
        $relative = 'api/core/Repo.php';
        $this->write($root, $relative, $this->phpFile(5));

        $baseline = FileSizeBaselineChecker::makeBaseline(
            FileSizeBaselineChecker::scan($root, ['api/core']),
            self::THRESHOLD,
            ['api/core']
        );
        $baseline['files'] = [$relative => 5];
        $baseline['count'] = 1;
        FileSizeBaselineChecker::writeBaseline($baselinePath, $baseline);

        $this->write($root, $relative, $this->phpFile(6));
        $result = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);

        $this->assertSame(1, $result['exit_code']);
        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame('GREW', $result['violations'][0]['type']);
        $this->assertSame($relative, $result['violations'][0]['path']);
        $this->assertSame(5, $result['violations'][0]['baseline']);
        $this->assertSame(6, $result['violations'][0]['current']);
        $this->assertStringContainsString('GREW ' . $relative . ': 5 → 6', $result['violations'][0]['message']);
        foreach (['ok', 'violations', 'scanned', 'skipped', 'exempted', 'threshold'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testShrinkingOrDeletingRecordedFilePasses(): void
    {
        $root = $this->newRoot();
        $baselinePath = $root . '/baseline.json';
        $relative = 'api/core/Repo.php';
        FileSizeBaselineChecker::writeBaseline($baselinePath, [
            'note' => 'test',
            'threshold' => self::THRESHOLD,
            'checker_version' => 1,
            'dirs' => ['api/core'],
            'exempt' => [],
            'count' => 1,
            'files' => [$relative => 10],
        ]);

        $this->write($root, $relative, $this->phpFile(3));
        $shrunken = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(0, $shrunken['exit_code']);
        $this->assertSame([], $shrunken['violations']);

        unlink($root . '/' . $relative);
        $deleted = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(0, $deleted['exit_code']);
        $this->assertSame([], $deleted['violations']);
    }

    public function testNewFileOverThresholdFailsWhileSmallerFilesPass(): void
    {
        $root = $this->newRoot();
        $baselinePath = $root . '/baseline.json';
        FileSizeBaselineChecker::writeBaseline($baselinePath, [
            'note' => 'test',
            'threshold' => self::THRESHOLD,
            'checker_version' => 1,
            'dirs' => ['api/core'],
            'exempt' => [],
            'count' => 0,
            'files' => [],
        ]);

        $relative = 'api/core/MovedBloat.php';
        $this->write($root, $relative, $this->phpFile(self::THRESHOLD + 1));
        $tooBig = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(1, $tooBig['exit_code']);
        $this->assertSame('NEW_OVER_THRESHOLD', $tooBig['violations'][0]['type']);
        $this->assertSame(self::THRESHOLD + 1, $tooBig['violations'][0]['current']);

        $this->write($root, $relative, $this->phpFile(self::THRESHOLD));
        $atThreshold = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(0, $atThreshold['exit_code'], '恰好等于阈值不算超阈值');

        $this->write($root, $relative, $this->phpFile(self::THRESHOLD - 1));
        $belowThreshold = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(0, $belowThreshold['exit_code']);
    }

    public function testCrLfAndLfCountAsSameLogicalLines(): void
    {
        $lf = "<?php\n// a\n// b\n";
        $crlf = "<?php\r\n// a\r\n// b\r\n";
        $cr = "<?php\r// a\r// b\r";

        $this->assertSame(3, FileSizeBaselineChecker::countLogicalLines($lf));
        $this->assertSame(3, FileSizeBaselineChecker::countLogicalLines($crlf));
        $this->assertSame(3, FileSizeBaselineChecker::countLogicalLines($cr));
        $this->assertSame(0, FileSizeBaselineChecker::countLogicalLines(''));
        $this->assertSame(1, FileSizeBaselineChecker::countLogicalLines("\n"));

        $rootLf = $this->newRoot();
        $rootCrLf = $this->newRoot();
        $this->write($rootLf, 'api/core/Same.php', $lf);
        $this->write($rootCrLf, 'api/core/Same.php', $crlf);
        $scanLf = FileSizeBaselineChecker::scan($rootLf, ['api/core']);
        $scanCrLf = FileSizeBaselineChecker::scan($rootCrLf, ['api/core']);
        $this->assertSame($scanLf['files'], $scanCrLf['files']);
    }

    public function testExemptGeneratedFileIsNotFrozen(): void
    {
        $root = $this->newRoot();
        $this->write($root, 'api/core/MigrationContract.php', $this->phpFile(self::THRESHOLD + 1));
        $scan = FileSizeBaselineChecker::scan($root, ['api/core']);

        $this->assertSame([], $scan['files']);
        $this->assertSame(1, $scan['exempted']);

        $baselinePath = $root . '/baseline.json';
        FileSizeBaselineChecker::writeBaseline($baselinePath, [
            'note' => 'test',
            'threshold' => self::THRESHOLD,
            'checker_version' => 1,
            'dirs' => ['api/core'],
            'exempt' => FileSizeBaselineChecker::DEFAULT_EXEMPT,
            'count' => 0,
            'files' => [],
        ]);
        $result = FileSizeBaselineChecker::evaluate($root, $baselinePath, self::THRESHOLD);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(1, $result['exempted']);
    }

    /** 生成 n 行 PHP 形状的文本（首行是标签）。 */
    private function phpFile(int $lines): string
    {
        if ($lines <= 0) {
            return '';
        }
        return "<?php\n" . str_repeat("// padding\n", $lines - 1);
    }

    private function newRoot(): string
    {
        $root = sys_get_temp_dir() . '/hublog_filesize_' . bin2hex(random_bytes(6));
        if (!@mkdir($root . '/api/core', 0777, true) && !is_dir($root . '/api/core')) {
            $this->fail('无法创建临时目录: ' . $root);
        }
        self::$tempRoots[] = $root;
        return str_replace('\\', '/', $root);
    }

    private function write(string $root, string $relative, string $content): void
    {
        $path = $root . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->fail('无法创建临时子目录: ' . $dir);
        }
        if (@file_put_contents($path, $content) === false) {
            $this->fail('无法写入临时文件: ' . $path);
        }
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $info) {
            if ($info->isDir()) {
                @rmdir($info->getPathname());
            } else {
                @unlink($info->getPathname());
            }
        }
        @rmdir($root);
    }
}
