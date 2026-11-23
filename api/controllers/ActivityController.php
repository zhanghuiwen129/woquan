<?php
/**
 * Hublog 活动控制器
 * V80：活动（报名制）列表、详情、报名/取消。
 * 注：打卡（checkin）功能已整体下线（见 Migration::migrate72 删除 checkins 表），
 *     原「打卡活动 / 我的打卡 / 打卡排行」一并移除；打卡请走「打卡帖」体系（PostController::checkinDo）。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\ActivityRepository;
use Hublog\Core\Auth;
use Hublog\Core\Helper;

class ActivityController extends BaseController
{
    /**
     * GET /api/activities — 活动列表
     * 参数：circle_id（限定圈子）、type（仅 signup）、status（默认 open）、keyword
     */
    public function list(Request $request): Response
    {
        $circleId = (int)$request->getInt('circle_id', 0);
        $type = $request->getString('type');
        if ($type !== 'signup') {
            $type = '';
        }
        $status = $request->getString('status', 'open');
        if (!in_array($status, ['open', 'closed', 'ended', ''], true)) {
            $status = 'open';
        }
        $keyword = trim((string)$request->getString('keyword'));

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20);
        $activities = ActivityRepository::fetchActivities($circleId, $type, $status, $keyword, $offset, $limit);
        $total = ActivityRepository::countActivities($circleId, $type, $status, $keyword);

        return Response::success(Helper::paginatedResponse($activities, $page, $limit, $total));
    }

    /**
     * GET /api/activity/detail?id=1 — 活动详情（含我的报名状态）
     */
    public function detail(Request $request): Response
    {
        $id = (int)$request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $activity = ActivityRepository::findActivityDetailById($id);
        if (!$activity) {
            return Response::error('活动不存在', 404);
        }

        $joinedCount = ActivityRepository::countJoinedRegistrations($id);

        $result = [
            'activity' => $activity,
            'joined_count' => $joinedCount,
            'joined' => false,
        ];

        $user = Auth::user($request);
        if (!empty($user['id'])) {
            $userId = (int)$user['id'];
            $result['joined'] = ActivityRepository::hasJoinedRegistration($id, $userId);
        }

        return Response::success($result);
    }

    /**
     * POST /api/activity/join — 报名（仅 signup 活动）
     */
    public function join(Request $request): Response
    {
        $user = Auth::check($request);
        $activityId = (int)$request->getInt('activity_id');
        if (!$activityId) {
            return Response::error('参数错误', 400);
        }

        $activity = ActivityRepository::findActivityById($activityId);
        if (!$activity) {
            return Response::error('活动不存在', 404);
        }
        if ($activity['status'] !== 'open') {
            return Response::error('该活动已截止，无法报名', 400);
        }
        if ($activity['type'] !== 'signup') {
            return Response::error('该活动不支持报名', 400);
        }
        if ($activity['start_at'] && strtotime($activity['start_at']) > time()) {
            return Response::error('活动尚未开始', 400);
        }

        $userId = (int)$user['id'];
        $exists = ActivityRepository::findRegistration($activityId, $userId);
        if ($exists && $exists['status'] === 'joined') {
            return Response::error('你已经报名过该活动', 409);
        }

        if ((int)$activity['capacity'] > 0) {
            $joined = ActivityRepository::countJoinedRegistrations($activityId);
            if ($joined >= (int)$activity['capacity']) {
                return Response::error('活动名额已满', 403);
            }
        }

        $note = mb_substr(trim((string)$request->getString('note')), 0, 255) ?: null;
        if ($exists) {
            ActivityRepository::updateRegistrationToJoined((int)$exists['id'], $note, Helper::now());
        } else {
            ActivityRepository::insertRegistration($activityId, $userId, $note);
        }

        return Response::success(
            ['joined' => true, 'activity_id' => $activityId],
            '报名成功'
        );
    }

    /**
     * POST /api/activity/leave — 取消报名
     */
    public function leave(Request $request): Response
    {
        $user = Auth::check($request);
        $activityId = (int)$request->getInt('activity_id');
        if (!$activityId) {
            return Response::error('参数错误', 400);
        }

        $affected = ActivityRepository::cancelRegistration(
            $activityId,
            (int)$user['id'],
            Helper::now()
        );
        if (!$affected) {
            return Response::error('你还没有报名该活动', 404);
        }
        return Response::success(null, '已取消报名');
    }

    /**
     * GET /api/activity/mine — 我报名的活动
     */
    public function mine(Request $request): Response
    {
        $user = Auth::check($request);

        $activities = ActivityRepository::fetchMyActivities((int)$user['id']);

        return Response::success([
            'activities' => $activities,
        ]);
    }
}
