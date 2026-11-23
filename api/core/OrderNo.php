<?php
/**
 * Hublog 订单号生成器（单一真源）
 *
 * 【为什么收敛到一处】
 * 改造前订单号有三处独立实现：
 *   - api/controllers/PaymentController.php::orderNo()   前缀 + YmdHis + 4 位随机
 *   - api/controllers/ShopController.php::orderNo()       同上（与上一处逐字节相同）
 *   - api/controllers/AdminFinanceController.php          'ADM' + YmdHis + 4 位随机
 * 三处格式各写各的，改一处漏两处就会出现「有的订单号带零填充、有的不带」，
 * 而订单号同时是资金幂等键的组成部分（biz_key = 'recharge:' . orderNo），
 * 格式不一致会让幂等键口径也变得不一致。
 *
 * 【唯一性口径】
 * 原实现只用 4 位随机数，同一秒内并发下单碰撞概率约 1/10000，
 * 撞号会让 uk_order_no 唯一索引直接拒绝写入 ——
 * 表现为「用户付款成功但订单创建失败」。
 * 现在改为 6 位随机数（random_int，密码学安全，非 mt_rand），
 * 同秒碰撞概率降到 1/100 万量级；配合库上的唯一索引兜底，
 * 万一仍撞号会由数据库拦下而不是静默覆盖。
 *
 * 【为什么不改格式】
 * order_no 落在 varchar(32)/(40) 字段里，历史数据已经是「前缀 + 14 位时间 + 数字」，
 * 任何格式变更都要考虑历史订单的兼容与运营侧的肉眼核对习惯。保持形态不变最稳。
 */

declare(strict_types=1);

namespace Hublog\Core;

class OrderNo
{
    /** 随机数位数：6 位（100 万量级空间） */
    private const RANDOM_DIGITS = 6;

    /**
     * 生成订单号。
     *
     * @param string $prefix 业务前缀（VIP / CIR / RC / SP / ADM …）
     * @return string 形如 RC20261116103045123456
     */
    public static function generate(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');
        if ($prefix === '') {
            $prefix = 'ORD';
        }

        $random = random_int(0, (10 ** self::RANDOM_DIGITS) - 1);
        $randomStr = str_pad((string)$random, self::RANDOM_DIGITS, '0', STR_PAD_LEFT);

        return $prefix . date('YmdHis') . $randomStr;
    }
}
