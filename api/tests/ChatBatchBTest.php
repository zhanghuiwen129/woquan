<?php
/**
 * 私信模块 · 批次 B 回归护栏
 *
 * 覆盖本轮四项修复（全部为纯静态 / 反射断言，不连真实 DB）：
 *   1. V98 迁移：messages.client_msg_id + uk_msg_client + 末尾自检 throw
 *   2. 迁移契约：CRITICAL_COLUMNS / REQUIRED_UNIQUE_INDEXES 手工登记
 *   3. ChatService::sendMessage 幂等查重 + 事务包裹 + 1062 回查 + 通知后置
 *   4. 前端三条发送链路携带 client_msg_id + 失败重试复用 + 版本号 bump
 *
 * 运行：php api/tests/run_tests.php
 */

declare(strict_types=1);

namespace Hublog\Tests;

require_once dirname(__DIR__) . '/core/MigrationContract.php';
require_once __DIR__ . '/SourceView.php';

class ChatBatchBTest extends \PHPUnit\Framework\TestCase
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

    /** 截取 sendMessage() 方法体，避免别的公共方法里的同名调用干扰位置断言 */
    private static function sendMessageSource(): string
    {
        $src = SourceView::chatService();
        $start = strpos($src, 'public static function sendMessage(');
        $end = strpos($src, 'public static function markAsRead(', $start === false ? 0 : $start);
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }
        return substr($src, $start, $end - $start);
    }

    public function testMigrationV98AddsIdempotencyColumnAndIndex(): void
    {
        $migration = self::src('api/core/Migration.php');
        $this->assertStringContainsString('private static int $currentVersion = 106;', $migration);

        $batch = '';
        foreach (['1', '2', '3', '4', '5'] as $batchNo) {
            $batch .= self::src('api/core/MigrationBatchC' . $batchNo . '.php');
        }
        $this->assertStringContainsString('private static function migrate98(): void', $batch);
        $this->assertStringContainsString('ADD COLUMN `client_msg_id` VARCHAR(64)', $batch);
        $this->assertStringContainsString(
            'ADD UNIQUE KEY `uk_msg_client` (`sender_id`, `client_msg_id`)',
            $batch
        );
        // DDL 失败不能静默吞掉：末尾自检缺失时抛异常
        $this->assertStringContainsString('V98 自检失败', $batch);
        $this->assertStringContainsString("throw \$e;", $batch);
    }

    public function testContractRegistersV98Sentinels(): void
    {
        $ref = new \ReflectionClass(\Hublog\Core\MigrationContract::class);

        $critical = $ref->getReflectionConstant('CRITICAL_COLUMNS')->getValue();
        $this->assertTrue(in_array(['messages', 'client_msg_id'], $critical, true));

        $indexes = $ref->getReflectionConstant('REQUIRED_UNIQUE_INDEXES')->getValue();
        $found = false;
        foreach ($indexes as $idx) {
            if ($idx['table'] === 'messages'
                && $idx['index'] === 'uk_msg_client'
                && $idx['cols'] === ['sender_id', 'client_msg_id']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);

        // 生成器自动段也必须收录列名，否则新旧库终态校验会漏掉该列
        $this->assertTrue(in_array('client_msg_id', $ref->getReflectionConstant('REQUIRED_COLUMN_NAMES')->getValue(), true));
    }

    public function testSendMessageUsesIdempotencyAndTransaction(): void
    {
        $svc = self::sendMessageSource();
        $this->assertStringContainsString("string \$clientMsgId = ''", $svc);
        $this->assertStringContainsString('Database::beginTransaction();', $svc);
        $this->assertStringContainsString('Database::commit();', $svc);
        $this->assertStringContainsString('Database::rollBack();', $svc);
        $this->assertStringContainsString(
            'SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1',
            $svc
        );
        // 并发重试 1062 唯一键冲突 → 回查既有消息，不抛 500
        $this->assertStringContainsString('=== 1062)', $svc);
        $this->assertStringContainsString("\$messageData['client_msg_id'] = \$clientMsgId;", $svc);
        $this->assertStringContainsString('private static function formatMessageById(', SourceView::chatService());

        // 顺序护栏：begin → insert → commit → 通知；通知 / SSE 不能留在事务内
        $posBegin = strpos($svc, 'Database::beginTransaction();');
        $posInsert = strpos($svc, "Database::insert('messages', \$messageData)");
        $posCommit = strpos($svc, 'Database::commit();');
        $posNotify = strpos($svc, '给会话中其他成员创建通知');
        $this->assertTrue($posBegin !== false && $posInsert !== false && $posCommit !== false && $posNotify !== false);
        $this->assertTrue($posBegin < $posInsert && $posInsert < $posCommit && $posCommit < $posNotify);
    }

    public function testControllerPassesClientMsgIdThroughBothBodyFormats(): void
    {
        $ctrl = self::src('api/controllers/ChatController.php');
        // Request 没有 post()：getString() 同时读 JSON body 与 FormData
        $this->assertStringContainsString("\$request->getString('client_msg_id', '')", $ctrl);
        $this->assertStringContainsString('strlen($clientMsgId) > 64', $ctrl);
        $this->assertStringContainsString(
            "ChatService::sendMessage(\$conversationId, \$user['id'], \$content, \$type, \$extra, \$clientMsgId)",
            $ctrl
        );
        $this->assertFalse(str_contains($ctrl, "\$request->post('client_msg_id'"));
    }

    public function testFrontendCarriesIdempotencyKeyAndBumpsVersion(): void
    {
        $js = self::src('templates/moments/assets/js/modules/chat.js');
        $this->assertStringContainsString('crypto.randomUUID', $js);
        $this->assertStringContainsString('pendingClientMsgId', $js);
        $this->assertStringContainsString('client_msg_id: clientMsgId', $js);
        // 图片 + 视频各一次 FormData.append
        $this->assertStringContainsString("formData.append('client_msg_id', generateClientMsgId());", $js);
        $this->assertTrue(substr_count($js, "formData.append('client_msg_id'") === 2);
        $this->assertTrue(substr_count($js, 'client_msg_id') >= 3);

        $app = self::src('templates/moments/assets/js/app.js');
        $this->assertStringContainsString('chat.js?v=20261117.3', $app);
        $html = self::src('templates/moments/chat.html');
        $this->assertStringContainsString('app.js?v=20261117.3', $html);
    }
}
