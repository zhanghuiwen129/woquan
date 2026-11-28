<?php
/**
 * CI 接线护栏（P1-2，unit）
 *
 * 结构性断言：防止 6.10 段或行数门禁脚本被误删后 CI 静默失去保护。
 * 只检查接线源码文本与 composer 别名，不执行子门禁脚本（其自身行为由 FileSizeGateTest 覆盖）。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use PHPUnit\Framework\TestCase;

final class CiGateWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testCiContainsFileSizeSectionAndInvocation(): void
    {
        $ci = (string)file_get_contents(self::ROOT . '/scripts/ci.php');
        $this->assertStringContainsString('6.10 单文件行数只减不增门禁', $ci);
        $this->assertStringContainsString('check-file-size.php', $ci);
        $this->assertStringContainsString("'--check'", $ci);
        $this->assertStringContainsString('report(', $ci);
        $this->assertStringContainsString('6.9 客户端 API 契约对账', $ci, '不得误删既有 6.9 段');
    }

    public function testBothGateScriptsExist(): void
    {
        $this->assertFileExists(self::ROOT . '/scripts/ci.php');
        $this->assertFileExists(self::ROOT . '/scripts/check-file-size.php');
        $this->assertFileExists(self::ROOT . '/docs/file_size_baseline.json');
    }

    public function testComposerAliasesAreDeclared(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertArrayHasKey('scripts', $composer);
        $scripts = $composer['scripts'];
        $this->assertArrayHasKey('gate:filesize', $scripts);
        $this->assertArrayHasKey('gate:filesize:write', $scripts);
        $this->assertStringContainsString('check-file-size.php', (string)$scripts['gate:filesize']);
        $this->assertStringContainsString('--check', (string)$scripts['gate:filesize']);
    }
}
