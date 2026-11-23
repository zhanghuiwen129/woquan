<?php
declare(strict_types=1);
/**
 * Hublog 后台「附件管理」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\UploadRepository;
use Hublog\Core\Auth;
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

class AdminAttachmentController extends AdminController
{
    public function attachments(Request $request): Response
    {
        $this->requireAdmin($request);
        $type = $request->getString('type', 'all');
        $keyword = $request->getString('keyword');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);
        $rows = UploadRepository::fetchAttachmentPage($type, $keyword, $offset, $limit);
        $total = (int)UploadRepository::countAttachments($type, $keyword);
        $items = array_map(function ($item) {
            $item['size_text'] = $this->formatSize($item['size']);
            // 去掉正文里的换行/多余空白，便于前台单行截断展示
            if (!empty($item['post_content'])) {
                $item['post_content'] = trim(preg_replace('/\s+/', ' ', $item['post_content']));
            }
            return $item;
        }, $rows);

        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    public function updateAttachmentStatus(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $status = $request->getInt('status');

        if (!$id || !in_array($status, [0, 1], true)) {
            return Response::error('参数错误', 400);
        }

        UploadRepository::updateAttachmentStatus($id, $status, Helper::now());
        $upload = UploadRepository::findAttachmentPostInfo($id);
        if ($upload && $upload['post_id']) {
            // P0-11：timeline 命名空间升版（原 clearCache('timeline_v2_') 是废弃前缀，清了等于没清）
            Cache::bumpNsVersion('timeline');
            Cache::delete("post_{$upload['post_id']}");
            Cache::delete("post_likes_{$upload['post_id']}");
            Cache::delete("post_comments_{$upload['post_id']}");
            Cache::delete("post_comments_raw_{$upload['post_id']}");
        }

        return Response::success(null, '操作成功');
    }

    public function deleteAttachment(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $upload = UploadRepository::findAttachmentForDelete($id);
        if ($upload) {
            // 只有不存在其他记录引用同一物理文件时才删除文件
            $refCount = UploadRepository::countUploadsByUrlExceptId($upload['url'], $id);
            if ($refCount === 0) {
                Upload::deleteUpload($upload['url']);
            }
            UploadRepository::deleteUploadById($id);
        }

        return Response::success(null, '删除成功');
    }


    /**
     * GET /api/admin/app-versions —— App 版本列表（后台「版本管理」页用）
     * V76：新增，配合后台发版表单，免去每次手动 INSERT app_versions。
     */
}
