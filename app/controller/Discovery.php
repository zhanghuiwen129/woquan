<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Discovery extends BaseFrontendController
{
    // 发现页
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        // 配置信息已在基类中加载
        View::assign([
            'isLogin' => $isLogin,
            'currentUser' => $currentUser,
            'current_url' => '/discover',
            'page_title' => '发现'
        ]);
        return View::fetch('index/discover');
    }

    // 活动页面
    public function activities()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        // 配置信息已在基类中加载
        View::assign([
            'isLogin' => $isLogin,
            'currentUser' => $currentUser,
            'current_url' => '/activities',
            'page_title' => '活动'
        ]);
        return View::fetch('index/activities');
    }

    // 获取活动列表
    public function getActivityList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取筛选参数
            $type = $_GET['type'] ?? 0; // 活动类型：0-全部，1-线上活动，2-线下活动
            $status = $_GET['status'] ?? 1; // 活动状态：0-未发布，1-进行中，2-已结束，3-已取消
            $isHot = $_GET['is_hot'] ?? -1; // 是否热门：-1-全部，0-否，1-是
            $keyword = $_GET['keyword'] ?? ''; // 搜索关键词

            // 获取分页参数
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
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
                $query->where('title', 'like', '%' . $keyword . '%');
            }

            // 计算总记录数
            $total = $query->count();

            // 获取分页数据
            $activities = $query->order('is_hot', 'desc')
                                ->order('start_time', 'asc')
                                ->order('create_time', 'desc')
                                ->limit($offset, $limit)
                                ->select();

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 检查用户是否已参与活动
            if (!empty($activities) && $userId) {
                foreach ($activities as &$activity) {
                    $isParticipated = Db::name('activity_participants')
                        ->where([
                            ['activity_id', '=', $activity['id']],
                            ['user_id', '=', $userId],
                            ['status', '=', 1]
                        ])->find();
                    $activity['is_participated'] = $isParticipated ? 1 : 0;
                }
            }

            return $this->success([
                'list' => $activities,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取活动列表成功');

        } catch (\Exception $e) {
            return $this->error('获取活动列表失败: ' . $e->getMessage());
        }
    }

    // 获取热门活动
    public function getHotActivities()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取参数
            $limit = $_GET['limit'] ?? 10;

            // 查询热门活动
            $activities = Db::name('activities')
                ->where('status', 1) // 只显示进行中的活动
                ->where('is_hot', 1) // 热门活动
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->limit($limit)
                ->select();

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 检查用户是否已参与活动
            if (!empty($activities) && $userId) {
                foreach ($activities as &$activity) {
                    $isParticipated = Db::name('activity_participants')
                        ->where([
                            ['activity_id', '=', $activity['id']],
                            ['user_id', '=', $userId],
                            ['status', '=', 1]
                        ])->find();
                    $activity['is_participated'] = $isParticipated ? 1 : 0;
                }
            }

            return $this->success($activities, '获取热门活动成功');

        } catch (\Exception $e) {
            return $this->error('获取热门活动失败: ' . $e->getMessage());
        }
    }

    // 获取活动详情
    public function getActivityDetail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取活动ID
            $activityId = $_GET['activity_id'] ?? null;
            if (!$activityId) {
                return $this->badRequest('缺少活动ID');
            }

            $activity = Db::name('activities')->where('id', $activityId)->find();
            if (!$activity) {
                return $this->notFound('活动不存在');
            }

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 检查用户是否已参与活动
            if ($userId) {
                $isParticipated = Db::name('activity_participants')
                    ->where([
                        ['activity_id', '=', $activityId],
                        ['user_id', '=', $userId],
                        ['status', '=', 1]
                    ])->find();
                $activity['is_participated'] = $isParticipated ? 1 : 0;
            }

            // 获取活动参与人数
            $participantCount = Db::name('activity_participants')
                ->where([
                    ['activity_id', '=', $activityId],
                    ['status', '=', 1]
                ])->count();
            $activity['actual_participant_count'] = $participantCount;

            return $this->success($activity, '获取活动详情成功');

        } catch (\Exception $e) {
            return $this->error('获取活动详情失败: ' . $e->getMessage());
        }
    }

    // 参与活动
    public function participateActivity()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['activity_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $activityId = $data['activity_id'];

            $activity = Db::name('activities')->where('id', $activityId)->find();
            if (!$activity) {
                return $this->notFound('活动不存在');
            }

            if ($activity['status'] != 1) {
                return $this->badRequest('活动已结束或未开始');
            }

            if ($activity['max_participants'] > 0) {
                $participantCount = Db::name('activity_participants')
                    ->where([
                        ['activity_id', '=', $activityId],
                        ['status', '=', 1]
                    ])->count();

                if ($participantCount >= $activity['max_participants']) {
                    return $this->badRequest('活动参与人数已满');
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
                    return $this->badRequest('您已经参与了该活动');
                } else {
                    $result = Db::name('activity_participants')
                        ->where('id', $existingParticipation['id'])
                        ->update([
                            'status' => 1,
                            'participant_time' => time()
                        ]);
                }
            } else {
                // 获取用户信息
                $user = Db::name('user')->where('id', $userId)->field('nickname, avatar')->find();

                // 开始事务
                Db::startTrans();

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
                    return $this->error('参与活动失败');
                }
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
                return $this->error('更新参与人数失败');
            }

            Db::commit();

            return $this->success(null, '参与活动成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('参与活动失败: ' . $e->getMessage());
        }
    }

    // 取消参与活动
    public function cancelParticipation()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (!$data || !isset($data['activity_id'])) {
                return $this->badRequest('无效的请求数据');
            }

            $activityId = $data['activity_id'];

            // 检查是否已经参与
            $existingParticipation = Db::name('activity_participants')
                ->where([
                    ['activity_id', '=', $activityId],
                    ['user_id', '=', $userId],
                    ['status', '=', 1]
                ])->find();

            if (!$existingParticipation) {
                return $this->badRequest('您未参与该活动');
            }

            Db::startTrans();

            $result = Db::name('activity_participants')
                ->where('id', $existingParticipation['id'])
                ->update(['status' => 2]);

            if (!$result) {
                Db::rollback();
                return $this->error('取消参与失败');
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
                return $this->error('更新参与人数失败');
            }

            Db::commit();

            return $this->success(null, '取消参与成功');

        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('取消参与失败: ' . $e->getMessage());
        }
    }

    // 获取我的活动列表
    public function getMyActivities()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;
            if (!$userId) {
                return $this->unauthorized();
            }

            $status = $_GET['status'] ?? 1;

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;

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

            return $this->success([
                'list' => $activities,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取我的活动列表成功');

        } catch (\Exception $e) {
            return $this->error('获取我的活动列表失败: ' . $e->getMessage());
        }
    }

    // 获取热门动态
    public function getHotMoments()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取分页参数
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
            $offset = ($page - 1) * $limit;

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 获取用户的好友列表
            $friends = [];
            if ($userId) {
                $friends = Db::name('follows')
                    ->where('user_id', $userId)
                    ->where('status', 1)
                    ->column('friend_id');
            }

            // 查询热门动态（按点赞数和评论数排序）
            $query = Db::name('moments')
                ->where('status', 1)
                ->where(function($query) use ($userId, $friends) {
                    // 只显示公开的、好友的或自己的动态
                    $query->where('privacy', 1) // 公开动态
                          ->whereOr('user_id', $userId) // 自己的动态
                          ->whereOr(function($query) use ($friends) {
                              $query->where('privacy', 3) // 仅好友可见
                                    ->whereIn('user_id', $friends);
                          });
                })
                ->orderRaw('(likes * 2 + comments) desc') // 综合热度排序
                ->order('create_time', 'desc');

            // 计算总记录数
            $total = $query->count();

            // 获取分页数据
            $moments = $query->limit($offset, $limit)->select();

            // 处理动态数据
            if (!empty($moments)) {
                foreach ($moments as &$moment) {
                    // 检查当前用户是否已点赞
                    if ($userId) {
                        $isLiked = Db::name('likes')
                            ->where('user_id', $userId)
                            ->where('target_id', $moment['id'])
                            ->where('target_type', 1)
                            ->find();
                        $moment['is_liked'] = $isLiked ? 1 : 0;

                        // 检查当前用户是否已收藏
                        $isCollected = Db::name('collections')
                            ->where([
                                ['moment_id', '=', $moment['id']],
                                ['user_id', '=', $userId]
                            ])->find();
                        $moment['is_collected'] = $isCollected ? 1 : 0;
                    } else {
                        $moment['is_liked'] = 0;
                        $moment['is_collected'] = 0;
                    }

                    // 格式化时间
                    $moment['publish_time'] = $moment['create_time'];
                }
            }

            return $this->success([
                'list' => $moments,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取热门动态成功');

        } catch (\Exception $e) {
            return $this->error('获取热门动态失败: ' . $e->getMessage());
        }
    }
}
