<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Core\PluginManifest;
use Hublog\Core\PluginSchemaMigrator;
use PHPUnit\Framework\TestCase;

/**
 * plugin.json 清单解析测试（E1-1 / E1-5）
 *
 * 重点：5 个内置插件无清单时必须完全走 docblock；非法字段 / core_version 非法
 * 只让该插件 invalid，不抛异常、不中断扫描主流程。
 */
final class PluginManifestTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $json = $dir . '/plugin.json';
            if (is_file($json)) {
                @unlink($json);
            }
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    /** 内置插件无 plugin.json：docblock 回退结果与改造前一致 */
    public function testBuiltinPluginsFallBackToDocblock(): void
    {
        $expected = [
            'AiComment' => 'AI 自动评论',
            'ContentAudit' => '智能内容审核',
            'SmartRecommend' => '个性化推荐（规则）',
            'SmartSearch' => '智能搜索',
            'SmartSummary' => '智能摘要与标签',
        ];
        foreach ($expected as $name => $title) {
            $dir = dirname(__DIR__, 2) . '/plugins/' . $name;
            $manifest = PluginManifest::fromDir($dir);
            $this->assertTrue($manifest->valid(), "{$name} 应能解析");
            $this->assertSame('docblock', $manifest->source());
            $this->assertSame($name, $manifest->name());
            $this->assertSame($title, $manifest->title());
            $this->assertSame('1.0.0', $manifest->version());
            $this->assertSame('*', $manifest->coreVersion());
            $this->assertTrue($manifest->supportsCore('2.16.29'));
        }
    }

    /** additionalProperties:false + 关键字扫描 */
    public function testUnknownFieldAndForbiddenKeywordAreRejected(): void
    {
        $dir = $this->writePlugin('ProbeBad', [
            'name' => 'ProbeBad',
            'ddl' => 'CREATE TABLE x',
            'title' => 'DROP TABLE users',
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertFalse($manifest->valid());
        $errors = implode(' ', $manifest->validate());
        $this->assertStringContainsString('不是允许的字段', $errors);
        $this->assertStringContainsString('禁止的 DDL 关键字', $errors);
    }

    public function testNameMustMatchDirectory(): void
    {
        $dir = $this->writePlugin('ProbeName', ['name' => 'OtherName']);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertFalse($manifest->valid());
        $this->assertStringContainsString('目录名', implode(' ', $manifest->errors()));
    }

    /** core_version 子集表达式解析 */
    public function testCoreVersionExpressionParsing(): void
    {
        $this->assertSame([], PluginManifest::parseCoreExpression('*'));
        $this->assertSame(
            [
                ['op' => '>=', 'version' => '2.16.0'],
                ['op' => '<', 'version' => '3.0.0'],
            ],
            PluginManifest::parseCoreExpression('>=2.16.0 <3.0.0')
        );
        $this->assertSame(
            [['op' => '>=', 'version' => '1.0.0']],
            PluginManifest::parseCoreExpression('1.0.0')
        );
        $this->assertSame(
            [['op' => '==', 'version' => '2.16.29']],
            PluginManifest::parseCoreExpression('=2.16.29')
        );
        $this->assertNull(PluginManifest::parseCoreExpression('>=abc'));
        $this->assertNull(PluginManifest::parseCoreExpression('>=2.16.0 *'));
    }

    /** 满足 / 不满足核心版本时的判定与可读原因 */
    public function testSupportsCoreAndReadableError(): void
    {
        $dir = $this->writePlugin('ProbeCore', [
            'name' => 'ProbeCore',
            'core_version' => '>=2.16.0 <3.0.0',
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertTrue($manifest->valid());
        $this->assertTrue($manifest->supportsCore('v2.16.29'));
        $this->assertNull($manifest->coreVersionError('2.16.29'));

        $this->assertFalse($manifest->supportsCore('2.15.0'));
        $error = (string)$manifest->coreVersionError('2.15.0');
        $this->assertStringContainsString('要求核心', $error);
        $this->assertStringContainsString('>=2.16.0 <3.0.0', $error);
    }

    public function testInvalidCoreVersionRejectsActivation(): void
    {
        $dir = $this->writePlugin('ProbeBadCore', [
            'name' => 'ProbeBadCore',
            'core_version' => '>=banana',
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertFalse($manifest->valid());
        $this->assertFalse($manifest->supportsCore('2.16.29'));
        $this->assertStringContainsString('core_version 表达式非法', implode(' ', $manifest->errors()));
    }

    /** varchar 必须有 length，type 必须在白名单 */
    public function testColumnSpecValidation(): void
    {
        $dir = $this->writePlugin('ProbeColumns', [
            'name' => 'ProbeColumns',
            'required_tables' => [[
                'name' => 'plugin_probe_columns',
                'columns' => [
                    ['name' => 'title', 'type' => 'varchar'],
                    ['name' => 'weird', 'type' => 'enum'],
                ],
            ]],
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $errors = implode(' ', $manifest->validate());
        $this->assertFalse($manifest->valid());
        $this->assertStringContainsString('varchar 必须声明 length', $errors);
        $this->assertStringContainsString('白名单类型', $errors);
    }

    /** 合法声明应无任何错误（供迁移器测试复用口径） */
    public function testValidManifestHasNoErrors(): void
    {
        $dir = $this->writePlugin('ProbeValid', [
            'name' => 'ProbeValid',
            'title' => '探针',
            'version' => '1.2.3',
            'core_version' => '>=2.16.0',
            'required_tables' => [[
                'name' => 'plugin_probe_valid',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'unsigned' => true, 'auto_increment' => true],
                    ['name' => 'note', 'type' => 'varchar', 'length' => 128],
                ],
                'primary_key' => 'id',
            ]],
        ]);
        $manifest = PluginManifest::fromDir($dir);
        $this->assertSame([], $manifest->errors());
        $this->assertSame([], PluginSchemaMigrator::validateAgainstContract($manifest));
    }

    /** @param array<string,mixed> $json */
    private function writePlugin(string $dirName, array $json): string
    {
        $base = sys_get_temp_dir() . '/plugin_manifest_' . bin2hex(random_bytes(6));
        @mkdir($base, 0775, true);
        // 目录名必须与清单 name 一致（schema 硬约束），所以子目录用固定名
        $dir = $base . '/' . $dirName;
        @mkdir($dir, 0775, true);
        file_put_contents(
            $dir . '/plugin.json',
            (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->tempDirs[] = $dir;
        $this->tempDirs[] = $base;
        return $dir;
    }
}
