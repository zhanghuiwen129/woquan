<?php
declare(strict_types=1);

/**
 * 实时事件投递管道接口。
 *
 * SsePush 门面只依赖此接口：通知偏好过滤与离线推送兜底留在门面层，
 * 事件队列的写入/读取/裁剪下沉到实现层。单机默认 FileDispatcher，
 * 将来多机可加 CentrifugoDispatcher，切换 REALTIME_DRIVER 即可，
 * 11 处生产 push 调用点与 /sse、/app/events 端点零改动。
 */

namespace Hublog\Core\Realtime;

interface DispatcherInterface
{
    /**
     * 投递一条事件到用户队列。
     *
     * @return bool 是否成功入队；false 时门面跳过离线推送兜底（与旧 fopen/flock 失败即 return 一致）
     */
    public function publish(int $userId, string $event, array $data): bool;

    /**
     * 读取用户队列中 id 大于 $lastEventId 的事件。
     *
     * @return array 事件列表 [{id, event, data, timestamp}]
     */
    public function poll(int $userId, int $lastEventId): array;
}
