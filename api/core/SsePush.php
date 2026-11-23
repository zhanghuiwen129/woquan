<?php
declare(strict_types=1);

/**
 * Hublog Server-Sent Events 管理类
 * 基于文件队列存储事件，支持 flock 原子读写和自动裁剪
 */

namespace Hublog\Core;

class SsePush
{
    /** 每个用户最多保留的事件数量 */
    private const MAX_EVENTS = 100;

    /**
     * 获取用户事件文件路径
     */
    private static function getFilePath(int $userId): string
    {
        return Cache::getCacheDir() . "sse_user_{$userId}.json";
    }

    /**
     * 推送事件到用户队列
     *
     * @param int    $userId 用户 ID
     * @param string $event  事件名称
     * @param array  $data   事件数据
     */
    /**
     * 通知事件 → notification_prefs 字段 映射
     * 后端偏好表列：all_enabled / post_like / post_comment / follow / message / circle_post / system
     * 这里只拦截与「通知提醒」设置相关的类型；call_invite / chat_message / typing 等不在其列，默认放行。
     */
    private const NOTIFY_EVENT_MAP = [
        'like'         => 'post_like',
        'comment_like' => 'post_like',
        'comment'      => 'post_comment',
        'reply'        => 'post_comment',
        'mention'      => 'post_comment',
        'follow'       => 'follow',
        'circle_post'  => 'circle_post',
        'system'       => 'system',
    ];

    /**
     * 收件人是否允许接收该类型通知（按 notification_prefs 判断）
     * 查不到行 / 未知类型 / 查询异常 一律放行，避免误拦。
     */
    private static function notificationAllowed(int $userId, string $type): bool
    {
        $field = self::NOTIFY_EVENT_MAP[$type] ?? null;
        if ($field === null) {
            return true;
        }
        try {
            $row = Database::fetchOne(
                "SELECT all_enabled, {$field} FROM notification_prefs WHERE user_id = ?",
                [$userId]
            );
        } catch (\Throwable $e) {
            return true;
        }
        if (!$row) {
            return true; // 未配置 = 默认开启
        }
        if ((int)($row['all_enabled'] ?? 1) === 0) {
            return false; // 总开关关
        }
        return (int)($row[$field] ?? 1) === 1;
    }

    public static function push(int $userId, string $event, array $data): void
    {
        // V94：通知提醒偏好拦截——仅 'notification' 事件按收件人 notify_prefs 过滤。
        // 在入队前拦截，SSE 实时通道与（将来开启的）厂商推送一并生效。
        if ($event === 'notification' && !self::notificationAllowed($userId, (string)($data['type'] ?? ''))) {
            return;
        }

        $file = self::getFilePath($userId);

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            Logger::error("[Hublog SsePush] 无法打开文件: {$file}");
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                Logger::error("[Hublog SsePush] 无法获取文件锁: {$file}");
                return;
            }

            // 读取现有事件
            $contents = '';
            rewind($fp);
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    break;
                }
                $contents .= $chunk;
            }

            $events = [];
            if ($contents !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $events = $decoded;
                }
            }

            // 生成自增 ID（基于最后一条事件的 ID + 1）
            $lastId = 0;
            if (!empty($events)) {
                $lastEvent = end($events);
                $lastId = (int)($lastEvent['id'] ?? 0);
            }
            $newId = $lastId + 1;

            // 追加新事件
            $events[] = [
                'id' => $newId,
                'event' => $event,
                'data' => $data,
                'timestamp' => time(),
            ];

            // 自动裁剪：仅保留最新的 100 条
            if (count($events) > self::MAX_EVENTS) {
                $events = array_slice($events, -self::MAX_EVENTS);
            }

            // 写回文件（先截断再写入，保证原子性）
            $json = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        // App 离线推送兜底：SSE 只覆盖前台，退后台/进程被杀时由厂商通道补推。
        // 全程 try/catch + 仅在 push_enabled=1 时生效，失败绝不影响 SSE 主流程。
        try {
            if (class_exists(PushService::class)) {
                PushService::pushFromEvent($userId, $event, $data);
            }
        } catch (\Throwable $e) {
            Logger::error('[Hublog SsePush] 离线推送异常: ' . $e->getMessage());
        }
    }

    /**
     * 轮询用户待处理事件
     *
     * @param int $userId      用户 ID
     * @param int $lastEventId 上次接收的事件 ID（仅返回 ID 大于此值的事件）
     * @return array 待处理事件列表，格式为 [{id, event, data, timestamp}]
     */
    public static function poll(int $userId, int $lastEventId = 0): array
    {
        $file = self::getFilePath($userId);

        if (!file_exists($file)) {
            return [];
        }

        $fp = @fopen($file, 'r');
        if (!$fp) {
            Logger::error("[Hublog SsePush] 无法打开文件: {$file}");
            return [];
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                Logger::error("[Hublog SsePush] 无法获取共享锁: {$file}");
                return [];
            }

            $contents = '';
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    break;
                }
                $contents .= $chunk;
            }
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        if ($contents === '') {
            return [];
        }

        $events = json_decode($contents, true);
        if (!is_array($events)) {
            return [];
        }

        // 过滤出 ID 大于 lastEventId 的事件
        $pending = [];
        foreach ($events as $event) {
            if ((int)($event['id'] ?? 0) > $lastEventId) {
                $pending[] = $event;
            }
        }

        return $pending;
    }
}
