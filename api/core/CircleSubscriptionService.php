<?php
/**
 * Hublog 付费圈子订阅周期与到期生命周期服务
 *
 * 职责边界：
 *   - 订阅天数解析（单圈配置优先，全局 settings 兜底，统一截断到 1..3650 天）；
 *   - 读时订阅状态判定（active / none / expired / exempt），供发帖、内容门禁与详情复用；
 *   - 到期懒清理、批量清退、到期提醒（best-effort，绝不阻断读请求）。
 *
 * 安全底线：所有「能不能看 / 能不能发」的判定都直接比较 expire_at 与 time()，
 * 不依赖 status=0 或任何 cron 产物。cron 只做清退与提醒，属于体验与数据整洁层。
 */

declare(strict_types=1);

namespace Hublog\Core;

final class CircleSubscriptionService
{
    /** 单圈 / 全局订阅天数上限（10 年），防止误填超大值把 datetime 撑爆 */
    private const MAX_PAY_DAYS = 3650;

    /** 全局配置缺失或非法时的兜底天数（沿用历史硬编码语义） */
    private const DEFAULT_PAY_DAYS = 365;

    /** 订阅状态：有效 */
    public const STATE_ACTIVE = 'active';

    /** 订阅状态：从未订阅 / 已失效（status != 1） */
    public const STATE_NONE = 'none';

    /** 订阅状态：订阅行仍为 status=1，但 expire_at 已过 */
    public const STATE_EXPIRED = 'expired';

    /** 订阅状态：免费圈 / 圈主 / 圈管，无需订阅 */
    public const STATE_EXEMPT = 'exempt';

    /**
     * 单圈最终订阅天数：circles.pay_days > 0 优先，否则读全局配置。
     * 两个来源都做 >0 校验与上限截断；fetchSettingValue 无行返回 false，(int)false=0 必须兜底。
     */
    public static function effectivePayDays(int $circleId): int
    {
        if ($circleId > 0) {
            $circle = PaymentRepository::findCirclePayInfo($circleId);
            $perCircle = (int)($circle['pay_days'] ?? 0);
            if ($perCircle > 0) {
                return min($perCircle, self::MAX_PAY_DAYS);
            }
        }
        return self::globalPayDays();
    }

    /** 全局订阅天数（settings.circle_subscribe_days），缺省 365 天 */
    private static function globalPayDays(): int
    {
        $raw = PaymentRepository::fetchSettingValue('circle_subscribe_days');
        $days = (int)$raw;
        return $days > 0 ? min($days, self::MAX_PAY_DAYS) : self::DEFAULT_PAY_DAYS;
    }

    /**
     * 当前用户对某圈的订阅状态。
     *
     * @param int         $userId   当前用户 ID，<=0 视为游客
     * @param int         $circleId 圈子 ID
     * @param array|null  $circle   已取到的圈子行（需含 pay_type / owner_id），null 时内部查询
     * @return string active|none|expired|exempt
     */
    public static function subscriptionState(int $userId, int $circleId, ?array $circle = null): string
    {
        if ($userId <= 0 || $circleId <= 0) {
            return self::STATE_NONE;
        }
        if ($circle === null) {
            $circle = PaymentRepository::findCirclePayInfo($circleId);
        }
        if ($circle === null) {
            return self::STATE_NONE;
        }
        if ((int)($circle['pay_type'] ?? 0) !== 2) {
            return self::STATE_EXEMPT;
        }
        if ((int)($circle['owner_id'] ?? 0) === $userId) {
            return self::STATE_EXEMPT;
        }
        $role = CircleRepository::getMemberRole($circleId, $userId);
        if ($role !== null && in_array(strtolower((string)$role), ['owner', 'admin'], true)) {
            return self::STATE_EXEMPT;
        }

        $row = PaymentRepository::findActiveSubscribe($circleId, $userId);
        if (!$row) {
            return self::STATE_NONE;
        }
        if ($row['expire_at'] === null || $row['expire_at'] === '') {
            // 老数据 expire_at=NULL 视为长期有效（保留历史语义）
            return self::STATE_ACTIVE;
        }
        $expire = strtotime((string)$row['expire_at']);
        return ($expire !== false && $expire > time()) ? self::STATE_ACTIVE : self::STATE_EXPIRED;
    }

