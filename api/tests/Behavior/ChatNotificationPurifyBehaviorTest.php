<?php
/**
 * T04 私信预览写入端净化 · 行为回归测试。
 *
 * 缺陷原貌：ChatSendTrait 把用户原文直接截 100 字写入 notifications.content，
 * 同时作为 SSE chat_message.preview 广播；恶意 HTML 事件属性随通知与实时消息扩散。
 * 本测试走真实 ChatService::sendMessage()，验证写入端只净化一次、输出点仍 escape。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\ChatService;
use Hublog\Core\Security;
use Hublog\Core\SsePush;

final class ChatNotificationPurifyBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `conversations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `type` TEXT DEFAULT 'private',
    `title` TEXT DEFAULT NULL,
    `avatar` TEXT DEFAULT NULL,
    `creator_id` INTEGER NOT NULL DEFAULT 0,
    `dm_key` TEXT DEFAULT NULL,
    `circle_id` INTEGER DEFAULT NULL,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `last_message_id` INTEGER DEFAULT NULL,
    `last_message_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `updated_at` TEXT DEFAULT NULL,
    `announcement` TEXT DEFAULT NULL,
    `announcement_at` TEXT DEFAULT NULL,
    `deleted_at` TEXT DEFAULT NULL
);
CREATE TABLE `conversation_members` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `role` TEXT DEFAULT 'member',
    `last_read_message_id` INTEGER NOT NULL DEFAULT 0,
    `muted` INTEGER NOT NULL DEFAULT 0,
    `pinned` INTEGER NOT NULL DEFAULT 0,
    `joined_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `muted_until` TEXT DEFAULT NULL,
    `deleted_at` TEXT DEFAULT NULL
);
CREATE TABLE `messages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `sender_id` INTEGER NOT NULL,
    `content` TEXT DEFAULT NULL,
    `type` TEXT DEFAULT 'text',
    `extra` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT NULL,
    `client_msg_id` TEXT DEFAULT NULL,
    `content_hash` TEXT DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `deleted_at` TEXT DEFAULT NULL,
    `deleted_by` INTEGER DEFAULT NULL,
    `delete_scope` TEXT DEFAULT NULL,
    `recalled_at` TEXT DEFAULT NULL,
    `read_at` TEXT DEFAULT NULL
);
CREATE TABLE `notifications` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `sender_id` INTEGER NOT NULL DEFAULT 0,
    `type` TEXT DEFAULT NULL,
    `post_id` INTEGER DEFAULT NULL,
    `comment_id` INTEGER DEFAULT NULL,
    `conversation_id` INTEGER DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `is_read` INTEGER NOT NULL DEFAULT 0,
    `created_at` TEXT DEFAULT NULL
);
CREATE TABLE `blocked_users` (
    `user_id` INTEGER NOT NULL,
    `blocked_user_id` INTEGER NOT NULL
);
SQL);

        foreach ([1, 2] as $userId) {
            $this->seed('users', [
                'id' => $userId, 'username' => "u{$userId}", 'nickname' => "n{$userId}",
                'avatar' => '', 'status' => 1,
            ]);
        }
        $this->seed('conversations', ['id' => 10, 'type' => 'private', 'title' => '私聊', 'member_count' => 2]);
        $this->seed('conversation_members', ['conversation_id' => 10, 'user_id' => 1, 'role' => 'member']);
        $this->seed('conversation_members', ['conversation_id' => 10, 'user_id' => 2, 'role' => 'member']);
    }

    public function testChatNotificationPreviewIsPurifiedAtWriteTime(): void
    {
        $malicious = '<img src=x onerror=alert(1)>';
        ChatService::sendMessage(10, 1, $malicious, 'text', null, 'purify-case-1');

        $stmt = $this->pdo()->prepare(
            "SELECT * FROM notifications WHERE user_id = 2 AND type = 'chat' LIMIT 1"
        );
        $stmt->execute();
        $notification = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($notification, '接收方应收到私信通知');

        $stored = (string)$notification['content'];
        $this->assertStringNotContainsString('onerror', $stored, '写入端必须移除事件处理器');
        $escaped = Security::escape($stored);
        $this->assertStringContainsString('&lt;img', $escaped, '输出点仍保留 escape 防御');
        $this->assertStringNotContainsString('<img', $escaped, '输出点不得出现裸标签');

        $typingEvents = array_values(array_filter(
            SsePush::poll(2, 0),
            static fn(array $e): bool => ($e['event'] ?? '') === 'chat_message'
        ));
        $this->assertCount(1, $typingEvents, '接收方应收到一条 chat_message 实时事件');
        $preview = (string)($typingEvents[0]['data']['preview'] ?? '');
        $this->assertStringNotContainsString('onerror', $preview, 'SSE 预览不得携带事件处理器');
    }
}
