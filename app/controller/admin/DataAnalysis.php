<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Request;
use think\facade\Db;
use think\facade\Redirect;

/**
 * 数据统计分析控制器
 */
class DataAnalysis extends AdminController
{
    // 需要管理员认证
    public function initialize()
    {
        parent::initialize();
        if (!Session::has('admin_id')) {
            return Redirect::to('/admin/login');
        }
    }

    // 数据统计分析首页
    public function index()
    {
        // 获取核心数据指标
        $coreMetrics = $this->getCoreMetrics();
        
        View::assign('core_metrics', $coreMetrics);
        View::assign('admin_name', Session::get('admin_name'));
        return View::fetch('admin/dataanalysis_index');
    }

    // 核心数据指标统计
    private function getCoreMetrics()
    {
        // 总用户数
        $totalUsers = Db::name('user')->count();
        
        // 今日新增用户数
        $today = strtotime(date('Y-m-d'));
        $todayUsers = Db::name('user')->where('create_time', '>=', $today)->count();
        
        // 总动态数
        $totalMoments = Db::name('moments')->count();
        
        // 今日新增动态数
        $todayMoments = Db::name('moments')->where('create_time', '>=', $today)->count();
        
        // 总评论数
        $totalComments = Db::name('comments')->count();
        
        // 今日新增评论数
        $todayComments = Db::name('comments')->where('create_time', '>=', $today)->count();
        
        // 总点赞数
        $totalLikes = Db::name('likes')->where('target_type', 1)->count();
        
        // 今日新增点赞数
        $todayLikes = Db::name('likes')->where('target_type', 1)->where('create_time', '>=', $today)->count();
        
        // 总话题数
        $totalTopics = Db::name('topics')->count();
        
        // 总活动数
        $totalActivities = Db::name('operations')->count();
        
        // 总举报数
        $totalReports = Db::name('reports')->count();
        
        // 待处理举报数
        $pendingReports = Db::name('reports')->where('status', 0)->count();
        
        return [
            'total_users' => $totalUsers,
            'today_users' => $todayUsers,
            'total_moments' => $totalMoments,
            'today_moments' => $todayMoments,
            'total_comments' => $totalComments,
            'today_comments' => $todayComments,
            'total_likes' => $totalLikes,
            'today_likes' => $todayLikes,
            'total_topics' => $totalTopics,
            'total_activities' => $totalActivities,
            'total_reports' => $totalReports,
            'pending_reports' => $pendingReports
        ];
    }

    // 内容数据统计
    public function contentStatistics()
    {
        $start_time = Request::param('start_time', date('Y-m-d', strtotime('-7 day')));
        $end_time = Request::param('end_time', date('Y-m-d'));
        
        // 内容发布趋势
        $contentTrend = $this->getContentTrend($start_time, $end_time);
        
        // 内容类型分布
        $contentTypeDistribution = $this->getContentTypeDistribution($start_time, $end_time);
        
        // 热门话题排行
        $hotTopics = $this->getHotTopics($start_time, $end_time);
        
        View::assign('start_time', $start_time);
        View::assign('end_time', $end_time);
        View::assign('content_trend', $contentTrend);
        View::assign('content_type_distribution', $contentTypeDistribution);
        View::assign('hot_topics', $hotTopics);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/dataanalysis_content');
    }

    // 内容发布趋势
    private function getContentTrend($startTime, $endTime)
    {
        $start = strtotime($startTime);
        $end = strtotime($endTime) + 86399;
        
        // 生成日期数组
        $dateArray = $this->generateDateArray($startTime, $endTime);
        
        // 查询每天的动态数
        $moments = Db::name('moments')
            ->field("DATE_FORMAT(FROM_UNIXTIME(create_time), '%Y-%m-%d') as date, COUNT(*) as count")
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->group('date')
            ->select();
        
        // 查询每天的评论数
        $comments = Db::name('comments')
            ->field("DATE_FORMAT(FROM_UNIXTIME(create_time), '%Y-%m-%d') as date, COUNT(*) as count")
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->group('date')
            ->select();
        
        // 构建趋势数据
        $trendData = [];
        foreach ($dateArray as $date) {
            $trendData[$date] = ['date' => $date, 'moments' => 0, 'comments' => 0];
        }
        
        // 填充动态数据
        foreach ($moments as $moment) {
            if (isset($trendData[$moment['date']])) {
                $trendData[$moment['date']]['moments'] = $moment['count'];
            }
        }
        
        // 填充评论数据
        foreach ($comments as $comment) {
            if (isset($trendData[$comment['date']])) {
                $trendData[$comment['date']]['comments'] = $comment['count'];
            }
        }
        
        return array_values($trendData);
    }

