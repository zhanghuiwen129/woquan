<?php
/**
 * 行为级测试：支付回调的充值单状态机、幂等键与响应形态
 *
 * 回调入口强依赖 php://input、渠道验签/查单（网络）与支付宝的 echo+exit，
 * 无法在 PHPUnit 进程内完整驱动；因此这里真实调用回调最终执行的
 * PaymentController::markRechargePaid()（反射调用私有方法，仍是生产代码本体）
 * 与 PaymentRepository，验证 pending→paid、重复 trade_no 不重复入账、
 * 关闭/缺单/金额为 0 的拒绝路径；并真实调用 notifyWxpay() 的畸形包路径。
 * 不做任何源码文本断言。
 */

declare(strict_types=1);

namespace Hublog\Tests\Behavior;

use Hublog\Controllers\PaymentController;
use Hublog\Core\PaymentRepository;
use Hublog\Core\Request;
use Hublog\Core\Response;
use ReflectionMethod;

final class PaymentNotifyBehaviorTest extends BehaviorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec(<<<'SQL'
CREATE TABLE `recharge_orders` (
    `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
    `order_no`   TEXT    NOT NULL,
    `user_id`    INTEGER NOT NULL,
    `amount`     TEXT    NOT NULL,
    `method`     TEXT    NOT NULL,
    `status`     TEXT    NOT NULL DEFAULT 'pending',
    `trade_no`   TEXT    DEFAULT NULL,
    `paid_at`    TEXT    DEFAULT NULL,
    `created_at` TEXT    DEFAULT NULL
);
CREATE UNIQUE INDEX `uk_order_no` ON `recharge_orders` (`order_no`);
CREATE UNIQUE INDEX `uk_trade_no` ON `recharge_orders` (`trade_no`);
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
SQL);
        $this->seed('users', ['id' => 8101, 'balance' => 0]);
        $this->seed('users', ['id' => 8102, 'balance' => 0]);
    }

    /** 反射调用生产控制器的私有入账方法，保持真实状态机而非复制其逻辑 */
    private function markPaid(string $orderNo, string $tradeNo): array
    {
        $method = new ReflectionMethod(PaymentController::class, 'markRechargePaid');
        $method->setAccessible(true);
        return $method->invoke(new PaymentController(), $orderNo, $tradeNo);
    }

    private function balanceOf(int $userId): float
    {
        return (float)$this->pdo()->query(
            'SELECT balance FROM users WHERE id = ' . $userId
        )->fetchColumn();
    }

    private function seedOrder(int $id, string $orderNo, int $userId, string $status, string $amount = '50.00'): void
    {
        $this->seed('recharge_orders', [
            'id' => $id, 'order_no' => $orderNo, 'user_id' => $userId,
            'amount' => $amount, 'method' => 'wxpay', 'status' => $status,
        ]);
    }

    public function testRepeatedCallbackWithSameTradeNoCreditsExactlyOnce(): void
    {
        $this->seedOrder(1, 'RC-REPLAY', 8101, 'pending');

        $first = $this->markPaid('RC-REPLAY', 'WX-TX-1');
        $this->assertTrue($first['ok']);
        $this->assertArrayNotHasKey('duplicated', $first);
        $this->assertSame(50.0, $this->balanceOf(8101));

        $order = PaymentRepository::findRechargeOrder('RC-REPLAY');
        $this->assertSame('paid', $order['status']);
        $this->assertSame('WX-TX-1', $order['trade_no']);
        $this->assertNotNull($order['paid_at']);

        $logs = $this->rows('wallet_logs');
        $this->assertCount(1, $logs);
        $this->assertSame('recharge:RC-REPLAY', $logs[0]['biz_key']);
        $this->assertSame(50.0, (float)$logs[0]['amount']);
        $this->assertSame('recharge', $logs[0]['type']);

        $second = $this->markPaid('RC-REPLAY', 'WX-TX-1');
        $this->assertTrue($second['ok'], '重复回调是幂等成功而不是报错重发');
        $this->assertTrue($second['duplicated'], '重放必须被标记为 duplicated');
        $this->assertSame(50.0, $this->balanceOf(8101), '重复回调不得二次入账');
        $this->assertCount(1, $this->rows('wallet_logs'), '重复回调不得新增钱包流水');
        $this->assertSame('WX-TX-1', PaymentRepository::findRechargeOrder('RC-REPLAY')['trade_no'],
            '重复回调不得覆盖已记录的渠道单号');
    }

    public function testSameTradeNoOnAnotherOrderIsRejectedByUniqueIndexAndRolledBack(): void
    {
        $this->seedOrder(1, 'RC-FIRST', 8101, 'pending');
        $this->seedOrder(2, 'RC-SECOND', 8102, 'pending');

        $first = $this->markPaid('RC-FIRST', 'TX-SHARED');
        $this->assertTrue($first['ok']);

        // uk_trade_no 阻止第二个订单复用同一渠道交易号；事务必须整体回滚。
        $second = $this->markPaid('RC-SECOND', 'TX-SHARED');
        $this->assertFalse($second['ok']);
        $this->assertNotEmpty($second['reason']);
        $this->assertSame('pending', PaymentRepository::findRechargeOrder('RC-SECOND')['status'],
            '唯一键冲突后订单必须仍在 pending，不能出现半写入');
        $this->assertNull(PaymentRepository::findRechargeOrder('RC-SECOND')['trade_no']);
        $this->assertSame(0.0, $this->balanceOf(8102), '冲突订单不得入账');
        $this->assertCount(1, $this->rows('wallet_logs'), '全库只允许一条入账流水');
        $this->assertSame(50.0, $this->balanceOf(8101));
    }

    public function testClosedMissingAndZeroAmountOrdersNeverEnterLedger(): void
    {
        $this->seedOrder(1, 'RC-CLOSED', 8101, 'closed');
        $this->seedOrder(2, 'RC-ZERO', 8102, 'pending', '0.00');

        $this->assertSame(
            ['ok' => false, 'reason' => '订单已关闭'],
            $this->markPaid('RC-CLOSED', 'TX-1')
        );
        $this->assertSame(
            ['ok' => false, 'reason' => '订单不存在'],
            $this->markPaid('RC-NOT-EXIST', 'TX-2')
        );
        $this->assertSame(
            ['ok' => false, 'reason' => '金额异常'],
            $this->markPaid('RC-ZERO', 'TX-3')
        );

        $this->assertSame(0.0, $this->balanceOf(8101));
        $this->assertSame(0.0, $this->balanceOf(8102));
        $this->assertSame([], $this->rows('wallet_logs'));
        $this->assertSame('closed', PaymentRepository::findRechargeOrder('RC-CLOSED')['status']);
        $this->assertSame('pending', PaymentRepository::findRechargeOrder('RC-ZERO')['status']);
    }

    public function testWxpayNotifyReturnsJsonErrorForMalformedPayload(): void
    {
        // CLI 下 php://input 为空 → 回调在解密/查单之前就必须返回统一 JSON 错误。
        $response = (new PaymentController())->notifyWxpay(new Request());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->getCode());
        $decoded = json_decode($response->toJson(), true);
        $this->assertSame(400, $decoded['code']);
        $this->assertSame('无效通知', $decoded['message']);
        $this->assertNull($decoded['data']);
        $this->assertSame([], $this->rows('wallet_logs'), '畸形回调不得触碰账本');
    }

    public function testSuccessAndFailureResponsesHaveDistinctJsonShapes(): void
    {
        // 微信成功/失败两条路径共用 Response 出口；支付宝以裸 success/fail 文本结束请求。
        $success = json_decode(Response::success(null, 'success')->toJson(), true);
        $this->assertSame(200, $success['code']);
        $this->assertSame('success', $success['message']);
        $this->assertNull($success['data']);

        $rejected = json_decode(Response::error('订单未支付', 400)->toJson(), true);
        $this->assertSame(400, $rejected['code']);
        $this->assertSame('订单未支付', $rejected['message']);

        $ledgerFailure = json_decode(Response::error('入账失败', 500)->toJson(), true);
        $this->assertSame(500, $ledgerFailure['code']);
        $this->assertNotSame($success['code'], $ledgerFailure['code']);
        $this->assertNotSame($success['message'], $ledgerFailure['message']);
    }
}
