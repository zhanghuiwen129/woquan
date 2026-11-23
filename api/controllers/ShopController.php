<?php
declare(strict_types=1);

/**
 * Hublog 商城控制器（V96）
 *
 * 商业模型：
 *   - 商品四类：vip 折扣会员 / physical 圈子周边（实物）/ info 圈友好物（圈友自发发布，仅展示，站内私信交易）
 *     / coupon 会员折扣卡（V143：买了不发会员，只发一张券，之后买会员时勾选核销打折）
 *   - 双轨币种：price_balance（余额元）/ price_points（积分），买家任选其一购买
 *   - info 类不在线交易，只展示 + 私信联系卖家
 *   - 周边实物下单后 ship_status=pending，后台发货
 *   - 折扣会员下单即调 PaymentController::grantVipOnly 发放
 *   - 折扣卡下单即写 user_coupons（status=unused），核销发生在 PaymentController::buyVip
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\ShopRepository;
use Hublog\Core\Auth;
use Hublog\Core\Helper;
use Hublog\Core\Cache;
use Hublog\Core\OrderNo;
use Hublog\Controllers\PaymentController;

class ShopController extends BaseController
{
    /** 商品类型（V143 新增 coupon 会员折扣卡） */
    private const TYPES = ['vip', 'physical', 'info', 'coupon'];

    /** V143：折扣模式 —— percent=按比例打折 / amount=固定金额立减 */
    private const DISCOUNT_MODES = ['percent', 'amount'];

    /** 读取 settings 表单项（与 PointController/PaymentController 同款，独立副本避免跨控制器耦合） */
    private function setting(string $key, $default)
    {
        $value = ShopRepository::fetchSetting($key);
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }
        return $value;
    }

    /**
     * 限制级词汇（默认词库）。覆盖：武器刀具 / 毒品管制药品 / 烟草限售 /
     * 赌博私彩 / 色情成人 / 违法服务（代孕办证发票套现贷款刷单）/
     * 诈骗假冒（高仿假货）/ 野生保护动物。后台 settings.shop_ban_words 可追加（逗号/空格/分号分隔）。
     */
    private function forbiddenWords(): array
    {
        $default = [
            // 武器 / 管制刀具
            '枪支', '枪械', '弹药', '仿真枪', '管制刀具', '匕首', '弩',
            // 毒品 / 管制药品
            '毒品', '冰毒', '白粉', '大麻', '摇头丸', '迷药', '催情', '听话水', '管制药品',
            // 烟草限售
            '香烟', '卷烟', '电子烟', '烟草', '烟油',
            // 赌博 / 私彩
            '赌博', '博彩', '私彩', '彩票', '赌球', '投注', '时时彩',
            // 色情 / 成人
            '色情', '裸聊', '约炮', '招嫖', '成人用品', '成人内容',
            // 违法服务
            '代孕', '办证', '刻章', '代开发票', '套现', '黑户贷款', '刷单', '兼职刷单', '返利', '求带',
            // 诈骗 / 假冒
            '诈骗', '高仿', '假货', '仿牌', 'A货',
            // 野生保护动物
            '象牙', '犀牛角', '野生保护动物', '穿山甲',
        ];
        $custom = trim((string)$this->setting('shop_ban_words', ''));
        if ($custom !== '') {
            $extra = preg_split('/[\s,，;；]+/u', $custom);
            foreach ($extra as $w) {
                $w = trim($w);
                if ($w !== '') {
                    $default[] = $w;
                }
            }
        }
        return $default;
    }

    /**
     * 在文本中查找限制级词，命中返回该词，否则返回空串。
     */
    private function findForbidden(string $text): string
    {
        $text = (string)$text;
        if ($text === '') {
            return '';
        }
        foreach ($this->forbiddenWords() as $w) {
            if ($w === '' ) {
                continue;
            }
            if (mb_stripos($text, $w) !== false) {
                return $w;
            }
        }
        return '';
    }

    /**
     * 生成唯一订单号（前缀 + 时间 + 随机，避免与充值单 RC 冲突）。
     *
     * 统一走 OrderNo 单一真源，与 PaymentController / AdminFinanceController 同格式，
     * 随机段由 4 位升到 6 位（同秒碰撞从 1/10000 降到 1/100 万量级）。
     */
    private function orderNo(string $prefix): string
    {
        return OrderNo::generate($prefix);
    }

    // ==================== 公开：商城配置 ====================

    /**
     * GET /api/shop/config —— 商城首页需要的开关与公告
     */
    public function config(Request $request): Response
    {
        return Response::success([
            'mall_switch' => (string)$this->setting('mall_switch', '1') === '1',
            'shop_notice' => (string)$this->setting('shop_notice', ''),
            'shop_user_post' => (string)$this->setting('shop_user_post', '1') === '1',
            'tabs' => [
                ['key' => 'vip', 'label' => '折扣会员'],
                ['key' => 'coupon', 'label' => '会员折扣卡'],
                ['key' => 'physical', 'label' => '圈子周边'],
                ['key' => 'info', 'label' => '圈友好物'],
            ],
        ]);
    }

    // ==================== 公开：商品列表 / 详情 ====================

    /**
     * GET /api/shop/products —— 商品列表（按 type 过滤）
     * query: { type=vip|physical|info|all, page, limit, keyword }
     */
    public function products(Request $request): Response
    {
        if ((string)$this->setting('mall_switch', '1') !== '1') {
            return Response::success(['list' => [], 'total' => 0], '商城已关闭');
        }
        $type = strtolower(trim((string)$request->getString('type', 'all')));
        $keyword = trim((string)$request->getString('keyword', ''));
        $page = max(1, $request->getInt('page', 1));
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        // WHERE 条件与参数组装（type/LIKE）已下沉，列表与计数共用同一助手
        $total = ShopRepository::countProducts($type, $keyword);
        $rows = ShopRepository::fetchProducts($type, $keyword, $limit, ($page - 1) * $limit);
        // 圈友好物附上卖家昵称/头像（仅 info 需要）
        if (in_array($type, ['info', 'all'], true)) {
            $sellerIds = array_filter(array_column($rows ?: [], 'seller_id'), fn($v) => $v > 0);
            if ($sellerIds) {
                $users = ShopRepository::fetchSellersByIds(array_values($sellerIds));
                $userMap = [];
                foreach ($users ?: [] as $u) {
                    $userMap[(int)$u['id']] = $u;
                }
                foreach ($rows as &$r) {
                    $sid = (int)$r['seller_id'];
                    $r['seller'] = $sid > 0 ? ($userMap[$sid] ?? null) : null;
                }
                unset($r);
            }
        }
        return Response::success([
            'list' => $rows ?: [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * GET /api/shop/product —— 商品详情
     * query: { id }
     */
    public function product(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $p = ShopRepository::findProductById($id);
        if (!$p || (int)$p['status'] !== 1) {
            return Response::error('商品不存在或已下架', 404);
        }
        if ((int)$p['seller_id'] > 0) {
            $p['seller'] = ShopRepository::findSellerById((int)$p['seller_id']) ?: null;
        } else {
            $p['seller'] = null;
        }
        return Response::success($p);
    }

    // ==================== 需登录：下单购买 ====================

    /**
     * POST /api/shop/buy —— 购买商品（余额或积分，双轨）
     * body: { product_id, pay_currency(balance|points), qty(默认1),
     *         receiver_name, receiver_phone, receiver_address(周边实物必填) }
     */
    public function buy(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $productId = $request->getInt('product_id', 0);
        $currency = strtolower(trim((string)$request->getString('pay_currency', 'balance')));
        // 单次最多 99 件：既是业务上限，也是 qty 倍率扣款的主防线（余额/积分按单价×数量扣）
        $qty = min(99, max(1, $request->getInt('qty', 1)));
        if (!in_array($currency, ['balance', 'points'], true)) {
            return Response::error('支付方式不合法', 400);
        }

        $p = ShopRepository::findProductById($productId);
        if (!$p || (int)$p['status'] !== 1) {
            return Response::error('商品不存在或已下架', 404);
        }
        if ($p['type'] === 'info') {
            return Response::error('圈友好物为信息展示，请私信联系卖家交易', 400);
        }

        // 价格
        if ($currency === 'balance') {
            $price = round((float)$p['price_balance'], 2);
            if ($price <= 0) {
                return Response::error('该商品不支持余额购买', 400);
            }
        } else {
            $price = (int)$p['price_points'];
            if ($price <= 0) {
                return Response::error('该商品不支持积分兑换', 400);
            }
        }

        // 库存（周边实物 + 折扣卡；折扣卡限量发售时同样要挡）
        if (in_array($p['type'], ['physical', 'coupon'], true) && (int)$p['stock'] > 0) {
            if ((int)$p['stock'] < $qty) {
                return Response::error('库存不足', 400);
            }
        }

        // V143：折扣卡必须配好折扣规则才能卖，否则用户买了张「什么都不减」的卡
        if ($p['type'] === 'coupon') {
            $mode = strtolower((string)($p['discount_mode'] ?? 'percent'));
            $value = (float)($p['discount_value'] ?? 0);
            if (!in_array($mode, self::DISCOUNT_MODES, true) || $value <= 0) {
                return Response::error('该折扣卡未配置折扣规则，暂不可购买', 400);
            }
            if ($mode === 'percent' && $value >= 100) {
                return Response::error('折扣卡配置异常（减免比例须小于 100%）', 400);
            }
        }

        $orderNo = $this->orderNo('SP');
        $receiverName = trim((string)$request->getString('receiver_name', ''));
        $receiverPhone = trim((string)$request->getString('receiver_phone', ''));
        $receiverAddress = trim((string)$request->getString('receiver_address', ''));
        if ($p['type'] === 'physical' && ($receiverName === '' || $receiverPhone === '' || $receiverAddress === '')) {
            return Response::error('请填写收货人、手机号与收货地址', 400);
        }

        $current = ShopRepository::findWalletByUserId($userId);
        if (!$current) {
            return Response::error('用户不存在', 404);
        }

        // 跨领域描述/发放编排仍在控制器；发放闭包由仓储在事务内调用，保证失败整体回滚
        $couponText = '';
        if ($p['type'] === 'coupon') {
            $couponText = PaymentController::describeCoupon(
                (string)$p['discount_mode'],
                (float)$p['discount_value'],
                (string)($p['vip_levels'] ?? '')
            );
        }
        $expire = '';
        $couponIds = [];
        try {
            $result = ShopRepository::purchase(
                $userId,
                $p,
                $productId,
                $qty,
                $currency,
                $price,
                $orderNo,
                $receiverName,
                $receiverPhone,
                $receiverAddress,
                static function () use ($userId, $p, $price, $orderNo): array {
                    $lvl = (int)$p['vip_level'];
                    $days = (int)$p['vip_days'];
                    return PaymentController::grantVipOnly($userId, $lvl, $days, $price, $orderNo, '商城折扣会员');
                }
            );
            $expire = $result['expire'];
            $couponIds = $result['coupon_ids'];

            if (!empty($user['username'])) {
                Cache::delete("user_profile_{$user['username']}");
            }

            return Response::success([
                'order_no' => $orderNo,
                'product_type' => $p['type'],
                'pay_currency' => $currency,
                'amount' => $currency === 'balance' ? $price : 0,
                'points' => $currency === 'points' ? $price : 0,
                'ship_status' => $p['type'] === 'physical' ? 'pending' : 'none',
                'vip_expire_at' => $expire,
                // V143：买了折扣卡就告诉用户拿到了什么、几张，别让人以为钱白花了
                'coupon_ids' => $couponIds,
                'coupon_desc' => $couponText,
            ], $p['type'] === 'coupon' ? '购买成功，折扣卡已放入你的卡包' : '购买成功');
        } catch (\Throwable $e) {
            error_log('[Hublog ShopController::buy] ' . $e->getMessage()); // @allow-error-log
            $msg = $e->getMessage();
            return Response::error(
                $msg === '余额不足' ? '余额不足' : ($msg === '积分不足' ? '积分不足' : ($msg ?: '下单失败，请重试')),
                500
            );
        }
    }

    /**
     * V143：把当前用户「已过期的未使用券」置为 expired。
     *
     * 券的过期不做定时任务（没有 crontab 保障），而是在读券的两个入口
     * （shop/orders、pay/vip-info）顺手刷一次 —— 读多写少，成本可忽略。
     */
    public static function expireStaleCoupons(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            ShopRepository::expireStaleCoupons($userId);
        } catch (\Throwable $e) {
            error_log('[Hublog ShopController::expireStaleCoupons] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * GET /api/shop/orders —— 我的商城订单
     *
     * V143：折扣卡订单要带券状态（未使用 / 已使用 / 已过期），
     * 否则用户买了卡之后在订单里看不出这张卡还能不能用。
     */
    public function orders(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        self::expireStaleCoupons($userId);
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $rows = ShopRepository::fetchUserOrders($userId, $limit);
        $rows = $rows ?: [];
        foreach ($rows as &$r) {
            if ($r['type'] === 'coupon' && $r['discount_mode']) {
                $r['coupon_desc'] = PaymentController::describeCoupon(
                    (string)$r['discount_mode'],
                    (float)$r['discount_value'],
                    (string)($r['vip_levels'] ?? '')
                );
            } else {
                $r['coupon_desc'] = '';
            }
        }
        unset($r);
        return Response::success($rows, '获取成功');
    }

    // ==================== 需登录：圈友好物发布 / 管理 ====================

    /**
     * POST /api/shop/post —— 发布圈友好物（info 类）
     * body: { title, cover, images(JSON 串或数组), content, price_balance(可选) }
     */
    public function post(Request $request): Response
    {
        $user = $this->requireUser($request);
        if ((string)$this->setting('shop_user_post', '1') !== '1') {
            return Response::error('圈友好物发布已关闭', 403);
        }
        $title = trim((string)$request->getString('title', ''));
        if ($title === '') {
            return Response::error('请填写标题', 400);
        }
        $bad = $this->findForbidden($title);
        if ($bad !== '') {
            return Response::error('标题包含限制级词汇：' . $bad, 400);
        }
        $content = trim((string)$request->getString('content', ''));
        $bad = $this->findForbidden($content);
        if ($bad !== '') {
            return Response::error('介绍包含限制级词汇：' . $bad, 400);
        }
        $images = $request->getString('images', '');
        if (is_string($images) && $images !== '' && json_decode($images, true) === null && !str_starts_with($images, '[')) {
            // 允许逗号分隔的 url 串转数组
            $images = json_encode(array_filter(explode(',', $images), fn($v) => trim($v) !== ''), JSON_UNESCAPED_SLASHES);
        }
        $id = ShopRepository::insertInfoProduct([
            'seller_id' => (int)$user['id'],
            'type' => 'info',
            'title' => mb_substr($title, 0, 120),
            'cover' => trim((string)$request->getString('cover', '')),
            'images' => $images,
            'content' => $content,
            'price_balance' => round((float)$request->getFloat('price_balance', 0), 2),
            'price_points' => 0,
            'status' => 1,
            'sort' => 0,
        ]);
        if (!$id) {
            return Response::error('发布失败', 500);
        }
        return Response::success(['id' => $id], '发布成功');
    }

    /**
     * GET /api/shop/my-posts —— 我发布的好物
     */
    public function myPosts(Request $request): Response
    {
        $user = $this->requireUser($request);
        $rows = ShopRepository::fetchMyProducts((int)$user['id']);
        return Response::success($rows ?: [], '获取成功');
    }

    /**
     * POST /api/shop/post-delete —— 删除自己发布的好物
     */
    public function postDelete(Request $request): Response
    {
        $user = $this->requireUser($request);
        $id = $request->getInt('id', 0);
        $p = ShopRepository::findOwnInfoProduct($id);
        if (!$p) {
            return Response::error('商品不存在', 404);
        }
        if ((int)$p['seller_id'] !== (int)$user['id'] || $p['type'] !== 'info') {
            return Response::error('只能删除自己发布的好物', 403);
        }
        ShopRepository::unpublishProduct($id);
        return Response::success(null, '已删除');
    }

    // ==================== 后台管理（admin 路由组，AdminMiddleware 已鉴权） ====================

    /**
     * GET /api/admin/shop-products —— 商品管理列表
     * query: { type, status, keyword, page, limit }
     */
    public function adminProducts(Request $request): Response
    {
        $type = strtolower(trim((string)$request->getString('type', '')));
        $status = $request->getString('status', '');
        $keyword = trim((string)$request->getString('keyword', ''));
        $page = max(1, $request->getInt('page', 1));
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $total = ShopRepository::countAdminProducts($type, $status, $keyword);
        $rows = ShopRepository::fetchAdminProducts(
            $type,
            $status,
            $keyword,
            $limit,
            ($page - 1) * $limit
        );
        return Response::success([
            'list' => $rows ?: [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * POST/PUT /api/admin/shop-product-save —— 创建 / 编辑商品（平台商品）
     * body: { id?(编辑), type, title, cover, images, content, price_balance, price_points,
     *         vip_level, vip_days, stock, sort, status }
     */
    public function adminProductSave(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $type = strtolower(trim((string)$request->getString('type', 'physical')));
        if (!in_array($type, self::TYPES, true)) {
            return Response::error('商品类型不合法', 400);
        }
        $title = trim((string)$request->getString('title', ''));
        if ($title === '') {
            return Response::error('请填写商品标题', 400);
        }
        $bad = $this->findForbidden($title);
        if ($bad !== '') {
            return Response::error('标题包含限制级词汇：' . $bad, 400);
        }
        $bad = $this->findForbidden(trim((string)$request->getString('content', '')));
        if ($bad !== '') {
            return Response::error('介绍包含限制级词汇：' . $bad, 400);
        }
        $data = [
            'type' => $type,
            'title' => mb_substr($title, 0, 120),
            'cover' => trim((string)$request->getString('cover', '')),
            'images' => trim((string)$request->getString('images', '')),
            'content' => trim((string)$request->getString('content', '')),
            'price_balance' => round((float)$request->getFloat('price_balance', 0), 2),
            'price_points' => (int)$request->getInt('price_points', 0),
            'vip_level' => (int)$request->getInt('vip_level', 0),
            'vip_days' => (int)$request->getInt('vip_days', 0),
            'stock' => (int)$request->getInt('stock', 0),
            'sort' => (int)$request->getInt('sort', 0),
            'status' => (int)$request->getInt('status', 1) === 1 ? 1 : 0,
        ];
        // type=vip 时必须有档位与天数；type=physical 时余额售价应 > 0
        if ($type === 'vip' && ($data['vip_level'] < 1 || $data['vip_level'] > 3 || $data['vip_days'] < 1)) {
            return Response::error('折扣会员需填写有效档位(1-3)与天数', 400);
        }
        if ($type === 'physical' && $data['price_balance'] <= 0) {
            return Response::error('实物周边需设置余额售价', 400);
        }

        // ---- V143：会员折扣卡 ----
        if ($type === 'coupon') {
            $mode = strtolower(trim((string)$request->getString('discount_mode', 'percent')));
            if (!in_array($mode, self::DISCOUNT_MODES, true)) {
                return Response::error('折扣方式不合法（percent 打折 / amount 立减）', 400);
            }
            $value = round((float)$request->getFloat('discount_value', 0), 2);
            if ($value <= 0) {
                return Response::error('请填写折扣力度', 400);
            }
            if ($mode === 'percent' && $value >= 100) {
                return Response::error('按比例打折时，减免比例必须小于 100%', 400);
            }
            // P1-3：立减金额上限 360 元（= 最高会员档 180 × 2）。
            // 折扣值只影响 buyVip 的 couponCut（有 min(value, price) 兜底），
            // 但放开上限会让配置口径失真，主理人拍板服务端硬顶。
            if ($mode === 'amount' && $value > 360) {
                return Response::error('立减金额不能超过360元', 400);
            }
            // 适用档位：允许 "1,2,3" 或数组，空 = 三档通用
            $levelsRaw = $request->getString('vip_levels', '');
            $levels = [];
            if (is_array($levelsRaw)) {
                $levels = array_values(array_filter(array_map('intval', $levelsRaw), fn($v) => $v >= 1 && $v <= 3));
            } elseif (trim((string)$levelsRaw) !== '') {
                $levels = array_values(array_filter(
                    array_map('intval', explode(',', (string)$levelsRaw)),
                    fn($v) => $v >= 1 && $v <= 3
                ));
            }
            $validDays = max(0, (int)$request->getInt('valid_days', 0));
            if ($data['price_balance'] <= 0 && $data['price_points'] <= 0) {
                return Response::error('折扣卡需设置余额售价或积分售价', 400);
            }
            $data['discount_mode'] = $mode;
            $data['discount_value'] = $value;
            $data['vip_levels'] = implode(',', $levels);
            $data['valid_days'] = $validDays;
        } else {
            // 非折扣卡：把折扣字段归零，避免改类型后残留脏配置
            $data['discount_mode'] = 'percent';
            $data['discount_value'] = 0;
            $data['vip_levels'] = '';
            $data['valid_days'] = 0;
        }

        if ($id > 0) {
            $exist = ShopRepository::findPlatformProductById($id);
            if (!$exist) {
                return Response::error('商品不存在', 404);
            }
            if ((int)$exist['seller_id'] !== 0) {
                return Response::error('圈友发布商品请在商品管理处理', 400);
            }
            ShopRepository::updateProduct($id, $data);
            return Response::success(['id' => $id], '已更新');
        }
        $data['seller_id'] = 0; // 平台商品
        $newId = ShopRepository::insertProduct($data);
        if (!$newId) {
            return Response::error('创建失败', 500);
        }
        return Response::success(['id' => $newId], '已创建');
    }

    /**
     * POST /api/admin/shop-product-delete —— 下架 / 删除平台商品（软删除，status=0）
     * body: { id }
     */
    public function adminProductDelete(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $exist = ShopRepository::findPlatformProductById($id);
        if (!$exist) {
            return Response::error('商品不存在', 404);
        }
        if ((int)$exist['seller_id'] !== 0) {
            return Response::error('圈友发布商品请在商品管理处理', 400);
        }
        ShopRepository::unpublishProduct($id);
        return Response::success(null, '已下架');
    }

    /**
     * GET /api/admin/shop-orders —— 商城订单列表
     * query: { status, ship_status, page, limit }
     */
    public function adminOrders(Request $request): Response
    {
        $status = strtolower(trim((string)$request->getString('status', '')));
        $ship = strtolower(trim((string)$request->getString('ship_status', '')));
        $page = max(1, $request->getInt('page', 1));
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $total = ShopRepository::countAdminOrders($status, $ship);
        $rows = ShopRepository::fetchAdminOrders($status, $ship, $limit, ($page - 1) * $limit);
        return Response::success([
            'list' => $rows ?: [],
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * POST /api/admin/shop-ship —— 更新发货状态
     * body: { id, ship_status(pending|shipped|completed), tracking_no? }
     */
    public function adminShip(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $ship = strtolower(trim((string)$request->getString('ship_status', '')));
        if (!in_array($ship, ['pending', 'shipped', 'completed'], true)) {
            return Response::error('发货状态不合法', 400);
        }
        $order = ShopRepository::findShipOrderById($id);
        if (!$order) {
            return Response::error('订单不存在', 404);
        }
        if ((string)$order['type'] !== 'physical') {
            return Response::error('该订单无需发货', 400);
        }
        $data = ['ship_status' => $ship];
        $tracking = trim((string)$request->getString('tracking_no', ''));
        if ($tracking !== '') {
            $data['remark'] = '快递单号：' . mb_substr($tracking, 0, 64);
        }
        ShopRepository::updateShipOrder($id, $data);
        return Response::success(null, '已更新发货状态');
    }

    /**
     * GET /api/admin/wheel-prizes —— 大转盘奖品列表（含停用）
     */
    public function adminWheelPrizes(Request $request): Response
    {
        $rows = ShopRepository::fetchAllWheelPrizes();
        return Response::success($rows ?: [], '获取成功');
    }

    /**
     * POST/PUT /api/admin/wheel-prize-save —— 创建 / 编辑奖品
     * body: { id?, name, prize_type(points|balance|vip|thanks), value, probability, total, status, sort }
     */
    public function adminWheelPrizeSave(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $name = trim((string)$request->getString('name', ''));
        if ($name === '') {
            return Response::error('请填写奖品名称', 400);
        }
        $prizeType = strtolower(trim((string)$request->getString('prize_type', 'points')));
        if (!in_array($prizeType, ['points', 'balance', 'vip', 'thanks'], true)) {
            return Response::error('奖品类型不合法', 400);
        }
        $probability = round((float)$request->getFloat('probability', 0), 3);
        if ($probability < 0 || $probability > 100) {
            return Response::error('概率需在 0-100 之间', 400);
        }
        $data = [
            'name' => mb_substr($name, 0, 60),
            'prize_type' => $prizeType,
            'value' => (int)$request->getInt('value', 0),
            'probability' => $probability,
            'total' => (int)$request->getInt('total', 0),
            'status' => (int)$request->getInt('status', 1) === 1 ? 1 : 0,
            'sort' => (int)$request->getInt('sort', 0),
        ];
        if ($prizeType === 'thanks') {
            $data['value'] = 0;
        }
        if ($id > 0) {
            $exist = ShopRepository::findWheelPrizeById($id);
            if (!$exist) {
                return Response::error('奖品不存在', 404);
            }
            // 编辑时若改小了 total 但 used 已超，则不允许
            if ($data['total'] > 0) {
                $used = (int)ShopRepository::fetchWheelPrizeUsed($id);
                if ($used > $data['total']) {
                    return Response::error('已抽出数量已超过新限量，无法下调', 400);
                }
            }
            ShopRepository::updateWheelPrize($id, $data);
            return Response::success(['id' => $id], '已更新');
        }
        $newId = ShopRepository::insertWheelPrize($data);
        if (!$newId) {
            return Response::error('创建失败', 500);
        }
        return Response::success(['id' => $newId], '已创建');
    }

    /**
     * POST /api/admin/wheel-prize-delete —— 删除奖品（硬删，谨慎）
     * body: { id }
     */
    public function adminWheelPrizeDelete(Request $request): Response
    {
        $id = $request->getInt('id', 0);
        $exist = ShopRepository::findWheelPrizeById($id);
        if (!$exist) {
            return Response::error('奖品不存在', 404);
        }
        ShopRepository::deleteWheelPrize($id);
        return Response::success(null, '已删除');
    }

    // ==================== 后台：折扣卡发放记录（V143） ====================

    /**
     * GET /api/admin/shop-coupons —— 已发出的会员折扣卡
     *
     * query: { status(unused|used|expired), keyword(券名 / 用户昵称), page, limit }
     * 站长要能查到「谁买了卡、用没用、用在哪笔会员订单上」，否则退款争议无从核对。
     */
    public function adminCoupons(Request $request): Response
    {
        $status = strtolower(trim((string)$request->getString('status', '')));
        $keyword = trim((string)$request->getString('keyword', ''));
        $page = max(1, $request->getInt('page', 1));
        $limit = $request->getInt('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }
        $total = ShopRepository::countAdminCoupons($status, $keyword);
        $rows = ShopRepository::fetchAdminCoupons($status, $keyword, $limit, ($page - 1) * $limit);
        $rows = $rows ?: [];
        foreach ($rows as &$r) {
            $r['coupon_desc'] = PaymentController::describeCoupon(
                (string)$r['discount_mode'],
                (float)$r['discount_value'],
                (string)($r['vip_levels'] ?? '')
            );
        }
        unset($r);
        return Response::success([
            'list' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}
