<?php
declare(strict_types=1);
/**
 * Hublog 后台「分类管理」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\CategoryRepository;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;

class AdminCategoryController extends AdminController
{
    public function categories(Request $request): Response
    {
        $this->requireAdmin($request);
        $rows = CategoryRepository::fetchAllCategories();
        $map = [];
        foreach ($rows as $row) {
            $row['status'] = (int)$row['status'];
            $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
            $row['sort_order'] = (int)$row['sort_order'];
            $row['children'] = [];
            $map[$row['id']] = $row;
        }
        $tree = [];
        foreach ($map as $id => $row) {
            if ($row['parent_id'] && isset($map[$row['parent_id']])) {
                $map[$row['parent_id']]['children'][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }
        return Response::success($tree);
    }

    public function allCategories(Request $request): Response
    {
        $rows = CategoryRepository::fetchActiveCategories();
        $map = [];
        foreach ($rows as $row) {
            $row['status'] = (int)$row['status'];
            $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
            $row['sort_order'] = (int)$row['sort_order'];
            $row['children'] = [];
            $map[$row['id']] = $row;
        }
        $tree = [];
        foreach ($map as $id => $row) {
            if ($row['parent_id'] && isset($map[$row['parent_id']])) {
                $map[$row['parent_id']]['children'][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }
        return Response::success($tree);
    }

    public function saveCategory(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $name = trim($request->getString('name'));
        $parentId = $request->getInt('parent_id');
        $sortOrder = $request->getInt('sort_order', 0);
        $status = $request->getInt('status', 1);

        if ($name === '') {
            return Response::error('分类名称不能为空', 400);
        }
        if (mb_strlen($name) > 50) {
            return Response::error('分类名称最多 50 个字符', 400);
        }
        if ($id && $parentId === $id) {
            return Response::error('不能选择自己作为父分类', 400);
        }
        if ($parentId) {
            $parent = CategoryRepository::findActiveCategoryId($parentId);
            if (!$parent) {
                return Response::error('父分类不存在或已禁用', 400);
            }
            if ($id) {
                $childIds = $this->getCategoryChildIds($id);
                if (in_array($parentId, $childIds, true)) {
                    return Response::error('不能选择子分类作为父分类', 400);
                }
            }
        }

        $data = [
            'name' => Security::purify($name),
            'parent_id' => $parentId ?: null,
            'sort_order' => $sortOrder,
            'status' => in_array($status, [0, 1], true) ? $status : 1,
            'updated_at' => Helper::now()
        ];

        if ($id) {
            CategoryRepository::updateCategoryFields($id, $data);
        } else {
            $data['created_at'] = Helper::now();
            CategoryRepository::insertCategory($data);
        }

        Cache::delete('site_settings');
        return Response::success(null, '保存成功');
    }

    public function deleteCategory(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        $children = CategoryRepository::countChildCategories($id);
        if ($children > 0) {
            return Response::error('请先删除该分类下的子分类', 400);
        }
        CategoryRepository::deleteCategoryById($id);
        Cache::delete('site_settings');
        return Response::success(null, '删除成功');
    }

    private function getCategoryChildIds(int $id): array
    {
        $ids = [];
        $rows = CategoryRepository::fetchChildCategoryIds($id);
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
            $ids = array_merge($ids, $this->getCategoryChildIds((int)$row['id']));
        }
        return $ids;
    }

}
