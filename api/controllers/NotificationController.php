<?php
/**
 * Hublog 消息通知控制器
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\NotificationRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\SsePush;
use Hublog\Core\ChatService;
use Hublog\Core\Exception\HublogException;

class NotificationController extends BaseController
{
    /**
     * 获取当前用户通知列表
     */
    public function index(Request $request): Response
    {
        $user = Auth::check($request);
        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(1, $request->getInt('limit', 15)));
        $offset = ($page - 1) * $limit;

        // P1-4：向后兼容游标——传 before_id 时按 id 游标翻页（替代 OFFSET，避免深翻页性能问题）。
        // 不传则保持原 page/offset 行为，旧客户端无感。
        $beforeId = $request->getInt('before_id');
        $useCursor = $beforeId > 0;

        // 分类筛选：App 通知页要分「评论/点赞/关注/系统」等 tab
        // 支持逗号分隔多类型（如 like,comment_like 合并进「点赞」tab），每个都过白名单
        $allowedTypes = ['comment', 'like', 'comment_like', 'follow', 'mention', 'system', 'message', 'category'];
        $types = [];
        foreach (explode(',', strtolower(trim((string)$request->getString('type')))) as $t) {
            $t = trim($t);
            if ($t !== '' && in_array($t, $allowedTypes, true)) {
                $types[] = $t;
            }
        }
        $types = array_values(array_unique($types));

        // P1-4：游标模式按 id 游标翻页（id 唯一单调，精确无重叠/遗漏）
        // WHERE 构造、IN 占位符与游标绑定全部留在仓储；两种分页各有独立方法。
        $userId = (int)$user['id'];
        if ($useCursor) {
            $notifications = NotificationRepository::fetchCursorPage($userId, $types, $beforeId, $limit);
        } else {
            $notifications = NotificationRepository::fetchOffsetPage($userId, $types, $offset, $limit);
        }

        // 【V52 补关联动态】批量取被点赞/被评论的动态摘要，避免列表里只有「xx 赞了你」
        // 却看不出是哪条动态。用 IN 一次查完，杜绝 N+1。
        $postIds = [];
        foreach ($notifications as $n) {
            if (!empty($n['post_id'])) {
                $postIds[(int)$n['post_id']] = true;
            }
        }
        $postMap = [];
        if ($postIds) {
            $ids = array_keys($postIds);
            $rows = NotificationRepository::fetchPostSummaries($userId, $ids);
            foreach ($rows as $r) {
                $img = null;
                if (!empty($r['images'])) {
                    $decoded = json_decode((string)$r['images'], true);
                    if (is_array($decoded)) {
                        $first = reset($decoded);
                        $img = is_string($first) ? $first : null;
                    } elseif (is_string($r['images']) && $r['images'] !== '') {
                        $img = $r['images'];
                    }
                }
                $plain = trim(strip_tags((string)($r['content'] ?? '')));
                $postMap[(int)$r['id']] = [
                    'id' => (int)$r['id'],
                    'content' => Security::escape(mb_substr($plain, 0, 60)),
                    'image' => $img
                ];
            }
        }

        foreach ($notifications as &$n) {
            // V168：系统发出的通知（徽章等）一律以「系统通知」身份展示，
            // 不能显示真实发送人账号——历史数据里 sender_id 还是借用的 ai_assistant（会显示成 ai评论家）。
            // 展示层覆盖后，即便 sender_id 指向 AI 或管理员账号，用户看到的都是系统。
            if (self::isSystemOriginNotification((string)($n['type'] ?? ''))) {
                $n['sender'] = [
                    'username' => 'system_notice',
                    'nickname' => Helper::SYSTEM_SENDER_NICKNAME,
                    'avatar'   => Helper::SYSTEM_SENDER_AVATAR
                ];
            } elseif (Helper::isDeletedAccount($n['username'] ?? null, $n['nickname'] ?? null)) {
                // V54：触发者是已注销账号时（动态按策略保留）统一显示「已注销用户」
                $n['sender'] = [
                    'username' => $n['username'] ?? '',
                    'nickname' => '已注销用户',
                    'avatar' => $n['avatar'] ?? '/templates/moments/assets/images/default-avatar.svg'
                ];
            } else {
                $n['sender'] = [
                    'username' => $n['username'] ?? '',
                    'nickname' => $n['nickname'] ?? '',
                    'avatar' => $n['avatar'] ?? '/templates/moments/assets/images/default-avatar.svg'
                ];
            }
            unset($n['username'], $n['nickname'], $n['avatar']);
            $n['content'] = Security::escape($n['content'] ?? '');
            $n['post'] = (!empty($n['post_id']) && isset($postMap[(int)$n['post_id']]))
                ? $postMap[(int)$n['post_id']]
                : null;
        }
        unset($n);

        // 总数也要带上同样的筛选条件，否则分类下的分页页数会算错
        // 注意计数 WHERE 与列表 WHERE 不同（无 n. 别名、无游标），仓储内保持 HEAD 口径
        $total = NotificationRepository::countNotifications($userId, $types);
        $unread = NotificationRepository::countUnreadNotifications($userId, $types);

        // 各分类的未读数，供前端 tab 显示角标
        $typeCounts = NotificationRepository::fetchUnreadCountsByType($userId);
        $unreadByType = [];
        foreach ($typeCounts as $row) {
            $unreadByType[(string)$row['type']] = (int)$row['cnt'];
        }

        return Response::success([
            'list' => $notifications,
            'unread' => (int)$unread,
            'unread_by_type' => $unreadByType,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => (int)ceil($total / $limit)
            ]
        ]);
    }

    /**
     * 获取未读通知数量
     */
    public function unreadCount(Request $request): Response
    {
        $user = Auth::check($request);
        // P1-2：10s 短 TTL 缓存 + 事件精确失效（来通知/标记已读/删除立即删 key）。
        // 原来每次实时 COUNT，轮询场景下是 DB 主要压力源之一。
        try {
            $count = (int)Cache::remember(
                "notif_unread_{$user['id']}",
                static function () use ($user): int {
                    return NotificationRepository::countAllUnread((int)$user['id']);
                },
                10
            );
        } catch (\PDOException $e) {
            // S6：SQLSTATE / 驱动错误码属于内部结构信息，不能拼进客户端响应
            error_log('[Hublog Notification unread] ' . $e->getMessage() // @allow-error-log
                . ' [SQLSTATE=' . ($e->errorInfo[0] ?? 'unknown')
                . ' code=' . ($e->errorInfo[1] ?? 'unknown') . ']');
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new HublogException('通知未读数查询失败，请稍后重试' . $detail, 500);
        } catch (\Throwable $e) {
            error_log('[Hublog Notification unread] ' . $e->getMessage()); // @allow-error-log
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new HublogException('通知未读数查询失败，请稍后重试' . $detail, 500);
        }
        return Response::success(['count' => $count]);
    }

    /**
     * 标记单条通知为已读
     */
    public function markRead(Request $request): Response
    {
        $user = Auth::check($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        NotificationRepository::markRead($id, (int)$user['id']);
        // P1-2：立即失效未读缓存，角标实时清零
        Cache::delete("notif_unread_{$user['id']}");
        return Response::success(null, '已标记为已读');
    }

    /**
     * 标记当前用户所有通知为已读
     */
    public function markAllRead(Request $request): Response
    {
        $user = Auth::check($request);
        NotificationRepository::markAllRead((int)$user['id']);
        // P1-2：立即失效未读缓存，角标实时清零
        Cache::delete("notif_unread_{$user['id']}");
        return Response::success(null, '全部已读');
    }

    /**
     * 删除单条通知
     */
    public function delete(Request $request): Response
    {
        $user = Auth::check($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        NotificationRepository::deleteNotification($id, (int)$user['id']);
        // P1-2：删掉的可能是未读通知，立即失效缓存
        Cache::delete("notif_unread_{$user['id']}");
        return Response::success(null, '删除成功');
    }

    /**
     * 清空当前用户的全部通知（真删数据库记录）
     * 单独开一个 clear 路径，避免和 DELETE /notifications/{id} 的路由参数冲突
     */
    public function clear(Request $request): Response
    {
        $user = Auth::check($request);
        NotificationRepository::deleteAllNotifications((int)$user['id']);
        Cache::delete("notif_unread_{$user['id']}");
        return Response::success(null, '已清空通知');
    }

    /**
     * GET /api/app/events —— 事件长轮询（App / 小程序端专用）
     *
     * 为什么需要它：EventSource 在 App 端不可用，此前 App 只能盲轮询未读数，
     * 既拿不到 chat_message / typing 这类实时事件，又白白耗电。
     * 这里把 H5 那条 SSE 通道背后的同一个事件队列（SsePush）暴露成普通 HTTP 接口，
     * 让 App 端也能拿到真实事件，而不是只有未读数。
     *
     * 前端传 last_event_id（上次拿到的最大事件 id），只返回增量的事件。
     */
    public function eventsPoll(Request $request): Response
    {
        $user = Auth::check($request);
        $userId = (int)$user['id'];
        $lastEventId = max(0, $request->getInt('last_event_id', 0));

        $events = SsePush::poll($userId, $lastEventId);

        $maxId = $lastEventId;
        foreach ($events as $e) {
            if ((int)($e['id'] ?? 0) > $maxId) {
                $maxId = (int)$e['id'];
            }
        }

        // P1-3：未读数改走 P1-2 的短缓存通道（10s TTL + 事件失效），
        // 不再每次长轮询都直查 DB
        $unreadNotifications = (int)Cache::remember(
            "notif_unread_{$userId}",
            static fn (): int => NotificationRepository::countAllUnread($userId),
            10
        );
        $unreadMessages = ChatService::getTotalUnread($userId);

        return Response::success([
            'events'               => array_values($events),
            'last_event_id'        => $maxId,
            'unread_notifications' => $unreadNotifications,
            'unread_messages'      => $unreadMessages,
            'server_time'          => time()
        ]);
    }

    /**
     * SSE 实时推送流
     * 前端通过 EventSource 连接，服务端持续推送通知和新消息事件
     */
    public function sseStream(Request $request): Response
    {
        // P1-7：本端点是全项目唯一允许 ?token= 查询传参的接口——
        // EventSource 规范上无法自定义请求头，H5 端只能把令牌放 URL。
        // 风险已收敛：1) 普通接口的 Auth::check 已收回退，令牌不会进其它 URL；
        // 2) access 日志对 token 参数脱敏（Logger::sanitizeUri）。
        // 后续如要彻底消除，可改为「专用短时效 SSE 令牌」（换取即 5 分钟过期）。
        // P0-3：新增 httpOnly Cookie 回退——EventSource 同源请求自动携带 Cookie，
        // 刷新页面后（localStorage 不再存 token）SSE 依然可用；?token= 兜底保留。
        $token = $request->getBearerToken();
        if (!$token) {
            $token = $_COOKIE[Auth::AUTH_COOKIE] ?? null;
        }
        if (!$token) {
            $token = $request->getString('token');
        }
        if (!$token) {
            return Response::error('未登录', 401);
        }
        // 用 token 直接校验用户
        try {
            $payload = Auth::verifyToken($token);
        } catch (\Throwable $e) {
            return Response::error('登录已过期', 401);
        }
        $userId = (int)($payload['sub'] ?? 0);
        if ($userId <= 0) {
            return Response::error('无效的 Token', 401);
        }
        $lastEventId = (int)($request->getInt('last_event_id', 0));

        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx 关闭缓冲

        // 发送初始连接成功事件
        echo "event: connected\ndata: " . json_encode(['user_id' => $userId, 'time' => time()]) . "\n\n";
        ob_flush();
        flush();

        $startTime = time();
        // 【P0-5 临时缓解】单条 SSE 连接独占一个 PHP-FPM worker，原先 50 秒断开，
        // 意味着「在线 N 人 ≈ 常驻占 N 个 worker」——按 pm.max_children=20 估算，
        // 20 个在线用户就能耗尽全站 worker 池，其余 API 请求排队甚至 504。
        //
        // 现降到 20 秒：worker 占用率 = 连接时长 / (连接时长 + 前端重连间隙)，
        // 由 50/(50+30)=62.5% 降到 20/(20+30)=40%，每个 worker 可承载的在线用户
        // 从约 32 提升到约 50。注意不能只缩连接时长而让前端立即重连 ——
        // 那样间隙趋近 0，占用率反而升到 95%。前端保持 30 秒重连间隙，
        // 断线期本就由 30 秒轮询兜底，因此通知实时性不变。
        //
        // 根治方向：SSE 迁到独立长连接网关 / 改长轮询，让推送不再占用 PHP worker。
        $maxExecutionTime = 20; // 秒后断开，前端会自动重连
        $lastHeartbeat = 0;     // P1-3：心跳节流时间戳

        while (time() - $startTime < $maxExecutionTime) {
            // 检查客户端是否断开
            if (connection_aborted()) {
                break;
            }

            // 获取待推送事件
            $events = SsePush::poll($userId, $lastEventId);

            foreach ($events as $event) {
                echo "id: {$event['id']}\n";
                echo "event: {$event['event']}\n";
                echo "data: " . json_encode($event['data'], JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastEventId = $event['id'];
            }

            // P1-3：未读数查询从「每 2 秒循环必查」改为「仅心跳到期时查」。
            // 原实现每 2 秒查 2 次 COUNT 但心跳 10 秒才发一次，4/5 的查询结果
            // 直接被丢弃；1000 个 SSE 连接就是每秒 1000 次无效 COUNT。
            // 现在每连接每 10 秒最多 2 次，且走 P1-2 短缓存（多客户端共享）。
            if (time() - $lastHeartbeat >= 10) {
                $lastHeartbeat = time();
                $unreadNotifications = (int)Cache::remember(
                    "notif_unread_{$userId}",
                    static fn (): int => NotificationRepository::countAllUnread($userId),
                    10
                );
                $unreadMessages = ChatService::getTotalUnread($userId);
                echo "event: heartbeat\ndata: " . json_encode([
                    'unread_notifications' => $unreadNotifications,
                    'unread_messages' => $unreadMessages,
                    'time' => time()
                ]) . "\n\n";
            }

            ob_flush();
            flush();

            // 休眠 2 秒再检查
            sleep(2);
        }

        // 发送关闭事件
        echo "event: close\ndata: " . json_encode(['reason' => 'timeout']) . "\n\n";
        ob_flush();
        flush();

        exit;
    }

    /**
     * 是否为系统发出的通知类型（展示层统一以「系统通知」身份呈现）。
     *
     * 这些通知不是某个用户的行为，sender_id 只是为了满足外键约束存的占位账号
     * （V168 前是 ai_assistant，会显示成「ai评论家」）。
     */
    private static function isSystemOriginNotification(string $type): bool
    {
        return in_array($type, ['badge', 'system'], true);
    }
}