    /** 是否是有效付费订阅（不含 exempt：免费圈 / 圈主 / 圈管不算「订阅」） */
    public static function isSubscriptionActive(int $userId, int $circleId, ?array $circle = null): bool
    {
        return self::subscriptionState($userId, $circleId, $circle) === self::STATE_ACTIVE;
    }

    /** 当前用户在指定圈子的订阅到期时间（不分页、不限制 status，供详情回显用） */
    public static function subscriptionExpireAt(int $userId, int $circleId): ?string
    {
        if ($userId <= 0 || $circleId <= 0) {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT expire_at FROM circle_subscribes WHERE circle_id = ? AND user_id = ? LIMIT 1",
            [$circleId, $userId]
        );
        if (!$row || $row['expire_at'] === null) {
            return null;
        }
        return (string)$row['expire_at'];
    }

    /** 是否可以读取圈子正文：有效订阅或免订阅身份均可；命中 expired 时顺手做 best-effort 懒清理 */
    public static function canAccessCircleContent(int $userId, int $circleId, ?array $circle = null): bool
    {
        $state = self::subscriptionState($userId, $circleId, $circle);
        if ($state === self::STATE_EXPIRED) {
            self::lazyCleanup($circleId, $userId);
        }
        return in_array($state, [self::STATE_ACTIVE, self::STATE_EXEMPT], true);
    }

    // ==================== 到期懒清理与批量清退 ====================

    /**
     * 读请求命中 expired 时的懒清理（best-effort，绝不中断读请求）。
     *
     * ① 条件 UPDATE 把订阅置 status=0（rowCount=0 直接返回，天然幂等）；
     * ② 开启 circle_sub_auto_cleanup 时只删 role='member' 且 status=1 的普通成员行，
     *    永不删圈主 / 圈管 / 被封禁成员；member_count 用 CASE 表达式做下限 0 的自减；
     * ③ 清掉圈子列表缓存。全程不碰钱包 / 积分 / 余额。
     *
     * @return bool 是否真的移除了普通成员行
     */
    public static function lazyCleanup(int $circleId, int $userId): bool
    {
        try {
            if (!self::deactivateExpiredSubscription($circleId, $userId)) {
                return false;
            }
            $removed = self::removeExpiredMember($circleId, $userId);
            try {
                Cache::deleteByPrefix('circles_list_');
            } catch (\Throwable $e) {
                // 缓存清理失败不影响主流程
            }
            return $removed;
        } catch (\Throwable $e) {
            error_log('[Hublog CircleSubscription lazyCleanup] ' . $e->getMessage()); // @allow-error-log
            return false;
        }
    }

