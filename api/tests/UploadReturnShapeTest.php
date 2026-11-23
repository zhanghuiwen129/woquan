<?php

declare(strict_types=1);

namespace Hublog\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * 上传函数返回值形状守卫（V167）
 *
 * 背景：`Upload::file()` / `Upload::image()` 两个方法都返回 **数组 `[url, hash]`**，
 * 但老调用点习惯写成 `$url = Upload::file(...)`，于是 `$url` 是数组。
 * 后果分两类，且都不报错、只在运行/前端暴露：
 *   1) 写库：数组被强转成字符串 "Array" 落进 `uploads.url` / `users.cover`；
 *   2) 下发前端：私信语音/视频/文件的 `extra.url` 变成 JSON 数组，
 *      App 端 `createInnerAudioContext().src = 数组` → 直接「播放失败」；
 *      且发送成功后 App 取 `data.message`（本不存在）导致本地消息没被替换，
 *      `extra.url` 仍为空 → 点播放提示「语音未上传完成」。
 *
 * 本用例扫描全仓 `Upload::file(` / `Upload::image(` 调用点，要求**左侧必须是列表解构**
 * （`[$url] = ` 或 `[$url, $hash] = `）；否则视为回归。
 */
final class UploadReturnShapeTest extends TestCase
{
    public function testUploadResultsAreListDestructured(): void
    {
        $issues = $this->scan();

        $this->assertSame(
            [],
            $issues,
            "上传函数返回 [url, hash]，必须用列表解构接收：\n" . implode("\n", $issues)
        );
    }

    /**
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
            foreach ($this->scanFile($path) as [$line, $method]) {
                $issues[] = "{$rel}:{$line} Upload::{$method}()";
            }
        }
        return $issues;
    }

    /**
     * 返回未解构的调用点：[[行号, 方法名], ...]
     *
     * @return list<array{0:int,1:string}>
     */
    private function scanFile(string $file): array
    {
        $src  = (string)file_get_contents($file);
        $toks = \PhpToken::tokenize($src);
        $n    = count($toks);
        $bad  = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if ($t->id !== T_STRING) {
                continue;
            }
            $method = strtolower($t->text);
            if (!in_array($method, ['file', 'image'], true)) {
                continue;
            }

            // 左侧应当是 `Upload` + `::`
            $j = $i - 1;
            while ($j >= 0 && $toks[$j]->id === T_WHITESPACE) {
                $j--;
            }
            if ($j < 0 || $toks[$j]->text !== '::') {
                continue;
            }
            $k = $j - 1;
            while ($k >= 0 && $toks[$k]->id === T_WHITESPACE) {
                $k--;
            }
            if ($k < 0 || $toks[$k]->id !== T_STRING || strtolower($toks[$k]->text) !== 'upload') {
                continue;
            }

            // 合法形态有两种：
            //   ① 列表解构：token 序是 `[` `$url` `]` `=` `Upload`  → 左邻是 `=`、再左是 `]`
            //   ② 整体返回：如 `return Upload::file(...)`               → 左邻不是 `=`
            // 只有「左邻是 `=` 且再左是普通变量」才是把数组当字符串用的误用。
            $p = $k - 1;
            while ($p >= 0 && in_array($toks[$p]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $p--;
            }
            if ($p < 0 || $toks[$p]->text !== '=') {
                continue; // 非赋值（如 return 整个数组），不判为回归
            }
            $q = $p - 1;
            while ($q >= 0 && in_array($toks[$q]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $q--;
            }
            if ($q < 0 || $toks[$q]->text === ']') {
                continue; // 列表解构，正确
            }
            if ($toks[$q]->id === T_VARIABLE) {
                $bad[] = [$toks[$i]->line, $method];
            }
        }
        return $bad;
    }
}
