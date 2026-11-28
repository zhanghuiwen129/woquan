<?php
declare(strict_types=1);

/**
 * 默认实时事件投递管道：基于文件队列存储事件，支持 flock 原子读写与自动裁剪。
 *
 * 由 SsePush.php（2026-11-24 拆分）下沉而来的旧实现，行为逐字一致：
 *   - 每个用户一个 sse_user_{id}.json，最多保留 MAX_EVENTS 条；
 *   - 事件 id 基于队尾 +1，裁剪只丢最旧的、不重置序号；
 *   - publish 失败返回 false（门面据此跳过离线兜底），poll 异常一律返回空数组。
 */

namespace Hublog\Core\Realtime;

use Hublog\Core\Cache;
use Hublog\Core\Logger;

final class FileDispatcher implements DispatcherInterface
{
    /** 每个用户最多保留的事件数量 */
    private const MAX_EVENTS = 100;

    /** 获取用户事件文件路径 */
    private static function getFilePath(int $userId): string
    {
        return Cache::getCacheDir() . "sse_user_{$userId}.json";
    }

    public function publish(int $userId, string $event, array $data): bool
    {
        $file = self::getFilePath($userId);

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            Logger::error("[Hublog SsePush] 无法打开文件: {$file}");
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                Logger::error("[Hublog SsePush] 无法获取文件锁: {$file}");
                return false;
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

        return true;
    }

    public function poll(int $userId, int $lastEventId): array
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
