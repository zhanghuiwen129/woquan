<?php
/**
 * PaymentRepository 迁移回归测试
 *
 * 核心断言方式：在内存 SQLite 里同时执行「迁移前的原始内联 SQL」与
 * 「迁移后的 Repository 方法」，逐字段比对返回结构与写库副作用，确保
 * 下沉数据访问不改字段顺序、排序、分页、可见性、软删条件与幂等键。
 *
 * 写方法重点覆盖事务：成功路径验证提交后的数据，异常路径验证 rollBack
 * 后数据零残留（订单状态回退、余额不变、无流水/订阅/成员行）。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Core\PaymentRepository;
use Hublog\Core\CircleSubscriptionService;
use PDO;

final class PaymentRepositoryBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `wallet_logs` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `amount`        REAL    NOT NULL,
    `type`          TEXT    NOT NULL,
    `biz_type`      TEXT    DEFAULT NULL,
    `biz_id`        INTEGER DEFAULT NULL,
    `biz_key`       TEXT    DEFAULT NULL,
    `balance_after` REAL    DEFAULT NULL,
    `remark`        TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL
);
CREATE UNIQUE INDEX `uk_wallet_biz_key` ON `wallet_logs` (`biz_key`);
CREATE TABLE `vip_subscriptions` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`    INTEGER NOT NULL,
    `level`      INTEGER NOT NULL,
    `days`       INTEGER NOT NULL,
    `amount`     REAL    NOT NULL DEFAULT 0,
    `order_no`   TEXT    DEFAULT NULL,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `started_at` TEXT    DEFAULT NULL,
    `expire_at`  TEXT    DEFAULT NULL,
    `remark`     TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `user_coupons` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `title`         TEXT    DEFAULT NULL,
    `discount_mode` TEXT    DEFAULT 'percent',
    `discount_value` REAL   NOT NULL DEFAULT 0,
    `vip_levels`    TEXT    DEFAULT NULL,
    `status`        TEXT    DEFAULT 'unused',
    `expire_at`     TEXT    DEFAULT NULL,
    `used_at`       TEXT    DEFAULT NULL,
    `used_order_no` TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL
);
CREATE TABLE `recharge_orders` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `order_no`   TEXT    NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `amount`     TEXT    NOT NULL,
    `method`     TEXT    NOT NULL,
    `status`     TEXT    DEFAULT 'pending',
    `trade_no`   TEXT    DEFAULT NULL,
    `paid_at`    TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `circle_subscribes` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id`  INTEGER NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `order_no`   TEXT    DEFAULT NULL,
    `amount`     REAL    NOT NULL DEFAULT 0,
    `status`     INTEGER NOT NULL DEFAULT 1,
    `expire_at`  TEXT    DEFAULT NULL,
    `notified_before_at` TEXT DEFAULT NULL,
    `notified_expire_at` TEXT DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE TABLE `circles` (
    `id`           INTEGER PRIMARY KEY AUTOINCREMENT,
    `name`         TEXT    DEFAULT NULL,
    `pay_type`     INTEGER NOT NULL DEFAULT 0,
    `pay_amount`   REAL    NOT NULL DEFAULT 0,
    `pay_vip_level` INTEGER NOT NULL DEFAULT 0,
    `pay_days`     INTEGER NOT NULL DEFAULT 0,
    `owner_id`     INTEGER NOT NULL DEFAULT 0,
    `member_count` INTEGER NOT NULL DEFAULT 0,
    `status`       INTEGER NOT NULL DEFAULT 1,
    `created_at`   TEXT    DEFAULT NULL
);
CREATE TABLE `circle_members` (
    `id`        INTEGER PRIMARY KEY AUTOINCREMENT,
    `circle_id` INTEGER NOT NULL,
    `user_id`   INTEGER NOT NULL,
    `role`      TEXT    DEFAULT 'member',
    `status`    INTEGER NOT NULL DEFAULT 1,
    `joined_at` TEXT    DEFAULT NULL
);
CREATE TABLE `points_logs` (
    `id`            INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id`       INTEGER NOT NULL,
    `points`        INTEGER NOT NULL,
    `type`          TEXT    DEFAULT NULL,
    `biz_type`      TEXT    DEFAULT NULL,
    `biz_id`        INTEGER DEFAULT NULL,
    `balance_after` INTEGER DEFAULT NULL,
    `remark`        TEXT    DEFAULT NULL,
    `created_at`    TEXT    DEFAULT NULL
);
SQL);
    }

    // ===== 读查询：与迁移前逐字等价（空 / 单行 / 多页 / 负例） =====

    public function testWalletReadQueriesMatchOriginalSql(): void
    {
        $this->seed('users', ['id' => 1, 'username' => 'alice', 'vip_level' => 2, 'vip_expire_at' => '2027-01-01 00:00:00', 'balance' => 123.45, 'points' => 88, 'avatar_frame' => 'gold']);
        $at = '2026-01-01 00:00:00';
        foreach ([
            [1, 100.0, 'recharge', 100.0, 'recharge:RC1'],
            [2, -30.0, 'consume', 70.0, 'vip:VIP1'],
            [3, -20.0, 'consume', 50.0, 'circle:CIR1'],
            [4, 10.0, 'adjust', 60.0, null],
        ] as [$id, $amount, $type, $after, $bizKey]) {
            $this->seed('wallet_logs', [
                'id' => $id, 'user_id' => 1, 'amount' => $amount, 'type' => $type,
                'biz_type' => $type, 'biz_id' => $id, 'biz_key' => $bizKey,
                'balance_after' => $after, 'remark' => "log{$id}", 'created_at' => $at,
            ]);
        }
        $this->seed('wallet_logs', [
            'id' => 5, 'user_id' => 2, 'amount' => -5.0, 'type' => 'consume',
            'biz_type' => 'vip', 'biz_id' => 0, 'biz_key' => 'vip:OTHER',
            'balance_after' => 95.0, 'remark' => 'other', 'created_at' => $at,
        ]);

        foreach ([
            ['all', '', 20], ['income', '', 20], ['expense', '', 20],
            ['all', 'consume', 20], ['all', 'not-a-type', 2], ['expense', 'consume', 2],
        ] as [$direction, $type, $limit]) {
            $this->assertSame(
                $this->originalWalletLogs(1, $direction, $type, $limit),
                PaymentRepository::fetchWalletLogs(1, $direction, $type, $limit),
                '钱包流水字段/方向/分类/分页必须与迁移前一致'
            );
        }
        $this->assertSame([], PaymentRepository::fetchWalletLogs(999, 'all', '', 20), '空用户无流水');
        $this->assertSame([3, 2], array_column(PaymentRepository::fetchWalletLogs(1, 'expense', '', 20), 'id'), 'ORDER BY id DESC');

        $this->assertSame($this->originalWalletSummary(1), PaymentRepository::fetchWalletSummary(1));
        $this->assertSame(4, PaymentRepository::fetchWalletSummary(1)['cnt']);
        $this->assertSame($this->originalWalletSummary(999), PaymentRepository::fetchWalletSummary(999), '空用户的全量统计仍返回聚合零行');

        $this->assertSame($this->originalWalletRow(1), PaymentRepository::findWalletUser(1));
        $this->assertNull(PaymentRepository::findWalletUser(999));
    }

    public function testAdminRechargeOrdersCountAndListShareSameWhere(): void
    {
        foreach ([10, 11] as $uid) {
            $this->seed('users', ['id' => $uid, 'username' => "u{$uid}", 'nickname' => "n{$uid}"]);
        }
        $orders = [
            [1, 'RC-1', 10, '10.00', 'wxpay', 'pending'],
            [2, 'RC-2', 10, '20.00', 'alipay', 'paid'],
            [3, 'RC-3', 11, '30.00', 'wxpay', 'paid'],
            [4, 'RC-4', 11, '40.00', 'alipay', 'closed'],
        ];
        foreach ($orders as [$id, $no, $uid, $amount, $method, $status]) {
            $this->seed('recharge_orders', [
                'id' => $id, 'order_no' => $no, 'user_id' => $uid, 'amount' => $amount,
                'method' => $method, 'status' => $status, 'trade_no' => null,
                'paid_at' => null, 'created_at' => '2026-01-01 00:00:00',
            ]);
        }

        foreach ([
            ['', '', 20, 0], ['paid', '', 20, 0], ['', 'wxpay', 20, 0],
            ['paid', 'wxpay', 20, 0], ['bogus', '', 20, 0], ['pending', '', 1, 1],
        ] as [$status, $method, $limit, $page]) {
            $offset = (int)(($page - 1) * $limit);
            $this->assertSame(
                $this->originalAdminRechargeList($status, $method, $limit, $offset),
                PaymentRepository::fetchAdminRechargeOrders($status, $method, $limit, $offset),
                '后台列表字段/JOIN/别名/排序/分页必须与迁移前一致'
            );
            $this->assertSame(
                $this->originalAdminRechargeCount($status, $method),
                PaymentRepository::countAdminRechargeOrders($status, $method),
                '计数与列表必须用同一套 WHERE'
            );
        }
        $this->assertSame(4, PaymentRepository::countAdminRechargeOrders('bogus', ''), '非法状态不过滤');
        $this->assertSame([], PaymentRepository::fetchAdminRechargeOrders('closed', 'wxpay', 20, 0), '负例无匹配');
        $this->assertSame(
            ['order_no', 'amount', 'method', 'status', 'trade_no', 'paid_at', 'created_at', 'user_name', 'user_username'],
            array_keys(PaymentRepository::fetchAdminRechargeOrders('', '', 1, 0)[0]),
            '字段顺序不得变化'
        );
        $this->assertSame([], PaymentRepository::fetchMyRechargeOrders(999, 20));
        $this->assertSame(
            ['RC-2', 'RC-1'],
            array_column(PaymentRepository::fetchMyRechargeOrders(10, 20), 'order_no'),
            '我的充值记录 ORDER BY id DESC'
        );
    }

    public function testMiscReadQueriesAndNonTransactionalWrites(): void
    {
        $this->pdo()->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('point_rate', '8')");
        $this->assertSame('8', PaymentRepository::fetchSettingValue('point_rate'));
        $this->assertFalse(PaymentRepository::fetchSettingValue('not-exist'), 'fetchColumn 无行返回 false');

        $this->seed('users', ['id' => 1, 'username' => 'alice', 'vip_level' => 1, 'vip_expire_at' => null, 'balance' => 50, 'points' => 100]);
        $this->assertSame('alice', PaymentRepository::findUsername(1)['username']);
        $this->assertNull(PaymentRepository::findUsername(999));
        $this->assertSame($this->originalVipExpiry(1), PaymentRepository::findVipExpiry(1));
        $this->assertSame($this->originalVipLevel(1), PaymentRepository::findVipLevel(1));
        $this->assertSame($this->originalUserBalancePoints(1), PaymentRepository::findUserBalancePoints(1));

        PaymentRepository::updateUserVip(1, 2, '2027-02-03 04:05:06');
        $this->assertSame(
            ['vip_level' => 2, 'vip_expire_at' => '2027-02-03 04:05:06'],
            $this->pdo()->query("SELECT vip_level, vip_expire_at FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC)
        );
        PaymentRepository::updateUserVip(1, 0, null);
        $this->assertNull($this->pdo()->query("SELECT vip_expire_at FROM users WHERE id = 1")->fetchColumn());

        $this->seed('user_coupons', ['id' => 1, 'user_id' => 1, 'title' => '8折', 'discount_mode' => 'percent', 'discount_value' => 20, 'vip_levels' => '1,2', 'status' => 'unused', 'expire_at' => '2027-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        $this->seed('user_coupons', ['id' => 2, 'user_id' => 1, 'title' => '立减', 'discount_mode' => 'amount', 'discount_value' => 10, 'vip_levels' => '', 'status' => 'unused', 'expire_at' => null, 'created_at' => '2026-01-02 00:00:00']);
        $this->seed('user_coupons', ['id' => 3, 'user_id' => 2, 'title' => '他人券', 'discount_mode' => 'amount', 'discount_value' => 5, 'vip_levels' => '', 'status' => 'unused', 'created_at' => '2026-01-03 00:00:00']);
        $this->assertSame($this->originalUserCoupons(1), PaymentRepository::fetchUserCoupons(1));
        $this->assertSame($this->originalCoupon(1, 1), PaymentRepository::findCoupon(1, 1));
        $this->assertNull(PaymentRepository::findCoupon(3, 1), '他人折扣卡不可见');
        PaymentRepository::markCouponExpired(2);
        $this->assertSame('expired', PaymentRepository::findCoupon(2, 1)['status']);

        $this->seed('vip_subscriptions', ['id' => 1, 'user_id' => 1, 'level' => 1, 'days' => 30, 'amount' => 20, 'order_no' => 'VIP-1', 'status' => 1, 'started_at' => '2026-01-01 00:00:00', 'expire_at' => '2026-01-31 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        $this->assertSame($this->originalVipOrders(1), PaymentRepository::fetchVipOrders(1));
        $this->assertSame([], PaymentRepository::fetchVipOrders(999));

        $this->seed('circles', ['id' => 1, 'name' => '付费圈', 'pay_type' => 2, 'pay_amount' => 20, 'pay_vip_level' => 0, 'member_count' => 1, 'status' => 1]);
        $this->assertSame($this->originalCirclePayInfo(1), PaymentRepository::findCirclePayInfo(1));
        $this->assertNull(PaymentRepository::findCirclePayInfo(999));

        $this->seed('circle_subscribes', ['id' => 1, 'circle_id' => 1, 'user_id' => 1, 'order_no' => 'CIR-1', 'amount' => 20, 'status' => 1, 'expire_at' => '2027-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        $this->assertSame($this->originalActiveSubscribe(1, 1), PaymentRepository::findActiveSubscribe(1, 1));
        $this->assertNull(PaymentRepository::findActiveSubscribe(1, 999));

        $newId = PaymentRepository::insertVipSubscription([
            'user_id' => 1, 'level' => 2, 'days' => 90, 'amount' => 50,
            'order_no' => 'VIP-2', 'status' => 1,
            'started_at' => '2026-02-01 00:00:00', 'expire_at' => '2026-05-01 00:00:00',
        ]);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame(2, PaymentRepository::fetchVipOrders(1)[0]['level'], '新订阅排在最前');
        $this->assertArrayNotHasKey('remark', PaymentRepository::fetchVipOrders(1)[0], 'grantVipOnly 不写 remark');
    }

    // ===== 事务写方法：成功路径 =====

    public function testClaimRechargeAndCreditSuccessMatchesOriginalSql(): void
    {
        // 场景 A 走 Repository，场景 B 走迁移前原始 SQL，逐字段对照
        $this->seed('users', ['id' => 1, 'balance' => 100, 'vip_level' => 0, 'points' => 0]);
        $this->seed('recharge_orders', ['id' => 1, 'order_no' => 'RC-A', 'user_id' => 1, 'amount' => '50.00', 'method' => 'wxpay', 'status' => 'pending', 'trade_no' => null]);
        $this->seed('users', ['id' => 2, 'balance' => 100, 'vip_level' => 0, 'points' => 0]);
        $this->seed('recharge_orders', ['id' => 2, 'order_no' => 'RC-B', 'user_id' => 2, 'amount' => '50.00', 'method' => 'wxpay', 'status' => 'pending', 'trade_no' => null]);

        $actual = PaymentRepository::claimRechargeAndCredit('RC-A', 'TX-A', 1, 50.0, 'wxpay', 1, '在线充值（微信支付）');
        $expected = $this->originalClaimRecharge('RC-B', 'TX-B', 2, 50.0, 'wxpay', 2, '在线充值（微信支付）');
        $this->assertSame($expected, $actual, '入账结果必须与迁移前一致');

        $cols = 'amount, type, biz_type, biz_id, biz_key, balance_after, remark';
        $actualLog = $this->pdo()->query("SELECT {$cols} FROM wallet_logs WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);
        $expectedLog = $this->pdo()->query("SELECT {$cols} FROM wallet_logs WHERE user_id = 2")->fetch(PDO::FETCH_ASSOC);
        $expectedLog['biz_id'] = 1;
        $expectedLog['biz_key'] = 'recharge:RC-A';
        $this->assertSame($expectedLog, $actualLog, '充值流水字段/幂等键/余额必须与迁移前逐字一致');
        $this->assertSame(150.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 1")->fetchColumn(), '余额已加');
        $this->assertSame('paid', PaymentRepository::findRechargeOrder('RC-A')['status']);
        $this->assertSame('TX-A', PaymentRepository::findRechargeOrder('RC-A')['trade_no']);
        $this->assertNotNull(PaymentRepository::findRechargeOrder('RC-A')['paid_at']);
        $this->assertSame(2, PaymentRepository::countAdminRechargeOrders('paid', ''), '两场景订单均已入账');
    }

    public function testSubscribeCircleSuccessMatchesOriginalSql(): void
    {
        // 首次订阅
        $this->seed('users', ['id' => 10, 'balance' => 100, 'points' => 0]);
        $this->seed('circles', ['id' => 20, 'name' => '测试圈', 'pay_type' => 2, 'pay_amount' => 20, 'member_count' => 5, 'status' => 1]);
        $before = time();
        $result = PaymentRepository::subscribeCircle(10, 20, 0, 20.0, 20.0, 365, 'CIR-S', 0, 80.0, '', '订阅圈子《测试圈》');
        $this->assertGreaterThanOrEqual($before + 365 * 86400, $result['expire']);
        $this->assertLessThanOrEqual(time() + 365 * 86400, $result['expire']);
        $this->assertSame(80.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 10")->fetchColumn(), '余额已扣');
        $this->assertSame(6, (int)$this->pdo()->query("SELECT member_count FROM circles WHERE id = 20")->fetchColumn(), 'member_count+1');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM circle_subscribes WHERE circle_id = 20 AND user_id = 10")->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM circle_members WHERE circle_id = 20 AND user_id = 10")->fetchColumn(), '订阅即入圈');
        $this->assertSame(
            ['amount' => -20.0, 'biz_type' => 'circle', 'biz_id' => 20, 'biz_key' => 'circle:10:20:CIR-S', 'balance_after' => 80.0, 'remark' => '订阅圈子《测试圈》'],
            $this->pdo()->query("SELECT amount, biz_type, biz_id, biz_key, balance_after, remark FROM wallet_logs WHERE user_id = 10")->fetch(PDO::FETCH_ASSOC),
            '圈子消费流水字段/幂等键必须与迁移前一致'
        );

        // 续费：已有未过期订阅 + 已是成员，不重复建成员/不加 member_count
        $future = date('Y-m-d H:i:s', time() + 10 * 86400);
        $this->seed('users', ['id' => 11, 'balance' => 100, 'points' => 0]);
        $this->seed('circles', ['id' => 21, 'name' => '续费圈', 'pay_type' => 2, 'member_count' => 5, 'status' => 1]);
        $this->seed('circle_subscribes', ['circle_id' => 21, 'user_id' => 11, 'order_no' => 'CIR-OLD', 'amount' => 20, 'status' => 1, 'expire_at' => $future, 'created_at' => '2026-01-01 00:00:00']);
        $this->seed('circle_members', ['circle_id' => 21, 'user_id' => 11, 'role' => 'member', 'status' => 1]);
        $renew = PaymentRepository::subscribeCircle(11, 21, 0, 20.0, 20.0, 365, 'CIR-R', 0, 80.0, '', '订阅圈子《续费圈》');
        $this->assertEqualsWithDelta(strtotime($future) + 365 * 86400, $renew['expire'], 2, '续费在旧到期时间上顺延');
        $this->assertSame(5, (int)$this->pdo()->query("SELECT member_count FROM circles WHERE id = 21")->fetchColumn(), '续费不重复加成员数');
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM circle_members WHERE circle_id = 21 AND user_id = 11")->fetchColumn());
        $this->assertSame(1, (int)$this->pdo()->query("SELECT COUNT(*) FROM circle_subscribes WHERE circle_id = 21 AND user_id = 11")->fetchColumn(), '续费更新原订阅行');
    }

    /** 批六-1 护栏：流水的 balance_after 必须取事务内重读值，不能用调用方陈旧快照 */
    public function testSubscribeCircleLedgerUsesRereadBalancesNotCallerSnapshot(): void
    {
        $this->seed('users', ['id' => 14, 'balance' => 100, 'points' => 50]);
        $this->seed('circles', [
            'id' => 24, 'name' => '快照圈', 'pay_type' => 2, 'pay_amount' => 30,
            'member_count' => 0, 'status' => 1,
        ]);

        // 故意传入与真实余额/积分完全不符的事务外快照
        PaymentRepository::subscribeCircle(
            14, 24, 20, 30.0, 30.0, 30, 'CIR-STALE',
            9999, 12345.67, '积分抵扣', '订阅圈子《快照圈》'
        );

        $pointLog = $this->pdo()->query(
            'SELECT points, balance_after FROM points_logs WHERE user_id = 14'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(-20, (int)$pointLog['points']);
        $this->assertSame(30, (int)$pointLog['balance_after'], '积分流水余额必须是重读的 50-20=30');

        $walletLog = $this->pdo()->query(
            'SELECT balance_after FROM wallet_logs WHERE user_id = 14'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(70.0, (float)$walletLog['balance_after'], '钱包流水余额必须是重读的 100-30=70');
    }

    // ===== V96：订阅天数解析（单圈 > 全局 > 365，上限 3650）=====

    public function testEffectivePayDaysPrefersCircleThenSettingThenDefault(): void
    {
        // 全局未配置（fetchSettingValue 返回 false）→ 365 天兜底，不能算成 0
        $this->seed('circles', ['id' => 1, 'name' => '无配置圈', 'pay_type' => 2, 'pay_days' => 0]);
        $this->assertSame(365, CircleSubscriptionService::effectivePayDays(1));

        // 全局配置 30 / 90 天
        $this->pdo()->exec("INSERT INTO settings (`key`, `value`) VALUES ('circle_subscribe_days', '30')");
        $this->assertSame(30, CircleSubscriptionService::effectivePayDays(1));
        $this->pdo()->exec("UPDATE settings SET `value` = '90' WHERE `key` = 'circle_subscribe_days'");
        $this->assertSame(90, CircleSubscriptionService::effectivePayDays(1));

        // 单圈覆盖全局；30 / 90 / 365 三档 + 上限截断
        foreach ([30, 90, 365, 99999] as $days) {
            $this->pdo()->exec("UPDATE circles SET pay_days = {$days} WHERE id = 1");
            $this->assertSame(min($days, 3650), CircleSubscriptionService::effectivePayDays(1));
        }

        // 全局非法值也兜底
        $this->pdo()->exec("UPDATE circles SET pay_days = 0 WHERE id = 1");
        $this->pdo()->exec("UPDATE settings SET `value` = '0' WHERE `key` = 'circle_subscribe_days'");
        $this->assertSame(365, CircleSubscriptionService::effectivePayDays(1));

        // 圈子不存在时回退全局（已非法 → 默认）
        $this->assertSame(365, CircleSubscriptionService::effectivePayDays(999));
    }

    public function testRenewResetsReminderMarkers(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3 * 86400);
        $this->seed('users', ['id' => 31, 'balance' => 100, 'points' => 0]);
        $this->seed('circles', ['id' => 31, 'name' => '提醒圈', 'pay_type' => 2, 'member_count' => 1, 'status' => 1]);
        $this->seed('circle_subscribes', [
            'circle_id' => 31, 'user_id' => 31, 'order_no' => 'CIR-OLD', 'amount' => 20,
            'status' => 1, 'expire_at' => $future,
            'notified_before_at' => '2026-01-01 00:00:00',
            'notified_expire_at' => '2026-01-08 00:00:00',
        ]);

        PaymentRepository::subscribeCircle(31, 31, 0, 20.0, 20.0, 30, 'CIR-NEW', 0, 80.0, '', '续费');

        $row = $this->pdo()->query("SELECT notified_before_at, notified_expire_at, status FROM circle_subscribes WHERE circle_id = 31 AND user_id = 31")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['notified_before_at'], '续费重置到期前提醒标记');
        $this->assertNull($row['notified_expire_at'], '续费重置到期提醒标记');
        $this->assertSame(1, (int)$row['status']);
    }

    // ===== 事务写方法：异常路径验证 rollBack 生效、数据无残留 =====

    public function testClaimRechargeRollsBackOnUserMissing(): void
    {
        // 订单指向不存在的用户：抢占成功后加余额 rowCount=0 抛异常，整笔必须回滚
        $this->seed('recharge_orders', ['id' => 1, 'order_no' => 'RC-FAIL', 'user_id' => 999, 'amount' => '50.00', 'method' => 'alipay', 'status' => 'pending', 'trade_no' => null]);
        try {
            PaymentRepository::claimRechargeAndCredit('RC-FAIL', 'TX-FAIL', 999, 50.0, 'alipay', 1, '在线充值（支付宝）');
            $this->fail('应抛出用户不存在异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('用户不存在', $e->getMessage());
        }
        $this->assertSame('pending', PaymentRepository::findRechargeOrder('RC-FAIL')['status'], 'rollBack 后订单状态回退为 pending');
        $this->assertNull(PaymentRepository::findRechargeOrder('RC-FAIL')['trade_no'], 'rollBack 后渠道单号未写入');
        $this->assertSame([], $this->rows('wallet_logs'), 'rollBack 后无入账流水残留');
    }

    public function testClaimRechargeOnClaimFailureReturnsNullWithoutResidue(): void
    {
        $this->seed('users', ['id' => 1, 'balance' => 100]);
        $this->seed('recharge_orders', ['id' => 1, 'order_no' => 'RC-C', 'user_id' => 1, 'amount' => '50.00', 'method' => 'wxpay', 'status' => 'paid', 'trade_no' => 'OLD']);
        $before = $this->rows('wallet_logs');
        $this->assertNull(PaymentRepository::claimRechargeAndCredit('RC-C', 'TX-C', 1, 50.0, 'wxpay', 1, '在线充值（微信支付）'), '非 pending 订单抢占失败');
        $this->assertSame($before, $this->rows('wallet_logs'), '抢占失败不写流水');
        $this->assertSame(100.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 1")->fetchColumn(), '余额不变');
        $this->assertSame('OLD', PaymentRepository::findRechargeOrder('RC-C')['trade_no'], '渠道单号未被覆盖');
        $this->assertSame('paid', PaymentRepository::findRechargeOrderStatus('RC-C')['status'], '回查最新状态仍为 paid（duplicated 分支）');
    }

    public function testSubscribeCircleRollsBackOnInsufficientFunds(): void
    {
        $this->seed('users', ['id' => 12, 'balance' => 5, 'points' => 0]);
        $this->seed('circles', ['id' => 22, 'name' => '钱不够圈', 'pay_type' => 2, 'member_count' => 0, 'status' => 1]);
        try {
            PaymentRepository::subscribeCircle(12, 22, 0, 20.0, 20.0, 365, 'CIR-F', 0, -15.0, '', '订阅圈子《钱不够圈》');
            $this->fail('应抛出余额不足异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('余额不足', $e->getMessage());
        }
        $this->assertSame(5.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 12")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query("SELECT member_count FROM circles WHERE id = 22")->fetchColumn());
        $this->assertSame([], $this->rows('circle_subscribes'), '无订阅残留');
        $this->assertSame([], $this->rows('circle_members'), '无成员残留');
        $this->assertSame([], $this->rows('wallet_logs'), '无流水残留');

        // 积分不足：扣积分失败同样整笔回滚
        $this->seed('users', ['id' => 13, 'balance' => 100, 'points' => 0]);
        $this->seed('circles', ['id' => 23, 'name' => '分不够圈', 'pay_type' => 2, 'member_count' => 0, 'status' => 1]);
        try {
            PaymentRepository::subscribeCircle(13, 23, 10, 20.0, 20.0, 365, 'CIR-P', 0, 80.0, '订阅圈子抵扣（折合 ¥1）', '订阅圈子《分不够圈》');
            $this->fail('应抛出积分不足异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('积分不足', $e->getMessage());
        }
        $this->assertSame(100.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 13")->fetchColumn());
        $this->assertSame([], $this->rows('points_logs'), '无积分流水残留');
        $this->assertSame([], $this->rows('wallet_logs'));
    }

    public function testPurchaseVipRollsBackOnInsufficientFunds(): void
    {
        // 余额不足：扣积分/扣余额阶段即抛异常，整笔回滚，不触及 DATE_ADD 语句
        $this->seed('users', ['id' => 30, 'balance' => 5, 'points' => 100, 'vip_level' => 0]);
        try {
            PaymentRepository::purchaseVip(30, 1, 30, 0, 20.0, 0, 20.0, 'VIP-F', 0, null, 0.0);
            $this->fail('应抛出余额不足异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('余额不足', $e->getMessage());
        }
        $this->assertSame(5.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 30")->fetchColumn());
        $this->assertSame(100, (int)$this->pdo()->query("SELECT points FROM users WHERE id = 30")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo()->query("SELECT vip_level FROM users WHERE id = 30")->fetchColumn());
        $this->assertSame([], $this->rows('vip_subscriptions'), '无订阅残留');
        $this->assertSame([], $this->rows('wallet_logs'), '无流水残留');
        $this->assertSame([], $this->rows('points_logs'));

        // 积分不足：同样整笔回滚
        $this->seed('users', ['id' => 31, 'balance' => 100, 'points' => 0, 'vip_level' => 0]);
        try {
            PaymentRepository::purchaseVip(31, 1, 30, 10, 20.0, 0, 20.0, 'VIP-P', 0, null, 0.0);
            $this->fail('应抛出积分不足异常');
        } catch (\RuntimeException $e) {
            $this->assertSame('积分不足', $e->getMessage());
        }
        $this->assertSame(100.0, (float)$this->pdo()->query("SELECT balance FROM users WHERE id = 31")->fetchColumn());
        $this->assertSame([], $this->rows('vip_subscriptions'));
        $this->assertSame([], $this->rows('wallet_logs'));
    }

    // ===== 迁移前原始 SQL 快照（用于逐字段对照） =====

    private function originalWalletLogs(int $userId, string $direction, string $type, int $limit): array
    {
        $where = 'user_id = ?';
        $params = [$userId];
        if ($direction === 'income') {
            $where .= ' AND amount > 0';
        } elseif ($direction === 'expense') {
            $where .= ' AND amount < 0';
        }
        if ($type !== '' && in_array($type, ['recharge', 'consume', 'adjust', 'income'], true)) {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        $stmt = $this->pdo()->prepare(
            "SELECT id, amount, type, biz_type, biz_id, balance_after, remark, created_at
             FROM wallet_logs WHERE {$where}
             ORDER BY id DESC LIMIT ?"
        );
        $stmt->execute(array_merge($params, [$limit]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalWalletSummary(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS expense,
                COUNT(*) AS cnt
             FROM wallet_logs WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function originalWalletRow(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT vip_level, vip_expire_at, balance, points, avatar_frame FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function originalAdminRechargeWhere(string $status, string $method): array
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

    private function originalAdminRechargeCount(string $status, string $method): int
    {
        [$where, $params] = $this->originalAdminRechargeWhere($status, $method);
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM recharge_orders r WHERE 1=1 {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function originalAdminRechargeList(string $status, string $method, int $limit, int $offset): array
    {
        [$where, $params] = $this->originalAdminRechargeWhere($status, $method);
        $stmt = $this->pdo()->prepare(
            "SELECT r.order_no, r.amount, r.method, r.status, r.trade_no, r.paid_at, r.created_at,
                    u.nickname AS user_name, u.username AS user_username
             FROM recharge_orders r LEFT JOIN users u ON u.id = r.user_id
             WHERE 1=1 {$where} ORDER BY r.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalVipExpiry(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT vip_level, vip_expire_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalVipLevel(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT vip_level FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalUserBalancePoints(int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT vip_level, balance, points FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalUserCoupons(int $userId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, title, discount_mode, discount_value, vip_levels, expire_at, created_at
             FROM user_coupons
             WHERE user_id = ? AND status = 'unused'
             ORDER BY id DESC LIMIT 50"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCoupon(int $couponId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT * FROM user_coupons WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$couponId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalVipOrders(int $userId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, level, days, amount, order_no, status, started_at, expire_at, created_at
             FROM vip_subscriptions WHERE user_id = ?
             ORDER BY id DESC LIMIT 30"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function originalCirclePayInfo(int $circleId): ?array
    {
        $stmt = $this->pdo()->prepare("SELECT id, name, pay_type, pay_amount, pay_vip_level, pay_days, owner_id FROM circles WHERE id = ? LIMIT 1");
        $stmt->execute([$circleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function originalActiveSubscribe(int $circleId, int $userId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id, expire_at FROM circle_subscribes
             WHERE circle_id = ? AND user_id = ? AND status = 1 LIMIT 1"
        );
        $stmt->execute([$circleId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** 迁移前 markRechargePaid 的完整事务 SQL 序列（用于成功路径对照） */
    private function originalClaimRecharge(
        string $orderNo,
        string $tradeNo,
        int $userId,
        float $amount,
        string $bizType,
        int $bizId,
        string $walletRemark
    ): array {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare(
                "UPDATE recharge_orders SET status = 'paid', trade_no = ?, paid_at = ?
                 WHERE order_no = ? AND status = 'pending'"
            );
            $claim->execute([$tradeNo, date('Y-m-d H:i:s'), $orderNo]);
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);
            $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? LIMIT 1");
            $balStmt->execute([$userId]);
            $after = (float)$balStmt->fetchColumn();
            $ins = $pdo->prepare(
                "INSERT INTO wallet_logs (user_id, amount, type, biz_type, biz_id, biz_key, balance_after, remark)
                 VALUES (?, ?, 'recharge', ?, ?, ?, ?, ?)"
            );
            $ins->execute([$userId, $amount, $bizType, $bizId, 'recharge:' . $orderNo, number_format($after, 2, '.', ''), $walletRemark]);
            $pdo->commit();
            return ['after' => $after];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
