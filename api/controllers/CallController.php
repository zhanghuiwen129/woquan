<?php
/**
 * Hublog 音视频通话控制器（P1-5，一对一语音/视频，音视频流走腾讯云 TRTC）
 *
 * 职责边界：
 *   - 本控制器**只做信令与记录**，不做音视频流（流由客户端 SDK 直连腾讯云）。
 *   - 信令通道复用现有 SsePush：在线走 SSE 实时通道，离线由 SsePush 内部
 *     转交 PushService 做厂商推送，因此**不需要额外的 WebSocket 服务**。
 *   - UserSig 由服务端用 SecretKey 签发，密钥绝不下发客户端。
 *
 * 呼叫状态机（call_records.status）：
 *   calling  响铃中 → accepted 已接通 → ended 正常结束
 *                   → rejected 被叫拒接
 *                   → canceled 主叫取消
 *                   → missed   响铃超时（惰性回收，无定时任务）
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\CallRepository;
use Hublog\Core\SsePush;
use Hublog\Core\TrtcSig;
use Hublog\Core\Cache;

class CallController extends BaseController
{
    /** 响铃超时（秒）：超过这个时间仍为 calling 视为未接 */
    private const RING_TIMEOUT = 90;

    /** 通话记录分页默认值 */
    private const PAGE_LIMIT = 20;

    /* ==================================================================
     * 凭证
     * ================================================================== */

    /**
     * POST /api/call/token —— 签发当前用户的 TRTC UserSig
     *
     * 客户端进房前调用，拿到 (sdk_app_id, user_id, user_sig) 三件套。
     * SecretKey 不下发。
     */
    public function token(Request $request): Response
    {
        $user = Auth::check($request);

        $issued = TrtcSig::issue((int)$user['id'], 86400);
        if (!$issued['ok']) {
            return Response::error($issued['message'], 503);
        }

        return Response::success($issued, '签发成功');
    }

    /* ==================================================================
     * 呼叫信令
     * ================================================================== */

    /**
     * POST /api/call/invite —— 发起呼叫
     *
     * 入参：callee_id（被叫 user_id）、type（audio|video）
     * 返回：room_id、expire_at（响铃截止时间戳）、对方用户简要信息
     */
    public function invite(Request $request): Response
    {
        $user = Auth::check($request);
        $callerId = (int)$user['id'];

        $calleeId = $request->getInt('callee_id');
        $type = $request->getString('type', 'audio');

        if (!in_array($type, ['audio', 'video'], true)) {
            return Response::error('通话类型只能是 audio 或 video', 400);
        }
        if ($calleeId <= 0) {
            return Response::error('参数错误', 400);
        }
        if ($calleeId === $callerId) {
            return Response::error('不能呼叫自己', 400);
        }

        // 服务端必须先配置 TRTC，否则客户端拿到房间号也进不去
        if (!TrtcSig::configured()) {
            return Response::error('服务端未开启音视频通话', 503);
        }

        $callee = CallRepository::findCallee($calleeId);
        if (!$callee || !empty($callee['deleted_at'])) {
            return Response::error('用户不存在', 404);
        }
        if ((int)($callee['status'] ?? 1) !== 1) {
            return Response::error('该用户已被禁用', 403);
        }

        $blockedErr = $this->checkBlockedBothWays($callerId, $calleeId);
        if ($blockedErr !== null) {
            return Response::error($blockedErr, 403);
        }

        // 惰性回收超时的响铃（有缓存节流，不必每次都 UPDATE）
        $this->expireStaleCalls();

        // 同一对用户在 5 分钟内已有进行中的通话 → 复用，避免重复响铃
        $active = CallRepository::findActiveCall($callerId, $calleeId, 300);
        if ($active) {
            return Response::error('对方正在通话中或已在呼叫', 409);
        }

        // 数字房间号：各端 SDK 兼容性最好（字符串房间号部分插件需额外参数）
        $roomId = (string)random_int(10000000, 99999999);

        $callId = CallRepository::insertCall($roomId, $callerId, $calleeId, $type);

        $caller = $this->briefUser($callerId);
        $payload = [
            'call_id'   => (int)$callId,
            'room_id'   => $roomId,
            'type'      => $type,
            'user_id'   => $callerId,
            'nickname'  => $caller['nickname'] ?? '',
            'avatar'    => $caller['avatar'] ?? '',
            'expire_at' => time() + self::RING_TIMEOUT,
        ];

        // 在线走 SSE；离线由 SsePush 内部转 PushService 发厂商推送
        SsePush::push($calleeId, 'call_invite', $payload);

        return Response::success($payload, '呼叫已发出');
    }

    /**
     * POST /api/call/accept —— 被叫接听
     */
    public function accept(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        $record = $this->findMyCall($request, $me, false);
        if ($record instanceof Response) {
            return $record;
        }
        if ($record['status'] !== 'calling') {
            return Response::error('通话已结束', 409);
        }

        CallRepository::markAccepted((int)$record['id'], date('Y-m-d H:i:s'));

        SsePush::push((int)$record['caller_id'], 'call_accepted', [
            'call_id' => (int)$record['id'],
            'room_id' => $record['room_id'],
            'type'    => $record['type'],
        ]);

        return Response::success([
            'call_id' => (int)$record['id'],
            'room_id' => $record['room_id'],
            'type'    => $record['type'],
        ], '已接听');
    }

    /**
     * POST /api/call/reject —— 被叫拒接
     */
    public function reject(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        $record = $this->findMyCall($request, $me, false);
        if ($record instanceof Response) {
            return $record;
        }
        if ($record['status'] !== 'calling') {
            return Response::error('通话已结束', 409);
        }

        $this->finish((int)$record['id'], 'rejected', 0);

        SsePush::push((int)$record['caller_id'], 'call_rejected', [
            'call_id' => (int)$record['id'],
            'room_id' => $record['room_id'],
            'type'    => $record['type'],
        ]);

        return Response::success(null, '已拒接');
    }

    /**
     * POST /api/call/cancel —— 主叫取消（对方还没接）
     */
    public function cancel(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        $record = $this->findMyCall($request, $me, true);
        if ($record instanceof Response) {
            return $record;
        }
        if ($record['status'] !== 'calling') {
            return Response::error('通话已接通，请挂断', 409);
        }

        $this->finish((int)$record['id'], 'canceled', 0);

        SsePush::push((int)$record['callee_id'], 'call_canceled', [
            'call_id' => (int)$record['id'],
            'room_id' => $record['room_id'],
            'type'    => $record['type'],
        ]);

        return Response::success(null, '已取消');
    }

    /**
     * POST /api/call/hangup —— 挂断（双方任一方可调用）
     *
     * duration 由客户端上报（秒），服务端用 started_at 兜底校正。
     */
    public function hangup(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        $roomId = $request->getString('room_id');
        if ($roomId === '') {
            return Response::error('参数错误', 400);
        }

        $record = CallRepository::findCallByRoom($roomId);
        if (!$record) {
            return Response::error('通话不存在', 404);
        }
        if ((int)$record['caller_id'] !== $me && (int)$record['callee_id'] !== $me) {
            return Response::error('无权操作此通话', 403);
        }
        if (!in_array($record['status'], ['calling', 'accepted'], true)) {
            return Response::success(null, '通话已结束');
        }

        $duration = $request->getInt('duration');
        if ($record['status'] === 'accepted' && $duration <= 0 && !empty($record['started_at'])) {
            $duration = max(0, time() - strtotime($record['started_at']));
        }
        if ($duration < 0) {
            $duration = 0;
        }

        // 主叫在响铃阶段挂断 = canceled；被叫响铃阶段挂断 = rejected
        $finalStatus = 'ended';
        if ($record['status'] === 'calling') {
            $finalStatus = ((int)$record['caller_id'] === $me) ? 'canceled' : 'rejected';
        }
        $this->finish((int)$record['id'], $finalStatus, $duration);

        $peerId = ((int)$record['caller_id'] === $me) ? (int)$record['callee_id'] : (int)$record['caller_id'];
        SsePush::push($peerId, 'call_ended', [
            'call_id'  => (int)$record['id'],
            'room_id'  => $record['room_id'],
            'type'     => $record['type'],
            'status'   => $finalStatus,
            'duration' => $duration,
        ]);

        return Response::success(['duration' => $duration], '通话已结束');
    }

    /* ==================================================================
     * 查询
     * ================================================================== */

    /**
     * GET /api/call/pending —— 拉取「正在呼入」的来电
     *
     * 用于被叫从离线推送唤醒进 App 后补齐现场（SSE 可能已经断开），
     * 也用于来电页刷新。只返回 RING_TIMEOUT 内仍在响铃的记录。
     */
    public function pending(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        $record = CallRepository::findPendingCall($me, self::RING_TIMEOUT);
        if (!$record) {
            return Response::success(null);
        }

        $caller = $this->briefUser((int)$record['caller_id']);

        return Response::success([
            'call_id'   => (int)$record['id'],
            'room_id'   => $record['room_id'],
            'type'      => $record['type'],
            'user_id'   => (int)$record['caller_id'],
            'nickname'  => $caller['nickname'] ?? '',
            'avatar'    => $caller['avatar'] ?? '',
            'expire_at' => strtotime($record['created_at']) + self::RING_TIMEOUT,
        ]);
    }

    /**
     * GET /api/call/records —— 通话记录
     *
     * 可选参数 user_id（只列与某人的通话）、page、limit
     */
    public function records(Request $request): Response
    {
        $user = Auth::check($request);
        $me = (int)$user['id'];

        ['page' => $page, 'limit' => $limit] = $request->pagination(self::PAGE_LIMIT);
        $peerId = $request->getInt('user_id');
        $offset = ($page - 1) * $limit;

        $total = CallRepository::countRecords($me, $peerId);
        $rows = CallRepository::fetchRecords($me, $peerId, $limit, $offset);

        $list = array_map(function (array $row) use ($me): array {
            $isCaller = ((int)$row['caller_id'] === $me);
            $peerId = $isCaller ? (int)$row['callee_id'] : (int)$row['caller_id'];
            $peer = $this->briefUser($peerId);

            return [
                'id'         => (int)$row['id'],
                'room_id'    => $row['room_id'],
                'type'       => $row['type'],
                'status'     => $row['status'],
                'duration'   => (int)$row['duration'],
                'is_caller'  => $isCaller,
                'peer'       => $peer,
                'created_at' => $row['created_at'],
                'ended_at'   => $row['ended_at'],
            ];
        }, $rows);

        return Response::success([
            'items' => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /* ==================================================================
     * 内部工具
     * ================================================================== */

    /**
     * 按 room_id 取通话记录，并校验调用方身份
     *
     * @param bool $mustBeCaller true=必须是主叫，false=必须是被叫
     * @return array|Response 出错时返回 Response
     */
    private function findMyCall(Request $request, int $me, bool $mustBeCaller)
    {
        $roomId = $request->getString('room_id');
        if ($roomId === '') {
            return Response::error('参数错误', 400);
        }

        $record = CallRepository::findCallByRoom($roomId);
        if (!$record) {
            return Response::error('通话不存在', 404);
        }

        $expected = $mustBeCaller ? (int)$record['caller_id'] : (int)$record['callee_id'];
        if ($expected !== $me) {
            return Response::error('无权操作此通话', 403);
        }

        return $record;
    }

    /** 终态落库：写入状态、时长、结束时间 */
    private function finish(int $callId, string $status, int $duration): void
    {
        CallRepository::finishCall($callId, $status, $duration, date('Y-m-d H:i:s'));
    }

    /**
     * 双向拉黑校验（与 ChatService 保持一致：任一方拉黑都不允许）
     * @return string|null 错误文案，null 表示通过
     */
    private function checkBlockedBothWays(int $userId, int $otherUserId): ?string
    {
        try {
            $blockedThem = CallRepository::existsBlockRow($userId, $otherUserId);
            if ($blockedThem) {
                return '你已拉黑该用户，无法发起通话';
            }
            $blockedByThem = CallRepository::existsBlockRow($otherUserId, $userId);
            if ($blockedByThem) {
                return '对方拒绝接收你的通话';
            }
        } catch (\Throwable $e) {
            // blocked_users 表异常时不阻断通话，只记日志
            error_log('[Hublog CallController] 拉黑校验失败: ' . $e->getMessage()); // @allow-error-log
        }

        return null;
    }

    /** 取用户简要信息（通话场景只需昵称头像） */
    private function briefUser(int $userId): array
    {
        $row = CallRepository::findBriefUser($userId);
        if (!$row) {
            return ['id' => $userId, 'nickname' => '用户', 'avatar' => ''];
        }
        return [
            'id'       => (int)$row['id'],
            'nickname' => $row['nickname'] ?: ($row['username'] ?? '用户'),
            'avatar'   => $row['avatar'] ?? '',
        ];
    }

    /**
     * 惰性回收超时响铃（60 秒节流，避免每次请求都扫表）
     */
    private function expireStaleCalls(): void
    {
        try {
            $key = 'call_expire_stale_at';
            $last = (int)(Cache::get($key) ?: 0);
            if ($last > 0 && (time() - $last) < 60) {
                return;
            }
            Cache::set($key, (string)time(), 120);

            CallRepository::expireStaleCalls(self::RING_TIMEOUT, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            error_log('[Hublog CallController] 超时呼叫回收失败: ' . $e->getMessage()); // @allow-error-log
        }
    }
}
