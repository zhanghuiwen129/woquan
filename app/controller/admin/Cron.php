<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;

class Cron extends AdminController
{
    
    public function index()
    {
        $tasks = Db::name('cron_tasks')
            ->order('create_time desc')
            ->select();
        
        View::assign([
            'tasks' => $tasks
        ]);
        
        return View::fetch('admin/cron/index');
    }
    
    public function records()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        
        $records = Db::name('cron_records')
            ->order('execute_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'records' => $records
        ]);
        
        return View::fetch('admin/cron/records');
    }
}
