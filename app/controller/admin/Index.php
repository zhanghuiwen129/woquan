<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Session;
use think\facade\Db;

class Index extends AdminController
{
    // 初始化方法，检查登录状态
    public function initialize()
    {
        parent::initialize();
    }

    // 后台首页
    public function index()
    {
        // 统计数据
        $statistics = [
            'user_count' => Db::name('user')->count(),
            'article_count' => Db::name('moments')->count(),
            'comment_count' => Db::name('comments')->count(),
            'today_login' => Db::name('admin_log')
                ->where('create_time', '>=', strtotime(date('Y-m-d')))
                ->count()
        ];

        // 最近登录日志
        $login_logs = Db::name('admin_log')
            ->order('create_time DESC')
            ->limit(10)
            ->select();

        // 分配模板变量
        View::assign('statistics', $statistics);
        View::assign('login_logs', $login_logs);
        View::assign('admin_name', Session::get('admin_name'));

        return View::fetch('admin/index');
    }

    // 欢迎页面
    public function welcome()
    {
        return View::fetch('admin/welcome');
    }
    
    // 测试方法
    public function test()
    {
        return 'Test method works!';
    }
}
