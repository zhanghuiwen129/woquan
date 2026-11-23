<?php
/**
 * Hublog 商城域数据仓储
 *
 * 承载 ShopController 下沉的全部 SQL 与事务边界（config / products / product /
 * buy / orders / 后台商品 / 后台订单 / 奖品配置 / 折扣卡记录）。
 * 所有值一律走 ? 绑定（含 LIMIT/OFFSET、IN、LIKE 与已转型 int）；字段清单、
 * 顺序、别名、JOIN、子查询、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - 前台商品列表（16 列 + p.status=1 + p.sort ASC,p.id DESC） ≠
 *    后台商品列表（p.* + seller_name + LEFT JOIN users + p.id DESC） ≠
 *    我的好物（8 列 + seller_id + LIMIT 50）；
 *  - 用户订单（优惠券标量子查询）≠ 后台订单（o.* + 买家字段 + 双 LEFT JOIN）；
 *  - wheel_prizes：商城后台 `SELECT * ... ORDER BY sort ASC, id ASC`（含停用）
 *    ≠ 积分域 PointRepository::fetchAvailableWheelPrizes / fetchDrawableWheelPrizes；
 *  - users 余额：下单前 `SELECT balance, points FROM users WHERE id = ?`
 *    ≠ 事务内 `SELECT ... LIMIT 1`；两者在并发语义上不同，各建方法。
 *
 * 控制器保留权限、参数校验、缓存边界、响应包装与跨领域编排
 * （PaymentController::grantVipOnly / describeCoupon）。购买事务需要调用
 * 跨领域发放逻辑时以 callable 注入，begin/commit/rollBack 仍全部在本类。
 */

declare(strict_types=1);

namespace Hublog\Core;

class ShopRepository
{
    /** adminProductSave() 允许写入的商品列（控制器硬编码，这里再挡一层） */
    private const PRODUCT_COLUMNS = [
        'type', 'title', 'cover', 'images', 'content', 'price_balance', 'price_points',
        'vip_level', 'vip_days', 'stock', 'sort', 'status', 'discount_mode', 'discount_value',
        'vip_levels', 'valid_days',
    ];

    /** 新建商品时额外允许的列（只有插入路径使用，更新路径不接受 seller_id） */
    private const INSERT_PRODUCT_COLUMNS = [
        'seller_id', 'type', 'title', 'cover', 'images', 'content', 'price_balance',
        'price_points', 'vip_level', 'vip_days', 'stock', 'sort', 'status',
        'discount_mode', 'discount_value', 'vip_levels', 'valid_days',
    ];

    /** adminShip() 允许写入的订单列 */
    private const ORDER_COLUMNS = ['ship_status', 'remark'];

    /** adminWheelPrizeSave() 允许写入的奖品列 */
    private const PRIZE_COLUMNS = [
        'name', 'prize_type', 'value', 'probability', 'total', 'status', 'sort',
    ];

    /** setting() —— 单配置值；SQL 与迁移前逐字一致 */
    public static function fetchSetting(string $key): mixed
    {
        return Database::fetchColumn("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1", [$key]);
    }

    // ==================== 商品列表 / 详情 ====================

