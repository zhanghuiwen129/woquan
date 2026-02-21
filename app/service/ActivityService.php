<?php
namespace app\service;

use think\facade\Db;
use think\facade\Session;

/**
 * 活动服务类
 */
class ActivityService
{
    /**
     * 获取活动列表
     * @param array $params 查询参数
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getActivityList($params = [], $currentUserId = null)
    {
        $type = $params['type'] ?? 0; // 活动类型：0-全部，1-线上活动，2-线下活动
        $status = $params['status'] ?? 1; // 活动状态：0-未发布，1-进行中，2-已结束，3-已取消
        $isHot = $params['is_hot'] ?? -1; // 是否热门：-1-全部，0-否，1-是
        $keyword = $params['keyword'] ?? ''; // 搜索关键词
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 构建查询
        $query = Db::name('activities')
            ->where('status', '>', 0); // 排除未发布的活动
        
        // 按类型筛选
        if ($type > 0) {
            $query->where('type', $type);
        }
        
        // 按状态筛选
        if ($status >= 0) {
            $query->where('status', $status);
        }
        
        // 按热门筛选
        if ($isHot >= 0) {
            $query->where('is_hot', $isHot);
        }
        
        // 按关键词搜索
        if (!empty($keyword)) {
            $escapedKeyword = Db::escape($keyword);
            $query->where('title', 'like', '%' . $escapedKeyword . '%');
        }
        
        // 计算总记录数
        $total = $query->count();
        
        // 获取分页数据
        $activities = $query->order('is_hot', 'desc')
                            ->order('start_time', 'asc')
                            ->order('create_time', 'desc')
                            ->limit($offset, $limit)
                            ->select();
        
        // 检查用户是否已参与活动
        if (!empty($activities) && $currentUserId) {
            foreach ($activities as &$activity) {
                $isParticipated = Db::name('activity_participants')
                    ->where([
                        ['activity_id', '=', $activity['id']],
                        ['user_id', '=', $currentUserId],
                        ['status', '=', 1]
                    ])->find();
                $activity['is_participated'] = $isParticipated ? 1 : 0;
            }
        }
        
        return [
            'list' => $activities,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }
    
    /**
     * 获取热门活动
     * @param int $limit 数量限制
     * @param int|null $currentUserId 当前用户ID
     * @return array
     */
    public static function getHotActivities($limit = 10, $currentUserId = null)
    {
        // 查询热门活动
        $activities = Db::name('activities')
            ->where('status', 1) // 只显示进行中的活动
            ->where('is_hot', 1) // 热门活动
            ->order('sort', 'asc')
            ->order('create_time', 'desc')
            ->limit($limit)
            ->select();
        
        // 检查用户是否已参与活动
        if (!empty($activities) && $currentUserId) {
            foreach ($activities as &$activity) {
                $isParticipated = Db::name('activity_participants')
                    ->where([
                        ['activity_id', '=', $activity['id']],
                        ['user_id', '=', $currentUserId],
                        ['status', '=', 1]
                    ])->find();
                $activity['is_participated'] = $isParticipated ? 1 : 0;
            }
        }
        
        return $activities;
    }
    
    /**
     * 获取活动详情
     * @param int $activityId 活动ID
     * @param int|null $currentUserId 当前用户ID
     * @return array|null
     */
    public static function getActivityDetail($activityId, $currentUserId = null)
    {
        // 获取活动详情
        $activity = Db::name('activities')->where('id', $activityId)->find();
        
        if (!$activity) {
            return null;
        }
        
        // 获取活动参与人数
        $participantCount = Db::name('activity_participants')
            ->where([
                ['activity_id', '=', $activityId],
                ['status', '=', 1]
            ])->count();
        $activity['actual_participant_count'] = $participantCount;

        // 检查用户是否已参与活动
        if ($currentUserId) {
            $isParticipated = Db::name('activity_participants')
                ->where([
                    ['activity_id', '=', $activityId],
                    ['user_id', '=', $currentUserId],
                    ['status', '=', 1]
                ])->find();
            $activity['is_participated'] = $isParticipated ? 1 : 0;
        }
        
        return $activity;
    }
    
