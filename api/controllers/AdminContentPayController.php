<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\Cache;
use Hublog\Core\ContentPayRepository as Repo;
use Hublog\Core\Request;
use Hublog\Core\Response;

/**
 * V97 后台「内容付费」子域控制器。
 * 定价 / 专栏 / 条目 / 订单查询 / 免费赠送权益。全部需管理员。
 */
class AdminContentPayController extends AdminController
{
    public function orders(Request $request): Response
    {
        $this->requireAdmin($request);
        $status = trim((string)$request->getString('status'));
        $page = max(1, $request->getInt('page', 1));
        $limit = min(100, max(1, $request->getInt('limit', 20)));
        return Response::success(Repo::adminOrders($status, $page, $limit));
    }

    /** 定价 upsert：product_type=post|column, target_id, price, preview_ratio, status */
    public function saveProduct(Request $request): Response
    {
        $this->requireAdmin($request);
        $productType = (string)$request->getString('product_type');
        $targetId = $request->getInt('target_id');
        if (!in_array($productType, [Repo::PRODUCT_POST, Repo::PRODUCT_COLUMN], true) || $targetId <= 0) {
            return Response::error('参数错误', 400);
        }
        $price = round(max(0, (float)$request->getFloat('price', 0)), 2);
        $ratio = max(0, min(100, $request->getInt('preview_ratio', 30)));
        $validDays = max(0, $request->getInt('valid_days', 0));
        $status = in_array($request->getInt('status', 1), [0, 1], true) ? $request->getInt('status', 1) : 1;
        $postType = trim((string)$request->getString('post_type', 'normal')) ?: 'normal';

        Repo::upsertProduct($productType, $targetId, $postType, $price, $ratio, $validDays, $status);
        Cache::bumpNsVersion('contentpay');
        return Response::success(null, '保存成功');
    }

    /** 专栏 upsert：id>0 更新，否则新建；owner_id 默认取当前管理员 */
    public function saveColumn(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $columnId = $request->getInt('id');
        $ownerId = $request->getInt('owner_id') > 0 ? $request->getInt('owner_id') : (int)($admin['id'] ?? 0);
        $title = trim((string)$request->getString('title'));
        if ($title === '') {
            return Response::error('专栏标题不能为空', 400);
        }
        $summary = trim((string)$request->getString('summary'));
        $cover = trim((string)$request->getString('cover'));
        $validDays = max(0, $request->getInt('valid_days', 365));
        $status = in_array($request->getInt('status', 1), [0, 1], true) ? $request->getInt('status', 1) : 1;

        $id = Repo::upsertColumn($columnId, $ownerId, $title, $summary, $cover, $validDays, $status);
        Cache::bumpNsVersion('contentpay');
        return Response::success(['id' => $id], '保存成功');
    }

    /** 专栏条目 upsert */
    public function saveItem(Request $request): Response
    {
        $this->requireAdmin($request);
        $columnId = $request->getInt('column_id');
        $postId = $request->getInt('post_id');
        if ($columnId <= 0 || $postId <= 0) {
            return Response::error('参数错误', 400);
        }
        $postType = trim((string)$request->getString('post_type', 'normal')) ?: 'normal';
        $sortOrder = $request->getInt('sort_order', 0);
        Repo::upsertItem($columnId, $postId, $postType, $sortOrder);
        Cache::bumpNsVersion('contentpay');
        return Response::success(null, '保存成功');
    }

    /** 删除专栏条目 */
    public function deleteItem(Request $request): Response
    {
        $this->requireAdmin($request);
        $columnId = $request->getInt('column_id');
        $itemId = $request->getInt('item_id');
        if ($columnId <= 0 || $itemId <= 0) {
            return Response::error('参数错误', 400);
        }
        Repo::deleteItem($columnId, $itemId);
        Cache::bumpNsVersion('contentpay');
        return Response::success(null, '删除成功');
    }

    /** 专栏列表（后台下拉用） */
    public function columns(Request $request): Response
    {
        $this->requireAdmin($request);
        return Response::success(['list' => Repo::adminColumns()]);
    }

    /** 免费赠送权益：user_id, product_type, target_id, days(0=永久) */
    public function grant(Request $request): Response
    {
        $this->requireAdmin($request);
        $userId = $request->getInt('user_id');
        $productType = (string)$request->getString('product_type');
        $targetId = $request->getInt('target_id');
        if ($userId <= 0 || $targetId <= 0 || !in_array($productType, [Repo::PRODUCT_POST, Repo::PRODUCT_COLUMN], true)) {
            return Response::error('参数错误', 400);
        }
        $days = max(0, $request->getInt('days', 0));
        $postType = trim((string)$request->getString('post_type', 'normal')) ?: 'normal';
        Repo::grantEntitlement($userId, $productType, $targetId, $postType, $days);
        Cache::bumpNsVersion('contentpay');
        return Response::success(null, '赠送成功');
    }
}
