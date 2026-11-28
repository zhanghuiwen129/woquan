<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Cache;
use Hublog\Core\ContentPayRepository as Repo;
use Hublog\Core\Logger;
use Hublog\Core\OrderNo;
use Hublog\Core\Request;
use Hublog\Core\Response;

/**
 * V97 内容付费墙用户侧控制器（全部需登录）。
 *
 * 商业模型与 circleSubscribe 一致：站内钱包记账消费 + 积分抵扣（≤50%），
 * 不新增资金通道。价格只从 content_products 读，接口不接受前端 price。
 */
class ContentPayController extends BaseController
{
    /** 商品信息 + 我的解锁状态 */
    public function product(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $postId = $request->getInt('post_id');
        $columnId = $request->getInt('column_id');

        if ($postId > 0) {
            $product = Repo::findActivePostProduct($postId);
            $type = Repo::PRODUCT_POST;
            $targetId = $postId;
        } elseif ($columnId > 0) {
            $p = Repo::findProduct(Repo::PRODUCT_COLUMN, $columnId);
            $product = ($p && (int)$p['status'] === 1) ? $p : null;
            $type = Repo::PRODUCT_COLUMN;
            $targetId = $columnId;
        } else {
            return Response::error('参数错误', 400);
        }
        if (!$product) {
            return Response::error('该内容未开通付费', 404);
        }

        $ent = Repo::findEntitlement($userId, $type, $targetId);
        $unlocked = Repo::entitlementActive($ent);
        // 单篇商品对应帖子的作者本人，或专栏作者本人 → 免购
        $authorExempt = false;
        if ($type === Repo::PRODUCT_POST) {
            $authorExempt = Repo::findPostAuthorId($targetId) === $userId;
        } else {
            $col = Repo::findColumn($targetId);
            $authorExempt = $col && (int)$col['owner_id'] === $userId;
        }
        if ($authorExempt) {
            $unlocked = true;
        }

        return Response::success([
            'product_type' => $type,
            'target_id' => $targetId,
            'post_type' => (string)($product['post_type'] ?? 'normal'),
            'price' => number_format((float)$product['price'], 2, '.', ''),
            'preview_ratio' => (int)$product['preview_ratio'],
            'valid_days' => (int)($product['valid_days'] ?? 0),
            'unlocked' => $unlocked,
            'expire_at' => $unlocked && $ent ? ($ent['expire_at'] ?? null) : null,
            'reason' => $unlocked ? 'unlocked' : 'paywall',
            'point_rate' => PaymentController::pointRate(),
        ]);
    }

    /** 专栏信息 + 条目 + 解锁状态 */
    public function column(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $columnId = $request->getInt('id');
        $col = Repo::findColumn($columnId);
        if (!$col || (int)$col['status'] !== 1) {
            return Response::error('专栏不存在', 404);
        }
        $items = Repo::columnItems($columnId);
        $ent = Repo::findEntitlement($userId, Repo::PRODUCT_COLUMN, $columnId);
        return Response::success([
            'id' => $columnId,
            'owner_id' => (int)$col['owner_id'],
            'title' => (string)$col['title'],
            'summary' => (string)$col['summary'],
            'cover' => (string)$col['cover'],
            'valid_days' => (int)$col['valid_days'],
            'item_count' => (int)$col['item_count'],
            'unlocked' => Repo::entitlementActive($ent),
            'expire_at' => $ent ? ($ent['expire_at'] ?? null) : null,
            'items' => $items,
        ]);
    }

