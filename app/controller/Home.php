<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;

class Home
{
    /**
     * 获取运营轮播图列表
     */
    public function getBanners()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取轮播图列表
            $banners = Db::name('banners')
                ->field('id, title, image_url, link_url, sort, status')
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'desc')
                ->select();

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => $banners]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取轮播图失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取公告列表
     */
    public function getAnnouncements()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $page = input('page', 1);
            $page = max(1, intval($page));
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));
            $offset = ($page - 1) * $limit;

            // 获取公告列表
            $announcementsQuery = Db::name('announcements')
                ->field('id, title, content, create_time, status')
                ->where('status', 1)
                ->order('create_time', 'desc');

            // 获取总数
            $total = $announcementsQuery->count();

            // 获取分页数据
            $announcements = $announcementsQuery->limit($offset, $limit)->select();

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $announcements,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取公告失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取活动入口列表
     */
    public function getActivityEntrance()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取活动入口列表
            $activities = Db::name('activities')
                ->field('id, name, icon_url, link_url, sort, status')
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'desc')
                ->select();

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => $activities]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取活动入口失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取首页热门推荐
     */
    public function getHotRecommendations()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $limit = input('limit', 10);
            $limit = max(1, min(50, intval($limit)));

            // 初始化推荐结果
            $recommendations = [];

            // 获取热门动态
            $hotMoments = Db::name('moments')
                ->field('id, user_id, nickname, avatar, content, images, location, likes, comments, create_time, visibility')
                ->where('status', 1)
                ->where('visibility', 1)
                ->order('likes', 'desc')
                ->order('create_time', 'desc')
                ->limit(intval($limit / 2))
                ->select();

            // 获取热门用户
            $hotUsers = Db::name('follows')
                ->alias('f')
                ->join('user u', 'f.following_id = u.id')
                ->group('f.following_id')
                ->field('u.id, u.username, u.nickname, u.avatar, u.bio, count(*) as follow_count')
                ->order('follow_count', 'desc')
                ->limit(intval($limit / 2))
                ->select();

            // 合并推荐结果
            $recommendations = [
                'hot_moments' => $hotMoments,
                'hot_users' => $hotUsers
            ];

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $recommendations
            ]);
        } catch (\Exception $e) {
            return $this->error('获取热门推荐失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取首页运营数据统计
     */
    public function getHomeStats()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取当前登录用户ID
            $currentUserId = session('user_id') ?? null;

            // 统计数据
            $stats = [
                'total_users' => Db::name('user')->count(),
                'total_moments' => Db::name('moments')->where('status', 1)->count(),
                'total_comments' => Db::name('moment_comments')->where('status', 1)->count(),
                'today_active_users' => Db::name('user_login_log')
                    ->where('login_time', '>=', strtotime(date('Y-m-d')))
                    ->count()
            ];

            // 如果用户已登录，添加个性化统计
            if ($currentUserId) {
                $stats['my_following_count'] = Db::name('follows')
                    ->where('follower_id', $currentUserId)
                    ->count();
                $stats['my_followers_count'] = Db::name('follows')
                    ->where('following_id', $currentUserId)
                    ->count();
                $stats['my_moments_count'] = Db::name('moments')
                    ->where('user_id', $currentUserId)
                    ->where('status', 1)
                    ->count();
                $stats['my_unread_notifications'] = Db::name('notifications')
                    ->where('user_id', $currentUserId)
                    ->where('is_read', 0)
                    ->where('status', 1)
                    ->count();
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return $this->error('获取首页统计数据失败: ' . $e->getMessage());
        }
    }
}
