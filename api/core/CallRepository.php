<?php
/**
 * Hublog 社交互动域「音视频通话」数据仓储
 *
 * 承载 CallController 下沉的全部 SQL 与参数绑定。原控制器中 DATE_SUB(NOW(),
 * INTERVAL 300 SECOND) 之类拼接值全部改为 ? 绑定；字段清单（含 SELECT *）、
 * JOIN/ORDER BY/LIMIT 顺序与迁移前 HEAD 原文逐字一致。
 *
 * 【相似 SQL 不合并】findCallByRoom() 与 findPendingCall() 虽同为 SELECT *，
 * 但 WHERE / ORDER BY / LIMIT 完全不同，各建各的方法；
 * findCallee()（id,status,deleted_at）与 findBriefUser()（id,nickname,username,avatar）
 * 字段集不同，不得复用。
 */

declare(strict_types=1);

namespace Hublog\Core;

class CallRepository
{
    /** invite() —— 被叫用户状态探测（软删 deleted_at 过滤留在控制器判断） */
    public static function findCallee(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, status, deleted_at FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }

    /** checkBlockedBothWays() —— 单向拉黑探测（双向由控制器交换参数各调一次） */
    public static function existsBlockRow(int $userId, int $blockedUserId): mixed
    {
        return Database::fetchColumn(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$userId, $blockedUserId]
        );
    }

    /** invite() —— 5 分钟内同对用户进行中的通话（INTERVAL 值改绑定） */
    public static function findActiveCall(int $callerId, int $calleeId, int $windowSeconds): ?array
    {
        return Database::fetchOne(
            "SELECT room_id, status, type, created_at
             FROM call_records
             WHERE caller_id = ? AND callee_id = ? AND status IN ('calling', 'accepted')
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             LIMIT 1",
            [$callerId, $calleeId, $windowSeconds]
        );
    }

    /** invite() —— 新建通话记录，返回 call_id */
    public static function insertCall(string $roomId, int $callerId, int $calleeId, string $type): int
    {
        return Database::insert('call_records', [
            'room_id'   => $roomId,
            'caller_id' => $callerId,
            'callee_id' => $calleeId,
            'type'      => $type,
            'status'    => 'calling',
        ]);
    }

    /** accept() —— 置为 accepted 并写 started_at */
    public static function markAccepted(int $callId, string $startedAt): int
    {
        return Database::update(
            'call_records',
            ['status' => 'accepted', 'started_at' => $startedAt],
            'id = ?',
            [$callId]
        );
    }

    /** finish() —— 终态落库：status + duration + ended_at */
    public static function finishCall(int $callId, string $status, int $duration, string $endedAt): int
    {
        return Database::update(
            'call_records',
            [
                'status'   => $status,
                'duration' => $duration,
                'ended_at' => $endedAt,
            ],
            'id = ?',
            [$callId]
        );
    }

    /** hangup() / findMyCall() —— 按房间号取完整记录（SELECT *，无状态过滤） */
    public static function findCallByRoom(string $roomId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM call_records WHERE room_id = ? LIMIT 1",
            [$roomId]
        );
    }

    /** pending() —— 还在响铃且未超时的最新来电（SELECT * + ORDER BY id DESC） */
    public static function findPendingCall(int $calleeId, int $ringTimeout): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM call_records
             WHERE callee_id = ? AND status = 'calling'
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY id DESC LIMIT 1",
            [$calleeId, $ringTimeout]
        );
    }

    /** records() —— 列表/计数共用的 WHERE 助手 */
    private static function callRecordsWhere(int $me, int $peerId): array
    {
        $where = '(caller_id = ? OR callee_id = ?)';
        $params = [$me, $me];
        if ($peerId > 0) {
            $where .= ' AND ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?))';
            $params[] = $me;
            $params[] = $peerId;
            $params[] = $peerId;
            $params[] = $me;
        }
        return [$where, $params];
    }

    /** records() —— 与列表同一 WHERE 的总数 */
    public static function countRecords(int $me, int $peerId): int
    {
        [$where, $params] = self::callRecordsWhere($me, $peerId);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM call_records WHERE {$where}",
            $params
        );
    }

    /** records() —— SELECT * 分页；原 LIMIT n OFFSET m 写法对应 LIMIT ? OFFSET ?（参数顺序 limit, offset） */
    public static function fetchRecords(int $me, int $peerId, int $limit, int $offset): array
    {
        [$where, $params] = self::callRecordsWhere($me, $peerId);
        return Database::fetchAll(
            "SELECT * FROM call_records WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** expireStaleCalls() —— 惰性回收超时响铃，WHERE 中的 interval 值走绑定 */
    public static function expireStaleCalls(int $ringTimeout, string $now): int
    {
        return Database::update(
            'call_records',
            ['status' => 'missed', 'ended_at' => $now],
            "status = 'calling' AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ringTimeout]
        );
    }

    /** briefUser() / hangup() / pending() —— 通话场景用户简要信息（4 列） */
    public static function findBriefUser(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, nickname, username, avatar FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }
}
