<?php
/**
 * Hublog VIP 到期批量降级服务（V102）
 *
 * 【定位】读时兜底 PaymentController::syncVip() 保留不动——它是未配 cron 站点的最后一道闸。
 * 本服务是新增的定时批量降级：让落库 vip_level 及时准确、并清掉头像框/个人页的陈旧缓存。
 * 两者天然幂等兼容：cron 先降级后，syncVip() 读到 vip_level=0 直接 no-op。
 *
 * 【口径】与 syncVip() 严格一致：vip_level>0 且 vip_expire_at<=NOW() 即降级。
 * 用 <= 不用 <，避免临界点「cron 认为有效 / 读时认为过期」抖动。
 *
 * 【副作用隔离】缓存与徽章回收失败不影响降级主流程（DB 已写好），逐项 try/catch。
 */

declare(strict_types=1);

namespace Hublog\Core;

class VipExpiryService
{
    /**
     * 扫描并降级过期会员。
     *
     * @param \PDO $pdo
     * @param int $limit 本轮最多处理行数（分页，避免大站锁整表）
     * @param callable|null $afterDowngrade 每个降级用户后的回调 fn(int $userId, string $username): void
     *        生产环境传 null（内部走真实缓存/徽章清理）；单测传入以断言副作用而不依赖 Cache/BadgeService。
     * @return array{scanned:int,downgraded:int,failed:int}
     */
    public static function sweepExpired(\PDO $pdo, int $limit = 200, ?callable $afterDowngrade = null): array
    {
        $limit = max(1, min(1000, $limit));
        $result = ['scanned' => 0, 'downgraded' => 0, 'failed' => 0];

        // 一次查出待降级名单 + username（供缓存清理用），避免逐行 N+1
        $rows = $pdo->query(
            "SELECT id, username, vip_level FROM users
             WHERE vip_level > 0 AND vip_expire_at IS NOT NULL AND vip_expire_at <= NOW()
             LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $result['scanned'] = count($rows);

        $stmt = $pdo->prepare(
            "UPDATE users SET vip_level = 0, vip_expire_at = NULL
             WHERE id = ? AND vip_level > 0 AND vip_expire_at IS NOT NULL AND vip_expire_at <= NOW()"
        );

        foreach ($rows as $row) {
            $uid = (int)$row['id'];
            $username = (string)($row['username'] ?? '');
            $oldLevel = (int)($row['vip_level'] ?? 0);
            try {
                $stmt->execute([$uid]);
                if ($stmt->rowCount() < 1) {
                    // 并发下已被 syncVip/另一轮 cron 降级，跳过副作用
                    continue;
                }
                $result['downgraded']++;

                // V103：降级审计（operator=0 系统/cron），与是否注入回调无关
                MemberChangeAudit::record(
                    $uid, MemberChangeAudit::TYPE_VIP_EXPIRE,
                    $oldLevel, 0, 0, '', 'VIP 到期自动降级', '', 'vip', ''
                );

                if ($afterDowngrade !== null) {
                    $afterDowngrade($uid, $username);
                } else {
                    self::clearSideEffects($uid, $username);
                }
            } catch (\Throwable $e) {
                // DB 降级失败或副作用失败都不阻断其余用户
                $result['failed']++;
                error_log('[Hublog VipExpiry] uid=' . $uid . ' ' . $e->getMessage()); // @allow-error-log
            }
        }

        return $result;
    }

    /**
     * 降级后清理副作用：认证缓存、个人页缓存、会员徽章回收。
     * 与 PaymentController::grantVipOnly() / syncVip() 的清理口径一致。
     */
    private static function clearSideEffects(int $uid, string $username): void
    {
        try {
            Auth::clearUserCache($uid);
        } catch (\Throwable $e) {
            error_log('[Hublog VipExpiry] clearUserCache uid=' . $uid . ' ' . $e->getMessage()); // @allow-error-log
        }
        if ($username !== '') {
            try {
                Cache::delete("user_profile_{$username}");
            } catch (\Throwable $e) {
                error_log('[Hublog VipExpiry] clear profile uid=' . $uid . ' ' . $e->getMessage()); // @allow-error-log
            }
        }
        try {
            BadgeService::onEvent($uid, 'vip_changed');
        } catch (\Throwable $e) {
            error_log('[Hublog VipExpiry] badge uid=' . $uid . ' ' . $e->getMessage()); // @allow-error-log
        }
    }
}
