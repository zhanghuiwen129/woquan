<?php
/**
 * 行为级测试：资金 / 权益类幂等唯一键（V92）
 *
 * 报告《Hublog 源码分析报告 · 最终版》4.3.7 指出：
 * 支付回调与钱包写入原本只靠应用层「先查状态再更新」做幂等，
 * 并发 / 崩溃重试 / 攻击者重放回调体都可能造成重复入账、重复发券。
 * 应用层是「尽力而为」，DB 唯一约束才是「最后一道闸」。
 *
 * 本测试不读源码字符串，而是真的建出带唯一约束的表、真的往里写，
 * 验证「同一个业务键写第二次会被数据库拒绝」。
 *
 * 覆盖：
 *  1. wallet_logs.biz_key 唯一 —— 同一充值单重复记账被拒，不同单正常
 *  2. recharge_orders.trade_no 唯一 —— 同一渠道流水号不能对应两张单
 *  3. user_coupons (order_id, slot) 唯一 —— 订单重放重复发券被拒，
 *     但同订单多张券（slot 不同）必须能正常发出去
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use PDO;

final class IdempotencyKeyBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 复用底座建出的表名风格，但按 MySQL 迁移后的终态结构重建这三张表，
        // 带上 V92 新增的唯一约束。SQLite 同样支持 UNIQUE 索引，
        // 因此「重复写入被 DB 拒绝」这条行为在两边语义一致。
        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE IF EXISTS `wallet_logs`');
        $pdo->exec('DROP TABLE IF EXISTS `recharge_orders`');
        $pdo->exec('DROP TABLE IF EXISTS `user_coupons`');

        $pdo->exec(<<<'SQL'
        CREATE TABLE `wallet_logs` (
            `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
            `user_id`      INTEGER NOT NULL,
            `amount`       REAL    NOT NULL DEFAULT 0,
            `type`         TEXT    NOT NULL DEFAULT 'consume',
            `biz_type`     TEXT    NOT NULL DEFAULT 'system',
            `biz_id`       INTEGER NOT NULL DEFAULT 0,
            `biz_key`      TEXT    DEFAULT NULL,
            `balance_after` REAL   NOT NULL DEFAULT 0,
            `remark`       TEXT    DEFAULT NULL,
            UNIQUE (`biz_key`)
        );
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE `recharge_orders` (
            `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
            `order_no`  TEXT    NOT NULL,
            `user_id`   INTEGER NOT NULL DEFAULT 0,
            `amount`    REAL    NOT NULL DEFAULT 0,
            `method`    TEXT    NOT NULL DEFAULT '',
            `status`    TEXT    NOT NULL DEFAULT 'pending',
            `trade_no`  TEXT    DEFAULT NULL,
            `paid_at`   TEXT    DEFAULT NULL,
            UNIQUE (`trade_no`)
        );
        SQL);

        $pdo->exec(<<<'SQL'
        CREATE TABLE `user_coupons` (
            `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
            `user_id`       INTEGER NOT NULL,
            `product_id`    INTEGER NOT NULL DEFAULT 0,
            `order_id`      INTEGER NOT NULL DEFAULT 0,
            `slot`          INTEGER NOT NULL DEFAULT 0,
            `title`         TEXT    NOT NULL DEFAULT '',
            `status`        TEXT    NOT NULL DEFAULT 'unused',
            UNIQUE (`order_id`, `slot`)
        );
        SQL);
    }

    // ── wallet_logs.biz_key ──────────────────────────────────────────────

    public function testSameBizKeyIsRejectedByDatabase(): void
    {
        $row = [
            'user_id'       => 7,
            'amount'        => 100.0,
            'type'          => 'recharge',
            'biz_type'      => 'wxpay',
            'biz_id'        => 1,
            'biz_key'       => 'recharge:RC20261112001',
            'balance_after' => 100.0,
            'remark'        => '在线充值',
        ];
        $this->seed('wallet_logs', $row);
        $this->assertCount(1, $this->rows('wallet_logs'));

        // 同一业务键再写一次（模拟回调重放）→ DB 必须拒绝
        $failed = false;
        try {
            $this->seed('wallet_logs', $row);
        } catch (\PDOException $e) {
            $failed = true;
        }
        $this->assertTrue($failed, '重复 biz_key 必须被唯一索引拒绝，否则会重复入账');
        $this->assertCount(1, $this->rows('wallet_logs'), '重复写入后表里仍应只有一条流水');
    }

    public function testNullBizKeyAllowsMultipleRows(): void
    {
        // biz_key = NULL 表示「本条流水天然可重复」（如后台手动调账），
        // MySQL 唯一索引允许多个 NULL，不能因为加了索引就误伤这些写入。
        $this->seed('wallet_logs', ['user_id' => 1, 'amount' => 1.0, 'biz_key' => null]);
        $this->seed('wallet_logs', ['user_id' => 1, 'amount' => 2.0, 'biz_key' => null]);
        $this->assertCount(2, $this->rows('wallet_logs'));
    }

    public function testDifferentBizKeysCoexist(): void
    {
        $this->seed('wallet_logs', ['user_id' => 1, 'amount' => 1.0, 'biz_key' => 'recharge:RC001']);
        $this->seed('wallet_logs', ['user_id' => 1, 'amount' => 2.0, 'biz_key' => 'recharge:RC002']);
        $this->seed('wallet_logs', ['user_id' => 1, 'amount' => 3.0, 'biz_key' => 'vip:VIP001']);
        $this->assertCount(3, $this->rows('wallet_logs'));
    }

    // ── recharge_orders.trade_no ──────────────────────────────────────────

    public function testSameTradeNoCannotBeUsedTwice(): void
    {
        $this->seed('recharge_orders', [
            'order_no' => 'RC001', 'user_id' => 1, 'amount' => 10.0,
            'method' => 'wxpay', 'status' => 'paid', 'trade_no' => 'WX123456',
        ]);

        // 同一渠道交易号落到另一张本站单上 → 必须被拒（伪造回调的核心防线）
        $failed = false;
        try {
            $this->seed('recharge_orders', [
                'order_no' => 'RC002', 'user_id' => 2, 'amount' => 10.0,
                'method' => 'wxpay', 'status' => 'paid', 'trade_no' => 'WX123456',
            ]);
        } catch (\PDOException $e) {
            $failed = true;
        }
        $this->assertTrue($failed, '同一 trade_no 不能对应两张充值单');
        $this->assertCount(1, $this->rows('recharge_orders'));
    }

    public function testMultiplePendingOrdersWithNullTradeNoCoexist(): void
    {
        // 待支付单的 trade_no 是 NULL：MySQL 唯一索引允许多个 NULL，
        // 所以「先建单再去支付」不会因为唯一键而建不出第二张单。
        $this->seed('recharge_orders', [
            'order_no' => 'RC001', 'user_id' => 1, 'status' => 'pending', 'trade_no' => null,
        ]);
        $this->seed('recharge_orders', [
            'order_no' => 'RC002', 'user_id' => 1, 'status' => 'pending', 'trade_no' => null,
        ]);
        $this->assertCount(2, $this->rows('recharge_orders'));
    }

    // ── user_coupons (order_id, slot) ─────────────────────────────────────

    public function testOrderReplayCannotGrantSameCouponTwice(): void
    {
        $this->seed('user_coupons', [
            'user_id' => 3, 'product_id' => 9, 'order_id' => 100, 'slot' => 0, 'title' => '折扣卡',
        ]);

        $failed = false;
        try {
            $this->seed('user_coupons', [
                'user_id' => 3, 'product_id' => 9, 'order_id' => 100, 'slot' => 0, 'title' => '折扣卡',
            ]);
        } catch (\PDOException $e) {
            $failed = true;
        }
        $this->assertTrue($failed, '订单重放不能重复发同一张券');
        $this->assertCount(1, $this->rows('user_coupons'));
    }

    public function testMultipleCouponsInOneOrderAreAllowed(): void
    {
        // 关键回归：一次买多张券（qty=3）会发 3 张 slot=0/1/2 的券，
        // 如果唯一键错做成 (user_id, order_id)，这里就会直接失败。
        for ($slot = 0; $slot < 3; $slot++) {
            $this->seed('user_coupons', [
                'user_id' => 3, 'product_id' => 9, 'order_id' => 100, 'slot' => $slot, 'title' => '折扣卡',
            ]);
        }
        $this->assertCount(3, $this->rows('user_coupons'));
    }

    public function testSameSlotInDifferentOrdersIsAllowed(): void
    {
        $this->seed('user_coupons', [
            'user_id' => 3, 'product_id' => 9, 'order_id' => 100, 'slot' => 0, 'title' => '折扣卡',
        ]);
        $this->seed('user_coupons', [
            'user_id' => 3, 'product_id' => 9, 'order_id' => 101, 'slot' => 0, 'title' => '折扣卡',
        ]);
        $this->assertCount(2, $this->rows('user_coupons'));
    }
}
