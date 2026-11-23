<?php
/**
 * Hublog 用户举报控制器
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\ReportRepository;
use Hublog\Core\Auth;
use Hublog\Core\Helper;
use Hublog\Core\Security;

class ReportController extends BaseController
{
    private const ALLOWED_REASONS = ['spam', 'porn', 'abuse', 'other'];
    /**
     * V56：举报对象类型
     * circle = 圈子（垃圾圈子/违规圈主）、message = 私信（骚扰消息）
     * 这两类原本不可举报，用户只能忍着；后台处理按 target_type 分流，属正常 CRUD
     */
    private const ALLOWED_TARGET_TYPES = ['post', 'comment', 'user', 'circle', 'message'];

    /**
     * 提交举报
     */
    public function create(Request $request): Response
    {
        $user = Auth::check($request);

        $targetType = $request->getString('target_type');
        $targetId = $request->getInt('target_id');
        $reason = $request->getString('reason');
        $description = $request->getString('description');

        if (!in_array($targetType, self::ALLOWED_TARGET_TYPES, true)) {
            return Response::error('举报对象类型无效', 400);
        }
        if (!$targetId) {
            return Response::error('举报对象ID不能为空', 400);
        }
        if (!in_array($reason, self::ALLOWED_REASONS, true)) {
            return Response::error('举报原因无效', 400);
        }
        if ($description) {
            $description = Security::purify($description);
            if (mb_strlen($description) > 500) {
                return Response::error('补充说明不能超过500字', 400);
            }
        }

        // 验证举报对象是否存在
        // 目标存在性校验下沉：post / 通用对象 / 私信（撤回守卫）三条独立路径
        $exists = match ($targetType) {
            'post'    => ReportRepository::findPostTarget($targetId),
            'message' => ReportRepository::findUndeletedMessage($targetId),
            default   => ReportRepository::findTargetInTable($targetType . 's', $targetId),
        };
        if (!$exists) {
            return Response::error('举报对象不存在', 404);
        }

        // 防止重复举报同一对象
        $existing = ReportRepository::findPendingReport((int)$user['id'], $targetType, $targetId);
        if ($existing) {
            return Response::error('您已举报过该内容，请等待管理员处理', 400);
        }

        ReportRepository::insertReport(
            (int)$user['id'],
            $targetType,
            $targetId,
            $reason,
            $description ?: null,
            date('Y-m-d H:i:s')
        );

        return Response::success(null, '举报已提交，管理员将尽快处理');
    }

    /**
     * 举报列表（管理员）
     */
    public function list(Request $request): Response
    {
        $this->requireAdmin($request);
        $status = $request->getInt('status', -1);

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);

        $list = ReportRepository::fetchAdminReportPage($status, $offset, $limit);
        $total = ReportRepository::countAdminReports($status);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * 处理举报（管理员）
     */
    public function resolve(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        $status = $request->getInt('status');

        if (!$id || !in_array($status, [1, 2], true)) {
            return Response::error('参数错误', 400);
        }

        ReportRepository::updateReportStatus($id, $status);

        return Response::success(null, '举报已处理');
    }
}
