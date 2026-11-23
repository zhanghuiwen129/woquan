<?php
/**
 * 源码视图：把「控制器 + 它拆分出的 Trait」拼成一个逻辑源码视图。
 *
 * 背景：PostController 在 V160 之后被拆成 PostCrudTrait / PostUpdateTrait /
 * PostFeedTrait 等 8 个 trait，但早期一批结构性回归测试仍只读
 * PostController.php —— 于是「重构」本身会制造 13 个假失败，CI 一上线就是红的。
 *
 * 这些断言守护的是行为约束（守卫赋值顺序、游标 envelope、过滤条件共用），
 * 与代码物理上放在哪个文件无关，所以视图应当跟随「类」而不是跟随「单个文件」。
 *
 * 这里刻意只做字符串拼接、不做 tokenize：
 *   - PhpToken / 正则在合并视图上依然可用；
 *   - token->line 变成合并后的全局行号，但同一方法内的相对顺序不变，
 *     行号比较类断言（如 V161 守卫顺序）仍然成立；
 *   - 后续再拆 trait、合并 trait 都不会让护栏失效。
 *
 * 注意：这不是「行为级测试」的替代品，只是让结构性护栏不因重构而误报。
 * 真正的行为级测试（连库发请求）仍属阶段 1 后续工作。
 */

declare(strict_types=1);

namespace Hublog\Tests;

final class SourceView
{
    private static ?string $postController = null;
    private static ?string $chatService = null;

    /** PostController 类及其全部 Trait 的合并源码 */
    public static function postController(): string
    {
        if (self::$postController !== null) {
            return self::$postController;
        }

        $dir  = dirname(__DIR__) . '/controllers';
        $main = $dir . '/PostController.php';
        if (!is_file($main)) {
            throw new \RuntimeException('PostController.php 缺失: ' . $main);
        }

        $traits = glob($dir . '/Post*Trait.php') ?: [];
        sort($traits, SORT_STRING); // 固定顺序，保证拼接结果可复现
        $files = array_merge([$main], $traits);

        $src = '';
        foreach ($files as $file) {
            $src .= (string)file_get_contents($file) . "\n";
        }

        return self::$postController = $src;
    }

    /** @return list<string> 组成视图的文件（诊断/报错用） */
    public static function postControllerFiles(): array
    {
        $dir  = dirname(__DIR__) . '/controllers';
        $traits = glob($dir . '/Post*Trait.php') ?: [];
        sort($traits, SORT_STRING);
        return array_merge([$dir . '/PostController.php'], $traits);
    }

    /**
     * ChatService 类及其全部 Trait 的合并源码。
     *
     * 与 postController() 同一思路：V99 之后 ChatService 的 37 个方法被拆到
     * 7 个 Chat*Trait.php，旧护栏只读主类会集体假红。
     *
     * 拼接顺序固定为「主类 + 排序后的 trait 文件」：ChatSendTrait 排在最后，
     * 其中 sendMessage 仍早于 markAsRead，ChatBatchBTest 的 strpos 截取依赖此顺序。
     */
    public static function chatService(): string
    {
        if (self::$chatService !== null) {
            return self::$chatService;
        }

        $files = self::chatServiceFiles();
        $src = '';
        foreach ($files as $file) {
            $src .= (string)file_get_contents($file) . "\n";
        }

        return self::$chatService = $src;
    }

    /** @return list<string> 组成 ChatService 视图的文件（诊断/报错用） */
    public static function chatServiceFiles(): array
    {
        $dir  = dirname(__DIR__) . '/core';
        $main = $dir . '/ChatService.php';
        if (!is_file($main)) {
            throw new \RuntimeException('ChatService.php 缺失: ' . $main);
        }
        $traits = glob($dir . '/Chat*Trait.php') ?: [];
        sort($traits, SORT_STRING); // 固定顺序，保证拼接结果可复现
        return array_merge([$main], $traits);
    }

    /**
     * 从 ChatService 逻辑视图里精确截取一个方法的「签名 + 花括号方法体」。
     *
     * 用 token_get_all 而非正则：花括号配对要考虑字符串插值里的 T_CURLY_OPEN，
     * 正则会在 chat_unread_{$userId} 这类字符串上提前收尾。找不到时返回空串。
     */
    public static function methodSource(string $method): string
    {
        $src = self::chatService();
        $tokens = token_get_all($src);

        $offsets = [];
        $offset = 0;
        foreach ($tokens as $idx => $token) {
            $offsets[$idx] = $offset;
            $offset += is_array($token) ? strlen($token[1]) : strlen($token);
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j])
                || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $method) {
                continue;
            }

            // 定位方法体的左花括号（跳过参数列表与返回类型）
            while ($j < $count && $tokens[$j] !== '{') {
                $j++;
            }
            if ($j >= $count) {
                return '';
            }

            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
                $cur = $tokens[$k];
                if ($cur === '{' || (is_array($cur) && in_array($cur[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($cur === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($src, $offsets[$i], $offsets[$k] + 1 - $offsets[$i]);
                    }
                }
            }
            return '';
        }
        return '';
    }
}
