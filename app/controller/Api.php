<?php

namespace app\controller;

use app\BaseController;
use app\model\Essay;
use app\model\User;
use think\facade\Request;
use think\facade\Cookie;
use think\facade\Db;

/**
 * API控制器类
 */
class Api extends BaseController
{

    /**
     * 首页文章列表API - 对应原api.php
     *
     * @return \think\response\Json
     */
    public function index()
    {
        $page = Request::post('page', 1);
        $essgs = 10; // 默认每页10条

        $essays = Essay::with(['user'])
            ->where('ptpaud', '<>', '0')
            ->where('ptpys', '<>', '0')
            ->order('id', 'desc')
            ->page($page, $essgs)
            ->select();

        return json([
            'code' => 200,
            'data' => $essays,
            'msg' => 'success'
        ]);
    }


    /**
     * 主页API - 对应原homeapi.php
     *
     * @return \think\response\Json
     */
    public function home()
    {
        $page = Request::post('page', 1);
        $getuser = Request::post('getuser', '');

        // 这里实现原homeapi.php的分页逻辑
        $query = Essay::with(['user'])
            ->where('ptpaud', '<>', '0')
            ->where('ptpys', '<>', '0');

        if (!empty($getuser)) {
            $query->where('ptpuser', $getuser);
        }

        $essays = $query->order('id', 'desc')
            ->page($page, 10)
            ->select();

        return json([
            'code' => 200,
            'data' => $essays,
            'msg' => 'success'
        ]);
    }

    /**
     * 用户登录API - 对应原login.php
     *
     * @return \think\response\Json
     */
    public function login()
    {
        $zh = Request::post('zh');
        $mm = Request::post('mm');

        if (empty($zh) || empty($mm)) {
            return json([
                'code' => 201,
                'msg' => '参数不完整'
            ]);
        }

        $user = User::login($zh, $mm);
        if ($user) {
            // 设置cookie
            Cookie::set('username', $user->username, 604800);
            Cookie::set('passid', $user->passid, 604800);

            return json([
                'code' => 200,
                'msg' => '登录成功',
                'data' => $user
            ]);
        }

        return json([
            'code' => 201,
            'msg' => '账号或密码错误'
        ]);
    }

    /**
     * 获取推荐用户列表 - 对应 /users/recommended
     *
     * @return \think\response\Json
     */
    public function getRecommendedUsers()
    {
        try {
            // 获取当前登录用户ID
            $currentUserId = Cookie::get('user_id');

            // 查询推荐用户（排除自己和已关注的用户）
            $recommendedUsers = User::field('id, username, nickname, avatar, bio, level, vip_level')
                ->where('status', 1);

            // 排除自己
            if ($currentUserId) {
                $recommendedUsers = $recommendedUsers->where('id', '<>', $currentUserId);
            }

            // 获取所有符合条件的用户ID
            $allUserIds = $recommendedUsers->column('id');

            // 如果已登录，排除已关注的用户
            if ($currentUserId && !empty($allUserIds)) {
                $followingIds = \app\model\Follow::where('follower_id', $currentUserId)
                    ->where('status', 1)
                    ->where('following_id', 'in', $allUserIds)
                    ->column('following_id');

                // 排除已关注的用户
                if (!empty($followingIds)) {
                    $allUserIds = array_diff($allUserIds, $followingIds);
                }
            }

            // 随机选取5个用户
            if (!empty($allUserIds)) {
                // 打乱数组并取前5个
                shuffle($allUserIds);
                $randomIds = array_slice($allUserIds, 0, 5);

                // 获取完整的用户信息
                $recommendedUsers = User::field('id, username, nickname, avatar, bio, level, vip_level')
                    ->where('id', 'in', $randomIds)
                    ->select()
                    ->toArray();
            } else {
                $recommendedUsers = [];
            }

            // 设置关注状态（都是未关注的）
            foreach ($recommendedUsers as &$user) {
                $user['is_following'] = false;
            }

            return json([
                'code' => 200,
                'data' => $recommendedUsers,
                'msg' => 'success'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取推荐用户失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户个人信息 - 对应 /user/getUserProfile
     *
     * @return \think\response\Json
     */
    public function getUserProfile()
    {
        try {
            $userId = Request::get('user_id');
            if (empty($userId)) {
                return json([
                    'code' => 400,
                    'msg' => '用户ID不能为空'
                ]);
            }

            $user = User::field('id, username, nickname, avatar, bio, gender, region, occupation, level, vip_level')
                ->where('id', $userId)
                ->where('status', 1)
                ->find();

            if (!$user) {
                return json([
                    'code' => 404,
                    'msg' => '用户不存在'
                ]);
            }

            return json([
                'code' => 200,
                'data' => $user,
                'msg' => 'success'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取用户信息失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取当前用户信息
     *
     * @return \think\response\Json
     */
    public function userInfo()
    {
        try {
            $userId = session('user_id') ?: cookie('user_id');

            if (!$userId) {
                return json([
                    'code' => 401,
                    'msg' => '未登录'
                ]);
            }

            if (!session('user_id') && $userId) {
                session('user_id', $userId);
                session('username', cookie('username') ?: '');
                session('nickname', cookie('nickname') ?: '');
                session('avatar', cookie('avatar') ?: '');
            }

            $user = User::field('id, username, nickname, avatar, bio, level, vip_level')
                ->where('id', $userId)
                ->where('status', 1)
                ->find();

            if (!$user) {
                return json([
                    'code' => 404,
                    'msg' => '用户不存在'
                ]);
            }

            $followingCount = \think\facade\Db::name('follows')
                ->where('follower_id', $userId)
                ->where('status', 1)
                ->count();

            $followersCount = \think\facade\Db::name('follows')
                ->where('following_id', $userId)
                ->where('status', 1)
                ->count();

            $postsCount = \think\facade\Db::name('moments')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->count();

            $favoritesCount = \think\facade\Db::name('favorites')
                ->where('user_id', $userId)
                ->count();

            $user['following'] = $followingCount;
            $user['followers'] = $followersCount;
            $user['posts'] = $postsCount;
            $user['favorites'] = $favoritesCount;

            return json([
                'code' => 200,
                'data' => $user,
                'msg' => 'success'
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取用户信息失败: ' . $e->getMessage()
            ]);
        }
    }
}