    /**
     * 参与活动
     * @param int $activityId 活动ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function participateActivity($activityId, $userId)
    {
        // 获取活动信息
        $activity = Db::name('activities')->where('id', $activityId)->find();
        
        if (!$activity) {
            return false;
        }
        
        // 检查活动状态
        if ($activity['status'] != 1) {
            return false;
        }
        
        // 检查活动是否已满
        if ($activity['max_participants'] > 0) {
            $participantCount = Db::name('activity_participants')
                ->where([
                    ['activity_id', '=', $activityId],
                    ['status', '=', 1]
                ])->count();

            if ($participantCount >= $activity['max_participants']) {
                return false;
            }
        }

        // 检查是否已经参与
        $existingParticipation = Db::name('activity_participants')
            ->where([
                ['activity_id', '=', $activityId],
                ['user_id', '=', $userId]
            ])->find();
        
        if ($existingParticipation) {
            if ($existingParticipation['status'] == 1) {
                return true; // 已经参与
            } else {
                // 恢复参与
                return Db::name('activity_participants')
                    ->where('id', $existingParticipation['id'])
                    ->update([
                        'status' => 1,
                        'participant_time' => time()
                    ]) > 0;
            }
        } else {
            // 获取用户信息
            $user = Db::name('user')->where('id', $userId)->field('nickname, avatar')->find();

            if (!$user) {
                return false;
            }

            // 开始事务
            Db::startTrans();

            try {
                // 插入参与记录
                $participationData = [
                    'activity_id' => $activityId,
                    'user_id' => $userId,
                    'nickname' => $user['nickname'] ?? '用户',
                    'avatar' => $user['avatar'] ?? '/static/images/default-avatar.png',
                    'participant_time' => time(),
                    'status' => 1
                ];

                $result = Db::name('activity_participants')->insert($participationData);

                if (!$result) {
                    Db::rollback();
                    return false;
                }

                // 更新活动参与人数
                $participantCount = Db::name('activity_participants')
                    ->where([
                        ['activity_id', '=', $activityId],
                        ['status', '=', 1]
                    ])->count();
                
                $updateResult = Db::name('activities')
                    ->where('id', $activityId)
                    ->update(['participant_count' => $participantCount]);
                
                if (!$updateResult) {
                    Db::rollback();
                    return false;
                }
                
                // 提交事务
                Db::commit();
                return true;
            } catch (Exception $e) {
                Db::rollback();
                return false;
            }
        }
    }
    
    /**
     * 取消参与活动
     * @param int $activityId 活动ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function cancelParticipation($activityId, $userId)
    {
        // 检查是否已经参与
        $existingParticipation = Db::name('pt_activity_participants')
            ->where([
                ['activity_id', '=', $activityId],
                ['user_id', '=', $userId],
                ['status', '=', 1]
            ])->find();
        
        if (!$existingParticipation) {
            return true; // 未参与，直接返回成功
        }
        
        // 开始事务
        Db::startTrans();
        
        try {
            // 更新参与状态
            $result = Db::name('activity_participants')
                ->where('id', $existingParticipation['id'])
                ->update(['status' => 2]);

            if (!$result) {
                Db::rollback();
                return false;
            }

            // 更新活动参与人数
            $participantCount = Db::name('activity_participants')
                ->where([
                    ['activity_id', '=', $activityId],
                    ['status', '=', 1]
                ])->count();
            
            $updateResult = Db::name('activities')
                ->where('id', $activityId)
                ->update(['participant_count' => $participantCount]);
            
            if (!$updateResult) {
                Db::rollback();
                return false;
            }
            
            // 提交事务
            Db::commit();
            return true;
        } catch (Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    /**
     * 获取我的活动列表
     * @param int $userId 用户ID
     * @param int $status 参与状态：1-已参与，2-已取消
     * @return array
     */
    public static function getMyActivities($userId, $status = 1)
    {
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        
        // 查询我的活动
        $query = Db::name('activity_participants')
            ->alias('ap')
            ->join('activities a', 'ap.activity_id = a.id', 'LEFT')
            ->where('ap.user_id', $userId)
            ->where('ap.status', $status)
            ->field('a.*, ap.status as participation_status, ap.participant_time');
        
        // 计算总记录数
        $total = $query->count();
        
        // 获取分页数据
        $activities = $query->order('ap.participant_time', 'desc')
                            ->limit($offset, $limit)
                            ->select();
        
        return [
            'list' => $activities,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }
}
