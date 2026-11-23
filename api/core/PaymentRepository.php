<?php
declare(strict_types=1);

/**
 * Hublog 支付 / 钱包 / 会员数据仓储
 *
 * 只承载 PaymentController 下沉的 SQL 与事务：返回原始行结构，不做权限判定、
 * 请求校验、缓存处理、支付网关编排与响应包装——这些仍留在控制器。
 * 迁移约定见 docs/内联SQL收敛规范.md。
 *
 * 约定（与原控制器逐条对齐，行为不变）：
 *  - 所有值一律通过 ? 占位符绑定，禁止字符串拼接值（含 LIMIT / INTERVAL 天数）；
 *  - 分页计数与列表共用同一套 WHERE（见 adminRechargeWhere / walletLogWhere）；
 *  - 字段清单与顺序、表别名、排序表达式逐字保留；
 *  - 写序列若原本带事务，则整段下沉并在 catch 内 rollBack 后 throw $e；
 *    原本无事务的连续写不擅自加事务；幂等键（biz_key / uk_*）逻辑保持原样。
 */

namespace Hublog\Core;

class PaymentRepository
{
    // ==================== 钱包（对应 wallet） ====================

    /** 钱包流水 WHERE：方向（amount 正负）与分类（type）均为白名单值，全部绑定 */
    private static function walletLogWhere(string $direction, string $type): array
    {
        $where = 'user_id = ?';
        $params = [];
        if ($direction === 'income') {
            $where .= ' AND amount > 0';
        } elseif ($direction === 'expense') {
            $where .= ' AND amount < 0';
        }
        if ($type !== '' && in_array($type, ['recharge', 'consume', 'adjust', 'income'], true)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        return [$where, $params];
    }

    /** 我的钱包流水列表：字段清单/排序与迁移前一致，LIMIT 绑定 */
    public static function fetchWalletLogs(int $userId, string $direction, string $type, int $limit): array
    {
        [$where, $params] = self::walletLogWhere($direction, $type);
        return Database::fetchAll(
            "SELECT id, amount, type, biz_type, biz_id, balance_after, remark, created_at
             FROM wallet_logs WHERE {$where}
             ORDER BY id DESC LIMIT ?",
            array_merge([$userId], $params, [$limit])
        );
    }

    /** 钱包全量收支统计（不受 direction/type 筛选影响），COUNT(*) 保持 */
    public static function fetchWalletSummary(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS expense,
                COUNT(*) AS cnt
             FROM wallet_logs WHERE user_id = ?",
            [$userId]
        );
    }

