<?php
declare(strict_types=1);

/**
 * Hublog 实时事件门面（facade）。
 *
 * 职责边界（2026-11-24 拆分）：
 *   - 保留：通知偏好过滤、离线推送兜底、对外稳定的 push()/poll() 签名；
 *   - 下沉：事件队列的写入/读取/裁剪/事件 id 生成 → DispatcherInterface 实现。
 *
 * 这样单机/多机只是「换投递管道」：默认 FileDispatcher（旧行为逐字一致），
 * 将来多机加 CentrifugoDispatcher 并切 REALTIME_DRIVER 即可，
 * 11 处生产 push 调用点与 /sse、/app/events 端点零改动。
 */

namespace Hublog\Core;

use Hublog\Core\Realtime\DispatcherInterface;
use Hublog\Core\Realtime\FileDispatcher;

class SsePush
{
    /** 进程内驱动实例（PHP-FPM 每请求重置；长驻 worker 首次调用后复用） */
    private static ?DispatcherInterface $dispatcher = null;

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

    /**
     * 取得当前派发器（进程内单例）。
     */
    private static function dispatcher(): DispatcherInterface
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = self::createDispatcher();
        }
        return self::$dispatcher;
    }

    /**
     * 按配置创建派发器。
     *
     * defined() 守卫是升级兼容的关键：存量安装的 config.php 没有 REALTIME_DRIVER，
     * 默认走 file，无需改任何配置即可平滑升级。显式配了未知驱动则快速失败，
     * 绝不静默降级到本机队列（多机下会丢事件且无人察觉）。
     */
    private static function createDispatcher(): DispatcherInterface
    {
        $driver = defined('REALTIME_DRIVER')
            ? strtolower(trim((string)REALTIME_DRIVER))
            : 'file';

        switch ($driver) {
            // 将来多机：case 'centrifugo': return new CentrifugoDispatcher();
            case 'file':
                return new FileDispatcher();
            default:
                throw new \RuntimeException("[Hublog SsePush] 未知的实时派发驱动: {$driver}");
        }
    }

    /**
     * 注入派发器（测试 / 灰度对账用）。
     *
     * 传 null 恢复「按 REALTIME_DRIVER 配置创建」。返回旧实例，
     * 便于调用方 try/finally 精确还原，不污染同进程其它用例。
     */
    public static function setDispatcher(?DispatcherInterface $dispatcher): ?DispatcherInterface
    {
        $old = self::$dispatcher;
        self::$dispatcher = $dispatcher;
        return $old;
    }

    /**
     * 推送事件到用户队列
     *
     * @param int    $userId 用户 ID
     * @param string $event  事件名称
     * @param array  $data   事件数据
     */
    public static function push(int $userId, string $event, array $data): void
    {
        // V94：通知提醒偏好拦截——仅 'notification' 事件按收件人 notify_prefs 过滤。
        // 在入队前拦截，SSE 实时通道与（将来开启的）厂商推送一并生效。
        if ($event === 'notification' && !self::notificationAllowed($userId, (string)($data['type'] ?? ''))) {
            return;
        }

        // 投递失败直接返回：与旧实现 fopen/flock 失败即 return 的行为一致，
        // 不触发下面的离线兜底。
        if (!self::dispatcher()->publish($userId, $event, $data)) {
            return;
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
        return self::dispatcher()->poll($userId, $lastEventId);
    }
}
