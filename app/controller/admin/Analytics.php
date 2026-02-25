<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Analytics extends AdminController
{
    
    public function index()
    {
        $totalUsers = Db::name('user')->count();
        $totalMoments = Db::name('moments')->count();
        $totalComments = Db::name('comments')->count();
        $todayActive = Db::name('user')
            ->where('last_heartbeat_time', '>=', strtotime(date('Y-m-d')))
            ->count();
        
        $yesterdayNew = Db::name('user')
            ->where('create_time', '>=', strtotime(date('Y-m-d', strtotime('-1 day'))))
            ->where('create_time', '<', strtotime(date('Y-m-d')))
            ->count();
        
        $weekNew = Db::name('user')
            ->where('create_time', '>=', strtotime(date('Y-m-d', strtotime('-7 days'))))
            ->count();
        
        $monthNew = Db::name('user')
            ->where('create_time', '>=', strtotime(date('Y-m-01')))
            ->count();
        
        $todayMoments = Db::name('moments')
            ->where('create_time', '>=', strtotime(date('Y-m-d')))
            ->count();
        
        $todayComments = Db::name('comments')
            ->where('create_time', '>=', strtotime(date('Y-m-d')))
            ->count();
        
        $statistics = [
            'total_users' => $totalUsers,
            'total_moments' => $totalMoments,
            'total_comments' => $totalComments,
            'today_active' => $todayActive,
            'yesterday_new' => $yesterdayNew,
            'week_new' => $weekNew,
            'month_new' => $monthNew,
            'growth_rate' => $totalUsers > 0 ? round($monthNew / $totalUsers * 100, 2) : 0,
            'today_moments' => $todayMoments,
            'today_comments' => $todayComments,
            'avg_moments' => $totalMoments > 0 ? round($totalMoments / 30) : 0,
            'avg_comments' => $totalComments > 0 ? round($totalComments / 30) : 0
        ];
        
        View::assign([
            'statistics' => $statistics
        ]);
        
        return View::fetch('admin/analytics/index');
    }
    
    public function user()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $users = Db::name('user')
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'users' => $users
        ]);
        
        return View::fetch('admin/analytics/user');
    }
    
    public function content()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $moments = Db::name('moments')
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'moments' => $moments
        ]);
        
        return View::fetch('admin/analytics/content');
    }
    
    public function retention()
    {
        $retention = [
            'day1' => 85,
            'day7' => 60,
            'day30' => 35
        ];
        
        View::assign([
            'retention' => $retention
        ]);
        
        return View::fetch('admin/analytics/retention');
    }
    
    public function conversion()
    {
        $conversion = [
            'register_to_active' => 75,
            'active_to_pay' => 15,
            'pay_rate' => 12
        ];
        
        View::assign([
            'conversion' => $conversion
        ]);
        
        return View::fetch('admin/analytics/conversion');
    }
}
