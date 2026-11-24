<?php

declare(strict_types=1);

namespace Hublog\Core;

/**
 * V97 内容付费墙数据层。
 *
 * 设计原则：
 * - 订单（资金流水）与权益（访问凭证）分表；付费墙只认 content_entitlements。
 * - 与 posts 只靠 target_id + post_type 冗余关联，不建外键、不改 posts 任何列。
 * - 购买走单事务 + 条件扣减（rowCount 守卫）+ uk_entitlement/uk_order_no/uk_wallet_biz_key 幂等。
 * - 价格永远从 content_products 服务端读，接口不接受前端 price。
 */
final class ContentPayRepository
{
    public const PRODUCT_POST = 'post';
    public const PRODUCT_COLUMN = 'column';

    /** 单篇商品（含下架） */
    public static function findProduct(string $productType, int $targetId): ?array
    {
        return Database::fetchOne(
            "SELECT id, product_type, target_id, post_type, price, preview_ratio, valid_days, status, sold_count
             FROM content_products WHERE product_type = ? AND target_id = ? LIMIT 1",
            [$productType, $targetId]
        ) ?: null;
    }

    /** 已上架的单篇商品 */
    public static function findActivePostProduct(int $postId): ?array
    {
        $row = self::findProduct(self::PRODUCT_POST, $postId);
        return ($row && (int)$row['status'] === 1) ? $row : null;
    }

    /** 专栏信息（含下架） */
    public static function findColumn(int $columnId): ?array
    {
        return Database::fetchOne(
            "SELECT id, owner_id, title, summary, cover, valid_days, item_count, status, created_at, updated_at
             FROM content_columns WHERE id = ? LIMIT 1",
            [$columnId]
        ) ?: null;
    }

    /** 专栏条目（含 post_type，按 sort_order 升序） */
    public static function columnItems(int $columnId): array
    {
        return Database::fetchAll(
            "SELECT id, column_id, post_id, post_type, sort_order FROM content_column_items
             WHERE column_id = ? ORDER BY sort_order ASC, id ASC",
            [$columnId]
        );
    }

    /**
     * 该帖子被哪些上架专栏收录（返回 column_id 列表）——付费墙按需取首个命中专栏。
     */
    public static function columnIdsOfPost(int $postId): array
    {
        $rows = Database::fetchAll(
            "SELECT ci.column_id FROM content_column_items ci
             INNER JOIN content_columns c ON c.id = ci.column_id
             INNER JOIN content_products p ON p.product_type = 'column' AND p.target_id = c.id
             WHERE ci.post_id = ? AND c.status = 1 AND p.status = 1
             ORDER BY ci.sort_order ASC, ci.column_id ASC",
            [$postId]
        );
        return array_map(static fn($r) => (int)$r['column_id'], $rows);
    }

    /** 单条权益（未过期由调用方按 expire_at 判定） */
    public static function findEntitlement(int $userId, string $productType, int $targetId): ?array
    {
        return Database::fetchOne(
            "SELECT id, user_id, product_type, target_id, post_type, source, order_no, expire_at
             FROM content_entitlements
             WHERE user_id = ? AND product_type = ? AND target_id = ? LIMIT 1",
            [$userId, $productType, $targetId]
        ) ?: null;
    }

