<?php
/**
 * Hublog 余额 / 积分流水对账器（V102）
 *
 * 【为什么需要】
 * users.balance / users.points 是冗余列，与 wallet_logs / points_logs 双写。
 * 双写一旦有一侧失败（非事务 adjusting、并发下陈旧快照、进程中断），
 * 冗余列就会与流水累计漂移，且不会自愈。CounterReconciler 只覆盖 4 个计数列，
 * 明确把 balance / points 排除在外。本类补上这块缺口。
 *
 * 【设计红线】
 *   - 只读：本类绝不自动改 users 表。资金数据自动覆盖会把真实错误「洗平」，
 *     掩盖双写 bug。只输出漂移明细，由运维核对流水后人工决定怎么处理。
 *   - DB 无关：纯 SQL + PHP，MySQL / SQLite 都能跑，便于单测。
 *   - 期初基准可配：历史存量没有「期初流水」，首轮对账需传入 baseline，
 *     否则会把存量误报成漂移。baseline 为 {user_id: 期初值} 映射。
 *
 * 【口径】
 *   期望余额 = 期初余额 + SUM(wallet_logs.amount)
 *   期望积分 = 期初积分 + SUM(points_logs.points)
 *   漂移 = 存储值 - 期望值（非 0 即异常）
 *
 * 【用法】
 *   php bin/reconcile-wallet.php
 *   php bin/reconcile-wallet.php --baseline baseline.json
 */

declare(strict_types=1);

namespace Hublog\Core;

class WalletReconciler
{
    /**
     * 对账余额与积分。
     *
     * @param \PDO $pdo 数据库连接
     * @param array{balance_baseline?:array<int,float>,points_baseline?:array<int,int>,only_user?:int} $options
     *        balance_baseline / points_baseline：期初映射，缺省视为 0。
     *        only_user：只查指定用户（核查单账号用）。
     * @return array{
     *   checked:int, drift_count:int,
     *   balance_drift:array<int,array>, points_drift:array<int,array>,
     *   skipped:array<int,array>
     * }
     */
    public static function reconcileAll(\PDO $pdo, array $options = []): array
    {
        $balanceBaseline = $options['balance_baseline'] ?? [];
        $pointsBaseline = $options['points_baseline'] ?? [];
        $onlyUser = isset($options['only_user']) ? (int)$options['only_user'] : 0;
        $where = $onlyUser > 0 ? ' WHERE u.id = ' . $onlyUser : '';
        $skipped = [];
        $balanceDrift = [];
        $pointsDrift = [];

        // ---- 余额：users.balance 对比「期初 + 流水累计」----
        try {
            $balanceRows = $pdo->query(
                "SELECT u.id, u.balance AS stored_val, COALESCE(SUM(w.amount),0) AS ledger_sum
                 FROM users u LEFT JOIN wallet_logs w ON w.user_id = u.id
                 {$where} GROUP BY u.id, u.balance"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($balanceRows as $r) {
                $uid = (int)$r['id'];
                $expected = (float)($balanceBaseline[$uid] ?? 0) + (float)$r['ledger_sum'];
                $stored = (float)$r['stored_val'];
                $drift = round($stored - $expected, 2);
                if (abs($drift) >= 0.01) {
                    $balanceDrift[] = [
                        'user_id' => $uid,
                        'field' => 'balance',
                        'stored' => $stored,
                        'ledger' => (float)$r['ledger_sum'],
                        'expected' => $expected,
                        'drift' => $drift,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $skipped[] = ['target' => 'users.balance / wallet_logs', 'reason' => $e->getMessage()];
        }

        // ---- 积分：users.points 对比「期初 + 流水累计」----
        try {
            $pointsRows = $pdo->query(
                "SELECT u.id, u.points AS stored_val, COALESCE(SUM(p.points),0) AS ledger_sum
                 FROM users u LEFT JOIN points_logs p ON p.user_id = u.id
                 {$where} GROUP BY u.id, u.points"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($pointsRows as $r) {
                $uid = (int)$r['id'];
                $expected = (int)($pointsBaseline[$uid] ?? 0) + (int)$r['ledger_sum'];
                $stored = (int)$r['stored_val'];
                $drift = $stored - $expected;
                if ($drift !== 0) {
                    $pointsDrift[] = [
                        'user_id' => $uid,
                        'field' => 'points',
                        'stored' => $stored,
                        'ledger' => (int)$r['ledger_sum'],
                        'expected' => $expected,
                        'drift' => $drift,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $skipped[] = ['target' => 'users.points / points_logs', 'reason' => $e->getMessage()];
        }

        return [
            'checked' => isset($balanceRows) ? count($balanceRows) : (isset($pointsRows) ? count($pointsRows) : 0),
            'drift_count' => count($balanceDrift) + count($pointsDrift),
            'balance_drift' => $balanceDrift,
            'points_drift' => $pointsDrift,
            'skipped' => $skipped,
        ];
    }
}
