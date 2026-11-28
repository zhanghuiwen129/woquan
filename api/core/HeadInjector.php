<?php
/**
 * SSR head 注入器（架构评审 P1-6 / R12）
 *
 * 【为什么需要】
 * index.php 原来对 </head> 连续做 7 次 str_replace：每次都把「新片段 + \n</head>」
 * 插到当前 </head> 前，输出顺序完全依赖 7 次调用的先后。任何片段自身若含
 * </head>（例如正文 JSON-LD 里出现该字面量），后续注入点会错位，SEO 标签
 * 被冲进 body；新增一个 meta 也要小心翼翼地排在正确位置。
 *
 * 【怎么做】
 * 1. 渲染管线在第一次注入前把文档里的 </head> 全部换成唯一占位符；
 * 2. 各处只调用 add() 累积片段，不再触碰 $html；
 * 3. 全部累积完成后调用一次 apply()，用 implode("\n", snippets) + "\n</head>"
 *    替换占位符——字节序与原 7 次注入完全一致。
 *
 * 【门禁】
 * add() 拒绝任何含 </head>（大小写不敏感）的片段，记 error 日志后静默丢弃。
 * 这里选择 fail-safe 而不是抛异常：单条污染片段最多让自己不输出，不能让整页 500。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class HeadInjector
{
    /** 文档占位符：主题骨架里出现同串的概率为零 */
    public const PLACEHOLDER = '<!--{{SSR_HEAD_INJECT}}-->';

    private const HEAD_CLOSE = '</head>';

    /** @var array<int, string> 按 add() 调用顺序排列的待注入片段 */
    private array $snippets = [];

    /**
     * 累积一段 head 注入内容（不含结尾的 </head>）。
     *
     * 含 </head> 的片段会被拒绝：这类片段一旦进入替换串，占位符替换后
     * 页面里会多出一个闭合标签，后续 DOM 解析全乱。拒绝时写 error 日志
     * 便于运营侧发现污染源，但绝不中断渲染。
     */
    public function add(string $snippet): void
    {
        if (stripos($snippet, self::HEAD_CLOSE) !== false) {
            Logger::error('[Hublog HeadInjector] 拒绝含 </head> 的 SSR 注入片段: ' . substr($snippet, 0, 200), ['module' => 'core']); // @allow-error-log
            return;
        }
        $this->snippets[] = $snippet;
    }

    /**
     * 一次性把所有片段写入文档占位符处。
     *
     * 文档没有占位符（骨架本身没有 </head>，或 apply 被重复调用）时原样返回，
     * 保证异常模板下不崩溃、也不产生第二个 </head>。
     */
    public function apply(string $document): string
    {
        if ($this->snippets === [] || !str_contains($document, self::PLACEHOLDER)) {
            return $document;
        }

        $replacement = implode("\n", $this->snippets) . "\n" . self::HEAD_CLOSE;
        return str_replace(self::PLACEHOLDER, $replacement, $document);
    }
}