    // 内容类型分布
    private function getContentTypeDistribution($startTime, $endTime)
    {
        $start = strtotime($startTime);
        $end = strtotime($endTime) + 86399;
        
        // 查询带图片的动态数
        $imageMoments = Db::name('moments')
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->where('images', '<>', '')
            ->where('images', '<>', '[]')
            ->count();
        
        // 查询带视频的动态数
        $videoMoments = Db::name('moments')
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->where('videos', '<>', '')
            ->where('videos', '<>', '[]')
            ->count();
        
        // 查询纯文本的动态数
        $textMoments = Db::name('moments')
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->where(function($query) {
                $query->where('images', '=', '')
                      ->whereOr('images', '=', '[]');
            })
            ->where(function($query) {
                $query->where('videos', '=', '')
                      ->whereOr('videos', '=', '[]');
            })
            ->count();
        
        return [
            ['name' => '图片动态', 'value' => $imageMoments],
            ['name' => '视频动态', 'value' => $videoMoments],
            ['name' => '纯文本动态', 'value' => $textMoments]
        ];
    }

    // 热门话题排行
    private function getHotTopics($startTime, $endTime)
    {
        $start = strtotime($startTime);
        $end = strtotime($endTime) + 86399;
        
        // 查询时间段内的话题使用情况
        $hotTopics = Db::name('moment_topics')
            ->alias('mt')
            ->leftJoin('moments m', 'mt.moment_id = m.id')
            ->leftJoin('topics t', 'mt.topic_id = t.id')
            ->field('t.id, t.name, t.description, COUNT(mt.moment_id) as use_count')
            ->where('m.create_time', '>=', $start)
            ->where('m.create_time', '<=', $end)
            ->group('mt.topic_id')
            ->order('use_count desc')
            ->limit(10)
            ->select();
        
        return $hotTopics;
    }

    // 用户数据统计
    public function userStatistics()
    {
        $start_time = Request::param('start_time', date('Y-m-d', strtotime('-30 day')));
        $end_time = Request::param('end_time', date('Y-m-d'));
        
        // 用户增长趋势
        $userGrowth = $this->getUserGrowth($start_time, $end_time);
        
        // 用户活跃度
        $userActivity = $this->getUserActivity();
        
        // 用户地区分布
        $userRegions = $this->getUserRegions();
        
        View::assign('start_time', $start_time);
        View::assign('end_time', $end_time);
        View::assign('user_growth', $userGrowth);
        View::assign('user_activity', $userActivity);
        View::assign('user_regions', $userRegions);
        View::assign('admin_name', Session::get('admin_name'));
        
        return View::fetch('admin/dataanalysis_users');
    }

    // 用户增长趋势
    private function getUserGrowth($startTime, $endTime)
    {
        $start = strtotime($startTime);
        $end = strtotime($endTime) + 86399;
        
        // 生成日期数组
        $dateArray = $this->generateDateArray($startTime, $endTime);
        
        // 查询每天的用户注册数
        $users = Db::name('user')
            ->field("DATE_FORMAT(FROM_UNIXTIME(create_time), '%Y-%m-%d') as date, COUNT(*) as count")
            ->where('create_time', '>=', $start)
            ->where('create_time', '<=', $end)
            ->group('date')
            ->select();
        
        // 构建趋势数据
        $growthData = [];
        $cumulative = 0;
        
        foreach ($dateArray as $date) {
            $growthData[$date] = ['date' => $date, 'count' => 0, 'cumulative' => 0];
        }
        
        // 填充用户数据
        foreach ($users as $user) {
            if (isset($growthData[$user['date']])) {
                $growthData[$user['date']]['count'] = $user['count'];
            }
        }
        
        // 计算累计用户数
        foreach ($growthData as &$data) {
            $cumulative += $data['count'];
            $data['cumulative'] = $cumulative;
        }
        
        return array_values($growthData);
    }

