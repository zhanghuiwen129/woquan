<?php
/**
 * 私信模块 · 批次 A 回归护栏
 *
 * 覆盖本轮三处修复：
 *   1. 后台聊天记录 CSV 导出的公式注入中和（csvSafe）
 *   2. PushService 的 chat_message -> preview 事件映射
 *   3. getMessages 的 before_id 游标分支（源码级断言，不连 DB）
 * 另附前端游标分页的源码护栏，避免误改回页码翻页。
 *
 * 运行：php api/tests/run_tests.php
 * 说明：全部为纯静态 / 反射断言，不依赖数据库或完整请求生命周期。
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__) . '/core/BaseController.php';
require_once dirname(__DIR__) . '/controllers/AdminController.php';
require_once dirname(__DIR__) . '/controllers/AdminChatController.php';
require_once dirname(__DIR__) . '/core/PushService.php';
require_once __DIR__ . '/SourceView.php';

class ChatBatchATest extends \PHPUnit\Framework\TestCase
{
    /** 项目根目录 */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** 读取源码文本用于护栏断言 */
    private static function src(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    /** 反射调用控制器里的私有静态工具方法，不改动其可见性 */
    private static function csvSafe($value): string
    {
        $method = new \ReflectionMethod(\Hublog\Controllers\AdminChatController::class, 'csvSafe');
        $method->setAccessible(true);
        return (string)$method->invoke(null, $value);
    }

    public function testCsvSafeNeutralizesFormulaPrefixes(): void
    {
        foreach ([
            '=HYPERLINK("http://evil.test","click")',
            '+SUM(A1:A9)',
            '-1+CMD|/C calc',
            '@SUM(A1)',
            "\t=1+1",
            "\r=1+1",
        ] as $payload) {
            $this->assertSame("'" . $payload, self::csvSafe($payload));
        }

        // 普通文本、空串、非字符串输入保持原样
        $this->assertSame('普通文本', self::csvSafe('普通文本'));
        $this->assertSame('普通=文本', self::csvSafe('普通=文本'));
        $this->assertSame('', self::csvSafe(''));
        $this->assertSame('', self::csvSafe(null));
        $this->assertSame('123', self::csvSafe(123));
    }

    public function testChatExportNeutralizesOnlyUserControlledText(): void
    {
        $src = self::src('api/controllers/AdminChatController.php');

        // 昵称/用户名、正文、删除人昵称拼接串都必须经过中和
        $this->assertStringContainsString("self::csvSafe(\$r['nickname'] ?: \$r['username'])", $src);
        $this->assertStringContainsString("self::csvSafe(\$r['content'])", $src);
        $this->assertStringContainsString(
            "self::csvSafe(!empty(\$r['deleted_by']) ? ((\$r['deleted_by_nickname'] ?: \$r['deleted_by_username']) . '#' . \$r['deleted_by']) : '')",
            $src
        );

        // ID / 时间 / 指纹等结构化字段保持原写入方式
        $this->assertStringContainsString("                \$r['id'],", $src);
        $this->assertStringContainsString("                \$r['created_at'],", $src);
        $this->assertStringContainsString("(string)(\$r['content_hash'] ?? '')", $src);

        // 公式注入的中和逻辑必须存在
        $this->assertStringContainsString("in_array(\$s[0], ['=', '+', '-', '@'", $src);
    }

    public function testPushEventMapChatMessageUsesPreview(): void
    {
        $ref = new \ReflectionClass(\Hublog\Core\PushService::class);
        $map = $ref->getReflectionConstant('EVENT_MAP')->getValue();

        $this->assertTrue(is_array($map));
        $this->assertArrayHasKey('chat_message', $map);
        $this->assertSame('preview', $map['chat_message']['content_key']);
        $this->assertSame('收到一条新私信', $map['chat_message']['title']);
    }

    public function testGetMessagesCursorBranchExists(): void
    {
        // ChatService 已在 V99 拆成 7 个 Trait，用逻辑源码视图取完整类源码
        $svc = SourceView::chatService();
        $this->assertStringContainsString('int $beforeId = 0', $svc);
        $this->assertStringContainsString('if ($beforeId > 0)', $svc);
        $this->assertStringContainsString('m.id < ?', $svc);
        $this->assertStringContainsString("'next_before_id' => \$nextBeforeId", $svc);
        $this->assertStringContainsString("'has_more' => \$nextBeforeId > 0", $svc);

        $ctrl = self::src('api/controllers/ChatController.php');
        $this->assertStringContainsString("\$beforeId = \$request->getInt('before_id', 0)", $ctrl);
        $this->assertStringContainsString('ChatService::getMessages($conversationId, $user[\'id\'], $page, $limit, $beforeId)', $ctrl);
    }

    public function testFrontendUsesCursorInsteadOfPageNumbers(): void
    {
        $js = self::src('templates/moments/assets/js/modules/chat.js');
        $this->assertStringContainsString('nextBeforeId: 0', $js);
        $this->assertStringContainsString('before_id=${chatState.nextBeforeId}', $js);
        $this->assertStringContainsString('data.pagination?.next_before_id', $js);
        $this->assertStringContainsString('data.pagination?.has_more', $js);
        $this->assertFalse(str_contains($js, 'messagePage'));

        // 引用处版本号必须跟进，避免浏览器命中旧缓存
        $app = self::src('templates/moments/assets/js/app.js');
        $this->assertStringContainsString('chat.js?v=20261117.3', $app);
    }
}