    /**
     * 批量扫描并清退过期订阅（cron 调用，best-effort）。
     *
     * @return array{expired:int,removed:int,failed:int}
     */
    public static function sweepExpired(int $limit = 200): array
    {
        $stats = ['expired' => 0, 'removed' => 0, 'failed' => 0];
        $limit = max(1, min(1000, $limit));
        $now = date('Y-m-d H:i:s');
        try {
            $rows = Database::fetchAll(
                "SELECT circle_id, user_id FROM circle_subscribes
                 WHERE status = 1 AND expire_at IS NOT NULL AND expire_at <= ?
                 ORDER BY id ASC LIMIT ?",
                [$now, $limit]
            );
        } catch (\Throwable $e) {
            error_log('[Hublog CircleSubscription sweep scan] ' . $e->getMessage()); // @allow-error-log
            return $stats;
        }

        foreach ($rows as $row) {
            $cid = (int)($row['circle_id'] ?? 0);
            $uid = (int)($row['user_id'] ?? 0);
            try {
                if (self::deactivateExpiredSubscription($cid, $uid)) {
                    $stats['expired']++;
                    try {
                        Cache::deleteByPrefix('circles_list_');
                    } catch (\Throwable $e) {
                        // 忽略
                    }
                }
                if (self::removeExpiredMember($cid, $uid)) {
                    $stats['removed']++;
                    // 成员位变了，信息流 / 热门榜缓存需要失效
                    try {
                        Cache::bumpNsVersion('timeline');
                        Cache::bumpNsVersion('popular');
                    } catch (\Throwable $e) {
                        // 忽略
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                error_log('[Hublog CircleSubscription sweep row ' . $cid . '/' . $uid . '] ' . $e->getMessage()); // @allow-error-log
            }
        }
        return $stats;
    }

    /** 条件置 status=0：只有仍为 status=1 且已过期的行会被更新，重复调用零副作用 */
    private static function deactivateExpiredSubscription(int $circleId, int $userId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE circle_subscribes SET status = 0
             WHERE circle_id = ? AND user_id = ? AND status = 1
               AND expire_at IS NOT NULL AND expire_at <= ?"
        );
        $stmt->execute([$circleId, $userId, date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    /** 自动清退开关：只有显式配置 '1' 才启用（迁移默认写入 1） */
    private static function autoCleanupEnabled(): bool
    {
        return self::setting('circle_sub_auto_cleanup') === '1';
    }

    /**
     * 删除过期普通成员位并回减 member_count。
     * role 限定 'member' + status=1：圈主 / 圈管永不删除，重复调用零副作用。
     */
    private static function removeExpiredMember(int $circleId, int $userId): bool
    {
        if ($circleId <= 0 || $userId <= 0 || !self::autoCleanupEnabled()) {
            return false;
        }
        $stmt = Database::getInstance()->prepare(
            "DELETE FROM circle_members WHERE circle_id = ? AND user_id = ? AND role = 'member' AND status = 1"
        );
        $stmt->execute([$circleId, $userId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        // CASE 表达式等价于 GREATEST(member_count-1, 0)，但 MySQL / SQLite 都支持
        Database::getInstance()->prepare(
            "UPDATE circles SET member_count = CASE WHEN member_count > 0 THEN member_count - 1 ELSE 0 END WHERE id = ?"
        )->execute([$circleId]);
        return true;
    }

    // ==================== 到期提醒 ====================

    /**
     * 到期前 / 到期当天提醒（先占后发，保证幂等）。
     *
     * 时间窗刻意限制为「到期前 N 天内」和「到期后 1 天内」，
     * 避免首次上线时把历史过期订阅全量补发一遍。
     * circle_expire_remind_days=0 关闭提醒。
     *
     * @param int|null $lookaheadDays null = 读 settings.circle_expire_remind_days
     * @return array{reminded:int,failed:int}
     */
    public static function sendReminders(?int $lookaheadDays = null, int $limit = 200): array
    {
        $stats = ['reminded' => 0, 'failed' => 0];
        $days = $lookaheadDays === null ? (int)self::setting('circle_expire_remind_days') : $lookaheadDays;
        if ($days <= 0) {
            return $stats;
        }
        $days = min($days, 365);
        $limit = max(1, min(1000, $limit));
        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        $beforeUntil = date('Y-m-d H:i:s', $nowTs + $days * 86400);
        $dayAgo = date('Y-m-d H:i:s', $nowTs - 86400);

        try {
            $beforeRows = Database::fetchAll(
                "SELECT cs.id, cs.user_id, c.name AS circle_name, cs.expire_at
                 FROM circle_subscribes cs LEFT JOIN circles c ON c.id = cs.circle_id
                 WHERE cs.status = 1 AND cs.expire_at IS NOT NULL
                   AND cs.expire_at BETWEEN ? AND ? AND cs.notified_before_at IS NULL
                 ORDER BY cs.expire_at ASC LIMIT ?",
                [$now, $beforeUntil, $limit]
            );
            $expireRows = Database::fetchAll(
                "SELECT cs.id, cs.user_id, c.name AS circle_name, cs.expire_at
                 FROM circle_subscribes cs LEFT JOIN circles c ON c.id = cs.circle_id
                 WHERE cs.status = 1 AND cs.expire_at IS NOT NULL
                   AND cs.expire_at <= ? AND cs.expire_at > ? AND cs.notified_expire_at IS NULL
                 ORDER BY cs.expire_at ASC LIMIT ?",
                [$now, $dayAgo, $limit]
            );
        } catch (\Throwable $e) {
            error_log('[Hublog CircleSubscription remind scan] ' . $e->getMessage()); // @allow-error-log
            return $stats;
        }

        foreach ([['notified_before_at', $beforeRows, 'before'], ['notified_expire_at', $expireRows, 'expire']] as [$marker, $rows, $kind]) {
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0 || !self::claimMarker($id, (string)$marker, $now)) {
                    continue;
                }
                try {
                    $name = (string)($row['circle_name'] ?? '圈子');
                    $message = $kind === 'before'
                        ? "你订阅的圈子《{$name}》将于 " . (string)($row['expire_at'] ?? '') . " 到期，续费后可继续查看圈子内容。"
                        : "你订阅的圈子《{$name}》已到期，续费后可继续查看圈子内容。";
                    self::deliverReminder((int)($row['user_id'] ?? 0), mb_substr($message, 0, 255), $now);
                    $stats['reminded']++;
                } catch (\Throwable $e) {
                    // 发送失败归还占位，下轮可重试
                    try {
                        self::releaseMarker($id, (string)$marker);
                    } catch (\Throwable $inner) {
                        // 忽略
                    }
                    $stats['failed']++;
                    error_log('[Hublog CircleSubscription remind ' . $kind . ' #' . $id . '] ' . $e->getMessage()); // @allow-error-log
                }
            }
        }
        return $stats;
    }

    /** 先占：原子条件 UPDATE 标记提醒列，只有抢到的那一次才发送 */
    private static function claimMarker(int $id, string $column, string $now): bool
    {
        if (!in_array($column, ['notified_before_at', 'notified_expire_at'], true)) {
            return false;
        }
        $stmt = Database::getInstance()->prepare(
            "UPDATE circle_subscribes SET {$column} = ? WHERE id = ? AND status = 1 AND {$column} IS NULL"
        );
        $stmt->execute([$now, $id]);
        return $stmt->rowCount() === 1;
    }

    /** 归还占位：发送失败时允许下轮重试 */
    private static function releaseMarker(int $id, string $column): void
    {
        if (!in_array($column, ['notified_before_at', 'notified_expire_at'], true)) {
            return;
        }
        Database::getInstance()->prepare(
            "UPDATE circle_subscribes SET {$column} = NULL WHERE id = ?"
        )->execute([$id]);
    }

    /** 落 notifications(type=system) + SSE 推送；SSE 失败只记日志，不影响通知落库 */
    private static function deliverReminder(int $userId, string $content, string $now): void
    {
        if ($userId <= 0) {
            return;
        }
        $senderId = Helper::getSystemUserId();
        if ($senderId <= 0) {
            $senderId = 1;
        }
        Database::insert('notifications', [
            'user_id' => $userId,
            'sender_id' => $senderId,
            'type' => 'system',
            'content' => $content,
            'is_read' => 0,
            'created_at' => $now,
        ]);
        try {
            SsePush::push($userId, 'notification', [
                'type' => 'system',
                'content' => $content,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[Hublog CircleSubscription remind SSE] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /** 读取 settings 原始字符串，缺行 / 异常统一返回空串 */
    private static function setting(string $key): string
    {
        try {
            $raw = PaymentRepository::fetchSettingValue($key);
            return ($raw === false || $raw === null) ? '' : trim((string)$raw);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ==================== 异步任务适配 ====================

    /**
     * JobQueue / AsyncTask handler 适配。
     * payload.action: sweep | remind | all（默认 all）；任何异常都转成 false 让 JobQueue 标记失败。
     */
    public static function runFromPayload(array $payload): bool
    {
        try {
            $action = strtolower(trim((string)($payload['action'] ?? 'all')));
            if ($action === 'sweep') {
                self::sweepExpired((int)($payload['limit'] ?? 200));
            } elseif ($action === 'remind') {
                $lookahead = $payload['lookahead_days'] ?? null;
                self::sendReminders($lookahead === null ? null : (int)$lookahead, (int)($payload['limit'] ?? 200));
            } else {
                $limit = (int)($payload['limit'] ?? 200);
                $lookahead = $payload['lookahead_days'] ?? null;
                // 先提醒再清退：清退会把 status 置 0，反过来会导致到期提醒漏发
                self::sendReminders($lookahead === null ? null : (int)$lookahead, $limit);
                self::sweepExpired($limit);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[Hublog CircleSubscription runFromPayload] ' . $e->getMessage()); // @allow-error-log
            return false;
        }
    }
}