    // 用户活跃度
    private function getUserActivity()
    {
        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day');
        $last7days = strtotime('-7 days');
        $last30days = strtotime('-30 days');
        
        // 今日活跃用户数（至少发布一条动态或评论）
        $todayActiveUsers = Db::name('user')
            ->whereExists(function($query) use ($today) {
                $query->table('moments')->whereRaw('user.id = moments.user_id and moments.create_time >= ' . $today);
            })
            ->whereOrExists(function($query) use ($today) {
                $query->table('comments')->whereRaw('user.id = comments.user_id and comments.create_time >= ' . $today);
            })
            ->count();
        
        // 昨日活跃用户数
        $yesterdayActiveUsers = Db::name('user')
            ->whereExists(function($query) use ($yesterday, $today) {
                $query->table('moments')->whereRaw('user.id = moments.user_id and moments.create_time >= ' . $yesterday . ' and moments.create_time < ' . $today);
            })
            ->whereOrExists(function($query) use ($yesterday, $today) {
                $query->table('comments')->whereRaw('user.id = comments.user_id and comments.create_time >= ' . $yesterday . ' and comments.create_time < ' . $today);
            })
            ->count();
        
        // 7日活跃用户数
        $last7daysActiveUsers = Db::name('user')
            ->whereExists(function($query) use ($last7days) {
                $query->table('moments')->whereRaw('user.id = moments.user_id and moments.create_time >= ' . $last7days);
            })
            ->whereOrExists(function($query) use ($last7days) {
                $query->table('comments')->whereRaw('user.id = comments.user_id and comments.create_time >= ' . $last7days);
            })
            ->count();
        
        // 30日活跃用户数
        $last30daysActiveUsers = Db::name('user')
            ->whereExists(function($query) use ($last30days) {
                $query->table('moments')->whereRaw('user.id = moments.user_id and moments.create_time >= ' . $last30days);
            })
            ->whereOrExists(function($query) use ($last30days) {
                $query->table('comments')->whereRaw('user.id = comments.user_id and comments.create_time >= ' . $last30days);
            })
            ->count();
        
        return [
            ['period' => '今日活跃用户', 'count' => $todayActiveUsers],
            ['period' => '昨日活跃用户', 'count' => $yesterdayActiveUsers],
            ['period' => '7日活跃用户', 'count' => $last7daysActiveUsers],
            ['period' => '30日活跃用户', 'count' => $last30daysActiveUsers]
        ];
    }

    // 用户地区分布（模拟数据，实际需要IP地址解析）
    private function getUserRegions()
    {
        // 由于没有实际的地区数据，这里返回模拟数据
        return [
            ['name' => '北京', 'value' => 1234],
            ['name' => '上海', 'value' => 987],
            ['name' => '广州', 'value' => 856],
            ['name' => '深圳', 'value' => 765],
            ['name' => '杭州', 'value' => 654],
            ['name' => '成都', 'value' => 543],
            ['name' => '武汉', 'value' => 432],
            ['name' => '南京', 'value' => 321],
            ['name' => '西安', 'value' => 210],
            ['name' => '重庆', 'value' => 198]
        ];
    }

    // 辅助函数：生成日期数组
    private function generateDateArray($startDate, $endDate)
    {
        $dates = [];
        $currentDate = strtotime($startDate);
        $endDate = strtotime($endDate);
        
        while ($currentDate <= $endDate) {
            $dates[] = date('Y-m-d', $currentDate);
            $currentDate += 86400; // 增加一天
        }
        
        return $dates;
    }
}
