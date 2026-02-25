<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;
use think\facade\Request;

class ChatLogService
{
    /**
     * 记录聊天日志
     */
    public static function log(array $data): bool
    {
        try {
            $logData = [
                'user_id' => $data['user_id'] ?? 0,
                'target_user_id' => $data['target_user_id'] ?? 0,
                'action' => $data['action'] ?? '',
                'message_id' => $data['message_id'] ?? 0,
                'details' => isset($data['details']) ? json_encode($data['details'], JSON_UNESCAPED_UNICODE) : '',
                'ip' => Request::ip() ?? '',
                'user_agent' => Request::header('user-agent') ?? '',
                'create_time' => time()
            ];

            Db::name('chat_logs')->insert($logData);
            return true;
        } catch (\Exception $e) {
            error_log('聊天日志记录失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 记录发送消息
     */
    public static function logSendMessage(int $userId, int $targetUserId, int $messageId, array $messageData): bool
    {
        return self::log([
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'action' => 'send_message',
            'message_id' => $messageId,
            'details' => [
                'message_type' => $messageData['message_type'] ?? 1,
                'content_length' => strlen($messageData['content'] ?? ''),
                'has_file' => !empty($messageData['file_url']),
                'reply_to_id' => $messageData['reply_to_id'] ?? 0
            ]
        ]);
    }

    /**
     * 记录删除消息
     */
    public static function logDeleteMessage(int $userId, int $messageId): bool
    {
        return self::log([
            'user_id' => $userId,
            'action' => 'delete_message',
            'message_id' => $messageId,
            'details' => [
                'reason' => 'user_delete'
            ]
        ]);
    }

    /**
     * 记录撤回消息
     */
    public static function logRecallMessage(int $userId, int $messageId): bool
    {
        return self::log([
            'user_id' => $userId,
            'action' => 'recall_message',
            'message_id' => $messageId,
            'details' => [
                'reason' => 'user_recall'
            ]
        ]);
    }

    /**
     * 记录屏蔽用户
     */
    public static function logBlockUser(int $userId, int $blockedUserId): bool
    {
        return self::log([
            'user_id' => $userId,
            'target_user_id' => $blockedUserId,
            'action' => 'block_user',
            'details' => [
                'reason' => 'user_block'
            ]
        ]);
    }

    /**
     * 记录取消屏蔽
     */
    public static function logUnblockUser(int $userId, int $unblockedUserId): bool
    {
        return self::log([
            'user_id' => $userId,
            'target_user_id' => $unblockedUserId,
            'action' => 'unblock_user',
            'details' => [
                'reason' => 'user_unblock'
            ]
        ]);
    }

    /**
     * 记录阅读消息
     */
    public static function logReadMessage(int $userId, int $messageId): bool
    {
        return self::log([
            'user_id' => $userId,
            'action' => 'read_message',
            'message_id' => $messageId,
            'details' => [
                'read_time' => time()
            ]
        ]);
    }

    /**
     * 记录转发消息
     */
    public static function logForwardMessage(int $userId, int $targetUserId, int $messageId): bool
    {
        return self::log([
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'action' => 'forward_message',
            'message_id' => $messageId,
            'details' => [
                'forward_time' => time()
            ]
        ]);
    }

    /**
     * 记录收藏消息
     */
    public static function logFavoriteMessage(int $userId, int $messageId): bool
    {
        return self::log([
            'user_id' => $userId,
            'action' => 'favorite_message',
            'message_id' => $messageId,
            'details' => [
                'favorite_time' => time()
            ]
        ]);
    }

    /**
     * 记录置顶消息
     */
    public static function logPinMessage(int $userId, int $messageId, bool $isPinned): bool
    {
        return self::log([
            'user_id' => $userId,
            'action' => 'pin_message',
            'message_id' => $messageId,
            'details' => [
                'is_pinned' => $isPinned,
                'pin_time' => $isPinned ? time() : 0
            ]
        ]);
    }

    /**
     * 获取用户聊天日志
     */
    public static function getUserLogs(int $userId, int $page = 1, int $pageSize = 20): array
    {
        try {
            $list = Db::name('chat_logs')
                ->where('user_id', $userId)
                ->order('create_time DESC')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $total = Db::name('chat_logs')
                ->where('user_id', $userId)
                ->count();

            return [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize
            ];
        } catch (\Exception $e) {
            error_log('获取聊天日志失败: ' . $e->getMessage());
            return [
                'list' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize
            ];
        }
    }

    /**
     * 获取消息相关日志
     */
    public static function getMessageLogs(int $messageId): array
    {
        try {
            return Db::name('chat_logs')
                ->where('message_id', $messageId)
                ->order('create_time DESC')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            error_log('获取消息日志失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 清理过期日志
     */
    public static function cleanExpiredLogs(int $days = 90): int
    {
        try {
            $expireTime = time() - ($days * 86400);
            return Db::name('chat_logs')
                ->where('create_time', '<', $expireTime)
                ->delete();
        } catch (\Exception $e) {
            error_log('清理过期日志失败: ' . $e->getMessage());
            return 0;
        }
    }
}
