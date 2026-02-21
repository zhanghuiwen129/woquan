<?php
namespace app\controller;

use think\facade\View;
use think\facade\Request;
use think\facade\Session;
use think\facade\Cookie;
use Exception;

class Index extends BaseFrontendController
{
    public function index()
    {
        // 检查登录状态 - 优先使用session，如果session为空则使用cookie
        $userId = Session::get('user_id') ?: Cookie::get('user_id');
        $isLogin = !empty($userId);

        // 获取当前登录用户信息
        $currentUser = null;
        if ($isLogin) {
            $currentUser = [
                'id' => $userId,
                'username' => Session::get('username', '') ?: Cookie::get('username', ''),
                'nickname' => Session::get('nickname', '') ?: Cookie::get('nickname', ''),
                'avatar' => Session::get('avatar', '') ?: Cookie::get('avatar', '')
            ];
        }

        // 获取动态列表
        $moments = $this->getMoments($userId);
        $recommendedUsers = $this->getRecommendedUsers($userId);
        $hotTopics = $this->getHotTopics();
        $activities = $this->getActivities();
        $onlineUsers = $this->getOnlineUsers();

        // 传递数据到模板（配置信息已在基类中加载）
        View::assign([
            'isLogin' => $isLogin,
            'currentUser' => $currentUser,
            'moments' => $moments,
            'recommendedUsers' => $recommendedUsers,
            'hotTopics' => $hotTopics,
            'activities' => $activities,
            'onlineUsers' => $onlineUsers
        ]);

        // 检测设备类型，返回对应的模板
        $userAgent = Request::header('user-agent');
        $isMobile = $this->isMobileDevice($userAgent);

        if ($isMobile) {
            // 移动端使用独立模板
            return View::fetch('index/index_mobile');
        } else {
            // PC端使用独立模板
            return View::fetch('index/index_pc');
        }
    }