    /**
     * 批量查权益：一次 IN 查当前用户对多组 (product_type,target_id) 的解锁情况。
     *
     * @param list<array{product_type:string,target_id:int}> $items
     * @return array<string,array> 键 = product_type . ':' . target_id
     */
    public static function findEntitlements(int $userId, array $items): array
    {
        $map = [];
        if ($userId <= 0 || $items === []) {
            return $map;
        }
        $posts = [];
        $columns = [];
        foreach ($items as $it) {
            $t = (string)($it['product_type'] ?? '');
            $id = (int)($it['target_id'] ?? 0);
            if ($t === self::PRODUCT_POST && $id > 0) {
                $posts[$id] = true;
            } elseif ($t === self::PRODUCT_COLUMN && $id > 0) {
                $columns[$id] = true;
            }
        }
        $rows = [];
        $postIds = array_keys($posts);
        if ($postIds) {
            $ph = implode(',', array_fill(0, count($postIds), '?'));
            $rows = array_merge($rows, Database::fetchAll(
                "SELECT product_type, target_id, expire_at FROM content_entitlements
                 WHERE user_id = ? AND product_type = 'post' AND target_id IN ({$ph})",
                array_merge([$userId], $postIds)
            ));
        }
        $columnIds = array_keys($columns);
        if ($columnIds) {
            $ph = implode(',', array_fill(0, count($columnIds), '?'));
            $rows = array_merge($rows, Database::fetchAll(
                "SELECT product_type, target_id, expire_at FROM content_entitlements
                 WHERE user_id = ? AND product_type = 'column' AND target_id IN ({$ph})",
                array_merge([$userId], $columnIds)
            ));
        }
        foreach ($rows as $r) {
            $map[(string)$r['product_type'] . ':' . (int)$r['target_id']] = $r;
        }
        return $map;
    }

    /** 权益是否有效（expire_at NULL=永久） */
    public static function entitlementActive(?array $entitlement): bool
    {
        if (!$entitlement) {
            return false;
        }
        $expire = $entitlement['expire_at'] ?? null;
        return $expire === null || (strtotime((string)$expire) > time());
    }

    /** 当前用户余额与积分 */
    public static function findUserBalancePoints(int $userId): array
    {
        return Database::fetchOne("SELECT balance, points FROM users WHERE id = ? LIMIT 1", [$userId]) ?: ['balance' => 0, 'points' => 0];
    }

