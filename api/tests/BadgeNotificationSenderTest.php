<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * V168 守卫：徽章通知的发送人必须是「系统」，不能是 AI 评论家账号。
 *
 * 现象：获得的徽章通知显示发送人是「ai评论家」。
 * 原因：notifyUnlock() 借用 AiService::getAiUserId() 作为 notifications.sender_id
 *      （该列有外键约束不能为 0），于是通知列表 JOIN users 后拿到的就是 AI 账号。
 */
final class BadgeNotificationSenderTest extends TestCase
{
    private function src(string $file): string
    {
        $path = dirname(__DIR__) . '/' . $file;
        $s = file_get_contents($path);
        $this->assertNotFalse($s, "无法读取 {$file}");
        // 去注释，防注释里出现的类名/方法名造成误判
        $s = \preg_replace('/\/\*.*?\*\//s', '', (string)$s);
        $s = \preg_replace('/\/\/[^\n]*/', '', (string)$s);
        return (string)$s;
    }

    public function testBadgeNotifyUsesSystemAccountNotAi(): void
    {
        $src = $this->src('core/BadgeService.php');

        $this->assertStringContainsString(
            'Helper::getSystemUserId()',
            $src,
            '徽章通知的 sender_id 必须取系统账号 system_notice。'
        );
        $this->assertStringNotContainsString(
            'AiService::getAiUserId()',
            $src,
            '徽章通知不得再借用 AI 评论家账号（会让用户看到发送人是「ai评论家」）。'
        );
    }

    public function testNotificationListRendersSystemOriginAsSystemSender(): void
    {
        $src = $this->src('controllers/NotificationController.php');

        $this->assertStringContainsString(
            'isSystemOriginNotification',
            $src,
            '通知列表必须区分系统来源通知并覆盖展示（否则历史数据里的 AI 发送人仍会露出来）。'
        );
        $this->assertStringContainsString(
            "'badge'",
            $src,
            "badge 类型必须被识别为系统发出的通知。"
        );
        $this->assertStringContainsString(
            'Helper::SYSTEM_SENDER_NICKNAME',
            $src,
            '系统来源通知的昵称必须用统一常量「系统通知」。'
        );
    }

    public function testSystemAccountIsCreatedByMigration(): void
    {
        $src = '';
        foreach (['1', '2', '3', '4', '5'] as $batchNo) {
            $src .= $this->src('core/MigrationBatchC' . $batchNo . '.php');
        }
        $this->assertStringContainsString("'system_notice'", $src, '必须有创建 system_notice 账号的迁移（migrate90）。');

        // 系统账号必须与 AI 账号独立，否则又会共用身份
        $this->assertStringContainsString(
            'public static function getSystemUserId',
            $this->src('core/Helper.php'),
            'Helper 必须提供 getSystemUserId()。'
        );
    }
}
