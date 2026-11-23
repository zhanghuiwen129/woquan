<?php

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\PageRepository;
use Hublog\Core\Request;
use Hublog\Core\Response;

/**
 * 公开单页读取（B2-2）
 *
 * GET /api/pages/{slug}：只返回 status=1 的页面；未命中 / 草稿直接 404，
 * 不回退到协议内容或硬编码缺省（那类兼容只在 /api/app/agreements 里保留）。
 */
class PageController extends BaseController
{
    public function show(Request $request): Response
    {
        $slug = strtolower(trim($request->getString('slug')));
        if (!PageRepository::isValidSlug($slug)) {
            return Response::error('页面不存在', 404);
        }
        $page = PageRepository::findBySlug($slug, true);
        if ($page === null) {
            return Response::error('页面不存在', 404);
        }

        // 字段集合严格固定，不把 id/status/source/sort_order 暴露给公开接口
        return Response::success([
            'slug' => (string)$page['slug'],
            'title' => (string)$page['title'],
            'content' => (string)($page['content'] ?? ''),
            'seo_title' => (string)($page['seo_title'] ?? ''),
            'seo_description' => (string)($page['seo_description'] ?? ''),
            'seo_keywords' => (string)($page['seo_keywords'] ?? ''),
            'updated_at' => (string)($page['updated_at'] ?? ''),
        ]);
    }
}