    /**
     * 购买内容完整写序列（单事务）。
     *
     * @return array{expire_at: ?string} expire_at NULL=永久解锁（单篇买断）
     */
    public static function buy(
        int $userId,
        string $productType,
        int $targetId,
        string $postType,
        float $price,
        int $usePoints,
        float $discount,
        float $pay,
        string $orderNo,
        int $pointsLeft,
        float $balanceAfter,
        int $validDays,
        string $pointsLogRemark,
        string $walletRemark
    ): array {
        Database::beginTransaction();
        try {
            if ($usePoints > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET points = points - ? WHERE id = ? AND points >= ?"
                );
                $stmt->execute([$usePoints, $userId, $usePoints]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('积分不足');
                }
            }

            if ($pay > 0) {
                $stmt = Database::getInstance()->prepare(
                    "UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?"
                );
                $stmt->execute([$pay, $userId, $pay]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('余额不足');
                }
            }

            // V102：调用方传入的 $pointsLeft / $balanceAfter 来自事务外快照，并发下
            // 会把流水的 balance_after 写成陈旧值。对齐 purchaseVip / circleSubscribe
            // 范本，扣款后事务内重读真实值再落流水（流水 amount 本身不受影响，
            // 这里修的是 balance_after 记录准确性）。
            $fresh = Database::fetchOne(
                "SELECT balance, points FROM users WHERE id = ? LIMIT 1",
                [$userId]
            );
            $pointsLeft = (int)($fresh['points'] ?? 0);
            $balanceAfter = round((float)($fresh['balance'] ?? 0), 2);

            if ($usePoints > 0) {
                Database::insert('points_logs', [
                    'user_id' => $userId,
                    'points' => -$usePoints,
                    'type' => 'spend',
                    'biz_type' => 'contentpay',
                    'biz_id' => $targetId,
                    'balance_after' => $pointsLeft,
                    'remark' => $pointsLogRemark,
                ]);
            }

            // 订单（uk_order_no 防重复下单）
            Database::insert('content_orders', [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'product_type' => $productType,
                'target_id' => $targetId,
                'post_type' => $postType,
                'amount' => $price,
                'points_used' => $usePoints,
                'discount' => $discount,
                'pay_amount' => $pay,
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            // 权益：单篇=永久(NULL)；专栏=NOW()+valid_days；续费顺延（取现有与 NOW 的较大值）
            $expireAt = null;
            if ($productType === self::PRODUCT_COLUMN && $validDays > 0) {
                $old = self::findEntitlement($userId, $productType, $targetId);
                $base = ($old && $old['expire_at'] && strtotime((string)$old['expire_at']) > time())
                    ? strtotime((string)$old['expire_at'])
                    : time();
                $expireAt = date('Y-m-d H:i:s', $base + $validDays * 86400);
                if ($old) {
                    Database::update('content_entitlements', [
                        'expire_at' => $expireAt,
                        'source' => 'purchase',
                        'order_no' => $orderNo,
                    ], 'id = ?', [(int)$old['id']]);
                } else {
                    Database::insert('content_entitlements', [
                        'user_id' => $userId,
                        'product_type' => $productType,
                        'target_id' => $targetId,
                        'post_type' => $postType,
                        'source' => 'purchase',
                        'order_no' => $orderNo,
                        'expire_at' => $expireAt,
                    ]);
                }
            } else {
                // uk_entitlement 唯一键兜底：并发/重放下重复购买由唯一约束抛错 → 事务回滚。
                // 常规「已购买」由控制器前置查权益拦截，返回 already_unlocked 不扣款。
                $inserted = Database::insert('content_entitlements', [
                    'user_id' => $userId,
                    'product_type' => $productType,
                    'target_id' => $targetId,
                    'post_type' => $postType,
                    'source' => 'purchase',
                    'order_no' => $orderNo,
                    'expire_at' => null,
                ]);
                if ($inserted <= 0) {
                    throw new \RuntimeException('已购买');
                }
            }

            // 钱包流水（uk_wallet_biz_key 防重复入账）
            Database::insert('wallet_logs', [
                'user_id' => $userId,
                'amount' => -$pay,
                'type' => 'consume',
                'biz_type' => 'contentpay',
                'biz_id' => $targetId,
                'biz_key' => 'content:' . $orderNo,
                'balance_after' => $balanceAfter,
                'remark' => $walletRemark,
            ]);

            // 销量 +1（仅单篇；专栏商品也计数）
            Database::getInstance()->prepare(
                "UPDATE content_products SET sold_count = sold_count + 1
                 WHERE product_type = ? AND target_id = ?"
            )->execute([$productType, $targetId]);

            Database::commit();
            return ['expire_at' => $expireAt];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 我的订单分页 */
    public static function myOrders(int $userId, int $page, int $limit): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $where = 'user_id = ?';
        $params = [$userId];
        $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM content_orders WHERE {$where}", $params);
        $rows = Database::fetchAll(
            "SELECT id, order_no, product_type, target_id, post_type, amount, points_used, discount, pay_amount, status, paid_at, created_at
             FROM content_orders WHERE {$where} ORDER BY id DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
        return ['total' => $total, 'list' => $rows];
    }

    /** 我的已解锁权益列表 */
    public static function myEntitlements(int $userId): array
    {
        return Database::fetchAll(
            "SELECT id, product_type, target_id, post_type, source, order_no, expire_at, created_at
             FROM content_entitlements WHERE user_id = ? ORDER BY id DESC",
            [$userId]
        );
    }

    /** 后台：全站订单分页（可按 status 过滤） */
    public static function adminOrders(string $status, int $page, int $limit): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM content_orders WHERE {$where}", $params);
        $offset = max(0, ($page - 1) * $limit);
        $rows = Database::fetchAll(
            "SELECT id, order_no, user_id, product_type, target_id, post_type, amount, points_used, discount, pay_amount, status, trade_no, paid_at, created_at
             FROM content_orders WHERE {$where} ORDER BY id DESC LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
        return ['total' => $total, 'list' => $rows];
    }

    /** 后台：定价 upsert（商品不存在则建） */
    public static function upsertProduct(
        string $productType,
        int $targetId,
        string $postType,
        float $price,
        int $previewRatio,
        int $validDays,
        int $status
    ): void {
        $old = self::findProduct($productType, $targetId);
        if ($old) {
            Database::update('content_products', [
                'post_type' => $postType,
                'price' => $price,
                'preview_ratio' => $previewRatio,
                'valid_days' => $validDays,
                'status' => $status,
            ], 'id = ?', [(int)$old['id']]);
        } else {
            Database::insert('content_products', [
                'product_type' => $productType,
                'target_id' => $targetId,
                'post_type' => $postType,
                'price' => $price,
                'preview_ratio' => $previewRatio,
                'valid_days' => $validDays,
                'status' => $status,
            ]);
        }
    }

    /** 后台：专栏 upsert */
    public static function upsertColumn(
        int $columnId,
        int $ownerId,
        string $title,
        string $summary,
        string $cover,
        int $validDays,
        int $status
    ): int {
        $data = [
            'owner_id' => $ownerId,
            'title' => $title,
            'summary' => $summary,
            'cover' => $cover,
            'valid_days' => $validDays,
            'status' => $status,
        ];
        if ($columnId > 0) {
            Database::update('content_columns', $data, 'id = ?', [$columnId]);
            return $columnId;
        }
        return Database::insert('content_columns', $data);
    }

    /** 后台：专栏条目 upsert（uk_column_item 去重） */
    public static function upsertItem(int $columnId, int $postId, string $postType, int $sortOrder): void
    {
        $old = Database::fetchOne(
            "SELECT id FROM content_column_items WHERE column_id = ? AND post_id = ? LIMIT 1",
            [$columnId, $postId]
        );
        if ($old) {
            Database::update('content_column_items', [
                'post_type' => $postType,
                'sort_order' => $sortOrder,
            ], 'id = ?', [(int)$old['id']]);
        } else {
            Database::insert('content_column_items', [
                'column_id' => $columnId,
                'post_id' => $postId,
                'post_type' => $postType,
                'sort_order' => $sortOrder,
            ]);
        }
        $cnt = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM content_column_items WHERE column_id = ?", [$columnId]
        );
        Database::update('content_columns', ['item_count' => $cnt], 'id = ?', [$columnId]);
    }

    /** 后台：删除专栏条目 */
    public static function deleteItem(int $columnId, int $itemId): void
    {
        Database::delete('content_column_items', 'id = ? AND column_id = ?', [$itemId, $columnId]);
        $cnt = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM content_column_items WHERE column_id = ?", [$columnId]
        );
        Database::update('content_columns', ['item_count' => $cnt], 'id = ?', [$columnId]);
    }

    /** 后台：专栏列表 */
    public static function adminColumns(): array
    {
        return Database::fetchAll(
            "SELECT id, owner_id, title, summary, cover, valid_days, item_count, status, created_at
             FROM content_columns ORDER BY id DESC LIMIT 200"
        );
    }

    /** 后台：免费赠送权益（source=manual，管理员专用；调用方已做鉴权） */
    public static function grantEntitlement(
        int $userId,
        string $productType,
        int $targetId,
        string $postType,
        int $days
    ): void {
        $expireAt = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $old = self::findEntitlement($userId, $productType, $targetId);
        if ($old) {
            Database::update('content_entitlements', [
                'expire_at' => $expireAt,
                'source' => 'manual',
            ], 'id = ?', [(int)$old['id']]);
        } else {
            Database::insert('content_entitlements', [
                'user_id' => $userId,
                'product_type' => $productType,
                'target_id' => $targetId,
                'post_type' => $postType,
                'source' => 'manual',
                'order_no' => '',
                'expire_at' => $expireAt,
            ]);
        }
    }
}
