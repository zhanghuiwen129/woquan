<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Controllers\PaymentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 支付业务规则测试（V167）
 *
 * 会员商业模型是 V67 定死、后面所有校验都照它写的地基（PaymentController 头部注释）。
 * 这一层一旦被人悄悄改了某个数字，页面不会崩、接口不报错，只会「少收钱」或「多给权益」，
 * 所以必须把这些数字钉住。
 *
 * 全部是对纯静态方法的断言，不连数据库，默认随 `bash scripts/run-tests.sh` 一起跑。
 */
final class PaymentRuleTest extends TestCase
{
    // ==================== 会员三档地基 ====================

    public function testThreeVipTiersAreConsistentlyDefined(): void
    {
        $ref    = new ReflectionClass(PaymentController::class);
        $consts = $ref->getReflectionConstants();

        // 1/2/3 三档必须在每张表上都齐全，缺一档就会 fallback 到 0 档（白送或全禁）
        $expected = [1, 2, 3];
        foreach (['VIP_DAYS', 'VIP_NAMES', 'POST_DAILY_LIMIT', 'CIRCLE_LIMIT'] as $name) {
            $found = null;
            foreach ($consts as $c) {
                if ($c->getName() === $name) {
                    $found = $c->getValue();
                    break;
                }
            }
            $this->assertNotNull($found, "常量 {$name} 不存在");
            foreach ($expected as $tier) {
                $this->assertArrayHasKey(
                    $tier,
                    $found,
                    "{$name} 缺少第 {$tier} 档——会员模型是 1 月度 / 2 季度 / 3 年度三档"
                );
            }
        }
    }

    public function testVipNamesMatchBusinessConvention(): void
    {
        $ref = new ReflectionClass(PaymentController::class);
        foreach ($ref->getReflectionConstants() as $c) {
            if ($c->getName() === 'VIP_NAMES') {
                $names = $c->getValue();
                $this->assertSame('青铜', $names[1]);
                $this->assertSame('白银', $names[2]);
                $this->assertSame('黄金', $names[3]);
                return;
            }
        }
        $this->fail('VIP_NAMES 不存在');
    }

    public function testVipDaysFollowMonthlyQuarterlyYearly(): void
    {
        $ref = new ReflectionClass(PaymentController::class);
        foreach ($ref->getReflectionConstants() as $c) {
            if ($c->getName() === 'VIP_DAYS') {
                $days = $c->getValue();
                $this->assertSame(30, $days[1]);
                $this->assertSame(90, $days[2]);
                $this->assertSame(365, $days[3]);
                return;
            }
        }
        $this->fail('VIP_DAYS 不存在');
    }

    public function testPrivilegesForEachTier(): void
    {
        $this->assertSame(['post_daily' => 10, 'circle_create' => 1], PaymentController::privileges(0));
        $this->assertSame(['post_daily' => 100, 'circle_create' => 5], PaymentController::privileges(1));
        $this->assertSame(['post_daily' => 100, 'circle_create' => 5], PaymentController::privileges(2));
        $this->assertSame(['post_daily' => 100, 'circle_create' => 5], PaymentController::privileges(3));
    }

    public function testUnknownVipLevelFallsBackToFreeTier(): void
    {
        // 脏数据（比如老数据里的 4、或某次误写）不能让 privileges 返回未定义结构
        $this->assertSame(
            PaymentController::privileges(0),
            PaymentController::privileges(9)
        );
        $this->assertSame(
            PaymentController::privileges(0),
            PaymentController::privileges(-1)
        );
    }

    // ==================== 折扣卡 ====================

    public function testDescribeCouponAmount(): void
    {
        $this->assertSame(
            '立减 ¥10.00（限青铜/白银/黄金）',
            PaymentController::describeCoupon('amount', 10.0)
        );
        $this->assertSame(
            '立减 ¥10.00（限白银/黄金）',
            PaymentController::describeCoupon('amount', 10.0, '2,3')
        );
    }

    public function testDescribeCouponPercent(): void
    {
        // percent 存的是「减免百分比」：20 → 实付 80% → 8折
        $this->assertSame('8折（限青铜/白银/黄金）', PaymentController::describeCoupon('percent', 20.0));
        // 折数不是整数时退化成「减 X%」，避免出现 8.5 折这种奇怪写法
        $this->assertSame('减 15%（限青铜/白银/黄金）', PaymentController::describeCoupon('percent', 15.0));
    }

    public function testCouponLevelsParsing(): void
    {
        $this->assertSame([1, 2, 3], PaymentController::couponLevels(''), '空串 = 三档通用');
        $this->assertSame([1], PaymentController::couponLevels('1'));
        $this->assertSame([2, 3], PaymentController::couponLevels('2,3'));
        $this->assertSame([2, 3], PaymentController::couponLevels(' 2 , 3 '), '应容忍空格');
    }

    public function testCouponLevelsFallsBackToAllTiersOnGarbageInput(): void
    {
        // 【已知行为，非期望】后台表单理应不会写出非法档位，但一旦写入，
        // 券的适用范围会被静默放大到三档通用。这里锁定现状：
        // 若后续要收紧（非法值 → 视为无效券），改这里会立刻提醒。
        $this->assertSame(
            [1, 2, 3],
            PaymentController::couponLevels('0,4,99'),
            '非法档位目前会退化成「三档通用」'
        );
    }

    public function testCouponAppliesToMatchingTierOnly(): void
    {
        $coupon = ['vip_levels' => '2,3'];

        $this->assertTrue(PaymentController::couponApplies($coupon, 2));
        $this->assertTrue(PaymentController::couponApplies($coupon, 3));
        $this->assertFalse(PaymentController::couponApplies($coupon, 1), '青铜不能用白银/黄金券');

        // 未限定档位的券，任何档位都能用
        $this->assertTrue(PaymentController::couponApplies([], 1));
    }

    public function testCouponCutNeverExceedsPrice(): void
    {
        // 立减 10 但原价只有 5：最多减到 0，不能算出负数（否则等于倒贴）
        $this->assertSame(
            5.0,
            PaymentController::couponCut(['discount_mode' => 'amount', 'discount_value' => 10.0], 5.0)
        );
        $this->assertSame(
            10.0,
            PaymentController::couponCut(['discount_mode' => 'amount', 'discount_value' => 10.0], 20.0)
        );
    }

    public function testCouponCutPercent(): void
    {
        $this->assertSame(
            20.0,
            PaymentController::couponCut(['discount_mode' => 'percent', 'discount_value' => 20.0], 100.0)
        );
        // 减免比例被 min($value, 99.99) 封住，永远留有 0.01 元，防止 0 元订单
        $this->assertSame(
            99.99,
            PaymentController::couponCut(['discount_mode' => 'percent', 'discount_value' => 150.0], 100.0)
        );
    }

    public function testCouponCutRejectsNonPositiveInput(): void
    {
        $this->assertSame(0.0, PaymentController::couponCut(['discount_value' => 0], 100.0));
        $this->assertSame(0.0, PaymentController::couponCut(['discount_value' => 10.0], 0.0));
        $this->assertSame(0.0, PaymentController::couponCut([], 100.0), '缺失字段不能当成「减到0」');
    }
}
