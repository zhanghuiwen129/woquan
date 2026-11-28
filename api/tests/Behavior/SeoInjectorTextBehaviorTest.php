<?php
/**
 * SEO 纯文本工具逐字节等价回归（架构评审 P1-5 / T02）
 *
 * oracle 是改造前 index.php 中 seo_plain_text()/seo_paragraphs() 的逐字副本，
 * 只把函数名改掉以避免与全局包装冲突。测试覆盖非法 UTF-8、emoji、图片链接、
 * [[media:]] 占位符、CRLF 分段以及 limit/maxBlocks 各档，全部用 assertSame 比对。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\SeoInjector;

final class SeoInjectorTextBehaviorTest extends BehaviorTestCase
{
    /**
     * 改造前 index.php 的 seo_plain_text() 逐字副本。
     */
    private static function legacyPlainText(string $markdown, int $limit = 0): string
    {
        $text = strip_tags((string)$markdown);
        // 正文里可能混入非法 UTF-8 字节（历史上被截断的多字节字符）：
        // 带 /u 修饰符的正则遇到非法字节会直接返回 null，进而让整页 500。
        // 这里先丢弃非法字节，再做正则替换；后面的 null 判断是第二道保险。
        if (!mb_check_encoding($text, 'UTF-8')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($clean)) {
                $text = $clean;
            }
        }
        $patterns = [
            '/!\[[^\]]*\]\([^)]*\)/',   // 图片
            '/\[([^\]]*)\]\([^)]*\)/',   // 链接保留文字
            '/`{1,3}[^`]*`{1,3}/',        // 行内代码
            '/^#{1,6}\s*/m',              // 标题标记
            '/^\s*[-*+]\s+/m',            // 列表符号
            '/^\s*>\s?/m',                // 引用
            '/\*{1,2}|_{1,2}/',           // 强调
        ];
        foreach ($patterns as $re) {
            // preg_replace 遇到非法 UTF-8 会返回 null，必须保留原文而不是让后续调用崩掉
            $out = preg_replace($re, '', $text);
            if ($out !== null) {
                $text = $out;
            }
        }
        // emoji
        $out = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', '', $text);
        if ($out !== null) {
            $text = $out;
        }
        $out = preg_replace('/\s+/u', ' ', $text);
        if ($out !== null) {
            $text = $out;
        }
        $text = trim($text);
        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
        }
        return $text;
    }

    /**
     * 改造前 index.php 的 seo_paragraphs() 逐字副本。
     */
    private static function legacyParagraphs(string $markdown, int $maxBlocks = 0): string
    {
        $blocks = preg_split('/\R{2,}/', (string)$markdown);
        $out = [];
        foreach ($blocks as $block) {
            $p = self::legacyPlainText($block);
            if ($p === '') {
                continue;
            }
            $out[] = '<p>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</p>';
            if ($maxBlocks > 0 && count($out) >= $maxBlocks) {
                break;
            }
        }
        return implode('', $out);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function markdownCases(): array
    {
        return [
            'empty' => [''],
            'plain' => ['hello world'],
            'headings_and_emphasis' => ["# 标题\n\n**粗体**与_斜体_，还有 `code` 和 > 引用\n- 列表项"],
            'image_and_link' => ['前文 ![封面](https://example.com/a.png) 后文 [链接文字](https://example.com/p/1)'],
            'media_placeholder' => ['[[media:image:12]] 和 [[media:video:9]] 与 [[media:attachment:3]]'],
            'emoji' => ['标题 😀🚀 内容 ❤️ ☀️ ⭐'],
            'invalid_utf8' => ["合法前缀\xFF\xC3中间\x80后缀"],
            'invalid_utf8_with_emoji' => ["\xE4\xB8\xAD\xFF😀\xC3\x28末尾"],
            'crlf_paragraphs' => ["第一段 第一行\r\n第一段 第二行\r\n\r\n第二段\t内容\r\n\r\n第三段"],
            'html_tags' => ['<div class="x">带 <b>标签</b> 的正文</div>'],
            'long_text' => [str_repeat('长文段落测试内容 ', 80)],
            'whitespace_only' => [" \t\n\r\n  \n"],
        ];
    }

    /**
     * @dataProvider markdownCases
     */
    public function testPlainTextMatchesLegacyOracle(string $markdown): void
    {
        foreach ([0, 1, 5, 30, 300, 3000, -1] as $limit) {
            $this->assertSame(
                self::legacyPlainText($markdown, $limit),
                SeoInjector::plainText($markdown, $limit),
                'plainText limit=' . $limit . ' 输出不一致'
            );
        }
    }

    /**
     * @dataProvider markdownCases
     */
    public function testParagraphsMatchesLegacyOracle(string $markdown): void
    {
        foreach ([0, 1, 2, 10, -1] as $maxBlocks) {
            $this->assertSame(
                self::legacyParagraphs($markdown, $maxBlocks),
                SeoInjector::paragraphs($markdown, $maxBlocks),
                'paragraphs maxBlocks=' . $maxBlocks . ' 输出不一致'
            );
        }
    }

    public function testDefaultArgumentsMatchLegacyDefaults(): void
    {
        $markdown = "![图](a.png) 第一段 😀\r\n\r\n第二段 **加粗**";
        $this->assertSame(self::legacyPlainText($markdown), SeoInjector::plainText($markdown));
        $this->assertSame(self::legacyParagraphs($markdown), SeoInjector::paragraphs($markdown));
    }

    /**
     * QA 补充边界：非法 UTF-8 字节 + [[media:]] 占位符 + emoji 的组合。
     * 期望值按拆分前语义人工写死（非法字节丢弃、emoji 清除、媒体占位符保留、
     * 空白折叠），不与内嵌 oracle 互相自证。
     */
    public function testPlainTextBoundaryInvalidUtf8MediaAndEmoji(): void
    {
        $markdown = "标题\xFF😀 [[media:image:12]]\n\n**正文**\xF0\x9F\x9A\x80尾部";

        $this->assertSame('标题 [[media:image:12]] 正文尾部', SeoInjector::plainText($markdown));
        $this->assertSame(
            '<p>标题 [[media:image:12]]</p><p>正文尾部</p>',
            SeoInjector::paragraphs($markdown)
        );
        $this->assertSame('标题 [[', SeoInjector::plainText($markdown, 5));
    }
}
