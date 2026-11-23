<?php
declare(strict_types=1);

/**
 * Hublog 支付 / 钱包 / 会员控制器
 *
 * 商业模型（V67 定死，后面所有校验都照它写）：
 *   等级     0 普通 / 1 月度 / 2 季度 / 3 年度，到期自动降级回 0
 *   钱包     独立流水 wallet_logs，users.balance 是冗余余额（双写，避免每次聚合）
 *   付费圈子 circles.pay_type：0 免费 / 1 会员等级门槛 / 2 付费订阅
 *   充值     不开放前端自助支付 —— 没有支付网关，开了就是提现漏洞。
 *            充值一律走后台手动入账（AdminController::walletAdjust），
 *            App 只做消费侧：买会员 / 订阅付费圈子 / 看流水。
 *
 * 路由分布（全部需登录）：
 *   wallet / vipInfo / vipOrders / buyVip / circlePayInfo / circleSubscribe
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Logger;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\Helper;
use Hublog\Core\Cache;
use Hublog\Core\PaymentGateway;
use Hublog\Core\BadgeService;
use Hublog\Core\OrderNo;
use Hublog\Core\PaymentRepository;
use Hublog\Core\UserRepository;
use Hublog\Core\CircleSubscriptionService;
use Hublog\Controllers\ShopController; // V143：折扣卡过期状态刷新（与 ShopController 互相引用，同命名空间下安全）

class PaymentController extends BaseController
{
    /** 会员等级 → 默认天数。价格走 settings（vip_price_1/2/3），后台可改。 */
    private const VIP_DAYS = [1 => 30, 2 => 90, 3 => 365];

    /** 会员等级 → 名称（报错提示用） */
    private const VIP_NAMES = [1 => '青铜', 2 => '白银', 3 => '黄金'];

    /** 每日发帖上限：下标是会员等级 */
    private const POST_DAILY_LIMIT = [0 => 10, 1 => 100, 2 => 100, 3 => 100];

    /** 可创建圈子上限：下标是会员等级 */
    private const CIRCLE_LIMIT = [0 => 1, 1 => 5, 2 => 5, 3 => 5];

    /** V68：单笔订单最多能用积分抵扣的比例（纯积分白嫖会打死付费圈子） */
    private const POINT_MAX_RATIO = 0.5;

    /** 最小实付（元）：折扣卡 + 积分叠加后也必须有实付，杜绝 0 元开会员 */
    private const MIN_PAY = 0.01;

    // ==================== 钱包 ====================

    /**
     * GET /api/pay/wallet —— 我的钱包（余额 + 最近流水 + 收支统计）
     *
     * V97：加上微信钱包式的「收支统计 + 分类筛选」。
     *   direction = all|income|expense   只看收入 / 只看支出
     *   type      = recharge|consume|adjust|income   按分类筛
     * summary 是**全量**统计（不受 direction/type 影响），保证切筛选时顶上的
     * 累计收入/支出不会跟着变，跟微信钱包里"本月收入/支出"始终是全量一个道理。
     */
    public function wallet(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        self::syncVip($userId);

        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }

        $direction = strtolower(trim($request->getString('direction', 'all')));
        if (!in_array($direction, ['all', 'income', 'expense'], true)) {
            $direction = 'all';
        }
        $type = trim($request->getString('type', ''));

        $logs = PaymentRepository::fetchWalletLogs($userId, $direction, $type, $limit);

        // 全量收支统计（不受上面筛选影响）
        $sum = PaymentRepository::fetchWalletSummary($userId);

        $row = PaymentRepository::findWalletUser($userId);

        return Response::success([
            'balance' => number_format((float)($row['balance'] ?? 0), 2, '.', ''),
            // V68：积分是站内主要流通货币，钱包页必须能直接看到
            'points' => (int)($row['points'] ?? 0),
            'point_rate' => (int)self::pointRate(),
            'vip_level' => (int)($row['vip_level'] ?? 0),
            'vip_expire_at' => $row['vip_expire_at'] ?? null,
            'avatar_frame' => $row['avatar_frame'] ?? 'none',
            'logs' => $logs,
            'summary' => [
                'income' => number_format((float)($sum['income'] ?? 0), 2, '.', ''),
                'expense' => number_format((float)($sum['expense'] ?? 0), 2, '.', ''),
                'count' => (int)($sum['cnt'] ?? 0),
            ],
            'filter' => ['direction' => $direction, 'type' => $type],
        ], '获取成功');
    }

    // ==================== 会员 ====================

    /**
     * GET /api/pay/vip-info —— 我的会员状态 + 在售档位
     */
    public function vipInfo(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        self::syncVip($userId);

        $row = PaymentRepository::findWalletUser($userId);
        $granted = self::privileges((int)($row['vip_level'] ?? 0));

        $plans = [];
        foreach (array_keys(self::VIP_DAYS) as $level) {
            $price = (float)$this->setting("vip_price_{$level}", [1 => 20, 2 => 50, 3 => 180][$level]);
            $plans[] = [
                'level' => $level,
                'days' => self::VIP_DAYS[$level],
                'price' => $price,
                'current' => (int)($row['vip_level'] ?? 0) === $level,
            ];
        }

        // V143：卡包 —— 买会员时能勾选的折扣卡（未使用且未过期）
        // 过期的券在这里顺手刷一次状态，避免 App 上还显示能用、一点就报错。
        ShopController::expireStaleCoupons($userId);
        $couponRows = PaymentRepository::fetchUserCoupons($userId);
        $coupons = [];
        foreach ($couponRows as $c) {
            $levels = self::couponLevels((string)($c['vip_levels'] ?? ''));
            // 每张券在每个档位上能减多少，前端勾选后直接显示折后价，不用自己算
            $cut = [];
            foreach ($plans as $p) {
                $cut[(string)$p['level']] = in_array($p['level'], $levels, true)
                    ? self::couponCut($c, (float)$p['price'])
                    : 0;
            }
            $coupons[] = [
                'id' => (int)$c['id'],
                'title' => (string)$c['title'],
                'discount_mode' => (string)$c['discount_mode'],
                'discount_value' => (float)$c['discount_value'],
                'desc' => self::describeCoupon((string)$c['discount_mode'], (float)$c['discount_value'], (string)($c['vip_levels'] ?? '')),
                'levels' => $levels,
                'expire_at' => $c['expire_at'],
                'cut' => $cut,
            ];
        }

        return Response::success([
            'vip_level' => (int)($row['vip_level'] ?? 0),
            'vip_expire_at' => $row['vip_expire_at'] ?? null,
            'avatar_frame' => $row['avatar_frame'] ?? 'none',
            'balance' => number_format((float)($row['balance'] ?? 0), 2, '.', ''),
            // V68：买会员时能用积分抵扣
            'points' => (int)($row['points'] ?? 0),
            'point_rate' => (int)self::pointRate(),
            'privileges' => $granted,
            'plans' => $plans,
            'coupons' => $coupons,
        ], '获取成功');
    }

    /**
     * GET /api/pay/vip-orders —— 我的会员订购单
     */
    public function vipOrders(Request $request): Response
    {
        $user = $this->requireUser($request);

        $rows = PaymentRepository::fetchVipOrders((int)$user['id']);

        return Response::success($rows, '获取成功');
    }

    /**
     * POST /api/pay/vip-buy —— 买会员（余额扣款 + 可选积分抵扣）
     *
     * body: { level: 1|2|3, use_points: 0 }
     * V68：积分是站内主要流通货币，所以这里允许拿积分折钱，抵扣上限 50%。
     */
    public function buyVip(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        self::syncVip($userId);

        $level = $request->getInt('level');
        if (!isset(self::VIP_DAYS[$level])) {
            return Response::error('会员档位不合法', 400);
        }

        $price = (float)$this->setting("vip_price_{$level}", [1 => 20, 2 => 50, 3 => 180][$level]);
        if ($price <= 0) {
            return Response::error('该档位尚未定价', 400);
        }

        $current = PaymentRepository::findWalletUser($userId);

        // 禁止降级：当前档位更高且仍在有效期内时，不能再买更低档位（否则会把等级刷低）。
        // 同档位 = 续费、更高档位 = 升级，均放行。
        $curLevel = (int)($current['vip_level'] ?? 0);
        $curExpire = !empty($current['vip_expire_at']) ? strtotime($current['vip_expire_at']) : 0;
        if ($curLevel > $level && $curExpire > time()) {
            $curName = self::VIP_NAMES[$curLevel] ?? ('Lv' . $curLevel);
            $newName = self::VIP_NAMES[$level] ?? ('Lv' . $level);
            return Response::error("你当前是{$curName}会员（有效期未结束），不能开通更低的{$newName}档位", 400);
        }

        // ---- V143 会员折扣卡 ----
        // 顺序固定为「先打折，再抵积分」：积分抵扣上限按折后价算，
        // 否则 50% 上限会被原价抬高，出现「打折 + 全积分」把会员几乎白送的情况。
        $couponId = $request->getInt('coupon_id', 0);
        $coupon = null;
        $couponCut = 0.0;
        if ($couponId > 0) {
            $coupon = PaymentRepository::findCoupon($couponId, $userId);
            if (!$coupon) {
                return Response::error('折扣卡不存在', 400);
            }
            if ((string)$coupon['status'] !== 'unused') {
                return Response::error('该折扣卡已使用或已过期', 400);
            }
            if (!empty($coupon['expire_at']) && strtotime((string)$coupon['expire_at']) < time()) {
                PaymentRepository::markCouponExpired($couponId);
                return Response::error('该折扣卡已过期', 400);
            }
            if (!self::couponApplies($coupon, $level)) {
                return Response::error(
                    '该折扣卡不适用于' . (self::VIP_NAMES[$level] ?? ('Lv' . $level)) . '档位',
                    400
                );
            }
            $couponCut = self::couponCut($coupon, $price);
        }
        $priceAfter = round(max(self::MIN_PAY, $price - $couponCut), 2);

        // ---- V68 积分抵扣 ----
        $usePoints = max(0, (int)$request->getInt('use_points', 0));
        $discount = 0;
        if ($usePoints > 0) {
            if ($usePoints > (int)($current['points'] ?? 0)) {
                return Response::error('积分不足', 400);
            }
            $discount = self::calcDiscount($usePoints, $priceAfter);
            if ($discount <= 0) {
                return Response::error('这些积分不够抵扣 1 元，少填点试试', 400);
            }
        }
        $pay = round(max(self::MIN_PAY, $priceAfter - $discount), 2);
        if ((float)($current['balance'] ?? 0) < $pay) {
            return Response::error('余额不足，请联系站长充值', 402);
        }

        $orderNo = $this->orderNo('VIP');
        $days = self::VIP_DAYS[$level];

        try {
            // 完整写序列（含事务）下沉到 Repository；事务内任一步失败会 rollBack 后原样抛出
            $result = PaymentRepository::purchaseVip(
                $userId,
                $level,
                $days,
                $usePoints,
                $pay,
                $discount,
                $priceAfter,
                $orderNo,
                $couponId > 0 ? $couponId : 0,
                $coupon
                    ? ('折扣卡：' . self::describeCoupon((string)$coupon['discount_mode'], (float)$coupon['discount_value'], (string)($coupon['vip_levels'] ?? '')))
                    : null,
                $couponCut
            );
            $balanceAfter = $result['balance_after'];
            $pointsAfter = $result['points_after'];
            $expire = $result['expire'];

            // 会员等级 / 到期时间已变，清掉个人主页缓存：
            // 否则「我的」页 onShow 拉 profile 时会读到旧等级，把刚刷新的登录态又盖回去。
            if (!empty($user['username'])) {
                Cache::delete("user_profile_{$user['username']}");
            }

            // V82：会员开通成功 → 徽章实时校验（VIP会员/年度VIP会员）
            BadgeService::onEvent($userId, 'vip_changed');

            return Response::success([
                'order_no' => $orderNo,
                'level' => $level,
                'expire_at' => date('Y-m-d H:i:s', $expire),
                'balance' => number_format($balanceAfter, 2, '.', ''),
                'points' => $pointsAfter,
                'discount' => $discount,
                // V143：App 要提示「已用折扣卡省了 ¥x」
                'origin_price' => $price,
                'coupon_cut' => $couponCut,
                'paid_amount' => $priceAfter,
            ], '开通成功');
        } catch (\Throwable $e) {
            Logger::error('[Hublog buyVip] ' . $e->getMessage());
            $msg = $e->getMessage();
            return Response::error(
                $msg === '余额不足' ? '余额不足'
                : ($msg === '积分不足' ? '积分不足'
                : ($msg === '折扣卡已被使用' ? '折扣卡已被使用，请重新选择' : '开通失败，请重试')),
                500
            );
        }
    }

    // ==================== 付费圈子 ====================

    /**
     * GET /api/pay/circle-pay —— 圈子的付费信息 + 我是否满足
     *
     * query: { circle_id }
     * 返回 { pay_type, pay_amount, pay_vip_level, required, subscribed, can_post, reason }
     * required/subscribed/can_post 用来驱动前端按钮文案，别让 App 自己猜门槛。
     */
    public function circlePayInfo(Request $request): Response
    {
        $user = $this->requireUser($request);
        $circleId = $request->getInt('circle_id');
        $circle = PaymentRepository::findCirclePayInfo($circleId);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }

        self::syncVip((int)$user['id']);
        [$canPost, $reason] = self::circlePostAllowed((int)$circleId, $circle, (int)$user['id']);

        return Response::success([
            'circle_id' => (int)$circle['id'],
            'name' => $circle['name'],
            'pay_type' => (int)$circle['pay_type'],
            'pay_amount' => number_format((float)$circle['pay_amount'], 2, '.', ''),
            'pay_vip_level' => (int)$circle['pay_vip_level'],
            'required' => (int)$circle['pay_type'] > 0,
            'can_post' => $canPost,
            'reason' => $reason,
        ], '获取成功');
    }

    /**
     * POST /api/pay/circle-subscribe —— 订阅 / 续费付费圈子
     *
     * body: { circle_id }
     */
    public function circleSubscribe(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $circleId = $request->getInt('circle_id');
        $circle = PaymentRepository::findCirclePayInfo($circleId);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        if ((int)$circle['pay_type'] !== 2) {
            return Response::error($circle['pay_type'] == 1 ? '该圈子只需会员等级，无需订阅' : '该圈子免费', 400);
        }

        self::syncVip($userId);
        [$canPost, $reason] = self::circlePostAllowed($circleId, $circle, $userId);
        if (!$canPost) {
            return Response::error($reason, 402);
        }

        $price = (float)$circle['pay_amount'];
        $current = PaymentRepository::findUserBalancePoints($userId);

        // ---- V68 积分抵扣 ----
        $usePoints = max(0, (int)$request->getInt('use_points', 0));
        $discount = 0;
        if ($usePoints > 0) {
            if ($usePoints > (int)($current['points'] ?? 0)) {
                return Response::error('积分不足', 400);
            }
            $discount = self::calcDiscount($usePoints, $price);
            if ($discount <= 0) {
                return Response::error('这些积分不够抵扣 1 元，少填点试试', 400);
            }
        }
        $pay = round($price - $discount, 2);
        if ($pay < 0) {
            $pay = 0;
        }
        // 批六-2：与 buyVip 对称的余额前置校验。仓储事务会因余额不足回滚并抛异常，
        // 但这里提前返回 402，给出明确契约，避免走异常兜底变成 500。
        if ((float)($current['balance'] ?? 0) < $pay) {
            return Response::error('余额不足，请联系站长充值', 402);
        }
        $pointsLeft = (int)$current['points'] - $usePoints;

        $orderNo = $this->orderNo('CIR');
        // V96：订阅天数改为「单圈配置 > 全局配置 > 365 天」，上限 3650 天由服务统一截断
        $days = CircleSubscriptionService::effectivePayDays($circleId);

        try {
            // 完整写序列（含事务）下沉到 Repository；事务内任一步失败会 rollBack 后原样抛出
            $balanceAfter = (float)$current['balance'] - $pay;
            $result = PaymentRepository::subscribeCircle(
                $userId,
                $circleId,
                $usePoints,
                $pay,
                $price,
                $days,
                $orderNo,
                $pointsLeft,
                $balanceAfter,
                "订阅圈子抵扣（折合 ¥{$discount}）",
                "订阅圈子《{$circle['name']}》" . ($discount > 0 ? "，积分抵扣 ¥{$discount}" : '')
            );
            $expire = $result['expire'];

            return Response::success([
                'circle_id' => $circleId,
                'expire_at' => date('Y-m-d H:i:s', $expire),
                'balance' => number_format($balanceAfter, 2, '.', ''),
                'points' => $pointsLeft,
                'discount' => $discount,
            ], '订阅成功，你已可以在圈子内发言');
        } catch (\Throwable $e) {
            Logger::error('[Hublog circleSubscribe] ' . $e->getMessage());
            return Response::error('订阅失败，请重试', 500);
        }
    }

    // ==================== 权益判定（供发帖 / 建圈复用） ====================

    /**
     * 圈内是否可以发言。
     * 返回 [bool, string]：能不能 + 不能时的原因（前端直接拿原因当提示语）。
     *
     * @param array<string, mixed> $circle
     * @return array{0: bool, 1: string}
     */
    public static function circlePostAllowed(int $circleId, array $circle, int $userId): array
    {
        $payType = (int)($circle['pay_type'] ?? 0);
        if ($payType <= 0) {
            return [true, ''];
        }

        $me = PaymentRepository::findVipLevel($userId);
        $vipLevel = (int)($me['vip_level'] ?? 0);

        if ($payType === 1) {
            // 等级门槛：门槛等级本身也算满足（开了高级会员还卡低级门槛很反直觉）
            if ($vipLevel === 0 || $vipLevel < (int)$circle['pay_vip_level']) {
                return [false, '该圈子需要会员才能加入'];
            }
            return [true, ''];
        }

        if ($payType === 2) {
            // V96：统一走订阅状态服务，文案逐字保持原样（App 端直接拿来显示）
            $state = CircleSubscriptionService::subscriptionState($userId, $circleId, $circle);
            if ($state === CircleSubscriptionService::STATE_NONE) {
                return [false, '该圈子为付费圈子，订阅后才能发言'];
            }
            if ($state === CircleSubscriptionService::STATE_EXPIRED) {
                return [false, '订阅已到期，续费后即可继续发言'];
            }
            return [true, ''];
        }

        return [true, ''];
    }

    /**
     * 当前权益集合（前端可直接渲染「每日可发 N 条 / 可建 N 个圈子」）
     *
     * @return array{post_daily: int, circle_create: int}
     */
    public static function privileges(int $vipLevel): array
    {
        return [
            'post_daily' => self::POST_DAILY_LIMIT[$vipLevel] ?? self::POST_DAILY_LIMIT[0],
            'circle_create' => self::CIRCLE_LIMIT[$vipLevel] ?? self::CIRCLE_LIMIT[0],
        ];
    }

    // ==================== 工具 ====================

    /**
     * 顺手把到期会员降级 —— 不做定时任务是常态，读的时候兜一下就行，
     * 免得昨天还是会员今天权限还在。
     */
    public static function syncVip(int $userId): void
    {
        $row = PaymentRepository::findVipExpiry($userId);
        if (!$row) {
            return;
        }
        if ((int)$row['vip_level'] > 0 && $row['vip_expire_at'] && strtotime($row['vip_expire_at']) <= time()) {
            PaymentRepository::updateUserVip($userId, 0, null);
        }
    }

    /**
     * 每元需要多少积分（后台 settings.point_rate 可改，默认 10 积分 = ¥1）
     */
    public static function pointRate(): int
    {
        $rate = (int)PaymentRepository::fetchSettingValue('point_rate');
        // fetchColumn 查无行返回 false（不是 null），不兜底会算成 0 → 抵扣永远是 0
        return $rate > 0 ? $rate : 10;
    }

    /**
     * 积分能抵多少钱（元）。抵扣上限 = 订单金额 × 50%
     */
    public static function calcDiscount(int $usePoints, float $amount): int
    {
        if ($usePoints <= 0 || $amount <= 0) {
            return 0;
        }
        $discount = intdiv($usePoints, self::pointRate());
        return min($discount, (int)floor($amount * self::POINT_MAX_RATIO));
    }

    // ==================== V143：会员折扣卡 ====================

    /**
     * 券面文案：「8折」「立减 ¥10」，带限定档位时补「（限白银/黄金）」。
     *
     * discount_mode=percent 时 discount_value 存的是**减免百分比**（20 → 8折），
     * 不是折扣率本身 —— 这么定是为了让后台表单「减免多少」两种模式语义一致。
     */
    public static function describeCoupon(string $mode, float $value, string $levels = ''): string
    {
        if ($mode === 'amount') {
            $txt = '立减 ¥' . number_format($value, 2, '.', '');
        } else {
            $keep = 100 - $value; // 减免 20% → 付 80%
            $zhe = $keep / 10;
            $txt = (abs($zhe - round($zhe)) < 0.0001)
                ? ((string)(int)round($zhe)) . '折'
                : '减 ' . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
        }
        $lv = self::couponLevels($levels);
        if ($lv) {
            $names = array_map(fn($v) => self::VIP_NAMES[$v] ?? ('Lv' . $v), $lv);
            $txt .= '（限' . implode('/', $names) . '）';
        }
        return $txt;
    }

    /** 券允许的档位集合；空串 = 三档通用 */
    public static function couponLevels(string $levels): array
    {
        if (trim($levels) === '') {
            return [1, 2, 3];
        }
        $out = [];
        foreach (explode(',', $levels) as $v) {
            $v = (int)trim($v);
            if ($v >= 1 && $v <= 3) {
                $out[] = $v;
            }
        }
        return $out ?: [1, 2, 3];
    }

    /** 这张券能不能用在指定档位上 */
    public static function couponApplies(array $coupon, int $level): bool
    {
        return in_array($level, self::couponLevels((string)($coupon['vip_levels'] ?? '')), true);
    }

    /** 这张券在某个原价上能减多少钱（不越界：立减不超过原价） */
    public static function couponCut(array $coupon, float $price): float
    {
        $value = (float)($coupon['discount_value'] ?? 0);
        if ($value <= 0 || $price <= 0) {
            return 0.0;
        }
        if (($coupon['discount_mode'] ?? 'percent') === 'amount') {
            return round(min($value, $price), 2);
        }
        return round($price * min($value, 99.99) / 100, 2);
    }

    private function setting(string $key, $default)
    {
        $value = PaymentRepository::fetchSettingValue($key);
        // 注意：没有这一行时 fetchColumn 返回的是 false 而不是 null，
        // 只判 null / '' 会把默认值整个吞成 0（会员价全是 ¥0 就是这么来的）。
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }
        return $value;
    }

    /**
     * 生成订单号（统一走 OrderNo 单一真源）。
     *
     * 原实现在本类、ShopController、AdminFinanceController 各写一份，
     * 且只有 4 位随机数（同秒碰撞约 1/10000），撞号会被 uk_order_no 唯一索引
     * 直接拒绝 → 表现为「用户付款成功但订单创建失败」。
     */
    private function orderNo(string $prefix): string
    {
        return OrderNo::generate($prefix);
    }

    // ==================== 会员发放（V96，供商城折扣会员复用） ====================

    /**
     * 仅发放会员：只更新 users.vip_* + 写 vip_subscriptions + 徽章 + 清缓存。
     * 不碰余额/积分——扣款由调用方（buyVip 或商城下单）负责，避免与支付逻辑耦合。
     *
     * @return array{ok:bool,expire?:string,reason?:string}
     */
    public static function grantVipOnly(int $userId, int $level, int $days, float $amount, string $orderNo, string $remark): array
    {
        if ($level < 1 || $days < 1) {
            return ['ok' => false, 'reason' => '会员档位不合法'];
        }
        $current = PaymentRepository::findVipExpiry($userId);
        if (!$current) {
            return ['ok' => false, 'reason' => '用户不存在'];
        }
        // 禁止降级：当前档位更高且未过期，不允许更低档位覆盖
        $curLevel = (int)($current['vip_level'] ?? 0);
        $curExpire = !empty($current['vip_expire_at']) ? strtotime($current['vip_expire_at']) : 0;
        if ($curLevel > $level && $curExpire > time()) {
            $curName = self::VIP_NAMES[$curLevel] ?? ('Lv' . $curLevel);
            $newName = self::VIP_NAMES[$level] ?? ('Lv' . $level);
            return ['ok' => false, 'reason' => "你当前是{$curName}会员（有效期未结束），无法开通更低的{$newName}档位"];
        }
        $base = $current['vip_expire_at'] ? strtotime($current['vip_expire_at']) : 0;
        $expire = max(time(), $base) + $days * 86400;
        try {
            PaymentRepository::updateUserVip($userId, $level, date('Y-m-d H:i:s', $expire));
            PaymentRepository::insertVipSubscription([
                'user_id' => $userId,
                'level' => $level,
                'days' => $days,
                'amount' => $amount,
                'order_no' => $orderNo,
                'status' => 1,
                'started_at' => Helper::now(),
                'expire_at' => date('Y-m-d H:i:s', $expire),
            ]);
            $u = PaymentRepository::findUsername($userId);
            if (!empty($u['username'])) {
                Cache::delete("user_profile_{$u['username']}");
            }
            BadgeService::onEvent($userId, 'vip_changed');
            return ['ok' => true, 'expire' => date('Y-m-d H:i:s', $expire)];
        } catch (\Throwable $e) {
            Logger::error('[Hublog grantVipOnly] ' . $e->getMessage());
            return ['ok' => false, 'reason' => '发放失败'];
        }
    }

    // ==================== 在线充值（V69） ====================

    /** 单笔充值上下限（元），挡住误输的天价金额 */
    private const RECHARGE_MIN = 1;
    private const RECHARGE_MAX = 5000;

    /**
     * 读取支付配置。全部走 settings（后台可填），不落迁移。
     * 任何一项没填，对应渠道自动不可用 —— 前端只会看到「已开启」的渠道。
     */
    private function paySettings(): array
    {
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'woquan.top');
        }
        return [
            'enabled' => (string)$this->setting('pay_enabled', '0') === '1',
            'wxpay_enabled' => (string)$this->setting('pay_wxpay_enabled', '0') === '1',
            'alipay_enabled' => (string)$this->setting('pay_alipay_enabled', '0') === '1',
            'wxpay' => [
                'appid' => trim((string)$this->setting('wxpay_appid', '')),
                'mchid' => trim((string)$this->setting('wxpay_mchid', '')),
                'serial_no' => trim((string)$this->setting('wxpay_serial_no', '')),
                'private_key' => (string)$this->setting('wxpay_private_key', ''),
                'api_v3_key' => trim((string)$this->setting('wxpay_api_v3_key', '')),
                'notify_url' => $base . '/api/pay/notify/wxpay',
            ],
            'alipay' => [
                'appid' => trim((string)$this->setting('alipay_appid', '')),
                'private_key' => (string)$this->setting('alipay_private_key', ''),
                'public_key' => (string)$this->setting('alipay_public_key', ''),
                'notify_url' => $base . '/api/pay/notify/alipay',
            ],
        ];
    }

    /** 某渠道配置是否填全（缺一项就不该出现在前端） */
    private function methodReady(array $s, string $method): bool
    {
        if ($method === 'wxpay') {
            return $s['wxpay_enabled']
                && $s['wxpay']['appid'] !== ''
                && $s['wxpay']['mchid'] !== ''
                && $s['wxpay']['serial_no'] !== ''
                && trim($s['wxpay']['private_key']) !== '';
        }
        if ($method === 'alipay') {
            return $s['alipay_enabled']
                && $s['alipay']['appid'] !== ''
                && trim($s['alipay']['private_key']) !== '';
        }
        return false;
    }

    /**
     * GET /api/pay/recharge-config —— 充值页要的一切开关与档位
     */
    public function rechargeConfig(Request $request): Response
    {
        $user = $this->requireUser($request);
        $s = $this->paySettings();
        $env = PaymentGateway::available();

        $methods = [];
        if ($this->methodReady($s, 'wxpay')) {
            $methods[] = ['key' => 'wxpay', 'label' => '微信支付'];
        }
        if ($this->methodReady($s, 'alipay')) {
            $methods[] = ['key' => 'alipay', 'label' => '支付宝'];
        }

        return Response::success([
            'enabled' => $s['enabled'] && $methods !== [],
            'env_ok' => $env['ok'],
            'methods' => $methods,
            'amounts' => [10, 30, 50, 100, 200, 500],
            'min_amount' => self::RECHARGE_MIN,
            'max_amount' => self::RECHARGE_MAX,
            'hint' => $s['enabled'] && $methods !== []
                ? '支付完成后余额自动到账，请留意钱包流水。'
                : '站长未开启在线充值，请联系站长手动入账。',
        ]);
    }

    /**
     * POST /api/pay/recharge-create —— 创建充值单并拉起渠道下单
     *
     * body: { amount, method(wxpay|alipay) }
     * 返回 orderInfo（App 端直接喂给 uni.requestPayment）
     */
    public function rechargeCreate(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];

        $s = $this->paySettings();
        if (!$s['enabled']) {
            return Response::error('在线充值未开启', 403);
        }
        $method = strtolower(trim((string)$request->getString('method')));
        if (!in_array($method, ['wxpay', 'alipay'], true)) {
            return Response::error('请选择支付方式', 400);
        }
        if (!$this->methodReady($s, $method)) {
            return Response::error('该支付方式未配置完整，请联系站长', 403);
        }

        $amount = (float)($request->getFloat('amount') ?? 0);
        // 只保留两位小数，避免 0.1+0.2 这种数进钱包流水
        $amount = round($amount, 2);
        if ($amount < self::RECHARGE_MIN || $amount > self::RECHARGE_MAX) {
            return Response::error('充值金额需在 ' . self::RECHARGE_MIN . ' ~ ' . self::RECHARGE_MAX . ' 元之间', 400);
        }

        $orderNo = $this->orderNo('RC');
        $orderId = PaymentRepository::insertRechargeOrder([
            'order_no' => $orderNo,
            'user_id' => $userId,
            'amount' => number_format($amount, 2, '.', ''),
            'method' => $method,
            'status' => 'pending',
            // V92：trade_no 改可空，待支付单不写渠道流水号。
            // 唯一索引 uk_trade_no 允许多个 NULL，正好表达「还没拿到渠道交易号」；
            // 若沿用旧的 '' 默认值，多张待支付单会互相撞唯一键。
            'trade_no' => null,
        ]);
        if (!$orderId) {
            return Response::error('创建充值单失败，请重试', 500);
        }

        $desc = '钱包充值 ' . number_format($amount, 2, '.', '') . ' 元';
        $res = $method === 'wxpay'
            ? PaymentGateway::wxpayAppOrder($orderNo, $amount, $desc, $s['wxpay'])
            : PaymentGateway::alipayAppOrder($orderNo, $amount, $desc, $s['alipay']);

        if (!$res['ok']) {
            // 下单失败就把单子关掉，别留一堆 pending 脏数据
            PaymentRepository::closeRechargeOrder($orderId);
            Logger::error('[Hublog rechargeCreate] ' . ($res['reason'] ?? '下单失败'));
            return Response::error((string)($res['reason'] ?? '下单失败'), 500);
        }

        $payload = [
            'order_no' => $orderNo,
            'amount' => number_format($amount, 2, '.', ''),
            'method' => $method,
        ];
        // 微信 App 端要对象，支付宝要字符串 —— 分别给，前端不用自己拼
        if ($method === 'wxpay') {
            $payload['orderInfo'] = $res['params'];
        } else {
            $payload['orderInfo'] = $res['orderInfo'];
        }
        return Response::success($payload, '下单成功');
    }

    /**
     * GET /api/pay/recharge-orders —— 我的充值记录
     */
    public function rechargeOrders(Request $request): Response
    {
        $user = $this->requireUser($request);
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $rows = PaymentRepository::fetchMyRechargeOrders((int)$user['id'], $limit);
        return Response::success($rows ?: [], '获取成功');
    }

    /**
     * GET /api/admin/recharge-orders —— 后台查看全站充值订单
     * query: { status(pending|paid|closed), method(wxpay|alipay), page, limit }
     */
    public function adminRechargeOrders(Request $request): Response
    {
        $status = strtolower(trim((string)$request->getString('status', '')));
        $method = strtolower(trim((string)$request->getString('method', '')));
        $page = max(1, $request->getInt('page', 1));
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        // 计数与列表共用同一套 WHERE（下沉到 Repository 的 adminRechargeWhere）
        $offset = (int)(($page - 1) * $limit);
        $total = PaymentRepository::countAdminRechargeOrders($status, $method);
        $rows = PaymentRepository::fetchAdminRechargeOrders($status, $method, $limit, $offset);
        return Response::success([
            'list' => $rows ?: [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 入账（幂等）：订单置为已支付 + 加余额 + 写钱包流水
     *
     * @return array{ok:bool,reason?:string,duplicated?:bool,user_id?:int,amount?:float}
     */
    private function markRechargePaid(string $orderNo, string $tradeNo): array
    {
        if ($orderNo === '') {
            return ['ok' => false, 'reason' => '单号为空'];
        }
        $order = PaymentRepository::findRechargeOrder($orderNo);
        if (!$order) {
            return ['ok' => false, 'reason' => '订单不存在'];
        }
        if ($order['status'] === 'paid') {
            return ['ok' => true, 'duplicated' => true, 'user_id' => (int)$order['user_id'], 'amount' => (float)$order['amount']];
        }
        if ($order['status'] === 'closed') {
            return ['ok' => false, 'reason' => '订单已关闭'];
        }

        $userId = (int)$order['user_id'];
        $amount = round((float)$order['amount'], 2);
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => '金额异常'];
        }

        try {
            // 完整写序列（含事务）下沉到 Repository：原子抢占 status='pending' → 加余额 → 钱包流水。
            // 抢占失败（rowCount=0）时 Repository 已 rollBack 并返回 null；其余异常 rollBack 后原样抛出。
            $result = PaymentRepository::claimRechargeAndCredit(
                $orderNo,
                $tradeNo,
                $userId,
                $amount,
                (string)$order['method'],
                (int)$order['id'],
                '在线充值（' . ($order['method'] === 'wxpay' ? '微信支付' : '支付宝') . '）'
            );
            if ($result === null) {
                // 没抢到：已被并发回调入账，或订单刚被关闭
                $latest = PaymentRepository::findRechargeOrderStatus($orderNo);
                if ($latest && $latest['status'] === 'paid') {
                    return ['ok' => true, 'duplicated' => true, 'user_id' => $userId, 'amount' => $amount];
                }
                return ['ok' => false, 'reason' => '订单状态已变更'];
            }
            return ['ok' => true, 'user_id' => $userId, 'amount' => $amount];
        } catch (\Throwable $e) {
            Logger::error('[Hublog markRechargePaid] ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * POST /api/pay/notify/wxpay —— 微信支付 v3 回调
     *
     * 不验签，改用「解密 resource 拿到单号 → 主动查单确认 SUCCESS」，
     * 省掉平台证书的下载与轮换，同时更抗伪造通知。
     */
    public function notifyWxpay(Request $request): Response
    {
        $s = $this->paySettings();
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            return Response::error('无效通知', 400);
        }
        if (($body['event_type'] ?? '') !== 'TRANSACTION.SUCCESS') {
            // 非支付成功事件：直接 200，别让微信重发
            return Response::success(null, '忽略');
        }

        $resource = is_array($body['resource'] ?? null) ? $body['resource'] : [];
        $decoded = PaymentGateway::wxpayDecryptResource($resource, $s['wxpay']['api_v3_key']);
        $orderNo = (string)($decoded['out_trade_no'] ?? '');
        if ($orderNo === '') {
            Logger::error('[Hublog notifyWxpay] 解密失败或缺少单号（检查 wxpay_api_v3_key）');
            return Response::error('解密失败', 400);
        }

        $query = PaymentGateway::wxpayQuery($orderNo, $s['wxpay']);
        if (!$query['ok'] || ($query['trade_state'] ?? '') !== 'SUCCESS') {
            Logger::error('[Hublog notifyWxpay] 查单未成功 ' . $orderNo . ' ' . ($query['reason'] ?? $query['trade_state'] ?? ''));
            return Response::error('订单未支付', 400);
        }

        // P1-2：回调金额一致性校验（settings 开关渐进式）。
        // 微信 v3 查单 amount.total 的字段路径曾被静态追踪确认为 data 顶层；
        // 但线上版本/配置差异不能靠"假设正确"来保证，先用 log 模式只探测不拦截，
        // 待探针日志确认字段稳定后，站长可切 enforce 强校验。默认 log 不改现网入账行为。
        $settings = Cache::remember('site_settings', function () {
            $rows = UserRepository::fetchAllSettings();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
        $mode = (string)($settings['wx_amount_check'] ?? 'log');
        if (!in_array($mode, ['off', 'log', 'enforce'], true)) {
            $mode = 'log'; // 非法值一律降级为 log，绝不因误配直接掉单
        }
        $order = PaymentRepository::findRechargeOrder($orderNo);
        $expectFen = (int)round((float)($order['amount'] ?? 0) * 100);
        $got = $query['amount_total']; // ?int，查单无该字段时为 null
        // 探针日志：只记结构不记 raw，只 SUCCESS 查单（此处 trade_state 已确认 SUCCESS）
        Logger::warning('[Hublog wxAmountProbe] orderNo=' . $orderNo . ' hasAmount=' . ($got === null ? 0 : 1)
            . ' amountTotal=' . ($got ?? 'null') . ' localExpectFen=' . $expectFen
            . ' match=' . ($got !== null && $got === $expectFen ? 1 : 0) . ' mode=' . $mode);
        if ($mode === 'enforce') {
            if ($got === null) {
                // fail-safe：字段缺失不入账，可人工补账，绝不误放行
                Logger::error('[Hublog notifyWxpay] enforce模式查单无amount.total，跳过入账 ' . $orderNo);
                return Response::error('金额字段缺失', 500);
            }
            if ($got !== $expectFen) {
                Logger::error('[Hublog notifyWxpay] 金额不符 ' . $orderNo . ' 渠道=' . $got . ' 本地=' . $expectFen);
                return Response::error('金额不一致', 400);
            }
        }
        // off / log 模式：仅探测不拦截，照常入账（保持现网行为）

        $res = $this->markRechargePaid($orderNo, (string)$query['transaction_id']);
        if (!$res['ok']) {
            Logger::error('[Hublog notifyWxpay] 入账失败 ' . $orderNo . ' ' . ($res['reason'] ?? ''));
            return Response::error($res['reason'] ?? '入账失败', 500);
        }
        return Response::success(null, 'success');
    }

    /**
     * POST /api/pay/notify/alipay —— 支付宝回调（RSA2 验签 + 输出 success）
     */
    public function notifyAlipay(Request $request): Response
    {
        $s = $this->paySettings();
        $post = $request->all();
        if (!isset($post['sign'])) {
            // 支付宝有时把参数放 query，兜一层
            $post = $_POST ?: $_GET;
        }

        if (!PaymentGateway::alipayVerify($post, $s['alipay']['public_key'])) {
            Logger::error('[Hublog notifyAlipay] 验签失败（检查 alipay_public_key）');
            echo 'fail';
            exit;
        }

        $status = (string)($post['trade_status'] ?? '');
        if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            echo 'success';
            exit;
        }

        // P1-2：支付宝无字段不确定性（total_amount 为元、与本地 amount 同单位），
        // 验签通过后直接强校验，防止"小额回调 + 篡改单号"走向入账。
        $orderNo = (string)($post['out_trade_no'] ?? '');
        $order = PaymentRepository::findRechargeOrder($orderNo);
        $paid = isset($post['total_amount']) ? round((float)$post['total_amount'], 2) : null;
        if (!$order || $paid === null || abs($paid - round((float)$order['amount'], 2)) > 0.01) {
            Logger::error('[Hublog notifyAlipay] 金额不符或缺单 ' . $orderNo
                . ' 回调=' . ($paid ?? 'null') . ' 本地=' . ($order['amount'] ?? 'null'));
            echo 'fail';
            exit;
        }

        $res = $this->markRechargePaid($orderNo, (string)($post['trade_no'] ?? ''));
        if (!$res['ok']) {
            Logger::error('[Hublog notifyAlipay] 入账失败 ' . ($post['out_trade_no'] ?? '') . ' ' . ($res['reason'] ?? ''));
            echo 'fail';
            exit;
        }
        echo 'success';
        exit;
    }
}
