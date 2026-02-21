<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\Session;

class Activities extends BaseFrontendController
{
    /**
     * 初始化方法,确保 session 正确启动
     */
    protected function initialize()
    {
        parent::initialize();
        $userId = Session::get('user_id');
    }

    /**
     * 获取活动列表
     */
    public function index()
    {
        try {
            $currentUserId = Session::get('user_id') ?? null;

            // 获取筛选参数
            $type = (int) ($_GET['type'] ?? 0);
            $status = (int) ($_GET['status'] ?? 1);
            $isHot = (int) ($_GET['is_hot'] ?? -1);
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;

            // 查询活动
            $query = Db::name('activities');

            // 根据类型筛选
            if ($type > 0) {
                $query->where('type', $type);
            }

            // 根据状态筛选
            if ($status > 0) {
                $query->where('status', $status);
            } else {
                $query->where('status', 'in', [0, 1]);
            }

            // 根据热门筛选
            if ($isHot >= 0) {
                $query->where('is_hot', $isHot);
            }

            // 只显示未取消的活动
            $query->where('status', '<>', 3);

            // 排序
            $query->order('is_hot desc, sort asc, start_time desc');

            // 分页
            $query->limit($offset, $limit);

            $activities = $query->select()->toArray();

            // 获取总记录数
            $countQuery = clone $query;
            $totalCount = $countQuery->count();

            // 检查是否有更多数据
            $hasMore = (($page * $limit) < $totalCount);

            // 处理参与状态
            if ($currentUserId) {
                $participatedIds = Db::name('activity_participants')
                    ->where('user_id', $currentUserId)
                    ->column('activity_id');

                foreach ($activities as &$activity) {
                    $activity['is_participated'] = in_array($activity['id'], $participatedIds) ? 1 : 0;

                    $canParticipate = true;
                    if ($activity['status'] !== 1) {
                        $canParticipate = false;
                    }
                    if ($activity['max_participants'] > 0 && $activity['participant_count'] >= $activity['max_participants']) {
                        $canParticipate = false;
                    }
                    $activity['can_participate'] = $canParticipate ? 1 : 0;
                }
            } else {
                foreach ($activities as &$activity) {
                    $activity['is_participated'] = 0;
                    $activity['can_participate'] = 0;
                }
            }

            return $this->success([
                'list' => $activities,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'has_more' => $hasMore
            ]);

        } catch (\Exception $e) {
            return $this->error('获取活动列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取活动详情
     */
    public function detail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json; charset=utf-8');

            $currentUserId = Session::get('user_id') ?? null;
            $activityId = (int) ($_GET['id'] ?? 0);

            if (!$activityId) {
                return $this->badRequest('活动ID不能为空');
            }

            // 获取活动详情
            $activity = Db::name('activities')->where('id', $activityId)->find();

            if (!$activity) {
                return $this->notFound('活动不存在');
            }

            // 获取组织者信息
            $organizer = Db::name('user')
                ->where('id', $activity['organizer_id'])
                ->field('id, nickname, avatar')
                ->find();

            $activity['organizer'] = $organizer ?: ['nickname' => $activity['organizer_name'], 'avatar' => '/static/images/default-avatar.png'];

            // 检查用户是否已参与
            if ($currentUserId) {
                $participant = Db::name('activity_participants')
                    ->where('user_id', $currentUserId)
                    ->where('activity_id', $activityId)
                    ->find();
                $activity['is_participated'] = $participant ? 1 : 0;

                // 检查是否可以参与
                $canParticipate = true;
                if ($activity['status'] !== 1) {
                    $canParticipate = false;
                }
                if ($activity['max_participants'] > 0 && $activity['participant_count'] >= $activity['max_participants']) {
                    $canParticipate = false;
                }
                $activity['can_participate'] = $canParticipate ? 1 : 0;
            } else {
                $activity['is_participated'] = 0;
                $activity['can_participate'] = 1;
            }

            return $this->success($activity, '获取活动详情成功');

        } catch (\Exception $e) {
            return $this->error('获取活动详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 参与活动
     */
    public function participate()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['activity_id'])) {
                return $this->badRequest('活动ID不能为空');
            }

            $activityId = (int) $data['activity_id'];

            // 获取活动信息
            $activity = Db::name('activities')->where('id', $activityId)->find();

            if (!$activity) {
                return $this->notFound('活动不存在');
            }

            // 检查活动状态
            if ($activity['status'] !== 1) {
                return $this->badRequest('活动未进行中，无法参与');
            }

            // 检查是否已满员
            if ($activity['max_participants'] > 0 && $activity['participant_count'] >= $activity['max_participants']) {
                return $this->badRequest('活动已满员');
            }

            // 检查是否已参与
            $participant = Db::name('activity_participants')
                ->where('user_id', $userId)
                ->where('activity_id', $activityId)
                ->find();

            if ($participant) {
                return $this->badRequest('已参与过该活动');
            }

            // 开始事务
            Db::startTrans();

            try {
                // 添加参与记录
                Db::name('activity_participants')->insert([
                    'user_id' => $userId,
                    'activity_id' => $activityId,
                    'participant_time' => time()
                ]);

                // 更新活动参与人数
                Db::name('activities')
                    ->where('id', $activityId)
                    ->inc('participant_count', 1)
                    ->update();

                Db::commit();

                return $this->success(null, '参与活动成功');

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return $this->error('参与活动失败: ' . $e->getMessage());
        }
    }

    /**
     * 取消参与活动
     */
    public function cancelParticipate()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Allow-Credentials: true');
            header('Content-Type: application/json; charset=utf-8');

            $userId = Session::get('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['activity_id'])) {
                return $this->badRequest('活动ID不能为空');
            }

            $activityId = (int) $data['activity_id'];

            // 获取活动信息
            $activity = Db::name('activities')->where('id', $activityId)->find();

            if (!$activity) {
                return $this->notFound('活动不存在');
            }

            // 检查是否已参与
            $participant = Db::name('activity_participants')
                ->where('user_id', $userId)
                ->where('activity_id', $activityId)
                ->find();

            if (!$participant) {
                return $this->badRequest('未参与过该活动');
            }

            // 开始事务
            Db::startTrans();

            try {
                // 删除参与记录
                Db::name('activity_participants')
                    ->where('user_id', $userId)
                    ->where('activity_id', $activityId)
                    ->delete();

                // 更新活动参与人数
                Db::name('activities')
                    ->where('id', $activityId)
                    ->dec('participant_count', 1)
                    ->update();

                Db::commit();

                return $this->success(null, '取消参与成功');

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return $this->error('取消参与失败: ' . $e->getMessage());
        }
    }
}
