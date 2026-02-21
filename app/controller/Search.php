<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Search extends BaseFrontendController
{
    // 搜索页面
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
            'isLogin' => $isLogin,
            'current_url' => '/search'
        ]);
        return View::fetch('index/search');
    }
    // 通用搜索功能
    public function generalSearch()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取搜索参数
            $keyword = Db::escape(input('get.keyword', ''));
            if (empty($keyword)) {
                return $this->badRequest('请输入搜索关键词');
            }

            $type = Db::escape(input('get.type', 'all'));
            $sort = Db::escape(input('get.sort', 'time'));
            $page = (int)input('get.page', 1);
            $limit = (int)input('get.limit', 10);
            $offset = ($page - 1) * $limit;

            // 获取当前登录用户ID
            $userId = session('user_id') ?? null;

            // 记录搜索历史
            $this->recordSearchHistory($userId, $keyword);

            // 增加热搜榜计数
            $this->updateHotSearch($keyword);

            // 根据搜索类型执行不同的搜索逻辑
            switch ($type) {
                case 'moment':
                    $result = $this->searchMoments($keyword, $userId, $sort, $offset, $limit);
                    break;
                case 'user':
                    $result = $this->searchUsers($keyword, $sort, $offset, $limit);
                    break;
                case 'topic':
                    $result = $this->searchTopics($keyword, $sort, $offset, $limit);
                    break;
                case 'activity':
                    $result = $this->searchActivities($keyword, $sort, $offset, $limit);
                    break;
                default:
                    $result = $this->searchAll($keyword, $userId, $sort, $offset, $limit);
                    break;
            }

            return $this->success($result, '搜索成功');

        } catch (\Exception $e) {
            return $this->error('搜索失败: ' . $e->getMessage());
        }
    }

    // 搜索动态
    private function searchMoments($keyword, $userId, $sort, $offset, $limit)
    {
        // 获取用户的好友列表
        $friends = [];
        if ($userId) {
            $friends = Db::name('follows')
                ->where('follower_id', $userId)
                ->where('status', 1)
                ->column('following_id');
        }

        // 构建查询条件
        $query = Db::name('moments')
            ->where('status', 1)
            ->where(function($query) use ($keyword) {
                $query->where('content', 'like', '%' . $keyword . '%');
            })
            ->where(function($query) use ($userId, $friends) {
                // 只显示公开的、好友的或自己的动态
                $query->where('privacy', 1) // 公开动态
                      ->whereOr('user_id', $userId) // 自己的动态
                      ->whereOr(function($query) use ($friends) {
                          $query->where('privacy', 3) // 仅好友可见
                                ->whereIn('user_id', $friends);
                      });
            });

        // 根据排序方式排序
        if ($sort == 'hot') {
            $query->orderRaw('(likes * 2 + comments) desc')
                  ->order('create_time', 'desc');
        } else {
            $query->order('create_time', 'desc');
        }

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

        return [
            'type' => 'moment',
            'list' => $moments,
            'total' => $total,
            'page' => ceil(($offset + 1) / $limit),
            'limit' => $limit
        ];
    }

    // 搜索用户
    private function searchUsers($keyword, $sort, $offset, $limit)
    {
        // 构建查询条件
        $query = Db::name('user')
            ->where('status', 1)
            ->where(function($query) use ($keyword) {
                $query->where('nickname', 'like', '%' . $keyword . '%')
                      ->whereOr('username', 'like', '%' . $keyword . '%');
            });

        // 根据排序方式排序
        if ($sort == 'hot') {
            $query->order('follower_count', 'desc');
        } else {
            $query->order('create_time', 'desc');
        }

        // 计算总记录数
        $total = $query->count();

        // 获取分页数据
        $users = $query->field('id, nickname, username, avatar, level, bio, follower_count, following_count, moments_count, create_time as register_time')
                      ->limit($offset, $limit)
                      ->select();

        // 获取当前登录用户ID
        $userId = session('user_id') ?? null;

        // 检查是否已关注
        if (!empty($users) && $userId) {
            foreach ($users as &$user) {
                $isFollowing = Db::name('follows')
                    ->where([
                        ['follower_id', '=', $userId],
                        ['following_id', '=', $user['id']],
                        ['status', '=', 1]
                    ])->find();
                $user['is_following'] = $isFollowing ? 1 : 0;
            }
        }

        return [
            'type' => 'user',
            'list' => $users,
            'total' => $total,
            'page' => ceil(($offset + 1) / $limit),
            'limit' => $limit
        ];
    }

    // 搜索话题
    private function searchTopics($keyword, $sort, $offset, $limit)
    {
        // 构建查询条件
        $query = Db::name('topics')
            ->where('status', 1)
            ->where('name', 'like', '%' . $keyword . '%');

        // 根据排序方式排序
        if ($sort == 'hot') {
            $query->order('follow_count', 'desc');
        } else {
            $query->order('create_time', 'desc');
        }

        // 计算总记录数
        $total = $query->count();

        // 获取分页数据
        $topics = $query->field('id, name, description, cover_image, follow_count, is_hot, create_time')
                       ->limit($offset, $limit)
                       ->select();

        return [
            'type' => 'topic',
            'list' => $topics,
            'total' => $total,
            'page' => ceil(($offset + 1) / $limit),
            'limit' => $limit
        ];
    }

    // 搜索活动
    private function searchActivities($keyword, $sort, $offset, $limit)
    {
        // 构建查询条件
        $query = Db::name('operations')
            ->where('status', 1)
            ->where('title', 'like', '%' . $keyword . '%');

        // 根据排序方式排序
        if ($sort == 'hot') {
            $query->order('participant_count', 'desc');
        } else {
            $query->order('start_time', 'asc');
        }

        // 计算总记录数
        $total = $query->count();

        // 获取分页数据
        $activities = $query->limit($offset, $limit)->select();

        return [
            'type' => 'activity',
            'list' => $activities,
            'total' => $total,
            'page' => ceil(($offset + 1) / $limit),
            'limit' => $limit
        ];
    }

    // 搜索全部内容
    private function searchAll($keyword, $userId, $sort, $offset, $limit)
    {
        // 搜索动态
        $momentsResult = $this->searchMoments($keyword, $userId, $sort, 0, min(3, $limit));
        
        // 搜索用户
        $usersResult = $this->searchUsers($keyword, $sort, 0, min(3, $limit));
        
        // 搜索话题
        $topicsResult = $this->searchTopics($keyword, $sort, 0, min(3, $limit));
        
        // 搜索活动
        $activitiesResult = $this->searchActivities($keyword, $sort, 0, min(3, $limit));

        return [
            'type' => 'all',
            'moments' => [
                'list' => $momentsResult['list'],
                'total' => $momentsResult['total']
            ],
            'users' => [
                'list' => $usersResult['list'],
                'total' => $usersResult['total']
            ],
            'topics' => [
                'list' => $topicsResult['list'],
                'total' => $topicsResult['total']
            ],
            'activities' => [
                'list' => $activitiesResult['list'],
                'total' => $activitiesResult['total']
            ]
        ];
    }

    // 获取搜索历史
    public function getSearchHistory()
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

            $history = Db::name('search_history')
                ->where('user_id', $userId)
                ->order('search_time', 'desc')
                ->limit(20)
                ->column('keyword');

            return $this->success($history, '获取搜索历史成功');

        } catch (\Exception $e) {
            return $this->error('获取搜索历史失败: ' . $e->getMessage());
        }
    }

    // 清除搜索历史
    public function clearSearchHistory()
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

            $result = Db::name('search_history')
                ->where('user_id', $userId)
                ->delete();

            if (!$result) {
                return $this->error('清除搜索历史失败');
            }

            return $this->success(null, '清除搜索历史成功');

        } catch (\Exception $e) {
            return $this->error('清除搜索历史失败: ' . $e->getMessage());
        }
    }

    // 获取热搜榜
    public function getHotSearches()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取参数
            $limit = $_GET['limit'] ?? 20;
            $sortBy = $_GET['sort_by'] ?? 'today'; // today-今日热门, total-总热门

            // 构建查询条件
            $query = Db::name('hot_searches')
                ->where('search_count', '>', 0);

            // 根据排序方式排序
            if ($sortBy == 'today') {
                $query->order('today_count', 'desc');
            } else {
                $query->order('search_count', 'desc');
            }

            // 获取热搜榜数据
            $hotSearches = $query->field('keyword, search_count, today_count, yesterday_count, is_hot')
                                ->limit($limit)
                                ->select();

            return $this->success($hotSearches, '获取热搜榜成功');

        } catch (\Exception $e) {
            return $this->error('获取热搜榜失败: ' . $e->getMessage());
        }
    }

    // 记录搜索历史
    private function recordSearchHistory($userId, $keyword)
    {
        if (!$userId || empty($keyword)) {
            return false;
        }

        try {
            // 检查是否已存在该搜索历史
            $existingHistory = Db::name('search_history')
                ->where([
                    ['user_id', '=', $userId],
                    ['keyword', '=', $keyword]
                ])->find();

            $now = time();

            if ($existingHistory) {
                // 更新搜索历史
                Db::name('search_history')
                    ->where('id', $existingHistory['id'])
                    ->update([
                        'search_time' => $now,
                        'search_count' => Db::raw('search_count + 1')
                    ]);
            } else {
                // 添加新的搜索历史
                Db::name('search_history')->insert([
                    'user_id' => $userId,
                    'keyword' => $keyword,
                    'search_time' => $now
                ]);
            }

            return true;
        } catch (\Exception $e) {
            // 记录错误日志
            error_log('记录搜索历史失败: ' . $e->getMessage());
            return false;
        }
    }

    // 更新热搜榜
    private function updateHotSearch($keyword)
    {
        if (empty($keyword)) {
            return false;
        }

        try {
            // 检查是否已存在该热搜词
            $existingHotSearch = Db::name('hot_searches')
                ->where('keyword', $keyword)
                ->find();

            $now = time();
            $today = strtotime(date('Y-m-d'));

            if ($existingHotSearch) {
                // 更新热搜词计数
                $updateData = [
                    'search_count' => Db::raw('search_count + 1'),
                    'update_time' => $now
                ];

                // 如果是今天的搜索，增加今日计数
                if ($existingHotSearch['update_time'] >= $today) {
                    $updateData['today_count'] = Db::raw('today_count + 1');
                } else {
                    // 新的一天，重置今日计数，保存昨日计数
                    $updateData['yesterday_count'] = $existingHotSearch['today_count'];
                    $updateData['today_count'] = 1;
                }

                Db::name('hot_searches')
                    ->where('id', $existingHotSearch['id'])
                    ->update($updateData);
            } else {
                // 添加新的热搜词
                Db::name('hot_searches')->insert([
                    'keyword' => $keyword,
                    'search_count' => 1,
                    'today_count' => 1,
                    'yesterday_count' => 0,
                    'update_time' => $now
                ]);
            }

            return true;
        } catch (\Exception $e) {
            // 记录错误日志
            error_log('更新热搜榜失败: ' . $e->getMessage());
            return false;
        }
    }
}
