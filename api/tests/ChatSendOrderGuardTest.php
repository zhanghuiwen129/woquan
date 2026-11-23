<?php
/**
 * T06 私信文件拦截前落盘 · 源码顺序守卫。
 *
 * 缺陷原貌：ChatController::send() 在 Upload::* 落盘之后才做全站禁言与
 * 会话权益校验，未授权/被禁言者可反复上传文件形成磁盘与 uploads 表垃圾。
 * 本测试锚定四个关键步骤在 send() 方法体内的先后顺序与唯一性。
 */

declare(strict_types=1);

namespace Hublog\Tests;

class ChatSendOrderGuardTest extends \PHPUnit\Framework\TestCase
{
    private static function src(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function testSendChecksMembershipMuteAndConversationBeforeAnyUpload(): void
    {
        $src = self::src('api/controllers/ChatController.php');

        // 全文件仅 send() 调用 findConversationForSend；同时把断言收窄到 send 方法体，
        // 避免将来其它方法新增同名调用时本守卫误判。
        $this->assertSame(1, substr_count($src, 'ChatRepository::findConversationForSend('));

        $start = strpos($src, 'public function send(');
        $end = strpos($src, 'public function markRead(', $start === false ? 0 : $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $body = substr($src, (int)$start, (int)$end - (int)$start);

        $pMember = strpos($body, 'validateMembership(');
        $pMute = strpos($body, 'assertNotMuted(');
        $pConv = strpos($body, 'ChatRepository::findConversationForSend(');
        $pUpload = strpos($body, 'Upload::image(');

        $this->assertNotFalse($pMember);
        $this->assertNotFalse($pMute);
        $this->assertNotFalse($pConv);
        $this->assertNotFalse($pUpload);
        $this->assertLessThan($pMute, $pMember, '会话成员校验必须早于全站禁言校验');
        $this->assertLessThan($pConv, $pMute, '全站禁言校验必须早于会话权益查询');
        $this->assertLessThan($pUpload, $pConv, '会话权益查询必须早于任何文件落盘');

        $this->assertSame(1, substr_count($body, 'ChatRepository::findConversationForSend('),
            'send() 内不得重复查询会话');
        $this->assertSame(1, substr_count($body, 'assertNotMuted('), 'send() 内不得重复禁言校验');
    }
}
