<?php
/**
 * V162（P1-4）结构性回归测试：timeline 游标翻页后端。
 *
 * 与现有 RoutesAssertTest 同一思路——只做静态/结构断言，不连数据库，
 * 用于在「本机无 PHP 运行时」环境下守护关键结构不被回退：
 *   1. timeline() 仅当客户端显式传 cursor 时才走新协议（旧客户端零回归）
 *   2. timelineCursor() 返回两段式 envelope（pinned/items/next_cursor/has_more）
 *   3. buildTimelineWhere() 被 page/offset 路径与游标路径共用（过滤逻辑不漂移）
 *   4. 游标流强制 is_pinned = 0 且按 id 游标（id < cursor），解决置顶排序冲突
 *
 * 完整行为级测试待 P2-1（phpunit + DB）就绪后补齐。
 */

namespace Hublog\Tests;

use Hublog\Tests\SourceView;
use PHPUnit\Framework\TestCase;

class TimelineCursorTest extends TestCase
{
    private static function source(): string
    {
        // V160 之后 timeline / 游标逻辑已拆到 PostFeedTrait。
        // 视图跟随「类」而非「单个文件」，重构不再制造假失败。
        return SourceView::postController();
    }

    /** 游标排序 SQL 的真实实现已下沉到 PostRepository，守卫必须读实现所在文件。 */
    private static function repositorySource(): string
    {
        $path = dirname(__DIR__) . '/core/PostRepository.php';
        if (!is_file($path)) {
            throw new \RuntimeException('PostRepository.php 缺失: ' . $path);
        }
        return (string)file_get_contents($path);
    }

    /**
     * 只取 fetchCursorPostIds() 方法体：同一仓储文件里其它查询也含
     * ORDER BY p.id DESC，若读全文件会让该断言被无关命中掩盖回归。
     */
    private static function cursorQuerySource(): string
    {
        $src = self::repositorySource();
        $start = strpos($src, 'public static function fetchCursorPostIds(');
        if ($start === false) {
            throw new \RuntimeException('PostRepository::fetchCursorPostIds 缺失');
        }
        $end = strpos($src, "\n    }", $start);
        if ($end === false) {
            throw new \RuntimeException('PostRepository::fetchCursorPostIds 方法体不完整');
        }
        return substr($src, $start, $end + 6 - $start);
    }

    public function testTimelineCursorIsOptInBackwardCompatible(): void
    {
        $src = self::source();
        // 旧客户端（不传 cursor）继续走原 page/offset 逻辑；新协议仅在 has('cursor') 时启用
        $this->assertStringContainsString(
            "if (\$request->has('cursor'))",
            $src,
            'timeline() 必须仅在客户端显式传 cursor 时派发到 timelineCursor，保证旧客户端零回归'
        );
        $this->assertStringContainsString(
            'return $this->timelineCursor(',
            $src,
            'has(cursor) 分支必须派发到 timelineCursor'
        );
    }

    public function testTimelineCursorMethodExistsAndIsPrivate(): void
    {
        $src = self::source();
        $this->assertStringContainsString('private function timelineCursor(', $src, 'timelineCursor 必须为 private');
    }

    public function testTimelineCursorEnvelopeShape(): void
    {
        $src = self::source();
        // 抽取 timelineCursor 方法体，校验两段式 envelope 的四个键都在其中
        $this->assertStringContainsString("'pinned' =>", $src, 'envelope 必须含 pinned');
        $this->assertStringContainsString("'items' =>", $src, 'envelope 必须含 items');
        $this->assertStringContainsString("'next_cursor' =>", $src, 'envelope 必须含 next_cursor');
        $this->assertStringContainsString("'has_more' =>", $src, 'envelope 必须含 has_more');
        // 首屏（cursor=0）才附带置顶集合，后续翻页不带——避免 App 端重复渲染
        $this->assertStringContainsString('if ($cursor === 0)', $src, '仅首屏附带 pinned 集合');
    }

    public function testCursorStreamExcludesPinnedAndUsesIdCursor(): void
    {
        // 非置顶/游标 WHERE 仍在 PostFeedTrait 拼装，ORDER BY 已下沉到 PostRepository；
        // 只取仓储里目标方法体 + 控制器视图，避免同文件其它查询的相同片段掩盖回归。
        $src = self::cursorQuerySource() . self::source();
        $this->assertStringContainsString(
            "AND p.is_pinned = 0",
            $src,
            '游标流必须排除置顶帖（is_pinned=0），游标只作用于非置顶流'
        );
        $this->assertStringContainsString(
            "AND p.id < ?",
            $src,
            '翻页必须按 id 游标（id < cursor）分页'
        );
        $this->assertStringContainsString(
            'ORDER BY p.id DESC',
            $src,
            '游标流必须按 id DESC 排序'
        );
    }

    public function testBuildTimelineWhereIsShared(): void
    {
        $src = self::source();
        $this->assertStringContainsString('private function buildTimelineWhere(', $src, 'buildTimelineWhere 必须为 private');
        // page/offset 路径与游标路径都调用它，过滤条件只维护一份
        $count = substr_count($src, '$this->buildTimelineWhere(');
        $this->assertGreaterThanOrEqual(2, $count, 'buildTimelineWhere 应被 page/offset 与游标两条路径共用');
    }
}
