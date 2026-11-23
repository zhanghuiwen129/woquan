<?php
/**
 * V161 回归测试：私密动态 + 定时发布的守卫必须真实生效。
 *
 * 背景：V160 拆分时发现历史潜藏缺陷——`validateCreateInput()` 里
 *   `if ($visibility === 'private' && $isScheduled)`
 * 位于 `$isScheduled` 赋值之前，导致「私密动态不支持定时发布」从未被拦截
 * （语法正确、逻辑死代码）。V161 把定时解析块提到了守卫之前修复。
 *
 * 本测试以「结构性断言」直接守护这个修复：在 validateCreateInput 方法体内，
 * `$isScheduled` 的初始化必须出现在私密+定时守卫之前；一旦有人把解析块挪回守卫之后，
 * 测试立即失败，防止缺陷回归。
 *
 * 该测试不连接数据库，与现有 RoutesAssertTest 风格一致，可在无 MySQL 环境运行：
 *   cd 项目根
 *   ./vendor/bin/phpunit -c api/phpunit.xml
 *
 * 注：完整的「行为级」集成测试（真正发起 create 请求并断言返回 400 + 该文案）
 * 需要 DB + Moderation 等运行时依赖，属于 P2-1 phpunit 回归保护补齐后的下一步，
 * 届时可在本文件追加 testPrivateScheduledRejectedBehavioral()。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PostControllerScheduledPrivateTest extends TestCase
{
    private function controllerSource(): string
    {
        // V160 之后 create/update 逻辑已拆到 PostCrudTrait / PostUpdateTrait。
        // 改用「控制器 + 全部 Trait」的逻辑源码视图，保持护栏在重构后依然有效。
        $files = \Hublog\Tests\SourceView::postControllerFiles();
        foreach ($files as $file) {
            $this->assertFileExists($file, '视图组成文件必须存在: ' . $file);
        }
        return \Hublog\Tests\SourceView::postController();
    }

    /**
     * V165：判断是否为纯注释行（整行注释），用于排除「注释里提到同一段文案」造成的误命中。
     */
    private function isCommentLine(string $line): bool
    {
        return (bool)preg_match('/^\s*(\/\/|\*|\/\*|#)/', $line);
    }

    /**
     * 提取某个方法的行数组（含签名行到下一个同级 `private/public/protected function` 之前）。
     * @return array<int,string>
     */
    private function methodLines(string $src, string $methodName): array
    {
        if (!preg_match('/^\s*(?:public|private|protected)\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/m', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("未找到方法 {$methodName}");
        }
        $start = strrpos(substr($src, 0, $m[0][1]), "\n") ?: 0;
        // 找到方法签名后的第一个 '{' 作为方法体起点
        $bodyStart = strpos($src, '{', $m[0][1]);
        if ($bodyStart === false) {
            $this->fail("方法 {$methodName} 未找到方法体");
        }
        // 简单括号匹配定位方法体结束
        $depth = 0;
        $end = $bodyStart;
        for ($i = $bodyStart; $i < strlen($src); $i++) {
            $c = $src[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($src, $bodyStart, $end - $bodyStart);
        return explode("\n", $body);
    }

    public function testIsScheduledAssignedBeforePrivateGuard(): void
    {
        $src = $this->controllerSource();
        $lines = $this->methodLines($src, 'validateCreateInput');

        $assignLine = null;   // $isScheduled = false; 初始化行（排除 $out['isScheduled']）
        $guardLine = null;    // 私密 + 定时 守卫行

        foreach ($lines as $idx => $line) {
            // V165：必须跳过注释行。第 517 行那段解释性注释里恰好也写了「私密动态不支持定时发布」，
            // 若不限定「真实代码行」，会先命中注释导致误判为「守卫在赋值之前」（假失败）。
            if ($this->isCommentLine($line)) {
                continue;
            }
            // 排除 $out['isScheduled'] 之类的读取，只认真正的赋值
            if ($assignLine === null && preg_match('/\$isScheduled\s*=\s*(false|true|!\s*empty)/', $line) && strpos($line, '$out[') === false) {
                $assignLine = $idx;
            }
            if ($guardLine === null && strpos($line, '私密动态不支持定时发布') !== false) {
                $guardLine = $idx;
            }
        }

        $this->assertNotNull($assignLine, '应在 validateCreateInput 内找到 $isScheduled 的初始化');
        $this->assertNotNull($guardLine, '应在 validateCreateInput 内找到「私密动态不支持定时发布」守卫');

        $this->assertLessThan(
            $guardLine,
            $assignLine,
            "回归缺陷：\$isScheduled 的初始化（行 {$assignLine}）必须出现在私密+定时守卫（行 {$guardLine}）之前，"
            . "否则守卫永远读到未初始化的 null 而失效。"
        );
    }

    public function testPrivateScheduledGuardTextPresent(): void
    {
        $src = $this->controllerSource();
        $this->assertStringContainsString(
            "if (\$visibility === 'private' && \$isScheduled)",
            $src,
            '私密+定时守卫应保留'
        );
        $this->assertStringContainsString(
            "return Response::error('私密动态不支持定时发布', 400);",
            $src,
            '私密+定时拦截文案应保留'
        );
    }

    public function testUpdatePrivateScheduledGuardPresent(): void
    {
        $src = $this->controllerSource();
        $lines = $this->methodLines($src, 'resolveUpdateFields');
        $body = implode("\n", $lines);

        $this->assertStringContainsString(
            '私密动态不支持定时发布',
            $body,
            'resolveUpdateFields 应加入与 create 对称的「私密+定时」守卫'
        );
        $this->assertStringContainsString(
            'effectiveVisibility',
            $body,
            '守卫应基于「合并既有帖 + 增量」后的最终可见范围判定'
        );
        $this->assertStringContainsString(
            'effectiveScheduled',
            $body,
            '守卫应基于「合并既有帖 + 增量」后的最终是否定时判定'
        );
    }
}
