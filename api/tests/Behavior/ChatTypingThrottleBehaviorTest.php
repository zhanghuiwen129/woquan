<?php
/**
 * T05 typing 服务端节流 · 行为回归测试。
 *
 * 缺陷原貌：/api/chat/typing 只要通过会话成员校验就会无限广播事件，
 * 单个成员可在 3 秒内灌满接收方 SSE 队列。本测试验证 Cache::withLock 的
 * 3 秒节流键按「会话 + 用户」隔离，且被拒请求不产生事件。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\ChatController;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Request;
use PDO;

final class ChatTypingThrottleBehaviorTest extends BehaviorTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

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
SQL);

        foreach ([1, 2] as $userId) {
            $this->seed('users', [
                'id' => $userId, 'username' => "u{$userId}", 'nickname' => "n{$userId}",
                'avatar' => '', 'status' => 1,
            ]);
        }
        $this->seed('conversations', ['id' => 10, 'type' => 'private', 'member_count' => 2]);
        $this->seed('conversation_members', ['conversation_id' => 10, 'user_id' => 1, 'role' => 'member']);
        $this->seed('conversation_members', ['conversation_id' => 10, 'user_id' => 2, 'role' => 'member']);

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        Auth::clear();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Auth::clear();
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    private function loginAs(int $userId): void
    {
        $user = $this->pdo()->query('SELECT * FROM users WHERE id = ' . $userId)->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($user, '登录用户必须存在');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Auth::generateToken($user);
        Auth::clear();
    }

    private function callTyping(int $conversationId = 10): int
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/chat/typing';
        $_GET = ['conversation_id' => $conversationId];
        $_POST = ['conversation_id' => $conversationId];
        Auth::clear();

        return (new ChatController())->typing(new Request())->getCode();
    }

    public function testTypingIsThrottledPerUserWithoutExtraEvents(): void
    {
        $this->loginAs(1);

        $this->assertSame(200, $this->callTyping(), '首次 typing 应放行');
        $this->assertTrue(Cache::has('chat_typing_10_1'), '节流键必须落盘');
        $events = \Hublog\Core\SsePush::poll(2, 0);
        $this->assertCount(1, array_values(array_filter(
            $events,
            static fn(array $e): bool => ($e['event'] ?? '') === 'typing'
        )), '首次请求应广播一条 typing');

        $this->assertSame(429, $this->callTyping(), '3 秒内重复请求应被节流');
        $after = \Hublog\Core\SsePush::poll(2, 0);
        $this->assertCount(1, array_values(array_filter(
            $after,
            static fn(array $e): bool => ($e['event'] ?? '') === 'typing'
        )), '被节流请求不得再广播事件');

        $this->assertFalse(Cache::has('chat_typing_10_2'), '他人节流键不得被当前用户请求写入');
    }
}
