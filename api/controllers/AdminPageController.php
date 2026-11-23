<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\Exception\HublogException;
use Hublog\Core\PageRepository;
use Hublog\Core\Request;
use Hublog\Core\Response;

/**
 * 后台通用单页管理（B2-1 / B2-3）
 *
 * 五个接口全部挂在 /api/admin 中间件组，鉴权由 requireAdmin() + 路由组 AdminMiddleware 双重保证。
 * slug 唯一（409）、非法（400）由 PageRepository 抛出并在此统一映射成对应 HTTP 码。
 */
class AdminPageController extends AdminController
{
    /** GET /api/admin/pages */
    public function list(Request $request): Response
    {
        $this->requireAdmin($request);
        $list = PageRepository::listAll();
        return Response::success(['list' => $list, 'total' => count($list)]);
    }

    /** POST /api/admin/pages */
    public function create(Request $request): Response
    {
        $this->requireAdmin($request);
        try {
            $id = PageRepository::save($this->pageData($request));
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        }
        return Response::success(['id' => $id], '创建成功');
    }

    /** PUT /api/admin/pages */
    public function update(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('参数错误', 400);
        }
        $data = $this->pageData($request);
        $data['id'] = $id;
        // 前端只改状态 / 正文时可不重复提交 slug
        if (($data['slug'] ?? '') === '') {
            $existing = PageRepository::findById($id);
            if ($existing === null) {
                return Response::error('页面不存在', 404);
            }
            $data['slug'] = (string)$existing['slug'];
            $data['title'] = (string)$existing['title'];
        }
        try {
            PageRepository::save($data);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        }
        return Response::success(['id' => $id], '更新成功');
    }

    /** DELETE /api/admin/pages */
    public function delete(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if ($id <= 0 || !PageRepository::delete($id)) {
            return Response::error('页面不存在', 404);
        }
        return Response::success(['deleted' => true], '删除成功');
    }

    /** POST /api/admin/pages/publish */
    public function publish(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $row = $id > 0 ? PageRepository::findById($id) : null;
        if ($row === null) {
            return Response::error('页面不存在', 404);
        }
        $status = $request->has('status') ? ($request->getBool('status') ? 1 : 0) : 1;
        try {
            PageRepository::save([
                'id' => $id,
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'content' => (string)($row['content'] ?? ''),
                'status' => $status,
                'template' => (string)($row['template'] ?? ''),
                'seo_title' => (string)($row['seo_title'] ?? ''),
                'seo_description' => (string)($row['seo_description'] ?? ''),
                'seo_keywords' => (string)($row['seo_keywords'] ?? ''),
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'source' => (string)($row['source'] ?? 'manual'),
            ]);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        }
        return Response::success(['slug' => (string)$row['slug'], 'status' => $status], '发布状态已更新');
    }

    /** @return array<string,mixed> */
    private function pageData(Request $request): array
    {
        return [
            'slug' => strtolower(trim($request->getString('slug'))),
            'title' => trim($request->getString('title')),
            'content' => $request->getString('content'),
            'status' => $request->getBool('status') ? 1 : 0,
            'template' => $request->getString('template'),
            'seo_title' => $request->getString('seo_title'),
            'seo_description' => $request->getString('seo_description'),
            'seo_keywords' => $request->getString('seo_keywords'),
            'sort_order' => $request->getInt('sort_order'),
        ];
    }
}
