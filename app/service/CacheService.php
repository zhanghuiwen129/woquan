<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;

class CacheService
{
    private static $cachePrefix = 'chat_';
    private static $defaultExpire = 3600;

    public static function getUserInfo(int $userId): ?array
    {
        $cacheKey = self::$cachePrefix . 'user_' . $userId;
        $userInfo = Cache::get($cacheKey);

        if ($userInfo === null) {
            $userInfo = \think\facade\Db::name('user')
                ->where('id', $userId)
                ->field('id,nickname,avatar,level,bio,username')
                ->find();

            if ($userInfo) {
                Cache::set($cacheKey, $userInfo, self::$defaultExpire);
            }
        }

        return $userInfo;
    }

    public static function clearUserInfo(int $userId): void
    {
        $cacheKey = self::$cachePrefix . 'user_' . $userId;
        Cache::delete($cacheKey);
    }

    public static function getMessageList(int $userId): array
    {
        $cacheKey = self::$cachePrefix . 'msg_list_' . $userId;
        $messageList = Cache::get($cacheKey);

        if ($messageList === null) {
            $lastMessages = \think\facade\Db::name('messages')
                ->alias('m')
                ->field('m.*')
                ->where(function($query) use ($userId) {
                    $query->where('m.sender_id', $userId)->whereOr('m.receiver_id', $userId);
                })
                ->order('m.create_time', 'desc')
                ->select()
                ->toArray();

            $messageList = [];
            $seenPairs = [];

            foreach ($lastMessages as $msg) {
                $otherUserId = ($msg['sender_id'] == $userId) ? $msg['receiver_id'] : $msg['sender_id'];
                $pairKey = min($userId, $otherUserId) . '_' . max($userId, $otherUserId);

                if (!isset($seenPairs[$pairKey])) {
                    $seenPairs[$pairKey] = true;
                    $messageList[] = $msg;
                }
            }

            Cache::set($cacheKey, $messageList, 300);
        }

        return $messageList;
    }

    public static function clearMessageList(int $userId): void
    {
        $cacheKey = self::$cachePrefix . 'msg_list_' . $userId;
        Cache::delete($cacheKey);
    }

    public static function getChatHistory(int $userId, int $otherUserId, int $page = 1, int $pageSize = 50): array
    {
        $cacheKey = self::$cachePrefix . 'chat_history_' . $userId . '_' . $otherUserId . '_' . $page;
        $chatHistory = Cache::get($cacheKey);

        if ($chatHistory === null) {
            $messages = \think\facade\Db::name('messages')
                ->where(function($query) use ($userId, $otherUserId) {
                    $query->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                })
                ->whereOr(function($query) use ($userId, $otherUserId) {
                    $query->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                })
                ->order('create_time', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $chatHistory = array_reverse($messages);
            Cache::set($cacheKey, $chatHistory, 60);
        }

        return $chatHistory;
    }

    public static function clearChatHistory(int $userId, int $otherUserId): void
    {
        // 清除该对话的所有分页缓存（最多清除前10页）
        for ($page = 1; $page <= 10; $page++) {
            $cacheKey = self::$cachePrefix . 'chat_history_' . $userId . '_' . $otherUserId . '_' . $page;
            Cache::delete($cacheKey);
        }
    }

    public static function getUnreadCount(int $userId): int
    {
        $cacheKey = self::$cachePrefix . 'unread_count_' . $userId;
        $unreadCount = Cache::get($cacheKey);

        if ($unreadCount === null) {
            $unreadCount = \think\facade\Db::name('messages')
                ->where('receiver_id', $userId)
                ->where('is_read', 0)
                ->count();

            Cache::set($cacheKey, $unreadCount, 60);
        }

        return $unreadCount;
    }

    public static function clearUnreadCount(int $userId): void
    {
        $cacheKey = self::$cachePrefix . 'unread_count_' . $userId;
        Cache::delete($cacheKey);
    }

    public static function set(string $key, $value, int $expire = 3600): bool
    {
        $cacheKey = self::$cachePrefix . $key;
        return Cache::set($cacheKey, $value, $expire);
    }

    public static function get(string $key)
    {
        $cacheKey = self::$cachePrefix . $key;
        return Cache::get($cacheKey);
    }

    public static function delete(string $key): bool
    {
        $cacheKey = self::$cachePrefix . $key;
        return Cache::delete($cacheKey);
    }

    public static function clear(): bool
    {
        return Cache::clear();
    }
}