    /**
     * 检测是否为移动设备
     */
    private function isMobileDevice($userAgent)
    {
        $mobileAgents = [
            'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 
            'Windows Phone', 'webOS', 'Opera Mini', 'IEMobile'
        ];

        foreach ($mobileAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取推荐关注用户
     */
    private function getRecommendedUsers($userId = null)
    {
        try {
            $query = \think\facade\Db::name('user')
                ->field('*')
                ->where('status', 1)
                ->order('create_time', 'desc')
                ->limit(10);

            $recommendedUsers = $query->select()->toArray();

            // 如果当前用户已登录，为每个推荐用户添加 is_following 字段
            if ($userId) {
                $followingIds = \think\facade\Db::name('follows')
                    ->where('follower_id', $userId)
                    ->where('status', 1)
                    ->column('following_id');

                foreach ($recommendedUsers as &$user) {
                    $user['is_following'] = in_array($user['id'], $followingIds);
                }
            } else {
                foreach ($recommendedUsers as &$user) {
                    $user['is_following'] = false;
                }
            }

            return $recommendedUsers;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取热门话题
     */
    private function getHotTopics()
    {
        try {
            // 查询话题表，按动态数量排序
            $topics = \think\facade\Db::name('topics')
                ->field('*')
                ->where('status', 1)
                ->order('moment_count', 'desc')
                ->limit(10)
                ->select()
                ->toArray();

            return $topics;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取活动列表
     */
    private function getActivities()
    {
        try {
            // 查询活动表，按创建时间倒序
            $activities = \think\facade\Db::name('activities')
                ->field('*')
                ->where('status', 1)
                ->where('start_time', '>', date('Y-m-d H:i:s'))
                ->order('start_time', 'asc')
                ->limit(5)
                ->select()
                ->toArray();

            return $activities;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取在线用户
     */
    private function getOnlineUsers()
    {
        try {
            // 查询最近5分钟内有操作的用户
            $onlineTime = date('Y-m-d H:i:s', strtotime('-5 minutes'));
            $onlineUsers = \think\facade\Db::name('user')
                ->field('id, nickname, username, avatar')
                ->where('status', 1)
                ->where('last_active_time', '>', $onlineTime)
                ->limit(20)
                ->select()
                ->toArray();

            return $onlineUsers;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取动态列表
     */
    private function getMoments($userId = null)
    {
        try {
            $query = \think\facade\Db::name('moments')
                ->alias('m')
                ->join('user u', 'm.user_id = u.id', 'LEFT')
                ->field('m.id as moment_id, m.user_id, u.nickname, u.username, u.avatar, m.content, m.images, m.videos as video, m.likes as like_count, m.comments as comment_count, m.create_time')
                ->where('m.status', 1);

            // 根据登录状态过滤
            if ($userId) {
                $followingIds = \think\facade\Db::name('follows')
                    ->where('follower_id', $userId)
                    ->where('status', 1)
                    ->column('following_id');

                $query->where(function($q) use ($userId, $followingIds) {
                    $q->where('m.privacy', 1)
                      ->whereOr('m.user_id', $userId)
                      ->whereOr(function($subQ) use ($followingIds) {
                          $subQ->where('m.privacy', 3)->whereIn('m.user_id', $followingIds);
                      });
                });
            } else {
                $query->where('m.privacy', 1);
            }

            $moments = $query->order('m.create_time desc')->limit(20)->select()->toArray();

            // 处理图片数据
            foreach ($moments as &$moment) {
                $moment['is_author'] = ($userId == $moment['user_id']);
                $moment['image_count'] = 0;

                if (!empty($moment['images'])) {
                    $images = is_array($moment['images']) ? $moment['images'] : json_decode($moment['images'], true);
                    if (is_array($images)) {
                        $moment['images'] = array_map(function($img) {
                            return is_array($img) ? $img : ['url' => $img];
                        }, $images);
                        $moment['image_count'] = count($images);
                    } else {
                        $moment['images'] = [];
                    }
                } else {
                    $moment['images'] = [];
                }

                // 视频处理
                if (!empty($moment['video'])) {
                    $videos = is_array($moment['video']) ? $moment['video'] : json_decode($moment['video'], true);
                    if (is_array($videos) && !empty($videos)) {
                        $moment['video'] = $videos[0];
                    }
                }

                // 话题标签
                $moment['topics'] = [];
            }

            // 检查当前用户对每条动态的点赞状态和关注状态
            if ($userId) {
                foreach ($moments as &$moment) {
                    // 检查是否点赞
                    $isLiked = \think\facade\Db::name('likes')
                        ->where('user_id', $userId)
                        ->where('target_id', $moment['moment_id'])
                        ->where('target_type', 1)
                        ->find();
                    $moment['is_liked'] = $isLiked ? 1 : 0;

                    // 检查是否关注
                    $isFollowed = \think\facade\Db::name('follows')
                        ->where('follower_id', $userId)
                        ->where('following_id', $moment['user_id'])
                        ->find();
                    $moment['is_followed'] = $isFollowed ? 1 : 0;
                }
            } else {
                foreach ($moments as &$moment) {
                    $moment['is_liked'] = 0;
                    $moment['is_followed'] = 0;
                }
            }

            return $moments;

        } catch (\Exception $e) {
            return [];
        }
    }

    // 获取网站配置 API
    public function siteConfig()
    {
        // 多层次清空输出缓冲,防止BOM和其他输出干扰
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 重新开启输出缓冲
        ob_start();

        try {
            $siteConfig = \app\model\SystemConfig::getAllConfigs();
            $config = [];
            foreach ($siteConfig as $item) {
                if (is_object($item)) {
                    $key = $item->config_key;
                    $value = $item->config_value;
                } elseif (is_array($item)) {
                    $key = $item['config_key'];
                    $value = $item['config_value'];
                }

                // 清除 BOM 头和其他控制字符
                if (is_string($value)) {
                    // 移除 UTF-8 BOM (EF BB BF)
                    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
                    // 移除 UTF-16 BE BOM (FE FF)
                    $value = preg_replace('/^\xFE\xFF/', '', $value);
                    // 移除 UTF-16 LE BOM (FF FE)
                    $value = preg_replace('/^\xFF\xFE/', '', $value);
                    // 移除 UTF-32 BE BOM (00 00 FE FF)
                    $value = preg_replace('/^\x00\x00\xFE\xFF/', '', $value);
                    // 移除 UTF-32 LE BOM (FF FE 00 00)
                    $value = preg_replace('/^\xFF\xFE\x00\x00/', '', $value);
                    // 移除其他控制字符
                    $value = preg_replace('/^[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $value);
                    // 移除所有空白字符
                    $value = trim($value);
                }

                $config[$key] = $value;
            }

            // 使用 ThinkPHP 的 JSON 响应,自动处理 BOM 问题
            $response = json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'name' => $config['site_name'] ?? '我圈社交平台',
                    'subtitle' => $config['site_subtitle'] ?? '连接你我，分享精彩',
                    'logo' => $config['site_logo'] ?? '',
                    'icon' => $config['site_favicon'] ?? '',
                    'sign' => $config['site_sign'] ?? '',
                    'copyright' => $config['site_copyright'] ?? '',
                    'beian' => $config['site_icp'] ?? '',
                    'homimg' => $config['site_homimg'] ?? '',
                    'message_enabled' => $config['message_enabled'] ?? '1',
                    'topic_enabled' => $config['topic_enabled'] ?? '1',
                    'follow_enabled' => $config['follow_enabled'] ?? '1',
                    'comment_enabled' => $config['comment_enabled'] ?? '1',
                    'like_enabled' => $config['like_enabled'] ?? '1',
                    'group_enabled' => $config['group_enabled'] ?? '1'
                ]
            ]);

            // 确保响应头没有BOM
            $response->header(['Content-Type' => 'application/json; charset=utf-8']);

            return $response;
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取配置失败',
                'data' => [
                    'name' => '我圈社交平台',
                    'subtitle' => '连接你我，分享精彩'
                ]
            ])->header(['Content-Type' => 'application/json; charset=utf-8']);
        }
    }

    public function jsErrorLog()
    {
        try {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?: Request::post();
            
            $errorData = [
                'message' => $input['message'] ?? 'Unknown error',
                'stack' => $input['stack'] ?? '',
                'url' => $input['url'] ?? '',
                'user_agent' => $input['userAgent'] ?? '',
                'timestamp' => $input['timestamp'] ?? date('Y-m-d H:i:s'),
                'context' => $input['context'] ?? []
            ];

            $logMessage = sprintf(
                "JavaScript Error: %s\nURL: %s\nUser Agent: %s\nContext: %s\nStack: %s",
                $errorData['message'],
                $errorData['url'],
                $errorData['user_agent'],
                json_encode($errorData['context'], JSON_UNESCAPED_UNICODE),
                $errorData['stack']
            );

            \think\facade\Log::error($logMessage);

            return $this->success(null, 'logged');
        } catch (\Exception $e) {
            return $this->error('failed: ' . $e->getMessage());
        }
    }
    
    public function home()
    {
        // 对应原home.php用户主页
        return View::fetch('home');
    }
    
    public function view($cid)
    {
        // 对应原view.php文章详情
        View::assign('cid', $cid);
        return View::fetch('view');
    }
    
    public function edit()
    {
        // 对应原edit.php发布文章
        return View::fetch('edit');
    }
    
    public function archives()
    {
        // 对应原archives.php用户档案
        return View::fetch('archives');
    }
    
    public function setup()
    {
        // 对应原setup.php用户设置
        return View::fetch('setup');
    }
    
    public function mobile()
    {
        // 移动端模板
        return View::fetch('mobile');
    }

    public function pc()
    {
        // PC端模板
        return View::fetch('pc');
    }

    public function profile()
    {
        try {
            // 从Session获取当前登录用户ID（Auth中间件已经验证过）
            $userId = session('user_id');

            // 从数据库获取当前登录用户信息
            $user = \think\facade\Db::name('user')
                ->where('id', $userId)
                ->field('id,username,nickname,avatar,email,real_name,gender,birthday,occupation,bio')
                ->find();

            if (!$user) {
                // 用户不存在，清除session并跳转到登录页
                session(null);
                cookie('user_id', null);
                return redirect('/login');
            }

            $currentUser = [
                'id' => $user['id'],
                'username' => $user['username'] ?? '',
                'nickname' => $user['nickname'] ?? $user['username'] ?? '',
                'avatar' => $user['avatar'] ?? '',
                'bio' => $user['bio'] ?? ''
            ];

            // 设置基本的网站配置（避免依赖SystemConfig模型）
            $siteName = '我圈社交平台';
            $siteSubtitle = '连接你我，分享精彩';

            View::assign([
                'currentUser' => $currentUser,
                'isLogin' => true,
                'current_url' => '/profile',
                'name' => $siteName,
                'subtitle' => $siteSubtitle
            ]);

            return View::fetch('index/profile');
        } catch (\Exception $e) {
            // 记录详细错误信息
            error_log('Profile页面加载失败: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());

            // 返回简单的错误页面
            return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>错误</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; text-align: center; }
        .error { color: #f53f3f; }
        .info { color: #666; margin-top: 20px; }
        a { color: #165dff; text-decoration: none; }
    </style>
</head>
<body>
    <h1 class="error">加载个人资料失败</h1>
    <p class="info">错误信息: ' . htmlspecialchars($e->getMessage()) . '</p>
    <p class="info"><a href="/">返回首页</a> | <a href="/login">重新登录</a></p>
</body>
</html>';
        }
    }

    public function userMoments()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        // 如果未登录，跳转到登录页
        if (!$isLogin) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/user-moments');
        return View::fetch('user-moments');
    }

    public function discover()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;

        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/discover');
        return View::fetch('discover');
    }

    public function favorites()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            // 未登录跳转到登录页
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 查询收藏列表（这里假设有个favorites表，如果没有请修改）
        $favorites = [];
        try {
            $favorites = \think\facade\Db::name('favorites')
                ->alias('f')
                ->join('user u', 'f.user_id = u.id')
                ->where('f.follower_id', $userId)
                ->field('u.id as user_id, u.username, u.nickname, u.avatar, u.bio, f.create_time')
                ->order('f.create_time', 'desc')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            // 如果favorites表不存在，返回空数组
            $favorites = [];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('favorites', $favorites);
        View::assign('current_url', '/favorites');
        return View::fetch('index/favorites');
    }

    public function visitors()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 查询访客列表
        $visitors = [];
        try {
            $visitors = \think\facade\Db::name('card_visitors')
                ->alias('cv')
                ->join('user u', 'cv.visitor_id = u.id')
                ->where('cv.user_id', $userId)
                ->field('cv.visitor_id, cv.visit_time, u.username, u.nickname, u.avatar, u.bio, u.level, u.vip_level')
                ->order('cv.visit_time', 'desc')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            // 如果card_visitors表不存在，返回空数组
            $visitors = [];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('visitors', $visitors);
        View::assign('current_url', '/visitors');
        return View::fetch('index/visitors');
    }

    public function mentions()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/mentions');
        return View::fetch('index/mentions');
    }

    public function settings()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/settings');
        return View::fetch('index/settings');
    }

    public function loginLogs()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        // 如果未登录，跳转到登录页
        if (!$isLogin) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/login-logs');
        return View::fetch('index/login-logs');
    }

    public function searchHistory()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/search-history');
        return View::fetch('index/search-history');
    }

    public function chat()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$isLogin) {
            return redirect('/login');
        }

        // 从数据库获取当前登录用户信息
        $currentUser = \think\facade\Db::name('user')
            ->where('id', $userId)
            ->field('id,username,nickname,avatar,email,real_name,gender,birthday,occupation,bio')
            ->find();

        if (!$currentUser) {
            return redirect('/login');
        }

        // 获取聊天对象ID（从URL参数中获取，默认为1）
        $targetUserId = input('target_id/d', 1);

        // 获取聊天对象信息
        $targetUser = [];
        if ($targetUserId) {
            $targetUser = \think\facade\Db::name('user')
                ->where('id', $targetUserId)
                ->field('id,username,nickname,avatar,level,bio')
                ->find();
        }

        // 检查是否安装了 Swoole WebSocket 扩展
        $enableWebSocket = extension_loaded('swoole');

        View::assign([
            'currentUser' => $currentUser,
            'targetUser' => $targetUser,
            'targetUserId' => $targetUserId,
            'isLogin' => $isLogin,
            'current_url' => '/chat',
            'enableWebSocket' => $enableWebSocket
        ]);
        return View::fetch('index/chat');
    }

    public function search()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;

        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/search');
        return View::fetch('search');
    }

