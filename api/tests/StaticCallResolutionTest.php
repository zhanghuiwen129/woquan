<?php

declare(strict_types=1);

namespace Hublog\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * 跨类静态调用解析守卫（V167）
 *
 * 背景：V166 上线后发现 App 首页报
 *   "Call to undefined method Hublog\Controllers\AdminController::defaultPostTools()"
 * —— 上帝类拆分把方法搬进了子类 AdminSettingsController，而 AppController 里
 * 全限定名的静态调用 \AdminController::defaultPostTools() 没跟着改。
 * 这类缺陷现有四项静态核验（针对 $this->x() 与 use 段）与 RoutesAssertTest（针对路由 handler）
 * 都覆盖不到；/api/app/config 的冒烟也未包含，于是直接上了生产。
 *
 * 本用例把全仓 `ClassName::method(` 形态的静态调用全部扫出来，逐个断言：
 *   1) 目标类存在（仅校验 Hublog\* 项目内类；内置类 / 可选扩展类跳过）；
 *   2) 方法在本类或父链上存在（method_exists 覆盖继承）；
 *   3) 类链上有 __callStatic 时放行。
 *
 * 同批修复的第二个同类雷：ChatService 9 处 Database::exec()（Database 从无此方法，
 * 管理端批量删消息/清空会话路径一触发即 500）——本用例可防止它以任何形态复发。
 */
final class StaticCallResolutionTest extends TestCase
{
    public function testAllCrossClassStaticCallsResolve(): void
    {
        $issues = $this->scan();

        $this->assertSame(
            [],
            $issues,
            "存在解析不了的跨类静态调用（拆分类/搬方法后必查项）：\n" . implode("\n", $issues)
        );
    }

    /**
     * 扫描 api/ 下全部 PHP 文件（排除 tests），返回问题清单。
     *
     * @return list<string>
     */
    private function scan(): array
    {
        $root = dirname(__DIR__);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        $issues = [];
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $f->getPathname());
            if (str_contains($path, '/tests/')) {
                continue;
            }
            $rel  = substr($path, strlen($root) + 1);
            foreach ($this->scanFile($path) as $issue) {
                $issues[] = "{$rel}: {$issue}";
            }
        }
        return $issues;
    }

    /**
     * 单文件扫描：解析 namespace / use，收集 `Class::method(` 调用并校验。
     *
     * @return list<string>
     */
    private function scanFile(string $file): array
    {
        $src  = (string)file_get_contents($file);
        $toks = \PhpToken::tokenize($src);
        $n    = count($toks);
        $ns   = '';
        $useMap = [];
        $depth  = 0;
        $issues = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];

            if ($t->id === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $n && $toks[$j]->text !== ';'; $j++) {
                    if (!in_array($toks[$j]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $name .= $toks[$j]->text;
                    }
                }
                $ns = trim($name, '\\');
                continue;
            }

            // use 只认文件顶层（类体内的 use 是 trait 引入，不入别名表）
            if ($t->id === T_USE && $depth === 0) {
                $stmt = '';
                for ($j = $i + 1; $j < $n && $toks[$j]->text !== ';'; $j++) {
                    $stmt .= $toks[$j]->text;
                }
                foreach ($this->parseUse($stmt) as $alias => $fq) {
                    $useMap[strtolower($alias)] = $fq;
                }
                continue;
            }

            if ($t->text === '{') { $depth++; continue; }
            if ($t->text === '}') { $depth--; continue; }
            if ($t->text !== '::') { continue; }

            // 前向：方法名（T_STRING）+ '('；::class / 常量 / 属性都不是方法调用
            $k = $i + 1;
            while ($k < $n && in_array($toks[$k]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $k++;
            }
            if ($k >= $n || $toks[$k]->id !== T_STRING || $toks[$k]->text === 'class') {
                continue;
            }
            $method = $toks[$k]->text;
            $k2 = $k + 1;
            while ($k2 < $n && $toks[$k2]->id === T_WHITESPACE) {
                $k2++;
            }
            if (($toks[$k2]->text ?? '') !== '(') {
                continue;
            }

            // 回溯：类引用 token 段。注意 PHP 8 会把 `A\B` / `\A\B` 整体切成
            // T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED（不是 T_STRING+T_NS_SEPARATOR），
            // 漏掉这两类会让所有带命名空间的静态调用静默失察（V167 变异测试踩过）。
            $j = $i - 1;
            $parts = [];
            while ($j >= 0 && in_array($toks[$j]->id, [
                T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            ], true)) {
                $parts[] = $toks[$j]->text;
                $j--;
            }
            if ($parts === []) {
                continue;
            }
            $name = implode('', array_reverse($parts));
            if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                continue;
            }
            $prev = $toks[$j] ?? null;
            if ($prev !== null && in_array($prev->text, ['->', '?->'], true)) {
                continue; // $obj->X::y 等动态场景不校验
            }

            $fq = $this->resolve($name, $ns, $useMap);
            if (!str_starts_with($fq, 'Hublog\\')) {
                continue; // 内置类 / 可选扩展类不校验
            }
            if (!class_exists($fq, true)) {
                $issues[] = "静态调用目标类不存在：{$name} → \\{$fq}::{$method}()";
                continue;
            }
            if (method_exists($fq, $method)) {
                continue;
            }
            $hasMagic = false;
            for ($c = $fq; $c; $c = get_parent_class($c)) {
                if (method_exists($c, '__callStatic')) {
                    $hasMagic = true;
                    break;
                }
            }
            if (!$hasMagic) {
                $issues[] = "方法不存在：\\{$fq}::{$method}()（拆分类/搬方法后漏改的调用点？）";
            }
        }
        return $issues;
    }

    /** 由调用点上下文解析全限定类名 */
    private function resolve(string $name, string $ns, array $useMap): string
    {
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        if (str_contains($name, '\\')) {
            // 相对限定名：基于当前命名空间
            return ($ns === '' ? '' : $ns . '\\') . $name;
        }
        // 短名：先查 use 别名，再退回当前命名空间
        return $useMap[strtolower($name)] ?? (($ns === '' ? '' : $ns . '\\') . $name);
    }

    /**
     * 解析一条 use 语句体（不含 use 关键字与结尾分号）。
     * 项目内无 group use / function / const import（V167 实测），
     * 但 group use 做了基础展开以防将来引入时守卫静默失真。
     *
     * @return array<string, string> alias(lower) => FQCN
     */
    private function parseUse(string $stmt): array
    {
        $stmt = trim((string)preg_replace('/\s+/', ' ', $stmt));
        $out = [];
        if ($stmt === '' || str_starts_with($stmt, 'function ') || str_starts_with($stmt, 'const ')) {
            return $out;
        }
        if (preg_match('/^([\w\\\\]+)\\\\\{(.*)\}$/', $stmt, $m)) {
            foreach (explode(',', $m[2]) as $item) {
                if (preg_match('/^([\w\\\\]+)(?:\s+as\s+(\w+))?$/i', trim($item), $g)) {
                    $out[strtolower($g[2] ?? $g[1])] = $m[1] . '\\' . $g[1];
                }
            }
            return $out;
        }
        if (preg_match('/^([\w\\\\]+)(?:\s+as\s+(\w+))?$/i', $stmt, $m)) {
            $alias = $m[2] ?? array_slice(explode('\\', $m[1]), -1)[0];
            $out[strtolower($alias)] = $m[1];
        }
        return $out;
    }
}