    /**
     * products() —— 前台商品列表 WHERE 助手（计数与列表共用）。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function productsWhere(string $type, string $keyword): array
    {
        $where = "WHERE p.status = 1";
        $params = [];
        if (in_array($type, ['vip', 'physical', 'info', 'coupon'], true)) {
            $where .= " AND p.type = ?";
            $params[] = $type;
        }
        if ($keyword !== '') {
            $where .= " AND p.title LIKE ?";
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    /** products() —— 前台商品总数 */
    public static function countProducts(string $type, string $keyword): int
    {
        [$where, $params] = self::productsWhere($type, $keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM shop_products p {$where}", $params);
    }

    /** products() —— 前台商品列表（16 列字段清单/顺序与排序逐字保留） */
    public static function fetchProducts(string $type, string $keyword, int $limit, int $offset): array
    {
        [$where, $params] = self::productsWhere($type, $keyword);
        return Database::fetchAll(
            "SELECT p.id, p.seller_id, p.type, p.title, p.cover, p.price_balance, p.price_points,
                    p.vip_level, p.vip_days, p.discount_mode, p.discount_value, p.vip_levels,
                    p.valid_days, p.stock, p.sales, p.sort
             FROM shop_products p {$where} ORDER BY p.sort ASC, p.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** products() —— 按卖家 ID 批量取昵称/头像（IN 元素全部绑定） */
    public static function fetchSellersByIds(array $sellerIds): array
    {
        $ids = array_values($sellerIds);
        if (empty($ids)) {
            return [];
        }
        $ph = str_repeat('?,', count($ids) - 1) . '?';
        return Database::fetchAll(
            "SELECT id, nickname, avatar FROM users WHERE id IN ({$ph})",
            $ids
        );
    }

    /** product() / buy() —— 商品详情（两处原文同为 SELECT * ... LIMIT 1） */
    public static function findProductById(int $productId): ?array
    {
        return Database::fetchOne("SELECT * FROM shop_products WHERE id = ? LIMIT 1", [$productId]);
    }

    /** product() —— 卖家昵称/头像 */
    public static function findSellerById(int $sellerId): ?array
    {
        return Database::fetchOne(
            "SELECT id, nickname, avatar FROM users WHERE id = ? LIMIT 1",
            [$sellerId]
        );
    }

    // ==================== 下单购买 ====================

    /** buy() —— 事务外读取当前钱包（无 LIMIT，与原文一致） */
    public static function findWalletByUserId(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT balance, points FROM users WHERE id = ?",
            [$userId]
        );
    }

    /** 写入列白名单 + 正则双校验，字段名永不进参数数组 */
    private static function assertColumns(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $column) {
            if (!is_string($column) || !preg_match('/^[a-z_]+$/', $column) || !in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('非法更新字段');
            }
        }
    }

    /**
     * buy() —— 扣款 / 流水 / 库存 / 销量 / 订单 / 发券完整事务。
     *
     * 条件原子更新（余额、积分、库存）与 V92 幂等键（biz_key、coupon slot）原样保留。
     * $grantVip 由控制器注入 PaymentController::grantVipOnly：跨领域编排仍在控制器，
     * 但它必须在本事务内执行才能保持原有「发放失败整体回滚」语义。
     *
     * @param array<string,mixed> $product
     * @param float|int $price
     * @param callable():array{ok:bool,reason?:string,expire?:string} $grantVip
     * @return array{expire:string,coupon_ids:array<int,int>}
     */
    public static function purchase(
        int $userId,
        array $product,
        int $productId,
        int $qty,
        string $currency,
        float|int $price,
        string $orderNo,
        string $receiverName,
        string $receiverPhone,
        string $receiverAddress,
        callable $grantVip
    ): array {
        // qty 倍率：仓储内部按「单价 × 数量」计算实际扣款额，调用方仍只传单价（签名不变）。
        // 整数积分走整数乘法，余额走 round 保留两位，避免浮点误差放大资损。
        $total = is_int($price) ? $price * $qty : round($price * $qty, 2);
        Database::beginTransaction();
        try {
            // 扣款（条件原子更新，防并发扣穿）
            if ($currency === 'balance') {
                $stmt = Database::query(
                    "UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?",
                    [$total, $userId, $total]
                );
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('余额不足');
                }
            } else {
                $stmt = Database::query(
                    "UPDATE users SET points = points - ? WHERE id = ? AND points >= ?",
                    [$total, $userId, $total]
                );
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('积分不足');
                }
            }

            // 事务内重读扣后真实余额/积分（V147 修复）
            $fresh = Database::fetchOne(
                "SELECT balance, points FROM users WHERE id = ? LIMIT 1",
                [$userId]
            );
            $balanceAfter = round((float)($fresh['balance'] ?? 0), 2);
            $pointsAfter = (int)($fresh['points'] ?? 0);

            if ($currency === 'balance') {
                Database::insert('wallet_logs', [
                    'user_id' => $userId,
                    'amount' => -$total,
                    'type' => 'consume',
                    'biz_type' => 'shop',
                    'biz_id' => $productId,
                    'biz_key' => 'shop:' . $orderNo,
                    'balance_after' => $balanceAfter,
                    'remark' => '商城购买：' . $product['title'],
                ]);
            } else {
                Database::insert('points_logs', [
                    'user_id' => $userId,
                    'points' => -$total,
                    'type' => 'spend',
                    'biz_type' => 'shop',
                    'biz_id' => $productId,
                    'balance_after' => $pointsAfter,
                    'remark' => '商城兑换：' . $product['title'],
                ]);
            }

            // 库存扣减：条件原子更新（V147 修复超卖）
            if (in_array($product['type'], ['physical', 'coupon'], true) && (int)$product['stock'] > 0) {
                $stmt = Database::query(
                    "UPDATE shop_products SET stock = stock - ? WHERE id = ? AND stock >= ?",
                    [$qty, $productId, $qty]
                );
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('库存不足');
                }
            }
            // 销量原子累加
            Database::query(
                "UPDATE shop_products SET sales = sales + ? WHERE id = ?",
                [$qty, $productId]
            );