    /** 钱包/会员页共用的用户行（wallet / vip-info / buy-vip 回显） */
    public static function findWalletUser(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT vip_level, vip_expire_at, balance, points, avatar_frame FROM users WHERE id = ?",
            [$userId]
        ) ?: null;
    }

    // ==================== 会员（对应 vip-info / vip-orders / buy-vip） ====================

    /** 未使用且未过期的折扣卡列表（过期状态刷新仍由控制器调用 ShopController 完成） */
    public static function fetchUserCoupons(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, title, discount_mode, discount_value, vip_levels, expire_at, created_at
             FROM user_coupons
             WHERE user_id = ? AND status = 'unused'
             ORDER BY id DESC LIMIT 50",
            [$userId]
        );
    }

    /** 我的会员订购单 */
    public static function fetchVipOrders(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, level, days, amount, order_no, status, started_at, expire_at, created_at
             FROM vip_subscriptions WHERE user_id = ?
             ORDER BY id DESC LIMIT 30",
            [$userId]
        );
    }

    /** 买会员时按 id + user_id 取折扣卡（回显与校验用，保持无额外状态过滤） */
    public static function findCoupon(int $couponId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM user_coupons WHERE id = ? AND user_id = ? LIMIT 1",
            [$couponId, $userId]
        ) ?: null;
    }

    /** 折扣卡已过期：单条更新，保持原有非事务语义 */
    public static function markCouponExpired(int $couponId): void
    {
        Database::update('user_coupons', ['status' => 'expired'], 'id = ?', [$couponId]);
    }

    /**
     * 买会员完整写序列（单事务）：扣积分（带余量条件）→ 扣余额（带余额条件）
     * → 原子续费 → 事务内重读 → 积分流水 → 订阅单 → 核销折扣卡 → 钱包流水。
     * 任一步失败 rollBack 后原样抛出，由控制器转换成原有错误文案与状态码。
     *
     * @return array{balance_after: float, points_after: int, expire: int}
     */
    public static function purchaseVip(
        int $userId,
        int $level,
        int $days,
        int $usePoints,
        float $pay,
        float $discount,
        float $priceAfter,
        string $orderNo,
        int $couponId,
        ?string $couponDesc,
        float $couponCut
    ): array {
        Database::beginTransaction();
        try {
            // 先扣积分（带余额条件，防并发扣穿）
            if ($usePoints > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET points = points - ? WHERE id = ? AND points >= ?"
                );
                $stmt->execute([$usePoints, $userId, $usePoints]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('积分不足');
                }
            }

            // 余额扣减：带余额条件，防止并发下把余额扣成负数
            if ($pay > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?"
                );
                $stmt->execute([$pay, $userId, $pay]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('余额不足');
                }
            }

            // 会员等级/到期时间：单条 SQL 原子更新（V147 修复「并发续费丢失天数」）。
            // 天数由调用方硬编码白名单 VIP_DAYS 提供，这里仍走 ? 绑定。
            $stmt = Database::getInstance()->prepare(
                "UPDATE users SET vip_level = ?,
                        vip_expire_at = DATE_ADD(GREATEST(COALESCE(vip_expire_at, '1970-01-02'), NOW()), INTERVAL ? DAY)
                 WHERE id = ?"
            );
            $stmt->execute([$level, $days, $userId]);

            // 事务内重读扣后真实余额/积分/到期时间（避免用陈旧值写流水）
            $fresh = Database::fetchOne(
                "SELECT balance, points, vip_expire_at FROM users WHERE id = ? LIMIT 1",
                [$userId]
            );
            $balanceAfter = (float)($fresh['balance'] ?? 0);
            $pointsAfter = (int)($fresh['points'] ?? 0);
            $expire = !empty($fresh['vip_expire_at'])
                ? (int)strtotime((string)$fresh['vip_expire_at'])
                : (time() + $days * 86400);

            // 积分抵扣流水（balance_after 用上面重读的真实值）
            if ($usePoints > 0) {
                Database::insert('points_logs', [
                    'user_id' => $userId,
                    'points' => -$usePoints,
                    'type' => 'spend',
                    'biz_type' => 'vip',
                    'biz_id' => 0,
                    'balance_after' => $pointsAfter,
                    'remark' => "开通会员抵扣（折合 ¥{$discount}）",
                ]);
            }

            $subId = Database::insert('vip_subscriptions', [
                'user_id' => $userId,
                'level' => $level,
                'days' => $days,
                'amount' => $priceAfter,
                'order_no' => $orderNo,
                'status' => 1,
                'started_at' => Helper::now(),
                'expire_at' => date('Y-m-d H:i:s', $expire),
                'remark' => $couponDesc !== null ? ($couponDesc . "，减 ¥{$couponCut}") : null,
            ]);

            // V143：核销折扣卡（一卡一次）
            if ($couponId > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE user_coupons SET status = 'used', used_at = ?, used_order_no = ?
                     WHERE id = ? AND status = 'unused'"
                );
                $stmt->execute([Helper::now(), $orderNo, $couponId]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('折扣卡已被使用');
                }
            }

            // V92：业务幂等键 = 会员订单号，防止同一单重复扣款记账
            Database::insert('wallet_logs', [
                'user_id' => $userId,
                'amount' => -$pay,
                'type' => 'consume',
                'biz_type' => 'vip',
                'biz_id' => $subId,
                'biz_key' => 'vip:' . $orderNo,
                'balance_after' => $balanceAfter,
                'remark' => "购买会员 Lv{$level}（{$days}天）"
                    . ($couponCut > 0 ? "，折扣卡减 ¥{$couponCut}" : '')
                    . ($discount > 0 ? "，积分抵扣 ¥{$discount}" : ''),
            ]);

            Database::commit();
            return ['balance_after' => $balanceAfter, 'points_after' => $pointsAfter, 'expire' => $expire];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 付费圈子（对应 circle-pay / circle-subscribe） ====================

    /** 圈子付费信息（circle-pay-info / circle-subscribe 共用） */
    public static function findCirclePayInfo(int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT id, name, pay_type, pay_amount, pay_vip_level, pay_days, owner_id FROM circles WHERE id = ? LIMIT 1",
            [$circleId]
        ) ?: null;
    }

    /** 订阅扣款前的用户余额/积分/等级行 */
    public static function findUserBalancePoints(int $userId): ?array
    {
        return Database::fetchOne("SELECT vip_level, balance, points FROM users WHERE id = ?", [$userId]) ?: null;
    }

    /** 当前用户会员等级（circlePostAllowed 用） */
    public static function findVipLevel(int $userId): ?array
    {
        return Database::fetchOne("SELECT vip_level FROM users WHERE id = ?", [$userId]) ?: null;
    }

    /** 有效的圈子订阅行（circlePostAllowed 判定 can_post 用） */
    public static function findActiveSubscribe(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, expire_at FROM circle_subscribes
             WHERE circle_id = ? AND user_id = ? AND status = 1 LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    /**
     * 订阅付费圈子完整写序列（单事务）：扣积分 + 积分流水 → 扣余额 →
     * 查/建订阅 → 自动入圈 + member_count+1 → 钱包流水。
     * 任一步失败 rollBack 后原样抛出，由控制器转换成原有错误文案与状态码。
     *
     * @return array{expire: int}
     */
    public static function subscribeCircle(
        int $userId,
        int $circleId,
        int $usePoints,
        float $pay,
        float $price,
        int $days,
        string $orderNo,
        int $pointsLeft,
        float $balanceAfter,
        string $pointsLogRemark,
        string $walletRemark
    ): array {
        Database::beginTransaction();
        try {
            // 先扣积分（带余量条件，防并发扣穿）
            if ($usePoints > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET points = points - ? WHERE id = ? AND points >= ?"
                );
                $stmt->execute([$usePoints, $userId, $usePoints]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('积分不足');
                }
            }

            if ($pay > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?"
                );
                $stmt->execute([$pay, $userId, $pay]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('余额不足');
                }
            }

            // 事务内重读扣后真实余额/积分（对齐 purchaseVip 范本）。
            // 调用方传入的 $pointsLeft/$balanceAfter 来自事务外快照，并发下会写错流水，
            // 这里统一覆盖为库内真实值后再落两条流水。
            $fresh = Database::fetchOne(
                "SELECT balance, points FROM users WHERE id = ? LIMIT 1",
                [$userId]
            );
            $balanceAfter = round((float)($fresh['balance'] ?? 0), 2);
            $pointsLeft = (int)($fresh['points'] ?? 0);

            if ($usePoints > 0) {
                Database::insert('points_logs', [
                    'user_id' => $userId,
                    'points' => -$usePoints,
                    'type' => 'spend',
                    'biz_type' => 'circle',
                    'biz_id' => $circleId,
                    'balance_after' => $pointsLeft,
                    'remark' => $pointsLogRemark,
                ]);
            }

            $old = Database::fetchOne(
                "SELECT id, expire_at FROM circle_subscribes WHERE circle_id = ? AND user_id = ? LIMIT 1",
                [$circleId, $userId]
            );
            $base = ($old && $old['expire_at'] && strtotime($old['expire_at']) > time())
                ? strtotime($old['expire_at'])
                : time();
            $expire = $base + $days * 86400;

            if ($old) {
                Database::update('circle_subscribes', [
                    'expire_at' => date('Y-m-d H:i:s', $expire),
                    'amount' => $price,
                    'status' => 1,
                    // 新周期重新计时提醒：不重置会在续费后几小时就收到「到期」通知
                    'notified_before_at' => null,
                    'notified_expire_at' => null,
                ], 'id = ?', [$old['id']]);
            } else {
                Database::insert('circle_subscribes', [
                    'circle_id' => $circleId,
                    'user_id' => $userId,
                    'order_no' => $orderNo,
                    'amount' => $price,
                    'status' => 1,
                    'expire_at' => date('Y-m-d H:i:s', $expire),
                ]);
            }

            // 订阅成功就等于加入圈子 —— 否则用户付了钱还不是成员
            $already = Database::fetchOne(
                "SELECT id FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1",
                [$circleId, $userId]
            );
            if (!$already) {
                Database::insert('circle_members', [
                    'circle_id' => $circleId,
                    'user_id' => $userId,
                    'role' => 'member',
                    'status' => 1,
                ]);
                Database::getInstance()->prepare(
                    "UPDATE circles SET member_count = member_count + 1 WHERE id = ?"
                )->execute([$circleId]);
            }

            // V92：业务幂等键 = 圈子订阅单号（用户 × 圈子 × 订阅段）
            Database::insert('wallet_logs', [
                'user_id' => $userId,
                'amount' => -$pay,
                'type' => 'consume',
                'biz_type' => 'circle',
                'biz_id' => $circleId,
                'biz_key' => 'circle:' . $userId . ':' . $circleId . ':' . $orderNo,
                'balance_after' => $balanceAfter,
                'remark' => $walletRemark,
            ]);

            Database::commit();
            return ['expire' => $expire];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 工具 / 权益（对应 syncVip / pointRate / setting） ====================

    /** 到期会员降级检查行；$expireAt 传 null 表示重置为未开通 */
    public static function findVipExpiry(int $userId): ?array
    {
        return Database::fetchOne("SELECT vip_level, vip_expire_at FROM users WHERE id = ?", [$userId]) ?: null;
    }

    /** 更新会员等级/到期时间：null 到期时间即降级重置，字段顺序与原 Database::update 一致 */
    public static function updateUserVip(int $userId, int $level, ?string $expireAt): void
    {
        Database::update('users', [
            'vip_level' => $level,
            'vip_expire_at' => $expireAt,
        ], 'id = ?', [$userId]);
    }

    /** 读取 settings 原始值（无行时 fetchColumn 返回 false，控制器据此回退默认值） */
    public static function fetchSettingValue(string $key): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    // ==================== 会员发放（对应 grantVipOnly，非事务） ====================

    /** 仅发放会员：插入订阅单（扣款由调用方负责，与支付逻辑解耦） */
    public static function insertVipSubscription(array $data): int
    {
        return Database::insert('vip_subscriptions', $data);
    }

    /** 取用户名（发放成功后清个人主页缓存用） */
    public static function findUsername(int $userId): ?array
    {
        return Database::fetchOne("SELECT username FROM users WHERE id = ?", [$userId]) ?: null;
    }

    // ==================== 在线充值（对应 recharge-create / recharge-orders） ====================

    /** 创建充值单，返回新订单 ID（trade_no 为 null 表达「未拿到渠道交易号」） */
    public static function insertRechargeOrder(array $data): int
    {
        return Database::insert('recharge_orders', $data);
    }

    /** 渠道下单失败：关闭充值单，避免留 pending 脏数据 */
    public static function closeRechargeOrder(int $orderId): void
    {
        Database::update('recharge_orders', ['status' => 'closed'], 'id = ?', [$orderId]);
    }

    /** 我的充值记录：ORDER BY id DESC，LIMIT 绑定 */
    public static function fetchMyRechargeOrders(int $userId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT order_no, amount, method, status, trade_no, paid_at, created_at
             FROM recharge_orders WHERE user_id = ? ORDER BY id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    /** 后台充值订单 WHERE：状态/方法均为白名单，计数与列表共用 */
    private static function adminRechargeWhere(string $status, string $method): array
    {
        $where = '';
        $params = [];
        if (in_array($status, ['pending', 'paid', 'closed'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        if (in_array($method, ['wxpay', 'alipay'], true)) {
            $where .= ' AND r.method = ?';
            $params[] = $method;
        }
        return [$where, $params];
    }

    /** 后台充值订单计数：COUNT(*) 与列表同 WHERE */
    public static function countAdminRechargeOrders(string $status, string $method): int
    {
        [$where, $params] = self::adminRechargeWhere($status, $method);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM recharge_orders r WHERE 1=1 {$where}",
            $params
        );
    }

    /** 后台充值订单列表：字段/别名/JOIN/排序逐字保留，LIMIT ?, ? = offset, limit */
    public static function fetchAdminRechargeOrders(
        string $status,
        string $method,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = self::adminRechargeWhere($status, $method);
        return Database::fetchAll(
            "SELECT r.order_no, r.amount, r.method, r.status, r.trade_no, r.paid_at, r.created_at,
                    u.nickname AS user_name, u.username AS user_username
             FROM recharge_orders r LEFT JOIN users u ON u.id = r.user_id
             WHERE 1=1 {$where} ORDER BY r.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** 按订单号取充值单（入账幂等前置校验） */
    public static function findRechargeOrder(string $orderNo): ?array
    {
        return Database::fetchOne("SELECT * FROM recharge_orders WHERE order_no = ? LIMIT 1", [$orderNo]) ?: null;
    }

    /** 抢占订单状态后回查最新状态（并发/重放判定 duplicated 用） */
    public static function findRechargeOrderStatus(string $orderNo): ?array
    {
        return Database::fetchOne("SELECT status FROM recharge_orders WHERE order_no = ? LIMIT 1", [$orderNo]) ?: null;
    }

    /**
     * 充值入账完整写序列（单事务）：原子抢占 status='pending' → 加余额 → 钱包流水。
     * 幂等键 biz_key=recharge:<orderNo> + uk_wallet_biz_key 保持不变。
     * 抢占失败（rowCount=0）时已 rollBack 并返回 null，由控制器回查最新状态决定
     * duplicated / 状态已变更；其他异常 rollBack 后原样抛出。
     *
     * @return array{ after: float }|null
     */
    public static function claimRechargeAndCredit(
        string $orderNo,
        string $tradeNo,
        int $userId,
        float $amount,
        string $bizType,
        int $bizId,
        string $walletRemark
    ): ?array {
        Database::beginTransaction();
        try {
            // V147：先原子抢占订单状态（WHERE 带 status='pending' 条件）
            $claim = Database::getInstance()->prepare(
                "UPDATE recharge_orders SET status = 'paid', trade_no = ?, paid_at = ?
                 WHERE order_no = ? AND status = 'pending'"
            );
            $claim->execute([$tradeNo, Helper::now(), $orderNo]);
            if ($claim->rowCount() === 0) {
                Database::rollBack();
                return null;
            }

            $stmt = Database::getInstance()->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException('用户不存在');
            }
            $after = (float)Database::fetchColumn("SELECT balance FROM users WHERE id = ? LIMIT 1", [$userId]);

            Database::insert('wallet_logs', [
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'recharge',
                'biz_type' => $bizType,
                'biz_id' => $bizId,
                'biz_key' => 'recharge:' . $orderNo,
                'balance_after' => number_format($after, 2, '.', ''),
                'remark' => $walletRemark,
            ]);

            Database::commit();
            return ['after' => $after];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
