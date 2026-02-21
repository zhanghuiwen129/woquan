<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Operation extends BaseFrontendController
{
    // 运营活动页面
    public function index()
    {
        // 获取当前登录用户信息（游客可访问）
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
            'currentUser' => $currentUser,
            'isLogin' => $isLogin
        ]);
        return View::fetch('index/operation');
    }
    // 获取运营活动列表
    public function getOperations()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $status = $_GET['status'] ?? null;
            $sort = $_GET['sort'] ?? 'time'; // time-时间排序, hot-热度排序
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $query = Db::name('operations')
                ->where(function($query) use ($status) {
                    if ($status !== null) {
                        $query->where('status', '=', $status);
                    }
                });

            // 根据排序方式排序
            if ($sort == 'hot') {
                $query->order('participant_count', 'desc');
            } else {
                $query->order('start_time', 'desc');
            }

            // 计算总记录数
            $total = $query->count();

            // 获取分页数据
            $operations = $query->limit($offset, $limit)->select();

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 处理活动数据
            if (!empty($operations)) {
                foreach ($operations as &$operation) {
                    // 更新活动状态
                    $now = time();
                    if ($operation['status'] == 1 && $now >= $operation['start_time'] && $now <= $operation['end_time']) {
                        $operation['status'] = 2;
                    } elseif ($operation['status'] == 1 && $now <= $operation['start_time']) {
                        $operation['status'] = 1;
                    } elseif (($operation['status'] == 1 || $operation['status'] == 2) && $now > $operation['end_time']) {
                        $operation['status'] = 3;
                    }

                    // 检查当前用户是否已参与
                    if ($userId) {
                        $isParticipated = Db::name('operation_participants')
                            ->where([
                                ['operation_id', '=', $operation['id']],
                                ['user_id', '=', $userId]
                            ])->find();
                        $operation['is_participated'] = $isParticipated ? 1 : 0;
                        $operation['participant_status'] = $isParticipated ? $isParticipated['status'] : 0;
                    } else {
                        $operation['is_participated'] = 0;
                        $operation['participant_status'] = 0;
                    }
                }
            }

            return $this->success([
                'list' => $operations,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取活动列表成功');

        } catch (\Exception $e) {
            return $this->error('获取活动列表失败: ' . $e->getMessage());
        }
    }

    // 获取运营活动详情
    public function getOperationDetail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取活动ID
            $operationId = $_GET['id'] ?? null;
            if (!$operationId) {
                return $this->badRequest('活动ID不能为空');
            }

            $operation = Db::name('operations')
                ->where('id', $operationId)
                ->find();

            if (!$operation) {
                return $this->notFound('活动不存在');
            }

            // 更新活动状态
            $now = time();
            if ($operation['status'] == 1 && $now >= $operation['start_time'] && $now <= $operation['end_time']) {
                $operation['status'] = 2;
            } elseif ($operation['status'] == 1 && $now <= $operation['start_time']) {
                $operation['status'] = 1;
            } elseif (($operation['status'] == 1 || $operation['status'] == 2) && $now > $operation['end_time']) {
                $operation['status'] = 3;
            }

            // 更新浏览人数
            Db::name('operations')
                ->where('id', $operationId)
                ->inc('view_count')
                ->update();

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 检查当前用户是否已参与
            if ($userId) {
                $participation = Db::name('operation_participants')
                    ->where([
                        ['operation_id', '=', $operationId],
                        ['user_id', '=', $userId]
                    ])->find();
                $operation['is_participated'] = $participation ? 1 : 0;
                $operation['participant_status'] = $participation ? $participation['status'] : 0;
            } else {
                $operation['is_participated'] = 0;
                $operation['participant_status'] = 0;
            }

            // 获取活动奖励信息
            $rewards = Db::name('operation_rewards')
                ->where('operation_id', $operationId)
                ->where('status', 1)
                ->select();

            $operation['rewards'] = $rewards;

            return $this->success($operation, '获取活动详情成功');

        } catch (\Exception $e) {
            return $this->error('获取活动详情失败: ' . $e->getMessage());
        }
    }

    // 参与运营活动
    public function participateOperation()
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

            $operationId = $_POST['operation_id'] ?? null;
            if (!$operationId) {
                return $this->badRequest('活动ID不能为空');
            }

            $operation = Db::name('operations')
                ->where('id', $operationId)
                ->find();

            if (!$operation) {
                return $this->notFound('活动不存在');
            }

            $now = time();
            if ($now < $operation['start_time']) {
                return $this->badRequest('活动尚未开始');
            }

            if ($now > $operation['end_time']) {
                return $this->badRequest('活动已结束');
            }

            if ($operation['status'] == 4) {
                return $this->badRequest('活动已下架');
            }

            $existingParticipation = Db::name('operation_participants')
                ->where([
                    ['operation_id', '=', $operationId],
                    ['user_id', '=', $userId]
                ])->find();

            if ($existingParticipation) {
                return $this->badRequest('已经参与该活动');
            }

            Db::startTrans();

            try {
                Db::name('operation_participants')->insert([
                    'operation_id' => $operationId,
                    'user_id' => $userId,
                    'participant_time' => time(),
                    'status' => 1
                ]);

                Db::name('operations')
                    ->where('id', $operationId)
                    ->inc('participant_count')
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

    // 获取用户参与的运营活动列表
    public function getUserOperations()
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

            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $offset = ($page - 1) * $limit;

            $participations = Db::name('operation_participants')
                ->alias('op')
                ->join('operations o', 'op.operation_id = o.id')
                ->where('op.user_id', $userId)
                ->order('op.participant_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 计算总记录数
            $total = Db::name('operation_participants')
                ->where('user_id', $userId)
                ->count();

            return $this->success([
                'list' => $participations,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ], '获取用户参与活动列表成功');

        } catch (\Exception $e) {
            return $this->error('获取用户参与活动列表失败: ' . $e->getMessage());
        }
    }

    // 创建运营活动（管理员功能）
    public function createOperation()
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

            if ($userId != 1) {
                return $this->forbidden('无权限创建活动');
            }

            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $cover = $_POST['cover'] ?? '';
            $startTime = $_POST['start_time'] ?? 0;
            $endTime = $_POST['end_time'] ?? 0;

            if (empty($title)) {
                return $this->badRequest('活动标题不能为空');
            }

            if (empty($description)) {
                return $this->badRequest('活动描述不能为空');
            }

            if (empty($cover)) {
                return $this->badRequest('活动封面不能为空');
            }
            }

            if (empty($startTime) || empty($endTime)) {
                return $this->badRequest('活动时间不能为空');
            }

            if ($startTime >= $endTime) {
                return $this->badRequest('开始时间必须小于结束时间');
            }

            Db::startTrans();

            try {
                $now = time();
                $operationId = Db::name('operations')->insertGetId([
                    'title' => $title,
                    'description' => $description,
                    'cover' => $cover,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'status' => $now < $startTime ? 1 : 2,
                    'participant_count' => 0,
                    'view_count' => 0,
                    'creator_id' => $userId,
                    'create_time' => $now,
                    'update_time' => $now
                ]);

                // 提交事务
                Db::commit();

                return $this->success(['operation_id' => $operationId], '创建活动成功');

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return $this->error('创建活动失败: ' . $e->getMessage());
        }
    }

    // 发放活动奖励（管理员功能）
    public function issueReward()
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

            if ($userId != 1) {
                return $this->forbidden('无权限发放奖励');
            }

            $operationId = $_POST['operation_id'] ?? null;
            $userId = $_POST['user_id'] ?? null;
            $rewardId = $_POST['reward_id'] ?? null;

            if (empty($operationId) || empty($userId) || empty($rewardId)) {
                return $this->badRequest('参数不能为空');
            }

            $reward = Db::name('operation_rewards')
                ->where('id', $rewardId)
                ->where('operation_id', $operationId)
                ->where('status', 1)
                ->find();

            if (!$reward) {
                return $this->notFound('奖励不存在');
            }

            if ($reward['remaining_quantity'] <= 0) {
                return $this->badRequest('奖励已发放完毕');
            }

            $participation = Db::name('operation_participants')
                ->where([
                    ['operation_id', '=', $operationId],
                    ['user_id', '=', $userId]
                ])->find();

            if (!$participation) {
                return $this->badRequest('用户未参与该活动');
            }

            // 开始事务
            Db::startTrans();

            try {
                // 发放奖励
                Db::name('operation_reward_records')->insert([
                    'operation_id' => $operationId,
                    'reward_id' => $rewardId,
                    'user_id' => $userId,
                    'reward_value' => $reward['value'],
                    'status' => 2,
                    'create_time' => time(),
                    'issue_time' => time()
                ]);

                // 更新奖励剩余数量
                Db::name('operation_rewards')
                    ->where('id', $rewardId)
                    ->dec('remaining_quantity')
                    ->update();

                // 更新用户参与状态
                Db::name('operation_participants')
                    ->where([
                        ['operation_id', '=', $operationId],
                        ['user_id', '=', $userId]
                    ])
                    ->update([
                        'status' => 3,
                        'reward_id' => $rewardId
                    ]);

                // 提交事务
                Db::commit();

                return $this->success(null, '奖励发放成功');

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return $this->error('奖励发放失败: ' . $e->getMessage());
        }
    }
}