            $shipStatus = $product['type'] === 'physical' ? 'pending' : 'none';
            $orderId = Database::insert('shop_orders', [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'product_id' => $productId,
                'qty' => $qty,
                'pay_currency' => $currency,
                'amount' => $currency === 'balance' ? $total : 0,
                'points' => $currency === 'points' ? $total : 0,
                'status' => 'paid',
                'ship_status' => $shipStatus,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'receiver_address' => $receiverAddress,
                'paid_at' => Helper::now(),
            ]);

            $expire = '';
            if ($product['type'] === 'vip') {
                $grant = $grantVip();
                if (!$grant['ok']) {
                    throw new \RuntimeException($grant['reason'] ?? '会员发放失败');
                }
                $expire = (string)$grant['expire'];
            }

            // V143：会员折扣卡按购买数量逐张发券（slot 唯一键防订单重放）
            $couponIds = [];
            if ($product['type'] === 'coupon') {
                $days = (int)($product['valid_days'] ?? 0);
                $expireAt = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
                for ($i = 0; $i < $qty; $i++) {
                    $couponIds[] = Database::insert('user_coupons', [
                        'user_id' => $userId,
                        'product_id' => $productId,
                        'order_id' => $orderId,
                        'slot' => $i,
                        'title' => (string)$product['title'],
                        'discount_mode' => (string)$product['discount_mode'],
                        'discount_value' => (float)$product['discount_value'],
                        'vip_levels' => (string)($product['vip_levels'] ?? ''),
                        'status' => 'unused',
                        'expire_at' => $expireAt,
                    ]);
                }
            }

            Database::commit();
            return ['expire' => $expire, 'coupon_ids' => $couponIds];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 券过期 / 我的订单 ====================

    /** expireStaleCoupons() —— 读券入口顺手刷过期（无事务，与原实现一致） */
    public static function expireStaleCoupons(int $userId): void
    {
        Database::query(
            "UPDATE user_coupons SET status = 'expired'
             WHERE user_id = ? AND status = 'unused' AND expire_at IS NOT NULL AND expire_at < NOW()",
            [$userId]
        );
    }

