<?php
/**
 * Hublog 后台财务 / 会员域数据仓储
 *
 * 承载 AdminFinanceController 下沉的全部 SQL 与事务边界：
 *   - wallet_logs 流水分页与筛选（后台财务口径）；
 *   - 余额调整（条件原子更新 + 事务内流水，V147 口径逐字保留）；
 *   - vip_subscriptions 订购单分页与后台人工开通事务。
 *
 * 【口径隔离】
 *  - walletLogs 列表与计数共用私有 walletLogsWhere()，WHERE 逐字一致，避免翻页错位；
 *  - adjustWalletBalance() 的条件更新 `balance + ? >= 0`、rowCount()==0 后的两次
 *    探测、错误文案「用户不存在」「扣款后余额不能为负」及 rollBack 后 throw
 *    全部留在仓储内部，控制器只把异常翻译成原有 500 响应；
 *  - PointRepository::creditBalanceAndLog() 是前台抽奖发奖的余额流水（type=reward），
 *    与后台充值/扣款（type=recharge|adjust）口径不同，二者刻意不合并。
 *
 * 所有值一律走 ? 绑定；字段清单、顺序、别名、JOIN、ORDER BY、LIMIT ?, ? 与迁移前
 * HEAD 原文逐字一致。控制器保留权限、参数校验、单号生成、徽章事件与响应包装。
 */

declare(strict_types=1);

namespace Hublog\Core;

class FinanceRepository
{
    /**
     * walletLogs() —— 列表与计数共用的 WHERE 助手。
     *
     * 原控制器用 `if ($type !== '')` / `if ($keyword !== '')` 判断，口径原样保留；
     * LIKE 的前后 % 属于绑定值的一部分，不拼进 SQL 文本。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function walletLogsWhere(string $type, string $keyword): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        if ($type !== '') {
            $where .= ' AND wl.type = ?';
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= ' AND (u.username LIKE ? OR u.nickname LIKE ? OR wl.remark LIKE ?)';
            $like = "%{$keyword}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        return [$where, $params];
    }

    /**
     * walletLogs() —— 钱包流水列表。
     *
     * 字段清单/顺序、LEFT JOIN、ORDER BY wl.id DESC、LIMIT ?, ?（offset, limit）
     * 与 HEAD 原文一致；LEFT JOIN users 保留无用户行。
     */
    public static function fetchWalletLogs(string $type, string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::walletLogsWhere($type, $keyword);
        return Database::fetchAll(
            "SELECT wl.id, wl.user_id, wl.amount, wl.type, wl.biz_type, wl.biz_id,
                    wl.balance_after, wl.remark, wl.created_at,
                    u.username, u.nickname, u.vip_level
             FROM wallet_logs wl
             LEFT JOIN users u ON wl.user_id = u.id
             {$where}
             ORDER BY wl.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** walletLogs() —— 与列表共用 WHERE 的分页计数（COUNT(*) 保持） */
    public static function countWalletLogs(string $type, string $keyword): int
    {
        [$where, $params] = self::walletLogsWhere($type, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM wallet_logs wl LEFT JOIN users u ON wl.user_id = u.id {$where}",
            $params
        );
    }

    /** walletAdjust() —— 调整前用户行（id, username, balance，无 LIMIT） */
    public static function findWalletAdjustUser(int $userId): ?array
    {
        return Database::fetchOne("SELECT id, username, balance FROM users WHERE id = ?", [$userId]);
    }

    /**
     * walletAdjust() —— 后台余额调整的原子更新 + 流水 + 完整事务。
     *
     * V147：条件更新 `balance + ? >= 0` 保证并发下不会扣成负数；rowCount()==0 时
     * 仍按 HEAD 顺序重查余额区分「用户不存在」与「扣款后余额不能为负」。
     * 事务内重读真实余额写流水与响应，避免陈旧计算值。异常 rollBack 后 throw，
     * 由控制器保持原有错误文案与 500 状态码。
     *
     * @return float 变动后的真实余额（round 到 2 位，与 HEAD 一致）
     */
    public static function adjustWalletBalance(int $userId, float $amount, string $remark): float
    {
        Database::beginTransaction();
        try {
            $stmt = Database::query(
                "UPDATE users SET balance = balance + ? WHERE id = ? AND balance + ? >= 0",
                [$amount, $userId, $amount]
            );
            if ($stmt->rowCount() === 0) {
                $exists = Database::fetchColumn("SELECT balance FROM users WHERE id = ? LIMIT 1", [$userId]);
                if ($exists === false) {
                    throw new \RuntimeException('用户不存在');
                }
                throw new \RuntimeException('扣款后余额不能为负');
            }
            $after = round((float)Database::fetchColumn("SELECT balance FROM users WHERE id = ? LIMIT 1", [$userId]), 2);

            Database::insert('wallet_logs', [
                'user_id' => $userId,
                'amount' => $amount,
                'type' => $amount > 0 ? 'recharge' : 'adjust',
                'biz_type' => 'admin',
                'balance_after' => $after,
                'remark' => $remark,
            ]);
            Database::commit();
            return $after;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** vipOrders() —— 订购单列表（LEFT JOIN users，字段/顺序/排序/分页逐字保留） */
    public static function fetchVipOrders(int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT vs.id, vs.user_id, vs.level, vs.days, vs.amount, vs.order_no, vs.status,
                    vs.started_at, vs.expire_at, vs.created_at,
                    u.username, u.nickname
             FROM vip_subscriptions vs
             LEFT JOIN users u ON vs.user_id = u.id
             ORDER BY vs.id DESC
             LIMIT ?, ?",
            [$offset, $limit]
        );
    }

    /** vipOrders() —— 订购单总数（无 WHERE，COUNT(*) 保持） */
    public static function countVipOrders(): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM vip_subscriptions");
    }

    /** grantVip() —— 开通前用户 VIP 行（无 LIMIT，时间判断留在控制器） */
    public static function findVipGrantUser(int $userId): ?array
    {
        return Database::fetchOne("SELECT id, vip_level, vip_expire_at FROM users WHERE id = ?", [$userId]);
    }

    /**
     * grantVip() —— 后台人工开通会员：更新用户 + 写订购单 + 完整事务。
     *
     * $expireAt / $startedAt / $orderNo 由控制器按原顺序生成后传入（单号与时间戳
     * 计算属于业务编排）；本方法只负责 SQL、参数绑定与事务边界。
     * 异常 rollBack 后 throw，控制器仍输出「开通失败」500。
     */
    public static function grantVip(
        int $userId,
        int $level,
        int $days,
        string $orderNo,
        string $startedAt,
        string $expireAt
    ): void {
        Database::beginTransaction();
        try {
            Database::update('users', [
                'vip_level' => $level,
                'vip_expire_at' => $expireAt,
            ], 'id = ?', [$userId]);
            Database::insert('vip_subscriptions', [
                'user_id' => $userId,
                'level' => $level,
                'days' => $days,
                'amount' => 0,
                'order_no' => $orderNo,
                'status' => 1,
                'started_at' => $startedAt,
                'expire_at' => $expireAt,
                'remark' => '后台人工开通',
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