    public function topic()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;

        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/topic');
        return View::fetch('topic');
    }

    public function error404()
    {
        return View::fetch('404');
    }

    public function test()
    {
        return View::fetch('test');
    }

    public function register()
    {
        return View::fetch('user/register');
    }

    public function login()
    {
        return View::fetch('user/login');
    }

    public function hello($name = 'ThinkPHP6')
    {
        return 'hello,' . $name;
    }

    // 个人名片页面
    public function card($id)
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);
        $currentUser = null;
        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => $isLogin,
            'targetUserId' => $id,
            'current_url' => '/card/' . $id
        ]);

        return View::fetch('index/card');
    }

    // 名片设置页面
    public function cardSettings()
    {
        try {
            // 检查登录状态
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return redirect('/login');
            }

            // 获取当前登录用户信息
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', !empty($userId));
        View::assign('current_url', '/card-settings');
        return View::fetch('index/card-settings');
        } catch (Exception $e) {
            // 记录错误日志
            error_log('Card Settings Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine());
            // 返回错误页面
            return response('500 Internal Server Error: ' . $e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }
    }

    // 名片访客页面
    public function cardVisitors()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/card-visitors');
        return View::fetch('index/card-visitors');
    }

    // 积分中心页面
    public function points()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/points');
        return View::fetch('index/points');
    }

    // 等级中心页面
    public function levels()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/levels');
        return View::fetch('index/levels');
    }

    // 主题中心页面
    public function themes()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/themes');
        return View::fetch('index/themes');
    }

    // 帮助中心/FAQ页面
    public function faq()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/faq');
        return View::fetch('index/faq');
    }

    // 草稿箱页面
    public function drafts()
    {
        // 检查登录状态
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        // 获取当前登录用户信息
        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/drafts');
        return View::fetch('index/drafts');
    }

    // 搜索结果页面
    public function searchResults()
    {
        // 搜索结果页面不需要强制登录
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        $currentUser = null;
        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/search-results');
        return View::fetch('index/search-results');
    }

    public function articleList()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('name', '我圈社交平台');
        View::assign('subtitle', '连接你我，分享精彩');
        View::assign('current_url', '/articles');
        View::assign('page_title', '文章列表');
        return View::fetch('index/article/index');
    }

    public function articlePublish()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('name', '我圈社交平台');
        View::assign('subtitle', '连接你我，分享精彩');
        View::assign('current_url', '/articles/publish');
        View::assign('page_title', '发布文章');
        return View::fetch('index/article/publish');
    }

    public function articleDetail()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        $currentUser = null;
        if ($userId) {
            $currentUser = [
                'id' => $userId,
                'username' => session('username', '') ?: cookie('username', ''),
                'nickname' => session('nickname', '') ?: cookie('nickname', ''),
                'avatar' => session('avatar', '') ?: cookie('avatar', '')
            ];
        }

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/article');
        return View::fetch('index/article/detail');
    }

    public function articleEdit()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        $articleId = Request::param('id', 0);

        $article = null;
        if ($articleId > 0) {
            $article = \think\facade\Db::name('articles')
                ->where('id', $articleId)
                ->where('user_id', $userId)
                ->find();
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/article/edit');
        View::assign('article', $article);
        View::assign('article_id', $articleId);
        return View::fetch('index/article/edit');
    }

    public function articleDrafts()
    {
        $userId = session('user_id') ?: cookie('user_id');
        $isLogin = !empty($userId);

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        View::assign('currentUser', $currentUser);
        View::assign('isLogin', $isLogin);
        View::assign('current_url', '/article/drafts');
        return View::fetch('index/article-drafts');
    }
}