    /** 购买解锁（单篇买断 / 专栏订阅） */
    public function buy(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $productType = (string)$request->getString('product_type');
        $targetId = $request->getInt('target_id');
        $usePoints = max(0, $request->getInt('use_points', 0));

        if (!in_array($productType, [Repo::PRODUCT_POST, Repo::PRODUCT_COLUMN], true) || $targetId <= 0) {
            return Response::error('参数错误', 400);
        }

        // 价格只从服务端读；下架/不存在的商品不卖
        $product = Repo::findProduct($productType, $targetId);
        if (!$product || (int)$product['status'] !== 1) {
            return Response::error('该内容暂不可购买', 404);
        }

        // 作者本人免购
        if ($productType === Repo::PRODUCT_POST) {
            if (Repo::findPostAuthorId($targetId) === $userId) {
                return Response::success(['already_unlocked' => true, 'reason' => 'author'], '作者本人无需购买');
            }
        } else {
            $col = Repo::findColumn($targetId);
            if ($col && (int)$col['owner_id'] === $userId) {
                return Response::success(['already_unlocked' => true, 'reason' => 'author'], '作者本人无需购买');
            }
        }

        // 已解锁：不重复扣款（专栏已过期则允许续费）
        $ent = Repo::findEntitlement($userId, $productType, $targetId);
        if (Repo::entitlementActive($ent)) {
            return Response::success([
                'already_unlocked' => true,
                'expire_at' => $ent['expire_at'] ?? null,
            ], '已解锁，无需重复购买');
        }

        $price = (float)$product['price'];
        $current = Repo::findUserBalancePoints($userId);
        $discount = 0;
        if ($usePoints > 0) {
            if ($usePoints > (int)($current['points'] ?? 0)) {
                return Response::error('积分不足', 400);
            }
            $discount = PaymentController::calcDiscount($usePoints, $price);
            if ($discount <= 0) {
                return Response::error('这些积分不够抵扣 1 元，少填点试试', 400);
            }
        }
        $pay = max(0, round($price - $discount, 2));
        $pointsLeft = (int)$current['points'] - $usePoints;
        $balanceAfter = (float)$current['balance'] - $pay;

        $orderNo = OrderNo::generate('CT');
        // 专栏有效期：商品 valid_days>0 用商品值，否则跟随专栏；单篇=0（永久）
        $validDays = 0;
        if ($productType === Repo::PRODUCT_COLUMN) {
            $validDays = (int)($product['valid_days'] ?? 0);
            if ($validDays <= 0) {
                $col = Repo::findColumn($targetId);
                $validDays = $col ? (int)$col['valid_days'] : 365;
            }
        }

        try {
            $result = Repo::buy(
                $userId,
                $productType,
                $targetId,
                (string)($product['post_type'] ?? 'normal'),
                $price,
                $usePoints,
                $discount,
                $pay,
                $orderNo,
                $pointsLeft,
                $balanceAfter,
                $validDays,
                "内容付费抵扣（折合 ¥{$discount}）",
                "解锁内容《" . ($productType === Repo::PRODUCT_COLUMN ? '专栏' : '单篇') . "#{$targetId}》" . ($discount > 0 ? "，积分抵扣 ¥{$discount}" : '')
            );
            // 独立命名空间失效，不碰 timeline/popular
            Cache::bumpNsVersion('contentpay');

            return Response::success([
                'order_no' => $orderNo,
                'product_type' => $productType,
                'target_id' => $targetId,
                'pay_amount' => number_format($pay, 2, '.', ''),
                'discount' => $discount,
                'expire_at' => $result['expire_at'],
                'balance' => number_format($balanceAfter, 2, '.', ''),
                'points' => $pointsLeft,
            ], '解锁成功');
        } catch (\Throwable $e) {
            Logger::error('[Hublog contentPay buy] ' . $e->getMessage());
            return Response::error('购买失败，请重试', 500);
        }
    }

    /** 我的订单分页 */
    public function orders(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(1, $request->getInt('limit', 20)));
        return Response::success(Repo::myOrders($userId, $page, $limit));
    }

    /** 我的已解锁权益 */
    public function entitlements(Request $request): Response
    {
        $user = $this->requireUser($request);
        $userId = (int)$user['id'];
        return Response::success(['list' => Repo::myEntitlements($userId)]);
    }
}