    /** orders() —— 我的商城订单（三条优惠券子查询 + LIMIT ? 逐字保留） */
    public static function fetchUserOrders(int $userId, int $limit): array
    {
        return Database::fetchAll(
            "SELECT o.id, o.order_no, o.product_id, o.qty, o.pay_currency, o.amount, o.points,
                    o.status, o.ship_status, o.created_at, p.title, p.cover, p.type,
                    (SELECT c.status FROM user_coupons c WHERE c.order_id = o.id ORDER BY c.id ASC LIMIT 1) AS coupon_status,
                    (SELECT c.expire_at FROM user_coupons c WHERE c.order_id = o.id ORDER BY c.id ASC LIMIT 1) AS coupon_expire,
                    (SELECT COUNT(*) FROM user_coupons c WHERE c.order_id = o.id) AS coupon_count,
                    p.discount_mode, p.discount_value, p.vip_levels, p.valid_days
             FROM shop_orders o LEFT JOIN shop_products p ON p.id = o.product_id
             WHERE o.user_id = ? ORDER BY o.id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    // ==================== 圈友好物 ====================

    /** post() —— 发布好物 */
    public static function insertInfoProduct(array $data): int
    {
        self::assertColumns($data, self::INSERT_PRODUCT_COLUMNS);
        return Database::insert('shop_products', $data);
    }

    /** myPosts() —— 我发布的好物（8 列 + LIMIT 50） */
    public static function fetchMyProducts(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, title, cover, images, content, price_balance, status, created_at
             FROM shop_products WHERE seller_id = ? ORDER BY id DESC LIMIT 50",
            [$userId]
        );
    }

    /** postDelete() —— 待删除的好物（id, seller_id, type） */
    public static function findOwnInfoProduct(int $productId): ?array
    {
        return Database::fetchOne(
            "SELECT id, seller_id, type FROM shop_products WHERE id = ? LIMIT 1",
            [$productId]
        );
    }

    /** postDelete() / adminProductDelete() —— 软下架（status=0） */
    public static function unpublishProduct(int $productId): void
    {
        Database::update('shop_products', ['status' => 0], 'id = ?', [$productId]);
    }

    // ==================== 后台商品管理 ====================

    /** adminProducts() —— 后台商品 WHERE 助手（计数与列表共用） */
    private static function adminProductsWhere(string $type, string $status, string $keyword): array
    {
        $where = '';
        $params = [];
        if (in_array($type, ['vip', 'physical', 'info', 'coupon'], true)) {
            $where .= " AND p.type = ?";
            $params[] = $type;
        }
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $where .= " AND p.status = ?";
            $params[] = (int)$status;
        }
        if ($keyword !== '') {
            $where .= " AND p.title LIKE ?";
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    /** adminProducts() —— 后台商品总数 */
    public static function countAdminProducts(string $type, string $status, string $keyword): int
    {
        [$where, $params] = self::adminProductsWhere($type, $status, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM shop_products p WHERE 1=1 {$where}",
            $params
        );
    }

    /** adminProducts() —— 后台商品列表（p.* + seller_name，字段/排序逐字保留） */
    public static function fetchAdminProducts(
        string $type,
        string $status,
        string $keyword,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = self::adminProductsWhere($type, $status, $keyword);
        return Database::fetchAll(
            "SELECT p.*, u.nickname AS seller_name
             FROM shop_products p LEFT JOIN users u ON u.id = p.seller_id
             WHERE 1=1 {$where} ORDER BY p.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** adminProductSave() / adminProductDelete() —— 平台商品校验行（id, seller_id） */
    public static function findPlatformProductById(int $productId): ?array
    {
        return Database::fetchOne("SELECT id, seller_id FROM shop_products WHERE id = ? LIMIT 1", [$productId]);
    }

    /** adminProductSave() —— 更新商品（列名白名单 + 正则校验） */
    public static function updateProduct(int $productId, array $data): void
    {
        self::assertColumns($data, self::PRODUCT_COLUMNS);
        Database::update('shop_products', $data, 'id = ?', [$productId]);
    }

    /** adminProductSave() —— 新建平台商品 */
    public static function insertProduct(array $data): int
    {
        self::assertColumns($data, self::INSERT_PRODUCT_COLUMNS);
        return Database::insert('shop_products', $data);
    }

    // ==================== 后台订单管理 ====================

    /** adminOrders() —— 后台订单 WHERE 助手（计数与列表共用） */
    private static function adminOrdersWhere(string $status, string $ship): array
    {
        $where = '';
        $params = [];
        if (in_array($status, ['paid', 'refunded', 'closed'], true)) {
            $where .= " AND o.status = ?";
            $params[] = $status;
        }
        if (in_array($ship, ['none', 'pending', 'shipped', 'completed'], true)) {
            $where .= " AND o.ship_status = ?";
            $params[] = $ship;
        }
        return [$where, $params];
    }

    /** adminOrders() —— 后台订单总数 */
    public static function countAdminOrders(string $status, string $ship): int
    {
        [$where, $params] = self::adminOrdersWhere($status, $ship);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM shop_orders o WHERE 1=1 {$where}",
            $params
        );
    }

    /** adminOrders() —— 后台订单列表（o.* + 买家字段 + 双 LEFT JOIN） */
    public static function fetchAdminOrders(
        string $status,
        string $ship,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = self::adminOrdersWhere($status, $ship);
        return Database::fetchAll(
            "SELECT o.*, p.title AS product_title, p.type AS product_type,
                    u.nickname AS buyer_name, u.username AS buyer_username
             FROM shop_orders o
             LEFT JOIN shop_products p ON p.id = o.product_id
             LEFT JOIN users u ON u.id = o.user_id
             WHERE 1=1 {$where} ORDER BY o.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** adminShip() —— 待发货订单校验行（JOIN 条件 p.id=o.product_id 无空格，逐字保留） */
    public static function findShipOrderById(int $orderId): ?array
    {
        return Database::fetchOne(
            "SELECT id, type FROM shop_orders o LEFT JOIN shop_products p ON p.id=o.product_id WHERE o.id = ? LIMIT 1",
            [$orderId]
        );
    }

    /** adminShip() —— 更新发货状态（列名白名单 + 正则校验） */
    public static function updateShipOrder(int $orderId, array $data): void
    {
        self::assertColumns($data, self::ORDER_COLUMNS);
        Database::update('shop_orders', $data, 'id = ?', [$orderId]);
    }

    // ==================== 后台大转盘奖品配置 ====================

    /** adminWheelPrizes() —— 含停用奖品的完整列表 */
    public static function fetchAllWheelPrizes(): array
    {
        return Database::fetchAll(
            "SELECT * FROM wheel_prizes ORDER BY sort ASC, id ASC"
        );
    }

    /** adminWheelPrizeSave() / Delete() —— 奖品存在性（SELECT id） */
    public static function findWheelPrizeById(int $prizeId): ?array
    {
        return Database::fetchOne("SELECT id FROM wheel_prizes WHERE id = ? LIMIT 1", [$prizeId]);
    }

    /** adminWheelPrizeSave() —— 读取已抽出数量 */
    public static function fetchWheelPrizeUsed(int $prizeId): mixed
    {
        return Database::fetchColumn("SELECT used FROM wheel_prizes WHERE id = ?", [$prizeId]);
    }

    /** adminWheelPrizeSave() —— 更新奖品（列名白名单 + 正则校验） */
    public static function updateWheelPrize(int $prizeId, array $data): void
    {
        self::assertColumns($data, self::PRIZE_COLUMNS);
        Database::update('wheel_prizes', $data, 'id = ?', [$prizeId]);
    }

    /** adminWheelPrizeSave() —— 新建奖品 */
    public static function insertWheelPrize(array $data): int
    {
        self::assertColumns($data, self::PRIZE_COLUMNS);
        return Database::insert('wheel_prizes', $data);
    }

    /** adminWheelPrizeDelete() —— 硬删奖品 */
    public static function deleteWheelPrize(int $prizeId): void
    {
        Database::delete('wheel_prizes', 'id = ?', [$prizeId]);
    }

    // ==================== 后台折扣卡发放记录 ====================

    /** adminCoupons() —— 券记录 WHERE 助手（计数与列表共用） */
    private static function adminCouponsWhere(string $status, string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($status, ['unused', 'used', 'expired'], true)) {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where .= ' AND (c.title LIKE ? OR u.nickname LIKE ? OR u.username LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    /** adminCoupons() —— 券记录总数 */
    public static function countAdminCoupons(string $status, string $keyword): int
    {
        [$where, $params] = self::adminCouponsWhere($status, $keyword);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM user_coupons c LEFT JOIN users u ON u.id = c.user_id {$where}",
            $params
        );
    }

    /** adminCoupons() —— 券记录列表（c.* + 用户字段，字段/排序逐字保留） */
    public static function fetchAdminCoupons(
        string $status,
        string $keyword,
        int $limit,
        int $offset
    ): array {
        [$where, $params] = self::adminCouponsWhere($status, $keyword);
        return Database::fetchAll(
            "SELECT c.*, u.nickname AS user_name, u.username AS user_account
             FROM user_coupons c LEFT JOIN users u ON u.id = c.user_id
             {$where} ORDER BY c.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }
}
