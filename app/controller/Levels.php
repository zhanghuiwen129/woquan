<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Levels extends BaseFrontendController
{
    // 等级页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId),
            'current_url' => '/levels'
        ]);
        return View::fetch('index/levels');
    }
    /**
     * 获取用户等级信息
     */
    public function getUserLevel()
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

            // 获取用户积分和等级信息
            $userPoints = Db::name('user_points')
                ->where('user_id', $userId)
                ->find();

            // 如果没有积分记录，创建初始记录
            if (!$userPoints) {
                $userPoints = [
                    'user_id' => $userId,
                    'total_points' => 0,
                    'available_points' => 0,
                    'frozen_points' => 0,
                    'level' => 1,
                    'create_time' => time(),
                    'update_time' => time()
                ];

                // 插入初始积分记录
                Db::name('user_points')->insert($userPoints);
            }

            // 获取当前等级信息
            $currentLevel = Db::name('user_level')
                ->where('level', $userPoints['level'])
                ->find();

            // 如果当前等级不存在，使用默认等级
            if (!$currentLevel) {
                $currentLevel = [
                    'level' => 1,
                    'name' => '新手',
                    'required_points' => 0,
                    'icon' => '',
                    'description' => '新注册用户',
                    'privileges' => json_encode([])
                ];
            }

            // 获取下一级等级信息
            $nextLevel = Db::name('user_level')
                ->where('required_points', '>', $userPoints['total_points'])
                ->order('required_points', 'asc')
                ->find();

            // 计算升级进度
            $progress = 0;
            if ($nextLevel) {
                $currentLevelPoints = $userPoints['total_points'] - $currentLevel['required_points'];
                $levelRange = $nextLevel['required_points'] - $currentLevel['required_points'];
                $progress = round(($currentLevelPoints / $levelRange) * 100);
            } else {
                $progress = 100;
            }

            $result = [
                'user_id' => $userId,
                'current_level' => [
                    'level' => $currentLevel['level'],
                    'name' => $currentLevel['name'],
                    'icon' => $currentLevel['icon'],
                    'description' => $currentLevel['description'],
                    'privileges' => json_decode($currentLevel['privileges'], true)
                ],
                'next_level' => $nextLevel ? [
                    'level' => $nextLevel['level'],
                    'name' => $nextLevel['name'],
                    'required_points' => $nextLevel['required_points']
                ] : null,
                'total_points' => $userPoints['total_points'],
                'current_level_points' => $userPoints['total_points'] - $currentLevel['required_points'],
                'next_level_need_points' => $nextLevel ? ($nextLevel['required_points'] - $userPoints['total_points']) : 0,
                'progress' => $progress
            ];

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->error('获取用户等级信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取等级列表
     */
    public function getLevelList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 获取所有等级列表
            $levels = Db::name('user_level')
                ->field('level, name, required_points, icon, description, privileges')
                ->order('level', 'asc')
                ->select();

            // 如果没有等级配置，使用默认等级
            if (empty($levels)) {
                $levels = [
                    [
                        'level' => 1,
                        'name' => '新手',
                        'required_points' => 0,
                        'icon' => '',
                        'description' => '新注册用户',
                        'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论'])
                    ],
                    [
                        'level' => 2,
                        'name' => '活跃用户',
                        'required_points' => 100,
                        'icon' => '',
                        'description' => '活跃参与社区',
                        'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以创建话题'])
                    ],
                    [
                        'level' => 3,
                        'name' => '达人',
                        'required_points' => 500,
                        'icon' => '',
                        'description' => '社区达人',
                        'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论', '可以创建话题', '可以设置动态置顶'])
                    ]
                ];
            } else {
                // 解析权限JSON
                foreach ($levels as &$level) {
                    $level['privileges'] = json_decode($level['privileges'], true);
                }
            }

            $result = ['list' => $levels];

            // 如果用户已登录，添加用户当前等级标记
            if ($userId) {
                // 获取用户积分和等级信息
                $userPoints = Db::name('user_points')
                    ->where('user_id', $userId)
                    ->find();

                if ($userPoints) {
                    $result['current_level'] = $userPoints['level'];
                    $result['total_points'] = $userPoints['total_points'];
                }
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $this->error('获取等级列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取等级特权
     */
    public function getLevelPrivileges()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 获取请求参数
            $level = input('level', 0, 'intval');

            // 如果没有指定等级，且用户已登录，使用用户当前等级
            if (!$level && $userId) {
                $userPoints = Db::name('user_points')
                    ->where('user_id', $userId)
                    ->find();

                if ($userPoints) {
                    $level = $userPoints['level'];
                }
            }

            // 如果没有指定等级，使用默认等级
            if (!$level) {
                $level = 1;
            }

            // 获取指定等级信息
            $levelInfo = Db::name('user_level')
                ->where('level', $level)
                ->find();

            if (!$levelInfo) {
                // 如果等级不存在，使用默认等级
                $levelInfo = [
                    'level' => 1,
                    'name' => '新手',
                    'required_points' => 0,
                    'icon' => '',
                    'description' => '新注册用户',
                    'privileges' => json_encode(['可以发布动态', '可以关注用户', '可以评论'])
                ];
            }

            // 解析权限JSON
            $levelInfo['privileges'] = json_decode($levelInfo['privileges'], true);

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $levelInfo
            ]);
        } catch (\Exception $e) {
            return $this->error('获取等级特权失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取等级升级记录
     */
    public function getLevelUpgradeRecords()
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

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $offset = ($page - 1) * $limit;

            // 获取升级记录总数
            $total = Db::name('user_level_log')
                ->where('user_id', $userId)
                ->count();

            // 获取升级记录列表
            $records = Db::name('user_level_log')
                ->where('user_id', $userId)
                ->field('id, old_level, new_level, upgrade_time, reason')
                ->order('upgrade_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 格式化时间
            foreach ($records as &$record) {
                $record['upgrade_time'] = date('Y-m-d H:i:s', $record['upgrade_time']);
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $records,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取等级升级记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取等级成长规则
     */
    public function getLevelRules()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取等级成长规则
            $rules = Db::name('level_growth_rule')
                ->field('id, action, points, description, limit_daily, limit_total')
                ->where('status', 1)
                ->order('sort', 'asc')
                ->select();

            // 如果没有规则配置，使用默认规则
            if (empty($rules)) {
                $rules = [
                    [
                        'id' => 1,
                        'action' => 'register',
                        'points' => 10,
                        'description' => '注册账号',
                        'limit_daily' => 1,
                        'limit_total' => 1
                    ],
                    [
                        'id' => 2,
                        'action' => 'login',
                        'points' => 1,
                        'description' => '每日登录',
                        'limit_daily' => 1,
                        'limit_total' => 0
                    ],
                    [
                        'id' => 3,
                        'action' => 'publish_moment',
                        'points' => 5,
                        'description' => '发布动态',
                        'limit_daily' => 5,
                        'limit_total' => 0
                    ],
                    [
                        'id' => 4,
                        'action' => 'comment',
                        'points' => 2,
                        'description' => '评论',
                        'limit_daily' => 10,
                        'limit_total' => 0
                    ],
                    [
                        'id' => 5,
                        'action' => 'like',
                        'points' => 1,
                        'description' => '点赞',
                        'limit_daily' => 20,
                        'limit_total' => 0
                    ],
                    [
                        'id' => 6,
                        'action' => 'follow',
                        'points' => 2,
                        'description' => '关注用户',
                        'limit_daily' => 10,
                        'limit_total' => 0
                    ]
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => $rules]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取等级成长规则失败: ' . $e->getMessage());
        }
    }
}
